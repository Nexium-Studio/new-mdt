<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Tarifs & Zones';
$pageSubtitle = 'Grille tarifaire et zones de Los Santos';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// ─── ZONES Los Santos depuis la carte ────────────────────────────────────────
$zonesLS = [
    // Zone 10 - bleu clair (côte)
    ['nom'=>'Del Perro',        'zone_num'=>10, 'couleur'=>'#06b6d4', 'icon'=>'🏖️', 'desc'=>'Plage et front de mer'],
    ['nom'=>'Pete Toraine',     'zone_num'=>10, 'couleur'=>'#0891b2', 'icon'=>'🌊', 'desc'=>'Zone côtière périphérique'],
    ['nom'=>'Vespucci Canaux',  'zone_num'=>10, 'couleur'=>'#0e7490', 'icon'=>'🛶', 'desc'=>'Canaux et marina'],
    ['nom'=>'Marina',           'zone_num'=>10, 'couleur'=>'#155e75', 'icon'=>'⛵', 'desc'=>'Port de plaisance'],
    // Zone 14 - mauve/lavande (centre)
    ['nom'=>'Centre-Ville',     'zone_num'=>14, 'couleur'=>'#8b5cf6', 'icon'=>'🏙️', 'desc'=>'Downtown Los Santos'],
    ['nom'=>'Little Seoul',     'zone_num'=>14, 'couleur'=>'#7c3aed', 'icon'=>'🏮', 'desc'=>'Quartier coréen'],
    ['nom'=>'Place des Cubes',  'zone_num'=>14, 'couleur'=>'#6d28d9', 'icon'=>'🏢', 'desc'=>'Quartier d\'affaires'],
    ['nom'=>'Maze Bank',        'zone_num'=>14, 'couleur'=>'#5b21b6', 'icon'=>'🏦', 'desc'=>'Secteur financier'],
    ['nom'=>'Studio',           'zone_num'=>14, 'couleur'=>'#4c1d95', 'icon'=>'🎬', 'desc'=>'Studios de cinéma'],
    // Zone 16 - rouge/rose (sud)
    ['nom'=>'La Mesa',          'zone_num'=>16, 'couleur'=>'#e11d48', 'icon'=>'🏭', 'desc'=>'Zone industrielle sud'],
    ['nom'=>'Wardock',          'zone_num'=>16, 'couleur'=>'#be123c', 'icon'=>'🔒', 'desc'=>'Zone sécurisée militaire'],
    ['nom'=>'Est Los Santos',   'zone_num'=>16, 'couleur'=>'#9f1239', 'icon'=>'🏗️', 'desc'=>'Périphérie est'],
    ['nom'=>'Quartier Vagos',   'zone_num'=>16, 'couleur'=>'#881337', 'icon'=>'🔶', 'desc'=>'Zone à risques'],
    ['nom'=>'Quartier Balles',  'zone_num'=>16, 'couleur'=>'#ca8a04', 'icon'=>'🎾', 'desc'=>'Résidentiel populaire'],
    ['nom'=>'La Came',          'zone_num'=>16, 'couleur'=>'#a16207', 'icon'=>'🔮', 'desc'=>'Quartier underground'],
    ['nom'=>'Quartier Familles','zone_num'=>16, 'couleur'=>'#713f12', 'icon'=>'🏘️', 'desc'=>'Zone résidentielle'],
    // Zone 30 - orange (nord)
    ['nom'=>'Golf',             'zone_num'=>30, 'couleur'=>'#16a34a', 'icon'=>'⛳', 'desc'=>'Country Club & Golf'],
    ['nom'=>'Rockford Hills',   'zone_num'=>30, 'couleur'=>'#15803d', 'icon'=>'🏡', 'desc'=>'Quartier luxueux'],
    ['nom'=>'Vinewood',         'zone_num'=>30, 'couleur'=>'#166534', 'icon'=>'⭐', 'desc'=>'Zone des stars'],
    ['nom'=>'Mirror Park',      'zone_num'=>30, 'couleur'=>'#14532d', 'icon'=>'🪞', 'desc'=>'Quartier résidentiel calme'],
    ['nom'=>'Casino',           'zone_num'=>30, 'couleur'=>'#dc2626', 'icon'=>'🎰', 'desc'=>'Diamond Casino & Resort'],
    // Zone 35 - vert (sous-zone)
    ['nom'=>'Sandy Shores',     'zone_num'=>35, 'couleur'=>'#d97706', 'icon'=>'🏜️', 'desc'=>'Zone désertique', 'extra'=>'+Désert'],
    ['nom'=>'Paleto Bay',       'zone_num'=>35, 'couleur'=>'#b45309', 'icon'=>'🌲', 'desc'=>'Zone nord éloignée', 'extra'=>'+Nord'],
    // Zone 36 - orange foncé (hors ville)
    ['nom'=>'Aéroport',         'zone_num'=>36, 'couleur'=>'#0284c7', 'icon'=>'✈️', 'desc'=>'Los Santos International'],
    ['nom'=>'Port',             'zone_num'=>36, 'couleur'=>'#0369a1', 'icon'=>'🚢', 'desc'=>'Port de commerce'],
    ['nom'=>'Dock',             'zone_num'=>36, 'couleur'=>'#075985', 'icon'=>'⚓', 'desc'=>'Terminal à conteneurs'],
];

// Regrouper par zone_num
$zonesParNum = [];
foreach ($zonesLS as $z) {
    $zonesParNum[$z['zone_num']][] = $z;
}
ksort($zonesParNum);

$zoneInfo = [
    10 => ['label'=>'Zone 10', 'color'=>'#06b6d4', 'majoration'=>0,  'desc'=>'Zone côtière — Tarif standard'],
    14 => ['label'=>'Zone 14', 'color'=>'#8b5cf6', 'majoration'=>0,  'desc'=>'Centre-ville — Tarif standard'],
    16 => ['label'=>'Zone 16', 'color'=>'#e11d48', 'majoration'=>10, 'desc'=>'Sud / Zone sensible — +10%'],
    30 => ['label'=>'Zone 30', 'color'=>'#f97316', 'majoration'=>20, 'desc'=>'Nord-Ouest / Vinewood — +20%'],
    35 => ['label'=>'Zone 35', 'color'=>'#84cc16', 'majoration'=>25, 'desc'=>'Banlieue éloignée — +25%'],
    36 => ['label'=>'Zone 36', 'color'=>'#f59e0b', 'majoration'=>30, 'desc'=>'Hors-ville / Aéroport — +30%'],
];

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isChef) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_tarif') {
        try {
            // Si nouveau défaut, enlever l'ancien
            if (!empty($_POST['defaut'])) {
                $db->exec("UPDATE taxi_tarifs SET defaut=0");
            }
            $db->prepare("
                INSERT INTO taxi_tarifs (nom, tarif_prise_en_charge, tarif_par_km, tarif_par_minute,
                    majoration_nuit_pct, majoration_weekend_pct, heure_debut_nuit, heure_fin_nuit, defaut)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                trim($_POST['nom']),
                floatval(str_replace(',','.',$_POST['tarif_pec'])),
                floatval(str_replace(',','.',$_POST['tarif_km'])),
                floatval(str_replace(',','.',$_POST['tarif_min']??0)),
                floatval(str_replace(',','.',$_POST['maj_nuit']??0)),
                floatval(str_replace(',','.',$_POST['maj_weekend']??0)),
                $_POST['heure_nuit_debut']??'22:00',
                $_POST['heure_nuit_fin']??'06:00',
                !empty($_POST['defaut']) ? 1 : 0,
            ]);
            $msg = 'Tarif créé.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'edit_tarif') {
        try {
            if (!empty($_POST['defaut'])) {
                $db->exec("UPDATE taxi_tarifs SET defaut=0");
            }
            $db->prepare("
                UPDATE taxi_tarifs SET nom=?, tarif_prise_en_charge=?, tarif_par_km=?, tarif_par_minute=?,
                    majoration_nuit_pct=?, majoration_weekend_pct=?, heure_debut_nuit=?, heure_fin_nuit=?, defaut=?
                WHERE id=?
            ")->execute([
                trim($_POST['nom']),
                floatval(str_replace(',','.',$_POST['tarif_pec'])),
                floatval(str_replace(',','.',$_POST['tarif_km'])),
                floatval(str_replace(',','.',$_POST['tarif_min']??0)),
                floatval(str_replace(',','.',$_POST['maj_nuit']??0)),
                floatval(str_replace(',','.',$_POST['maj_weekend']??0)),
                $_POST['heure_nuit_debut']??'22:00',
                $_POST['heure_nuit_fin']??'06:00',
                !empty($_POST['defaut']) ? 1 : 0,
                intval($_POST['tarif_id']),
            ]);
            $msg = 'Tarif mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'toggle_tarif') {
        try {
            $db->prepare("UPDATE taxi_tarifs SET actif=NOT actif WHERE id=?")->execute([intval($_POST['tarif_id'])]);
            $msg = 'Statut modifié.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'create_zone') {
        try {
            $db->prepare("INSERT INTO taxi_zones (nom, description, majoration_pct) VALUES (?,?,?)")
               ->execute([
                   trim($_POST['nom']),
                   trim($_POST['description']??''),
                   floatval(str_replace(',','.',$_POST['majoration_pct']??0)),
               ]);
            $msg = 'Zone créée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'edit_zone') {
        try {
            $db->prepare("UPDATE taxi_zones SET nom=?, description=?, majoration_pct=? WHERE id=?")
               ->execute([
                   trim($_POST['nom']),
                   trim($_POST['description']??''),
                   floatval(str_replace(',','.',$_POST['majoration_pct']??0)),
                   intval($_POST['zone_id']),
               ]);
            $msg = 'Zone mise à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ─── DONNÉES ─────────────────────────────────────────────────────────────────
$tarifs = $db->query("
    SELECT t.*,
           COUNT(c.id) as nb_utilisations
    FROM taxi_tarifs t
    LEFT JOIN taxi_courses c ON c.tarif_id=t.id
    GROUP BY t.id
    ORDER BY t.defaut DESC, t.nom ASC
")->fetchAll();

$zones = $db->query("
    SELECT z.*,
           COUNT(c.id) as nb_courses,
           SUM(c.montant_total) as ca_total
    FROM taxi_zones z
    LEFT JOIN taxi_courses c ON c.zone_id=z.id
    GROUP BY z.id
    ORDER BY z.majoration_pct ASC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* ─ Tarif Card ─ */
.tarif-card {
    background:rgba(13,17,23,.8);
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
    transition:all .22s;
}
.tarif-card:hover { border-color:rgba(245,158,11,.38); }
.tarif-card.defaut { border-color:rgba(245,158,11,.5); box-shadow:0 0 20px rgba(245,158,11,.1); }
.tarif-card.inactif { opacity:.55; }

.tc-header {
    padding:.95rem 1.15rem;
    display:flex; align-items:center; justify-content:space-between;
    border-bottom:1px solid rgba(245,158,11,.08);
    background:linear-gradient(135deg,rgba(245,158,11,.05),transparent);
}
.tc-name  { font-size:.95rem; font-weight:800; color:var(--text); }
.tc-grid  { display:grid; grid-template-columns:repeat(2,1fr); gap:.65rem; padding:1rem 1.15rem; }
.tc-item  { }
.tc-label { font-size:.65rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin-bottom:.2rem; }
.tc-val   { font-size:1.1rem; font-weight:800; color:var(--col-lt); }
.tc-val-sm{ font-size:.85rem; font-weight:700; color:#94a3b8; }
.tc-footer{
    padding:.75rem 1.15rem;
    border-top:1px solid rgba(245,158,11,.08);
    display:flex; align-items:center; justify-content:space-between; gap:.5rem;
    flex-wrap:wrap;
}

/* ─ Carte Los Santos ─ */
.map-container {
    background:rgba(13,17,23,.9);
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
    padding:1.5rem;
}

.map-legend { display:flex; flex-direction:column; gap:.5rem; }
.map-legend-row {
    display:flex; align-items:center; gap:.65rem;
    padding:.55rem .75rem;
    border-radius:9px;
    border:1px solid rgba(255,255,255,.06);
    cursor:pointer;
    transition:all .18s;
}
.map-legend-row:hover { background:rgba(245,158,11,.06); }
.map-legend-dot { width:14px; height:14px; border-radius:50%; flex-shrink:0; }
.map-legend-label { font-size:.8rem; font-weight:700; color:var(--text); flex:1; }
.map-legend-pct { font-size:.75rem; font-weight:700; padding:.15rem .5rem; border-radius:5px; }

/* ─ Zones quartiers ─ */
.quartiers-grid { display:flex; flex-wrap:wrap; gap:.35rem; padding:1rem 0; }
.quartier-chip {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.28rem .65rem; border-radius:20px;
    font-size:.72rem; font-weight:600;
    cursor:pointer; transition:all .18s;
    border:1px solid transparent;
}
.quartier-chip:hover { transform:translateY(-1px); box-shadow:0 2px 8px rgba(0,0,0,.3); }

/* ─ Simulateur ─ */
.simulator {
    background:linear-gradient(135deg,rgba(245,158,11,.08),rgba(245,158,11,.03));
    border:1px solid rgba(245,158,11,.22);
    border-radius:12px;
    padding:1.25rem;
}
.sim-result {
    background:rgba(0,0,0,.3);
    border:1px solid rgba(245,158,11,.2);
    border-radius:9px;
    padding:1rem;
    margin-top:1rem;
}
.sim-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:.3rem 0;
    font-size:.83rem;
    border-bottom:1px solid rgba(245,158,11,.06);
}
.sim-row:last-child { border-bottom:none; }
.sim-total {
    font-size:1.15rem; font-weight:800;
    color:var(--col-lt); padding-top:.5rem;
    border-top:2px solid rgba(245,158,11,.2) !important;
    margin-top:.25rem;
}

/* ─ Zone DB card ─ */
.zone-db-card {
    background:rgba(13,17,23,.8);
    border:1px solid var(--border);
    border-radius:11px;
    padding:.9rem 1.1rem;
    display:flex; align-items:center; gap:.85rem;
    transition:all .2s;
}
.zone-db-card:hover { border-color:rgba(245,158,11,.35); }
.zone-color-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.4rem;align-items:start;">

    <!-- Colonne principale -->
    <div>

        <!-- ─ GRILLE TARIFAIRE ──────────────────────────────────────── -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-tag"></i> Grille Tarifaire</div>
                <?php if ($isChef): ?>
                <button onclick="Taxi.openModal('modal-new-tarif')" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Nouveau Tarif
                </button>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;">
                <?php foreach ($tarifs as $t): ?>
                <div class="tarif-card <?php echo $t['defaut']?'defaut':''; ?> <?php echo !$t['actif']?'inactif':''; ?>">
                    <div class="tc-header">
                        <div>
                            <div class="tc-name"><?php echo htmlspecialchars($t['nom']); ?></div>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem;"><?php echo $t['nb_utilisations']; ?> utilisation(s)</div>
                        </div>
                        <div style="display:flex;gap:.35rem;align-items:center;">
                            <?php if ($t['defaut']): ?>
                            <span class="badge" style="background:rgba(245,158,11,.2);color:var(--col-lt);border:1px solid rgba(245,158,11,.4);font-size:.65rem;">
                                <i class="fas fa-star"></i> Défaut
                            </span>
                            <?php endif; ?>
                            <span class="badge <?php echo $t['actif']?'b-terminee':'b-annulee'; ?>" style="font-size:.65rem;">
                                <?php echo $t['actif']?'Actif':'Inactif'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="tc-grid">
                        <div class="tc-item">
                            <div class="tc-label">Prise en charge</div>
                            <div class="tc-val">$<?php echo number_format($t['tarif_prise_en_charge'],2); ?></div>
                        </div>
                        <div class="tc-item">
                            <div class="tc-label">Prix / km</div>
                            <div class="tc-val">$<?php echo number_format($t['tarif_par_km'],2); ?></div>
                        </div>
                        <?php if ($t['tarif_par_minute'] > 0): ?>
                        <div class="tc-item">
                            <div class="tc-label">Prix / minute</div>
                            <div class="tc-val-sm">$<?php echo number_format($t['tarif_par_minute'],2); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($t['majoration_nuit_pct'] > 0): ?>
                        <div class="tc-item">
                            <div class="tc-label">Majoration Nuit</div>
                            <div class="tc-val-sm" style="color:#a78bfa;">+<?php echo $t['majoration_nuit_pct']; ?>%</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($t['majoration_weekend_pct'] > 0): ?>
                        <div class="tc-item">
                            <div class="tc-label">Maj. Weekend</div>
                            <div class="tc-val-sm" style="color:#60a5fa;">+<?php echo $t['majoration_weekend_pct']; ?>%</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($t['majoration_nuit_pct'] > 0): ?>
                    <div style="padding:.25rem 1.15rem .65rem;font-size:.72rem;color:var(--muted);">
                        <i class="fas fa-moon" style="color:#a78bfa;"></i>
                        Nuit : <?php echo substr($t['heure_debut_nuit'],0,5); ?> → <?php echo substr($t['heure_fin_nuit'],0,5); ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($isChef): ?>
                    <div class="tc-footer">
                        <button onclick="Taxi.openModal('modal-edit-tarif-<?php echo $t['id']; ?>')" class="btn btn-secondary btn-xs">
                            <i class="fas fa-pen"></i> Modifier
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_tarif">
                            <input type="hidden" name="tarif_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="btn <?php echo $t['actif']?'btn-danger':'btn-success'; ?> btn-xs">
                                <?php echo $t['actif']?'Désactiver':'Activer'; ?>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Modal modifier tarif -->
                <div id="modal-edit-tarif-<?php echo $t['id']; ?>" class="modal-overlay hidden">
                    <div class="modal">
                        <div class="modal-header">
                            <h2><i class="fas fa-pen"></i> Modifier — <?php echo htmlspecialchars($t['nom']); ?></h2>
                            <button class="modal-close" onclick="Taxi.closeModal('modal-edit-tarif-<?php echo $t['id']; ?>')">×</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_tarif">
                            <input type="hidden" name="tarif_id" value="<?php echo $t['id']; ?>">
                            <div class="modal-body">
                                <div class="form-group" style="margin-bottom:.9rem;">
                                    <label class="form-label">Nom du Tarif *</label>
                                    <input type="text" name="nom" class="form-input" value="<?php echo htmlspecialchars($t['nom']); ?>" required>
                                </div>
                                <div class="form-grid3" style="margin-bottom:.9rem;">
                                    <div class="form-group">
                                        <label class="form-label">Prise en charge ($)</label>
                                        <input type="number" name="tarif_pec" class="form-input" step="0.01" value="<?php echo $t['tarif_prise_en_charge']; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Prix / km ($)</label>
                                        <input type="number" name="tarif_km" class="form-input" step="0.01" value="<?php echo $t['tarif_par_km']; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Prix / min ($)</label>
                                        <input type="number" name="tarif_min" class="form-input" step="0.01" value="<?php echo $t['tarif_par_minute']; ?>">
                                    </div>
                                </div>
                                <div class="form-grid" style="margin-bottom:.9rem;">
                                    <div class="form-group">
                                        <label class="form-label">Majoration Nuit (%)</label>
                                        <input type="number" name="maj_nuit" class="form-input" step="0.1" value="<?php echo $t['majoration_nuit_pct']; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Majoration Weekend (%)</label>
                                        <input type="number" name="maj_weekend" class="form-input" step="0.1" value="<?php echo $t['majoration_weekend_pct']; ?>">
                                    </div>
                                </div>
                                <div class="form-grid" style="margin-bottom:.9rem;">
                                    <div class="form-group">
                                        <label class="form-label">Début Nuit</label>
                                        <input type="time" name="heure_nuit_debut" class="form-input" value="<?php echo substr($t['heure_debut_nuit'],0,5); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Fin Nuit</label>
                                        <input type="time" name="heure_nuit_fin" class="form-input" value="<?php echo substr($t['heure_fin_nuit'],0,5); ?>">
                                    </div>
                                </div>
                                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:var(--col-lt);">
                                    <input type="checkbox" name="defaut" style="accent-color:var(--col);width:16px;height:16px;" <?php echo $t['defaut']?'checked':''; ?>>
                                    Définir comme tarif par défaut
                                </label>
                            </div>
                            <div class="modal-footer">
                                <button type="button" onclick="Taxi.closeModal('modal-edit-tarif-<?php echo $t['id']; ?>')" class="btn btn-secondary">Annuler</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ─ CARTE LOS SANTOS ─────────────────────────────────────── -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-map"></i> Carte des Zones — Los Santos</div>
                <span style="font-size:.75rem;color:var(--muted);">Majoration par secteur</span>
            </div>

            <?php foreach ($zonesParNum as $num => $quartiers):
                $zi = $zoneInfo[$num];
            ?>
            <div style="margin-bottom:1.1rem;">
                <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.55rem;">
                    <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $zi['color']; ?>22;border:2px solid <?php echo $zi['color']; ?>;
                         display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;color:<?php echo $zi['color']; ?>;">
                        <?php echo $num; ?>
                    </div>
                    <div>
                        <div style="font-size:.88rem;font-weight:700;color:var(--text);"><?php echo $zi['label']; ?></div>
                        <div style="font-size:.72rem;color:var(--muted);"><?php echo $zi['desc']; ?></div>
                    </div>
                    <div style="margin-left:auto;">
                        <span class="badge" style="background:<?php echo $zi['color']; ?>20;border:1px solid <?php echo $zi['color']; ?>44;color:<?php echo $zi['color']; ?>;font-size:.72rem;">
                            <?php echo $zi['majoration']>0 ? '+'.$zi['majoration'].'%' : 'Standard'; ?>
                        </span>
                    </div>
                </div>
                <div class="quartiers-grid" style="padding:0;">
                    <?php foreach ($quartiers as $q): ?>
                    <span class="quartier-chip"
                          style="background:<?php echo $q['couleur']; ?>20;border-color:<?php echo $q['couleur']; ?>44;color:<?php echo $q['couleur']; ?>;"
                          title="<?php echo htmlspecialchars($q['desc']); ?>">
                        <?php echo $q['icon']; ?> <?php echo htmlspecialchars($q['nom']); ?>
                        <?php if (!empty($q['extra'])): ?><span style="opacity:.65;font-size:.62rem;"><?php echo $q['extra']; ?></span><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="height:1px;background:rgba(245,158,11,.06);margin-bottom:1.1rem;"></div>
            <?php endforeach; ?>

            <!-- Note tarification inter-zones -->
            <div style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:.9rem 1.1rem;font-size:.8rem;color:#94a3b8;line-height:1.6;">
                <div style="font-weight:700;color:var(--col-lt);margin-bottom:.35rem;"><i class="fas fa-info-circle"></i> Règle de Majoration Inter-Zones</div>
                Lorsqu'une course traverse plusieurs zones, la majoration de la <strong style="color:var(--text);">zone de destination</strong> est appliquée.
                Les courses entre zones 10/14 n'ont <strong style="color:#4ade80;">aucune majoration</strong>.
                Les courses vers l'aéroport (zone 36) ou les zones éloignées (35) incluent automatiquement la majoration.
            </div>
        </div>

        <!-- ─ ZONES BASE DE DONNÉES ────────────────────────────────── -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-map-marker-alt"></i> Zones Enregistrées (BDD)</div>
                <?php if ($isChef): ?>
                <button onclick="Taxi.openModal('modal-new-zone')" class="btn btn-secondary btn-sm">
                    <i class="fas fa-plus"></i> Ajouter Zone
                </button>
                <?php endif; ?>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.65rem;">
                <?php foreach ($zones as $z): ?>
                <div class="zone-db-card">
                    <div class="zone-color-dot" style="background:var(--col);opacity:.6;"></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.85rem;font-weight:700;color:var(--text);"><?php echo htmlspecialchars($z['nom']); ?></div>
                        <?php if ($z['description']): ?><div style="font-size:.72rem;color:var(--muted);"><?php echo htmlspecialchars($z['description']); ?></div><?php endif; ?>
                        <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem;"><?php echo $z['nb_courses']??0; ?> courses</div>
                    </div>
                    <span class="badge" style="background:rgba(245,158,11,.1);color:var(--col-lt);border:1px solid rgba(245,158,11,.25);font-size:.7rem;">
                        <?php echo $z['majoration_pct']>0?'+'.$z['majoration_pct'].'%':'Standard'; ?>
                    </span>
                    <?php if ($isChef): ?>
                    <button onclick="Taxi.openModal('modal-edit-zone-<?php echo $z['id']; ?>')" class="btn btn-secondary btn-xs"><i class="fas fa-pen"></i></button>
                    <?php endif; ?>
                </div>

                <!-- Modal edit zone -->
                <div id="modal-edit-zone-<?php echo $z['id']; ?>" class="modal-overlay hidden">
                    <div class="modal modal-sm">
                        <div class="modal-header">
                            <h2><i class="fas fa-map-marker-alt"></i> Modifier Zone</h2>
                            <button class="modal-close" onclick="Taxi.closeModal('modal-edit-zone-<?php echo $z['id']; ?>')">×</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_zone">
                            <input type="hidden" name="zone_id" value="<?php echo $z['id']; ?>">
                            <div class="modal-body">
                                <div class="form-group" style="margin-bottom:.85rem;">
                                    <label class="form-label">Nom *</label>
                                    <input type="text" name="nom" class="form-input" value="<?php echo htmlspecialchars($z['nom']); ?>" required>
                                </div>
                                <div class="form-group" style="margin-bottom:.85rem;">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-input" value="<?php echo htmlspecialchars($z['description']??''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Majoration (%)</label>
                                    <input type="number" name="majoration_pct" class="form-input" step="0.1" value="<?php echo $z['majoration_pct']; ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" onclick="Taxi.closeModal('modal-edit-zone-<?php echo $z['id']; ?>')" class="btn btn-secondary btn-sm">Annuler</button>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ─ SIDEBAR DROITE ─ ─────────────────────────────────────────── -->
    <div>

        <!-- Légende zones carte -->
        <div class="map-container" style="margin-bottom:1.25rem;">
            <div style="font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:.85rem;display:flex;align-items:center;gap:.45rem;">
                <i class="fas fa-layer-group" style="color:var(--col);"></i> Zones Tarifaires
            </div>
            <div class="map-legend">
                <?php foreach ($zoneInfo as $num => $zi): ?>
                <div class="map-legend-row" style="border-color:<?php echo $zi['color']; ?>25;">
                    <div class="map-legend-dot" style="background:<?php echo $zi['color']; ?>;"></div>
                    <div style="flex:1;">
                        <div class="map-legend-label"><?php echo $zi['label']; ?></div>
                        <div style="font-size:.68rem;color:var(--muted);"><?php echo $zi['desc']; ?></div>
                    </div>
                    <div class="map-legend-pct" style="background:<?php echo $zi['color']; ?>20;color:<?php echo $zi['color']; ?>;">
                        <?php echo $zi['majoration']>0?'+'.$zi['majoration'].'%':'Base'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Simulateur de course -->
        <?php $tarifDefaut = array_filter($tarifs, fn($t) => $t['defaut']); $tarifDefaut = reset($tarifDefaut) ?: $tarifs[0]; ?>
        <div class="simulator">
            <div style="font-size:.82rem;font-weight:700;color:var(--col-lt);margin-bottom:.85rem;display:flex;align-items:center;gap:.45rem;">
                <i class="fas fa-calculator"></i> Simulateur de Course
            </div>

            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label">Tarif</label>
                <select id="sim-tarif" class="form-select" onchange="simulate()">
                    <?php foreach ($tarifs as $t): if (!$t['actif']) continue; ?>
                    <option value="<?php echo $t['tarif_prise_en_charge']; ?>,<?php echo $t['tarif_par_km']; ?>,<?php echo $t['tarif_par_minute']; ?>" <?php echo $t['defaut']?'selected':''; ?>>
                        <?php echo htmlspecialchars($t['nom']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:.75rem;">
                <label class="form-label">Zone</label>
                <select id="sim-zone" class="form-select" onchange="simulate()">
                    <?php foreach ($zoneInfo as $num => $zi): ?>
                    <option value="<?php echo $zi['majoration']; ?>"><?php echo $zi['label']; ?> (<?php echo $zi['majoration']>0?'+'.$zi['majoration'].'%':'Standard'; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid" style="margin-bottom:.75rem;">
                <div class="form-group">
                    <label class="form-label">Distance (km)</label>
                    <input type="number" id="sim-km" class="form-input" value="5" min="0" step="0.1" oninput="simulate()">
                </div>
                <div class="form-group">
                    <label class="form-label">Durée (min)</label>
                    <input type="number" id="sim-min" class="form-input" value="10" min="0" oninput="simulate()">
                </div>
            </div>
            <div style="display:flex;gap:.65rem;margin-bottom:.5rem;">
                <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;color:#a78bfa;cursor:pointer;">
                    <input type="checkbox" id="sim-nuit" onchange="simulate()" style="accent-color:#a78bfa;"> 🌙 Nuit
                </label>
                <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;color:#60a5fa;cursor:pointer;">
                    <input type="checkbox" id="sim-weekend" onchange="simulate()" style="accent-color:#60a5fa;"> 📅 Weekend
                </label>
            </div>

            <div class="sim-result" id="sim-result">
                <div class="sim-row"><span>Prise en charge</span><span id="r-pec">—</span></div>
                <div class="sim-row"><span>Course (km)</span><span id="r-km">—</span></div>
                <div class="sim-row"><span>Durée</span><span id="r-dur">—</span></div>
                <div class="sim-row"><span>Majoration zone</span><span id="r-zone">—</span></div>
                <div class="sim-row"><span>Majoration nuit</span><span id="r-nuit" style="color:#a78bfa;">—</span></div>
                <div class="sim-row"><span>Majoration weekend</span><span id="r-wkd" style="color:#60a5fa;">—</span></div>
                <div class="sim-row sim-total"><span>TOTAL ESTIMÉ</span><span id="r-total">—</span></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODAL NOUVEAU TARIF ═══════════════════════════════════════ -->
<div id="modal-new-tarif" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouveau Tarif</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-new-tarif')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_tarif">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:.9rem;">
                    <label class="form-label">Nom du Tarif *</label>
                    <input type="text" name="nom" class="form-input" placeholder="ex: Tarif Standard, VIP, Longue Distance..." required>
                </div>
                <div class="form-grid3" style="margin-bottom:.9rem;">
                    <div class="form-group">
                        <label class="form-label">Prise en charge ($)</label>
                        <input type="number" name="tarif_pec" class="form-input" step="0.01" value="5.00" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix / km ($)</label>
                        <input type="number" name="tarif_km" class="form-input" step="0.01" value="2.50" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix / min ($)</label>
                        <input type="number" name="tarif_min" class="form-input" step="0.01" value="0.00">
                    </div>
                </div>
                <div class="form-grid" style="margin-bottom:.9rem;">
                    <div class="form-group">
                        <label class="form-label">Majoration Nuit (%)</label>
                        <input type="number" name="maj_nuit" class="form-input" step="0.1" value="25">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Majoration Weekend (%)</label>
                        <input type="number" name="maj_weekend" class="form-input" step="0.1" value="10">
                    </div>
                </div>
                <div class="form-grid" style="margin-bottom:.9rem;">
                    <div class="form-group">
                        <label class="form-label">Début Nuit</label>
                        <input type="time" name="heure_nuit_debut" class="form-input" value="22:00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fin Nuit</label>
                        <input type="time" name="heure_nuit_fin" class="form-input" value="06:00">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:var(--col-lt);">
                    <input type="checkbox" name="defaut" style="accent-color:var(--col);width:16px;height:16px;">
                    Définir comme tarif par défaut
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-new-tarif')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL NOUVELLE ZONE BDD ═══════════════════════════════════ -->
<div id="modal-new-zone" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-map-marker-alt"></i> Nouvelle Zone</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-new-zone')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_zone">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:.85rem;">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-input" placeholder="ex: Vinewood Hills" required>
                </div>
                <div class="form-group" style="margin-bottom:.85rem;">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-input" placeholder="Description courte...">
                </div>
                <div class="form-group">
                    <label class="form-label">Majoration (%)</label>
                    <input type="number" name="majoration_pct" class="form-input" step="0.1" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-new-zone')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<script>
const TARIFS_DATA = <?php echo json_encode(array_filter($tarifs, fn($t)=>$t['actif'])); ?>;
const ZONES_DATA  = <?php echo json_encode($zoneInfo); ?>;

function simulate() {
    const tarifSel = document.getElementById('sim-tarif').value.split(',');
    const pec   = parseFloat(tarifSel[0]) || 0;
    const km    = parseFloat(tarifSel[1]) || 0;
    const min   = parseFloat(tarifSel[2]) || 0;
    const zone  = parseFloat(document.getElementById('sim-zone').value) || 0;
    const dist  = parseFloat(document.getElementById('sim-km').value) || 0;
    const dur   = parseFloat(document.getElementById('sim-min').value) || 0;
    const nuit  = document.getElementById('sim-nuit').checked;
    const wkd   = document.getElementById('sim-weekend').checked;

    // Trouver maj nuit/wkd du tarif
    const tarif = TARIFS_DATA.find(t => t.tarif_prise_en_charge == tarifSel[0] && t.tarif_par_km == tarifSel[1]);
    const majNuit = tarif ? parseFloat(tarif.majoration_nuit_pct) : 25;
    const majWkd  = tarif ? parseFloat(tarif.majoration_weekend_pct) : 10;

    const base  = pec + (dist * km) + (dur * min);
    const zoneAmt  = base * (zone / 100);
    const nuitAmt  = nuit ? base * (majNuit / 100) : 0;
    const wkdAmt   = wkd  ? base * (majWkd / 100)  : 0;
    const total = base + zoneAmt + nuitAmt + wkdAmt;

    const f = v => '$'+v.toFixed(2);
    document.getElementById('r-pec').textContent  = f(pec);
    document.getElementById('r-km').textContent   = f(dist * km) + ' ('+dist+'km)';
    document.getElementById('r-dur').textContent  = f(dur * min) + ' ('+dur+'min)';
    document.getElementById('r-zone').textContent = zone > 0 ? '+'+f(zoneAmt)+' (+'+zone+'%)' : '—';
    document.getElementById('r-nuit').textContent = nuit ? '+'+f(nuitAmt)+' (+'+majNuit+'%)' : '—';
    document.getElementById('r-wkd').textContent  = wkd  ? '+'+f(wkdAmt)+' (+'+majWkd+'%)'  : '—';
    document.getElementById('r-total').textContent = f(total);
}
simulate();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>