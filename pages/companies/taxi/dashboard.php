<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Vue d\'ensemble en temps réel';
$db  = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();
$canStats = faction_acl_can($db, $uid, $companyFaction, 'dashboard.stats');
$uid = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff','Directeur','Responsable']);

// Réponse JSON pour compteur nav
if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type: application/json');
    echo json_encode(['attente' => (int)$n]);
    exit;
}

// ─── STATS GLOBALES ───────────────────────────────────────────────────────────
$today = date('Y-m-d');

$statsGlobal = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(statut='en_attente') as en_attente,
        SUM(statut='en_cours')   as en_cours,
        SUM(statut='assignee')   as assignee,
        SUM(statut='terminee')   as terminee,
        SUM(statut='annulee')    as annulee,
        SUM(urgente=1 AND statut NOT IN ('terminee','annulee')) as urgentes
    FROM taxi_courses
")->fetch();

$statsToday = $db->query("
    SELECT
        COUNT(*) as nb,
        SUM(montant_total) as ca,
        AVG(duree_course) as duree_moy,
        AVG(note_client) as note_moy,
        SUM(distance_km) as km_total
    FROM taxi_courses
    WHERE DATE(date_demande) = '$today' AND statut = 'terminee'
")->fetch();

$statsMois = $db->query("
    SELECT
        COUNT(*) as nb,
        SUM(montant_total) as ca,
        SUM(pourboire) as pourboires
    FROM taxi_courses
    WHERE MONTH(date_demande)=MONTH(NOW()) AND YEAR(date_demande)=YEAR(NOW()) AND statut='terminee'
")->fetch();

// Chauffeurs actifs maintenant
$chauffeursActifs = $db->query("
    SELECT tc.*, u.username, u.faction_rank,
           (SELECT COUNT(*) FROM taxi_courses c WHERE c.chauffeur_id=tc.id AND c.statut IN ('en_cours','assignee')) as course_active
    FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id = u.id
    WHERE tc.actif=1 AND tc.statut != 'hors_service'
    ORDER BY FIELD(tc.statut,'en_course','disponible','pause')
")->fetchAll();

// Courses actives (en cours / assignées)
$coursesActives = $db->query("
    SELECT c.*,
           tc.numero_badge, u.username as chauffeur_nom,
           TIMESTAMPDIFF(MINUTE, c.heure_prise_en_charge, NOW()) as mins_en_cours
    FROM taxi_courses c
    LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id = tc.id
    LEFT JOIN users u ON tc.user_id = u.id
    WHERE c.statut IN ('en_cours','assignee')
    ORDER BY c.urgente DESC, c.date_demande ASC
")->fetchAll();

// Courses en attente
$coursesAttente = $db->query("
    SELECT c.*,
           cl.type as client_type, cl.nom as client_reg_nom,
           TIMESTAMPDIFF(MINUTE, c.date_demande, NOW()) as mins_attente
    FROM taxi_courses c
    LEFT JOIN taxi_clients cl ON c.client_id = cl.id
    WHERE c.statut = 'en_attente'
    ORDER BY c.urgente DESC, c.date_demande ASC
    LIMIT 10
")->fetchAll();

// Dernières courses terminées
$derniersCourses = $db->query("
    SELECT c.*, tc.numero_badge, u.username as chauffeur_nom
    FROM taxi_courses c
    LEFT JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    LEFT JOIN users u ON tc.user_id=u.id
    WHERE c.statut='terminee'
    ORDER BY c.heure_arrivee DESC
    LIMIT 8
")->fetchAll();

// Top chauffeurs du jour
$topChauffeurs = $db->query("
    SELECT u.username, tc.numero_badge, tc.statut as ch_statut,
           COUNT(c.id) as nb_courses,
           SUM(c.montant_total) as revenus,
           AVG(c.note_client) as note_moy
    FROM taxi_courses c
    JOIN taxi_chauffeurs tc ON c.chauffeur_id=tc.id
    JOIN users u ON tc.user_id=u.id
    WHERE DATE(c.date_demande)='$today' AND c.statut='terminee'
    GROUP BY tc.id, u.username, tc.numero_badge, tc.statut
    ORDER BY revenus DESC
    LIMIT 6
")->fetchAll();

// Revenus 7 derniers jours
$revenusSemaine = $db->query("
    SELECT DATE(date_demande) as jour,
           COUNT(*) as nb_courses,
           SUM(montant_total) as ca
    FROM taxi_courses
    WHERE statut='terminee'
      AND date_demande >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(date_demande)
    ORDER BY jour ASC
")->fetchAll();

// Stats chauffeurs disponibles vs en course
$repartition = $db->query("
    SELECT statut, COUNT(*) as nb
    FROM taxi_chauffeurs WHERE actif=1
    GROUP BY statut
")->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/includes/header.php';
?>

<style>
/* Live courses strip */
.live-strip {
    background: rgba(245,158,11,.06);
    border: 1px solid rgba(245,158,11,.2);
    border-radius:12px; padding:1rem 1.25rem;
    margin-bottom:1.4rem;
    display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
}
.live-strip-item {
    display:flex; align-items:center; gap:.4rem;
    font-size:.82rem; color:#94a3b8;
}
.live-strip-item strong { color:var(--text); font-size:1.05rem; }

/* Chauffeur card mini */
.driver-card {
    background:rgba(13,17,23,.8);
    border:1px solid rgba(245,158,11,.12);
    border-radius:11px; padding:.95rem 1.1rem;
    display:flex; align-items:center; gap:.9rem;
    transition:all .2s;
}
.driver-card:hover { border-color:rgba(245,158,11,.32); }

.driver-avatar {
    width:38px; height:38px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:1rem; font-weight:800; flex-shrink:0;
    background:linear-gradient(135deg,var(--col),var(--col-dk));
    color:#000;
}
.driver-info { flex:1; min-width:0; }
.driver-name { font-size:.85rem; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.driver-badge{ font-size:.72rem; color:var(--muted); }

/* Course active card */
.active-course-card {
    background:rgba(13,17,23,.8);
    border:1px solid rgba(124,58,237,.2);
    border-radius:11px; padding:1rem 1.15rem;
    margin-bottom:.65rem;
    transition:all .2s;
}
.active-course-card.urgente { border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.04); }
.ac-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:.5rem; }
.ac-ref  { font-size:.72rem; color:var(--muted); font-family:monospace; }
.ac-route{ font-size:.82rem; color:#cbd5e1; }
.ac-route strong { color:var(--text); }
.ac-meta { display:flex; gap:.65rem; flex-wrap:wrap; margin-top:.4rem; font-size:.75rem; color:var(--muted); }
.ac-meta span { display:flex; align-items:center; gap:.25rem; }

/* Attente card */
.attente-card {
    background:rgba(13,17,23,.8);
    border:1px solid rgba(245,158,11,.15);
    border-radius:11px; padding:.9rem 1.1rem;
    margin-bottom:.6rem;
    display:flex; align-items:center; gap:1rem;
    transition:all .2s;
}
.attente-card.urgente { border-color:rgba(239,68,68,.4); background:rgba(239,68,68,.04); }
.attente-card:hover { border-color:rgba(245,158,11,.38); }
.attente-wait { font-size:1.2rem; font-weight:800; color:var(--col-lt); min-width:40px; text-align:center; }
.attente-wait.long { color:#f87171; }
.attente-info { flex:1; min-width:0; }
.attente-client { font-size:.86rem; font-weight:700; color:var(--text); }
.attente-route  { font-size:.78rem; color:var(--muted); margin-top:.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Chart bar */
.mini-bar-chart { display:flex; align-items:flex-end; gap:.25rem; height:70px; }
.mini-bar {
    flex:1; background:rgba(245,158,11,.15);
    border-radius:4px 4px 0 0;
    position:relative; min-height:4px;
    transition:height .5s;
    cursor:default;
}
.mini-bar:hover { background:rgba(245,158,11,.3); }
.mini-bar-label { font-size:.62rem; color:var(--muted); text-align:center; margin-top:.2rem; }

/* Top driver row */
.top-driver-row {
    display:flex; align-items:center; gap:.75rem;
    padding:.7rem 0;
    border-bottom:1px solid rgba(245,158,11,.06);
}
.top-driver-row:last-child { border-bottom:none; }
.top-rank { width:24px; height:24px; border-radius:50%; background:rgba(245,158,11,.12); color:var(--col-lt); font-size:.72rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.top-rank.gold   { background:linear-gradient(135deg,#f59e0b,#b45309); color:#000; }
.top-rank.silver { background:linear-gradient(135deg,#94a3b8,#64748b); color:#fff; }
.top-rank.bronze { background:linear-gradient(135deg,#cd7c2f,#92400e); color:#fff; }

/* Doughnut mini */
.repartition-grid { display:flex; flex-direction:column; gap:.55rem; }
.rep-row { display:flex; align-items:center; gap:.65rem; }
.rep-label { font-size:.78rem; color:#94a3b8; flex:1; }
.rep-bar-bg { flex:2; height:7px; background:rgba(255,255,255,.06); border-radius:4px; overflow:hidden; }
.rep-bar-fill { height:100%; border-radius:4px; transition:width .6s; }
.rep-val { font-size:.78rem; font-weight:700; color:var(--text); min-width:24px; text-align:right; }
</style>

<!-- ── Bandeau Live ──────────────────────────────────────────── -->
<div class="live-strip">
    <div style="display:flex;align-items:center;gap:.5rem;">
        <span class="live-badge"><span class="dot"></span>LIVE</span>
    </div>
    <div class="live-strip-item"><i class="fas fa-car-side"></i> <strong><?php echo count($chauffeursActifs); ?></strong>&nbsp;chauffeurs actifs</div>
    <div class="live-strip-item"><i class="fas fa-route"></i> <strong><?php echo count($coursesActives); ?></strong>&nbsp;courses en cours</div>
    <div class="live-strip-item"><i class="fas fa-hourglass-half"></i> <strong><?php echo $statsGlobal['en_attente']; ?></strong>&nbsp;en attente</div>
    <div class="live-strip-item"><i class="fas fa-bolt"></i> <strong><?php echo $statsGlobal['urgentes']; ?></strong>&nbsp;urgentes</div>
    <div style="margin-left:auto;font-size:.75rem;color:var(--muted);">
        <i class="fas fa-sync-alt"></i> <?php echo date('H:i'); ?>
    </div>
</div>

<!-- ── Stats Cards ──────────────────────────────────────────── -->
<div class="grid-4" style="margin-bottom:1.4rem;">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        <div class="stat-label">CA Aujourd'hui</div>
        <div class="stat-value">$<?php echo number_format($statsToday['ca'] ?? 0, 0); ?></div>
        <div class="stat-sub"><span class="stat-up"><i class="fas fa-check-circle"></i></span> <?php echo $statsToday['nb'] ?? 0; ?> courses terminées</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(124,58,237,.12);color:#a78bfa;"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-label">CA du Mois</div>
        <div class="stat-value">$<?php echo number_format($statsMois['ca'] ?? 0, 0); ?></div>
        <div class="stat-sub"><?php echo $statsMois['nb'] ?? 0; ?> courses · +$<?php echo number_format($statsMois['pourboires'] ?? 0, 0); ?> pourboires</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(34,197,94,.12);color:#4ade80;"><i class="fas fa-star"></i></div>
        <div class="stat-label">Note Moyenne</div>
        <div class="stat-value"><?php echo number_format($statsToday['note_moy'] ?? 5, 1); ?></div>
        <div class="stat-sub stars"><?php echo str_repeat('★', round($statsToday['note_moy'] ?? 5)); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(59,130,246,.12);color:#60a5fa;"><i class="fas fa-road"></i></div>
        <div class="stat-label">Km Parcourus</div>
        <div class="stat-value"><?php echo number_format($statsToday['km_total'] ?? 0, 0); ?></div>
        <div class="stat-sub">Durée moy : <?php echo $statsToday['duree_moy'] ? round($statsToday['duree_moy']).'min' : '—'; ?></div>
    </div>
</div>

<!-- ── Grille principale ─────────────────────────────────────── -->
<div class="grid-2" style="align-items:start;">

    <!-- Colonne gauche -->
    <div>
        <!-- Chauffeurs en ligne -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-car"></i> Chauffeurs Actifs</div>
                <a href="chauffeurs.php" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Tout voir</a>
            </div>
            <?php if (empty($chauffeursActifs)): ?>
                <div style="text-align:center;padding:2rem;color:var(--muted);">Aucun chauffeur actif</div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem;">
                <?php foreach ($chauffeursActifs as $ch): ?>
                <div class="driver-card">
                    <div class="driver-avatar"><?php echo strtoupper(substr($ch['username'],0,1)); ?></div>
                    <div class="driver-info">
                        <div class="driver-name"><?php echo htmlspecialchars($ch['username']); ?></div>
                        <div class="driver-badge">
                            <span class="badge b-<?php echo $ch['statut']; ?>"><?php echo ucfirst(str_replace('_',' ',$ch['statut'])); ?></span>
                        </div>
                    </div>
                    <?php if ($ch['course_active']): ?>
                        <i class="fas fa-circle" style="color:#a78bfa;font-size:.55rem;"></i>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Courses en attente -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-hourglass-half"></i> En Attente <span style="color:#f87171;font-size:.82rem;margin-left:.3rem;">(<?php echo count($coursesAttente); ?>)</span></div>
                <a href="dispatch.php" class="btn btn-warning btn-sm"><i class="fas fa-broadcast-tower"></i> Dispatch</a>
            </div>
            <?php if (empty($coursesAttente)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--muted);font-size:.85rem;"><i class="fas fa-check-circle" style="color:#4ade80;"></i> Aucune course en attente</div>
            <?php else: ?>
                <?php foreach ($coursesAttente as $c): ?>
                <div class="attente-card <?php echo $c['urgente']?'urgente':''; ?>">
                    <div class="attente-wait <?php echo $c['mins_attente']>15?'long':''; ?>">
                        <?php echo $c['mins_attente']; ?>m
                    </div>
                    <div class="attente-info">
                        <div class="attente-client">
                            <?php echo htmlspecialchars($c['client_nom']); ?>
                            <?php if ($c['urgente']): ?><span class="badge b-urgente" style="margin-left:.4rem;">URGENTE</span><?php endif; ?>
                        </div>
                        <div class="attente-route">
                            <i class="fas fa-map-marker-alt" style="color:var(--col);"></i> <?php echo htmlspecialchars($c['depart_adresse']); ?>
                            &rarr; <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                        </div>
                    </div>
                    <a href="dispatch.php?assign=<?php echo $c['id']; ?>" class="btn btn-primary btn-xs">
                        <i class="fas fa-paper-plane"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Colonne droite -->
    <div>
        <!-- Courses en cours -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-route"></i> En Cours <span style="color:#a78bfa;font-size:.82rem;margin-left:.3rem;">(<?php echo count($coursesActives); ?>)</span></div>
            </div>
            <?php if (empty($coursesActives)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--muted);font-size:.85rem;">Aucune course en cours</div>
            <?php else: ?>
                <?php foreach ($coursesActives as $c): ?>
                <div class="active-course-card <?php echo $c['urgente']?'urgente':''; ?>">
                    <div class="ac-header">
                        <span class="ac-ref"><?php echo htmlspecialchars($c['reference']); ?></span>
                        <span class="badge b-<?php echo $c['statut']; ?>"><?php echo ucfirst(str_replace('_',' ',$c['statut'])); ?></span>
                    </div>
                    <div class="ac-route">
                        <strong><?php echo htmlspecialchars($c['client_nom']); ?></strong>
                        <span style="color:var(--muted);"> · </span>
                        <?php echo htmlspecialchars($c['depart_adresse']); ?> → <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                    </div>
                    <div class="ac-meta">
                        <?php if ($c['chauffeur_nom']): ?>
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($c['chauffeur_nom']); ?> #<?php echo htmlspecialchars($c['numero_badge']); ?></span>
                        <?php endif; ?>
                        <?php if ($c['mins_en_cours'] !== null): ?>
                        <span><i class="fas fa-clock"></i> <?php echo $c['mins_en_cours']; ?>min en course</span>
                        <?php endif; ?>
                        <?php if ($c['distance_km']): ?>
                        <span><i class="fas fa-road"></i> <?php echo $c['distance_km']; ?>km</span>
                        <?php endif; ?>
                        <?php if ($c['montant_total']): ?>
                        <span style="color:var(--col-lt);font-weight:700;"><i class="fas fa-dollar-sign"></i> $<?php echo number_format($c['montant_total'],2); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Top Chauffeurs du jour -->
        <div class="card">
            <div class="card-hd">
                <div class="card-title"><i class="fas fa-trophy"></i> Top Chauffeurs — Aujourd'hui</div>
            </div>
            <?php if (empty($topChauffeurs)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--muted);font-size:.85rem;">Aucune course terminée aujourd'hui</div>
            <?php else: ?>
                <?php foreach ($topChauffeurs as $i => $ch): ?>
                <div class="top-driver-row">
                    <div class="top-rank <?php echo $i===0?'gold':($i===1?'silver':($i===2?'bronze':'')); ?>"><?php echo $i+1; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.85rem;font-weight:700;color:var(--text);"><?php echo htmlspecialchars($ch['username']); ?></div>
                        <div style="font-size:.73rem;color:var(--muted);">
                            #<?php echo htmlspecialchars($ch['numero_badge']); ?> · <?php echo $ch['nb_courses']; ?> course(s)
                            <?php if ($ch['note_moy']): ?> · <span class="stars"><?php echo str_repeat('★', round($ch['note_moy'])); ?></span> <?php echo number_format($ch['note_moy'],1); ?><?php endif; ?>
                        </div>
                    </div>
                    <div style="font-weight:800;color:var(--col-lt);">$<?php echo number_format($ch['revenus'],0); ?></div>
                    <span class="badge b-<?php echo $ch['ch_statut']; ?>" style="font-size:.65rem;"><?php echo ucfirst(str_replace('_',' ',$ch['ch_statut'])); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Revenus 7 jours + Répartition ────────────────────────── -->
<div class="grid-2">
    <div class="card">
        <div class="card-hd">
            <div class="card-title"><i class="fas fa-chart-bar"></i> Revenus — 7 Derniers Jours</div>
        </div>
        <?php
        $maxCA = max(array_column($revenusSemaine, 'ca') ?: [1]);
        ?>
        <div class="mini-bar-chart">
            <?php foreach ($revenusSemaine as $row): ?>
            <?php $pct = min(100, round(($row['ca'] / $maxCA) * 100)); ?>
            <div style="display:flex;flex-direction:column;align-items:center;flex:1;">
                <div class="mini-bar" style="height:<?php echo $pct; ?>%;width:100%;"
                     title="<?php echo date('d/m',strtotime($row['jour'])); ?> — $<?php echo number_format($row['ca'],0); ?> (<?php echo $row['nb_courses']; ?> courses)">
                </div>
                <div class="mini-bar-label"><?php echo date('D',strtotime($row['jour'])); ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($revenusSemaine)): ?>
            <div style="color:var(--muted);font-size:.82rem;text-align:center;width:100%;padding:1rem;">Pas encore de données</div>
            <?php endif; ?>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--muted);margin-top:.5rem;">
            <span>Total semaine : <strong style="color:var(--col-lt);">$<?php echo number_format(array_sum(array_column($revenusSemaine,'ca')),0); ?></strong></span>
            <span><?php echo array_sum(array_column($revenusSemaine,'nb_courses')); ?> courses</span>
        </div>
    </div>

    <div class="card">
        <div class="card-hd">
            <div class="card-title"><i class="fas fa-users"></i> Répartition Chauffeurs</div>
        </div>
        <?php
        $totalCh = array_sum($repartition) ?: 1;
        $repColors = ['disponible'=>'#4ade80','en_course'=>'#fbbf24','pause'=>'#60a5fa','hors_service'=>'#6b7280'];
        $repLabels = ['disponible'=>'Disponibles','en_course'=>'En course','pause'=>'En pause','hors_service'=>'Hors service'];
        ?>
        <div class="repartition-grid">
            <?php foreach ($repLabels as $key => $label): ?>
            <?php $nb = $repartition[$key] ?? 0; $pct = round($nb / $totalCh * 100); ?>
            <div class="rep-row">
                <div class="rep-label"><span style="color:<?php echo $repColors[$key]; ?>;">●</span> <?php echo $label; ?></div>
                <div class="rep-bar-bg">
                    <div class="rep-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $repColors[$key]; ?>;opacity:.7;"></div>
                </div>
                <div class="rep-val"><?php echo $nb; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid rgba(245,158,11,.08);font-size:.78rem;color:var(--muted);">
            Total enregistrés : <strong style="color:var(--text);"><?php echo $totalCh; ?></strong> chauffeurs
        </div>
    </div>
</div>

<!-- ── Dernières courses ─────────────────────────────────────── -->
<div class="card">
    <div class="card-hd">
        <div class="card-title"><i class="fas fa-history"></i> Dernières Courses Terminées</div>
        <a href="courses.php" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> Toutes les courses</a>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Réf.</th>
                <th>Client</th>
                <th>Chauffeur</th>
                <th>Trajet</th>
                <th>Durée</th>
                <th>Montant</th>
                <th>Note</th>
                <th>Paiement</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($derniersCourses)): ?>
            <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted);">Aucune course terminée</td></tr>
            <?php endif; ?>
            <?php foreach ($derniersCourses as $c): ?>
            <tr>
                <td style="font-family:monospace;font-size:.78rem;color:var(--muted);"><?php echo htmlspecialchars($c['reference']); ?></td>
                <td style="font-weight:600;color:var(--text);"><?php echo htmlspecialchars($c['client_nom']); ?></td>
                <td><?php echo $c['chauffeur_nom'] ? htmlspecialchars($c['chauffeur_nom']).' <span style="color:var(--muted);font-size:.75rem;">#'.htmlspecialchars($c['numero_badge']).'</span>' : '<span style="color:var(--muted);">—</span>'; ?></td>
                <td style="font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($c['depart_adresse']); ?> → <?php echo htmlspecialchars($c['arrivee_adresse']); ?>
                </td>
                <td><?php echo $c['duree_course'] ? $c['duree_course'].'min' : '—'; ?></td>
                <td style="font-weight:700;color:var(--col-lt);">$<?php echo number_format($c['montant_total'],2); ?></td>
                <td>
                    <?php if ($c['note_client']): ?>
                    <span class="stars" style="font-size:.75rem;"><?php echo str_repeat('★',intval($c['note_client'])); ?><?php echo str_repeat('☆',5-intval($c['note_client'])); ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="badge b-<?php echo $c['mode_paiement']; ?>"><?php echo ucfirst($c['mode_paiement']); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>