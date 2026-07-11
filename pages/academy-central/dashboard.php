<?php
require_once __DIR__ . '/../../config/config.php';
academy_require_central_entry();

$pageTitle  = 'Académie Centralisée — Dashboard';
$breadcrumb = [['label' => 'Dashboard']];
$db  = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];
$accessible = academy_user_accessible_factions($uid);

$kpis = ['pending' => 0, 'in_progress' => 0, 'exam' => 0, 'qcm' => 0, 'qcm_pass' => 0, 'formations_active' => 0, 'matricules_busy' => 0, 'matricules_total' => 0];
$byFaction = [];
try {
    if (!empty($accessible)) {
        $ph = implode(',', array_fill(0, count($accessible), '?'));
        $s = $db->prepare("SELECT faction_key, status, COUNT(*) AS n FROM academy_candidatures WHERE faction_key IN ($ph) GROUP BY faction_key, status");
        $s->execute($accessible);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byFaction[$r['faction_key']]['cand'][$r['status']] = (int)$r['n'];
            if ($r['status'] === 'en_attente') $kpis['pending']     += (int)$r['n'];
            if ($r['status'] === 'en_cours')   $kpis['in_progress'] += (int)$r['n'];
            if ($r['status'] === 'en_examen')  $kpis['exam']        += (int)$r['n'];
        }
        $s = $db->prepare("SELECT COUNT(*) total, SUM(passed) ok FROM academy_qcm_attempts WHERE faction_key IN ($ph)");
        $s->execute($accessible);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) { $kpis['qcm'] = (int)$row['total']; $kpis['qcm_pass'] = (int)$row['ok']; }
        $s = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM academy_user_formations uf JOIN academy_formations f ON f.id = uf.formation_id WHERE f.faction_key IN ($ph) AND uf.status = 'en_cours'");
        $s->execute($accessible);
        $kpis['formations_active'] = (int)$s->fetchColumn();
        $s = $db->prepare("SELECT faction_key, COUNT(*) tot, SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) busy FROM academy_matricules WHERE faction_key IN ($ph) GROUP BY faction_key");
        $s->execute($accessible);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byFaction[$r['faction_key']]['mat'] = ['total' => (int)$r['tot'], 'busy' => (int)$r['busy']];
            $kpis['matricules_total'] += (int)$r['tot'];
            $kpis['matricules_busy']  += (int)$r['busy'];
        }
    }
} catch (Exception $e) {}

$recent = [];
try {
    if (!empty($accessible)) {
        $ph = implode(',', array_fill(0, count($accessible), '?'));
        $s = $db->prepare("SELECT c.*, u.username, u.avatar FROM academy_candidatures c LEFT JOIN users u ON u.id = c.user_id WHERE c.faction_key IN ($ph) ORDER BY c.created_at DESC LIMIT 10");
        $s->execute($accessible);
        $recent = $s->fetchAll(PDO::FETCH_ASSOC);
        $s = $db->prepare("SELECT a.*, u.username FROM academy_qcm_attempts a LEFT JOIN users u ON u.id = a.user_id WHERE a.faction_key IN ($ph) ORDER BY a.started_at DESC LIMIT 6");
        $s->execute($accessible);
        $recentQcm = $s->fetchAll(PDO::FETCH_ASSOC);
    } else { $recentQcm = []; }
} catch (Exception $e) { $recentQcm = []; }

$qcmRate = $kpis['qcm'] > 0 ? (int)round($kpis['qcm_pass'] * 100 / $kpis['qcm']) : 0;
$matRate = $kpis['matricules_total'] > 0 ? (int)round($kpis['matricules_busy'] * 100 / $kpis['matricules_total']) : 0;

include __DIR__ . '/includes/header.php';
$statuses = academy_candidature_statuses();
?>

<style>
.fac-tile{position:relative;padding:14px 16px;background:var(--bg-card);border:1px solid var(--border);border-radius:13px;display:block;text-decoration:none;transition:all .2s;overflow:hidden}
.fac-tile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--fc,#8b5cf6)}
.fac-tile:hover{transform:translateY(-2px);border-color:var(--border-hover)}
.fac-tile .ft-head{display:flex;align-items:center;gap:11px;margin-bottom:10px}
.fac-tile .ft-ic{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-size:.95rem;color:var(--fc);background:var(--fc15,rgba(139,92,246,.1))}
.fac-tile .ft-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;color:#fff;flex:1}
.fac-tile .ft-arrow{color:var(--text-secondary);font-size:.78rem;transition:transform .15s}
.fac-tile:hover .ft-arrow{transform:translateX(3px);color:#fff}
.fac-tile .ft-stats{display:flex;gap:6px;flex-wrap:wrap}
.fac-tile .ft-stats .badge{font-size:.66rem}
.fac-tile .ft-mat{margin-top:9px;padding-top:9px;border-top:1px solid var(--border);font-size:.72rem;color:var(--text-secondary);display:flex;align-items:center;gap:8px}
.fac-tile .ft-mat .pb{flex:1;height:5px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden}
.fac-tile .ft-mat .pb i{display:block;height:100%;background:linear-gradient(90deg,#10b981,#f59e0b)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-building-columns"></i> Académie Centralisée</div>
    <h1><span class="ph-ic"><i class="fa-solid fa-gauge-high"></i></span> Dashboard</h1>
    <p>Vue d'ensemble des candidatures, QCM, formations et matricules sur les factions auxquelles vous avez accès.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php" class="btn btn-secondary"><i class="fa-solid fa-comments"></i> Forums</a>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card stat-yellow"><div class="stat-ic"><i class="fa-solid fa-clock"></i></div><div class="stat-info">
    <div class="stat-label">À traiter</div><div class="stat-value"><?php echo $kpis['pending']; ?></div>
    <div class="stat-meta">candidatures en attente</div>
  </div></div>
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-hourglass-half"></i></div><div class="stat-info">
    <div class="stat-label">En cours</div><div class="stat-value"><?php echo $kpis['in_progress']; ?></div>
    <div class="stat-meta">en revue</div>
  </div></div>
  <div class="stat-card" style="--stat-c:#c4b5fd;--stat-bg:rgba(139,92,246,.14)"><div class="stat-ic"><i class="fa-solid fa-clipboard-check"></i></div><div class="stat-info">
    <div class="stat-label">En examen</div><div class="stat-value"><?php echo $kpis['exam']; ?></div>
    <div class="stat-meta">QCM débloqué</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-circle-question"></i></div><div class="stat-info">
    <div class="stat-label">QCM passés</div><div class="stat-value"><?php echo $kpis['qcm']; ?></div>
    <div class="stat-meta"><?php echo $qcmRate; ?>% de réussite</div>
  </div></div>
  <div class="stat-card stat-cyan"><div class="stat-ic"><i class="fa-solid fa-chalkboard-user"></i></div><div class="stat-info">
    <div class="stat-label">Formations actives</div><div class="stat-value"><?php echo $kpis['formations_active']; ?></div>
    <div class="stat-meta">stagiaires en cours</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-id-card"></i></div><div class="stat-info">
    <div class="stat-label">Matricules</div><div class="stat-value"><?php echo $kpis['matricules_busy']; ?>/<?php echo $kpis['matricules_total']; ?></div>
    <div class="stat-meta"><?php echo $matRate; ?>% d'occupation</div>
  </div></div>
</div>

<div class="grid-2" style="gap:18px;align-items:flex-start">

  <!-- COL 1 : Recentes candidatures -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-rectangle-list"></i> Dernières candidatures</div>
      <span class="badge badge-purple"><?php echo count($recent); ?></span>
    </div>
    <div class="table-wrapper">
      <?php if (empty($recent)): ?>
      <div class="empty-state">
        <i class="fa-solid fa-inbox"></i>
        <div class="es-title">Aucune candidature récente</div>
        <p>Les nouvelles candidatures apparaîtront ici.</p>
      </div>
      <?php else: ?>
      <table>
        <thead><tr><th>Candidat</th><th>Faction</th><th>Statut</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $c):
          $m = academy_faction_meta($c['faction_key']);
          $st = $statuses[$c['status']] ?? null;
          $initial = strtoupper(mb_substr($c['username'] ?? 'U', 0, 1));
        ?>
        <tr>
          <td>
            <div class="usr">
              <div class="usr-ava">
                <?php if (!empty($c['avatar'])): ?>
                  <img src="<?php echo htmlspecialchars($c['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
                <?php else: echo $initial; endif; ?>
              </div>
              <div class="row-strong"><?php echo htmlspecialchars($c['username'] ?? '—'); ?></div>
            </div>
          </td>
          <td><span class="badge" style="background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i> <?php echo htmlspecialchars($m['name']); ?></span></td>
          <td><?php if ($st): ?><span class="badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>"><i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo $st['label']; ?></span><?php endif; ?></td>
          <td><span class="row-muted"><?php echo date('d/m H:i', strtotime($c['created_at'])); ?></span></td>
          <td>
            <?php if (academy_user_can($uid, 'log_candidature_' . $m['slug'])): ?>
            <a href="<?php echo SITE_URL; ?>/pages/academy-central/log_candidature_<?php echo $m['slug']; ?>.php" class="btn btn-secondary btn-sm" title="Voir"><i class="fa-solid fa-arrow-right"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- COL 2 : Recentes tentatives QCM -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fa-solid fa-clipboard-list"></i> Dernières tentatives QCM</div>
      <span class="badge badge-purple"><?php echo count($recentQcm); ?></span>
    </div>
    <div class="table-wrapper">
      <?php if (empty($recentQcm)): ?>
      <div class="empty-state">
        <i class="fa-solid fa-circle-question"></i>
        <div class="es-title">Aucune tentative</div>
        <p>Les résultats des QCM s'afficheront ici.</p>
      </div>
      <?php else: ?>
      <table>
        <thead><tr><th>Candidat</th><th>Faction</th><th>%</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($recentQcm as $a):
          $m = academy_faction_meta($a['faction_key']);
          $pct = (int)$a['percentage'];
          $barC = $a['passed'] ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
          $initial = strtoupper(mb_substr($a['username'] ?? 'U', 0, 1));
        ?>
        <tr>
          <td><div class="usr"><div class="usr-ava"><?php echo $initial; ?></div><div class="row-strong"><?php echo htmlspecialchars($a['username'] ?? '—'); ?></div></div></td>
          <td><span class="badge" style="background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>;font-size:.65rem"><?php echo htmlspecialchars($m['slug']); ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;min-width:90px">
              <div style="flex:1;height:5px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden">
                <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo $barC; ?>"></div>
              </div>
              <span class="mono row-strong" style="font-size:.78rem;color:<?php echo $barC; ?>"><?php echo $pct; ?>%</span>
            </div>
          </td>
          <td><?php echo $a['passed'] ? '<span class="badge badge-green"><i class="fa-solid fa-check"></i></span>' : '<span class="badge badge-red"><i class="fa-solid fa-xmark"></i></span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Section Mes Factions -->
<div class="card" style="margin-top:18px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-building-columns"></i> Mes factions</div>
    <span class="badge badge-purple"><?php echo count($accessible); ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($accessible)): ?>
    <div class="empty-state">
      <i class="fa-solid fa-lock"></i>
      <div class="es-title">Aucune faction accessible</div>
      <p>Vous n'avez pas encore de grade académie. Demandez à un administrateur d'en attribuer un.</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
    <?php foreach ($accessible as $fk):
      $m = academy_faction_meta($fk);
      $d = $byFaction[$fk]['cand'] ?? [];
      $tot = array_sum($d);
      $mat = $byFaction[$fk]['mat'] ?? null;
      [$fr,$fg,$fb] = sscanf($m['color'], "#%02x%02x%02x");
      $facStyle = "--fc:{$m['color']};--fc15:rgba($fr,$fg,$fb,.10)";
      $primaryFile = academy_user_can($uid, 'log_candidature_' . $m['slug']) ? 'log_candidature_' . $m['slug'] : (academy_user_can($uid, 'effectifs_' . $m['slug']) ? 'effectifs_' . $m['slug'] : 'matricules_' . $m['slug']);
    ?>
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/<?php echo $primaryFile; ?>.php" class="fac-tile" style="<?php echo $facStyle; ?>">
      <div class="ft-head">
        <div class="ft-ic"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></div>
        <div class="ft-name"><?php echo htmlspecialchars($m['name']); ?></div>
        <i class="fa-solid fa-arrow-right ft-arrow"></i>
      </div>
      <div class="ft-stats">
        <?php if ($tot === 0): ?>
        <span class="badge badge-slate">Aucune candidature</span>
        <?php else: foreach ($statuses as $sk => $sm):
          $n = (int)($d[$sk] ?? 0); if ($n === 0) continue;
        ?>
        <span class="badge" style="background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>"><i class="fa-solid fa-<?php echo $sm['icon']; ?>"></i> <?php echo $n; ?></span>
        <?php endforeach; endif; ?>
      </div>
      <?php if ($mat && $mat['total'] > 0): $rate = (int)round($mat['busy'] * 100 / $mat['total']); ?>
      <div class="ft-mat">
        <i class="fa-solid fa-id-card" style="color:var(--fc)"></i>
        <span><?php echo $mat['busy']; ?>/<?php echo $mat['total']; ?></span>
        <div class="pb"><i style="width:<?php echo $rate; ?>%"></i></div>
        <span class="mono"><?php echo $rate; ?>%</span>
      </div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
