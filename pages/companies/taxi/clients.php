<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Clients';
$pageSubtitle = 'Carnet d\'adresses & Clients réguliers';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// ─── ZONES Los Santos (carte image) ──────────────────────────────────────────
$zonesLS = [
    'Centre-Ville'       => ['color'=>'#f59e0b', 'icon'=>'🏙️'],
    'Vinewood'           => ['color'=>'#ef4444', 'icon'=>'⭐'],
    'Rockford Hills'     => ['color'=>'#f97316', 'icon'=>'🏡'],
    'Golf'               => ['color'=>'#22c55e', 'icon'=>'⛳'],
    'Del Perro'          => ['color'=>'#06b6d4', 'icon'=>'🏖️'],
    'Studio'             => ['color'=>'#8b5cf6', 'icon'=>'🎬'],
    'Little Seoul'       => ['color'=>'#ec4899', 'icon'=>'🏮'],
    'Place des Cubes'    => ['color'=>'#eab308', 'icon'=>'🏢'],
    'Marina'             => ['color'=>'#0ea5e9', 'icon'=>'⛵'],
    'Vespucci Canaux'    => ['color'=>'#10b981', 'icon'=>'🛶'],
    'Pete Toraine'       => ['color'=>'#84cc16', 'icon'=>'🌿'],
    'Mirror Park'        => ['color'=>'#a855f7', 'icon'=>'🪞'],
    'Casino'             => ['color'=>'#dc2626', 'icon'=>'🎰'],
    'La Mesa'            => ['color'=>'#7c3aed', 'icon'=>'🏭'],
    'Quartier Familles'  => ['color'=>'#16a34a', 'icon'=>'🏘️'],
    'Quartier Balles'    => ['color'=>'#ca8a04', 'icon'=>'🎾'],
    'Quartier Vagos'     => ['color'=>'#b45309', 'icon'=>'🔶'],
    'La Came'            => ['color'=>'#6d28d9', 'icon'=>'🔮'],
    'Maze Bank'          => ['color'=>'#1d4ed8', 'icon'=>'🏦'],
    'Wardock'            => ['color'=>'#be123c', 'icon'=>'🔒'],
    'Aéroport'           => ['color'=>'#0891b2', 'icon'=>'✈️'],
    'Port'               => ['color'=>'#1e40af', 'icon'=>'🚢'],
    'Dock'               => ['color'=>'#155e75', 'icon'=>'⚓'],
    'Est Los Santos'     => ['color'=>'#9f1239', 'icon'=>'🏗️'],
    'Sandy Shores'       => ['color'=>'#d97706', 'icon'=>'🏜️'],
    'Paleto Bay'         => ['color'=>'#15803d', 'icon'=>'🌲'],
];

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $db->prepare("
                INSERT INTO taxi_clients (nom, telephone, email, adresse_habituelle, type, note)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                trim($_POST['nom']),
                trim($_POST['telephone']??''),
                trim($_POST['email']??''),
                trim($_POST['adresse_habituelle']??''),
                $_POST['type']??'particulier',
                trim($_POST['note']??''),
            ]);
            $msg = 'Client créé avec succès.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'edit') {
        try {
            $db->prepare("
                UPDATE taxi_clients SET nom=?, telephone=?, email=?, adresse_habituelle=?, type=?, note=?
                WHERE id=?
            ")->execute([
                trim($_POST['nom']),
                trim($_POST['telephone']??''),
                trim($_POST['email']??''),
                trim($_POST['adresse_habituelle']??''),
                $_POST['type']??'particulier',
                trim($_POST['note']??''),
                intval($_POST['client_id']),
            ]);
            $msg = 'Client mis à jour.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete' && $isChef) {
        try {
            // Vérifier aucune course liée active
            $check = $db->prepare("SELECT COUNT(*) FROM taxi_courses WHERE client_id=? AND statut NOT IN ('terminee','annulee')");
            $check->execute([intval($_POST['client_id'])]);
            if ($check->fetchColumn() > 0) throw new Exception('Ce client a des courses actives.');
            $db->prepare("DELETE FROM taxi_clients WHERE id=?")->execute([intval($_POST['client_id'])]);
            $msg = 'Client supprimé.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ─── FILTRES ─────────────────────────────────────────────────────────────────
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 16;
$offset  = ($page - 1) * $perPage;
$fType   = $_GET['type'] ?? '';
$search  = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($fType)  { $where[] = 'type=?'; $params[] = $fType; }
if ($search) { $where[] = '(nom LIKE ? OR telephone LIKE ? OR email LIKE ? OR adresse_habituelle LIKE ?)'; $s="%$search%"; $params[]=$s;$params[]=$s;$params[]=$s;$params[]=$s; }
$whereStr = implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) FROM taxi_clients WHERE $whereStr");
$total->execute($params); $total = $total->fetchColumn();
$totalPages = ceil($total / $perPage);

$clients = $db->prepare("
    SELECT c.*,
           COUNT(co.id) as nb_courses,
           SUM(CASE WHEN co.statut='terminee' THEN co.montant_total ELSE 0 END) as ca_total,
           MAX(co.date_demande) as derniere_course,
           AVG(CASE WHEN co.statut='terminee' THEN co.note_client END) as note_moy
    FROM taxi_clients c
    LEFT JOIN taxi_courses co ON c.id=co.client_id
    WHERE $whereStr
    GROUP BY c.id
    ORDER BY c.total_courses DESC, c.nom ASC
    LIMIT $perPage OFFSET $offset
");
$clients->execute($params); $clients = $clients->fetchAll();

// Stats
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(type='particulier') as particuliers,
        SUM(type='entreprise') as entreprises,
        SUM(type='vip') as vips,
        SUM(total_courses) as total_courses_tous
    FROM taxi_clients
")->fetch();

// Pour modal édition : client sélectionné via GET
$editClient = null;
if (!empty($_GET['edit'])) {
    $editClient = $db->prepare("SELECT * FROM taxi_clients WHERE id=?")->execute([intval($_GET['edit'])]);
    $editClient = $db->query("SELECT * FROM taxi_clients WHERE id=".intval($_GET['edit']))->fetch();
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ─ Client Cards ─ */
.client-card {
    background:rgba(13,17,23,.8);
    border:1px solid var(--border);
    border-radius:13px;
    overflow:hidden;
    transition:all .22s;
    cursor:pointer;
}
.client-card:hover { border-color:rgba(245,158,11,.38); box-shadow:0 4px 20px rgba(245,158,11,.07); transform:translateY(-1px); }
.client-card.vip     { border-top:3px solid #f59e0b; }
.client-card.entreprise { border-top:3px solid #60a5fa; }
.client-card.particulier{ border-top:3px solid #6b7280; }

.cc-header {
    padding:.9rem 1.1rem;
    display:flex; align-items:center; gap:.8rem;
    border-bottom:1px solid rgba(245,158,11,.07);
}
.cc-avatar {
    width:42px; height:42px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:1rem; font-weight:800;
}
.cc-avatar.vip        { background:linear-gradient(135deg,#f59e0b,#b45309); color:#000; }
.cc-avatar.entreprise { background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; }
.cc-avatar.particulier{ background:linear-gradient(135deg,#6b7280,#374151); color:#fff; }

.cc-info { flex:1; min-width:0; }
.cc-name { font-size:.9rem; font-weight:800; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cc-type { display:flex; align-items:center; gap:.3rem; margin-top:.18rem; }

.cc-stats {
    display:grid; grid-template-columns:repeat(3,1fr); gap:0;
    border-bottom:1px solid rgba(245,158,11,.07);
}
.cc-stat {
    padding:.6rem .5rem; text-align:center;
    border-right:1px solid rgba(245,158,11,.07);
}
.cc-stat:last-child { border-right:none; }
.cc-stat-val   { font-size:.95rem; font-weight:800; color:var(--text); }
.cc-stat-label { font-size:.62rem; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; }

.cc-contact {
    padding:.65rem 1.1rem;
    font-size:.75rem; color:#94a3b8;
    display:flex; flex-direction:column; gap:.2rem;
}
.cc-contact i { color:var(--col); font-size:.68rem; margin-right:.3rem; }

.cc-footer {
    padding:.6rem 1.1rem;
    border-top:1px solid rgba(245,158,11,.07);
    display:flex; align-items:center; justify-content:space-between; gap:.4rem;
}

/* ─ Type badges ─ */
.b-particulier{ background:rgba(107,114,128,.12); color:#9ca3af; border:1px solid rgba(107,114,128,.25); }
.b-entreprise { background:rgba(59,130,246,.1);   color:#60a5fa; border:1px solid rgba(59,130,246,.25); }
.b-vip        { background:rgba(245,158,11,.12);  color:var(--col-lt); border:1px solid rgba(245,158,11,.28); }

/* ─ Dernière course ─ */
.last-course-badge {
    font-size:.68rem; color:var(--muted);
    display:flex; align-items:center; gap:.25rem;
}

/* ─ Note étoiles ─ */
.rating-stars { color:var(--col); font-size:.72rem; letter-spacing:.05rem; }

/* ─ Zone pills ─ */
.zone-pill {
    display:inline-flex; align-items:center; gap:.28rem;
    padding:.18rem .55rem; border-radius:20px;
    font-size:.68rem; font-weight:600;
    border:1px solid rgba(255,255,255,.12);
    margin:.1rem;
}

/* ─ Historique courses ─ */
.course-mini-row {
    padding:.55rem .75rem;
    border-bottom:1px solid rgba(245,158,11,.06);
    display:flex; align-items:center; gap:.65rem;
    font-size:.78rem;
}
.course-mini-row:last-child { border-bottom:none; }

/* ─ Carte client détail ─ */
.detail-section {
    background:rgba(245,158,11,.04);
    border:1px solid rgba(245,158,11,.12);
    border-radius:10px;
    padding:.85rem 1rem;
    margin-bottom:.85rem;
}
.detail-section-title {
    font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px;
    color:var(--col); margin-bottom:.65rem; display:flex; align-items:center; gap:.4rem;
}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid-4" style="margin-bottom:1.25rem;">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-address-book"></i></div>
        <div class="stat-label">Total Clients</div>
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-sub"><?php echo $stats['total_courses_tous']; ?> courses enregistrées</div>
    </div>
    <div class="stat-card" style="border-color:rgba(245,158,11,.2);">
        <div class="stat-icon" style="background:rgba(245,158,11,.12);color:var(--col-lt);"><i class="fas fa-star"></i></div>
        <div class="stat-label">VIP</div>
        <div class="stat-value"><?php echo $stats['vips']; ?></div>
        <div class="stat-sub">clients prioritaires</div>
    </div>
    <div class="stat-card" style="border-color:rgba(59,130,246,.15);">
        <div class="stat-icon" style="background:rgba(59,130,246,.12);color:#60a5fa;"><i class="fas fa-building"></i></div>
        <div class="stat-label">Entreprises</div>
        <div class="stat-value"><?php echo $stats['entreprises']; ?></div>
        <div class="stat-sub">comptes corporate</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(107,114,128,.12);color:#9ca3af;"><i class="fas fa-user"></i></div>
        <div class="stat-label">Particuliers</div>
        <div class="stat-value"><?php echo $stats['particuliers']; ?></div>
        <div class="stat-sub">clients individuels</div>
    </div>
</div>

<!-- Toolbar -->
<div class="card" style="padding:.9rem 1.15rem;margin-bottom:1.1rem;">
    <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:.65rem;flex:1;flex-wrap:wrap;">
            <div class="search-wrap" style="min-width:240px;flex:1;">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Nom, téléphone, adresse..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <select name="type" class="form-select" style="width:auto;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <option value="particulier" <?php echo $fType==='particulier'?'selected':''; ?>>👤 Particulier</option>
                <option value="entreprise"  <?php echo $fType==='entreprise'?'selected':''; ?>>🏢 Entreprise</option>
                <option value="vip"         <?php echo $fType==='vip'?'selected':''; ?>>⭐ VIP</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
            <?php if ($search||$fType): ?><a href="clients.php" class="btn btn-sm" style="color:#f87171;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);"><i class="fas fa-times"></i></a><?php endif; ?>
        </form>
        <button onclick="Taxi.openModal('modal-create-client')" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Nouveau Client
        </button>
    </div>
</div>

<!-- Grille clients -->
<?php if (empty($clients)): ?>
<div class="card" style="text-align:center;padding:3rem;">
    <i class="fas fa-address-book" style="font-size:2.5rem;color:var(--muted);display:block;margin-bottom:.75rem;"></i>
    <div style="color:var(--muted);">Aucun client trouvé</div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1rem;">
    <?php foreach ($clients as $cl):
        $typIcons = ['particulier'=>'👤','entreprise'=>'🏢','vip'=>'⭐'];
    ?>
    <div class="client-card <?php echo $cl['type']; ?>" onclick="Taxi.openModal('modal-detail-<?php echo $cl['id']; ?>')">
        <div class="cc-header">
            <div class="cc-avatar <?php echo $cl['type']; ?>">
                <?php echo $cl['type']==='entreprise' ? '<i class="fas fa-building" style="font-size:.9rem;"></i>' : strtoupper(substr($cl['nom'],0,1)); ?>
            </div>
            <div class="cc-info">
                <div class="cc-name"><?php echo htmlspecialchars($cl['nom']); ?></div>
                <div class="cc-type">
                    <span class="badge b-<?php echo $cl['type']; ?>" style="font-size:.65rem;">
                        <?php echo $typIcons[$cl['type']]??''; ?> <?php echo ucfirst($cl['type']); ?>
                    </span>
                    <?php if ($cl['note_moy']): ?>
                    <span class="rating-stars"><?php echo str_repeat('★',round($cl['note_moy'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="cc-stats">
            <div class="cc-stat">
                <div class="cc-stat-val"><?php echo $cl['nb_courses']??0; ?></div>
                <div class="cc-stat-label">Courses</div>
            </div>
            <div class="cc-stat">
                <div class="cc-stat-val" style="color:var(--col-lt);">$<?php echo number_format($cl['ca_total']??0,0); ?></div>
                <div class="cc-stat-label">CA Total</div>
            </div>
            <div class="cc-stat">
                <div class="cc-stat-val" style="font-size:.78rem;color:#94a3b8;">
                    <?php echo $cl['derniere_course'] ? date('d/m/y',strtotime($cl['derniere_course'])) : '—'; ?>
                </div>
                <div class="cc-stat-label">Dernière</div>
            </div>
        </div>

        <div class="cc-contact">
            <?php if ($cl['telephone']): ?><span><i class="fas fa-phone"></i><?php echo htmlspecialchars($cl['telephone']); ?></span><?php endif; ?>
            <?php if ($cl['email']): ?><span><i class="fas fa-envelope"></i><?php echo htmlspecialchars($cl['email']); ?></span><?php endif; ?>
            <?php if ($cl['adresse_habituelle']): ?><span><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($cl['adresse_habituelle']); ?></span><?php endif; ?>
        </div>

        <?php if ($cl['note']): ?>
        <div style="padding:.4rem 1.1rem .5rem;font-size:.73rem;color:var(--muted);font-style:italic;border-top:1px solid rgba(245,158,11,.06);">
            <i class="fas fa-sticky-note" style="color:var(--col);"></i> <?php echo htmlspecialchars(substr($cl['note'],0,80)).(strlen($cl['note'])>80?'…':''); ?>
        </div>
        <?php endif; ?>

        <div class="cc-footer" onclick="event.stopPropagation()">
            <button onclick="Taxi.openModal('modal-detail-<?php echo $cl['id']; ?>')" class="btn btn-secondary btn-xs">
                <i class="fas fa-eye"></i> Voir
            </button>
            <div style="display:flex;gap:.35rem;">
                <button onclick="Taxi.openModal('modal-edit-<?php echo $cl['id']; ?>')" class="btn btn-warning btn-xs">
                    <i class="fas fa-pen"></i>
                </button>
                <!-- Nouvelle course rapide -->
                <a href="dispatch.php?client=<?php echo $cl['id']; ?>" class="btn btn-primary btn-xs">
                    <i class="fas fa-taxi"></i>
                </a>
                <?php if ($isChef): ?>
                <form method="POST" style="display:inline;" onclick="event.stopPropagation()">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs" data-confirm="Supprimer ce client ?">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── MODAL DÉTAIL CLIENT ─── -->
    <div id="modal-detail-<?php echo $cl['id']; ?>" class="modal-overlay hidden">
        <div class="modal modal-lg">
            <div class="modal-header">
                <h2>
                    <span style="font-size:1.2rem;"><?php echo $typIcons[$cl['type']]??''; ?></span>
                    <?php echo htmlspecialchars($cl['nom']); ?>
                    <span class="badge b-<?php echo $cl['type']; ?>" style="font-size:.7rem;"><?php echo ucfirst($cl['type']); ?></span>
                </h2>
                <button class="modal-close" onclick="Taxi.closeModal('modal-detail-<?php echo $cl['id']; ?>')">×</button>
            </div>
            <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                <div>
                    <!-- Infos contact -->
                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-address-card"></i> Coordonnées</div>
                        <?php if ($cl['telephone']): ?>
                        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;font-size:.84rem;">
                            <i class="fas fa-phone" style="color:var(--col);width:16px;"></i>
                            <span><?php echo htmlspecialchars($cl['telephone']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($cl['email']): ?>
                        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;font-size:.84rem;">
                            <i class="fas fa-envelope" style="color:var(--col);width:16px;"></i>
                            <span><?php echo htmlspecialchars($cl['email']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($cl['adresse_habituelle']): ?>
                        <div style="display:flex;align-items:flex-start;gap:.5rem;font-size:.84rem;">
                            <i class="fas fa-map-marker-alt" style="color:var(--col);width:16px;margin-top:.15rem;"></i>
                            <span><?php echo htmlspecialchars($cl['adresse_habituelle']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!$cl['telephone'] && !$cl['email'] && !$cl['adresse_habituelle']): ?>
                        <div style="color:var(--muted);font-size:.8rem;font-style:italic;">Aucune coordonnée renseignée</div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats -->
                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-chart-pie"></i> Statistiques</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem;font-size:.83rem;">
                            <div>
                                <div style="color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Courses</div>
                                <div style="font-weight:800;font-size:1.1rem;"><?php echo $cl['nb_courses']??0; ?></div>
                            </div>
                            <div>
                                <div style="color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">CA Total</div>
                                <div style="font-weight:800;font-size:1.1rem;color:var(--col-lt);">$<?php echo number_format($cl['ca_total']??0,2); ?></div>
                            </div>
                            <?php if ($cl['note_moy']): ?>
                            <div>
                                <div style="color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Note Moyenne</div>
                                <div style="font-weight:700;"><span class="rating-stars"><?php echo str_repeat('★',round($cl['note_moy'])); ?></span> <?php echo number_format($cl['note_moy'],1); ?>/5</div>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div style="color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.15rem;">Client depuis</div>
                                <div><?php echo date('d/m/Y',strtotime($cl['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($cl['note']): ?>
                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-sticky-note"></i> Notes Internes</div>
                        <div style="font-size:.83rem;color:#94a3b8;font-style:italic;line-height:1.5;"><?php echo nl2br(htmlspecialchars($cl['note'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div>
                    <!-- Dernières courses -->
                    <div class="detail-section" style="padding:0;overflow:hidden;">
                        <div class="detail-section-title" style="padding:.75rem 1rem .5rem;">
                            <i class="fas fa-history"></i> Dernières Courses
                        </div>
                        <?php
                        $histoCourses = $db->prepare("
                            SELECT c.*, u.username as ch_nom
                            FROM taxi_courses c
                            LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
                            LEFT JOIN users u ON tc.user_id=u.id
                            WHERE c.client_id=?
                            ORDER BY c.date_demande DESC LIMIT 8
                        ");
                        $histoCourses->execute([$cl['id']]);
                        $histoCourses = $histoCourses->fetchAll();
                        ?>
                        <?php if (empty($histoCourses)): ?>
                        <div style="padding:1rem;text-align:center;color:var(--muted);font-size:.8rem;font-style:italic;">Aucune course enregistrée</div>
                        <?php endif; ?>
                        <?php foreach ($histoCourses as $hc): ?>
                        <div class="course-mini-row">
                            <div style="font-size:.7rem;color:var(--muted);font-family:monospace;min-width:80px;"><?php echo date('d/m/y',strtotime($hc['date_demande'])); ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:.77rem;color:#cbd5e1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($hc['depart_adresse']); ?> → <?php echo htmlspecialchars($hc['arrivee_adresse']); ?>
                                </div>
                                <?php if ($hc['ch_nom']): ?><div style="font-size:.68rem;color:var(--muted);">🚕 <?php echo htmlspecialchars($hc['ch_nom']); ?></div><?php endif; ?>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <?php if ($hc['montant_total']): ?><div style="font-size:.78rem;font-weight:700;color:var(--col-lt);">$<?php echo number_format($hc['montant_total'],2); ?></div><?php endif; ?>
                                <span class="badge b-<?php echo $hc['statut']; ?>" style="font-size:.58rem;"><?php echo ucfirst(str_replace('_',' ',$hc['statut'])); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-detail-<?php echo $cl['id']; ?>');Taxi.openModal('modal-edit-<?php echo $cl['id']; ?>')" class="btn btn-secondary">
                    <i class="fas fa-pen"></i> Modifier
                </button>
                <a href="dispatch.php?client=<?php echo $cl['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-taxi"></i> Nouvelle Course
                </a>
            </div>
        </div>
    </div>

    <!-- ─── MODAL ÉDITER CLIENT ─── -->
    <div id="modal-edit-<?php echo $cl['id']; ?>" class="modal-overlay hidden">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-pen"></i> Modifier — <?php echo htmlspecialchars($cl['nom']); ?></h2>
                <button class="modal-close" onclick="Taxi.closeModal('modal-edit-<?php echo $cl['id']; ?>')">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                <div class="modal-body">
                    <div class="form-grid" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Nom / Raison Sociale *</label>
                            <input type="text" name="nom" class="form-input" value="<?php echo htmlspecialchars($cl['nom']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="particulier" <?php echo $cl['type']==='particulier'?'selected':''; ?>>👤 Particulier</option>
                                <option value="entreprise"  <?php echo $cl['type']==='entreprise'?'selected':''; ?>>🏢 Entreprise</option>
                                <option value="vip"         <?php echo $cl['type']==='vip'?'selected':''; ?>>⭐ VIP</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" name="telephone" class="form-input" value="<?php echo htmlspecialchars($cl['telephone']??''); ?>" placeholder="555-XXXX">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($cl['email']??''); ?>">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label">Adresse Habituelle</label>
                        <input type="text" name="adresse_habituelle" class="form-input" value="<?php echo htmlspecialchars($cl['adresse_habituelle']??''); ?>" placeholder="Adresse par défaut...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes Internes</label>
                        <textarea name="note" class="form-textarea" placeholder="Notes sur le client, préférences, instructions..."><?php echo htmlspecialchars($cl['note']??''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="Taxi.closeModal('modal-edit-<?php echo $cl['id']; ?>')" class="btn btn-secondary">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-top:1.25rem;">
    <?php if ($page>1): ?><a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
    <?php for ($i=max(1,$page-3); $i<=min($totalPages,$page+3); $i++): ?>
    <a href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>" class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($page<$totalPages): ?><a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ MODAL NOUVEAU CLIENT ═══════════════════════════════════════ -->
<div id="modal-create-client" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Nouveau Client</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-create-client')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-grid" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Nom / Raison Sociale *</label>
                        <input type="text" name="nom" class="form-input" placeholder="Nom complet ou société" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="particulier">👤 Particulier</option>
                            <option value="entreprise">🏢 Entreprise</option>
                            <option value="vip">⭐ VIP</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="text" name="telephone" class="form-input" placeholder="555-XXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" placeholder="email@exemple.com">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="form-label">Adresse Habituelle</label>
                    <input type="text" name="adresse_habituelle" class="form-input" placeholder="Zone / Adresse par défaut...">
                    <!-- Zones rapides -->
                    <div style="margin-top:.5rem;display:flex;flex-wrap:wrap;gap:.25rem;">
                        <?php foreach ($zonesLS as $zone => $zdata): ?>
                        <span class="zone-pill" style="background:<?php echo $zdata['color']; ?>22;border-color:<?php echo $zdata['color']; ?>44;color:<?php echo $zdata['color']; ?>;cursor:pointer;"
                              onclick="document.querySelector('#modal-create-client [name=adresse_habituelle]').value='<?php echo addslashes($zone); ?>'">
                            <?php echo $zdata['icon']; ?> <?php echo $zone; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes Internes</label>
                    <textarea name="note" class="form-textarea" placeholder="Préférences, instructions particulières, historique..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-create-client')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Créer le client</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>