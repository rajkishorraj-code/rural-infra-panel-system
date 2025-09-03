<?php
/*
BambooPlasticPanelManager - Single-file PHP prototype
Run with: php -S 127.0.0.1:8000 BambooPlasticPanelManager.php
Features (minimal):
- SQLite DB (panels.db)
- CRUD: Recipes, Panel Types, Batches, QC Tests, Pilot Sites, Installations
- Simple dashboard + Bootstrap UI
- API endpoints: /api/report_qc, /api/field_report (JSON)

This is a prototype for local testing. Add proper routing, auth, input validation and error handling for production.
*/

$dbFile = __DIR__ . '/panels.db';
$dsn = "sqlite:" . $dbFile;

// Initialize DB and tables
function db(): PDO {
    static $pdo = null;
    global $dsn;
    if ($pdo === null) {
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function init_db(){
    if (!file_exists(__DIR__ . '/panels.db')){
        $sql = file_get_contents('php://memory'); // placeholder
    }
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS recipe (id INTEGER PRIMARY KEY, name TEXT NOT NULL, cement_percent REAL DEFAULT 0.0, plastic_percent REAL DEFAULT 0.0, additives TEXT, notes TEXT, created_at TEXT);");
    $p->exec("CREATE TABLE IF NOT EXISTS panel_type (id INTEGER PRIMARY KEY, name TEXT NOT NULL, length_m REAL DEFAULT 1.0, width_m REAL DEFAULT 0.5, thickness_m REAL DEFAULT 0.12, target_strength_mpa REAL DEFAULT 20.0, notes TEXT);");
    $p->exec("CREATE TABLE IF NOT EXISTS batch (id INTEGER PRIMARY KEY, panel_type_id INTEGER, recipe_id INTEGER, quantity INTEGER DEFAULT 0, produced_on TEXT, status TEXT DEFAULT 'produced');");
    $p->exec("CREATE TABLE IF NOT EXISTS qc_test (id INTEGER PRIMARY KEY, batch_id INTEGER, compressive_mpa REAL, flexural_mpa REAL, water_absorption_percent REAL, abrasion_loss_percent REAL, tested_on TEXT, notes TEXT);");
    $p->exec("CREATE TABLE IF NOT EXISTS pilot_site (id INTEGER PRIMARY KEY, name TEXT NOT NULL, village TEXT, district TEXT, latitude REAL, longitude REAL, slope_deg REAL, notes TEXT);");
    $p->exec("CREATE TABLE IF NOT EXISTS installation (id INTEGER PRIMARY KEY, site_id INTEGER, batch_id INTEGER, panels_installed INTEGER DEFAULT 0, installed_on TEXT, status TEXT DEFAULT 'installed');");

    // seed sample if empty
    $count = $p->query("SELECT COUNT(*) FROM recipe")->fetchColumn();
    if ($count == 0) {
        $stmt = $p->prepare('INSERT INTO recipe (name, cement_percent, plastic_percent, additives, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(['Sample: Cement 7% + Plastic 5%', 7.0, 5.0, 'flyash 15%', '', date('c')]);
    }
    $count = $p->query("SELECT COUNT(*) FROM panel_type")->fetchColumn();
    if ($count == 0) {
        $stmt = $p->prepare('INSERT INTO panel_type (name, length_m, width_m, thickness_m, target_strength_mpa, notes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(['1.0x0.5x0.12 m Medium Duty', 1.0, 0.5, 0.12, 20.0, '']);
    }
}

init_db();

// Simple router
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Helpers
function render($content, $vars = []){
    extract($vars);
    echo $content;
}

function escape($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }

// Load lists
function getAll($table, $order = null){
    $p = db();
    $sql = "SELECT * FROM $table" . ($order ? " ORDER BY $order" : "");
    return $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getById($table, $id){
    $p = db();
    $stmt = $p->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// POST JSON helper
function get_json_input(){
    $data = json_decode(file_get_contents('php://input'), true);
    return $data ?: [];
}

// API endpoints
if ($path === '/api/report_qc' && $method === 'POST'){
    $data = get_json_input();
    if (empty($data['batch_id'])){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'batch_id required']);
        exit;
    }
    $p = db();
    $stmt = $p->prepare('INSERT INTO qc_test (batch_id, compressive_mpa, flexural_mpa, water_absorption_percent, abrasion_loss_percent, tested_on, notes) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $data['batch_id'],
        $data['compressive_mpa'] ?? null,
        $data['flexural_mpa'] ?? null,
        $data['water_absorption_percent'] ?? null,
        $data['abrasion_loss_percent'] ?? null,
        date('c'),
        $data['notes'] ?? ''
    ]);
    echo json_encode(['status'=>'ok','id'=>$p->lastInsertId()]);
    exit;
}

if ($path === '/api/field_report' && $method === 'POST'){
    $data = get_json_input();
    if (empty($data['site_id']) || empty($data['batch_id'])){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'site_id and batch_id required']);
        exit;
    }
    $p = db();
    $stmt = $p->prepare('INSERT INTO installation (site_id, batch_id, panels_installed, installed_on, status) VALUES (?,?,?,?,?)');
    $stmt->execute([
        $data['site_id'],
        $data['batch_id'],
        $data['installed_panels'] ?? 0,
        date('c'),
        'reported'
    ]);
    echo json_encode(['status'=>'ok','installation_id'=>$p->lastInsertId()]);
    exit;
}

// Serve static-ish routes and forms
ob_start();
$base_html = function($body_html){ return <<<HTML
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
    \$body_html
  </div>
  </body>
</html>
HTML;
};

if ($path === '/' ){
    $total_recipes = db()->query('SELECT COUNT(*) FROM recipe')->fetchColumn();
    $total_panel_types = db()->query('SELECT COUNT(*) FROM panel_type')->fetchColumn();
    $total_batches = db()->query('SELECT COUNT(*) FROM batch')->fetchColumn();
    $total_qc = db()->query('SELECT COUNT(*) FROM qc_test')->fetchColumn();
    $sites = getAll('pilot_site');
    $body = "<div class='row'>".
            "<div class='col-md-3'><div class='card p-3'>Recipes<br><h3>{$total_recipes}</h3></div></div>".
            "<div class='col-md-3'><div class='card p-3'>Panel Types<br><h3>{$total_panel_types}</h3></div></div>".
            "<div class='col-md-3'><div class='card p-3'>Batches<br><h3>{$total_batches}</h3></div></div>".
            "<div class='col-md-3'><div class='card p-3'>QC Tests<br><h3>{$total_qc}</h3></div></div>".
            "</div><hr/><h4>Pilot Sites</h4><div class='row'>";
    if (count($sites) == 0) {
        $body .= "<p>No pilot sites yet. <a href='/sites/new'>Add one</a></p>";
    } else {
        foreach($sites as $s){
            $body .= "<div class='col-md-4'><div class='card p-2 mb-2'><strong>" . escape($s['name']) . "</strong><br/>" . escape($s['village']) . ", " . escape($s['district']) . "<br/>Slope: " . escape($s['slope_deg']) . "°</div></div>";
        }
    }
    $body .= "</div>";
    echo $base_html($body);
    exit;
}

// Recipes list & new
if ($path === '/recipes'){
    $items = getAll('recipe', 'created_at DESC');
    $body = "<h3>Recipes <a class='btn btn-sm btn-success' href='/recipes/new'>New</a></h3>";
    $body .= "<table class='table table-sm'><tr><th>Name</th><th>Cement %</th><th>Plastic %</th><th>Actions</th></tr>";
    foreach($items as $r){
        $body .= "<tr><td>" . escape($r['name']) . "</td><td>" . escape($r['cement_percent']) . "</td><td>" . escape($r['plastic_percent']) . "</td><td><a href='/recipes/" . $r['id'] . "'>View</a></td></tr>";
    }
    $body .= "</table>";
    echo $base_html($body);
    exit;
}

if ($path === '/recipes/new'){
    if ($method === 'POST'){
        $name = $_POST['name'] ?? '';
        $cement = floatval($_POST['cement'] ?? 0);
        $plastic = floatval($_POST['plastic'] ?? 0);
        $additives = $_POST['additives'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $stmt = db()->prepare('INSERT INTO recipe (name, cement_percent, plastic_percent, additives, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $cement, $plastic, $additives, $notes, date('c')]);
        header('Location: /recipes'); exit;
    }
    $body = <<<HTML
<h3>New Recipe</h3>
<form method="post">
  <div class="mb-3"><label>Name</label><input class="form-control" name="name" required></div>
  <div class="mb-3"><label>Cement %</label><input class="form-control" name="cement" value="5"></div>
  <div class="mb-3"><label>Plastic % (fine agg vol %)</label><input class="form-control" name="plastic" value="5"></div>
  <div class="mb-3"><label>Additives</label><input class="form-control" name="additives"></div>
  <div class="mb-3"><label>Notes</label><textarea class="form-control" name="notes"></textarea></div>
  <button class="btn btn-primary">Save</button>
</form>
HTML;
    echo $base_html($body);
    exit;
}

// View recipe
if (preg_match('#^/recipes/(\d+)$#', $path, $m)){
    $r = getById('recipe', $m[1]);
    if (!$r){ http_response_code(404); echo 'Not found'; exit; }
    $body = "<h3>Recipe: " . escape($r['name']) . "</h3>";
    $body .= "<ul><li>Cement: " . escape($r['cement_percent']) . " %</li><li>Plastic: " . escape($r['plastic_percent']) . "</li><li>Additives: " . escape($r['additives']) . "</li><li>Notes: " . escape($r['notes']) . "</li></ul>";
    $body .= "<a href='/recipes'>Back</a>";
    echo $base_html($body);
    exit;
}

// Panel types list & new
if ($path === '/panels'){
    $items = getAll('panel_type');
    $body = "<h3>Panel Types <a class='btn btn-sm btn-success' href='/panels/new'>New</a></h3>";
    $body .= "<table class='table table-sm'><tr><th>Name</th><th>Size (m)</th><th>Thickness</th><th>Target MPa</th></tr>";
    foreach($items as $p){
        $body .= "<tr><td>" . escape($p['name']) . "</td><td>" . escape($p['length_m']) . " x " . escape($p['width_m']) . "</td><td>" . escape($p['thickness_m']) . "</td><td>" . escape($p['target_strength_mpa']) . "</td></tr>";
    }
    $body .= "</table>";
    echo $base_html($body);
    exit;
}

if ($path === '/panels/new'){
    if ($method === 'POST'){
        $name = $_POST['name'] ?? '';
        $length = floatval($_POST['length'] ?? 1.0);
        $width = floatval($_POST['width'] ?? 0.5);
        $thickness = floatval($_POST['thickness'] ?? 0.12);
        $target = floatval($_POST['target'] ?? 20.0);
        $notes = $_POST['notes'] ?? '';
        $stmt = db()->prepare('INSERT INTO panel_type (name, length_m, width_m, thickness_m, target_strength_mpa, notes) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$name, $length, $width, $thickness, $target, $notes]);
        header('Location: /panels'); exit;
    }
    $body = <<<HTML
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
HTML;
    echo $base_html($body);
    exit;
}

// Batches list & new
if ($path === '/batches'){
    $items = db()->query('SELECT b.*, p.name as panel_name, r.name as recipe_name FROM batch b LEFT JOIN panel_type p ON p.id=b.panel_type_id LEFT JOIN recipe r ON r.id=b.recipe_id ORDER BY produced_on DESC')->fetchAll(PDO::FETCH_ASSOC);
    $body = "<h3>Batches <a class='btn btn-sm btn-success' href='/batches/new'>New</a></h3>";
    $body .= "<table class='table table-sm'><tr><th>ID</th><th>Panel Type</th><th>Recipe</th><th>Qty</th><th>Produced On</th></tr>";
    foreach($items as $b){
        $body .= "<tr><td>" . escape($b['id']) . "</td><td>" . escape($b['panel_name']) . "</td><td>" . escape($b['recipe_name']) . "</td><td>" . escape($b['quantity']) . "</td><td>" . escape($b['produced_on']) . "</td></tr>";
    }
    $body .= "</table>";
    echo $base_html($body);
    exit;
}

if ($path === '/batches/new'){
    $recipes = getAll('recipe');
    $panels = getAll('panel_type');
    if ($method === 'POST'){
        $recipe = intval($_POST['recipe'] ?? 0);
        $panel = intval($_POST['panel'] ?? 0);
        $qty = intval($_POST['quantity'] ?? 0);
        $produced_on = $_POST['produced_on'] ?? date('c');
        $stmt = db()->prepare('INSERT INTO batch (panel_type_id, recipe_id, quantity, produced_on, status) VALUES (?,?,?,?,?)');
        $stmt->execute([$panel, $recipe, $qty, $produced_on, 'produced']);
        header('Location: /batches'); exit;
    }
    $optionsR = '';
    foreach($recipes as $r) $optionsR .= "<option value='" . $r['id'] . "'>" . escape($r['name']) . "</option>";
    $optionsP = '';
    foreach($panels as $p) $optionsP .= "<option value='" . $p['id'] . "'>" . escape($p['name']) . "</option>";
    $body = "<h3>New Batch</h3><form method='post'><div class='mb-3'><label>Recipe</label><select class='form-control' name='recipe'>" . $optionsR . "</select></div><div class='mb-3'><label>Panel Type</label><select class='form-control' name='panel'>" . $optionsP . "</select></div><div class='mb-3'><label>Quantity</label><input class='form-control' name='quantity' value='100'></div><div class='mb-3'><label>Produced On</label><input class='form-control' name='produced_on' type='date'></div><button class='btn btn-primary'>Save</button></form>";
    echo $base_html($body);
    exit;
}

// QC list & new
if ($path === '/qc'){
    $items = db()->query('SELECT q.*, b.id as batch_id FROM qc_test q LEFT JOIN batch b ON b.id=q.batch_id ORDER BY tested_on DESC')->fetchAll(PDO::FETCH_ASSOC);
    $body = "<h3>QC Tests <a class='btn btn-sm btn-success' href='/qc/new'>New</a></h3>";
    $body .= "<table class='table table-sm'><tr><th>ID</th><th>Batch</th><th>Comp (MPa)</th><th>Flex (MPa)</th><th>Tested</th></tr>";
    foreach($items as $q){
        $body .= "<tr><td>" . escape($q['id']) . "</td><td>" . escape($q['batch_id']) . "</td><td>" . escape($q['compressive_mpa']) . "</td><td>" . escape($q['flexural_mpa']) . "</td><td>" . escape($q['tested_on']) . "</td></tr>";
    }
    $body .= "</table>";
    echo $base_html($body);
    exit;
}

if ($path === '/qc/new'){
    $batches = getAll('batch');
    if ($method === 'POST'){
        $batch = intval($_POST['batch'] ?? 0);
        $comp = floatval($_POST['comp'] ?? 0);
        $flex = floatval($_POST['flex'] ?? 0);
        $water = floatval($_POST['water'] ?? 0);
        $abr = floatval($_POST['abr'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        $stmt = db()->prepare('INSERT INTO qc_test (batch_id, compressive_mpa, flexural_mpa, water_absorption_percent, abrasion_loss_percent, tested_on, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$batch, $comp, $flex, $water, $abr, date('c'), $notes]);
        header('Location: /qc'); exit;
    }
    $opt = '';
    foreach($batches as $b) $opt .= "<option value='" . $b['id'] . "'>" . $b['id'] . " - " . escape($b['panel_type_id']) . "</option>";
    $body = "<h3>New QC Test</h3><form method='post'><div class='mb-3'><label>Batch</label><select class='form-control' name='batch'>" . $opt . "</select></div><div class='mb-3'><label>Compressive (MPa)</label><input class='form-control' name='comp'></div><div class='mb-3'><label>Flexural (MPa)</label><input class='form-control' name='flex'></div><div class='mb-3'><label>Water Absorption %</label><input class='form-control' name='water'></div><div class='mb-3'><label>Abrasion Loss %</label><input class='form-control' name='abr'></div><div class='mb-3'><label>Notes</label><textarea class='form-control' name='notes'></textarea></div><button class='btn btn-primary'>Save</button></form>";
    echo $base_html($body);
    exit;
}

// Sites list & new
if ($path === '/sites'){
    $items = getAll('pilot_site');
    $body = "<h3>Pilot Sites <a class='btn btn-sm btn-success' href='/sites/new'>New</a></h3>";
    $body .= "<table class='table table-sm'><tr><th>Name</th><th>Village</th><th>District</th><th>Slope</th></tr>";
    foreach($items as $s){
        $body .= "<tr><td>" . escape($s['name']) . "</td><td>" . escape($s['village']) . "</td><td>" . escape($s['district']) . "</td><td>" . escape($s['slope_deg']) . "</td></tr>";
    }
    $body .= "</table>";
    echo $base_html($body);
    exit;
}

if ($path === '/sites/new'){
    if ($method === 'POST'){
        $name = $_POST['name'] ?? '';
        $village = $_POST['village'] ?? '';
        $district = $_POST['district'] ?? '';
        $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? floatval($_POST['lat']) : null;
        $lon = isset($_POST['lon']) && $_POST['lon'] !== '' ? floatval($_POST['lon']) : null;
        $slope = floatval($_POST['slope'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        $stmt = db()->prepare('INSERT INTO pilot_site (name, village, district, latitude, longitude, slope_deg, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$name, $village, $district, $lat, $lon, $slope, $notes]);
        header('Location: /sites'); exit;
    }
    $body = <<<HTML
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
HTML;
    echo $base_html($body);
    exit;
}

// Installation new
if ($path === '/install/new'){
    $sites = getAll('pilot_site');
    $batches = getAll('batch');
    if ($method === 'POST'){
        $site = intval($_POST['site'] ?? 0);
        $batch = intval($_POST['batch'] ?? 0);
        $qty = intval($_POST['qty'] ?? 0);
        $installed_on = $_POST['installed_on'] ?? date('c');
        $stmt = db()->prepare('INSERT INTO installation (site_id, batch_id, panels_installed, installed_on, status) VALUES (?,?,?,?,?)');
        $stmt->execute([$site, $batch, $qty, $installed_on, 'installed']);
        header('Location: /'); exit;
    }
    $optS = '';
    foreach($sites as $s) $optS .= "<option value='" . $s['id'] . "'>" . escape($s['name']) . "</option>";
    $optB = '';
    foreach($batches as $b) $optB .= "<option value='" . $b['id'] . "'>" . $b['id'] . "</option>";
    $body = "<h3>New Installation</h3><form method='post'><div class='mb-3'><label>Site</label><select class='form-control' name='site'>" . $optS . "</select></div><div class='mb-3'><label>Batch</label><select class='form-control' name='batch'>" . $optB . "</select></div><div class='mb-3'><label>Panels Installed</label><input class='form-control' name='qty' value='100'></div><div class='mb-3'><label>Installed On</label><input class='form-control' name='installed_on' type='date'></div><button class='btn btn-primary'>Save</button></form>";
    echo $base_html($body);
    exit;
}

// Fallback 404
http_response_code(404);
echo "<h1>404 Not Found</h1>";
exit;
?>
