<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/faction_helper.php';
requireLogin();

$companyName    = 'Hayes';
$companyColor   = '#78716c';
$companyFaction = 'HAYES_AUTO';
$pageTitle      = 'Custom Garage';
$pageIcon       = 'car-side';

if ($_SESSION['user_faction'] !== $companyFaction &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'custom.view')) faction_acl_deny();
$canCustomize = faction_acl_can($db, $uid, $companyFaction, 'custom.customize');
$canManage    = faction_acl_can($db, $uid, $companyFaction, 'custom.add');
$canEdit      = faction_acl_can($db, $uid, $companyFaction, 'custom.edit');
$canDelete    = faction_acl_can($db, $uid, $companyFaction, 'custom.delete');
$isStaff      = faction_acl_staff_bypass($db, $uid, $companyFaction);

$msg = ''; $msgType = '';

// ── ACTIONS POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_vehicle' && $canManage) {
        try {
            $db->prepare("INSERT INTO garage_vehicles (name, category, base_price, description) VALUES (?,?,?,?)")
               ->execute([
                   trim($_POST['name']),
                   $_POST['category'],
                   floatval($_POST['base_price']),
                   trim($_POST['description'] ?? '')
               ]);
            $msg = 'Véhicule ajouté avec succès.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'update_vehicle' && $canManage) {
        try {
            $db->prepare("UPDATE garage_vehicles SET name=?, category=?, base_price=?, description=? WHERE id=?")
               ->execute([
                   trim($_POST['name']),
                   $_POST['category'],
                   floatval($_POST['base_price']),
                   trim($_POST['description'] ?? ''),
                   intval($_POST['vehicle_id'])
               ]);
            $msg = 'Véhicule modifié.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete_vehicle' && $canManage) {
        try {
            $db->prepare("DELETE FROM garage_vehicles WHERE id=?")->execute([intval($_POST['vehicle_id'])]);
            $msg = 'Véhicule supprimé.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'customize_vehicle' && $canCustomize) {
        try {
            $vid = intval($_POST['vehicle_id']);
            $db->prepare("DELETE FROM garage_vehicle_customizations WHERE vehicle_id=?")->execute([$vid]);
            $modIds = $_POST['mods'] ?? [];
            if (!empty($modIds)) {
                $stmtM = $db->prepare("INSERT INTO garage_vehicle_customizations (vehicle_id, mod_id) VALUES (?,?)");
                foreach ($modIds as $mid) { $stmtM->execute([$vid, intval($mid)]); }
            }
            $msg = count($modIds) . ' modification(s) appliquée(s).'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error'; }
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$vehicles = $db->query("
    SELECT v.id, v.name, v.category, v.base_price, v.description,
           COALESCE(SUM(m.percentage), 0)                              AS total_pct,
           v.base_price * (1 + COALESCE(SUM(m.percentage), 0) / 100)  AS final_price,
           COUNT(DISTINCT c.mod_id)                                    AS mods_count
    FROM garage_vehicles v
    LEFT JOIN garage_vehicle_customizations c ON v.id = c.vehicle_id
    LEFT JOIN garage_mods m ON c.mod_id = m.id
    GROUP BY v.id ORDER BY v.category, v.name
")->fetchAll(PDO::FETCH_ASSOC);

$vehiclesByCategory = [];
foreach ($vehicles as $v) { $vehiclesByCategory[$v['category']][] = $v; }

$allMods = $db->query("SELECT * FROM garage_mods ORDER BY category, subcategory, percentage DESC")
              ->fetchAll(PDO::FETCH_ASSOC);
$modsByCategory = [];
foreach ($allMods as $m) { $modsByCategory[$m['category']][$m['subcategory']][] = $m; }

$categoryIcons = [
    'Moto'                 => ['ico'=>'motorcycle',     'color'=>'#f97316'],
    'Sportives'            => ['ico'=>'bolt',           'color'=>'#a78bfa'],
    'Sportives Classiques' => ['ico'=>'flag-checkered', 'color'=>'#67e8f9'],
    'Muscle'               => ['ico'=>'fire',           'color'=>'#fb923c'],
    'Super'                => ['ico'=>'crown',          'color'=>'#e879f9'],
    'Off Road'             => ['ico'=>'mountain',       'color'=>'#4ade80'],
    'Sedan'                => ['ico'=>'car-side',       'color'=>'#60a5fa'],
    'Modèle'               => ['ico'=>'car',            'color'=>'#94a3b8'],
    'Vans'                 => ['ico'=>'van-shuttle',    'color'=>'#6366f1'],
    'Utilitaire'           => ['ico'=>'truck',          'color'=>'#78716c'],
    'Coupé'                => ['ico'=>'car-rear',       'color'=>'#f43f5e'],
    'Compact'              => ['ico'=>'cube',           'color'=>'#22d3ee'],
];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ══ CUSTOM PAGE — LS CUSTOM CYBERPUNK ══════════════════════════════════════ */

/* Scan-line overlay on content */
.lsc-content::before {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background: repeating-linear-gradient(
        0deg,
        transparent, transparent 3px,
        rgba(120,113,108,.012) 3px, rgba(120,113,108,.012) 4px
    );
}

/* ── KPI RAIL ── */
.kpi-rail {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: .85rem; margin-bottom: 2rem;
}
.kpi-node {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: 11px; padding: 1rem 1.1rem;
    position: relative; overflow: hidden;
    transition: border-color .2s;
}
.kpi-node::before {
    content: '';
    position: absolute; top: 0; left: 0;
    width: 100%; height: 2px;
    background: var(--kpi-col, var(--violet));
    box-shadow: 0 0 10px var(--kpi-col, var(--violet));
}
.kpi-node:hover { border-color: rgba(120,113,108,.3); }
.kpi-label {
    font-size: .6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .15em;
    color: var(--txt-s); margin-bottom: .38rem;
}
.kpi-val {
    font-family: 'DM Mono', 'Orbitron', monospace;
    font-size: 1.7rem; color: var(--txt); line-height: 1;
}
.kpi-sub { font-size: .68rem; color: var(--txt-s); margin-top: .22rem; }

/* ── TOOLBAR ── */
.toolbar {
    display: flex; gap: .8rem; align-items: center;
    flex-wrap: wrap; margin-bottom: 2rem;
}
.srch-wrap {
    flex: 1; min-width: 200px; max-width: 340px;
    position: relative;
}
.srch-wrap i {
    position: absolute; left: .85rem; top: 50%;
    transform: translateY(-50%);
    color: var(--txt-s); font-size: .78rem; pointer-events: none;
}
.srch-wrap input {
    width: 100%;
    padding: .68rem .9rem .68rem 2.4rem;
    background: var(--bg-c); border: 1px solid var(--bdr);
    border-radius: 8px; color: var(--txt);
    font-family: 'DM Sans', sans-serif; font-size: .88rem;
    transition: all .16s;
}
.srch-wrap input:focus {
    outline: none; border-color: var(--violet);
    box-shadow: 0 0 0 3px rgba(120,113,108,.1);
}
.srch-wrap input::placeholder { color: var(--txt-m); }

/* ── CATEGORY SECTION ── */
.cat-sec { margin-bottom: 2.8rem; }
.cat-hdr {
    display: flex; align-items: center; gap: 1rem;
    padding: .85rem 1.3rem;
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-left: 3px solid var(--cat-col, var(--violet));
    border-radius: 0 10px 10px 0;
    margin-bottom: 1.3rem;
    position: relative; overflow: hidden;
}
.cat-hdr::before {
    content: '';
    position: absolute; top: 0; left: 0; bottom: 0;
    width: 60%;
    background: linear-gradient(90deg, rgba(120,113,108,.05), transparent);
}
.cat-ico {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; flex-shrink: 0;
    background: rgba(120,113,108,.08);
    border: 1px solid rgba(120,113,108,.2);
}
.cat-name {
    font-family: 'Orbitron', monospace;
    font-size: .98rem; font-weight: 800;
    color: var(--txt); letter-spacing: .05em;
}
.cat-count {
    margin-left: auto;
    font-family: 'DM Mono', monospace; font-size: .78rem;
    color: var(--txt-s);
    background: rgba(120,113,108,.07);
    border: 1px solid rgba(120,113,108,.15);
    border-radius: 6px; padding: .22rem .65rem;
}

/* ── VEHICLES GRID ── */
.vgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.2rem;
}

/* ── VEHICLE CARD ── */
.vcard {
    background: var(--bg-c);
    border: 1px solid var(--bdr);
    border-radius: 13px; overflow: hidden;
    transition: all .2s; position: relative;
}
.vcard::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg,
        rgba(120,113,108,.03) 0%, transparent 50%);
    pointer-events: none;
}
.vcard:hover {
    border-color: rgba(120,113,108,.38);
    transform: translateY(-4px);
    box-shadow: 0 16px 48px rgba(0,0,0,.5),
                0 0 32px rgba(120,113,108,.08);
}

/* Neon top strip */
.vcard-strip {
    height: 2px;
    background: linear-gradient(90deg,
        var(--violet-d), var(--violet), var(--cyan), var(--violet), var(--violet-d));
    background-size: 200% 100%;
    animation: strip-flow 4s linear infinite;
}
@keyframes strip-flow { 0%{background-position:100% 0} 100%{background-position:-100% 0} }

.vcard-body { padding: 1.2rem 1.3rem; }

.vcard-name {
    font-family: 'Orbitron', monospace;
    font-size: .97rem; font-weight: 800;
    color: var(--txt); margin-bottom: .35rem;
    letter-spacing: .03em;
}
.vcard-desc {
    font-size: .8rem; color: var(--txt-s);
    line-height: 1.5; margin-bottom: .95rem;
    min-height: 2.2em;
}

/* Price block */
.vcard-prices {
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(120,113,108,.1);
    border-radius: 8px; padding: .85rem 1rem;
    margin-bottom: .95rem;
}
.vp-row { display: flex; justify-content: space-between; align-items: center; }
.vp-row + .vp-row {
    margin-top: .5rem; padding-top: .5rem;
    border-top: 1px dashed rgba(120,113,108,.12);
}
.vp-label { font-size: .72rem; color: var(--txt-s); text-transform: uppercase; letter-spacing: .08em; }
.vp-base {
    font-family: 'DM Mono', monospace;
    font-size: 1rem; color: #60a5fa;
}
.vp-mods { font-family: 'DM Mono', monospace; font-size: .92rem; color: var(--violet-l); }
.vp-final {
    font-family: 'DM Mono', monospace;
    font-size: 1.25rem; color: var(--cyan);
    text-shadow: 0 0 14px rgba(6,182,212,.4);
}

/* Mods indicator */
.vcard-mods { margin-bottom: .95rem; }
.vcard-mods-badge {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .3rem .8rem; border-radius: 20px;
    font-size: .75rem; font-weight: 600;
}
.vcard-mods-badge.has-mods {
    background: rgba(120,113,108,.1);
    border: 1px solid rgba(120,113,108,.25);
    color: var(--violet-l);
}
.vcard-mods-badge.no-mods {
    background: rgba(100,116,139,.08);
    border: 1px solid rgba(100,116,139,.18);
    color: var(--txt-s);
}

/* Card actions */
.vcard-actions {
    display: flex; gap: .5rem;
    padding: .85rem 1.3rem;
    border-top: 1px solid var(--bdr);
    background: rgba(0,0,0,.18);
}

/* ── CUSTOMIZE MODAL ── */
.mods-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.3rem;
    max-height: 58vh; overflow-y: auto;
    padding-right: .3rem;
}
.mods-grid::-webkit-scrollbar { width: 4px; }
.mods-grid::-webkit-scrollbar-thumb { background: rgba(120,113,108,.3); border-radius: 2px; }

.mod-cat {
    background: rgba(120,113,108,.04);
    border: 1px solid rgba(120,113,108,.12);
    border-radius: 10px; padding: 1rem 1.1rem;
}
.mod-cat-title {
    font-family: 'Orbitron', monospace;
    font-size: .7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--violet-l); margin-bottom: .9rem;
    display: flex; align-items: center; gap: .4rem;
}
.mod-sub-title {
    font-size: .68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--txt-s); margin-bottom: .6rem; margin-top: .8rem;
    padding-bottom: .4rem;
    border-bottom: 1px solid rgba(120,113,108,.1);
}
.mod-row {
    display: flex; align-items: center; gap: .7rem;
    padding: .42rem .4rem; border-radius: 6px;
    cursor: pointer; transition: background .14s;
}
.mod-row:hover { background: rgba(120,113,108,.07); }
.mod-row input[type="checkbox"] {
    width: 15px; height: 15px; cursor: pointer;
    accent-color: var(--violet); flex-shrink: 0;
}
.mod-row label {
    flex: 1; cursor: pointer;
    display: flex; justify-content: space-between; align-items: center;
    font-size: .83rem; color: var(--txt);
}
.mod-pct {
    background: rgba(120,113,108,.15);
    padding: .15rem .55rem; border-radius: 10px;
    font-size: .74rem; font-weight: 700;
    color: var(--violet-l); white-space: nowrap;
}

/* Price calculator sticky */
.price-calc {
    position: sticky; bottom: 0;
    background: linear-gradient(to top, var(--bg) 80%, transparent);
    border-top: 2px solid var(--violet);
    padding: 1.2rem 1.6rem .2rem;
    margin: 0 -1.6rem -1.6rem;
}
.calc-row {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: .55rem;
    font-size: .9rem; color: rgba(255,255,255,.75);
}
.calc-row.total {
    font-size: 1.1rem; font-weight: 700;
    padding-top: .75rem; margin-bottom: 0;
    border-top: 1px solid rgba(120,113,108,.2);
    color: var(--cyan);
    font-family: 'DM Mono', monospace;
}

/* ── EMPTY STATE ── */
.empty-state {
    text-align: center; padding: 5rem 2rem;
    color: var(--txt-s);
}
.empty-state i {
    font-size: 4rem; opacity: .12; display: block;
    margin-bottom: 1.2rem; color: var(--violet);
}

@media (max-width:768px) {
    .vgrid { grid-template-columns: 1fr; }
    .mods-grid { grid-template-columns: 1fr; }
    .kpi-rail { grid-template-columns: repeat(2,1fr); }
}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ── KPI RAIL ─────────────────────────────────────────────────────────── -->
<div class="kpi-rail">
    <div class="kpi-node" style="--kpi-col:#78716c">
        <div class="kpi-label">Véhicules</div>
        <div class="kpi-val"><?php echo count($vehicles); ?></div>
        <div class="kpi-sub">en catalogue</div>
    </div>
    <div class="kpi-node" style="--kpi-col:#06b6d4">
        <div class="kpi-label">Catégories</div>
        <div class="kpi-val"><?php echo count($vehiclesByCategory); ?></div>
        <div class="kpi-sub">actives</div>
    </div>
    <div class="kpi-node" style="--kpi-col:#e879f9">
        <div class="kpi-label">Mods dispo</div>
        <div class="kpi-val"><?php echo count($allMods); ?></div>
        <div class="kpi-sub">modifications</div>
    </div>
    <?php
    $totalApplied = array_sum(array_column($vehicles, 'mods_count'));
    ?>
    <div class="kpi-node" style="--kpi-col:#67e8f9">
        <div class="kpi-label">Mods actives</div>
        <div class="kpi-val"><?php echo $totalApplied; ?></div>
        <div class="kpi-sub">sur les véhicules</div>
    </div>
</div>

<!-- ── TOOLBAR ─────────────────────────────────────────────────────────── -->
<div class="toolbar">
    <div class="srch-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="srch" placeholder="Filtrer par nom…"
               oninput="filterVehicles(this.value)">
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-primary" onclick="openModal('addVehicleModal')">
        <i class="fas fa-plus"></i> Ajouter un véhicule
    </button>
    <?php endif; ?>
</div>

<!-- ── CATALOGUE ───────────────────────────────────────────────────────── -->
<?php if (empty($vehicles)): ?>
<div class="empty-state">
    <i class="fas fa-car-side"></i>
    <p>Aucun véhicule enregistré.</p>
    <?php if ($canManage): ?>
    <button class="btn btn-primary" style="margin-top:1.2rem" onclick="openModal('addVehicleModal')">
        <i class="fas fa-plus"></i> Ajouter le premier
    </button>
    <?php endif; ?>
</div>
<?php else: ?>

<?php foreach ($vehiclesByCategory as $cat => $catVehicles):
    $ci = $categoryIcons[$cat] ?? ['ico'=>'car', 'color'=>'#78716c'];
?>
<div class="cat-sec" data-cat="<?php echo htmlspecialchars(strtolower($cat)); ?>">
    <div class="cat-hdr" style="--cat-col:<?php echo $ci['color']; ?>; border-left-color:<?php echo $ci['color']; ?>">
        <div class="cat-ico" style="color:<?php echo $ci['color']; ?>; background:<?php echo $ci['color']; ?>18; border-color:<?php echo $ci['color']; ?>30">
            <i class="fas fa-<?php echo $ci['ico']; ?>"></i>
        </div>
        <span class="cat-name"><?php echo htmlspecialchars($cat); ?></span>
        <span class="cat-count"><?php echo count($catVehicles); ?> véhicule<?php echo count($catVehicles)>1?'s':''; ?></span>
    </div>

    <div class="vgrid">
    <?php foreach ($catVehicles as $v): ?>
    <div class="vcard"
         data-id="<?php echo $v['id']; ?>"
         data-name="<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>"
         data-category="<?php echo htmlspecialchars($v['category'], ENT_QUOTES); ?>"
         data-price="<?php echo $v['base_price']; ?>"
         data-desc="<?php echo htmlspecialchars($v['description'] ?? '', ENT_QUOTES); ?>"
         data-search="<?php echo strtolower(htmlspecialchars($v['name'])); ?>">

        <div class="vcard-strip"></div>
        <div class="vcard-body">
            <div class="vcard-name"><?php echo htmlspecialchars($v['name']); ?></div>
            <div class="vcard-desc"><?php echo htmlspecialchars($v['description'] ?: '—'); ?></div>

            <div class="vcard-prices">
                <div class="vp-row">
                    <span class="vp-label">Base</span>
                    <span class="vp-base">$<?php echo number_format($v['base_price'], 0, ',', ' '); ?></span>
                </div>
                <?php if ($v['mods_count'] > 0): ?>
                <div class="vp-row">
                    <span class="vp-label">Mods (+<?php echo number_format($v['total_pct'], 1); ?>%)</span>
                    <span class="vp-mods">+$<?php echo number_format($v['final_price'] - $v['base_price'], 0, ',', ' '); ?></span>
                </div>
                <div class="vp-row">
                    <span class="vp-label">Prix final</span>
                    <span class="vp-final">$<?php echo number_format($v['final_price'], 0, ',', ' '); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="vcard-mods">
                <span class="vcard-mods-badge <?php echo $v['mods_count'] > 0 ? 'has-mods' : 'no-mods'; ?>">
                    <i class="fas fa-wrench"></i>
                    <?php echo $v['mods_count']; ?> mod<?php echo $v['mods_count'] !== 1 ? 's' : ''; ?>
                    <?php if ($v['mods_count'] === 0): ?><span style="color:var(--txt-m)">— aucune</span><?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($canCustomize || $canManage): ?>
        <div class="vcard-actions">
            <?php if ($canCustomize): ?>
            <button class="btn btn-cyan btn-sm" style="flex:1"
                    onclick="openCustomizeModal(this.closest('.vcard'))">
                <i class="fas fa-paint-brush"></i> Customiser
            </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <button class="btn btn-secondary btn-sm"
                    onclick="openEditModal(this.closest('.vcard'))">
                <i class="fas fa-pen"></i>
            </button>
            <button class="btn btn-danger btn-sm"
                    onclick="deleteVehicle(this.closest('.vcard'))">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════ -->

<!-- Ajouter -->
<?php if ($canManage): ?>
<div id="addVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter un véhicule</h2>
            <button class="modal-close" onclick="closeModal('addVehicleModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_vehicle">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="name" class="form-input" required placeholder="Ex : Pfister 811">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" class="form-select" required>
                            <option value="">— Choisir —</option>
                            <?php foreach ($categoryIcons as $cName => $ci): ?>
                            <option value="<?php echo htmlspecialchars($cName); ?>"><?php echo htmlspecialchars($cName); ?></option>
                            <?php endforeach; ?>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix de base ($) *</label>
                        <input type="number" name="base_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3"></textarea>
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

<!-- Modifier -->
<div id="editVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-pen"></i> Modifier le véhicule</h2>
            <button class="modal-close" onclick="closeModal('editVehicleModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" id="e_id">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="name" id="e_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" id="e_cat" class="form-select" required>
                            <?php foreach ($categoryIcons as $cName => $ci): ?>
                            <option value="<?php echo htmlspecialchars($cName); ?>"><?php echo htmlspecialchars($cName); ?></option>
                            <?php endforeach; ?>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix de base ($) *</label>
                        <input type="number" name="base_price" id="e_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="e_desc" class="form-textarea" rows="3"></textarea>
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
<?php endif; ?>

<!-- Customiser (tous les employés) -->
<div id="customizeModal" class="modal-overlay hidden">
    <div class="modal" style="max-width:960px">
        <div class="modal-header">
            <h2><i class="fas fa-paint-brush"></i> Customiser — <span id="c_vname" style="color:var(--violet-l)"></span></h2>
            <button class="modal-close" onclick="closeModal('customizeModal')">&times;</button>
        </div>
        <form method="POST" id="customizeForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="customize_vehicle">
                <input type="hidden" name="vehicle_id" id="c_vid">

                <div class="mods-grid">
                    <!-- Performance -->
                    <?php if (!empty($modsByCategory['Performance'])): ?>
                    <div class="mod-cat">
                        <div class="mod-cat-title"><i class="fas fa-tachometer-alt"></i> Performance</div>
                        <?php foreach ($modsByCategory['Performance'] as $sub => $mods): ?>
                        <div class="mod-sub-title"><?php echo htmlspecialchars($sub); ?></div>
                        <?php foreach ($mods as $m): ?>
                        <div class="mod-row">
                            <input type="checkbox" name="mods[]"
                                   value="<?php echo $m['id']; ?>"
                                   id="mod_<?php echo $m['id']; ?>"
                                   data-id="<?php echo $m['id']; ?>"
                                   data-pct="<?php echo $m['percentage']; ?>"
                                   onchange="calcPrice()">
                            <label for="mod_<?php echo $m['id']; ?>">
                                <span><?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="mod-pct">+<?php echo number_format($m['percentage'],1); ?>%</span>
                            </label>
                        </div>
                        <?php endforeach; endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Aspect -->
                    <?php if (!empty($modsByCategory['Aspect'])): ?>
                    <div class="mod-cat">
                        <div class="mod-cat-title"><i class="fas fa-palette"></i> Aspect</div>
                        <?php foreach ($modsByCategory['Aspect'] as $sub => $mods): ?>
                        <div class="mod-sub-title"><?php echo htmlspecialchars($sub); ?></div>
                        <?php foreach ($mods as $m): ?>
                        <div class="mod-row">
                            <input type="checkbox" name="mods[]"
                                   value="<?php echo $m['id']; ?>"
                                   id="mod_<?php echo $m['id']; ?>"
                                   data-id="<?php echo $m['id']; ?>"
                                   data-pct="<?php echo $m['percentage']; ?>"
                                   onchange="calcPrice()">
                            <label for="mod_<?php echo $m['id']; ?>">
                                <span><?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="mod-pct">+<?php echo number_format($m['percentage'],1); ?>%</span>
                            </label>
                        </div>
                        <?php endforeach; endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Autres catégories -->
                    <?php foreach ($modsByCategory as $catName => $subs):
                        if (in_array($catName, ['Performance','Aspect'])) continue; ?>
                    <div class="mod-cat">
                        <div class="mod-cat-title"><i class="fas fa-cog"></i> <?php echo htmlspecialchars($catName); ?></div>
                        <?php foreach ($subs as $sub => $mods): ?>
                        <div class="mod-sub-title"><?php echo htmlspecialchars($sub); ?></div>
                        <?php foreach ($mods as $m): ?>
                        <div class="mod-row">
                            <input type="checkbox" name="mods[]"
                                   value="<?php echo $m['id']; ?>"
                                   id="mod_<?php echo $m['id']; ?>"
                                   data-id="<?php echo $m['id']; ?>"
                                   data-pct="<?php echo $m['percentage']; ?>"
                                   onchange="calcPrice()">
                            <label for="mod_<?php echo $m['id']; ?>">
                                <span><?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="mod-pct">+<?php echo number_format($m['percentage'],1); ?>%</span>
                            </label>
                        </div>
                        <?php endforeach; endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calculateur prix -->
                <div class="price-calc">
                    <div class="calc-row">
                        <span>Prix de base</span>
                        <span id="c_base" style="font-family:'DM Mono',monospace;color:#60a5fa">$0</span>
                    </div>
                    <div class="calc-row">
                        <span>Mods sélectionnés (<span id="c_pct">0%</span>)</span>
                        <span id="c_mods" style="color:var(--violet-l)">+$0</span>
                    </div>
                    <div class="calc-row total">
                        <span>Coût total</span>
                        <span id="c_total">$0</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('customizeModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Appliquer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete form (dynamic) -->
<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete_vehicle">
    <input type="hidden" name="vehicle_id" id="d_id">
</form>

<script>
// ── Modal helpers ─────────────────────────────────────────────────────────
function openModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('hidden'); document.body.style.overflow = ''; }
}
document.querySelectorAll('.modal-overlay').forEach(function(ov) {
    ov.addEventListener('click', function(e) {
        if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow = ''; }
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(function(m) {
            m.classList.add('hidden');
        });
        document.body.style.overflow = '';
    }
});

// ── Open edit — data from card attributes (no JSON in onclick) ────────────
function openEditModal(card) {
    document.getElementById('e_id').value    = card.dataset.id;
    document.getElementById('e_name').value  = card.dataset.name;
    document.getElementById('e_price').value = card.dataset.price;
    document.getElementById('e_desc').value  = card.dataset.desc;
    const sel = document.getElementById('e_cat');
    if (sel) sel.value = card.dataset.category;
    openModal('editVehicleModal');
}

// ── Delete ────────────────────────────────────────────────────────────────
function deleteVehicle(card) {
    if (!confirm('Supprimer « ' + card.dataset.name + ' » ?\nCette action est irréversible.')) return;
    document.getElementById('d_id').value = card.dataset.id;
    document.getElementById('deleteForm').submit();
}

// ── Customize: open + load current mods via API ───────────────────────────
let currentBasePrice = 0;

async function openCustomizeModal(card) {
    currentBasePrice = parseFloat(card.dataset.price);
    document.getElementById('c_vid').value         = card.dataset.id;
    document.getElementById('c_vname').textContent = card.dataset.name;

    // Reset all checkboxes
    document.querySelectorAll('#customizeForm input[type="checkbox"]').forEach(cb => cb.checked = false);
    calcPrice();

    // Load existing mods via API
    try {
        const res  = await fetch('api/get-vehicle-mods.php?vehicle_id=' + card.dataset.id);
        const data = await res.json();
        if (data.success) {
            data.mods.forEach(modId => {
                const cb = document.querySelector('#mod_' + modId);
                if (cb) cb.checked = true;
            });
        }
    } catch (e) { console.warn('API mods non disponible', e); }

    calcPrice();
    openModal('customizeModal');
}

// ── Price calculator ───────────────────────────────────────────────────────
function calcPrice() {
    let totalPct = 0;
    document.querySelectorAll('#customizeForm input[type="checkbox"]:checked').forEach(cb => {
        totalPct += parseFloat(cb.dataset.pct || 0);
    });
    const modsPrice  = currentBasePrice * (totalPct / 100);
    const finalPrice = currentBasePrice + modsPrice;

    document.getElementById('c_base').textContent  = '$' + currentBasePrice.toLocaleString('fr-FR');
    document.getElementById('c_pct').textContent   = totalPct.toFixed(1) + '%';
    document.getElementById('c_mods').textContent  = '+$' + modsPrice.toLocaleString('fr-FR');
    document.getElementById('c_total').textContent = '$' + finalPrice.toLocaleString('fr-FR');
}

// ── Client-side search filter ──────────────────────────────────────────────
function filterVehicles(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.vcard').forEach(function(card) {
        const name = card.dataset.search || '';
        card.style.display = name.includes(q) ? '' : 'none';
    });
    // Hide empty category sections
    document.querySelectorAll('.cat-sec').forEach(function(sec) {
        const visible = Array.from(sec.querySelectorAll('.vcard')).some(c => c.style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
    });
}

// ── Compat alias ──────────────────────────────────────────────────────────
const MDT = { openModal, closeModal };
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>