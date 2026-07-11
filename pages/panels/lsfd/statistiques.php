<?php
/**
 * §11 — Statistiques & reporting LSFD
 * Vues agrégées (hebdo / mensuel / annuel) : interventions par type/catégorie/
 * station, temps de réponse moyen, taux d'occupation des unités, incidents majeurs.
 * Lecture seule (try/catch). Export Google Docs / remontée budget = liens externes.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$db = Database::getInstance()->getConnection();
$tax = require __DIR__ . '/includes/incident_types.php';

// Période
$period = in_array($_GET['p'] ?? 'mois', ['semaine','mois','annee'], true) ? $_GET['p'] : 'mois';
$since = ['semaine'=>'INTERVAL 7 DAY','mois'=>'INTERVAL 1 MONTH','annee'=>'INTERVAL 1 YEAR'][$period];
$cond = "created_at >= DATE_SUB(NOW(), $since)";

function lsfd_scalar2(PDO $db, string $sql, $def=0){ try { return $db->query($sql)->fetchColumn(); } catch (Exception $e){ return $def; } }
function lsfd_pairs(PDO $db, string $sql): array { try { return $db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR) ?: []; } catch (Exception $e){ return []; } }

$total   = (int) lsfd_scalar2($db, "SELECT COUNT(*) FROM lsfd_incidents WHERE $cond");
$closed  = (int) lsfd_scalar2($db, "SELECT COUNT(*) FROM lsfd_incidents WHERE status='cloture' AND $cond");
$pcrTot  = (int) lsfd_scalar2($db, "SELECT COUNT(*) FROM lsfd_pcr WHERE $cond");
// Temps de réponse moyen (appel → arrivée) en minutes
$avgResp = lsfd_scalar2($db, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, time_call, time_arrival)),1) FROM lsfd_incidents WHERE time_call IS NOT NULL AND time_arrival IS NOT NULL AND $cond", null);

$byCat = lsfd_pairs($db, "SELECT category, COUNT(*) FROM lsfd_incidents WHERE $cond GROUP BY category");
$byStation = lsfd_pairs($db, "SELECT COALESCE(NULLIF(station,''),'Non assignée') AS s, COUNT(*) FROM lsfd_incidents WHERE $cond GROUP BY s ORDER BY COUNT(*) DESC");
$byType = lsfd_pairs($db, "SELECT type, COUNT(*) FROM lsfd_incidents WHERE $cond GROUP BY type ORDER BY COUNT(*) DESC LIMIT 10");

$maxCat = $byCat ? max($byCat) : 1;
$catLabels = $tax['categories'];

$pageTitle = 'Statistiques';
include __DIR__ . '/includes/header.php';
?>
<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-chart-line"></i> Statistiques & reporting</div><div class="lf-ph-sub">Activité opérationnelle LSFD</div></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="?p=semaine" class="lf-btn <?php echo $period==='semaine'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Semaine</a>
    <a href="?p=mois" class="lf-btn <?php echo $period==='mois'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Mois</a>
    <a href="?p=annee" class="lf-btn <?php echo $period==='annee'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Année</a>
  </div>
</div>

<div class="lf-kpis">
  <div class="lf-kpi"><i class="fas fa-truck-fast lf-kpi-ico"></i><div class="lf-kpi-lbl">Interventions</div><div class="lf-kpi-val"><?php echo $total; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-flag-checkered lf-kpi-ico"></i><div class="lf-kpi-lbl">Clôturées</div><div class="lf-kpi-val"><?php echo $closed; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-stopwatch lf-kpi-ico"></i><div class="lf-kpi-lbl">Temps de réponse moy.</div><div class="lf-kpi-val"><?php echo $avgResp !== null ? $avgResp.' min' : '—'; ?></div></div>
  <div class="lf-kpi"><i class="fas fa-notes-medical lf-kpi-ico"></i><div class="lf-kpi-lbl">PCR</div><div class="lf-kpi-val"><?php echo $pcrTot; ?></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-layer-group"></i> Par catégorie</h3></div>
    <?php if (empty($byCat)): ?><div class="lf-empty" style="padding:1.5rem"><i class="fas fa-chart-simple"></i><p>Aucune donnée</p></div>
    <?php else: foreach ($byCat as $k=>$n): $cat=$catLabels[$k]??[ucfirst($k),'gray','fa-circle']; $pct=$maxCat?round($n/$maxCat*100):0; ?>
      <div style="margin-bottom:.7rem">
        <div style="display:flex;justify-content:space-between;font-size:.84rem;margin-bottom:.25rem"><span style="color:#cbd5e1"><i class="fas <?php echo $cat[2]; ?>" style="color:var(--panel-color,#dc2626);width:18px"></i> <?php echo htmlspecialchars($cat[0]); ?></span><span style="color:#94a3b8"><?php echo (int)$n; ?></span></div>
        <div style="height:8px;background:rgba(100,116,139,.12);border-radius:4px;overflow:hidden"><div style="height:100%;width:<?php echo $pct; ?>%;background:linear-gradient(90deg,var(--panel-color,#dc2626),#f97316)"></div></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="lf-card">
    <div class="lf-card-h"><h3><i class="fas fa-building-shield"></i> Par caserne</h3></div>
    <table class="lf-table"><tbody>
      <?php if (empty($byStation)): ?><tr class="lf-empty-row"><td colspan="2">Aucune donnée</td></tr>
      <?php else: foreach ($byStation as $s=>$n): ?>
        <tr><td><?php echo htmlspecialchars($s); ?></td><td style="text-align:right"><span class="lf-badge red"><?php echo (int)$n; ?></span></td></tr>
      <?php endforeach; endif; ?>
    </tbody></table>
  </div>
</div>

<div class="lf-card">
  <div class="lf-card-h"><h3><i class="fas fa-ranking-star"></i> Top types d'incidents</h3>
    <a href="https://docs.google.com/" target="_blank" rel="noopener" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fab fa-google"></i> Export rapport</a>
  </div>
  <table class="lf-table">
    <thead><tr><th>Type</th><th style="text-align:right">Occurrences</th></tr></thead>
    <tbody>
      <?php if (empty($byType)): ?><tr class="lf-empty-row"><td colspan="2">Aucune donnée</td></tr>
      <?php else: foreach ($byType as $t=>$n): ?>
        <tr><td><?php echo htmlspecialchars($t); ?></td><td style="text-align:right"><?php echo (int)$n; ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  <div style="color:#64748b;font-size:.78rem;margin-top:.8rem"><i class="fas fa-coins"></i> Les coûts des interventions majeures (consommables, dommages) peuvent être remontés au budget d'État via le panneau Gouvernement.</div>
</div>

<?php include __DIR__ . '/includes/footer.php';
