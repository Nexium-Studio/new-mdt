<?php
/* ============================================================================
   pages/lssd/vehicule_lssd.php
   Gestion de la flotte véhicules + plaques côté LSSD (Sergent I minimum).
   Mêmes tables que pages/staff/content/dispatch-custom.php (sync 100%).
   ============================================================================ */
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!in_array($_SESSION['user_faction'] ?? '', ['LSPD','LSSD','DOJ']) &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

/* ── Chargement des grades LSSD pour vérification du seuil "Sergeant I" ── */
$RANKS = [];
try {
    $st = $db->prepare(
        "SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id
         WHERE f.faction_key COLLATE utf8mb4_unicode_ci='LSSD' ORDER BY fg.rank_order ASC"
    );
    $st->execute();
    $RANKS = array_column($st->fetchAll(), 'name');
} catch (Exception $e) {
    $RANKS = ['Sheriff','Undersheriff','Assistant Sheriff','Division Chief','Area Commander',
              'Captain','Lieutenant','Sergeant','Deputy Sheriff','Deputy Sheriff Trainee'];
}
/* rank_order=1 = grade le plus haut → niveau le plus élevé */
$RLEVEL = [];
foreach ($RANKS as $idx => $name) { $RLEVEL[$name] = count($RANKS) - $idx; }

try {
    $g = $db->prepare("SELECT faction_rank FROM users WHERE id=? LIMIT 1");
    $g->execute([$uid]);
    $row = $g->fetch();
    $myRank = $row['faction_rank'] ?? ($_SESSION['user_faction_rank'] ?? '');
} catch (Exception $e) {
    $myRank = $_SESSION['user_faction_rank'] ?? '';
}
$myLvl       = $RLEVEL[$myRank] ?? 0;
$lvlSgtI     = $RLEVEL['Sergeant'] ?? 0;
$staffBypass = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']);
$canManage   = $staffBypass || ($lvlSgtI > 0 && $myLvl >= $lvlSgtI);

if (!$canManage) {
    /* Backend : on bloque totalement, AJAX inclus, avec message d'erreur clair. */
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Accès refusé : grade Sergent I minimum requis."]);
        exit;
    }
    $pageTitle = 'Flotte Véhicules';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="alert alert-error" style="display:flex;align-items:center;gap:.7rem;">
        <i class="fas fa-lock" style="font-size:1.2rem;"></i>
        <div>
            <strong>Accès refusé.</strong>
            Cette page est réservée aux gradés LSSD <strong>Sergent I</strong> et plus.
            <div style="margin-top:.25rem;font-size:.78rem;opacity:.85;">Votre grade actuel : <strong><?=htmlspecialchars($myRank ?: '— inconnu —')?></strong>.</div>
        </div>
    </div>
    <div style="text-align:center;margin-top:1rem;">
        <a href="<?=SITE_URL?>/pages/lssd/dashboard.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Retour au dashboard LSSD</a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ── Tables (idempotent) ─────────────────────────────────────────────── */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS lssd_dispatch_patrol_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        position INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS lssd_dispatch_vehicle_plates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        plate VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_vehicle_plate (vehicle_id, plate),
        KEY idx_plate (plate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/* ── AJAX ─────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? $_GET['action'] ?? '';
    try {
        switch ($act) {
            case 'list':
                $vehicles = $db->query("SELECT id, name FROM lssd_dispatch_patrol_vehicles ORDER BY position, name")->fetchAll(PDO::FETCH_ASSOC);
                $platesByV = [];
                $rowsP = $db->query("SELECT id, vehicle_id, plate FROM lssd_dispatch_vehicle_plates ORDER BY plate")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rowsP as $rp) { $platesByV[(int)$rp['vehicle_id']][] = $rp; }
                $usedPlates = [];
                try {
                    $rowsU = $db->query("SELECT vehicle_plate FROM lssd_dispatch_cards WHERE vehicle_plate IS NOT NULL AND vehicle_plate <> ''")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($rowsU as $u) $usedPlates[$u] = true;
                } catch (Exception $e) {}
                foreach ($vehicles as &$v) {
                    $vid = (int)$v['id'];
                    $list = $platesByV[$vid] ?? [];
                    $usedC = 0;
                    foreach ($list as &$p) {
                        $p['used'] = !empty($usedPlates[$p['plate']]);
                        if ($p['used']) $usedC++;
                    }
                    unset($p);
                    $v['plates']      = $list;
                    $v['plates_total']= count($list);
                    $v['plates_used'] = $usedC;
                }
                unset($v);
                echo json_encode(['success' => true, 'vehicles' => $vehicles]);
                break;

            case 'add_vehicle':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') { echo json_encode(['success'=>false,'error'=>'Nom requis']); break; }
                $pos = (int)$db->query("SELECT COALESCE(MAX(position),0)+1 FROM lssd_dispatch_patrol_vehicles")->fetchColumn();
                $db->prepare("INSERT INTO lssd_dispatch_patrol_vehicles(name, position) VALUES(?, ?)")->execute([$name, $pos]);
                echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'name'=>$name]);
                break;

            case 'rename_vehicle':
                $id = (int)$_POST['id']; $name = trim($_POST['name'] ?? '');
                if ($name === '' || $id <= 0) { echo json_encode(['success'=>false,'error'=>'Paramètres invalides']); break; }
                $db->prepare("UPDATE lssd_dispatch_patrol_vehicles SET name=? WHERE id=?")->execute([$name, $id]);
                echo json_encode(['success'=>true]);
                break;

            case 'delete_vehicle':
                $id = (int)$_POST['id'];
                $db->prepare("DELETE FROM lssd_dispatch_patrol_vehicles WHERE id=?")->execute([$id]);
                $db->prepare("DELETE FROM lssd_dispatch_vehicle_plates WHERE vehicle_id=?")->execute([$id]);
                echo json_encode(['success'=>true]);
                break;

            case 'add_plate':
                $vid   = (int)$_POST['vehicle_id'];
                $plate = strtoupper(trim($_POST['plate'] ?? ''));
                if ($vid <= 0 || $plate === '') { echo json_encode(['success'=>false,'error'=>'Véhicule et plaque requis']); break; }
                if (mb_strlen($plate) > 16)     { echo json_encode(['success'=>false,'error'=>'Plaque trop longue (16 max)']); break; }
                try {
                    $db->prepare("INSERT INTO lssd_dispatch_vehicle_plates(vehicle_id, plate) VALUES(?, ?)")->execute([$vid, $plate]);
                    echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'plate'=>$plate]);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate') !== false) echo json_encode(['success'=>false,'error'=>'Cette plaque existe déjà pour ce véhicule.']);
                    else echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
                }
                break;

            case 'add_plates_bulk':
                $vid = (int)$_POST['vehicle_id'];
                $raw = (string)($_POST['plates'] ?? '');
                if ($vid <= 0) { echo json_encode(['success'=>false,'error'=>'Véhicule invalide']); break; }
                $arr = preg_split('/[\s,;]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
                $added = 0; $skipped = 0; $newOnes = [];
                $ins = $db->prepare("INSERT IGNORE INTO lssd_dispatch_vehicle_plates(vehicle_id, plate) VALUES(?, ?)");
                foreach ($arr as $p) {
                    $p = trim($p);
                    if ($p === '' || mb_strlen($p) > 16) { $skipped++; continue; }
                    try {
                        $ins->execute([$vid, $p]);
                        if ($ins->rowCount() > 0) {
                            $added++;
                            $newOnes[] = ['id' => $db->lastInsertId(), 'plate' => $p];
                        } else $skipped++;
                    } catch (Exception $e) { $skipped++; }
                }
                echo json_encode(['success'=>true,'added'=>$added,'skipped'=>$skipped,'plates'=>$newOnes]);
                break;

            case 'rename_plate':
                $id    = (int)$_POST['id'];
                $plate = strtoupper(trim($_POST['plate'] ?? ''));
                if ($id <= 0 || $plate === '') { echo json_encode(['success'=>false,'error'=>'Paramètres invalides']); break; }
                if (mb_strlen($plate) > 16)    { echo json_encode(['success'=>false,'error'=>'Plaque trop longue']); break; }
                /* On vérifie que la plaque n'est pas utilisée actuellement par une carte (sinon on casserait la liaison) */
                try {
                    $cur = $db->prepare("SELECT plate FROM lssd_dispatch_vehicle_plates WHERE id=?");
                    $cur->execute([$id]); $oldPlate = $cur->fetchColumn();
                    if ($oldPlate !== false) {
                        $u = $db->prepare("SELECT 1 FROM lssd_dispatch_cards WHERE vehicle_plate=? LIMIT 1");
                        $u->execute([$oldPlate]);
                        if ($u->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'Cette plaque est attribuée à une carte dispatch — retirez-la d\'abord.']); break; }
                    }
                    $db->prepare("UPDATE lssd_dispatch_vehicle_plates SET plate=? WHERE id=?")->execute([$plate, $id]);
                    echo json_encode(['success'=>true]);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate') !== false) echo json_encode(['success'=>false,'error'=>'Cette plaque existe déjà pour ce véhicule.']);
                    else echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
                }
                break;

            case 'delete_plate':
                $id = (int)$_POST['id'];
                /* Sécurité : on empêche la suppression si la plaque est en cours d'utilisation */
                $cur = $db->prepare("SELECT plate FROM lssd_dispatch_vehicle_plates WHERE id=?");
                $cur->execute([$id]); $plateVal = $cur->fetchColumn();
                if ($plateVal !== false) {
                    $u = $db->prepare("SELECT 1 FROM lssd_dispatch_cards WHERE vehicle_plate=? LIMIT 1");
                    $u->execute([$plateVal]);
                    if ($u->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'Plaque utilisée par une carte dispatch — retirez-la d\'abord.']); break; }
                }
                $db->prepare("DELETE FROM lssd_dispatch_vehicle_plates WHERE id=?")->execute([$id]);
                echo json_encode(['success'=>true]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Action inconnue']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Données initiales pour le rendu serveur (KPI + premier paint) ─────── */
$vehicles = [];
try { $vehicles = $db->query("SELECT id, name FROM lssd_dispatch_patrol_vehicles ORDER BY position, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$platesByV = [];
try {
    $rowsP = $db->query("SELECT id, vehicle_id, plate FROM lssd_dispatch_vehicle_plates ORDER BY plate")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsP as $rp) { $platesByV[(int)$rp['vehicle_id']][] = $rp; }
} catch (Exception $e) {}

$usedPlates = [];
try {
    $rowsU = $db->query("SELECT vehicle_plate FROM lssd_dispatch_cards WHERE vehicle_plate IS NOT NULL AND vehicle_plate <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rowsU as $u) $usedPlates[$u] = true;
} catch (Exception $e) {}

$totalVehicles = count($vehicles);
$totalPlates   = 0; $totalUsed = 0;
foreach ($platesByV as $arr) {
    $totalPlates += count($arr);
    foreach ($arr as $p) if (!empty($usedPlates[$p['plate']])) $totalUsed++;
}
$totalFree = max(0, $totalPlates - $totalUsed);

$pageTitle = 'Flotte Véhicules';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Vehicule LSSD : couleur d'accent verte/cyan pour cohérence "flotte" ── */
.vl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:1rem; }
@media(max-width:600px){ .vl-grid { grid-template-columns:1fr; } }

.vl-card {
    background:linear-gradient(160deg, var(--bg-c), var(--bg-e));
    border:1px solid var(--bdr); border-radius:14px; overflow:hidden;
    transition:border-color .2s, transform .2s;
    display:flex; flex-direction:column;
}
.vl-card:hover { border-color:var(--bdr-h); transform:translateY(-1px); }

.vl-card-hd {
    display:flex; align-items:center; gap:.7rem; padding:.95rem 1.1rem .85rem;
    border-bottom:1px solid var(--bdr); background:rgba(16,185,129,.04);
}
.vl-card-ico {
    width:38px; height:38px; border-radius:10px;
    background:linear-gradient(135deg, rgba(16,185,129,.18), rgba(16,185,129,.16));
    color:#6ee7b7; display:grid; place-items:center; font-size:1rem; flex-shrink:0;
}
.vl-card-name { flex:1; min-width:0; font-family:'Syne',serif; font-weight:800; font-size:1rem; color:#fff; }
.vl-card-name .vl-rename-input {
    width:100%; background:rgba(0,0,0,.25); border:1px solid var(--bdr);
    border-radius:6px; padding:.35rem .55rem; color:#fff;
    font-family:'Syne',serif; font-weight:800; font-size:1rem; outline:none;
}
.vl-card-name .vl-rename-input:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(16,185,129,.15); }
.vl-card-acts { display:flex; gap:.35rem; flex-shrink:0; }
.vl-card-acts .btn { padding:.3rem .55rem; font-size:.7rem; }
.vl-card-acts .btn i { font-size:.72rem; }

.vl-stats { display:flex; gap:.4rem; padding:.55rem 1.1rem; flex-wrap:wrap; }
.vl-stat {
    display:inline-flex; align-items:center; gap:.32rem;
    padding:.22rem .58rem; border-radius:50px; font-family:'IBM Plex Mono',monospace;
    font-size:.66rem; font-weight:700; letter-spacing:.3px;
}
.vl-stat.s-total { background:rgba(16,185,129,.1);  color:var(--blue-l);   border:1px solid rgba(16,185,129,.22); }
.vl-stat.s-free  { background:rgba(16,185,129,.1); color:#6ee7b7;        border:1px solid rgba(16,185,129,.22); }
.vl-stat.s-used  { background:rgba(245,158,11,.1); color:#fde68a;        border:1px solid rgba(245,158,11,.22); }

.vl-plates { padding:0 1.1rem .85rem; flex:1; }
.vl-plates-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(108px,1fr));
    gap:.4rem; max-height:230px; overflow-y:auto;
    padding:.18rem; background:rgba(0,0,0,.2); border-radius:8px;
    border:1px solid var(--bdr);
}
.vl-plates-grid::-webkit-scrollbar { width:5px; }
.vl-plates-grid::-webkit-scrollbar-thumb { background:rgba(16,185,129,.4); border-radius:3px; }

.vl-plate {
    position:relative; display:flex; align-items:center; gap:.3rem;
    padding:.42rem .35rem .42rem .55rem; border-radius:7px;
    font-family:'IBM Plex Mono',monospace; font-size:.74rem; font-weight:700;
    letter-spacing:.5px; line-height:1.05; border:1px solid; transition:all .15s;
}
.vl-plate.free { background:rgba(16,185,129,.07); border-color:rgba(16,185,129,.32); color:#6ee7b7; }
.vl-plate.free:hover { background:rgba(16,185,129,.14); border-color:rgba(16,185,129,.55); transform:translateY(-1px); }
.vl-plate.used { background:rgba(100,116,139,.07); border-color:rgba(100,116,139,.25); color:var(--txt-s); opacity:.78; }
.vl-plate-txt { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.vl-plate-actions { display:flex; gap:2px; opacity:0; transition:opacity .14s; }
.vl-plate:hover .vl-plate-actions { opacity:1; }
.vl-plate-act {
    background:transparent; border:none; cursor:pointer; color:inherit; opacity:.65;
    width:18px; height:18px; border-radius:4px; display:grid; place-items:center;
    font-size:.65rem; transition:all .14s;
}
.vl-plate-act:hover { opacity:1; background:rgba(255,255,255,.08); }
.vl-plate-act.del:hover { background:rgba(239,68,68,.2); color:#fca5a5; }
.vl-plate.used .vl-plate-act.del[disabled]{ cursor:not-allowed; opacity:.3; }
.vl-plate-edit-input {
    flex:1; background:rgba(0,0,0,.4); border:1px solid var(--blue); border-radius:5px;
    padding:.25rem .4rem; color:#fff; font-family:'IBM Plex Mono',monospace;
    font-size:.74rem; font-weight:700; outline:none; min-width:0;
    text-transform:uppercase; letter-spacing:.5px;
}

.vl-empty {
    grid-column:1/-1; padding:1.1rem .8rem; text-align:center;
    color:var(--txt-m); font-size:.78rem; font-style:italic;
}

.vl-card-add {
    display:flex; gap:.4rem; padding:.6rem 1.1rem 1rem;
}
.vl-card-add .form-input {
    flex:1; padding:.42rem .65rem; font-size:.78rem; text-transform:uppercase;
    font-family:'IBM Plex Mono',monospace; letter-spacing:.5px;
}
.vl-card-add .btn { padding:.42rem .85rem; font-size:.74rem; }
.vl-card-add .btn-bulk { background:rgba(139,92,246,.1); color:#c4b5fd; border:1px solid rgba(139,92,246,.25); }
.vl-card-add .btn-bulk:hover { background:rgba(139,92,246,.2); }

/* ── Empty state global ── */
.vl-empty-state {
    text-align:center; padding:3rem 1rem; color:var(--txt-s);
    background:var(--bg-c); border:2px dashed var(--bdr); border-radius:14px;
}
.vl-empty-state i { font-size:2.5rem; margin-bottom:.7rem; opacity:.4; display:block; color:var(--blue-l); }
.vl-empty-state strong { color:var(--txt); font-size:1rem; display:block; margin-bottom:.35rem; }

/* ── Modal bulk ── */
.vl-bulk-textarea {
    width:100%; min-height:120px; background:#020508;
    border:1px solid rgba(16,185,129,.2); border-radius:7px;
    color:var(--txt); font-family:'IBM Plex Mono',monospace; font-size:.85rem;
    padding:.7rem .9rem; resize:vertical; text-transform:uppercase; letter-spacing:.5px;
    transition:all .17s;
}
.vl-bulk-textarea:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(16,185,129,.12); }

/* ── Toolbar ── */
.vl-toolbar {
    display:flex; gap:.7rem; flex-wrap:wrap; align-items:center;
    margin-bottom:1.1rem;
}
.vl-toolbar .vl-search { flex:1; min-width:230px; position:relative; }
.vl-toolbar .vl-search input {
    width:100%; padding:.6rem .85rem .6rem 2.4rem; background:#020508;
    border:1px solid var(--bdr); border-radius:9px; color:var(--txt);
    font-size:.83rem; transition:all .17s;
}
.vl-toolbar .vl-search input:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(16,185,129,.12); }
.vl-toolbar .vl-search i {
    position:absolute; left:.85rem; top:50%; transform:translateY(-50%);
    color:var(--txt-m); font-size:.85rem; pointer-events:none;
}

/* ── Inline alert ── */
.vl-info-banner {
    display:flex; align-items:flex-start; gap:.7rem; padding:.85rem 1.1rem;
    background:rgba(16,185,129,.06); border:1px solid rgba(16,185,129,.18);
    border-radius:10px; margin-bottom:1.2rem; font-size:.81rem; color:var(--txt-s); line-height:1.55;
}
.vl-info-banner i { color:var(--blue-l); margin-top:2px; font-size:.95rem; }
.vl-info-banner strong { color:var(--blue-xl); }
</style>

<!-- ══ KPI ══ -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-car-side"></i></div>
        <div class="kpi-lbl">Modèles</div>
        <div class="kpi-val blue" id="kpiVehicles"><?= $totalVehicles ?></div>
        <div class="kpi-sub">Véhicules de patrouille</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(16,185,129,.15);color:#6ee7b7;"><i class="fas fa-id-card"></i></div>
        <div class="kpi-lbl">Plaques totales</div>
        <div class="kpi-val green" id="kpiPlates"><?= $totalPlates ?></div>
        <div class="kpi-sub">Plaques enregistrées</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(245,158,11,.15);color:#fde68a;"><i class="fas fa-circle-dot"></i></div>
        <div class="kpi-lbl">En service</div>
        <div class="kpi-val yellow" id="kpiUsed"><?= $totalUsed ?></div>
        <div class="kpi-sub">Plaques attribuées</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(16,185,129,.15);color:var(--blue-l);"><i class="fas fa-circle-check"></i></div>
        <div class="kpi-lbl">Disponibles</div>
        <div class="kpi-val" id="kpiFree"><?= $totalFree ?></div>
        <div class="kpi-sub">Plaques libres</div>
    </div>
</div>

<!-- ══ Info banner ══ -->
<div class="vl-info-banner">
    <i class="fas fa-circle-info"></i>
    <div>
        Cette page synchronise la flotte directement avec le <strong>Dispatch</strong> et le panel staff. Toute modification est immédiate côté terrain.
        Connecté en tant que <strong><?= htmlspecialchars($myRank) ?></strong> — accès <strong>Sergent I</strong> minimum requis.
    </div>
</div>

<!-- ══ Toolbar ══ -->
<div class="vl-toolbar">
    <div class="vl-search">
        <i class="fas fa-search"></i>
        <input type="text" id="vlSearch" placeholder="Rechercher un véhicule ou une plaque…" oninput="vlFilter()">
    </div>
    <button class="btn btn-primary" onclick="vlOpenAddVehicle()"><i class="fas fa-plus"></i> Nouveau véhicule</button>
    <button class="btn btn-secondary" onclick="vlReload()"><i class="fas fa-rotate"></i> Rafraîchir</button>
</div>

<!-- ══ Grid véhicules ══ -->
<div id="vlGrid" class="vl-grid">
    <?php if (empty($vehicles)): ?>
        <div class="vl-empty-state" style="grid-column:1/-1;">
            <i class="fas fa-car-side"></i>
            <strong>Aucun véhicule enregistré</strong>
            Cliquez sur <em>« Nouveau véhicule »</em> pour démarrer la flotte.
        </div>
    <?php endif; ?>
</div>

<!-- ══ Modal : Add vehicle ══ -->
<div id="modalAddVehicle" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-car-side"></i> Nouveau véhicule</h2>
            <button class="modal-cl" onclick="vlCloseModal('modalAddVehicle')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-bd">
            <div class="form-group">
                <label class="form-label">Nom du modèle</label>
                <input type="text" id="vlNewVehicleName" class="form-input" placeholder="Ex: Alamo, Dodge Charger, Ford Explorer..." onkeydown="if(event.key==='Enter')vlSubmitAddVehicle()">
            </div>
        </div>
        <div class="modal-ft">
            <button class="btn btn-ghost" onclick="vlCloseModal('modalAddVehicle')">Annuler</button>
            <button class="btn btn-primary" onclick="vlSubmitAddVehicle()"><i class="fas fa-check"></i> Créer</button>
        </div>
    </div>
</div>

<!-- ══ Modal : Bulk plates ══ -->
<div id="modalBulkPlates" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-list"></i> Ajout en masse — <span id="vlBulkVehicleName" style="color:var(--blue-l)"></span></h2>
            <button class="modal-cl" onclick="vlCloseModal('modalBulkPlates')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-bd">
            <div class="form-group">
                <label class="form-label">Plaques (séparées par espaces, virgules ou retours à la ligne)</label>
                <textarea id="vlBulkText" class="vl-bulk-textarea" placeholder="GTD547PM, OPL8759ZZ&#10;ABC123XY DEF456WZ"></textarea>
                <div style="font-size:.7rem;color:var(--txt-m);margin-top:.4rem;">
                    <i class="fas fa-circle-info"></i> Les doublons seront ignorés automatiquement. 16 caractères max par plaque.
                </div>
            </div>
        </div>
        <div class="modal-ft">
            <button class="btn btn-ghost" onclick="vlCloseModal('modalBulkPlates')">Annuler</button>
            <button class="btn btn-primary" onclick="vlSubmitBulkPlates()"><i class="fas fa-cloud-arrow-up"></i> Importer</button>
        </div>
    </div>
</div>

<script>
/* ============================================================
   Vehicule LSSD — gestion AJAX
   ============================================================ */
const VL = {
    vehicles: [],
    bulkVehicleId: null,
    async api(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k,v]) => fd.append(k, v));
        const r = await fetch(window.location.pathname + '?ajax=1', { method:'POST', body:fd });
        if (r.status === 403) return { success:false, error:'Accès refusé.' };
        return r.json();
    },
    esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); },
    toast(msg, type='success') {
        if (window.LSSD && typeof window.LSSD.toast === 'function') return window.LSSD.toast(msg, type);
        const c = document.getElementById('lssd-toasts'); if (!c) { alert(msg); return; }
        const el = document.createElement('div');
        const colors = { success:'#10b981', error:'#ef4444', info:'#10b981', warning:'#f59e0b' };
        const ic     = { success:'check-circle', error:'circle-xmark', info:'circle-info', warning:'triangle-exclamation' };
        el.style.cssText = 'background:' + colors[type] + ';color:#fff;padding:.7rem 1rem;border-radius:8px;font-size:.8rem;font-weight:600;display:flex;align-items:center;gap:.5rem;box-shadow:0 4px 18px rgba(0,0,0,.4);pointer-events:auto;animation:vlSlide .25s';
        el.innerHTML = '<i class="fas fa-' + ic[type] + '"></i>' + this.esc(msg);
        c.appendChild(el);
        setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(), 300); }, 3500);
    },
    confirm(msg, title='Confirmer') {
        return new Promise(resolve => {
            if (window.LSSD && typeof window.LSSD.confirm === 'function') {
                /* LSSD.confirm utilise un callback : on bridge vers Promise.
                   Si l'utilisateur annule, le callback n'est pas appelé : on observe
                   donc aussi le bouton "Annuler" via un override temporaire. */
                const prevCancel = window.LSSD.confirmCancel;
                let resolved = false;
                window.LSSD.confirmCancel = function() {
                    if (!resolved) { resolved = true; resolve(false); }
                    if (typeof prevCancel === 'function') prevCancel.apply(window.LSSD, arguments);
                    window.LSSD.confirmCancel = prevCancel;
                };
                window.LSSD.confirm({
                    title,
                    msg: String(msg || '').replace(/\n/g,'<br>'),
                    okLabel: 'Confirmer',
                    okClass: 'btn-danger',
                    callback: () => {
                        if (!resolved) { resolved = true; resolve(true); }
                        window.LSSD.confirmCancel = prevCancel;
                    }
                });
            } else {
                resolve(window.confirm(msg));
            }
        });
    }
};

/* Inline keyframes pour les toasts (au cas où LSSD.toast n'est pas dispo) */
const __vlStyle = document.createElement('style');
__vlStyle.textContent = '@keyframes vlSlide{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}';
document.head.appendChild(__vlStyle);

/* ── Render ── */
function vlRender() {
    const grid = document.getElementById('vlGrid');
    if (!VL.vehicles.length) {
        grid.innerHTML = `
            <div class="vl-empty-state" style="grid-column:1/-1;">
                <i class="fas fa-car-side"></i>
                <strong>Aucun véhicule enregistré</strong>
                Cliquez sur « Nouveau véhicule » pour démarrer la flotte.
            </div>`;
        return;
    }
    grid.innerHTML = VL.vehicles.map(v => vlCardHTML(v)).join('');
}

function vlCardHTML(v) {
    const platesHtml = v.plates.length
        ? v.plates.map(p => vlPlateHTML(p)).join('')
        : '<div class="vl-empty">Aucune plaque pour ce véhicule.</div>';

    return `
    <div class="vl-card" data-vid="${v.id}" data-vname="${VL.esc((v.name||'').toLowerCase())}">
        <div class="vl-card-hd">
            <div class="vl-card-ico"><i class="fas fa-car-side"></i></div>
            <div class="vl-card-name" id="vl-name-${v.id}" data-name="${VL.esc(v.name)}">${VL.esc(v.name)}</div>
            <div class="vl-card-acts">
                <button class="btn btn-secondary" onclick="vlRenameVehicle(${v.id})" title="Renommer">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn btn-danger" onclick="vlDeleteVehicle(${v.id})" title="Supprimer le véhicule">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="vl-stats">
            <span class="vl-stat s-total" id="vl-stat-total-${v.id}"><i class="fas fa-id-card"></i> ${v.plates_total}</span>
            <span class="vl-stat s-free" id="vl-stat-free-${v.id}"><i class="fas fa-circle-check"></i> ${v.plates_total - v.plates_used} libre${(v.plates_total-v.plates_used)>1?'s':''}</span>
            ${v.plates_used > 0 ? `<span class="vl-stat s-used" id="vl-stat-used-${v.id}"><i class="fas fa-circle-dot"></i> ${v.plates_used} utilisée${v.plates_used>1?'s':''}</span>` : `<span class="vl-stat s-used" id="vl-stat-used-${v.id}" style="display:none;"></span>`}
        </div>
        <div class="vl-plates">
            <div class="vl-plates-grid" id="vl-plates-${v.id}">${platesHtml}</div>
        </div>
        <div class="vl-card-add">
            <input type="text" id="vl-newplate-${v.id}" class="form-input" placeholder="Nouvelle plaque (ex: AB123CD)" maxlength="16" oninput="this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')vlAddPlate(${v.id})">
            <button class="btn btn-primary" onclick="vlAddPlate(${v.id})" title="Ajouter"><i class="fas fa-plus"></i></button>
            <button class="btn btn-bulk" onclick="vlOpenBulk(${v.id}, '${VL.esc(v.name).replace(/'/g, "\\'")}')" title="Ajout en masse"><i class="fas fa-list"></i></button>
        </div>
    </div>`;
}

function vlPlateHTML(p) {
    const isUsed = !!p.used;
    return `
    <div class="vl-plate ${isUsed ? 'used':'free'}" id="vl-plate-${p.id}" data-plate="${VL.esc((p.plate||'').toLowerCase())}" title="${isUsed ? 'Plaque attribuée à une carte dispatch' : 'Plaque disponible'}">
        <span class="vl-plate-txt" id="vl-plate-txt-${p.id}">${VL.esc(p.plate)}</span>
        <div class="vl-plate-actions">
            <button class="vl-plate-act" onclick="vlEditPlate(${p.id})" title="Renommer"><i class="fas fa-pen"></i></button>
            <button class="vl-plate-act del" ${isUsed ? 'disabled' : ''} onclick="vlDeletePlate(${p.id}, ${isUsed?1:0})" title="${isUsed ? 'Plaque utilisée — non supprimable' : 'Supprimer'}"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
}

/* ── KPI update ── */
function vlRefreshKPIs() {
    const total = VL.vehicles.length;
    const plates = VL.vehicles.reduce((s,v)=>s+v.plates_total, 0);
    const used   = VL.vehicles.reduce((s,v)=>s+v.plates_used, 0);
    document.getElementById('kpiVehicles').textContent = total;
    document.getElementById('kpiPlates').textContent   = plates;
    document.getElementById('kpiUsed').textContent     = used;
    document.getElementById('kpiFree').textContent     = Math.max(0, plates - used);
}

/* ── Reload ── */
async function vlReload() {
    const r = await VL.api({ action:'list' });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    VL.vehicles = r.vehicles || [];
    vlRender();
    vlRefreshKPIs();
    vlFilter();
}

/* ── Add vehicle ── */
function vlOpenAddVehicle() {
    document.getElementById('vlNewVehicleName').value = '';
    document.getElementById('modalAddVehicle').classList.remove('hidden');
    setTimeout(() => document.getElementById('vlNewVehicleName').focus(), 80);
}
async function vlSubmitAddVehicle() {
    const name = document.getElementById('vlNewVehicleName').value.trim();
    if (!name) { VL.toast('Nom requis', 'error'); return; }
    const r = await VL.api({ action:'add_vehicle', name });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    vlCloseModal('modalAddVehicle');
    VL.toast('Véhicule créé');
    await vlReload();
}

/* ── Rename vehicle (inline) ── */
function vlRenameVehicle(vid) {
    const wrap = document.getElementById('vl-name-' + vid);
    if (!wrap) return;
    const cur = wrap.dataset.name || wrap.textContent.trim();
    wrap.innerHTML = `<input type="text" class="vl-rename-input" id="vl-rn-${vid}" value="${VL.esc(cur)}" onkeydown="if(event.key==='Enter')vlSubmitRename(${vid});else if(event.key==='Escape')vlCancelRename(${vid},'${VL.esc(cur).replace(/'/g,"\\'")}')">`;
    setTimeout(() => { const i=document.getElementById('vl-rn-'+vid); if(i){i.focus();i.select();} }, 10);
}
function vlCancelRename(vid, name) {
    const wrap = document.getElementById('vl-name-' + vid);
    if (wrap) { wrap.textContent = name; wrap.dataset.name = name; }
}
async function vlSubmitRename(vid) {
    const inp = document.getElementById('vl-rn-' + vid);
    if (!inp) return;
    const name = inp.value.trim();
    if (!name) { VL.toast('Nom requis', 'error'); return; }
    const r = await VL.api({ action:'rename_vehicle', id:vid, name });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    const v = VL.vehicles.find(x => x.id == vid); if (v) v.name = name;
    const wrap = document.getElementById('vl-name-' + vid);
    if (wrap) { wrap.textContent = name; wrap.dataset.name = name; }
    /* update data-vname pour search */
    const card = document.querySelector('.vl-card[data-vid="'+vid+'"]');
    if (card) card.dataset.vname = name.toLowerCase();
    VL.toast('Véhicule renommé');
}

/* ── Delete vehicle ── */
async function vlDeleteVehicle(vid) {
    const v = VL.vehicles.find(x => x.id == vid); if (!v) return;
    let msg = `Supprimer définitivement « ${v.name} » et ses ${v.plates_total} plaque(s) ?`;
    if (v.plates_used > 0) msg += `\n\n⚠️ ${v.plates_used} plaque(s) sont actuellement utilisées par des cartes dispatch — elles seront détachées.`;
    const ok = await VL.confirm(msg, 'Supprimer le véhicule');
    if (!ok) return;
    const r = await VL.api({ action:'delete_vehicle', id:vid });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    VL.vehicles = VL.vehicles.filter(x => x.id != vid);
    document.querySelector('.vl-card[data-vid="'+vid+'"]')?.remove();
    vlRefreshKPIs();
    if (!VL.vehicles.length) vlRender();
    VL.toast('Véhicule supprimé');
}

/* ── Plates ── */
async function vlAddPlate(vid) {
    const inp = document.getElementById('vl-newplate-' + vid);
    if (!inp) return;
    const plate = inp.value.trim().toUpperCase();
    if (!plate) { VL.toast('Plaque requise', 'error'); return; }
    const r = await VL.api({ action:'add_plate', vehicle_id:vid, plate });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    inp.value = '';
    const v = VL.vehicles.find(x => x.id == vid);
    if (v) {
        const newP = { id:r.id, plate:r.plate, used:false };
        v.plates.push(newP);
        v.plates.sort((a,b) => (a.plate||'').localeCompare(b.plate||''));
        v.plates_total++;
        vlReplaceCard(v);
    }
    vlRefreshKPIs();
    vlFilter();
    VL.toast('Plaque ajoutée');
}

function vlEditPlate(pid) {
    const txt = document.getElementById('vl-plate-txt-' + pid);
    if (!txt) return;
    const cur = txt.textContent.trim();
    txt.outerHTML = `<input type="text" class="vl-plate-edit-input" id="vl-pe-${pid}" value="${VL.esc(cur)}" maxlength="16" oninput="this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')vlSubmitEditPlate(${pid});else if(event.key==='Escape')vlCancelEditPlate(${pid},'${VL.esc(cur).replace(/'/g,"\\'")}')">`;
    setTimeout(() => { const i=document.getElementById('vl-pe-'+pid); if(i){i.focus();i.select();} }, 10);
}
function vlCancelEditPlate(pid, original) {
    const inp = document.getElementById('vl-pe-' + pid);
    if (inp) inp.outerHTML = `<span class="vl-plate-txt" id="vl-plate-txt-${pid}">${VL.esc(original)}</span>`;
}
async function vlSubmitEditPlate(pid) {
    const inp = document.getElementById('vl-pe-' + pid);
    if (!inp) return;
    const plate = inp.value.trim().toUpperCase();
    if (!plate) { VL.toast('Plaque requise', 'error'); return; }
    const r = await VL.api({ action:'rename_plate', id:pid, plate });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); vlCancelEditPlate(pid, plate); return; }
    /* maj cache */
    for (const v of VL.vehicles) {
        const p = v.plates.find(x => x.id == pid);
        if (p) { p.plate = plate; v.plates.sort((a,b)=>(a.plate||'').localeCompare(b.plate||'')); vlReplaceCard(v); break; }
    }
    vlFilter();
    VL.toast('Plaque renommée');
}

async function vlDeletePlate(pid, isUsed) {
    if (isUsed) { VL.toast('Plaque utilisée — retirez-la d\'abord de la carte dispatch.', 'warning'); return; }
    const ok = await VL.confirm('Supprimer cette plaque ?', 'Supprimer');
    if (!ok) return;
    const r = await VL.api({ action:'delete_plate', id:pid });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    /* maj cache */
    for (const v of VL.vehicles) {
        const idx = v.plates.findIndex(x => x.id == pid);
        if (idx >= 0) { v.plates.splice(idx, 1); v.plates_total--; vlReplaceCard(v); break; }
    }
    vlRefreshKPIs();
    VL.toast('Plaque supprimée');
}

/* Re-render d'une seule carte sans re-flow toute la grille */
function vlReplaceCard(v) {
    const old = document.querySelector('.vl-card[data-vid="'+v.id+'"]');
    if (!old) return;
    const wrap = document.createElement('div'); wrap.innerHTML = vlCardHTML(v);
    old.replaceWith(wrap.firstElementChild);
}

/* ── Bulk ── */
function vlOpenBulk(vid, name) {
    VL.bulkVehicleId = vid;
    document.getElementById('vlBulkVehicleName').textContent = name;
    document.getElementById('vlBulkText').value = '';
    document.getElementById('modalBulkPlates').classList.remove('hidden');
    setTimeout(() => document.getElementById('vlBulkText').focus(), 80);
}
async function vlSubmitBulkPlates() {
    const txt = document.getElementById('vlBulkText').value.trim();
    if (!txt) { VL.toast('Aucune plaque', 'error'); return; }
    const vid = VL.bulkVehicleId; if (!vid) return;
    const r = await VL.api({ action:'add_plates_bulk', vehicle_id:vid, plates:txt });
    if (!r.success) { VL.toast(r.error || 'Erreur', 'error'); return; }
    vlCloseModal('modalBulkPlates');
    VL.toast(`${r.added} ajoutée(s), ${r.skipped} ignorée(s)`, r.added > 0 ? 'success' : 'info');
    await vlReload();
}

/* ── Modals ── */
function vlCloseModal(id) {
    document.getElementById(id)?.classList.add('hidden');
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-ov').forEach(m => m.classList.add('hidden'));
    }
});
document.querySelectorAll('.modal-ov').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.add('hidden'); });
});

/* ── Search/filter ── */
function vlFilter() {
    const q = (document.getElementById('vlSearch').value || '').trim().toLowerCase();
    document.querySelectorAll('.vl-card').forEach(card => {
        if (!q) { card.style.display = ''; return; }
        const vname = card.dataset.vname || '';
        const plates = [...card.querySelectorAll('.vl-plate')].map(p => p.dataset.plate || '');
        const ok = vname.includes(q) || plates.some(p => p.includes(q));
        card.style.display = ok ? '' : 'none';
    });
}

/* ── Init : charger via AJAX pour avoir les flags "used" frais ── */
vlReload();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
