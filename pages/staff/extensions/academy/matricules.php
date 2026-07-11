<?php
// pages/staff/extensions/academy/matricules.php — CRUD plages matricules par faction
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Matricules';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Matricules']];
$success = $error = '';

if (empty($_SESSION['staff_academy_csrf'])) $_SESSION['staff_academy_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['staff_academy_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $fk = (string)($_POST['faction_key'] ?? '');
            if (!academy_faction_exists($fk)) throw new Exception('Faction invalide');
            $min = max(0, (int)($_POST['range_min'] ?? 0));
            $max = max($min, (int)($_POST['range_max'] ?? 0));
            $pad = max(1, min(6, (int)($_POST['padding'] ?? 3)));
            $desc = trim((string)($_POST['description'] ?? ''));
            if ($max - $min > 999) throw new Exception('Plage trop large (max 1000 numéros par plage)');
            if ($id > 0) $db->prepare('UPDATE academy_matricule_ranges SET faction_key=?, range_min=?, range_max=?, padding=?, description=? WHERE id=?')
                             ->execute([$fk, $min, $max, $pad, $desc, $id]);
            else        $db->prepare('INSERT INTO academy_matricule_ranges (faction_key, range_min, range_max, padding, description) VALUES (?,?,?,?,?)')
                             ->execute([$fk, $min, $max, $pad, $desc]);
            academy_matricule_sync_slots($fk);
            $success = 'Plage enregistrée et slots matricules générés.';
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_matricule_ranges WHERE id = ?')->execute([$id]);
            $success = 'Plage supprimée (les matricules existants restent).';
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$ranges = $db->query('SELECT * FROM academy_matricule_ranges ORDER BY faction_key, range_min')->fetchAll(PDO::FETCH_ASSOC);
$stats = [];
try {
    $r = $db->query("SELECT faction_key,
                         COUNT(*) AS total,
                         SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS busy
                     FROM academy_matricules GROUP BY faction_key")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $row) $stats[$row['faction_key']] = $row;
} catch (Exception $e) {}

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1>Plages de matricules par faction</h1>
    <p>Définissez les intervalles (min–max) de matricules disponibles pour chaque faction.</p>
  </div>
  <div class="page-header-right">
    <button class="btn btn-primary" onclick="openRangeModal()"><i class="fa-solid fa-plus"></i> Nouvelle plage</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <div class="table-wrapper">
    <?php if (empty($ranges)): ?>
    <div class="empty-state"><i class="fa-solid fa-id-card"></i><p>Aucune plage configurée</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>Faction</th><th>Min</th><th>Max</th><th>Taille</th><th>Padding</th><th>Description</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($ranges as $r):
        $m = academy_faction_meta($r['faction_key']);
      ?>
      <tr>
        <td><span class="badge" style="background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>"><?php echo htmlspecialchars($m['name']); ?></span></td>
        <td class="mono"><?php echo str_pad((string)$r['range_min'], (int)$r['padding'], '0', STR_PAD_LEFT); ?></td>
        <td class="mono"><?php echo str_pad((string)$r['range_max'], (int)$r['padding'], '0', STR_PAD_LEFT); ?></td>
        <td><?php echo (int)$r['range_max'] - (int)$r['range_min'] + 1; ?></td>
        <td><?php echo (int)$r['padding']; ?></td>
        <td style="font-size:.8rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
        <td style="display:flex;gap:6px">
          <button class="btn btn-secondary btn-sm" onclick='openRangeModal(<?php echo json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-pen"></i></button>
          <form method="POST" onsubmit="return confirm('Supprimer la plage ?')">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
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

<div class="card">
  <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-column"></i> Utilisation par faction</div></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
    <?php foreach (academy_factions() as $fk => $m):
      $s = $stats[$fk] ?? ['total' => 0, 'busy' => 0];
      $pct = $s['total'] > 0 ? (int)round($s['busy'] * 100 / $s['total']) : 0;
    ?>
    <div style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:12px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <div style="width:24px;height:24px;border-radius:6px;background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>;display:grid;place-items:center;font-size:.75rem"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></div>
        <strong style="font-size:.85rem"><?php echo htmlspecialchars($m['name']); ?></strong>
      </div>
      <div style="font-size:.78rem;color:var(--text-secondary)"><?php echo (int)$s['busy']; ?> / <?php echo (int)$s['total']; ?> occupés (<?php echo $pct; ?>%)</div>
      <div style="height:5px;background:var(--border);border-radius:3px;margin-top:6px;overflow:hidden">
        <div style="height:100%;background:<?php echo $m['color']; ?>;width:<?php echo $pct; ?>%"></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="rangeModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px">
  <div class="modal" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:18px;width:100%;max-width:560px;padding:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px"><h3 id="rangeTitle">Nouvelle plage</h3><button class="btn btn-secondary btn-icon" onclick="closeRangeModal()"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
      <input type="hidden" name="action" id="rangeAction" value="create">
      <input type="hidden" name="id" id="rangeId" value="">
      <div class="form-group"><label class="form-label">Faction</label>
        <select name="faction_key" id="rangeFaction" class="form-control" required>
          <option value="">— Choisir —</option>
          <?php foreach (academy_factions() as $fk => $m): ?><option value="<?php echo $fk; ?>"><?php echo htmlspecialchars($m['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Min</label><input type="number" name="range_min" id="rangeMin" class="form-control" min="0" required></div>
        <div class="form-group"><label class="form-label">Max</label><input type="number" name="range_max" id="rangeMax" class="form-control" min="0" required></div>
        <div class="form-group"><label class="form-label">Padding</label><input type="number" name="padding" id="rangePad" class="form-control" min="1" max="6" value="3"></div>
      </div>
      <div class="form-group"><label class="form-label">Description (optionnel)</label><input type="text" name="description" id="rangeDesc" class="form-control" maxlength="255"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end"><button type="button" class="btn btn-secondary" onclick="closeRangeModal()">Annuler</button><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Enregistrer</button></div>
    </form>
  </div>
</div>

<script>
function openRangeModal(r){
  document.getElementById('rangeAction').value = r ? 'update' : 'create';
  document.getElementById('rangeId').value = r ? r.id : '';
  document.getElementById('rangeTitle').textContent = r ? 'Modifier la plage' : 'Nouvelle plage';
  document.getElementById('rangeFaction').value = r ? r.faction_key : '';
  document.getElementById('rangeMin').value = r ? r.range_min : '';
  document.getElementById('rangeMax').value = r ? r.range_max : '';
  document.getElementById('rangePad').value = r ? r.padding : 3;
  document.getElementById('rangeDesc').value = r ? (r.description || '') : '';
  document.getElementById('rangeModal').style.display = 'grid';
}
function closeRangeModal(){ document.getElementById('rangeModal').style.display = 'none'; }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
