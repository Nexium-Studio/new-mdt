<?php
/**
 * §4 — Dispatch & gestion des appels LSFD
 * Tableau live des incidents (colonnes par statut), réception d'appels 911 et
 * affectation. Création déléguée au handler de interventions.php (réutilisation).
 * Lecture seule par LSPD/SAMS pour coordination ; co-dispatch SAMS si blessés,
 * alerte LSPD si HazMat/explosion (flags posés à la création de l'incident).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$db = Database::getInstance()->getConnection();
$tax = require __DIR__ . '/includes/incident_types.php';
$selfUrl = SITE_URL . '/pages/panels/lsfd/dispatch.php';
$intervUrl = SITE_URL . '/pages/panels/lsfd/interventions.php';

// Avancement rapide de statut (reste sur le board)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'advance') {
    $id = (int)($_POST['id'] ?? 0);
    $s = in_array($_POST['status'] ?? '', ['ouvert','en_cours','cloture'], true) ? $_POST['status'] : 'ouvert';
    try {
        $extra = $s === 'cloture' ? ', time_closed=NOW()' : '';
        $db->prepare("UPDATE lsfd_incidents SET status=?$extra WHERE id=?")->execute([$s, $id]);
        lsfd_log('status','incident',$id,"Dispatch → $s");
    } catch (Exception $e) {}
    header("Location: $selfUrl"); exit;
}

function lsfd_board(PDO $db, string $sql): array {
    try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) { return []; }
}
$colOpen   = lsfd_board($db, "SELECT * FROM lsfd_incidents WHERE status='ouvert' ORDER BY FIELD(priority,'critique','haute','normale','basse'), created_at DESC");
$colCours  = lsfd_board($db, "SELECT * FROM lsfd_incidents WHERE status='en_cours' ORDER BY created_at DESC");
$colClos   = lsfd_board($db, "SELECT * FROM lsfd_incidents WHERE status='cloture' AND DATE(time_closed)=CURDATE() ORDER BY time_closed DESC");

$catLabels = $tax['categories'];
$prioLabels = ['critique'=>['Critique','red'],'haute'=>['Haute','orange'],'normale'=>['Normale','blue'],'basse'=>['Basse','gray']];

function lsfd_card($inc, $catLabels, $prioLabels, $intervUrl, $selfUrl) {
    $cat = $catLabels[$inc['category']] ?? [ucfirst($inc['category']),'gray','fa-circle'];
    $pr  = $prioLabels[$inc['priority']] ?? [ucfirst($inc['priority']),'gray'];
    ob_start(); ?>
    <div class="lf-dispatch-card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem">
        <span class="lf-badge <?php echo $cat[1]; ?>"><i class="fas <?php echo $cat[2]; ?>"></i> <?php echo htmlspecialchars($cat[0]); ?></span>
        <span class="lf-badge <?php echo $pr[1]; ?>"><?php echo htmlspecialchars($pr[0]); ?></span>
      </div>
      <div style="font-weight:700;color:#e8edf5;font-size:.9rem"><?php echo htmlspecialchars($inc['type']); ?></div>
      <div style="color:#94a3b8;font-size:.8rem;margin:.35rem 0;line-height:1.4">
        <?php if ($inc['address']): ?><div><i class="fas fa-location-dot" style="width:16px"></i> <?php echo htmlspecialchars($inc['address']); ?></div><?php endif; ?>
        <?php if ($inc['radio_channel']): ?><div><i class="fas fa-walkie-talkie" style="width:16px"></i> <?php echo htmlspecialchars($inc['radio_channel']); ?></div><?php endif; ?>
        <?php if ($inc['caller_name']): ?><div><i class="fas fa-phone" style="width:16px"></i> <?php echo htmlspecialchars($inc['caller_name']); ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.5rem">
        <span style="color:#64748b;font-size:.72rem"><?php echo htmlspecialchars($inc['ref'] ?: '#'.$inc['id']); ?></span>
        <?php if ($inc['injured']): ?><span class="lf-badge orange" style="font-size:.6rem"><i class="fas fa-truck-medical"></i> SAMS</span><?php endif; ?>
      </div>
      <div style="display:flex;gap:.3rem;flex-wrap:wrap">
        <a href="<?php echo $intervUrl; ?>?id=<?php echo $inc['id']; ?>" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-helmet-safety"></i> Affecter</a>
        <?php if ($inc['status']==='ouvert'): ?>
          <form method="POST" style="margin:0"><input type="hidden" name="action" value="advance"><input type="hidden" name="id" value="<?php echo $inc['id']; ?>"><input type="hidden" name="status" value="en_cours"><button class="lf-btn lf-btn-primary lf-btn-sm"><i class="fas fa-play"></i></button></form>
        <?php elseif ($inc['status']==='en_cours'): ?>
          <form method="POST" style="margin:0" data-lsfd-confirm="Clôturer cet incident ?" data-lsfd-confirm-danger="false"><input type="hidden" name="action" value="advance"><input type="hidden" name="id" value="<?php echo $inc['id']; ?>"><input type="hidden" name="status" value="cloture"><button class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-flag-checkered"></i></button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}

$pageTitle = 'Dispatch';
include __DIR__ . '/includes/header.php';
?>
<style>
.lf-dispatch-cols { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; align-items:start; }
.lf-dispatch-col { background:rgba(15,18,28,.5); border:1px solid rgba(100,116,139,.14); border-radius:14px; padding:1rem; }
.lf-dispatch-col-h { display:flex; align-items:center; justify-content:space-between; margin-bottom:.85rem; font-family:'Syne',sans-serif; font-weight:800; font-size:.82rem; text-transform:uppercase; letter-spacing:1px; color:#cbd5e1; }
.lf-dispatch-card { background:rgba(255,255,255,.02); border:1px solid rgba(100,116,139,.14); border-left:3px solid var(--panel-color,#dc2626); border-radius:10px; padding:.85rem; margin-bottom:.7rem; }
@media (max-width:900px){ .lf-dispatch-cols{ grid-template-columns:1fr; } }
</style>

<div class="lf-pagehead">
  <div>
    <div class="lf-ph-title"><i class="fas fa-tower-broadcast"></i> Dispatch LSFD</div>
    <div class="lf-ph-sub">Tableau live des appels & interventions — visible en lecture par LSPD / SAMS</div>
  </div>
  <button class="lf-btn lf-btn-primary" onclick="document.getElementById('call911Modal').classList.add('open')"><i class="fas fa-phone-volume"></i> Nouvel appel 911</button>
</div>

<div class="lf-dispatch-cols">
  <div class="lf-dispatch-col">
    <div class="lf-dispatch-col-h"><span><i class="fas fa-bell" style="color:#f97316"></i> Ouverts</span><span class="lf-badge orange"><?php echo count($colOpen); ?></span></div>
    <?php if (empty($colOpen)): ?><div class="lf-empty" style="padding:1.5rem"><i class="fas fa-check-circle"></i><p>Aucun appel en attente</p></div>
    <?php else: foreach ($colOpen as $inc) echo lsfd_card($inc,$catLabels,$prioLabels,$intervUrl,$selfUrl); endif; ?>
  </div>
  <div class="lf-dispatch-col">
    <div class="lf-dispatch-col-h"><span><i class="fas fa-truck-fast" style="color:#3b82f6"></i> En cours</span><span class="lf-badge blue"><?php echo count($colCours); ?></span></div>
    <?php if (empty($colCours)): ?><div class="lf-empty" style="padding:1.5rem"><i class="fas fa-truck"></i><p>Aucune intervention en cours</p></div>
    <?php else: foreach ($colCours as $inc) echo lsfd_card($inc,$catLabels,$prioLabels,$intervUrl,$selfUrl); endif; ?>
  </div>
  <div class="lf-dispatch-col">
    <div class="lf-dispatch-col-h"><span><i class="fas fa-flag-checkered" style="color:#22c55e"></i> Clôturés (jour)</span><span class="lf-badge green"><?php echo count($colClos); ?></span></div>
    <?php if (empty($colClos)): ?><div class="lf-empty" style="padding:1.5rem"><i class="fas fa-inbox"></i><p>Aucune clôture aujourd'hui</p></div>
    <?php else: foreach ($colClos as $inc) echo lsfd_card($inc,$catLabels,$prioLabels,$intervUrl,$selfUrl); endif; ?>
  </div>
</div>

<!-- Modal appel 911 (poste vers le handler de interventions.php) -->
<div class="lf-modal-ov" id="call911Modal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-phone-volume"></i> Réception d'appel 911</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST" action="<?php echo $intervUrl; ?>">
      <input type="hidden" name="action" value="create">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Type d'incident</label>
            <select name="type" id="dispType" data-search="true" required>
              <?php foreach ($tax['types'] as $catKey=>$list): ?>
                <optgroup label="<?php echo htmlspecialchars($tax['categories'][$catKey][0]); ?>">
                <?php foreach ($list as $t): ?><option value="<?php echo htmlspecialchars($t); ?>" data-cat="<?php echo $catKey; ?>"><?php echo htmlspecialchars($t); ?></option><?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="lf-field"><label>Priorité</label>
            <select name="priority"><option value="haute">Haute</option><option value="critique">Critique</option><option value="normale" selected>Normale</option><option value="basse">Basse</option></select>
          </div>
        </div>
        <input type="hidden" name="category" id="dispCat">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Déclarant</label><input class="lf-input" name="caller_name" placeholder="Nom de l'appelant"></div>
          <div class="lf-field"><label>Canal radio</label><input class="lf-input" name="radio_channel" placeholder="Ex: Canal 3"></div>
        </div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Adresse</label><input class="lf-input" name="address" placeholder="Localisation"></div>
          <div class="lf-field"><label>Caserne</label><input class="lf-input" name="station" placeholder="Station la plus proche"></div>
        </div>
        <div class="lf-field"><label>Description de l'appel</label><textarea class="lf-textarea" name="description"></textarea></div>
        <input type="hidden" name="injured" id="dispInjured">
        <span class="lsfd-switch" data-target="dispInjured"><span class="sw-track"></span><span class="sw-lbl">Blessés (co-dispatch SAMS)</span></span>
      </div>
      <div class="lf-modal-f">
        <button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button>
        <button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-tower-broadcast"></i> Créer l'incident</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const cat = document.getElementById('dispCat'); const type = document.getElementById('dispType');
  if (!cat || !type) return;
  function sync(){ const o=type.options[type.selectedIndex]; if(o&&o.dataset.cat) cat.value=o.dataset.cat; }
  type.addEventListener('change', sync); sync();
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
