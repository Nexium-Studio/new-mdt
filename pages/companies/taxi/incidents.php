<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle    = 'Incidents';
$pageSubtitle = 'Registre des incidents & Signalements';
$db  = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Créer incident indépendant (sans course spécifique)
    if ($action === 'create_incident') {
        try {
            $courseId = intval($_POST['course_id'] ?? 0) ?: null;

            // Si course saisie manuellement par référence
            if (!$courseId && !empty($_POST['course_ref'])) {
                $cRef = $db->prepare("SELECT id FROM taxi_courses WHERE reference=?");
                $cRef->execute([trim($_POST['course_ref'])]);
                $courseId = $cRef->fetchColumn() ?: null;
                if (!$courseId) throw new Exception('Référence course introuvable : '.trim($_POST['course_ref']));
            }

            // Si toujours pas de course, créer un incident "sans course"
            // On a besoin d'une course valide par contrainte FK → si pas de course, on passe NULL
            if (!$courseId) {
                // Créer une entrée "sans course" si besoin en récupérant la dernière course du chauffeur
                // Sinon on refuse
                throw new Exception('Veuillez renseigner la référence de la course concernée.');
            }

            $db->prepare("
                INSERT INTO taxi_incidents (course_id, type, description, gravite, action_prise, rapporte_by)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $courseId,
                $_POST['type'],
                trim($_POST['description']),
                $_POST['gravite'],
                trim($_POST['action_prise']??''),
                $uid,
            ]);
            $msg = 'Incident enregistré.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // Ajouter action corrective
    if ($action === 'add_action' && $isChef) {
        try {
            $db->prepare("UPDATE taxi_incidents SET action_prise=? WHERE id=?")
               ->execute([trim($_POST['action_prise']), intval($_POST['incident_id'])]);
            $msg = 'Action enregistrée.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    // Supprimer
    if ($action === 'delete' && $isChef) {
        try {
            $db->prepare("DELETE FROM taxi_incidents WHERE id=?")->execute([intval($_POST['incident_id'])]);
            $msg = 'Incident supprimé.'; $msgType = 'success';
        } catch (Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
}

// ─── FILTRES ─────────────────────────────────────────────────────────────────
$page    = max(1, intval($_GET['page']??1));
$perPage = 15;
$offset  = ($page-1) * $perPage;
$fType   = $_GET['type']??'';
$fGrav   = $_GET['gravite']??'';
$fAction = $_GET['action']??''; // '' | 'oui' | 'non'
$fCh     = $_GET['chauffeur']??'';
$search  = trim($_GET['q']??'');

$where  = ['1=1'];
$params = [];
if ($fType) { $where[] = 'i.type=?'; $params[] = $fType; }
if ($fGrav) { $where[] = 'i.gravite=?'; $params[] = $fGrav; }
if ($fAction === 'oui') { $where[] = 'i.action_prise IS NOT NULL AND i.action_prise != ""'; }
if ($fAction === 'non') { $where[] = '(i.action_prise IS NULL OR i.action_prise = "")'; }
if ($fCh)   { $where[] = 'tc.id=?'; $params[] = intval($fCh); }
if ($search){ $where[] = '(i.description LIKE ? OR c.reference LIKE ? OR c.client_nom LIKE ? OR uch.username LIKE ?)';
              $s="%$search%"; $params[]=$s;$params[]=$s;$params[]=$s;$params[]=$s; }
$whereStr = implode(' AND ',$where);

$total = $db->prepare("
    SELECT COUNT(*) FROM taxi_incidents i
    JOIN taxi_courses c ON i.course_id=c.id
    LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    LEFT JOIN users uch ON tc.user_id=uch.id
    WHERE $whereStr
");
$total->execute($params); $total = $total->fetchColumn();
$totalPages = ceil($total/$perPage);

$incidents = $db->prepare("
    SELECT i.*,
           c.reference, c.client_nom, c.depart_adresse, c.arrivee_adresse, c.statut as course_statut,
           tc.numero_badge, uch.username as chauffeur_nom,
           urp.username as rapporte_par
    FROM taxi_incidents i
    JOIN taxi_courses c ON i.course_id=c.id
    LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    LEFT JOIN users uch ON tc.user_id=uch.id
    LEFT JOIN users urp ON i.rapporte_by=urp.id
    WHERE $whereStr
    ORDER BY i.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$incidents->execute($params); $incidents = $incidents->fetchAll();

// Stats
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(gravite='grave') as graves,
        SUM(gravite='moyenne') as moyennes,
        SUM(gravite='mineure') as mineures,
        SUM(type='accident') as accidents,
        SUM(type='comportement') as comportements,
        SUM(type='materiel') as materiels,
        SUM(type='autre') as autres,
        SUM(action_prise IS NULL OR action_prise='') as sans_action,
        SUM(action_prise IS NOT NULL AND action_prise!='') as avec_action,
        SUM(MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())) as ce_mois
    FROM taxi_incidents
")->fetch();

// Tendance 30 jours
$tendance = $db->query("
    SELECT DATE(created_at) as jour, COUNT(*) as nb, gravite
    FROM taxi_incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at), gravite
    ORDER BY jour DESC
")->fetchAll();

// Top chauffeurs avec incidents
$topIncidents = $db->query("
    SELECT uch.username, tc.numero_badge,
           COUNT(*) as nb_incidents,
           SUM(i.gravite='grave') as graves
    FROM taxi_incidents i
    JOIN taxi_courses c ON i.course_id=c.id
    JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    JOIN users uch ON tc.user_id=uch.id
    GROUP BY tc.id, uch.username, tc.numero_badge
    ORDER BY graves DESC, nb_incidents DESC
    LIMIT 6
")->fetchAll();

// Chauffeurs pour filtre
$chauffeursList = $db->query("
    SELECT tc.id, u.username, tc.numero_badge FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id WHERE tc.actif=1 ORDER BY u.username
")->fetchAll();

// Courses récentes pour sélection
$coursesRecentes = $db->query("
    SELECT id, reference, client_nom, DATE(date_demande) as date_course
    FROM taxi_courses
    ORDER BY date_demande DESC LIMIT 50
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* ─ Gravité colors ─ */
.g-grave   { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.3); }
.g-moyenne { background:rgba(245,158,11,.1);  color:var(--col-lt); border:1px solid rgba(245,158,11,.28); }
.g-mineure { background:rgba(34,197,94,.08);  color:#4ade80; border:1px solid rgba(34,197,94,.22); }

/* ─ Type icons/colors ─ */
.t-accident      { color:#f87171; }
.t-comportement  { color:var(--col-lt); }
.t-materiel      { color:#60a5fa; }
.t-autre         { color:#94a3b8; }

/* ─ Incident row ─ */
.incident-row td { vertical-align:top !important; padding:1rem .9rem !important; }

.incident-card {
    background:rgba(13,17,23,.8);
    border:1px solid var(--border);
    border-radius:13px;
    overflow:hidden;
    transition:all .22s;
}
.incident-card.grave   { border-left:4px solid #ef4444; }
.incident-card.moyenne { border-left:4px solid #f59e0b; }
.incident-card.mineure { border-left:4px solid #4ade80; }
.incident-card:hover { border-color:rgba(245,158,11,.35); box-shadow:0 4px 20px rgba(0,0,0,.3); }

.ic-header {
    padding:.9rem 1.15rem;
    display:flex; align-items:center; justify-content:space-between; gap:.75rem;
    border-bottom:1px solid rgba(245,158,11,.07);
}
.ic-body { padding:.9rem 1.15rem; }
.ic-footer {
    padding:.7rem 1.15rem;
    border-top:1px solid rgba(245,158,11,.07);
    display:flex; align-items:center; justify-content:space-between; gap:.5rem;
    background:rgba(0,0,0,.15);
}

.action-needed {
    display:inline-flex; align-items:center; gap:.3rem;
    font-size:.7rem; color:#f87171; font-weight:700;
    animation:blink-text 1.5s infinite;
}
@keyframes blink-text { 0%,100%{opacity:1;} 50%{opacity:.4;} }

.action-done {
    display:inline-flex; align-items:center; gap:.3rem;
    font-size:.7rem; color:#4ade80; font-weight:600;
}

/* ─ Stats bars ─ */
.stat-bar-row {
    display:flex; align-items:center; gap:.65rem; margin-bottom:.5rem;
}
.stat-bar-label { font-size:.75rem; color:#94a3b8; min-width:90px; display:flex; align-items:center; gap:.4rem; }
.stat-bar-bg { flex:1; height:7px; background:rgba(255,255,255,.05); border-radius:4px; overflow:hidden; }
.stat-bar-fill { height:100%; border-radius:4px; transition:width .6s; }
.stat-bar-val { font-size:.75rem; font-weight:700; color:var(--text); min-width:28px; text-align:right; }

/* ─ Alert prioritaire ─ */
.alert-grave-banner {
    background:rgba(239,68,68,.1);
    border:1px solid rgba(239,68,68,.3);
    border-radius:11px;
    padding:.85rem 1.1rem;
    margin-bottom:1.1rem;
    display:flex; align-items:center; gap:.75rem;
    color:#f87171; font-size:.84rem; font-weight:600;
}

/* ─ Timeline ─ */
.timeline { position:relative; padding-left:1.5rem; }
.timeline::before { content:''; position:absolute; left:7px; top:0; bottom:0; width:1px; background:rgba(245,158,11,.15); }
.timeline-item { position:relative; padding-bottom:1rem; }
.timeline-item::before {
    content:''; position:absolute; left:-1.5rem; top:.25rem;
    width:9px; height:9px; border-radius:50%;
    background:var(--col); border:1px solid var(--col-dk);
}
.timeline-item.grave::before   { background:#ef4444; border-color:#dc2626; }
.timeline-item.moyenne::before { background:var(--col); }
.timeline-item.mineure::before { background:#4ade80; border-color:#16a34a; }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Alerte incidents graves sans action -->
<?php
$gravSansAction = $db->query("SELECT COUNT(*) FROM taxi_incidents WHERE gravite='grave' AND (action_prise IS NULL OR action_prise='') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
if ($gravSansAction > 0):
?>
<div class="alert-grave-banner">
    <i class="fas fa-exclamation-circle" style="font-size:1.3rem;flex-shrink:0;"></i>
    <div>
        <strong><?php echo $gravSansAction; ?> incident(s) grave(s)</strong> des 7 derniers jours sans action corrective.
        <a href="?gravite=grave&action=non" style="color:#f87171;text-decoration:underline;margin-left:.3rem;">Voir →</a>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.4rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(239,68,68,.12);color:#f87171;"><i class="fas fa-flag"></i></div>
        <div class="stat-label">Total Incidents</div>
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-sub"><?php echo $stats['ce_mois']; ?> ce mois</div>
    </div>
    <div class="stat-card" style="border-color:rgba(239,68,68,.2);">
        <div class="stat-icon" style="background:rgba(239,68,68,.14);color:#f87171;"><i class="fas fa-radiation"></i></div>
        <div class="stat-label">Graves</div>
        <div class="stat-value" style="color:#f87171;"><?php echo $stats['graves']; ?></div>
        <div class="stat-sub stat-dn"><?php echo $stats['sans_action']; ?> sans action</div>
    </div>
    <div class="stat-card" style="border-color:rgba(245,158,11,.2);">
        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-label">Moyens</div>
        <div class="stat-value"><?php echo $stats['moyennes']; ?></div>
        <div class="stat-sub">nécessitent suivi</div>
    </div>
    <div class="stat-card" style="border-color:rgba(34,197,94,.15);">
        <div class="stat-icon" style="background:rgba(34,197,94,.12);color:#4ade80;"><i class="fas fa-check-circle"></i></div>
        <div class="stat-label">Actions Prises</div>
        <div class="stat-value" style="color:#4ade80;"><?php echo $stats['avec_action']; ?></div>
        <div class="stat-sub"><?php echo $stats['total'] > 0 ? round($stats['avec_action']/$stats['total']*100) : 0; ?>% traités</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:1.25rem;align-items:start;">

    <!-- Colonne principale -->
    <div>
        <!-- Filtres + toolbar -->
        <div class="card" style="padding:.9rem 1.15rem;margin-bottom:1.1rem;">
            <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;">
                <form method="GET" style="display:flex;gap:.65rem;flex:1;flex-wrap:wrap;">
                    <div class="search-wrap" style="min-width:200px;flex:1;">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" placeholder="Description, réf, client, chauffeur..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <select name="type" class="form-select" style="width:auto;" onchange="this.form.submit()">
                        <option value="">Tous types</option>
                        <option value="accident"     <?php echo $fType==='accident'?'selected':''; ?>>💥 Accident</option>
                        <option value="comportement" <?php echo $fType==='comportement'?'selected':''; ?>>😡 Comportement</option>
                        <option value="materiel"     <?php echo $fType==='materiel'?'selected':''; ?>>🔧 Matériel</option>
                        <option value="autre"        <?php echo $fType==='autre'?'selected':''; ?>>📝 Autre</option>
                    </select>
                    <select name="gravite" class="form-select" style="width:auto;" onchange="this.form.submit()">
                        <option value="">Toutes gravités</option>
                        <option value="grave"   <?php echo $fGrav==='grave'?'selected':''; ?>>🔴 Grave</option>
                        <option value="moyenne" <?php echo $fGrav==='moyenne'?'selected':''; ?>>🟡 Moyenne</option>
                        <option value="mineure" <?php echo $fGrav==='mineure'?'selected':''; ?>>🟢 Mineure</option>
                    </select>
                    <select name="action" class="form-select" style="width:auto;" onchange="this.form.submit()">
                        <option value="">Action ?</option>
                        <option value="non" <?php echo $fAction==='non'?'selected':''; ?>>⚠️ Sans action</option>
                        <option value="oui" <?php echo $fAction==='oui'?'selected':''; ?>>✅ Avec action</option>
                    </select>
                    <select name="chauffeur" class="form-select" style="width:auto;" onchange="this.form.submit()">
                        <option value="">Tous chauffeurs</option>
                        <?php foreach ($chauffeursList as $ch): ?>
                        <option value="<?php echo $ch['id']; ?>" <?php echo $fCh==$ch['id']?'selected':''; ?>><?php echo htmlspecialchars($ch['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i></button>
                    <?php if ($search||$fType||$fGrav||$fAction||$fCh): ?>
                    <a href="incidents.php" class="btn btn-sm" style="color:#f87171;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
                <button onclick="Taxi.openModal('modal-new-incident')" class="btn btn-danger btn-sm">
                    <i class="fas fa-flag"></i> Signaler
                </button>
            </div>
        </div>

        <!-- Liste incidents -->
        <?php if (empty($incidents)): ?>
        <div class="card" style="text-align:center;padding:3rem;">
            <i class="fas fa-check-circle" style="font-size:2.5rem;color:#4ade80;display:block;margin-bottom:.75rem;"></i>
            <div style="color:var(--muted);">Aucun incident trouvé</div>
        </div>
        <?php else: ?>

        <div style="display:flex;flex-direction:column;gap:.85rem;">
            <?php foreach ($incidents as $inc):
                $typeIcons = ['accident'=>'💥','comportement'=>'😡','materiel'=>'🔧','autre'=>'📝'];
                $typeColors= ['accident'=>'#f87171','comportement'=>'#fbbf24','materiel'=>'#60a5fa','autre'=>'#9ca3af'];
                $hasAction = !empty($inc['action_prise']);
            ?>
            <div class="incident-card <?php echo $inc['gravite']; ?>">
                <div class="ic-header">
                    <div style="display:flex;align-items:center;gap:.75rem;flex:1;min-width:0;">
                        <div style="width:38px;height:38px;border-radius:10px;flex-shrink:0;
                             background:<?php echo $typeColors[$inc['type']]??'#94a3b8'; ?>18;
                             border:1px solid <?php echo $typeColors[$inc['type']]??'#94a3b8'; ?>30;
                             display:flex;align-items:center;justify-content:center;font-size:1.15rem;">
                            <?php echo $typeIcons[$inc['type']]??'📝'; ?>
                        </div>
                        <div style="min-width:0;">
                            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                                <span style="font-size:.9rem;font-weight:800;color:var(--text);">
                                    <?php echo ucfirst($inc['type']); ?>
                                </span>
                                <span class="badge g-<?php echo $inc['gravite']; ?>" style="font-size:.65rem;">
                                    <?php echo ['grave'=>'🔴','moyenne'=>'🟡','mineure'=>'🟢'][$inc['gravite']]; ?>
                                    <?php echo ucfirst($inc['gravite']); ?>
                                </span>
                                <?php if ($hasAction): ?>
                                <span class="action-done"><i class="fas fa-check-circle"></i> Action prise</span>
                                <?php elseif ($inc['gravite'] !== 'mineure'): ?>
                                <span class="action-needed"><i class="fas fa-exclamation-triangle"></i> Action requise</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:.1rem;">
                                <?php echo date('d/m/Y à H:i',strtotime($inc['created_at'])); ?>
                                · Signalé par <strong style="color:#94a3b8;"><?php echo htmlspecialchars($inc['rapporte_par']??'—'); ?></strong>
                            </div>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:.72rem;font-family:monospace;color:var(--muted);"><?php echo htmlspecialchars($inc['reference']); ?></div>
                        <?php if ($inc['chauffeur_nom']): ?>
                        <div style="font-size:.75rem;color:var(--col-lt);font-weight:600;margin-top:.15rem;">
                            🚕 <?php echo htmlspecialchars($inc['chauffeur_nom']); ?> #<?php echo htmlspecialchars($inc['numero_badge']??'—'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ic-body">
                    <!-- Trajet concerné -->
                    <div style="font-size:.78rem;color:#94a3b8;margin-bottom:.65rem;">
                        <i class="fas fa-map-pin" style="color:var(--col);font-size:.65rem;"></i>
                        <?php echo htmlspecialchars($inc['depart_adresse']); ?>
                        <span style="color:var(--muted);"> → </span>
                        <?php echo htmlspecialchars($inc['arrivee_adresse']); ?>
                        · Client : <strong style="color:#cbd5e1;"><?php echo htmlspecialchars($inc['client_nom']); ?></strong>
                    </div>

                    <!-- Description -->
                    <div style="background:rgba(239,68,68,.04);border:1px solid rgba(239,68,68,.12);border-radius:8px;padding:.75rem .9rem;margin-bottom:.5rem;">
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#f87171;margin-bottom:.3rem;">
                            <i class="fas fa-file-alt"></i> Description
                        </div>
                        <div style="font-size:.83rem;color:#cbd5e1;line-height:1.55;"><?php echo nl2br(htmlspecialchars($inc['description'])); ?></div>
                    </div>

                    <!-- Action corrective -->
                    <?php if ($hasAction): ?>
                    <div style="background:rgba(34,197,94,.04);border:1px solid rgba(34,197,94,.15);border-radius:8px;padding:.75rem .9rem;">
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#4ade80;margin-bottom:.3rem;">
                            <i class="fas fa-check-circle"></i> Action Corrective
                        </div>
                        <div style="font-size:.83rem;color:#86efac;line-height:1.55;"><?php echo nl2br(htmlspecialchars($inc['action_prise'])); ?></div>
                    </div>
                    <?php elseif ($isChef): ?>
                    <button onclick="Taxi.openModal('modal-action-<?php echo $inc['id']; ?>')" class="btn btn-success btn-xs" style="margin-top:.3rem;">
                        <i class="fas fa-plus"></i> Ajouter une action corrective
                    </button>
                    <?php else: ?>
                    <div style="font-size:.75rem;color:#f87171;font-style:italic;margin-top:.3rem;">
                        <i class="fas fa-clock"></i> En attente d'action corrective
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ic-footer">
                    <div style="font-size:.72rem;color:var(--muted);">
                        #<?php echo $inc['id']; ?>
                    </div>
                    <div style="display:flex;gap:.35rem;">
                        <?php if ($isChef && !$hasAction): ?>
                        <button onclick="Taxi.openModal('modal-action-<?php echo $inc['id']; ?>')" class="btn btn-success btn-xs">
                            <i class="fas fa-gavel"></i> Action
                        </button>
                        <?php endif; ?>
                        <?php if ($isChef): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="incident_id" value="<?php echo $inc['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs" data-confirm="Supprimer cet incident ?">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Modal action corrective -->
            <div id="modal-action-<?php echo $inc['id']; ?>" class="modal-overlay hidden">
                <div class="modal modal-sm">
                    <div class="modal-header">
                        <h2><i class="fas fa-gavel"></i> Action Corrective</h2>
                        <button class="modal-close" onclick="Taxi.closeModal('modal-action-<?php echo $inc['id']; ?>')">×</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_action">
                        <input type="hidden" name="incident_id" value="<?php echo $inc['id']; ?>">
                        <div class="modal-body">
                            <div style="margin-bottom:.85rem;padding:.65rem .85rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);border-radius:8px;font-size:.8rem;color:#f87171;">
                                <strong>Incident #<?php echo $inc['id']; ?></strong> — <?php echo ucfirst($inc['type']); ?> /
                                <span class="badge g-<?php echo $inc['gravite']; ?>" style="font-size:.65rem;"><?php echo ucfirst($inc['gravite']); ?></span>
                            </div>
                            <div style="margin-bottom:.85rem;font-size:.8rem;color:#94a3b8;">
                                <em><?php echo htmlspecialchars(substr($inc['description'],0,120)); ?>…</em>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description de l'Action Prise *</label>
                                <textarea name="action_prise" class="form-textarea" required
                                    placeholder="Décrivez les mesures correctives prises : avertissement, sanction, réparation, formation..."><?php echo htmlspecialchars($inc['action_prise']??''); ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" onclick="Taxi.closeModal('modal-action-<?php echo $inc['id']; ?>')" class="btn btn-secondary btn-sm">Annuler</button>
                            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Valider l'action</button>
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
    </div>

    <!-- Sidebar droite -->
    <div>
        <!-- Répartition types -->
        <div class="card" style="margin-bottom:1.1rem;">
            <div class="card-hd"><div class="card-title"><i class="fas fa-chart-pie"></i> Répartition</div></div>
            <?php
            $typesData = [
                'accident'     => ['label'=>'💥 Accident',     'color'=>'#f87171', 'nb'=>$stats['accidents']],
                'comportement' => ['label'=>'😡 Comportement', 'color'=>'#fbbf24', 'nb'=>$stats['comportements']],
                'materiel'     => ['label'=>'🔧 Matériel',     'color'=>'#60a5fa', 'nb'=>$stats['materiels']],
                'autre'        => ['label'=>'📝 Autre',        'color'=>'#9ca3af', 'nb'=>$stats['autres']],
            ];
            $maxType = max(array_column($typesData,'nb') ?: [1]);
            ?>
            <?php foreach ($typesData as $key => $td): ?>
            <div class="stat-bar-row">
                <div class="stat-bar-label" style="color:<?php echo $td['color']; ?>;"><?php echo $td['label']; ?></div>
                <div class="stat-bar-bg">
                    <div class="stat-bar-fill" style="width:<?php echo $maxType > 0 ? round($td['nb']/$maxType*100) : 0; ?>%;background:<?php echo $td['color']; ?>;opacity:.7;"></div>
                </div>
                <div class="stat-bar-val"><?php echo $td['nb']; ?></div>
            </div>
            <?php endforeach; ?>

            <div style="height:1px;background:rgba(245,158,11,.08);margin:.75rem 0;"></div>

            <div class="stat-bar-row">
                <div class="stat-bar-label" style="color:#f87171;"><i class="fas fa-circle" style="font-size:.55rem;"></i> Graves</div>
                <div class="stat-bar-bg">
                    <div class="stat-bar-fill" style="width:<?php echo $stats['total']>0?round($stats['graves']/$stats['total']*100):0; ?>%;background:#ef4444;"></div>
                </div>
                <div class="stat-bar-val"><?php echo $stats['graves']; ?></div>
            </div>
            <div class="stat-bar-row">
                <div class="stat-bar-label" style="color:var(--col-lt);"><i class="fas fa-circle" style="font-size:.55rem;"></i> Moyens</div>
                <div class="stat-bar-bg">
                    <div class="stat-bar-fill" style="width:<?php echo $stats['total']>0?round($stats['moyennes']/$stats['total']*100):0; ?>%;background:var(--col);opacity:.8;"></div>
                </div>
                <div class="stat-bar-val"><?php echo $stats['moyennes']; ?></div>
            </div>
            <div class="stat-bar-row">
                <div class="stat-bar-label" style="color:#4ade80;"><i class="fas fa-circle" style="font-size:.55rem;"></i> Mineurs</div>
                <div class="stat-bar-bg">
                    <div class="stat-bar-fill" style="width:<?php echo $stats['total']>0?round($stats['mineures']/$stats['total']*100):0; ?>%;background:#4ade80;opacity:.7;"></div>
                </div>
                <div class="stat-bar-val"><?php echo $stats['mineures']; ?></div>
            </div>

            <div style="height:1px;background:rgba(245,158,11,.08);margin:.75rem 0;"></div>

            <div style="display:flex;justify-content:space-between;font-size:.78rem;">
                <div>
                    <span style="color:#4ade80;font-weight:700;"><?php echo $stats['avec_action']; ?></span>
                    <span style="color:var(--muted);"> traités</span>
                </div>
                <div>
                    <span style="color:#f87171;font-weight:700;"><?php echo $stats['sans_action']; ?></span>
                    <span style="color:var(--muted);"> en attente</span>
                </div>
            </div>
        </div>

        <!-- Top chauffeurs incidents -->
        <?php if (!empty($topIncidents)): ?>
        <div class="card" style="margin-bottom:1.1rem;">
            <div class="card-hd"><div class="card-title"><i class="fas fa-user-times"></i> Chauffeurs concernés</div></div>
            <?php foreach ($topIncidents as $i => $ch): ?>
            <div style="display:flex;align-items:center;gap:.65rem;padding:.6rem 0;border-bottom:1px solid rgba(245,158,11,.06);">
                <div style="width:28px;height:28px;border-radius:50%;background:rgba(239,68,68,.12);color:#f87171;font-size:.75rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $i+1; ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.83rem;font-weight:700;color:var(--text);"><?php echo htmlspecialchars($ch['username']); ?></div>
                    <div style="font-size:.7rem;color:var(--muted);">#<?php echo htmlspecialchars($ch['numero_badge']); ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:.82rem;font-weight:700;color:#f87171;"><?php echo $ch['nb_incidents']; ?> incident(s)</div>
                    <?php if ($ch['graves']): ?>
                    <div style="font-size:.7rem;color:#dc2626;"><?php echo $ch['graves']; ?> grave(s)</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Timeline récente -->
        <?php if (!empty($tendance)): ?>
        <div class="card">
            <div class="card-hd"><div class="card-title"><i class="fas fa-history"></i> 30 Derniers Jours</div></div>
            <?php
            // Grouper par jour
            $joursMap = [];
            foreach ($tendance as $t) {
                if (!isset($joursMap[$t['jour']])) $joursMap[$t['jour']] = ['total'=>0,'grave'=>false];
                $joursMap[$t['jour']]['total'] += $t['nb'];
                if ($t['gravite'] === 'grave') $joursMap[$t['jour']]['grave'] = true;
            }
            $joursMap = array_slice($joursMap, 0, 10, true);
            ?>
            <div class="timeline">
                <?php foreach ($joursMap as $jour => $data): ?>
                <div class="timeline-item <?php echo $data['grave']?'grave':''; ?>">
                    <div style="font-size:.78rem;color:var(--text);font-weight:600;">
                        <?php echo date('d/m', strtotime($jour)); ?>
                        <span style="color:<?php echo $data['grave']?'#f87171':'var(--muted)'; ?>;font-weight:700;margin-left:.3rem;">
                            <?php echo $data['total']; ?> incident(s)
                            <?php echo $data['grave']?'<span style="color:#f87171;font-size:.7rem;">⚠ GRAVE</span>':''; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ MODAL SIGNALER INCIDENT ═══════════════════════════════════ -->
<div id="modal-new-incident" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-flag"></i> Signaler un Incident</h2>
            <button class="modal-close" onclick="Taxi.closeModal('modal-new-incident')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_incident">
            <div class="modal-body">

                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="form-label">Course Concernée *</label>
                    <select name="course_id" class="form-select">
                        <option value="">— Sélectionner une course —</option>
                        <?php foreach ($coursesRecentes as $cr): ?>
                        <option value="<?php echo $cr['id']; ?>">
                            <?php echo htmlspecialchars($cr['reference']); ?> — <?php echo htmlspecialchars($cr['client_nom']); ?> (<?php echo date('d/m/Y',strtotime($cr['date_course'])); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:.72rem;color:var(--muted);margin-top:.3rem;">
                        Ou saisissez la référence manuellement :
                        <input type="text" name="course_ref" class="form-input" placeholder="CRS-2025-00001" style="margin-top:.3rem;">
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label">Type d'Incident *</label>
                        <select name="type" class="form-select" required>
                            <option value="accident">💥 Accident</option>
                            <option value="comportement">😡 Comportement</option>
                            <option value="materiel">🔧 Matériel</option>
                            <option value="autre">📝 Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gravité *</label>
                        <select name="gravite" class="form-select" required>
                            <option value="mineure">🟢 Mineure</option>
                            <option value="moyenne">🟡 Moyenne</option>
                            <option value="grave">🔴 Grave</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="form-label">Description détaillée *</label>
                    <textarea name="description" class="form-textarea" required
                              placeholder="Décrivez précisément l'incident : circonstances, personnes impliquées, dommages constatés..."
                              style="min-height:110px;"></textarea>
                </div>

                <?php if ($isChef): ?>
                <div class="form-group">
                    <label class="form-label">Action Corrective (si déjà prise)</label>
                    <textarea name="action_prise" class="form-textarea"
                              placeholder="Mesures prises immédiatement..."
                              style="min-height:75px;"></textarea>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Taxi.closeModal('modal-new-incident')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-flag"></i> Signaler l'incident</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>