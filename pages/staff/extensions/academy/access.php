<?php
// pages/staff/extensions/academy/access.php — vue alternative des permissions (par page → grades)
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Accès';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Accès']];

$grades = $db->query('SELECT * FROM academy_grades ORDER BY faction_key IS NULL DESC, faction_key, sort_order')->fetchAll(PDO::FETCH_ASSOC);
$perms = $db->query('SELECT * FROM academy_grade_page_access')->fetchAll(PDO::FETCH_ASSOC);

$byPage = [];
foreach ($perms as $p) $byPage[$p['page_key']][] = $p;

$PAGE_KEYS = [
    'dashboard' => ['label' => 'Dashboard Académie centralisée', 'icon' => 'gauge-high'],
    'forums'    => ['label' => 'Forums',                          'icon' => 'comments'],
];
foreach (academy_factions() as $fk => $m) {
    $slug = $m['slug'];
    $PAGE_KEYS['log_candidature_' . $slug]    = ['label' => 'Candidatures — ' . $m['name'],     'icon' => 'file-signature',    'faction' => $fk];
    $PAGE_KEYS['log_qcm_' . $slug]            = ['label' => 'Log QCM — ' . $m['name'],          'icon' => 'clipboard-list',    'faction' => $fk];
    $PAGE_KEYS['config_qcm_' . $slug]         = ['label' => 'Config QCM — ' . $m['name'],       'icon' => 'circle-question',   'faction' => $fk];
    $PAGE_KEYS['config_candidature_' . $slug] = ['label' => 'Config candidature — ' . $m['name'],'icon' => 'sliders',          'faction' => $fk];
    $PAGE_KEYS['effectifs_' . $slug]          = ['label' => 'Effectifs — ' . $m['name'],        'icon' => 'users',             'faction' => $fk];
    $PAGE_KEYS['matricules_' . $slug]         = ['label' => 'Matricules — ' . $m['name'],       'icon' => 'id-card',           'faction' => $fk];
    $PAGE_KEYS['formations_' . $slug]         = ['label' => 'Formations — ' . $m['name'],       'icon' => 'chalkboard-user',   'faction' => $fk];
    $PAGE_KEYS['documents_' . $slug]          = ['label' => 'Documents — ' . $m['name'],        'icon' => 'folder-open',       'faction' => $fk];
}

$gradesById = [];
foreach ($grades as $g) $gradesById[(int)$g['id']] = $g;

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1>Accès Académie par page</h1>
    <p>Vue inverse : pour chaque page du panel Centralisé, la liste des grades qui y ont accès.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/grades.php" class="btn btn-secondary"><i class="fa-solid fa-graduation-cap"></i> Gérer les grades</a>
  </div>
</div>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Page</th><th>Faction</th><th>Grades avec accès</th></tr></thead>
      <tbody>
      <?php foreach ($PAGE_KEYS as $pk => $info):
        $list = $byPage[$pk] ?? [];
        $fm = !empty($info['faction']) ? academy_faction_meta($info['faction']) : null;
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:26px;height:26px;border-radius:6px;background:rgba(139,92,246,.14);color:#c4b5fd;display:grid;place-items:center"><i class="fa-solid fa-<?php echo $info['icon']; ?>"></i></div>
            <div>
              <div style="font-weight:700;font-size:.88rem"><?php echo htmlspecialchars($info['label']); ?></div>
              <div class="mono" style="font-size:.7rem;color:var(--text-secondary)"><?php echo $pk; ?></div>
            </div>
          </div>
        </td>
        <td>
          <?php if ($fm): ?><span class="badge" style="background:<?php echo $fm['color']; ?>22;color:<?php echo $fm['color']; ?>"><?php echo htmlspecialchars($fm['name']); ?></span>
          <?php else: ?><span class="badge badge-purple">Global</span><?php endif; ?>
        </td>
        <td>
          <?php if (empty($list)): ?>
          <span style="color:var(--text-secondary);font-size:.8rem">Aucun grade</span>
          <?php else: foreach ($list as $p):
            $g = $gradesById[(int)$p['grade_id']] ?? null; if (!$g) continue;
          ?>
          <span class="badge" style="background:<?php echo $g['color']; ?>22;color:<?php echo $g['color']; ?>;margin-right:4px">
            <i class="fa-solid fa-<?php echo $g['icon']; ?>"></i> <?php echo htmlspecialchars($g['name']); ?>
            <?php echo $p['can_edit'] ? '· <i class="fa-solid fa-pen" style="font-size:.7em"></i>' : ''; ?>
          </span>
          <?php endforeach; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
