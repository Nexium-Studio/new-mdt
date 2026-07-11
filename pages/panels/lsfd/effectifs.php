<?php
/**
 * §2 — Effectifs LSFD (vue de consultation)
 * La gestion RH (recrutement, promotions, sanctions, matricules, planning) est
 * assurée par l'Académie Centrale. Cette page affiche le registre des agents et
 * lie vers les modules academy-central pour toute action.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();

// Hiérarchie de référence (cahier des charges)
$gradeRef = ['Pompier Stagiaire','Pompier','Pompier 1re classe','Caporal','Sergent','Lieutenant','Capitaine','Bataillon Chief','Deputy Chief','Fire Chief'];

// Grades réels depuis la DB (rank_order ASC) ; fallback sur la référence.
$grades = function_exists('lsfd_grades') ? lsfd_grades() : [];
$rankOrder = [];
foreach ($grades as $i => $g) { $rankOrder[$g['name']] = (int)$g['rank_order']; }

// Registre des agents LSFD.
$agents = [];
try {
    $st = $db->prepare(
        "SELECT u.id, u.faction_rank,
                NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS rp_name,
                p.avatar
         FROM users u
         LEFT JOIN profiles p ON p.id = u.profile
         WHERE u.faction = 'LSFD'");
    $st->execute();
    $agents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $agents = []; }

// Tri par rang (rank_order croissant = plus haut gradé en premier), puis nom.
usort($agents, function ($a, $b) use ($rankOrder) {
    $ra = $rankOrder[$a['faction_rank']] ?? 999;
    $rb = $rankOrder[$b['faction_rank']] ?? 999;
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp((string)$a['rp_name'], (string)$b['rp_name']);
});

$pageTitle = 'Effectifs';
include __DIR__ . '/includes/header.php';
?>

<div class="lf-pagehead">
  <div>
    <div class="lf-ph-title"><i class="fas fa-people-group"></i> Effectifs</div>
    <div class="lf-ph-sub"><?php echo count($agents); ?> agent<?php echo count($agents)>1?'s':''; ?> — gestion RH via l'Académie Centrale</div>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/effectifs_lsfd.php" class="lf-btn lf-btn-primary"><i class="fas fa-users-gear"></i> Gérer les effectifs</a>
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/matricules_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-id-card"></i> Matricules</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;align-items:start">
  <!-- Registre -->
  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-address-book"></i> Registre des agents</h3>
      <input type="text" class="lf-input" id="agentSearch" placeholder="Rechercher…" style="max-width:220px" oninput="filterAgents(this.value)">
    </div>
    <table class="lf-table" id="agentTable">
      <thead><tr><th>Agent</th><th>Grade</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($agents)): ?>
          <tr class="lf-empty-row"><td colspan="3"><i class="fas fa-user-slash"></i> Aucun agent LSFD enregistré</td></tr>
        <?php else: foreach ($agents as $a):
          $name = $a['rp_name'] ?: 'Profil non renseigné';
          $ini = mb_strtoupper(mb_substr($name, 0, 1)); ?>
          <tr data-name="<?php echo htmlspecialchars(mb_strtolower($name.' '.($a['faction_rank']??''))); ?>">
            <td>
              <div style="display:flex;align-items:center;gap:.7rem">
                <?php if (!empty($a['avatar'])): ?>
                  <img src="<?php echo htmlspecialchars($a['avatar']); ?>" style="width:34px;height:34px;border-radius:9px;object-fit:cover">
                <?php else: ?>
                  <span style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--panel-color,#dc2626),#991b1b);display:grid;place-items:center;font-weight:700;color:#fff;font-size:.85rem"><?php echo htmlspecialchars($ini); ?></span>
                <?php endif; ?>
                <span style="font-weight:600;color:#e2e8f0"><?php echo htmlspecialchars($name); ?></span>
              </div>
            </td>
            <td><span class="lf-badge red"><?php echo htmlspecialchars($a['faction_rank'] ?: '—'); ?></span></td>
            <td style="text-align:right"><a href="<?php echo SITE_URL; ?>/pages/academy-central/effectifs_lsfd.php" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-up-right-from-square"></i></a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div>
    <!-- Hiérarchie -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-ranking-star"></i> Hiérarchie LSFD</h3></div>
      <?php
        $display = !empty($grades) ? array_column($grades, 'name') : $gradeRef;
        foreach ($display as $i => $g): ?>
        <div style="display:flex;align-items:center;gap:.6rem;padding:.45rem 0;border-bottom:1px solid rgba(100,116,139,.08)">
          <span style="width:22px;height:22px;border-radius:6px;background:rgba(var(--panel-color-rgb,220,38,38),.12);color:var(--panel-color,#dc2626);display:grid;place-items:center;font-size:.7rem;font-weight:700"><?php echo $i+1; ?></span>
          <span style="color:#cbd5e1;font-size:.86rem"><?php echo htmlspecialchars($g); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Liens RH académie -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-graduation-cap"></i> Gestion RH</h3></div>
      <div style="display:flex;flex-direction:column;gap:.5rem">
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/effectifs_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-users-gear"></i> Effectifs & promotions</a>
        <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-user-plus"></i> Recrutement / candidatures</a>
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/matricules_lsfd.php" class="lf-btn lf-btn-ghost"><i class="fas fa-id-card"></i> Matricules</a>
        <a href="<?php echo SITE_URL; ?>/pages/panels/lsfd/certifications.php" class="lf-btn lf-btn-ghost"><i class="fas fa-award"></i> Certifications & formations</a>
      </div>
    </div>
  </div>
</div>

<script>
function filterAgents(q) {
  q = (q||'').toLowerCase().trim();
  document.querySelectorAll('#agentTable tbody tr[data-name]').forEach(tr => {
    tr.style.display = !q || tr.dataset.name.includes(q) ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php';
