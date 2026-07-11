<?php
// pages/staff/extensions/academy/qcm.php — paramètres QCM par faction
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — QCM';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'QCM']];
$success = $error = '';

if (empty($_SESSION['staff_academy_csrf'])) $_SESSION['staff_academy_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['staff_academy_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    try {
        $fk = (string)($_POST['faction_key'] ?? '');
        if (!academy_faction_exists($fk)) throw new Exception('Faction invalide');
        $n = max(1, min(100, (int)($_POST['num_questions'] ?? 10)));
        $pass = max(0, min(100, (int)($_POST['passing_score'] ?? 60)));
        $dur = max(1, min(180, (int)($_POST['duration_minutes'] ?? 30)));
        $rand = !empty($_POST['randomize']) ? 1 : 0;
        $act  = !empty($_POST['is_active']) ? 1 : 0;
        $db->prepare('INSERT INTO academy_qcm_config (faction_key, num_questions, passing_score, duration_minutes, randomize, is_active) VALUES (?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE num_questions=VALUES(num_questions), passing_score=VALUES(passing_score), duration_minutes=VALUES(duration_minutes), randomize=VALUES(randomize), is_active=VALUES(is_active)')
           ->execute([$fk, $n, $pass, $dur, $rand, $act]);
        $success = 'Configuration QCM mise à jour pour ' . $fk . '.';
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$configs = [];
try {
    $r = $db->query('SELECT * FROM academy_qcm_config')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $row) $configs[$row['faction_key']] = $row;
} catch (Exception $e) {}

$stats = [];
try {
    $r = $db->query('SELECT faction_key, COUNT(*) AS questions FROM academy_qcm_questions WHERE is_active = 1 GROUP BY faction_key')->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats = $r;
} catch (Exception $e) {}

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1>Paramètres QCM par faction</h1>
    <p>Définissez le nombre de questions, la durée et le score minimum pour chaque QCM.</p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px">
<?php foreach (academy_factions() as $fk => $m):
  $cfg = $configs[$fk] ?? ['num_questions'=>10,'passing_score'=>60,'duration_minutes'=>30,'randomize'=>1,'is_active'=>1];
  $qCount = (int)($stats[$fk] ?? 0);
?>
<div class="card">
  <div class="card-header">
    <div class="card-title">
      <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>;display:grid;place-items:center"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></div>
      <?php echo htmlspecialchars($m['name']); ?>
    </div>
    <span class="badge badge-slate"><?php echo $qCount; ?> question(s)</span>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
      <input type="hidden" name="faction_key" value="<?php echo $fk; ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group"><label class="form-label">Nb questions</label><input type="number" name="num_questions" class="form-control" min="1" max="100" value="<?php echo (int)$cfg['num_questions']; ?>"></div>
        <div class="form-group"><label class="form-label">Score min %</label><input type="number" name="passing_score" class="form-control" min="0" max="100" value="<?php echo (int)$cfg['passing_score']; ?>"></div>
        <div class="form-group"><label class="form-label">Durée (min)</label><input type="number" name="duration_minutes" class="form-control" min="1" max="180" value="<?php echo (int)$cfg['duration_minutes']; ?>"></div>
        <div class="form-group" style="display:flex;flex-direction:column;gap:6px;padding-top:22px">
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="randomize" <?php echo !empty($cfg['randomize']) ? 'checked' : ''; ?>> Questions aléatoires</label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" <?php echo !empty($cfg['is_active']) ? 'checked' : ''; ?>> QCM actif</label>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:6px">
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/config_qcm_<?php echo $m['slug']; ?>.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> Banque de questions</a>
        <button class="btn btn-primary btn-sm" style="margin-left:auto"><i class="fa-solid fa-check"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
