<?php
/**
 * _log_qcm_engine.php — historique des tentatives QCM par faction.
 */
if (!isset($__ACADEMY_FACTION) || !academy_faction_exists($__ACADEMY_FACTION)) { http_response_code(404); exit('Faction invalide'); }
$__fk = $__ACADEMY_FACTION;
$__meta = academy_faction_meta($__fk);
$__slug = $__meta['slug'];
academy_require_access('log_qcm_' . $__slug);

$pageTitle  = 'Log QCM — ' . $__meta['name'];
$breadcrumb = [['label' => $__meta['name']], ['label' => 'Log QCM']];
$db = Database::getInstance()->getConnection();

$passedFilter = $_GET['passed'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT a.*, u.username, u.avatar FROM academy_qcm_attempts a LEFT JOIN users u ON u.id = a.user_id WHERE a.faction_key = ?';
$p = [$__fk];
if ($passedFilter === '1' || $passedFilter === '0') { $sql .= ' AND a.passed = ?'; $p[] = (int)$passedFilter; }
if ($q !== '') { $sql .= ' AND u.username LIKE ?'; $p[] = '%' . $q . '%'; }
$sql .= ' ORDER BY a.started_at DESC LIMIT 300';
try { $stmt = $db->prepare($sql); $stmt->execute($p); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $rows = []; }

// KPIs globaux
$total = $passed = $failed = 0; $sumPct = 0; $bestPct = 0;
try {
    $r = $db->prepare('SELECT COUNT(*) total, SUM(passed) passed, AVG(percentage) avg_pct, MAX(percentage) best FROM academy_qcm_attempts WHERE faction_key = ?');
    $r->execute([$__fk]);
    $k = $r->fetch(PDO::FETCH_ASSOC);
    if ($k) {
        $total   = (int)$k['total'];
        $passed  = (int)$k['passed'];
        $failed  = $total - $passed;
        $sumPct  = (int)round($k['avg_pct'] ?? 0);
        $bestPct = (int)round($k['best'] ?? 0);
    }
} catch (Exception $e) {}
$rate = $total > 0 ? (int)round($passed * 100 / $total) : 0;

[$ar,$ag,$ab] = sscanf($__meta['color'], "#%02x%02x%02x");
$facCss = "--fac-c:{$__meta['color']};--fac-c-15:rgba($ar,$ag,$ab,.10);--fac-c-25:rgba($ar,$ag,$ab,.25);";

include __DIR__ . '/header.php';
?>

<style><?php echo $facCss; ?></style>

<div class="page-header">
  <div class="page-header-left">
    <div class="ph-eyebrow"><i class="fa-solid fa-<?php echo $__meta['icon']; ?>"></i> <?php echo htmlspecialchars($__meta['name']); ?></div>
    <h1><span class="ph-ic" style="background:var(--fac-c-15);color:var(--fac-c)"><i class="fa-solid fa-clipboard-list"></i></span> Historique QCM</h1>
    <p>Tentatives, scores et taux de réussite des candidats.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/config_qcm_<?php echo $__slug; ?>.php" class="btn btn-secondary"><i class="fa-solid fa-sliders"></i> Configurer le QCM</a>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card stat-blue"><div class="stat-ic"><i class="fa-solid fa-list-check"></i></div><div class="stat-info">
    <div class="stat-label">Tentatives</div><div class="stat-value"><?php echo $total; ?></div>
    <div class="stat-meta">total</div>
  </div></div>
  <div class="stat-card stat-green"><div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div><div class="stat-info">
    <div class="stat-label">Réussites</div><div class="stat-value"><?php echo $passed; ?></div>
    <div class="stat-meta"><?php echo $rate; ?>% de réussite</div>
  </div></div>
  <div class="stat-card stat-red"><div class="stat-ic"><i class="fa-solid fa-circle-xmark"></i></div><div class="stat-info">
    <div class="stat-label">Échecs</div><div class="stat-value"><?php echo $failed; ?></div>
    <div class="stat-meta">à recommencer</div>
  </div></div>
  <div class="stat-card stat-orange"><div class="stat-ic"><i class="fa-solid fa-percent"></i></div><div class="stat-info">
    <div class="stat-label">Score moyen</div><div class="stat-value"><?php echo $sumPct; ?>%</div>
    <div class="stat-meta">meilleur : <?php echo $bestPct; ?>%</div>
  </div></div>
</div>

<form method="GET" class="toolbar">
  <div class="grow search-input">
    <i class="fa-solid fa-search"></i>
    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="Rechercher un candidat…">
  </div>
  <select name="passed" class="form-control" style="max-width:200px;height:36px" onchange="this.form.submit()">
    <option value="">Toutes les tentatives</option>
    <option value="1" <?php echo $passedFilter === '1' ? 'selected' : ''; ?>>Réussies uniquement</option>
    <option value="0" <?php echo $passedFilter === '0' ? 'selected' : ''; ?>>Échouées uniquement</option>
  </select>
  <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Filtrer</button>
  <?php if ($passedFilter !== '' || $q !== ''): ?>
  <a href="?" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Réinitialiser</a>
  <?php endif; ?>
</form>

<div class="card">
  <?php if (empty($rows)): ?>
  <div class="empty-state">
    <i class="fa-solid fa-circle-question"></i>
    <div class="es-title">Aucune tentative pour le moment</div>
    <p>Les résultats des QCM passés par les candidats apparaîtront ici.</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>#</th><th>Candidat</th><th>Score</th><th>Réussite</th><th>Statut</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $pct = (int)$r['percentage'];
        $barC = $r['passed'] ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
        $initial = strtoupper(mb_substr($r['username'] ?? 'U', 0, 1));
      ?>
      <tr>
        <td><span class="row-muted mono">#<?php echo str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT); ?></span></td>
        <td>
          <div class="usr">
            <div class="usr-ava">
              <?php if (!empty($r['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($r['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
              <?php else: echo $initial; endif; ?>
            </div>
            <div>
              <div class="row-strong"><?php echo htmlspecialchars($r['username'] ?? '—'); ?></div>
              <div class="row-muted">user #<?php echo (int)$r['user_id']; ?></div>
            </div>
          </div>
        </td>
        <td><span class="mono row-strong"><?php echo (int)$r['score']; ?> / <?php echo (int)$r['total']; ?></span></td>
        <td>
          <div style="display:flex;align-items:center;gap:10px;min-width:140px">
            <div style="flex:1;height:6px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden">
              <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo $barC; ?>;transition:width .4s"></div>
            </div>
            <span class="mono row-strong" style="font-size:.78rem;color:<?php echo $barC; ?>;min-width:38px;text-align:right"><?php echo $pct; ?>%</span>
          </div>
        </td>
        <td>
          <?php if ($r['passed']): ?>
          <span class="badge badge-green"><i class="fa-solid fa-check"></i> Réussi</span>
          <?php else: ?>
          <span class="badge badge-red"><i class="fa-solid fa-xmark"></i> Échoué</span>
          <?php endif; ?>
        </td>
        <td><span class="row-muted"><?php echo date('d/m/Y H:i', strtotime($r['started_at'])); ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
