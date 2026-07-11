<?php
/**
 * §1 — Dashboard LSFD
 * Statut casernes, unités actives, interventions en cours, risque incendie,
 * annonces internes et statistiques du jour. Toutes les requêtes en try/catch
 * (dégradation gracieuse si la migration n'est pas encore appliquée).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$db = Database::getInstance()->getConnection();

/** Exécute un COUNT/scalaire en try/catch, retourne $default si échec. */
function lsfd_scalar(PDO $db, string $sql, array $args = [], $default = 0) {
    try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
    catch (Exception $e) { return $default; }
}
function lsfd_rows(PDO $db, string $sql, array $args = []): array {
    try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}

// ── Paramètres (risque incendie) ────────────────────────────────────────────
$fireRisk = 'modere';
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM lsfd_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['fire_risk'])) $fireRisk = $rows['fire_risk'];
} catch (Exception $e) {}
$riskMap = [
    'bas'     => ['Bas',     'green',  'fa-shield-halved'],
    'modere'  => ['Modéré',  'yellow', 'fa-fire'],
    'eleve'   => ['Élevé',   'orange', 'fa-fire-flame-curved'],
    'extreme' => ['Extrême', 'red',    'fa-triangle-exclamation'],
];
$risk = $riskMap[$fireRisk] ?? $riskMap['modere'];

// ── KPI ─────────────────────────────────────────────────────────────────────
$nbAgents     = (int) lsfd_scalar($db, "SELECT COUNT(*) FROM users WHERE faction='LSFD'");
$nbOpen       = (int) lsfd_scalar($db, "SELECT COUNT(*) FROM lsfd_incidents WHERE status IN ('ouvert','en_cours')");
$nbToday      = (int) lsfd_scalar($db, "SELECT COUNT(*) FROM lsfd_incidents WHERE DATE(created_at)=CURDATE()");
$nbApparatus  = (int) lsfd_scalar($db, "SELECT COUNT(*) FROM lsfd_apparatus WHERE status='service'");

// ── Interventions en cours ──────────────────────────────────────────────────
$openIncidents = lsfd_rows($db,
    "SELECT id, ref, category, type, priority, address, station, status, time_call
     FROM lsfd_incidents WHERE status IN ('ouvert','en_cours') ORDER BY
       FIELD(priority,'critique','haute','normale','basse'), created_at DESC LIMIT 8");

// ── Unités actives (agents engagés sur incidents en cours) ──────────────────
$activeUnits = lsfd_rows($db,
    "SELECT iu.role, iu.unit_status,
            NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS rp_name,
            u.faction_rank, i.ref
     FROM lsfd_incident_units iu
     JOIN lsfd_incidents i ON i.id = iu.incident_id AND i.status IN ('ouvert','en_cours')
     LEFT JOIN users u ON u.id = iu.user_id
     LEFT JOIN profiles p ON p.id = u.profile
     ORDER BY iu.created_at DESC LIMIT 10");

// ── Casernes (compte des agents par station d'affectation des appareils) ─────
$stations = lsfd_rows($db,
    "SELECT station, COUNT(*) AS nb, SUM(status='service') AS dispo
     FROM lsfd_apparatus WHERE station IS NOT NULL AND station<>'' GROUP BY station ORDER BY station");

// ── Annonces internes ───────────────────────────────────────────────────────
$announcements = lsfd_rows($db,
    "SELECT a.title, a.body, a.created_at,
            NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS rp_name
     FROM lsfd_announcements a
     LEFT JOIN users u ON u.id = a.created_by
     LEFT JOIN profiles p ON p.id = u.profile
     ORDER BY a.created_at DESC LIMIT 5");

$catLabels = ['incendie'=>['Incendie','red'],'secours'=>['Secours','orange'],'hazmat'=>['HazMat','yellow'],'sauvetage'=>['Sauvetage','blue'],'divers'=>['Divers','gray']];
$prioLabels = ['critique'=>['Critique','red'],'haute'=>['Haute','orange'],'normale'=>['Normale','blue'],'basse'=>['Basse','gray']];
$unitStatusLabels = ['en_route'=>['En route','blue'],'sur_scene'=>['Sur scène','orange'],'transit_hopital'=>['Transit hôpital','purple'],'disponible'=>['Disponible','green'],'hors_service'=>['Hors service','gray']];

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="lf-pagehead">
  <div>
    <div class="lf-ph-title"><i class="fas fa-fire-flame-curved"></i> Los Santos Fire Department</div>
    <div class="lf-ph-sub">Tableau de bord opérationnel — <?php echo date('d/m/Y'); ?></div>
  </div>
  <div class="lf-badge <?php echo $risk[1]; ?>" style="font-size:.82rem;padding:.5rem .9rem">
    <i class="fas <?php echo $risk[2]; ?>"></i> Risque incendie : <?php echo $risk[0]; ?>
  </div>
</div>

<!-- KPI -->
<div class="lf-kpis">
  <div class="lf-kpi"><i class="fas fa-people-group lf-kpi-ico"></i><div class="lf-kpi-lbl">Effectifs</div><div class="lf-kpi-val"><?php echo $nbAgents; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-truck-fast lf-kpi-ico"></i><div class="lf-kpi-lbl">Interventions ouvertes</div><div class="lf-kpi-val"><?php echo $nbOpen; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-calendar-day lf-kpi-ico"></i><div class="lf-kpi-lbl">Interventions du jour</div><div class="lf-kpi-val"><?php echo $nbToday; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-truck-medical lf-kpi-ico"></i><div class="lf-kpi-lbl">Appareils en service</div><div class="lf-kpi-val"><?php echo $nbApparatus; ?></div></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;align-items:start">
  <div>
    <!-- Interventions en cours -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-truck-fast"></i> Interventions en cours</h3>
        <a href="<?php echo SITE_URL; ?>/pages/panels/lsfd/interventions.php" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-arrow-right"></i> Toutes</a>
      </div>
      <table class="lf-table">
        <thead><tr><th>Réf.</th><th>Catégorie</th><th>Type</th><th>Priorité</th><th>Adresse</th><th>Statut</th></tr></thead>
        <tbody>
          <?php if (empty($openIncidents)): ?>
            <tr class="lf-empty-row"><td colspan="6"><i class="fas fa-check-circle"></i> Aucune intervention en cours</td></tr>
          <?php else: foreach ($openIncidents as $inc):
            $cat = $catLabels[$inc['category']] ?? [ucfirst($inc['category']),'gray'];
            $pr  = $prioLabels[$inc['priority']] ?? [ucfirst($inc['priority']),'gray']; ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($inc['ref'] ?: '—'); ?></strong></td>
              <td><span class="lf-badge <?php echo $cat[1]; ?>"><?php echo htmlspecialchars($cat[0]); ?></span></td>
              <td><?php echo htmlspecialchars($inc['type']); ?></td>
              <td><span class="lf-badge <?php echo $pr[1]; ?>"><?php echo htmlspecialchars($pr[0]); ?></span></td>
              <td><?php echo htmlspecialchars($inc['address'] ?: '—'); ?></td>
              <td><span class="lf-badge <?php echo $inc['status']==='ouvert'?'orange':'blue'; ?>"><?php echo $inc['status']==='ouvert'?'Ouvert':'En cours'; ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Unités actives -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-helmet-safety"></i> Unités actives</h3></div>
      <table class="lf-table">
        <thead><tr><th>Agent</th><th>Grade</th><th>Rôle</th><th>Incident</th><th>Statut</th></tr></thead>
        <tbody>
          <?php if (empty($activeUnits)): ?>
            <tr class="lf-empty-row"><td colspan="5"><i class="fas fa-user-clock"></i> Aucune unité engagée</td></tr>
          <?php else: foreach ($activeUnits as $u):
            $us = $unitStatusLabels[$u['unit_status']] ?? [ucfirst((string)$u['unit_status']),'gray']; ?>
            <tr>
              <td><?php echo htmlspecialchars($u['rp_name'] ?: 'Profil non renseigné'); ?></td>
              <td><?php echo htmlspecialchars($u['faction_rank'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars($u['role'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars($u['ref'] ?: '—'); ?></td>
              <td><span class="lf-badge <?php echo $us[1]; ?>"><?php echo htmlspecialchars($us[0]); ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <!-- Statut casernes -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-building-shield"></i> Casernes</h3></div>
      <?php if (empty($stations)): ?>
        <div class="lf-empty" style="padding:1.5rem"><i class="fas fa-building"></i><p>Aucune caserne configurée</p></div>
      <?php else: foreach ($stations as $s): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid rgba(100,116,139,.08)">
          <span style="font-weight:600;color:#cbd5e1"><i class="fas fa-fire-extinguisher" style="color:var(--panel-color,#dc2626);margin-right:.5rem"></i><?php echo htmlspecialchars($s['station']); ?></span>
          <span class="lf-badge green"><?php echo (int)$s['dispo']; ?>/<?php echo (int)$s['nb']; ?> dispo</span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Annonces -->
    <div class="lf-card">
      <div class="lf-card-h"><h3><i class="fas fa-bullhorn"></i> Annonces</h3>
        <a href="<?php echo SITE_URL; ?>/pages/panels/lsfd/annonces.php" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-arrow-right"></i></a>
      </div>
      <?php if (empty($announcements)): ?>
        <div class="lf-empty" style="padding:1.5rem"><i class="fas fa-comment-slash"></i><p>Aucune annonce</p></div>
      <?php else: foreach ($announcements as $a): ?>
        <div style="padding:.7rem 0;border-bottom:1px solid rgba(100,116,139,.08)">
          <div style="font-weight:700;color:#e8edf5;font-size:.9rem"><?php echo htmlspecialchars($a['title']); ?></div>
          <div style="color:#94a3b8;font-size:.82rem;margin:.25rem 0;line-height:1.5"><?php echo nl2br(htmlspecialchars(mb_strimwidth((string)$a['body'],0,160,'…'))); ?></div>
          <div style="color:#64748b;font-size:.72rem"><?php echo htmlspecialchars($a['rp_name'] ?: '—'); ?> · <?php echo date('d/m H:i', strtotime($a['created_at'])); ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';
