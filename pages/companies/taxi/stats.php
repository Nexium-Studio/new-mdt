<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🚕 Taxi Los Santos';
$companyColor   = '#f59e0b';
$companyFaction = 'TAXI';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Statistiques';
$pageSubtitle = 'Analyse des performances';
$db  = Database::getInstance()->getConnection();

if (isset($_GET['_count'])) {
    $n = $db->query("SELECT COUNT(*) FROM taxi_courses WHERE statut='en_attente'")->fetchColumn();
    header('Content-Type:application/json'); echo json_encode(['attente'=>(int)$n]); exit;
}

// Période
$periode = $_GET['periode'] ?? '30';
$dateDebut = date('Y-m-d', strtotime("-{$periode} days"));
$today = date('Y-m-d');

// ─── STATS GLOBALES PÉRIODE ───────────────────────────────────────────────────
$sg = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(statut='terminee') as terminee,
        SUM(statut='annulee') as annulee,
        SUM(montant_total) as ca_total,
        SUM(pourboire) as pourboires,
        AVG(CASE WHEN statut='terminee' THEN montant_total END) as panier_moy,
        AVG(CASE WHEN statut='terminee' THEN distance_km END) as dist_moy,
        AVG(CASE WHEN statut='terminee' THEN duree_course END) as duree_moy,
        AVG(note_client) as note_moy,
        SUM(CASE WHEN statut='terminee' THEN distance_km ELSE 0 END) as km_total,
        SUM(urgente=1 AND statut='terminee') as urgentes
    FROM taxi_courses
    WHERE DATE(date_demande) >= '$dateDebut'
")->fetch();

// Revenus par jour
$revJour = $db->query("
    SELECT DATE(date_demande) as jour, COUNT(*) as nb, SUM(montant_total) as ca
    FROM taxi_courses
    WHERE statut='terminee' AND DATE(date_demande) >= '$dateDebut'
    GROUP BY DATE(date_demande) ORDER BY jour ASC
")->fetchAll();

// Répartition par heure
$parHeure = $db->query("
    SELECT HOUR(date_demande) as heure, COUNT(*) as nb
    FROM taxi_courses
    WHERE DATE(date_demande) >= '$dateDebut'
    GROUP BY HOUR(date_demande) ORDER BY heure ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Top chauffeurs période
$topCh = $db->query("
    SELECT u.username, tc.numero_badge, tc.statut,
           COUNT(CASE WHEN c.statut='terminee' THEN 1 END) as nb,
           SUM(CASE WHEN c.statut='terminee' THEN c.montant_total ELSE 0 END) as ca,
           AVG(CASE WHEN c.statut='terminee' THEN c.note_client END) as note,
           SUM(CASE WHEN c.statut='terminee' THEN c.distance_km ELSE 0 END) as km
    FROM taxi_chauffeurs tc
    JOIN users u ON tc.user_id=u.id
    LEFT JOIN taxi_courses c ON tc.id=c.chauffeur_id AND DATE(c.date_demande) >= '$dateDebut'
    WHERE tc.actif=1
    GROUP BY tc.id, u.username, tc.numero_badge, tc.statut
    HAVING nb > 0
    ORDER BY ca DESC
    LIMIT 10
")->fetchAll();

// Répartition modes de paiement
$modesPaie = $db->query("
    SELECT mode_paiement, COUNT(*) as nb, SUM(montant_total) as ca
    FROM taxi_courses
    WHERE statut='terminee' AND DATE(date_demande) >= '$dateDebut'
    GROUP BY mode_paiement ORDER BY ca DESC
")->fetchAll();

// Taux annulation
$tauxAnnul = $sg['total'] > 0 ? round($sg['annulee'] / $sg['total'] * 100, 1) : 0;
$tauxConclus = $sg['total'] > 0 ? round($sg['terminee'] / $sg['total'] * 100, 1) : 0;

// Courses par jour semaine
$parJourSem = $db->query("
    SELECT DAYOFWEEK(date_demande) as jour, COUNT(*) as nb, SUM(montant_total) as ca
    FROM taxi_courses
    WHERE statut='terminee' AND DATE(date_demande) >= '$dateDebut'
    GROUP BY DAYOFWEEK(date_demande) ORDER BY jour
")->fetchAll(PDO::FETCH_UNIQUE);

include __DIR__ . '/includes/header.php';
?>

<style>
.kpi-card {
    background:rgba(13,17,23,.8);
    border:1px solid var(--border);
    border-radius:14px;
    padding:1.25rem;
    text-align:center;
    transition:all .22s;
}
.kpi-card:hover { border-color:rgba(245,158,11,.35); }
.kpi-icon { font-size:1.6rem; margin-bottom:.5rem; }
.kpi-val  { font-size:1.6rem; font-weight:800; color:var(--text); letter-spacing:-1px; }
.kpi-label{ font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin-top:.2rem; }
.kpi-sub  { font-size:.75rem; color:var(--muted); margin-top:.2rem; }

/* Bar chart custom */
.chart-bar-row {
    display:flex; align-items:center; gap:.75rem; margin-bottom:.45rem;
}
.chart-bar-label { font-size:.75rem; color:#94a3b8; min-width:60px; text-align:right; }
.chart-bar-bg    { flex:1; height:8px; background:rgba(255,255,255,.05); border-radius:4px; overflow:hidden; }
.chart-bar-fill  { height:100%; border-radius:4px; transition:width .6s; }
.chart-bar-val   { font-size:.75rem; font-weight:700; color:var(--text); min-width:55px; text-align:right; }

/* Heatmap heures */
.hour-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:.2rem; }
.hour-cell {
    aspect-ratio:1;
    border-radius:4px;
    display:flex; align-items:center; justify-content:center;
    font-size:.6rem; font-weight:700; color:rgba(255,255,255,.4);
    transition:all .2s;
    cursor:default;
}
.hour-cell:hover { transform:scale(1.2); }

/* Progression radiale */
.radial-wrap {
    display:flex; flex-direction:column; align-items:center; gap:.3rem;
}
.radial-val { font-size:1.8rem; font-weight:800; letter-spacing:-1px; }
</style>

<!-- Sélecteur période -->
<div style="display:flex;align-items:center;gap:.65rem;margin-bottom:1.4rem;flex-wrap:wrap;">
    <span style="font-size:.82rem;color:var(--muted);">Période :</span>
    <?php foreach (['7'=>'7 jours','14'=>'14 jours','30'=>'30 jours','90'=>'3 mois','365'=>'1 an'] as $v=>$l): ?>
    <a href="?periode=<?php echo $v; ?>" class="btn btn-sm <?php echo $periode==$v?'btn-primary':'btn-secondary'; ?>">
        <?php echo $l; ?>
    </a>
    <?php endforeach; ?>
    <span style="font-size:.78rem;color:var(--muted);margin-left:auto;">
        Du <?php echo date('d/m/Y', strtotime($dateDebut)); ?> au <?php echo date('d/m/Y'); ?>
    </span>
</div>

<!-- KPIs principaux -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.4rem;">
    <div class="kpi-card">
        <div class="kpi-icon">🚕</div>
        <div class="kpi-val"><?php echo number_format($sg['total']); ?></div>
        <div class="kpi-label">Courses totales</div>
        <div class="kpi-sub"><?php echo $tauxConclus; ?>% conclues</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">💰</div>
        <div class="kpi-val">$<?php echo number_format($sg['ca_total']??0, 0); ?></div>
        <div class="kpi-label">CA Total</div>
        <div class="kpi-sub">+$<?php echo number_format($sg['pourboires']??0,0); ?> tips</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">🧾</div>
        <div class="kpi-val">$<?php echo number_format($sg['panier_moy']??0, 2); ?></div>
        <div class="kpi-label">Panier Moyen</div>
        <div class="kpi-sub">par course terminée</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">🛣️</div>
        <div class="kpi-val"><?php echo number_format($sg['km_total']??0, 0); ?></div>
        <div class="kpi-label">Km Parcourus</div>
        <div class="kpi-sub">moy. <?php echo number_format($sg['dist_moy']??0, 1); ?>km/course</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">⏱️</div>
        <div class="kpi-val"><?php echo round($sg['duree_moy']??0); ?>m</div>
        <div class="kpi-label">Durée Moyenne</div>
        <div class="kpi-sub">par course</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">⭐</div>
        <div class="kpi-val"><?php echo number_format($sg['note_moy']??0, 1); ?></div>
        <div class="kpi-label">Note Moyenne</div>
        <div class="kpi-sub" class="stars"><?php echo str_repeat('★', round($sg['note_moy']??0)); ?></div>
    </div>
    <div class="kpi-card" style="border-color:rgba(239,68,68,.2);">
        <div class="kpi-icon">❌</div>
        <div class="kpi-val" style="color:#f87171;"><?php echo $tauxAnnul; ?>%</div>
        <div class="kpi-label">Taux Annulation</div>
        <div class="kpi-sub"><?php echo $sg['annulee']; ?> courses</div>
    </div>
    <div class="kpi-card" style="border-color:rgba(239,68,68,.15);">
        <div class="kpi-icon">⚡</div>
        <div class="kpi-val" style="color:#f87171;"><?php echo $sg['urgentes']; ?></div>
        <div class="kpi-label">Courses Urgentes</div>
        <div class="kpi-sub">terminées</div>
    </div>
</div>

<div class="grid-2">
    <!-- Revenus par jour chart -->
    <div class="card">
        <div class="card-hd"><div class="card-title"><i class="fas fa-chart-area"></i> CA Journalier</div></div>
        <?php
        $maxCA = max(array_column($revJour,'ca') ?: [1]);
        ?>
        <?php if (empty($revJour)): ?>
        <div style="text-align:center;padding:2rem;color:var(--muted);">Pas de données</div>
        <?php else: ?>
        <div style="display:flex;align-items:flex-end;gap:.2rem;height:100px;padding-bottom:.5rem;">
            <?php foreach ($revJour as $r):
                $h = max(5, round(($r['ca']/$maxCA)*100));
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;" title="<?php echo date('d/m',strtotime($r['jour'])); ?> — $<?php echo number_format($r['ca'],0); ?> (<?php echo $r['nb']; ?> courses)">
                <div style="background:linear-gradient(0,rgba(245,158,11,.4),rgba(245,158,11,.2));width:100%;height:<?php echo $h; ?>%;border-radius:3px 3px 0 0;min-height:4px;"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--muted);margin-top:.25rem;">
            <span><?php echo date('d/m',strtotime($revJour[0]['jour'])); ?></span>
            <span>$<?php echo number_format($sg['ca_total']??0,0); ?> total</span>
            <span><?php echo date('d/m',strtotime(end($revJour)['jour'])); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modes de paiement -->
    <div class="card">
        <div class="card-hd"><div class="card-title"><i class="fas fa-credit-card"></i> Modes de Paiement</div></div>
        <?php
        $totalPaie = array_sum(array_column($modesPaie,'ca')) ?: 1;
        $paieColors = ['especes'=>'#4ade80','carte'=>'#60a5fa','virement'=>'#a78bfa','compte'=>'#fbbf24'];
        $paieLabels = ['especes'=>'💵 Espèces','carte'=>'💳 Carte','virement'=>'🏦 Virement','compte'=>'📒 Compte'];
        ?>
        <?php foreach ($modesPaie as $mp): ?>
        <div class="chart-bar-row">
            <div class="chart-bar-label"><?php echo $paieLabels[$mp['mode_paiement']]??ucfirst($mp['mode_paiement']); ?></div>
            <div class="chart-bar-bg">
                <div class="chart-bar-fill" style="width:<?php echo round($mp['ca']/$totalPaie*100); ?>%;background:<?php echo $paieColors[$mp['mode_paiement']]??'#94a3b8'; ?>;opacity:.8;"></div>
            </div>
            <div class="chart-bar-val">$<?php echo number_format($mp['ca'],0); ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($modesPaie)): ?>
        <div style="text-align:center;padding:2rem;color:var(--muted);">Pas de données</div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <!-- Heatmap par heure -->
    <div class="card">
        <div class="card-hd"><div class="card-title"><i class="fas fa-clock"></i> Activité par Heure</div></div>
        <?php $maxHeure = max($parHeure ?: [1]); ?>
        <div class="hour-grid">
            <?php for ($h=0; $h<24; $h++):
                $nb  = $parHeure[$h] ?? 0;
                $pct = $maxHeure > 0 ? ($nb/$maxHeure) : 0;
                $alpha = max(0.08, $pct * 0.85);
                $isNight = $h < 6 || $h >= 22;
            ?>
            <div class="hour-cell"
                 style="background:rgba(<?php echo $isNight?'124,58,237':'245,158,11'; ?>,<?php echo $alpha; ?>);"
                 title="<?php echo str_pad($h,2,'0',STR_PAD_LEFT); ?>h00 — <?php echo $nb; ?> courses">
                <?php echo str_pad($h,2,'0',STR_PAD_LEFT); ?>
            </div>
            <?php endfor; ?>
        </div>
        <div style="display:flex;gap:1rem;font-size:.72rem;color:var(--muted);margin-top:.75rem;">
            <span><span style="color:var(--col);">■</span> Heures de jour</span>
            <span><span style="color:#a78bfa;">■</span> Heures de nuit</span>
            <span style="margin-left:auto;">Plus foncé = plus de courses</span>
        </div>
    </div>

    <!-- Activité par jour de semaine -->
    <div class="card">
        <div class="card-hd"><div class="card-title"><i class="fas fa-calendar-week"></i> Activité par Jour</div></div>
        <?php
        $jourLabels = [1=>'Dim',2=>'Lun',3=>'Mar',4=>'Mer',5=>'Jeu',6=>'Ven',7=>'Sam'];
        $maxJour = max(array_column($parJourSem,'ca') ?: [1]);
        ?>
        <?php for ($j=2; $j<=8; $j++):
            $key = ($j > 7) ? 1 : $j;
            $data = $parJourSem[$key] ?? ['nb'=>0,'ca'=>0];
            $pct = $maxJour > 0 ? round($data['ca']/$maxJour*100) : 0;
            $isWeekend = in_array($key,[1,7]);
        ?>
        <div class="chart-bar-row">
            <div class="chart-bar-label"><?php echo $jourLabels[$key]; ?><?php echo $isWeekend?' 🟡':''; ?></div>
            <div class="chart-bar-bg">
                <div class="chart-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $isWeekend?'#fbbf24':'rgba(245,158,11,.7)'; ?>;"></div>
            </div>
            <div class="chart-bar-val" style="font-size:.72rem;">$<?php echo number_format($data['ca']??0,0); ?> <span style="color:var(--muted);">(<?php echo $data['nb']??0; ?>)</span></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- Top Chauffeurs Période -->
<div class="card">
    <div class="card-hd">
        <div class="card-title"><i class="fas fa-trophy"></i> Classement Chauffeurs — <?php echo $periode; ?> derniers jours</div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Chauffeur</th>
                <th>Badge</th>
                <th>Statut</th>
                <th>Courses</th>
                <th>CA</th>
                <th>Km</th>
                <th>Note Moy.</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($topCh as $i => $ch): ?>
            <tr>
                <td>
                    <span style="width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;
                          background:<?php echo $i===0?'linear-gradient(135deg,#f59e0b,#b45309)':($i===1?'linear-gradient(135deg,#94a3b8,#64748b)':($i===2?'linear-gradient(135deg,#cd7c2f,#92400e)':'rgba(245,158,11,.1)')); ?>;
                          color:<?php echo $i<3?'#fff':'var(--col-lt)'; ?>;">
                        <?php echo $i+1; ?>
                    </span>
                </td>
                <td style="font-weight:700;color:var(--text);"><?php echo htmlspecialchars($ch['username']); ?></td>
                <td style="font-family:monospace;font-size:.78rem;color:var(--muted);">#<?php echo htmlspecialchars($ch['numero_badge']); ?></td>
                <td><span class="badge b-<?php echo $ch['statut']; ?>"><?php echo ucfirst(str_replace('_',' ',$ch['statut'])); ?></span></td>
                <td style="font-weight:600;"><?php echo $ch['nb']; ?></td>
                <td style="font-weight:800;color:var(--col-lt);">$<?php echo number_format($ch['ca'],2); ?></td>
                <td style="color:#94a3b8;"><?php echo number_format($ch['km']??0,1); ?> km</td>
                <td>
                    <?php if ($ch['note']): ?>
                    <span class="stars" style="font-size:.75rem;"><?php echo str_repeat('★',round($ch['note'])); ?><?php echo str_repeat('☆',5-round($ch['note'])); ?></span>
                    <span style="font-size:.8rem;color:var(--col-lt);font-weight:600;margin-left:.25rem;"><?php echo number_format($ch['note'],1); ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($topCh)): ?>
            <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted);">Aucune donnée sur cette période</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>