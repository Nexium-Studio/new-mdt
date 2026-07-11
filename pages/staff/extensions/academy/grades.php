<?php
// pages/staff/extensions/academy/grades.php — CRUD grades + assignations + permissions pages
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Grades';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Grades']];
$success = $error = '';

if (empty($_SESSION['staff_academy_csrf'])) $_SESSION['staff_academy_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['staff_academy_csrf'];

$PAGE_KEYS = [
    'dashboard' => 'Dashboard',
    'forums'    => 'Forums',
];
foreach (academy_factions() as $fk => $m) {
    $slug = $m['slug'];
    foreach (['log_candidature','log_qcm','config_qcm','config_candidature','effectifs','matricules','formations','documents'] as $t) {
        $PAGE_KEYS[$t . '_' . $slug] = $m['name'] . ' · ' . ucfirst(str_replace('_', ' ', $t));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $fk = $_POST['faction_key'] ?? '';
            if ($fk !== '' && !academy_faction_exists($fk)) throw new Exception('Faction invalide');
            $gk = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string)($_POST['grade_key'] ?? ''))));
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#8b5cf6';
            $icon = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['icon'] ?? 'graduation-cap'));
            $order = (int)($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($gk === '' || $name === '') throw new Exception('Clé et nom requis');
            if ($action === 'update' && $id > 0) {
                $db->prepare('UPDATE academy_grades SET faction_key=?, grade_key=?, name=?, description=?, color=?, icon=?, sort_order=?, is_active=? WHERE id=?')
                   ->execute([$fk ?: null, $gk, $name, $desc, $color, $icon, $order, $active, $id]);
                $gid = $id;
            } else {
                $db->prepare('INSERT INTO academy_grades (faction_key, grade_key, name, description, color, icon, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$fk ?: null, $gk, $name, $desc, $color, $icon, $order, $active]);
                $gid = (int)$db->lastInsertId();
            }
            // Permissions pages
            $pages = $_POST['pages'] ?? [];
            $db->prepare('DELETE FROM academy_grade_page_access WHERE grade_id = ?')->execute([$gid]);
            if (is_array($pages)) {
                $ins = $db->prepare('INSERT INTO academy_grade_page_access (grade_id, page_key, can_view, can_edit) VALUES (?,?,?,?)');
                foreach ($pages as $pk => $flags) {
                    if (!array_key_exists($pk, $PAGE_KEYS)) continue;
                    $canView = !empty($flags['view']) ? 1 : 0;
                    $canEdit = !empty($flags['edit']) ? 1 : 0;
                    if ($canView || $canEdit) $ins->execute([$gid, $pk, $canView, $canEdit]);
                }
            }
            $success = 'Grade enregistré.';
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_grades WHERE id = ?')->execute([$id]);
            $success = 'Grade supprimé.';
        }
        if ($action === 'assign') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $gid = (int)($_POST['grade_id'] ?? 0);
            if ($uid > 0 && $gid > 0) {
                $db->prepare('INSERT IGNORE INTO academy_user_grades (user_id, grade_id, assigned_by) VALUES (?,?,?)')
                   ->execute([$uid, $gid, (int)$_SESSION['user_id']]);
                $success = 'Grade assigné.';
            }
        }
        if ($action === 'unassign') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_user_grades WHERE id = ?')->execute([$id]);
            $success = 'Assignation retirée.';
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$grades = $db->query('SELECT g.*, (SELECT COUNT(*) FROM academy_user_grades WHERE grade_id=g.id) AS user_count FROM academy_grades g ORDER BY g.faction_key IS NULL DESC, g.faction_key, g.sort_order')->fetchAll(PDO::FETCH_ASSOC);
$perms = $db->query('SELECT * FROM academy_grade_page_access')->fetchAll(PDO::FETCH_ASSOC);
$permsByGrade = [];
foreach ($perms as $p) $permsByGrade[(int)$p['grade_id']][$p['page_key']] = $p;

$users = $db->query("SELECT id, username, faction, role FROM users WHERE role != 'Invité' ORDER BY username LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$assigns = $db->query('SELECT aug.id, aug.user_id, aug.grade_id, aug.assigned_at, u.username, g.name AS grade_name, g.color AS grade_color, g.faction_key FROM academy_user_grades aug LEFT JOIN users u ON u.id=aug.user_id LEFT JOIN academy_grades g ON g.id=aug.grade_id ORDER BY u.username, g.name')->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1>Gestion des grades Académie</h1>
    <p>Créez des grades par faction (ou inter-factions), définissez leurs droits d'accès page par page, puis assignez-les aux utilisateurs.</p>
  </div>
  <div class="page-header-right">
    <button class="btn btn-primary" onclick="openGradeModal()"><i class="fa-solid fa-plus"></i> Nouveau grade</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

<div class="grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:flex-start">
  <!-- GRADES -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-graduation-cap"></i> Grades existants</div></div>
    <div class="table-wrapper">
      <?php if (empty($grades)): ?>
      <div class="empty-state"><i class="fa-solid fa-graduation-cap"></i><p>Aucun grade</p></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Grade</th><th>Faction</th><th>Users</th><th>Pages</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grades as $g):
          $fm = $g['faction_key'] ? academy_faction_meta($g['faction_key']) : null;
          $pCount = count($permsByGrade[(int)$g['id']] ?? []);
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:28px;height:28px;border-radius:7px;background:<?php echo $g['color']; ?>22;color:<?php echo $g['color']; ?>;display:grid;place-items:center"><i class="fa-solid fa-<?php echo htmlspecialchars($g['icon']); ?>"></i></div>
              <div>
                <div style="font-weight:700"><?php echo htmlspecialchars($g['name']); ?></div>
                <div class="mono" style="font-size:.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($g['grade_key']); ?></div>
              </div>
            </div>
          </td>
          <td>
            <?php if ($fm): ?><span class="badge" style="background:<?php echo $fm['color']; ?>22;color:<?php echo $fm['color']; ?>"><?php echo htmlspecialchars($fm['name']); ?></span>
            <?php else: ?><span class="badge badge-purple">Inter-factions</span><?php endif; ?>
          </td>
          <td><?php echo (int)$g['user_count']; ?></td>
          <td><?php echo $pCount; ?></td>
          <td style="display:flex;gap:4px">
            <button class="btn btn-secondary btn-sm" onclick='openGradeModal(<?php echo json_encode($g, JSON_HEX_APOS|JSON_HEX_QUOT); ?>, <?php echo json_encode($permsByGrade[(int)$g['id']] ?? new stdClass(), JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce grade ?')">
              <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
              <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ASSIGNATIONS -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-user-plus"></i> Attribuer un grade</div></div>
    <div class="card-body">
      <form method="POST" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;margin-bottom:16px">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="assign">
        <select name="user_id" class="form-control" required>
          <option value="">— Utilisateur —</option>
          <?php foreach ($users as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?> · <?php echo htmlspecialchars($u['role']); ?></option><?php endforeach; ?>
        </select>
        <select name="grade_id" class="form-control" required>
          <option value="">— Grade —</option>
          <?php foreach ($grades as $g): ?><option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?> <?php echo $g['faction_key'] ? '(' . htmlspecialchars($g['faction_key']) . ')' : '(Inter)'; ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i></button>
      </form>
      <div style="max-height:420px;overflow-y:auto">
      <?php if (empty($assigns)): ?>
      <div class="empty-state"><i class="fa-solid fa-user-plus"></i><p>Aucune assignation</p></div>
      <?php else: ?>
      <table>
        <thead><tr><th>User</th><th>Grade</th><th>Le</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assigns as $a): ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($a['username'] ?? '—'); ?></strong></td>
          <td><span class="badge" style="background:<?php echo $a['grade_color']; ?>22;color:<?php echo $a['grade_color']; ?>"><?php echo htmlspecialchars($a['grade_name']); ?></span></td>
          <td style="font-size:.78rem;color:var(--text-secondary)"><?php echo date('d/m/Y', strtotime($a['assigned_at'])); ?></td>
          <td>
            <form method="POST" style="display:inline" onsubmit="return confirm('Retirer cette assignation ?')">
              <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
              <input type="hidden" name="action" value="unassign">
              <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
              <button class="btn btn-danger btn-sm btn-icon"><i class="fa-solid fa-xmark"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- MODAL GRADE -->
<div class="modal-overlay" id="gradeModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px">
  <div class="modal" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:18px;width:100%;max-width:900px;padding:24px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
      <h3 id="gradeModalTitle">Nouveau grade</h3>
      <button class="btn btn-secondary btn-icon" onclick="closeGradeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="gradeForm">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
      <input type="hidden" name="action" id="gradeAction" value="create">
      <input type="hidden" name="id" id="gradeId" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group"><label class="form-label">Faction (laisser vide = inter-factions)</label>
          <select name="faction_key" id="gradeFaction" class="form-control">
            <option value="">— Inter-factions —</option>
            <?php foreach (academy_factions() as $fk => $fm): ?><option value="<?php echo $fk; ?>"><?php echo htmlspecialchars($fm['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Clé (majuscules, ex: INSTRUCTEUR)</label><input type="text" name="grade_key" id="gradeKey" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Nom affiché</label><input type="text" name="name" id="gradeName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Ordre</label><input type="number" name="sort_order" id="gradeOrder" class="form-control" value="0"></div>
        <div class="form-group"><label class="form-label">Couleur</label><input type="color" name="color" id="gradeColor" class="form-control" value="#8b5cf6"></div>
        <div class="form-group"><label class="form-label">Icône (FontAwesome)</label><input type="text" name="icon" id="gradeIcon" class="form-control" value="graduation-cap"></div>
      </div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="gradeDesc" class="form-control" rows="2"></textarea></div>
      <div class="form-group"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" id="gradeActive" checked> Grade actif</label></div>

      <div style="margin-top:16px">
        <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;margin-bottom:8px"><i class="fa-solid fa-shield"></i> Permissions par page</div>
        <div style="max-height:320px;overflow-y:auto;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:10px">
          <table style="width:100%;font-size:.82rem">
            <thead><tr><th style="text-align:left;padding:6px">Page</th><th style="text-align:center;padding:6px;width:60px">Voir</th><th style="text-align:center;padding:6px;width:60px">Éditer</th></tr></thead>
            <tbody>
            <?php foreach ($PAGE_KEYS as $pk => $lab): ?>
            <tr>
              <td style="padding:4px 6px;color:var(--text-primary)"><?php echo htmlspecialchars($lab); ?> <span class="mono" style="font-size:.7rem;color:var(--text-secondary)"><?php echo $pk; ?></span></td>
              <td style="text-align:center;padding:4px 6px"><input type="checkbox" name="pages[<?php echo $pk; ?>][view]" data-pk="<?php echo $pk; ?>" data-flag="view" value="1"></td>
              <td style="text-align:center;padding:4px 6px"><input type="checkbox" name="pages[<?php echo $pk; ?>][edit]" data-pk="<?php echo $pk; ?>" data-flag="edit" value="1"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
        <button type="button" class="btn btn-secondary" onclick="closeGradeModal()">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openGradeModal(g, perms){
  document.getElementById('gradeAction').value = g ? 'update' : 'create';
  document.getElementById('gradeId').value = g ? g.id : '';
  document.getElementById('gradeModalTitle').textContent = g ? 'Modifier le grade' : 'Nouveau grade';
  document.getElementById('gradeFaction').value = g ? (g.faction_key || '') : '';
  document.getElementById('gradeKey').value = g ? g.grade_key : '';
  document.getElementById('gradeName').value = g ? g.name : '';
  document.getElementById('gradeOrder').value = g ? g.sort_order : 0;
  document.getElementById('gradeColor').value = g ? g.color : '#8b5cf6';
  document.getElementById('gradeIcon').value = g ? g.icon : 'graduation-cap';
  document.getElementById('gradeDesc').value = g ? (g.description || '') : '';
  document.getElementById('gradeActive').checked = g ? !!+g.is_active : true;
  document.querySelectorAll('#gradeForm input[type=checkbox][data-pk]').forEach(cb => {
    const pk = cb.dataset.pk, fl = cb.dataset.flag;
    const val = (perms && perms[pk]) ? !!+perms[pk]['can_' + fl] : false;
    cb.checked = val;
  });
  document.getElementById('gradeModal').style.display = 'grid';
}
function closeGradeModal(){ document.getElementById('gradeModal').style.display = 'none'; }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
