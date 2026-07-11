<?php
// pages/staff/extensions/academy/formations.php — vue cross-faction des formations
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Formations';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Formations']];

$stats = [];
try {
    $r = $db->query("SELECT faction_key,
                       SUM(CASE WHEN uf.status='en_cours' THEN 1 ELSE 0 END) AS en_cours,
                       SUM(CASE WHEN uf.status='valide'   THEN 1 ELSE 0 END) AS valide,
                       SUM(CASE WHEN uf.status='refuse'   THEN 1 ELSE 0 END) AS refuse,
                       COUNT(DISTINCT f.id) AS formations
                FROM academy_formations f
                LEFT JOIN academy_user_formations uf ON uf.formation_id = f.id
                GROUP BY faction_key")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $row) $stats[$row['faction_key']] = $row;
} catch (Exception $e) {}

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1>Formations Académie — Vue globale</h1>
    <p>Récapitulatif des formations par faction. Créez/modifiez les formations dans le panel centralisé correspondant.</p>
  </div>
</div>

<div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> La création des formations se fait directement dans <strong>Académie Centralisée → [Faction] → Formations</strong>. Cette page offre une vue d'ensemble.</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
<?php foreach (academy_factions() as $fk => $m):
  $s = $stats[$fk] ?? ['formations'=>0,'en_cours'=>0,'valide'=>0,'refuse'=>0];
?>
<div class="card">
  <div class="card-header">
    <div class="card-title">
      <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $m['color']; ?>22;color:<?php echo $m['color']; ?>;display:grid;place-items:center"><i class="fa-solid fa-<?php echo $m['icon']; ?>"></i></div>
      <?php echo htmlspecialchars($m['name']); ?>
    </div>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px">
      <div style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800"><?php echo (int)$s['formations']; ?></div><div style="font-size:.65rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em">Formations</div></div>
      <div style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#93c5fd"><?php echo (int)$s['en_cours']; ?></div><div style="font-size:.65rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em">En cours</div></div>
      <div style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7"><?php echo (int)$s['valide']; ?></div><div style="font-size:.65rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em">Validés</div></div>
      <div style="text-align:center"><div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#fca5a5"><?php echo (int)$s['refuse']; ?></div><div style="font-size:.65rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em">Refusés</div></div>
    </div>
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/formations_<?php echo $m['slug']; ?>.php" class="btn btn-primary btn-sm" style="width:100%;justify-content:center"><i class="fa-solid fa-arrow-right"></i> Gérer</a>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
