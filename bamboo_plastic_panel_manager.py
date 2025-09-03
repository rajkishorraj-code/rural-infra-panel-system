"""
BambooPlasticPanelManager - Minimal Prototype Flask App
Single-file prototype for managing prefabricated bamboo-plastic road panels.
Features:
- Manage Recipes (mix proportions)
- Manage Panel Types (geometry, target strength)
- Create Production Batches (link recipe + panels)
- Record QC test results for batches
- Manage Pilot Sites & Installations
- Simple dashboard and JSON API endpoints for field reports

To run:
1. python3 -m venv venv
2. source venv/bin/activate    (or venv\Scripts\activate on Windows)
3. pip install flask sqlalchemy flask_sqlalchemy
4. python BambooPlasticPanelManager.py
5. Open http://127.0.0.1:5000

This is a prototype: replace with production-level auth, validation, and hosting for real deployment.
"""

from flask import Flask, render_template_string, request, redirect, url_for, jsonify, flash
from flask_sqlalchemy import SQLAlchemy
from datetime import datetime
import os

app = Flask(__name__)
app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///panels.db'
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
app.secret_key = 'dev-secret'

db = SQLAlchemy(app)

# -----------------
# Models
# -----------------
class Recipe(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(120), nullable=False)
    cement_percent = db.Column(db.Float, default=0.0)  # % by mass
    plastic_percent = db.Column(db.Float, default=0.0) # % by volume of fine aggregate
    additives = db.Column(db.String(250))
    notes = db.Column(db.String(500))
    created_at = db.Column(db.DateTime, default=datetime.utcnow)

class PanelType(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(120), nullable=False)
    length_m = db.Column(db.Float, default=1.0)
    width_m = db.Column(db.Float, default=0.5)
    thickness_m = db.Column(db.Float, default=0.12)
    target_strength_mpa = db.Column(db.Float, default=20.0)
    notes = db.Column(db.String(250))

class Batch(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    panel_type_id = db.Column(db.Integer, db.ForeignKey('panel_type.id'))
    recipe_id = db.Column(db.Integer, db.ForeignKey('recipe.id'))
    quantity = db.Column(db.Integer, default=0)
    produced_on = db.Column(db.DateTime, default=datetime.utcnow)
    status = db.Column(db.String(50), default='produced')

    recipe = db.relationship('Recipe')
    panel_type = db.relationship('PanelType')

class QCTest(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    batch_id = db.Column(db.Integer, db.ForeignKey('batch.id'))
    compressive_mpa = db.Column(db.Float)
    flexural_mpa = db.Column(db.Float)
    water_absorption_percent = db.Column(db.Float)
    abrasion_loss_percent = db.Column(db.Float)
    tested_on = db.Column(db.DateTime, default=datetime.utcnow)
    notes = db.Column(db.String(500))

    batch = db.relationship('Batch')

class PilotSite(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(120), nullable=False)
    village = db.Column(db.String(120))
    district = db.Column(db.String(120))
    latitude = db.Column(db.Float)
    longitude = db.Column(db.Float)
    slope_deg = db.Column(db.Float)
    notes = db.Column(db.String(500))

class Installation(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    site_id = db.Column(db.Integer, db.ForeignKey('pilot_site.id'))
    batch_id = db.Column(db.Integer, db.ForeignKey('batch.id'))
    panels_installed = db.Column(db.Integer, default=0)
    installed_on = db.Column(db.DateTime, default=datetime.utcnow)
    status = db.Column(db.String(50), default='installed')

    site = db.relationship('PilotSite')
    batch = db.relationship('Batch')

# -----------------
# Initialize DB
# -----------------
@app.before_first_request
def create_tables():
    db.create_all()

# -----------------
# Templates (simple single-file templates for prototype)
# -----------------
base_html = '''
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bamboo Panel Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-3">
    <div class="container-fluid">
      <a class="navbar-brand" href="/">PanelManager</a>
      <div class="collapse navbar-collapse">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="/recipes">Recipes</a></li>
          <li class="nav-item"><a class="nav-link" href="/panels">Panel Types</a></li>
          <li class="nav-item"><a class="nav-link" href="/batches">Batches</a></li>
          <li class="nav-item"><a class="nav-link" href="/qc">QC Tests</a></li>
          <li class="nav-item"><a class="nav-link" href="/sites">Pilot Sites</a></li>
        </ul>
      </div>
    </div>
  </nav>
  <div class="container">
    {% with messages = get_flashed_messages() %}
      {% if messages %}
        <div class="alert alert-info">{{ messages[0] }}</div>
      {% endif %}
    {% endwith %}
    {% block content %}{% endblock %}
  </div>
  </body>
</html>
'''

# -----------------
# Routes: Dashboard
# -----------------
@app.route('/')
def dashboard():
    total_recipes = Recipe.query.count()
    total_panel_types = PanelType.query.count()
    total_batches = Batch.query.count()
    total_qc = QCTest.query.count()
    sites = PilotSite.query.all()
    return render_template_string(base_html + '''
    {% block content %}
    <div class="row">
      <div class="col-md-3"><div class="card p-3">Recipes<br><h3>{{total_recipes}}</h3></div></div>
      <div class="col-md-3"><div class="card p-3">Panel Types<br><h3>{{total_panel_types}}</h3></div></div>
      <div class="col-md-3"><div class="card p-3">Batches<br><h3>{{total_batches}}</h3></div></div>
      <div class="col-md-3"><div class="card p-3">QC Tests<br><h3>{{total_qc}}</h3></div></div>
    </div>
    <hr/>
    <h4>Pilot Sites</h4>
    <div class="row">
      {% for s in sites %}
        <div class="col-md-4"><div class="card p-2 mb-2">
          <strong>{{s.name}}</strong><br/>
          {{s.village}}, {{s.district}}<br/>
          Slope: {{s.slope_deg}}°
        </div></div>
      {% else %}
        <p>No pilot sites yet. <a href="/sites/new">Add one</a></p>
      {% endfor %}
    </div>
    {% endblock %}
    ''', total_recipes=total_recipes, total_panel_types=total_panel_types, total_batches=total_batches, total_qc=total_qc, sites=sites)

# -----------------
# Recipes
# -----------------
@app.route('/recipes')
def recipes():
    items = Recipe.query.order_by(Recipe.created_at.desc()).all()
    return render_template_string(base_html + '''
    {% block content %}
    <h3>Recipes <a class="btn btn-sm btn-success" href="/recipes/new">New</a></h3>
    <table class="table table-sm">
      <tr><th>Name</th><th>Cement %</th><th>Plastic %</th><th>Actions</th></tr>
      {% for r in items %}
      <tr>
        <td>{{r.name}}</td>
        <td>{{r.cement_percent}}</td>
        <td>{{r.plastic_percent}}</td>
        <td><a href="/recipes/{{r.id}}">View</a></td>
      </tr>
      {% endfor %}
    </table>
    {% endblock %}
    ''', items=items)

@app.route('/recipes/new', methods=['GET','POST'])
def new_recipe():
    if request.method == 'POST':
        r = Recipe(
            name=request.form['name'],
            cement_percent=float(request.form.get('cement',0)),
            plastic_percent=float(request.form.get('plastic',0)),
            additives=request.form.get('additives',''),
            notes=request.form.get('notes','')
        )
        db.session.add(r)
        db.session.commit()
        flash('Recipe created')
        return redirect(url_for('recipes'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New Recipe</h3>
    <form method="post">
      <div class="mb-3"><label>Name</label><input class="form-control" name="name" required></div>
      <div class="mb-3"><label>Cement %</label><input class="form-control" name="cement" value="5"></div>
      <div class="mb-3"><label>Plastic % (fine agg vol %)</label><input class="form-control" name="plastic" value="5"></div>
      <div class="mb-3"><label>Additives</label><input class="form-control" name="additives"></div>
      <div class="mb-3"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''')

@app.route('/recipes/<int:id>')
def view_recipe(id):
    r = Recipe.query.get_or_404(id)
    return render_template_string(base_html + '''
    {% block content %}
    <h3>Recipe: {{r.name}}</h3>
    <ul>
      <li>Cement: {{r.cement_percent}} %</li>
      <li>Plastic: {{r.plastic_percent}} %</li>
      <li>Additives: {{r.additives}}</li>
      <li>Notes: {{r.notes}}</li>
    </ul>
    <a href="/recipes">Back</a>
    {% endblock %}
    ''', r=r)

# -----------------
# Panel Types
# -----------------
@app.route('/panels')
def panels():
    items = PanelType.query.all()
    return render_template_string(base_html + '''
    {% block content %}
    <h3>Panel Types <a class="btn btn-sm btn-success" href="/panels/new">New</a></h3>
    <table class="table table-sm"><tr><th>Name</th><th>Size (m)</th><th>Thickness</th><th>Target MPa</th></tr>
    {% for p in items %}
      <tr><td>{{p.name}}</td><td>{{p.length_m}} x {{p.width_m}}</td><td>{{p.thickness_m}}</td><td>{{p.target_strength_mpa}}</td></tr>
    {% endfor %}
    </table>
    {% endblock %}
    ''', items=items)

@app.route('/panels/new', methods=['GET','POST'])
def new_panel():
    if request.method == 'POST':
        p = PanelType(
            name=request.form['name'],
            length_m=float(request.form.get('length',1.0)),
            width_m=float(request.form.get('width',0.5)),
            thickness_m=float(request.form.get('thickness',0.12)),
            target_strength_mpa=float(request.form.get('target',20.0)),
            notes=request.form.get('notes','')
        )
        db.session.add(p)
        db.session.commit()
        flash('Panel type created')
        return redirect(url_for('panels'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New Panel Type</h3>
    <form method="post">
      <div class="mb-3"><label>Name</label><input class="form-control" name="name" required></div>
      <div class="mb-3"><label>Length (m)</label><input class="form-control" name="length" value="1.0"></div>
      <div class="mb-3"><label>Width (m)</label><input class="form-control" name="width" value="0.5"></div>
      <div class="mb-3"><label>Thickness (m)</label><input class="form-control" name="thickness" value="0.12"></div>
      <div class="mb-3"><label>Target Strength (MPa)</label><input class="form-control" name="target" value="20"></div>
      <div class="mb-3"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''')

# -----------------
# Batches
# -----------------
@app.route('/batches')
def batches():
    items = Batch.query.order_by(Batch.produced_on.desc()).all()
    return render_template_string(base_html + '''
    {% block content %}
    <h3>Batches <a class="btn btn-sm btn-success" href="/batches/new">New</a></h3>
    <table class="table table-sm"><tr><th>ID</th><th>Panel Type</th><th>Recipe</th><th>Qty</th><th>Produced On</th></tr>
    {% for b in items %}
      <tr><td>{{b.id}}</td><td>{{b.panel_type.name if b.panel_type else 'n/a'}}</td><td>{{b.recipe.name if b.recipe else 'n/a'}}</td><td>{{b.quantity}}</td><td>{{b.produced_on.strftime('%Y-%m-%d')}}</td></tr>
    {% endfor %}
    </table>
    {% endblock %}
    ''', items=items)

@app.route('/batches/new', methods=['GET','POST'])
def new_batch():
    recipes = Recipe.query.all()
    panels = PanelType.query.all()
    if request.method == 'POST':
        b = Batch(
            recipe_id=int(request.form.get('recipe')),
            panel_type_id=int(request.form.get('panel')),
            quantity=int(request.form.get('quantity',0)),
            produced_on=datetime.strptime(request.form.get('produced_on'), '%Y-%m-%d') if request.form.get('produced_on') else datetime.utcnow()
        )
        db.session.add(b)
        db.session.commit()
        flash('Batch created')
        return redirect(url_for('batches'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New Batch</h3>
    <form method="post">
      <div class="mb-3"><label>Recipe</label><select class="form-control" name="recipe">{% for r in recipes %}<option value="{{r.id}}">{{r.name}}</option>{% endfor %}</select></div>
      <div class="mb-3"><label>Panel Type</label><select class="form-control" name="panel">{% for p in panels %}<option value="{{p.id}}">{{p.name}}</option>{% endfor %}</select></div>
      <div class="mb-3"><label>Quantity</label><input class="form-control" name="quantity" value="100"></div>
      <div class="mb-3"><label>Produced On</label><input class="form-control" name="produced_on" type="date"></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''', recipes=recipes, panels=panels)

# -----------------
# QC Tests
# -----------------
@app.route('/qc')
def qc():
    items = QCTest.query.order_by(QCTest.tested_on.desc()).all()
    return render_template_string(base_html + '''
    {% block content %}
    <h3>QC Tests <a class="btn btn-sm btn-success" href="/qc/new">New</a></h3>
    <table class="table table-sm"><tr><th>ID</th><th>Batch</th><th>Comp (MPa)</th><th>Flex (MPa)</th><th>Tested</th></tr>
    {% for q in items %}
      <tr><td>{{q.id}}</td><td>{{q.batch.id if q.batch else 'n/a'}}</td><td>{{q.compressive_mpa}}</td><td>{{q.flexural_mpa}}</td><td>{{q.tested_on.strftime('%Y-%m-%d')}}</td></tr>
    {% endfor %}
    </table>
    {% endblock %}
    ''', items=items)

@app.route('/qc/new', methods=['GET','POST'])
def new_qc():
    batches = Batch.query.all()
    if request.method == 'POST':
        q = QCTest(
            batch_id=int(request.form.get('batch')),
            compressive_mpa=float(request.form.get('comp',0)),
            flexural_mpa=float(request.form.get('flex',0)),
            water_absorption_percent=float(request.form.get('water',0)),
            abrasion_loss_percent=float(request.form.get('abr',0)),
            notes=request.form.get('notes','')
        )
        db.session.add(q)
        db.session.commit()
        flash('QC Test recorded')
        return redirect(url_for('qc'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New QC Test</h3>
    <form method="post">
      <div class="mb-3"><label>Batch</label><select class="form-control" name="batch">{% for b in batches %}<option value="{{b.id}}">{{b.id}} - {{b.panel_type.name if b.panel_type else 'n/a'}}</option>{% endfor %}</select></div>
      <div class="mb-3"><label>Compressive (MPa)</label><input class="form-control" name="comp"></div>
      <div class="mb-3"><label>Flexural (MPa)</label><input class="form-control" name="flex"></div>
      <div class="mb-3"><label>Water Absorption %</label><input class="form-control" name="water"></div>
      <div class="mb-3"><label>Abrasion Loss %</label><input class="form-control" name="abr"></div>
      <div class="mb-3"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''', batches=batches)

# -----------------
# Pilot Sites & Installations
# -----------------
@app.route('/sites')
def sites():
    items = PilotSite.query.all()
    return render_template_string(base_html + '''
    {% block content %}
    <h3>Pilot Sites <a class="btn btn-sm btn-success" href="/sites/new">New</a></h3>
    <table class="table table-sm"><tr><th>Name</th><th>Village</th><th>District</th><th>Slope</th></tr>
    {% for s in items %}
      <tr><td>{{s.name}}</td><td>{{s.village}}</td><td>{{s.district}}</td><td>{{s.slope_deg}}</td></tr>
    {% endfor %}
    </table>
    {% endblock %}
    ''', items=items)

@app.route('/sites/new', methods=['GET','POST'])
def new_site():
    if request.method == 'POST':
        s = PilotSite(
            name=request.form.get('name'),
            village=request.form.get('village'),
            district=request.form.get('district'),
            latitude=float(request.form.get('lat')) if request.form.get('lat') else None,
            longitude=float(request.form.get('lon')) if request.form.get('lon') else None,
            slope_deg=float(request.form.get('slope',0)),
            notes=request.form.get('notes','')
        )
        db.session.add(s)
        db.session.commit()
        flash('Site added')
        return redirect(url_for('sites'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New Pilot Site</h3>
    <form method="post">
      <div class="mb-3"><label>Name</label><input class="form-control" name="name" required></div>
      <div class="mb-3"><label>Village</label><input class="form-control" name="village"></div>
      <div class="mb-3"><label>District</label><input class="form-control" name="district"></div>
      <div class="mb-3"><label>Latitude</label><input class="form-control" name="lat"></div>
      <div class="mb-3"><label>Longitude</label><input class="form-control" name="lon"></div>
      <div class="mb-3"><label>Slope (deg)</label><input class="form-control" name="slope" value="5"></div>
      <div class="mb-3"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''')

# -----------------
# Installations
# -----------------
@app.route('/install/new', methods=['GET','POST'])
def new_install():
    sites = PilotSite.query.all()
    batches = Batch.query.all()
    if request.method == 'POST':
        it = Installation(
            site_id=int(request.form.get('site')),
            batch_id=int(request.form.get('batch')),
            panels_installed=int(request.form.get('qty',0)),
            installed_on=datetime.strptime(request.form.get('installed_on'), '%Y-%m-%d') if request.form.get('installed_on') else datetime.utcnow()
        )
        db.session.add(it)
        db.session.commit()
        flash('Installation recorded')
        return redirect(url_for('dashboard'))
    return render_template_string(base_html + '''
    {% block content %}
    <h3>New Installation</h3>
    <form method="post">
      <div class="mb-3"><label>Site</label><select class="form-control" name="site">{% for s in sites %}<option value="{{s.id}}">{{s.name}}</option>{% endfor %}</select></div>
      <div class="mb-3"><label>Batch</label><select class="form-control" name="batch">{% for b in batches %}<option value="{{b.id}}">{{b.id}}</option>{% endfor %}</select></div>
      <div class="mb-3"><label>Panels Installed</label><input class="form-control" name="qty" value="100"></div>
      <div class="mb-3"><label>Installed On</label><input class="form-control" name="installed_on" type="date"></div>
      <button class="btn btn-primary">Save</button>
    </form>
    {% endblock %}
    ''', sites=sites, batches=batches)

# -----------------
# Simple API endpoints for field/mobile
# -----------------
@app.route('/api/report_qc', methods=['POST'])
def api_report_qc():
    """Accepts JSON: {batch_id, compressive_mpa, flexural_mpa, water_absorption_percent, abrasion_loss_percent, notes}
    Returns success JSON.
    """
    data = request.json or {}
    try:
        q = QCTest(
            batch_id=data.get('batch_id'),
            compressive_mpa=data.get('compressive_mpa'),
            flexural_mpa=data.get('flexural_mpa'),
            water_absorption_percent=data.get('water_absorption_percent'),
            abrasion_loss_percent=data.get('abrasion_loss_percent'),
            notes=data.get('notes','')
        )
        db.session.add(q)
        db.session.commit()
        return jsonify({'status':'ok','id':q.id})
    except Exception as e:
        return jsonify({'status':'error','message':str(e)}), 400

@app.route('/api/field_report', methods=['POST'])
def api_field_report():
    """Field app can report installation status or issues.
    Example JSON: {site_id, batch_id, installed_panels, issue: 'crack'}
    """
    data = request.json or {}
    site_id = data.get('site_id')
    batch_id = data.get('batch_id')
    if not site_id or not batch_id:
        return jsonify({'status':'error','message':'site_id and batch_id required'}), 400
    it = Installation(site_id=site_id, batch_id=batch_id, panels_installed=data.get('installed_panels',0), installed_on=datetime.utcnow(), status='reported')
    db.session.add(it)
    db.session.commit()
    return jsonify({'status':'ok','installation_id':it.id})

# -----------------
# Run
# -----------------
if __name__ == '__main__':
    # On first run, create a sample recipe and panel type for demonstration
    if Recipe.query.count() == 0:
        r = Recipe(name='Sample: Cement 7% + Plastic 5%', cement_percent=7.0, plastic_percent=5.0, additives='flyash 15%')
        db.session.add(r)
    if PanelType.query.count() == 0:
        p = PanelType(name='1.0x0.5x0.12 m Medium Duty', length_m=1.0, width_m=0.5, thickness_m=0.12, target_strength_mpa=20.0)
        db.session.add(p)
    db.session.commit()
    app.run(debug=True)
