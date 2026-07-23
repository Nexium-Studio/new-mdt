<?php
// pages/staff/content/lssd/dispatch-custom.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

/* ── Nom panel LSSD ── */
$lssdName = 'LSSD';
try {
    $r = $db->prepare("SELECT custom_name FROM admin_panel_config WHERE panel_key='LSSD' LIMIT 1");
    $r->execute();
    $rw = $r->fetch();
    if ($rw && $rw['custom_name']) $lssdName = $rw['custom_name'];
} catch (Exception $e) {}

$pageTitle  = 'Custom Dispatch ' . $lssdName;
$breadcrumb = [['label' => 'Panels', 'url' => 'panels.php'], ['label' => 'Custom Dispatch ' . $lssdName]];
$success = $error = '';

/* ── Création de TOUTES les tables nécessaires ── */
$tablesToCreate = [
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_config (
        config_key VARCHAR(64) PRIMARY KEY,
        config_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        position INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_patrol_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        position INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_labels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(20) DEFAULT '#10b981'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_card_labels (
        card_id INT NOT NULL,
        label_id INT NOT NULL,
        PRIMARY KEY (card_id, label_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_vehicle_plates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        plate VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_vehicle_plate (vehicle_id, plate),
        KEY idx_plate (plate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_global_checklists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(128) NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS lssd_dispatch_global_checklist_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        checklist_id INT NOT NULL,
        text VARCHAR(255) NOT NULL,
        position INT DEFAULT 0,
        KEY idx_checklist (checklist_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablesToCreate as $sql) {
    try { $db->exec($sql); } catch (Exception $e) {}
}

/* ── Valeurs par défaut ── */
$CODE_TEN_DEFAULT = [
    '10-03'=>'gray','10-06'=>'gray','10-07'=>'gray','10-08'=>'gray',
    '10-10'=>'gray','10-23'=>'gray','10-98'=>'gray',
    '10-38'=>'green','10-50'=>'green','10-51'=>'green',
    '10-52'=>'green','10-56'=>'green','10-57'=>'green',
    '10-14'=>'blue','10-15'=>'blue','10-19'=>'blue',
    '10-31'=>'red','10-35'=>'red','10-91'=>'red',
    '10-40'=>'orange','10-59'=>'orange','10-60'=>'orange',
    '10-74'=>'orange','10-75'=>'orange',
];
$OTHER_TAGS_DEFAULT = [
    'En Pause'       => 'gray',
    'En Formation'   => 'gray',
    'En Déplacement' => 'green',
    'En Attente'     => 'purple',
    'En Meeting'     => 'yellow',
    'Code 5'         => 'red',
];
$THEME_DEFAULTS = [
    'bg_type'        => 'gradient',
    'bg_color'       => '#060f1d',
    'bg_image'       => '',
    'bg_overlay'     => '0.55',
    'accent'         => '#00e5ff',
    'board_title'    => 'DISPATCH BOARD',
    'col_opacity'    => '0.88',
    'card_radius'    => '12',
    'topbar_opacity' => '0.97',
    'col_blur'       => '8',
];

/* ── Chargement depuis DB ── */
$codeTen   = $CODE_TEN_DEFAULT;
$otherTags = $OTHER_TAGS_DEFAULT;
$theme     = $THEME_DEFAULTS;
try {
    $rows = $db->query("SELECT config_key, config_value FROM lssd_dispatch_config WHERE config_key IN ('code_ten','other_tags','lssd_dispatch_theme')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['code_ten'])) {
        $dec = json_decode($rows['code_ten'], true);
        if (is_array($dec) && !empty($dec)) $codeTen = $dec;
    }
    if (!empty($rows['other_tags'])) {
        $dec = json_decode($rows['other_tags'], true);
        if (is_array($dec) && !empty($dec)) $otherTags = $dec;
    }
    if (!empty($rows['lssd_dispatch_theme'])) {
        $dec = json_decode($rows['lssd_dispatch_theme'], true);
        if (is_array($dec)) $theme = array_merge($THEME_DEFAULTS, $dec);
    }
} catch (Exception $e) {}

/* ── Couleurs preset Tags/Codes ── */
$COLORS = [
    'gray'   => ['label' => 'Gris',   'hex' => '#64748b'],
    'green'  => ['label' => 'Vert',   'hex' => '#10b981'],
    'red'    => ['label' => 'Rouge',  'hex' => '#ef4444'],
    'orange' => ['label' => 'Orange', 'hex' => '#f97316'],
    'blue'   => ['label' => 'Bleu',   'hex' => '#10b981'],
    'purple' => ['label' => 'Violet', 'hex' => '#8b5cf6'],
    'yellow' => ['label' => 'Jaune',  'hex' => '#eab308'],
    'cyan'   => ['label' => 'Cyan',   'hex' => '#06b6d4'],
];

/* ── Upload fond ── */
define('BG_UPLOAD_DIR', __DIR__ . '/../../../public/uploads/dispatch/bg/');
define('BG_UPLOAD_URL', SITE_URL . '/public/uploads/dispatch/bg/');

/* ════════════════════════════════════════════════════
   TRAITEMENTS POST
════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $curTab = $_POST['current_tab'] ?? 'apparence';

    /* ── GRADES ── */
    if ($action === 'add_grade') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nom requis.'; }
        else {
            try {
                $pos = $db->query("SELECT COALESCE(MAX(position),0)+1 FROM lssd_dispatch_grades")->fetchColumn();
                $db->prepare("INSERT INTO lssd_dispatch_grades (name,position) VALUES (?,?)")->execute([$name, $pos]);
                $success = 'Grade "'.htmlspecialchars($name).'" ajouté.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
    if ($action === 'edit_grade') {
        $id = (int)$_POST['id']; $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nom requis.'; }
        else { $db->prepare("UPDATE lssd_dispatch_grades SET name=? WHERE id=?")->execute([$name,$id]); $success = 'Grade mis à jour.'; }
    }
    if ($action === 'delete_grade') {
        $db->prepare("DELETE FROM lssd_dispatch_grades WHERE id=?")->execute([(int)$_POST['id']]);
        $success = 'Grade supprimé.';
    }

    /* ── VÉHICULES ── */
    if ($action === 'add_vehicle') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nom requis.'; }
        else {
            try {
                $pos = $db->query("SELECT COALESCE(MAX(position),0)+1 FROM lssd_dispatch_patrol_vehicles")->fetchColumn();
                $db->prepare("INSERT INTO lssd_dispatch_patrol_vehicles (name,position) VALUES (?,?)")->execute([$name, $pos]);
                $success = 'Véhicule "'.htmlspecialchars($name).'" ajouté.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
    if ($action === 'edit_vehicle') {
        $id = (int)$_POST['id']; $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nom requis.'; }
        else { $db->prepare("UPDATE lssd_dispatch_patrol_vehicles SET name=? WHERE id=?")->execute([$name,$id]); $success = 'Véhicule mis à jour.'; }
    }
    if ($action === 'delete_vehicle') {
        $db->prepare("DELETE FROM lssd_dispatch_patrol_vehicles WHERE id=?")->execute([(int)$_POST['id']]);
        try { $db->prepare("DELETE FROM lssd_dispatch_vehicle_plates WHERE vehicle_id=?")->execute([(int)$_POST['id']]); } catch (Exception $e) {}
        $success = 'Véhicule supprimé.';
    }

    /* ── PLAQUES ── */
    if ($action === 'add_plate') {
        $vid   = (int)($_POST['vehicle_id'] ?? 0);
        $plate = strtoupper(trim($_POST['plate'] ?? ''));
        if ($vid <= 0 || $plate === '') { $error = 'Véhicule et plaque requis.'; }
        elseif (mb_strlen($plate) > 16) { $error = 'Plaque trop longue (max 16).'; }
        else {
            try {
                $db->prepare("INSERT INTO lssd_dispatch_vehicle_plates(vehicle_id, plate) VALUES(?,?)")->execute([$vid, $plate]);
                $success = 'Plaque "'.htmlspecialchars($plate).'" ajoutée.';
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) $error = 'Cette plaque existe déjà pour ce véhicule.';
                else $error = $e->getMessage();
            }
        }
    }
    if ($action === 'add_plates_bulk') {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $raw = (string)($_POST['plates'] ?? '');
        if ($vid <= 0) { $error = 'Véhicule invalide.'; }
        else {
            $arr = preg_split('/[\s,;]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
            $added = 0; $skipped = 0;
            $ins = $db->prepare("INSERT IGNORE INTO lssd_dispatch_vehicle_plates(vehicle_id, plate) VALUES(?,?)");
            foreach ($arr as $p) {
                $p = trim($p);
                if ($p === '' || mb_strlen($p) > 16) { $skipped++; continue; }
                try { $ins->execute([$vid, $p]); if ($ins->rowCount() > 0) $added++; else $skipped++; }
                catch (Exception $e) { $skipped++; }
            }
            $success = "$added plaque(s) ajoutée(s) — $skipped ignorée(s).";
        }
    }
    if ($action === 'delete_plate') {
        $db->prepare("DELETE FROM lssd_dispatch_vehicle_plates WHERE id=?")->execute([(int)$_POST['id']]);
        $success = 'Plaque supprimée.';
    }

    /* ── LABELS ── */
    if ($action === 'add_label') {
        $name = trim($_POST['name'] ?? ''); $color = trim($_POST['color'] ?? '#10b981');
        if ($name === '') { $error = 'Nom requis.'; }
        else {
            try { $db->prepare("INSERT INTO lssd_dispatch_labels (name,color) VALUES (?,?)")->execute([$name,$color]); $success = 'Label "'.htmlspecialchars($name).'" ajouté.'; }
            catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
    if ($action === 'edit_label') {
        $id = (int)$_POST['id']; $name = trim($_POST['name'] ?? ''); $color = trim($_POST['color'] ?? '#10b981');
        if ($name === '') { $error = 'Nom requis.'; }
        else { $db->prepare("UPDATE lssd_dispatch_labels SET name=?,color=? WHERE id=?")->execute([$name,$color,$id]); $success = 'Label mis à jour.'; }
    }
    if ($action === 'delete_label') {
        $id = (int)$_POST['id'];
        try { $db->prepare("DELETE FROM lssd_dispatch_card_labels WHERE label_id=?")->execute([$id]); } catch (Exception $e) {}
        $db->prepare("DELETE FROM lssd_dispatch_labels WHERE id=?")->execute([$id]);
        $success = 'Label supprimé.';
    }

    /* ── OTHER TAGS ── */
    if ($action === 'add_other_tag') {
        $name = trim($_POST['name'] ?? ''); $color = trim($_POST['color'] ?? 'gray');
        if ($name === '') { $error = 'Nom requis.'; }
        elseif (isset($otherTags[$name])) { $error = 'Ce tag existe déjà.'; }
        else {
            $otherTags[$name] = $color;
            $json = json_encode($otherTags, JSON_UNESCAPED_UNICODE);
            $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
            $success = 'Tag "'.htmlspecialchars($name).'" ajouté.';
        }
    }
    if ($action === 'edit_other_tag') {
        $old = $_POST['old_name'] ?? ''; $name = trim($_POST['name'] ?? ''); $color = trim($_POST['color'] ?? 'gray');
        if ($name === '') { $error = 'Nom requis.'; }
        else {
            if (isset($otherTags[$old])) unset($otherTags[$old]);
            $otherTags[$name] = $color;
            $json = json_encode($otherTags, JSON_UNESCAPED_UNICODE);
            $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
            $success = 'Tag mis à jour.';
        }
    }
    if ($action === 'delete_other_tag') {
        $name = $_POST['name'] ?? '';
        if (isset($otherTags[$name])) unset($otherTags[$name]);
        $json = json_encode($otherTags, JSON_UNESCAPED_UNICODE);
        $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
        $success = 'Tag supprimé.';
    }

    /* ── CODES 10 ── */
    if ($action === 'add_code_ten') {
        $code = strtoupper(trim($_POST['code'] ?? '')); $color = trim($_POST['color'] ?? 'gray');
        if ($code === '') { $error = 'Code requis.'; }
        elseif (isset($codeTen[$code])) { $error = 'Ce code existe déjà.'; }
        else {
            $codeTen[$code] = $color; ksort($codeTen);
            $json = json_encode($codeTen);
            $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
            $success = 'Code "'.htmlspecialchars($code).'" ajouté.';
        }
    }
    if ($action === 'edit_code_ten') {
        $old = $_POST['old_code'] ?? ''; $code = strtoupper(trim($_POST['code'] ?? '')); $color = trim($_POST['color'] ?? 'gray');
        if ($code === '') { $error = 'Code requis.'; }
        else {
            if (isset($codeTen[$old])) unset($codeTen[$old]);
            $codeTen[$code] = $color; ksort($codeTen);
            $json = json_encode($codeTen);
            $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
            $success = 'Code mis à jour.';
        }
    }
    if ($action === 'delete_code_ten') {
        $code = $_POST['code'] ?? '';
        if (isset($codeTen[$code])) unset($codeTen[$code]);
        $json = json_encode($codeTen);
        $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
        $success = 'Code supprimé.';
    }

    /* ── THÈME ── */
    if ($action === 'save_theme') {
        $theme['bg_type']        = in_array($_POST['bg_type'] ?? '', ['gradient','solid','image']) ? $_POST['bg_type'] : 'gradient';
        $theme['bg_color']       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['bg_color'] ?? '') ? $_POST['bg_color'] : '#060f1d';
        $theme['accent']         = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent'] ?? '')    ? $_POST['accent']  : '#00e5ff';
        $theme['board_title']    = mb_substr(trim($_POST['board_title'] ?? 'DISPATCH BOARD'), 0, 40) ?: 'DISPATCH BOARD';
        $theme['bg_overlay']     = (string)max(0, min(1,  (float)($_POST['bg_overlay']     ?? 0.55)));
        $theme['col_opacity']    = (string)max(0, min(1,  (float)($_POST['col_opacity']    ?? 0.88)));
        $theme['topbar_opacity'] = (string)max(0, min(1,  (float)($_POST['topbar_opacity'] ?? 0.97)));
        $theme['col_blur']       = (string)max(0, min(30, (int)($_POST['col_blur']         ?? 8)));
        $theme['card_radius']    = (string)max(4, min(24, (int)($_POST['card_radius']      ?? 12)));
        // Upload image
        if (!empty($_FILES['bg_image_file']['name'])) {
            $f = $_FILES['bg_image_file'];
            if (!in_array($f['type'], ['image/jpeg','image/png','image/webp','image/gif'])) {
                $error = 'Format invalide (JPG, PNG, WEBP, GIF).';
            } elseif ($f['size'] > 8 * 1024 * 1024) {
                $error = 'Image trop lourde (max 8 MB).';
            } else {
                if (!is_dir(BG_UPLOAD_DIR)) mkdir(BG_UPLOAD_DIR, 0755, true);
                $fn = 'lssd_dispatch_bg_'.time().'.'.pathinfo($f['name'], PATHINFO_EXTENSION);
                if (move_uploaded_file($f['tmp_name'], BG_UPLOAD_DIR.$fn)) {
                    if (!empty($theme['bg_image'])) { $old = BG_UPLOAD_DIR.basename($theme['bg_image']); if(file_exists($old)) @unlink($old); }
                    $theme['bg_image'] = BG_UPLOAD_URL.$fn;
                } else { $error = 'Erreur upload.'; }
            }
        } elseif (!empty($_POST['bg_image_url'])) {
            $theme['bg_image'] = filter_var($_POST['bg_image_url'], FILTER_VALIDATE_URL) ? $_POST['bg_image_url'] : $theme['bg_image'];
        }
        if (!$error) {
            $json = json_encode($theme);
            $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('lssd_dispatch_theme',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
            $success = 'Apparence sauvegardée.';
        }
    }
    if ($action === 'reset_theme') {
        $theme = $THEME_DEFAULTS; $curTab = 'apparence';
        $json  = json_encode($theme);
        $db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('lssd_dispatch_theme',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);
        $success = 'Apparence réinitialisée.';
    }

    if ($success) {
        header("Location: dispatch-custom.php?tab={$curTab}&ok=".urlencode($success));
        exit;
    }
}

if (!empty($_GET['ok'])) $success = $_GET['ok'];

/* ── Charger les listes (TOUTES avec try/catch) ── */
$grades = [];
try { $grades = $db->query("SELECT * FROM lssd_dispatch_grades ORDER BY position")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$vehicles = [];
try { $vehicles = $db->query("SELECT * FROM lssd_dispatch_patrol_vehicles ORDER BY position")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

/* Plaques groupées par vehicle_id */
$platesByVehicle = [];
try {
    $rowsP = $db->query("SELECT id, vehicle_id, plate FROM lssd_dispatch_vehicle_plates ORDER BY plate")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsP as $rp) { $platesByVehicle[(int)$rp['vehicle_id']][] = $rp; }
} catch (Exception $e) {}
/* Plaques utilisées sur des cartes (pour badge "X utilisées") */
$usedPlates = [];
try {
    $rowsU = $db->query("SELECT vehicle_plate FROM lssd_dispatch_cards WHERE vehicle_plate IS NOT NULL AND vehicle_plate <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rowsU as $u) { $usedPlates[$u] = true; }
} catch (Exception $e) {}

$labels = [];
try { $labels = $db->query("SELECT * FROM lssd_dispatch_labels ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$activeTab = $_GET['tab'] ?? 'apparence';

/* ── Helpers ── */
function chex(string $k, array $C): string { return $C[$k]['hex'] ?? '#64748b'; }

function cswatches(string $fn, string $sel, array $C): void {
    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">';
    foreach ($C as $k => $m) {
        $ch  = $sel === $k ? 'checked' : '';
        $brd = $sel === $k ? '#fff'    : 'transparent';
        echo "<label title='{$m['label']}' style='cursor:pointer;'>
            <input type='radio' name='{$fn}' value='{$k}' {$ch} class='dc-color-r' style='display:none;'>
            <span class='dc-sw' style='display:inline-block;width:26px;height:26px;border-radius:6px;background:{$m['hex']};border:2.5px solid {$brd};transition:all .15s;'></span>
        </label>";
    }
    echo '</div>';
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Grilles données ── */
.dc-grid { display:grid; grid-template-columns:1fr 340px; gap:20px; align-items:start; }
@media(max-width:1100px){ .dc-grid{ grid-template-columns:1fr; } }
.dc-list  { display:flex; flex-direction:column; gap:6px; }
.dc-row   { display:flex; align-items:center; gap:12px; padding:10px 14px; border:1px solid var(--border); border-radius:10px; background:var(--bg-elevated); transition:border-color .2s; }
.dc-row:hover { border-color:var(--border-hover); }
.dc-row-ico   { width:32px; height:32px; border-radius:8px; display:grid; place-items:center; flex-shrink:0; font-size:.82rem; }
.dc-row-name  { flex:1; font-weight:600; font-size:.875rem; }
.dc-row-sub   { font-size:.7rem; color:var(--text-secondary); margin-top:2px; }
.dc-row-acts  { display:flex; gap:6px; flex-shrink:0; }
.dc-ef        { flex-direction:column; gap:10px; background:var(--bg-elevated); border:1px solid var(--blue); border-radius:10px; padding:14px 16px; margin-top:4px; }
.dc-empty     { text-align:center; padding:32px 20px; color:var(--text-secondary); font-size:.85rem; }
.dc-count     { font-size:.65rem; font-weight:700; background:var(--bg-elevated); border:1px solid var(--border); color:var(--text-secondary); padding:1px 7px; border-radius:8px; margin-left:6px; }

/* ── Thème ── */
.th-grid { display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start; }
@media(max-width:1200px){ .th-grid{ grid-template-columns:1fr; } }
.board-preview { width:100%; height:220px; border-radius:14px; overflow:hidden; position:relative; border:1px solid var(--border); }
.board-preview-bg      { position:absolute; inset:0; transition:all .3s; }
.board-preview-overlay { position:absolute; inset:0; background:rgba(0,0,0,0); transition:background .3s; }
.board-preview-topbar  { position:relative; z-index:2; height:36px; display:flex; align-items:center; padding:0 12px; gap:8px; border-bottom:1px solid rgba(255,255,255,.1); transition:background .3s; }
.board-preview-title   { font-family:'DM Mono',monospace; font-size:.65rem; font-weight:800; letter-spacing:2px; transition:color .3s; }
.board-preview-cols    { position:relative; z-index:2; display:flex; gap:8px; padding:10px 12px; }
.board-preview-col     { width:80px; height:120px; border-radius:8px; border:1px solid rgba(255,255,255,.12); overflow:hidden; transition:all .3s; }
.board-preview-col-hdr { height:22px; display:flex; align-items:center; padding:0 7px; gap:5px; border-bottom:2px solid transparent; }
.board-preview-col-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.board-preview-col-name{ font-size:.5rem; font-weight:700; color:rgba(255,255,255,.8); }
.board-preview-card    { margin:5px 5px 0; height:20px; border-radius:5px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); }
.dt-slider-wrap { display:flex; align-items:center; gap:10px; }
.dt-slider { flex:1; -webkit-appearance:none; height:4px; border-radius:2px; background:var(--border); outline:none; cursor:pointer; }
.dt-slider::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:16px; border-radius:50%; background:var(--blue); cursor:pointer; border:2px solid var(--bg-elevated); transition:transform .15s; }
.dt-slider::-webkit-slider-thumb:hover { transform:scale(1.2); }
.bg-type-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.bg-type-btn  { padding:10px; border-radius:10px; border:2px solid var(--border); background:var(--bg-elevated); cursor:pointer; text-align:center; transition:all .2s; font-size:.8rem; color:var(--text-secondary); }
.bg-type-btn:hover  { border-color:var(--border-hover); color:var(--text-primary); }
.bg-type-btn.active { border-color:var(--blue); color:var(--blue); background:rgba(16,185,129,.08); }
.bg-type-btn i { display:block; font-size:1.2rem; margin-bottom:5px; }
.upload-zone  { border:2px dashed var(--border); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all .2s; color:var(--text-secondary); font-size:.82rem; }
.upload-zone:hover { border-color:var(--blue); color:var(--blue); background:rgba(16,185,129,.04); }
.upload-zone i { font-size:1.5rem; display:block; margin-bottom:6px; opacity:.6; }

/* ── Plaques par véhicule ── */
.vehicle-block { display:flex; flex-direction:column; gap:0; margin-bottom:4px; }
.vehicle-row { border-bottom-left-radius:0; border-bottom-right-radius:0; }
.vehicle-block:has(.vp-panel[style*="display: none"]) .vehicle-row,
.vehicle-block:has(.vp-panel[style*="display:none"]) .vehicle-row { border-bottom-left-radius:10px; border-bottom-right-radius:10px; }
.vp-pill {
    display:inline-flex; align-items:center; gap:4px; font-size:.65rem; font-weight:700;
    padding:2px 8px; border-radius:50px;
    background:rgba(16,185,129,.1); color:var(--blue); border:1px solid rgba(16,185,129,.22);
    margin-right:5px; font-family:'DM Mono',monospace; letter-spacing:.3px;
}
.vp-pill.vp-pill-warn { background:rgba(245,158,11,.1); color:var(--orange,#f59e0b); border-color:rgba(245,158,11,.22); }
.vp-panel {
    background:rgba(16,185,129,.04); border:1px solid var(--border); border-top:none;
    border-radius:0 0 10px 10px; padding:14px 16px; animation:vpSlide .2s ease;
}
@keyframes vpSlide { from { opacity:0; transform:translateY(-3px); } to { opacity:1; transform:translateY(0); } }
.vp-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr));
    gap:6px; margin-bottom:14px;
}
.vp-empty { grid-column:1/-1; padding:14px; text-align:center; color:var(--text-secondary); font-size:.78rem; font-style:italic; }
.vp-chip {
    display:flex; align-items:center; gap:4px; padding:6px 8px; border-radius:6px;
    border:1px solid; font-family:'DM Mono',monospace; font-size:.72rem;
    font-weight:700; letter-spacing:.5px; transition:all .15s; position:relative;
}
.vp-chip.free {
    background:rgba(16,185,129,.08); border-color:rgba(16,185,129,.32); color:#10b981;
}
.vp-chip.used {
    background:rgba(100,116,139,.06); border-color:rgba(100,116,139,.22); color:var(--text-secondary);
    text-decoration:line-through; opacity:.7;
}
.vp-chip-txt { flex:1; min-width:0; }
.vp-chip-del {
    background:transparent; border:none; cursor:pointer; padding:1px 4px;
    color:var(--text-secondary); border-radius:3px; transition:all .14s; font-size:.7rem;
}
.vp-chip-del:hover { background:rgba(239,68,68,.15); color:var(--red,#ef4444); }
.vp-add-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:10px; padding-top:12px;
    border-top:1px dashed var(--border);
}
@media(max-width:700px) { .vp-add-grid { grid-template-columns:1fr; } }
.vp-add-form { display:flex; flex-direction:column; gap:4px; }
.vp-tip {
    display:flex; gap:8px; align-items:flex-start; margin-top:14px; padding:10px 12px;
    background:rgba(16,185,129,.06); border:1px solid rgba(16,185,129,.18); border-radius:8px;
    font-size:.75rem; color:var(--text-secondary); line-height:1.45;
}
.vp-tip i { color:var(--blue); margin-top:2px; }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1>
            <i class="fa-solid fa-radio" style="color:var(--cyan);margin-right:10px;"></i>
            Custom Dispatch <span style="color:var(--cyan);"><?php echo htmlspecialchars($lssdName); ?></span>
        </h1>
        <p>Apparence, grades, véhicules, labels, tags et codes 10</p>
    </div>
    <div class="page-header-right">
        <a href="<?php echo SITE_URL; ?>/pages/lssd/dispatch.php" target="_blank" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir le Dispatch
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- TABS -->
<div class="tabs" data-tab-group="dc">
    <button class="tab-btn <?php echo $activeTab==='apparence' ?'active':''; ?>" data-tab="apparence">
        <i class="fa-solid fa-palette"></i> Apparence
    </button>
    <button class="tab-btn <?php echo $activeTab==='grades' ?'active':''; ?>" data-tab="grades">
        <i class="fa-solid fa-shield-halved"></i> Grades
        <span class="dc-count"><?php echo count($grades); ?></span>
    </button>
    <button class="tab-btn <?php echo $activeTab==='vehicles' ?'active':''; ?>" data-tab="vehicles">
        <i class="fa-solid fa-car"></i> Véhicules
        <span class="dc-count"><?php echo count($vehicles); ?></span>
    </button>
    <button class="tab-btn <?php echo $activeTab==='labels' ?'active':''; ?>" data-tab="labels">
        <i class="fa-solid fa-tag"></i> Labels
        <span class="dc-count"><?php echo count($labels); ?></span>
    </button>
    <button class="tab-btn <?php echo $activeTab==='other_tags' ?'active':''; ?>" data-tab="other_tags">
        <i class="fa-solid fa-hashtag"></i> Tags
        <span class="dc-count"><?php echo count($otherTags); ?></span>
    </button>
    <button class="tab-btn <?php echo $activeTab==='code_ten' ?'active':''; ?>" data-tab="code_ten">
        <i class="fa-solid fa-walkie-talkie"></i> Codes 10
        <span class="dc-count"><?php echo count($codeTen); ?></span>
    </button>
</div>

<!-- ══════════════════════════════════════════
     ONGLET APPARENCE
══════════════════════════════════════════ -->
<div class="tab-pane <?php echo $activeTab==='apparence'?'active':''; ?>" id="tab-apparence">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="save_theme">
<input type="hidden" name="current_tab" value="apparence">

<div class="th-grid">
    <div style="display:flex;flex-direction:column;gap:16px;">

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-image"></i> Fond du board</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Type de fond</label>
                    <div class="bg-type-grid">
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='gradient'?'active':''; ?>" onclick="setBgType('gradient',event)">
                            <i class="fa-solid fa-circle-half-stroke"></i> Dégradé
                        </div>
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='solid'?'active':''; ?>" onclick="setBgType('solid',event)">
                            <i class="fa-solid fa-square"></i> Couleur unie
                        </div>
                        <div class="bg-type-btn <?php echo $theme['bg_type']==='image'?'active':''; ?>" onclick="setBgType('image',event)">
                            <i class="fa-solid fa-image"></i> Image
                        </div>
                    </div>
                    <input type="hidden" name="bg_type" id="bgTypeInput" value="<?php echo htmlspecialchars($theme['bg_type']); ?>">
                </div>

                <div class="form-group" id="bgColorGroup" style="display:<?php echo $theme['bg_type']==='image'?'none':'block'; ?>;">
                    <label class="form-label">Couleur de fond</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="bg_color" id="bgColor" class="form-control"
                               value="<?php echo htmlspecialchars($theme['bg_color']); ?>"
                               style="height:38px;padding:4px;width:52px;flex-shrink:0;" oninput="updatePreview()">
                        <span style="font-size:.8rem;color:var(--text-secondary);">Couleur principale du fond</span>
                    </div>
                </div>

                <div id="bgImageGroup" style="display:<?php echo $theme['bg_type']==='image'?'block':'none'; ?>;">
                    <div class="form-group">
                        <label class="form-label">Upload image de fond</label>
                        <label class="upload-zone" for="bgImageFile">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            Cliquez pour uploader (JPG, PNG, WEBP — max 8 MB)
                        </label>
                        <input type="file" id="bgImageFile" name="bg_image_file" accept="image/*" style="display:none;" onchange="previewBgImage(this)">
                        <?php if (!empty($theme['bg_image'])): ?>
                        <div style="margin-top:8px;">
                            <img src="<?php echo htmlspecialchars($theme['bg_image']); ?>" alt="" style="height:60px;border-radius:8px;border:1px solid var(--border);">
                            <div style="font-size:.72rem;color:var(--text-secondary);margin-top:4px;">Image actuelle</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">— ou URL externe</label>
                        <input type="url" name="bg_image_url" class="form-control"
                               value="<?php echo htmlspecialchars($theme['bg_image']); ?>"
                               placeholder="https://exemple.com/image.jpg" oninput="updatePreview()">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Voile sombre : <span id="overlayVal"><?php echo $theme['bg_overlay']; ?></span></label>
                        <div class="dt-slider-wrap">
                            <input type="range" name="bg_overlay" class="dt-slider" id="overlaySlider"
                                   min="0" max="1" step="0.05" value="<?php echo htmlspecialchars($theme['bg_overlay']); ?>"
                                   oninput="document.getElementById('overlayVal').textContent=this.value;updatePreview();">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Accent & Titre</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Couleur d'accent</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="accent" id="accentColor" class="form-control"
                               value="<?php echo htmlspecialchars($theme['accent']); ?>"
                               style="height:38px;padding:4px;width:52px;flex-shrink:0;" oninput="updatePreview()">
                        <span style="font-size:.8rem;color:var(--text-secondary);">Bordures, logo, badges et boutons.</span>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Titre du board</label>
                    <input type="text" name="board_title" class="form-control"
                           value="<?php echo htmlspecialchars($theme['board_title']); ?>"
                           maxlength="40" placeholder="DISPATCH BOARD" oninput="updatePreview()">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-sliders"></i> Colonnes & Cartes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Opacité des colonnes : <span id="colOpacityVal"><?php echo $theme['col_opacity']; ?></span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="col_opacity" class="dt-slider" id="colOpacitySlider"
                               min="0" max="1" step="0.05" value="<?php echo htmlspecialchars($theme['col_opacity']); ?>"
                               oninput="document.getElementById('colOpacityVal').textContent=this.value;updatePreview();">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Flou des colonnes : <span id="colBlurVal"><?php echo $theme['col_blur']; ?>px</span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="col_blur" class="dt-slider" id="colBlurSlider"
                               min="0" max="30" step="1" value="<?php echo htmlspecialchars($theme['col_blur']); ?>"
                               oninput="document.getElementById('colBlurVal').textContent=this.value+'px';updatePreview();">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Rayon des cartes : <span id="cardRadiusVal"><?php echo $theme['card_radius']; ?>px</span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="card_radius" class="dt-slider" id="cardRadiusSlider"
                               min="4" max="24" step="1" value="<?php echo htmlspecialchars($theme['card_radius']); ?>"
                               oninput="document.getElementById('cardRadiusVal').textContent=this.value+'px';updatePreview();">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Opacité topbar : <span id="topbarOpacityVal"><?php echo $theme['topbar_opacity']; ?></span></label>
                    <div class="dt-slider-wrap">
                        <input type="range" name="topbar_opacity" class="dt-slider" id="topbarOpacitySlider"
                               min="0" max="1" step="0.05" value="<?php echo htmlspecialchars($theme['topbar_opacity']); ?>"
                               oninput="document.getElementById('topbarOpacityVal').textContent=this.value;updatePreview();">
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">
                <i class="fa-solid fa-floppy-disk"></i> Sauvegarder l'apparence
            </button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="reset_theme">
                <input type="hidden" name="current_tab" value="apparence">
                <button type="submit" class="btn btn-secondary" onclick="return confirm('Réinitialiser aux valeurs par défaut ?')">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </button>
            </form>
        </div>
    </div>

    <!-- Aperçu sticky -->
    <div style="position:sticky;top:80px;">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-eye"></i> Aperçu temps réel</div></div>
            <div class="card-body" style="padding:16px;">
                <div class="board-preview" id="boardPreview">
                    <div class="board-preview-bg" id="previewBg"></div>
                    <div class="board-preview-overlay" id="previewOverlay"></div>
                    <div class="board-preview-topbar" id="previewTopbar">
                        <span class="board-preview-title" id="previewTitle">DISPATCH BOARD</span>
                        <div style="margin-left:auto;display:flex;gap:4px;">
                            <div style="width:20px;height:8px;border-radius:3px;background:rgba(0,229,255,.2);border:1px solid rgba(0,229,255,.3);"></div>
                            <div style="width:20px;height:8px;border-radius:3px;background:rgba(0,229,255,.2);border:1px solid rgba(0,229,255,.3);"></div>
                        </div>
                    </div>
                    <div class="board-preview-cols">
                        <?php foreach (['#00e5ff','#22c55e','#ef4444'] as $col): ?>
                        <div class="board-preview-col" style="background:rgba(11,25,41,.88);">
                            <div class="board-preview-col-hdr" style="border-color:<?php echo $col; ?>33;">
                                <div class="board-preview-col-dot" style="background:<?php echo $col; ?>;box-shadow:0 0 5px <?php echo $col; ?>66;"></div>
                                <div class="board-preview-col-name">COLONNE</div>
                            </div>
                            <div class="board-preview-card"></div>
                            <div class="board-preview-card"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p style="margin-top:10px;font-size:.72rem;color:var(--text-secondary);">
                    <i class="fa-solid fa-circle-info" style="color:var(--blue);margin-right:4px;"></i>
                    Aperçu indicatif — le rendu peut légèrement varier.
                </p>
            </div>
        </div>
    </div>
</div>
</form>
</div>

<!-- ══ GRADES ══ -->
<div class="tab-pane <?php echo $activeTab==='grades'?'active':''; ?>" id="tab-grades">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-shield-halved"></i> Grades</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if (empty($grades)): ?>
        <div class="dc-empty"><i class="fa-solid fa-shield-halved" style="font-size:1.8rem;display:block;margin-bottom:10px;opacity:.25;"></i>Aucun grade configuré</div>
    <?php else: foreach ($grades as $g): $sid = 'gef'.$g['id']; ?>
    <div>
        <div class="dc-row">
            <div class="dc-row-ico" style="background:rgba(16,185,129,.12);color:var(--blue);"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="dc-row-name"><?php echo htmlspecialchars($g['name']); ?></div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_grade">
                    <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                    <input type="hidden" name="current_tab" value="grades">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_grade">
            <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
            <input type="hidden" name="current_tab" value="grades">
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($g['name']); ?>" required style="flex:1;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter un grade</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_grade">
            <input type="hidden" name="current_tab" value="grades">
            <label class="form-label">Nom du grade</label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="name" class="form-control" placeholder="Ex: Lieutenant, Sergeant..." required>
                <button type="submit" class="btn btn-primary" style="flex-shrink:0;"><i class="fa-solid fa-plus"></i></button>
            </div>
        </form>
    </div>
</div>
</div></div>

<!-- ══ VÉHICULES ══ -->
<div class="tab-pane <?php echo $activeTab==='vehicles'?'active':''; ?>" id="tab-vehicles">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-car"></i> Véhicules &amp; plaques associées</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if (empty($vehicles)): ?>
        <div class="dc-empty"><i class="fa-solid fa-car" style="font-size:1.8rem;display:block;margin-bottom:10px;opacity:.25;"></i>Aucun véhicule configuré — créez-en un ci-contre.</div>
    <?php else: foreach ($vehicles as $v):
        $sid     = 'vef'.$v['id'];
        $vid     = (int)$v['id'];
        $myPlates = $platesByVehicle[$vid] ?? [];
        $totalP   = count($myPlates);
        $usedCnt  = 0;
        foreach ($myPlates as $mp) { if (!empty($usedPlates[$mp['plate']])) $usedCnt++; }
    ?>
    <div class="vehicle-block">
        <div class="dc-row vehicle-row">
            <div class="dc-row-ico" style="background:rgba(16,185,129,.12);color:var(--green);"><i class="fa-solid fa-car"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="dc-row-name"><?php echo htmlspecialchars($v['name']); ?></div>
                <div class="dc-row-sub">
                    <span class="vp-pill"><i class="fa-solid fa-id-card"></i> <?php echo $totalP; ?> plaque<?php echo $totalP>1?'s':''; ?></span>
                    <?php if ($usedCnt>0): ?><span class="vp-pill vp-pill-warn"><i class="fa-solid fa-circle-dot"></i> <?php echo $usedCnt; ?> utilisée<?php echo $usedCnt>1?'s':''; ?></span><?php endif; ?>
                </div>
            </div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm" onclick="vpToggle(<?php echo $vid; ?>)" id="vp-toggle-<?php echo $vid; ?>" title="Voir / cacher les plaques">
                    <i class="fa-solid fa-id-card-clip"></i> <span id="vp-toggle-lbl-<?php echo $vid; ?>">Plaques</span>
                </button>
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Renommer"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce véhicule ET toutes ses plaques ?');">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                    <input type="hidden" name="current_tab" value="vehicles">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_vehicle">
            <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
            <input type="hidden" name="current_tab" value="vehicles">
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($v['name']); ?>" required style="flex:1;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>

        <div class="vp-panel" id="vp-panel-<?php echo $vid; ?>" style="display:none;">
            <div class="vp-grid" id="vp-grid-<?php echo $vid; ?>">
                <?php if (empty($myPlates)): ?>
                    <div class="vp-empty">Aucune plaque pour ce véhicule.</div>
                <?php else: foreach ($myPlates as $mp): $isUsed = !empty($usedPlates[$mp['plate']]); ?>
                    <div class="vp-chip <?php echo $isUsed ? 'used':'free'; ?>" title="<?php echo $isUsed ? 'Plaque utilisée par une carte' : 'Plaque disponible'; ?>">
                        <span class="vp-chip-txt"><?php echo htmlspecialchars($mp['plate']); ?></span>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette plaque ?');">
                            <input type="hidden" name="action" value="delete_plate">
                            <input type="hidden" name="id" value="<?php echo (int)$mp['id']; ?>">
                            <input type="hidden" name="current_tab" value="vehicles">
                            <button type="submit" class="vp-chip-del" title="Supprimer"><i class="fa-solid fa-times"></i></button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="vp-add-grid">
                <form method="POST" class="vp-add-form" style="grid-column: span 1;">
                    <input type="hidden" name="action" value="add_plate">
                    <input type="hidden" name="vehicle_id" value="<?php echo $vid; ?>">
                    <input type="hidden" name="current_tab" value="vehicles">
                    <label class="form-label" style="font-size:.7rem;"><i class="fa-solid fa-plus"></i> Ajouter une plaque</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" name="plate" class="form-control vp-plate-input" placeholder="Ex: AB123CD" maxlength="16" required style="flex:1;text-transform:uppercase;font-family:'DM Mono',monospace;letter-spacing:1px;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </form>
                <form method="POST" class="vp-add-form" style="grid-column: span 1;">
                    <input type="hidden" name="action" value="add_plates_bulk">
                    <input type="hidden" name="vehicle_id" value="<?php echo $vid; ?>">
                    <input type="hidden" name="current_tab" value="vehicles">
                    <label class="form-label" style="font-size:.7rem;"><i class="fa-solid fa-list"></i> Ajout en masse (séparées par espaces, virgules ou retours)</label>
                    <div style="display:flex;gap:6px;align-items:flex-start;">
                        <textarea name="plates" class="form-control vp-plate-input" rows="2" placeholder="GTD547PM, OPL8759ZZ, ABC123XY..." required style="flex:1;text-transform:uppercase;font-family:'DM Mono',monospace;letter-spacing:1px;resize:vertical;"></textarea>
                        <button type="submit" class="btn btn-primary btn-sm" style="align-self:stretch;"><i class="fa-solid fa-cloud-arrow-up"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter un véhicule</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_vehicle">
            <input type="hidden" name="current_tab" value="vehicles">
            <label class="form-label">Nom du véhicule</label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="name" class="form-control" placeholder="Ex: Alamo, Police Cruiser, SUV..." required>
                <button type="submit" class="btn btn-primary" style="flex-shrink:0;"><i class="fa-solid fa-plus"></i></button>
            </div>
        </form>
        <div class="vp-tip">
            <i class="fa-solid fa-circle-info"></i>
            <span>Une fois le véhicule créé, cliquez sur <strong>Plaques</strong> pour gérer la liste des plaques associées. Côté Dispatch, l'opérateur ne pourra sélectionner que les plaques disponibles.</span>
        </div>
    </div>
</div>
</div></div>

<!-- ══ LABELS ══ -->
<div class="tab-pane <?php echo $activeTab==='labels'?'active':''; ?>" id="tab-labels">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-tag"></i> Labels des cartes</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if (empty($labels)): ?>
        <div class="dc-empty"><i class="fa-solid fa-tag" style="font-size:1.8rem;display:block;margin-bottom:10px;opacity:.25;"></i>Aucun label — créez-en un ci-contre</div>
    <?php else: foreach ($labels as $lb): $sid = 'lef'.$lb['id']; ?>
    <div>
        <div class="dc-row">
            <div style="width:32px;height:32px;border-radius:8px;background:<?php echo htmlspecialchars($lb['color']); ?>22;display:grid;place-items:center;flex-shrink:0;">
                <div style="width:12px;height:12px;border-radius:50%;background:<?php echo htmlspecialchars($lb['color']); ?>;"></div>
            </div>
            <div>
                <div class="dc-row-name"><?php echo htmlspecialchars($lb['name']); ?></div>
                <div class="dc-row-sub" style="font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($lb['color']); ?></div>
            </div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_label">
                    <input type="hidden" name="id" value="<?php echo $lb['id']; ?>">
                    <input type="hidden" name="current_tab" value="labels">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_label">
            <input type="hidden" name="id" value="<?php echo $lb['id']; ?>">
            <input type="hidden" name="current_tab" value="labels">
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" style="font-size:.75rem;">Nom</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($lb['name']); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label" style="font-size:.75rem;">Couleur</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" name="color" class="form-control" value="<?php echo htmlspecialchars($lb['color']); ?>" style="height:38px;padding:4px;width:52px;">
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Sauvegarder</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter un label</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_label">
            <input type="hidden" name="current_tab" value="labels">
            <div class="form-group">
                <label class="form-label">Nom</label>
                <input type="text" name="name" class="form-control" placeholder="Ex: Priorité haute..." required>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Couleur</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" name="color" class="form-control" value="#10b981" style="height:38px;padding:4px;width:52px;">
                    <span style="font-size:.8rem;color:var(--text-secondary);">Cliquez pour choisir</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<!-- ══ OTHER TAGS ══ -->
<div class="tab-pane <?php echo $activeTab==='other_tags'?'active':''; ?>" id="tab-other_tags">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-hashtag"></i> Tags de statut</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if (empty($otherTags)): ?>
        <div class="dc-empty">Aucun tag configuré</div>
    <?php else: foreach ($otherTags as $tn => $tc):
        $hex = chex($tc, $COLORS);
        $sid = 'otef_'.preg_replace('/[^a-z0-9]/i', '_', $tn);
    ?>
    <div>
        <div class="dc-row">
            <div style="width:12px;height:12px;border-radius:50%;background:<?php echo $hex; ?>;flex-shrink:0;box-shadow:0 0 6px <?php echo $hex; ?>66;"></div>
            <div>
                <div class="dc-row-name"><?php echo htmlspecialchars($tn); ?></div>
                <div class="dc-row-sub"><?php echo $COLORS[$tc]['label'] ?? $tc; ?></div>
            </div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_other_tag">
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($tn); ?>">
                    <input type="hidden" name="current_tab" value="other_tags">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_other_tag">
            <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($tn); ?>">
            <input type="hidden" name="current_tab" value="other_tags">
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" style="font-size:.75rem;">Nom</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($tn); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label" style="font-size:.75rem;">Couleur</label>
                <?php cswatches('color', $tc, $COLORS); ?>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> OK</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter un tag</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_other_tag">
            <input type="hidden" name="current_tab" value="other_tags">
            <div class="form-group">
                <label class="form-label">Nom</label>
                <input type="text" name="name" class="form-control" placeholder="Ex: En Pause, Code 5..." required>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Couleur</label>
                <?php cswatches('color', 'gray', $COLORS); ?>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<!-- ══ CODES 10 ══ -->
<div class="tab-pane <?php echo $activeTab==='code_ten'?'active':''; ?>" id="tab-code_ten">
<div class="alert alert-info" style="margin-bottom:20px;">
    <i class="fa-solid fa-circle-info"></i>
    <span>Codes radio utilisés sur les cartes dispatch (ex: <strong>10-38</strong>). La couleur définit le badge affiché.</span>
</div>
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-walkie-talkie"></i> Codes 10 configurés</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if (empty($codeTen)): ?>
        <div class="dc-empty">Aucun code configuré</div>
    <?php else: foreach ($codeTen as $code => $cColor):
        $hex = chex($cColor, $COLORS);
        $sid = 'c10ef_'.preg_replace('/[^a-z0-9]/i', '_', $code);
    ?>
    <div>
        <div class="dc-row">
            <div style="min-width:62px;padding:4px 10px;border-radius:6px;background:<?php echo $hex; ?>22;border:1px solid <?php echo $hex; ?>44;font-family:'DM Mono',monospace;font-size:.82rem;font-weight:700;color:<?php echo $hex; ?>;text-align:center;flex-shrink:0;">
                <?php echo htmlspecialchars($code); ?>
            </div>
            <div class="dc-row-sub"><?php echo $COLORS[$cColor]['label'] ?? $cColor; ?></div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_code_ten">
                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
                    <input type="hidden" name="current_tab" value="code_ten">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_code_ten">
            <input type="hidden" name="old_code" value="<?php echo htmlspecialchars($code); ?>">
            <input type="hidden" name="current_tab" value="code_ten">
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" style="font-size:.75rem;">Code (ex: 10-38)</label>
                <input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($code); ?>"
                       style="font-family:'DM Mono',monospace;text-transform:uppercase;" required>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label" style="font-size:.75rem;">Couleur</label>
                <?php cswatches('color', $cColor, $COLORS); ?>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> OK</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter un code 10</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_code_ten">
            <input type="hidden" name="current_tab" value="code_ten">
            <div class="form-group">
                <label class="form-label">Code radio</label>
                <input type="text" name="code" class="form-control" placeholder="Ex: 10-38, 10-91..."
                       style="font-family:'DM Mono',monospace;text-transform:uppercase;" required>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Couleur</label>
                <?php cswatches('color', 'gray', $COLORS); ?>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<script>
/* ── Édition inline ── */
function dcEdit(id) {
    var f = document.getElementById(id);
    if (!f) return;
    var open = (f.style.display === 'flex');
    document.querySelectorAll('.dc-ef').forEach(function(x){ x.style.display = 'none'; });
    if (!open) {
        f.style.display = 'flex';
        var inp = f.querySelector('input[type="text"]');
        if (inp) setTimeout(function(){ inp.focus(); inp.select(); }, 30);
    }
}
function dcClose(id) {
    var f = document.getElementById(id);
    if (f) f.style.display = 'none';
}

/* Toggle plaques par véhicule */
function vpToggle(vid) {
    var p = document.getElementById('vp-panel-' + vid);
    var l = document.getElementById('vp-toggle-lbl-' + vid);
    if (!p) return;
    var isOpen = p.style.display !== 'none';
    p.style.display = isOpen ? 'none' : 'block';
    if (l) l.textContent = isOpen ? 'Plaques' : 'Masquer';
}
/* Auto-uppercase pour les inputs plaque */
document.addEventListener('input', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('vp-plate-input')) {
        e.target.value = e.target.value.toUpperCase();
    }
});

/* Highlight swatches couleur */
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('dc-color-r')) return;
    var form = e.target.closest('form');
    if (!form) return;
    form.querySelectorAll('input[name="'+e.target.name+'"] + .dc-sw').forEach(function(sw){
        sw.style.borderColor = 'transparent';
        sw.style.transform   = 'scale(1)';
    });
    var sel = e.target.nextElementSibling;
    if (sel) { sel.style.borderColor = '#fff'; sel.style.transform = 'scale(1.25)'; }
});

/* ESC */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.dc-ef').forEach(function(f){ f.style.display='none'; });
});

/* ── Aperçu thème ── */
function setBgType(type, ev) {
    document.getElementById('bgTypeInput').value = type;
    document.querySelectorAll('.bg-type-btn').forEach(function(b){ b.classList.remove('active'); });
    if (ev && ev.currentTarget) ev.currentTarget.classList.add('active');
    document.getElementById('bgColorGroup').style.display = (type === 'image') ? 'none' : 'block';
    document.getElementById('bgImageGroup').style.display = (type === 'image') ? 'block' : 'none';
    updatePreview();
}

function previewBgImage(input) {
    if (!input.files[0]) return;
    var url = URL.createObjectURL(input.files[0]);
    var bg  = document.getElementById('previewBg');
    bg.style.backgroundImage    = "url('"+url+"')";
    bg.style.backgroundSize     = 'cover';
    bg.style.backgroundPosition = 'center';
    updatePreview();
}

function updatePreview() {
    var bgType  = document.getElementById('bgTypeInput')?.value    || 'gradient';
    var bgColor = document.getElementById('bgColor')?.value        || '#060f1d';
    var accent  = document.getElementById('accentColor')?.value    || '#00e5ff';
    var title   = document.querySelector('[name="board_title"]')?.value || 'DISPATCH BOARD';
    var overlay = parseFloat(document.getElementById('overlaySlider')?.value      || 0.55);
    var colOp   = parseFloat(document.getElementById('colOpacitySlider')?.value   || 0.88);
    var colBlur = parseInt(document.getElementById('colBlurSlider')?.value         || 8);
    var cardR   = parseInt(document.getElementById('cardRadiusSlider')?.value      || 12);
    var topOp   = parseFloat(document.getElementById('topbarOpacitySlider')?.value || 0.97);

    var bg  = document.getElementById('previewBg');
    var ov  = document.getElementById('previewOverlay');
    var tb  = document.getElementById('previewTopbar');
    var ttl = document.getElementById('previewTitle');
    if (!bg) return;

    var ri = parseInt(accent.slice(1,3),16),
        gi = parseInt(accent.slice(3,5),16),
        bi = parseInt(accent.slice(5,7),16);

    if (bgType === 'image') {
        var urlInp = document.querySelector('[name="bg_image_url"]')?.value || '';
        if (urlInp && bg.style.backgroundImage.indexOf('blob') === -1)
            bg.style.background = "url('"+urlInp+"') center/cover no-repeat";
        ov.style.background = 'rgba(0,0,0,'+overlay+')';
    } else if (bgType === 'solid') {
        bg.style.background = bgColor;
        ov.style.background = 'none';
    } else {
        bg.style.background = 'radial-gradient(ellipse 70% 50% at 20% 60%, rgba('+ri+','+gi+','+bi+',.12), transparent), '+bgColor;
        ov.style.background = 'none';
    }
    tb.style.background  = 'rgba(6,15,29,'+topOp+')';
    tb.style.borderColor = 'rgba('+ri+','+gi+','+bi+',.2)';
    ttl.textContent  = title;
    ttl.style.color  = accent;
    document.querySelectorAll('.board-preview-col').forEach(function(c){
        c.style.background     = 'rgba(11,25,41,'+colOp+')';
        c.style.backdropFilter = 'blur('+Math.min(colBlur,6)+'px)';
        c.style.borderRadius   = Math.min(cardR,10)+'px';
        c.style.borderColor    = 'rgba('+ri+','+gi+','+bi+',.15)';
    });
    document.querySelectorAll('.board-preview-card').forEach(function(c){
        c.style.borderRadius = Math.min(cardR,8)+'px';
        c.style.borderColor  = 'rgba('+ri+','+gi+','+bi+',.12)';
    });
}
updatePreview();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>