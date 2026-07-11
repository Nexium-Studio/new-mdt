<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = "🔧 Tuner's Shop";
$companyColor   = '#f59e0b';
$companyFaction = 'BENNYS';
$pageTitle      = 'Custom Garage';
$pageIcon       = 'car';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// ── Permissions ─────────────────────────────────────────────────────────────
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'custom.view')) faction_acl_deny();
$canCustomize = faction_acl_can($db, $uid, $companyFaction, 'custom.customize');
$canManage    = faction_acl_can($db, $uid, $companyFaction, 'custom.add');
$canEdit      = faction_acl_can($db, $uid, $companyFaction, 'custom.edit');
$canDelete    = faction_acl_can($db, $uid, $companyFaction, 'custom.delete');

$msg = ''; $msgType = '';

// ── AJOUTER VÉHICULE ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $canManage) {
    try {
        $db->prepare("INSERT INTO bennys_vehicles (name, category, base_price, description) VALUES (?,?,?,?)")
           ->execute([trim($_POST['vehicle_name']), trim($_POST['category']), floatval($_POST['base_price']), trim($_POST['description'] ?? '')]);
        $msg = 'Véhicule ajouté avec succès.'; $msgType = 'success';
    } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
}

// ── MODIFIER VÉHICULE ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update' && $canManage) {
    try {
        $db->prepare("UPDATE bennys_vehicles SET name=?, category=?, base_price=?, description=? WHERE id=?")
           ->execute([trim($_POST['vehicle_name']), trim($_POST['category']), floatval($_POST['base_price']), trim($_POST['description'] ?? ''), intval($_POST['vehicle_id'])]);
        $msg = 'Véhicule mis à jour.'; $msgType = 'success';
    } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
}

// ── SUPPRIMER VÉHICULE ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $canManage) {
    try {
        $db->prepare("DELETE FROM bennys_vehicles WHERE id=?")->execute([intval($_POST['vehicle_id'])]);
        $msg = 'Véhicule supprimé.'; $msgType = 'success';
    } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
}

// ── APPLIQUER MODIFICATIONS ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_mods' && $canManage) {
    try {
        $vehicleId = intval($_POST['vehicle_id']);

        // 1. Supprimer anciennes mods Benny's
        $db->prepare("DELETE FROM bennys_vehicle_customizations WHERE vehicle_id=?")->execute([$vehicleId]);

        // 2. Récupérer infos du véhicule
        $vInfo = $db->prepare("SELECT name, base_price FROM bennys_vehicles WHERE id=? LIMIT 1");
        $vInfo->execute([$vehicleId]);
        $vRow = $vInfo->fetch(PDO::FETCH_ASSOC);

        $mods = $_POST['modifications'] ?? [];

        if (!empty($mods) && $vRow) {
            // 3. Insérer nouvelles mods
            $stmtMod = $db->prepare("INSERT INTO bennys_vehicle_customizations (vehicle_id, mod_id) VALUES (?,?)");
            foreach ($mods as $modId) { $stmtMod->execute([$vehicleId, intval($modId)]); }

            // 4. Calculer le coût des mods (somme des pourcentages → multiplicateur)
            $stmtPct = $db->prepare("
                SELECT COALESCE(SUM(gm.percentage), 0) AS total_pct
                FROM garage_mods gm
                JOIN bennys_vehicle_customizations bvc ON gm.id = bvc.mod_id
                WHERE bvc.vehicle_id = ?
            ");
            $stmtPct->execute([$vehicleId]);
            $totalPct  = (float)$stmtPct->fetchColumn();
            $finalPr   = $vRow['base_price'] * (1 + $totalPct / 100);
            $modsPrice = round($finalPr - $vRow['base_price'], 2);
            $stockName = 'Custom · ' . $vRow['name'];

            // 5. Sync company_stock : DELETE + INSERT (sans dépendre d'une contrainte UNIQUE)
            $db->prepare("DELETE FROM company_stock WHERE faction=? AND item_name=?")
               ->execute([$companyFaction, $stockName]);
            $db->prepare("
                INSERT INTO company_stock (faction, item_name, item_category, item_price, stock_quantity)
                VALUES (?, ?, 'Customisations', ?, 1)
            ")->execute([$companyFaction, $stockName, $modsPrice]);

            $msg = count($mods) . ' mod(s) appliquée(s) — disponible sur la page Vente.';
        } else {
            // Aucune mod → retirer de la vente si présent
            if ($vRow) {
                $stockName = 'Custom · ' . $vRow['name'];
                $db->prepare("DELETE FROM company_stock WHERE faction=? AND item_name=?")
                   ->execute([$companyFaction, $stockName]);
            }
            $msg = 'Aucune mod sélectionnée — customisation retirée de la vente.';
        }
        $msgType = 'success';
    } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
}


// ── RÉINITIALISER toutes les mods ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_mods' && $canManage) {
    try {
        $vehicleId = intval($_POST['vehicle_id']);
        $db->prepare("DELETE FROM bennys_vehicle_customizations WHERE vehicle_id=?")->execute([$vehicleId]);
        // Retirer de company_stock
        $vn = $db->prepare("SELECT name FROM bennys_vehicles WHERE id=? LIMIT 1");
        $vn->execute([$vehicleId]);
        $vName = $vn->fetchColumn();
        if ($vName) {
            $db->prepare("DELETE FROM company_stock WHERE faction=? AND item_name=?")
               ->execute([$companyFaction, 'Custom · ' . $vName]);
        }
        $msg = 'Customisations réinitialisées. Retirée de la vente.'; $msgType = 'success';
    } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
}

// ── DONNÉES ─────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$qry    = "SELECT * FROM bennys_vehicles WHERE 1=1";
$prms   = [];
if ($search) { $qry .= " AND name LIKE ?"; $prms[] = "%{$search}%"; }
$qry .= " ORDER BY category, name";
$rawVehicles = $db->prepare($qry);
$rawVehicles->execute($prms);
$rawVehicles = $rawVehicles->fetchAll(PDO::FETCH_ASSOC);

// Enrichir chaque véhicule avec ses mods et prix final
$vehicles = []; $totalVehicles = 0;
foreach ($rawVehicles as $v) {
    $stmtM = $db->prepare("SELECT gm.* FROM garage_mods gm JOIN bennys_vehicle_customizations bvc ON gm.id=bvc.mod_id WHERE bvc.vehicle_id=?");
    $stmtM->execute([$v['id']]);
    $v['mods']        = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    $multiplier       = 1.0;
    foreach ($v['mods'] as $m) { $multiplier *= (1 + $m['percentage'] / 100); }
    $v['final_price'] = $v['base_price'] * $multiplier;
    $v['mods_price']  = round($v['final_price'] - $v['base_price'], 2);
    $cat              = $v['category'] ?: 'Autre';
    $vehicles[$cat][] = $v;
    $totalVehicles++;
}

// Toutes les mods disponibles
$allMods   = $db->query("SELECT * FROM garage_mods ORDER BY category, subcategory, name")->fetchAll(PDO::FETCH_ASSOC);
$modsByType = [];
foreach ($allMods as $m) {
    $t = $m['category'] . ' — ' . $m['subcategory'];
    $modsByType[$t][] = $m;
}

// Catégorie icons map
$catIcons = [
    'Moto'                 => ['icon'=>'motorcycle',    'color'=>'#f97316'],
    'Sportives'            => ['icon'=>'bolt',          'color'=>'#facc15'],
    'Sportives Classiques' => ['icon'=>'flag-checkered','color'=>'#a3e635'],
    'Muscle'               => ['icon'=>'fire',          'color'=>'#fb923c'],
    'Super'                => ['icon'=>'crown',         'color'=>'#e879f9'],
    'Off Road'             => ['icon'=>'mountain',      'color'=>'#4ade80'],
    'Sedan'                => ['icon'=>'car-side',      'color'=>'#60a5fa'],
    'Modèle'               => ['icon'=>'car',           'color'=>'#94a3b8'],
    'Vans'                 => ['icon'=>'van-shuttle',   'color'=>'#6366f1'],
    'Utilitaire'           => ['icon'=>'truck',         'color'=>'#78716c'],
    'Coupé'                => ['icon'=>'car-rear',      'color'=>'#f43f5e'],
    'Compact'              => ['icon'=>'cube',          'color'=>'#22d3ee'],
];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ══ CUSTOM.PHP — GARAGE STYLE ══════════════════════════════════════════════ */

/* Ambient grid background */
.garage-bg {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image:
        linear-gradient(rgba(245,158,11,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(245,158,11,.04) 1px, transparent 1px);
    background-size: 48px 48px;
}

.c-content { position: relative; z-index: 1; }

/* ── STATS BAR ── */
.stats-rail {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: .9rem;
    margin-bottom: 2rem;
}
.stat-pill {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: 10px;
    padding: 1rem 1.2rem;
    position: relative; overflow: hidden;
    transition: border-color .2s;
}
.stat-pill::before {
    content: '';
    position: absolute; top: 0; left: 0;
    width: 3px; height: 100%;
    background: var(--pill-color, var(--amber));
    box-shadow: 0 0 10px var(--pill-color, var(--amber));
}
.stat-pill:hover { border-color: rgba(245,158,11,.3); }
.stat-pill-label {
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .14em;
    color: var(--txt-s); margin-bottom: .4rem;
}
.stat-pill-value {
    font-family: 'Share Tech Mono', monospace;
    font-size: 1.65rem; color: var(--txt);
    line-height: 1;
}
.stat-pill-sub { font-size: .7rem; color: var(--txt-s); margin-top: .25rem; }

/* ── TOOLBAR ── */
.toolbar {
    display: flex; gap: .8rem; align-items: center;
    flex-wrap: wrap; margin-bottom: 2rem;
}
.search-wrap {
    flex: 1; min-width: 200px; max-width: 360px;
    position: relative;
}
.search-wrap i {
    position: absolute; left: .85rem; top: 50%;
    transform: translateY(-50%);
    color: var(--txt-s); font-size: .8rem; pointer-events: none;
}
.search-wrap input {
    width: 100%;
    padding: .68rem .9rem .68rem 2.4rem;
    background: var(--bg-c); border: 1px solid var(--bdr);
    border-radius: 8px; color: var(--txt);
    font-family: 'Rajdhani', sans-serif; font-size: .9rem;
    transition: all .18s;
}
.search-wrap input:focus {
    outline: none; border-color: var(--amber);
    box-shadow: 0 0 0 3px rgba(245,158,11,.1);
}
.search-wrap input::placeholder { color: var(--txt-m); }

/* ── CATEGORY SECTION ── */
.cat-section { margin-bottom: 2.8rem; }

.cat-header {
    display: flex; align-items: center; gap: 1rem;
    padding: .9rem 1.4rem;
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-left: 4px solid var(--cat-color, var(--amber));
    border-radius: 0 10px 10px 0;
    margin-bottom: 1.4rem;
    position: relative; overflow: hidden;
}
.cat-header::after {
    content: '';
    position: absolute; right: -20px; top: 50%;
    transform: translateY(-50%);
    width: 120px; height: 120px; border-radius: 50%;
    background: radial-gradient(circle, rgba(245,158,11,.06), transparent 70%);
}
.cat-icon {
    width: 38px; height: 38px; border-radius: 9px;
    background: rgba(245,158,11,.1);
    border: 1px solid rgba(245,158,11,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: var(--cat-color, var(--amber));
}
.cat-name {
    font-family: 'Exo 2', sans-serif;
    font-size: 1.1rem; font-weight: 900;
    color: var(--txt); letter-spacing: .04em;
}
.cat-count {
    margin-left: auto;
    font-family: 'Share Tech Mono', monospace;
    font-size: .85rem; color: var(--txt-s);
    background: rgba(245,158,11,.06);
    border: 1px solid rgba(245,158,11,.14);
    border-radius: 6px; padding: .25rem .7rem;
}

/* ── VEHICLES GRID ── */
.vehicles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 1.3rem;
}

/* ── VEHICLE CARD ── */
.vehicle-card {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: 14px; overflow: hidden;
    transition: all .22s;
    position: relative;
}
.vehicle-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--amber), transparent);
    opacity: 0; transition: opacity .22s;
}
.vehicle-card:hover {
    border-color: rgba(245,158,11,.3);
    transform: translateY(-4px);
    box-shadow: 0 16px 48px rgba(0,0,0,.4), 0 0 32px rgba(245,158,11,.06);
}
.vehicle-card:hover::before { opacity: 1; }

/* Card top band */
.vc-band {
    height: 6px;
    background: linear-gradient(90deg, var(--amber-d), var(--amber), var(--amber-l));
}

.vc-body { padding: 1.3rem 1.4rem; }

.vc-name {
    font-family: 'Exo 2', sans-serif;
    font-size: 1.1rem; font-weight: 900;
    color: var(--txt); margin-bottom: .4rem;
    letter-spacing: .02em;
}
.vc-desc {
    font-size: .83rem; color: var(--txt-s);
    line-height: 1.5; margin-bottom: 1rem;
    min-height: 2.4em;
}

/* Price display */
.vc-prices {
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(245,158,11,.1);
    border-radius: 9px; padding: .9rem 1rem;
    margin-bottom: 1rem;
}
.vc-price-row {
    display: flex; justify-content: space-between; align-items: center;
}
.vc-price-row + .vc-price-row { margin-top: .55rem; padding-top: .55rem; border-top: 1px solid rgba(245,158,11,.08); }
.vc-price-label { font-size: .75rem; color: var(--txt-s); text-transform: uppercase; letter-spacing: .08em; }
.vc-price-base {
    font-family: 'Share Tech Mono', monospace;
    font-size: 1rem; color: #60a5fa;
}
.vc-price-final {
    font-family: 'Share Tech Mono', monospace;
    font-size: 1.3rem; color: #4ade80;
    text-shadow: 0 0 12px rgba(74,222,128,.3);
}

/* Mods tags */
.vc-mods { margin-bottom: 1rem; }
.vc-mods-label {
    font-size: .65rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--txt-s); margin-bottom: .5rem;
    display: flex; align-items: center; gap: .4rem;
}
.vc-mods-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.mod-tag {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .65rem; border-radius: 5px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.2);
    font-size: .7rem; font-weight: 600; color: var(--amber-l);
}
.mod-tag .pct { color: #4ade80; font-family: 'Share Tech Mono', monospace; }

/* Card actions */
.vc-actions {
    display: flex; gap: .5rem;
    padding: .9rem 1.4rem;
    border-top: 1px solid var(--bdr);
    background: rgba(0,0,0,.2);
}

/* ── MODS MODAL ── */
.mods-modal-grid {
    display: grid; gap: 1rem;
    max-height: 55vh; overflow-y: auto;
    padding-right: .4rem;
}
.mods-modal-grid::-webkit-scrollbar { width: 4px; }
.mods-modal-grid::-webkit-scrollbar-thumb { background: rgba(245,158,11,.3); border-radius: 2px; }

.mod-group {
    background: rgba(245,158,11,.04);
    border: 1px solid rgba(245,158,11,.12);
    border-radius: 9px; padding: .9rem 1rem;
}
.mod-group-title {
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--amber-l); margin-bottom: .7rem;
    display: flex; align-items: center; gap: .4rem;
}
.mod-row {
    display: flex; align-items: center; gap: .7rem;
    padding: .45rem .5rem; border-radius: 6px;
    cursor: pointer; transition: background .15s;
}
.mod-row:hover { background: rgba(245,158,11,.07); }
.mod-row input[type="checkbox"] {
    width: 16px; height: 16px; cursor: pointer;
    accent-color: var(--amber); flex-shrink: 0;
}
.mod-row-name { flex: 1; font-size: .85rem; color: var(--txt); }
.mod-row-pct {
    font-family: 'Share Tech Mono', monospace;
    font-size: .8rem; color: #4ade80; font-weight: 700;
}

/* ── EMPTY STATE ── */
.empty-garage {
    text-align: center; padding: 5rem 2rem;
    color: var(--txt-s);
}
.empty-garage i {
    font-size: 4rem; opacity: .15; display: block;
    margin-bottom: 1.2rem; color: var(--amber);
}
.empty-garage p { font-size: .9rem; }


/* ── RESET MODAL — identité propre électrique ──────────────────────── */
.btn-reset-trigger {
    display: inline-flex; align-items: center; justify-content: center;
    gap: .3rem; padding: .36rem .62rem; border-radius: 7px;
    background: linear-gradient(135deg, rgba(239,68,68,.1), rgba(185,28,28,.06));
    border: 1px solid rgba(239,68,68,.28);
    color: #fca5a5; cursor: pointer;
    font-size: .77rem; font-weight: 700;
    position: relative; overflow: hidden;
    transition: all .18s;
}
.btn-reset-trigger .reset-bolt {
    font-size: .7rem; opacity: 0;
    position: absolute; top: 2px; right: 4px;
    transition: opacity .15s, transform .15s;
    pointer-events: none;
}
.btn-reset-trigger:hover {
    background: rgba(239,68,68,.2);
    border-color: rgba(239,68,68,.6);
    color: #fecaca;
    box-shadow: 0 0 18px rgba(239,68,68,.3);
    transform: translateY(-1px);
}
.btn-reset-trigger:hover .reset-icon-wrap i {
    animation: reset-cw .45s cubic-bezier(.34,1.56,.64,1);
}
.btn-reset-trigger:hover .reset-bolt { opacity: 1; transform: translateY(1px); }
.btn-reset-trigger:active { transform: scale(.92) rotate(-6deg); box-shadow: 0 0 24px rgba(239,68,68,.45); }
@keyframes reset-cw { 0%{transform:rotate(0)} 100%{transform:rotate(-360deg)} }

/* Reset modal layout */
.reset-modal-body { padding: 0; }

.reset-modal-hero {
    padding: 2rem 2rem 1.5rem;
    text-align: center;
    background: linear-gradient(180deg, rgba(239,68,68,.08) 0%, transparent 100%);
    border-bottom: 1px solid rgba(239,68,68,.12);
    position: relative; overflow: hidden;
}
.reset-modal-hero::before {
    content: '';
    position: absolute; top: -40px; left: 50%; transform: translateX(-50%);
    width: 200px; height: 200px; border-radius: 50%;
    background: radial-gradient(circle, rgba(239,68,68,.12), transparent 70%);
    animation: rm-pulse 2.5s ease-in-out infinite;
}
@keyframes rm-pulse { 0%,100%{transform:translateX(-50%) scale(1);opacity:1} 50%{transform:translateX(-50%) scale(1.15);opacity:.6} }

.reset-icon-big {
    width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem;
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(185,28,28,.12));
    border: 2px solid rgba(239,68,68,.4);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; color: #fca5a5;
    box-shadow: 0 0 28px rgba(239,68,68,.25);
    position: relative; z-index: 1;
}
.reset-title {
    font-size: 1.1rem; font-weight: 900; color: var(--txt);
    margin-bottom: .4rem; position: relative; z-index: 1;
}
.reset-subtitle {
    font-size: .82rem; color: var(--txt-s); position: relative; z-index: 1;
}
.reset-vname {
    color: #fcd34d; font-weight: 800;
}

.reset-content { padding: 1.4rem 2rem; }

.reset-warning-box {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .9rem 1rem; border-radius: 8px; margin-bottom: 1.2rem;
    background: rgba(251,191,36,.06);
    border: 1px solid rgba(251,191,36,.2);
}
.reset-warning-box i { color: #fbbf24; font-size: 1rem; margin-top: .05rem; flex-shrink: 0; }
.reset-warning-text { font-size: .8rem; color: #fde68a; line-height: 1.5; }

.reset-mods-label {
    font-size: .62rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .14em; color: var(--txt-s); margin-bottom: .65rem;
}
.reset-mods-list {
    list-style: none;
    display: flex; flex-wrap: wrap; gap: .38rem;
    max-height: 140px; overflow-y: auto;
    padding-right: .2rem;
}
.reset-mods-list::-webkit-scrollbar { width: 3px; }
.reset-mods-list::-webkit-scrollbar-thumb { background: rgba(239,68,68,.3); border-radius: 2px; }
.reset-mod-chip {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .65rem; border-radius: 5px;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2);
    font-size: .7rem; font-weight: 700; color: #fca5a5;
}
.reset-mod-chip .chip-pct { color: #94a3b8; font-size: .66rem; }

.reset-confirm-btn {
    width: 100%; padding: .85rem; border-radius: 9px; cursor: pointer;
    background: linear-gradient(135deg, #dc2626, #991b1b);
    border: 1px solid rgba(239,68,68,.4);
    color: #fff; font-family: inherit; font-size: .9rem; font-weight: 800;
    letter-spacing: .04em;
    display: flex; align-items: center; justify-content: center; gap: .55rem;
    position: relative; overflow: hidden;
    transition: all .18s;
    box-shadow: 0 4px 16px rgba(220,38,38,.35);
}
.reset-confirm-btn::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,.1), transparent);
    opacity: 0; transition: opacity .18s;
}
.reset-confirm-btn:hover {
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    box-shadow: 0 6px 24px rgba(220,38,38,.5);
    transform: translateY(-1px);
}
.reset-confirm-btn:hover::before { opacity: 1; }
.reset-confirm-btn:hover i { animation: reset-cw .4s ease; }
.reset-confirm-btn:active { transform: scale(.97); }

.reset-footer {
    padding: 1rem 2rem;
    border-top: 1px solid rgba(239,68,68,.1);
    display: flex; gap: .65rem;
}
@media (max-width: 768px) {
    .vehicles-grid { grid-template-columns: 1fr; }
    .stats-rail { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- Ambient grid -->
<div class="garage-bg"></div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if (!$canManage): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    Mode lecture — votre grade ne permet pas de modifier les véhicules.
</div>
<?php endif; ?>

<!-- ── STATS RAIL ──────────────────────────────────────────────────────────── -->
<div class="stats-rail">
    <div class="stat-pill" style="--pill-color:#f59e0b">
        <div class="stat-pill-label">Véhicules</div>
        <div class="stat-pill-value"><?php echo $totalVehicles; ?></div>
        <div class="stat-pill-sub">en catalogue</div>
    </div>
    <div class="stat-pill" style="--pill-color:#4ade80">
        <div class="stat-pill-label">Catégories</div>
        <div class="stat-pill-value"><?php echo count($vehicles); ?></div>
        <div class="stat-pill-sub">actives</div>
    </div>
    <div class="stat-pill" style="--pill-color:#e879f9">
        <div class="stat-pill-label">Mods dispo</div>
        <div class="stat-pill-value"><?php echo count($allMods); ?></div>
        <div class="stat-pill-sub">modifications</div>
    </div>
    <?php
    $totalMods = 0;
    foreach ($rawVehicles as $rv) {
        $s2 = $db->prepare("SELECT COUNT(*) FROM bennys_vehicle_customizations WHERE vehicle_id=?");
        $s2->execute([$rv['id']]);
        $totalMods += (int)$s2->fetchColumn();
    }
    ?>
    <div class="stat-pill" style="--pill-color:#60a5fa">
        <div class="stat-pill-label">Mods appliquées</div>
        <div class="stat-pill-value"><?php echo $totalMods; ?></div>
        <div class="stat-pill-sub">sur les véhicules</div>
    </div>
</div>

<!-- ── TOOLBAR ────────────────────────────────────────────────────────────── -->
<form method="GET" id="searchForm">
<div class="toolbar">
    <div class="search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="Rechercher un véhicule…"
               value="<?php echo htmlspecialchars($search); ?>"
               oninput="this.form.submit()">
    </div>
    <?php if ($search): ?>
    <a href="?" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Réinitialiser</a>
    <?php endif; ?>
    <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary" onclick="openModal('addVehicleModal')">
        <i class="fas fa-plus"></i> Ajouter un véhicule
    </button>
    <?php endif; ?>
</div>
</form>

<!-- ── VEHICLE CATALOGUE ──────────────────────────────────────────────────── -->
<?php if (empty($vehicles)): ?>
<div class="empty-garage">
    <i class="fas fa-car-side"></i>
    <p>Aucun véhicule trouvé<?php echo $search ? " pour « <strong>".htmlspecialchars($search)."</strong> »" : ''; ?>.</p>
</div>
<?php else: ?>

<?php foreach ($vehicles as $category => $catVehicles):
    $ci = $catIcons[$category] ?? ['icon'=>'car', 'color'=>'#f59e0b'];
?>
<div class="cat-section">
    <div class="cat-header" style="--cat-color:<?php echo $ci['color']; ?>; border-left-color:<?php echo $ci['color']; ?>">
        <div class="cat-icon" style="color:<?php echo $ci['color']; ?>; background:<?php echo $ci['color']; ?>18; border-color:<?php echo $ci['color']; ?>30">
            <i class="fas fa-<?php echo $ci['icon']; ?>"></i>
        </div>
        <span class="cat-name"><?php echo htmlspecialchars($category); ?></span>
        <span class="cat-count"><?php echo count($catVehicles); ?> véhicule<?php echo count($catVehicles) > 1 ? 's' : ''; ?></span>
    </div>

    <div class="vehicles-grid">
    <?php foreach ($catVehicles as $v): ?>
        <div class="vehicle-card"
             data-id="<?php echo $v['id']; ?>"
             data-name="<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>"
             data-category="<?php echo htmlspecialchars($v['category'], ENT_QUOTES); ?>"
             data-price="<?php echo $v['base_price']; ?>"
             data-desc="<?php echo htmlspecialchars($v['description'] ?? '', ENT_QUOTES); ?>">

            <div class="vc-band"></div>
            <div class="vc-body">
                <div class="vc-name"><?php echo htmlspecialchars($v['name']); ?></div>
                <div class="vc-desc"><?php echo htmlspecialchars($v['description'] ?: '—'); ?></div>

                <?php if (!empty($v['mods'])): ?>
                <div style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .7rem;border-radius:20px;font-size:.68rem;font-weight:700;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:#4ade80;margin-bottom:.75rem">
                    <i class="fas fa-tag"></i> Disponible à la vente &bull; <?php echo '$'.number_format($v['mods_price'],0,',',' '); ?>
                </div>
                <?php endif; ?>
                <div class="vc-prices">
                    <div class="vc-price-row">
                        <span class="vc-price-label">Prix de base</span>
                        <span class="vc-price-base">$<?php echo number_format($v['base_price'], 0, ',', ' '); ?></span>
                    </div>
                    <?php if (!empty($v['mods'])): ?>
                    <div class="vc-price-row">
                        <span class="vc-price-label">Coût des mods</span>
                        <span style="font-family:'Share Tech Mono',monospace;font-size:.95rem;color:var(--amber-l)">+$<?php echo number_format($v['mods_price'],0,',',' '); ?></span>
                    </div>
                    <div class="vc-price-row">
                        <span class="vc-price-label">Prix avec mods</span>
                        <span class="vc-price-final">$<?php echo number_format($v['final_price'], 0, ',', ' '); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($v['mods'])): ?>
                <div class="vc-mods">
                    <div class="vc-mods-label">
                        <i class="fas fa-wrench"></i>
                        <?php echo count($v['mods']); ?> modification<?php echo count($v['mods']) > 1 ? 's' : ''; ?>
                    </div>
                    <div class="vc-mods-tags">
                        <?php foreach ($v['mods'] as $m): ?>
                        <span class="mod-tag">
                            <?php echo htmlspecialchars($m['name']); ?>
                            <span class="pct">+<?php echo number_format($m['percentage'], 1); ?>%</span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
            <div class="vc-actions">
                <button class="btn btn-secondary btn-sm" style="flex:1"
                        onclick="openModsModal(this.closest('.vehicle-card'))">
                    <i class="fas fa-wrench"></i> Mods
                </button>
                <?php if (!empty($v['mods'])): ?>
                <button class="btn-reset-trigger btn-sm"
                        title="Réinitialiser les modifications"
                        onclick="openResetModal(this.closest('.vehicle-card'))">
                    <span class="reset-icon-wrap"><i class="fas fa-rotate-left"></i></span>
                    <span class="reset-bolt">⚡</span>
                </button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openEditModal(this.closest('.vehicle-card'))">
                    <i class="fas fa-pen"></i>
                </button>
                <form method="POST" style="margin:0"
                      data-confirm="Supprimer « %t » ?" data-confirm-target="<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="vehicle_id" value="<?php echo $v['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<?php if ($canManage): ?>
<!-- ── MODAL : AJOUTER VÉHICULE ─────────────────────────────────────────── -->
<div id="addVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter un véhicule</h2>
            <button class="modal-close" onclick="closeModal('addVehicleModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom du véhicule *</label>
                        <input type="text" name="vehicle_name" class="form-input" required placeholder="Ex : Pfister 811">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" class="form-select" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach (array_keys($catIcons) as $cName): ?>
                            <option value="<?php echo htmlspecialchars($cName); ?>"><?php echo htmlspecialchars($cName); ?></option>
                            <?php endforeach; ?>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix de base ($) *</label>
                        <input type="number" name="base_price" class="form-input" step="0.01" min="0" required placeholder="0">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3" placeholder="Caractéristiques du véhicule…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('addVehicleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL : MODIFIER VÉHICULE ────────────────────────────────────────── -->
<div id="editVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-pen"></i> Modifier le véhicule</h2>
            <button class="modal-close" onclick="closeModal('editVehicleModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="vehicle_id" id="edit_id">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom du véhicule *</label>
                        <input type="text" name="vehicle_name" id="edit_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" id="edit_category" class="form-select" required>
                            <?php foreach (array_keys($catIcons) as $cName): ?>
                            <option value="<?php echo htmlspecialchars($cName); ?>"><?php echo htmlspecialchars($cName); ?></option>
                            <?php endforeach; ?>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix de base ($) *</label>
                        <input type="number" name="base_price" id="edit_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_desc" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editVehicleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL : MODIFICATIONS ────────────────────────────────────────────── -->
<div id="modsModal" class="modal-overlay hidden">
    <div class="modal" style="max-width:720px">
        <div class="modal-header">
            <h2><i class="fas fa-wrench"></i> Mods — <span id="mods_vname" style="color:var(--amber-l)"></span></h2>
            <button class="modal-close" onclick="closeModal('modsModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_mods">
                <input type="hidden" name="vehicle_id" id="mods_vid">
                <?php if (empty($modsByType)): ?>
                    <div style="text-align:center;padding:2rem;color:var(--txt-s)">
                        <i class="fas fa-box-open" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.8rem"></i>
                        Aucune modification disponible dans la table <code>garage_mods</code>.
                    </div>
                <?php else: ?>
                <div class="alert alert-info" style="margin-bottom:1.2rem">
                    <i class="fas fa-info-circle"></i>
                    Cochez les mods à appliquer. Le prix sera recalculé (cumul multiplicatif).
                </div>
                <div class="mods-modal-grid">
                    <?php foreach ($modsByType as $type => $mods): ?>
                    <div class="mod-group">
                        <div class="mod-group-title"><i class="fas fa-cog"></i> <?php echo htmlspecialchars($type); ?></div>
                        <?php foreach ($mods as $m): ?>
                        <label class="mod-row">
                            <input type="checkbox" name="modifications[]" value="<?php echo $m['id']; ?>"
                                   class="mod-check" data-id="<?php echo $m['id']; ?>">
                            <span class="mod-row-name"><?php echo htmlspecialchars($m['name']); ?></span>
                            <span class="mod-row-pct">+<?php echo number_format($m['percentage'], 1); ?>%</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modsModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Appliquer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<?php if ($canManage): ?>
<!-- ── MODAL : RÉINITIALISER LES MODS ─────────────────────────────────────── -->
<div id="resetModal" class="modal-overlay hidden">
    <div class="modal" style="max-width:500px;border-color:rgba(239,68,68,.3)">

        <div class="reset-modal-body">
            <!-- Hero -->
            <div class="reset-modal-hero">
                <button class="modal-close" onclick="closeModal('resetModal')"
                        style="position:absolute;top:1rem;right:1rem;border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.08);color:#fca5a5">
                    &times;
                </button>
                <div class="reset-icon-big">
                    <i class="fas fa-rotate-left"></i>
                </div>
                <div class="reset-title">Réinitialiser les mods</div>
                <div class="reset-subtitle">
                    Véhicule : <span class="reset-vname" id="reset_vname_display"></span>
                </div>
            </div>

            <!-- Content -->
            <div class="reset-content">
                <div class="reset-warning-box">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div class="reset-warning-text">
                        Toutes les modifications seront supprimées et le véhicule sera
                        <strong>retiré de la page Vente</strong>. Cette action est irréversible.
                    </div>
                </div>

                <div class="reset-mods-label">Modifications qui seront supprimées</div>
                <ul class="reset-mods-list" id="reset_mods_list">
                    <!-- populated by JS -->
                </ul>
            </div>

            <!-- Footer with form -->
            <div class="reset-footer">
                <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('resetModal')">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <form method="POST" id="resetForm" style="flex:2">
                    <input type="hidden" name="action" value="reset_mods">
                    <input type="hidden" name="vehicle_id" id="reset_vid_input">
                    <button type="submit" class="reset-confirm-btn">
                        <i class="fas fa-rotate-left"></i>
                        Confirmer la réinitialisation
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}
// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(function(ov) {
    ov.addEventListener('click', function(e) {
        if (e.target === ov) ov.classList.add('hidden');
    });
});

// ── Open edit modal — reads data from card attributes (no JSON in onclick) ──
function openEditModal(card) {
    document.getElementById('edit_id').value       = card.dataset.id;
    document.getElementById('edit_name').value     = card.dataset.name;
    document.getElementById('edit_price').value    = card.dataset.price;
    document.getElementById('edit_desc').value     = card.dataset.desc;
    const sel = document.getElementById('edit_category');
    if (sel) sel.value = card.dataset.category;
    openModal('editVehicleModal');
}

// ── Open mods modal — pre-checks existing mods from DOM ──────────────────────
<?php
// Serialize current mods per vehicle id for JS
$vehicleModIds   = [];
$vehicleModNames = [];  // {vid: [{name, pct}]}
foreach ($rawVehicles as $rv) {
    $sm = $db->prepare("SELECT bvc.mod_id, gm.name, gm.percentage FROM bennys_vehicle_customizations bvc JOIN garage_mods gm ON gm.id=bvc.mod_id WHERE bvc.vehicle_id=?");
    $sm->execute([$rv['id']]);
    $rows = $sm->fetchAll(PDO::FETCH_ASSOC);
    $vehicleModIds[$rv['id']]   = array_column($rows, 'mod_id');
    $vehicleModNames[$rv['id']] = array_map(fn($r)=>['name'=>$r['name'],'pct'=>$r['percentage']], $rows);
}
?>
const VEHICLE_MODS      = <?php echo json_encode($vehicleModIds); ?>;
const VEHICLE_MOD_NAMES = <?php echo json_encode($vehicleModNames); ?>;

function openModsModal(card) {
    const vid  = card.dataset.id;
    const name = card.dataset.name;
    document.getElementById('mods_vid').value       = vid;
    document.getElementById('mods_vname').textContent = name;

    // Reset all checkboxes first
    document.querySelectorAll('#modsModal .mod-check').forEach(function(cb) {
        cb.checked = false;
    });

    // Pre-check current mods
    const current = VEHICLE_MODS[vid] || [];
    current.forEach(function(modId) {
        const cb = document.querySelector('#modsModal .mod-check[data-id="' + modId + '"]');
        if (cb) cb.checked = true;
    });

    openModal('modsModal');
}

// ── Open Reset Modal ─────────────────────────────────────────────────────────
function openResetModal(card) {
    const vid   = card.dataset.id;
    const vname = card.dataset.name;

    // Populate hidden input + title
    document.getElementById('reset_vid_input').value      = vid;
    document.getElementById('reset_vname_display').textContent = vname;

    // Build mod chips
    const modsList = document.getElementById('reset_mods_list');
    const mods     = VEHICLE_MOD_NAMES[vid] || [];
    if (mods.length) {
        modsList.innerHTML = mods.map(m => `
            <li class="reset-mod-chip">
                <i class="fas fa-wrench" style="font-size:.62rem;opacity:.6"></i>
                ${m.name}
                <span class="chip-pct">+${parseFloat(m.pct).toFixed(1)}%</span>
            </li>
        `).join('');
    } else {
        modsList.innerHTML = '<li style="color:var(--txt-s);font-size:.8rem">Aucune modification</li>';
    }

    openModal('resetModal');
}

// ── Compatibility alias for old calls ────────────────────────────────────────
const Company = { openModal: openModal, closeModal: closeModal };
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>