<?php
/**
 * §5 — Gestion des interventions LSFD (fiches d'incident)
 * Liste + création + fiche détaillée (unités engagées, statuts, clôture).
 * Mode liste par défaut ; ?id=X affiche la fiche détaillée.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$tax = require __DIR__ . '/includes/incident_types.php';
$selfUrl = SITE_URL . '/pages/panels/lsfd/interventions.php';

// Agents LSFD (pour l'affectation d'unités)
$agents = [];
try {
    $st = $db->query("SELECT u.id, u.faction_rank,
        NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS rp_name
        FROM users u LEFT JOIN profiles p ON p.id=u.profile WHERE u.faction='LSFD'");
    $agents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $agents = []; }
$agentName = [];
foreach ($agents as $a) { $agentName[$a['id']] = $a['rp_name'] ?: 'Profil non renseigné'; }

$flash = '';

// ── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $cat = array_key_exists($_POST['category'] ?? '', $tax['categories']) ? $_POST['category'] : 'divers';
            $type = trim($_POST['type'] ?? '');
            $prio = in_array($_POST['priority'] ?? '', ['basse','normale','haute','critique'], true) ? $_POST['priority'] : 'normale';
            $injured = in_array($cat, $tax['sams'], true) ? 1 : (int)!empty($_POST['injured']);
            // Référence INC-AAAA-NNNNN
            $year = date('Y');
            $n = (int) $db->query("SELECT COUNT(*) FROM lsfd_incidents WHERE YEAR(created_at)=$year")->fetchColumn();
            $ref = 'INC-' . $year . '-' . str_pad((string)($n + 1), 5, '0', STR_PAD_LEFT);
            $st = $db->prepare("INSERT INTO lsfd_incidents
                (ref, category, type, priority, address, coords, caller_name, description, radio_channel, station, status, injured, time_call, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?, 'ouvert', ?, NOW(), ?)");
            $st->execute([$ref, $cat, $type, $prio, trim($_POST['address']??''), trim($_POST['coords']??''),
                trim($_POST['caller_name']??''), trim($_POST['description']??''), trim($_POST['radio_channel']??''),
                trim($_POST['station']??''), $injured, $_SESSION['user_id'] ?? null]);
            $newId = (int)$db->lastInsertId();
            lsfd_log('create', 'incident', $newId, "Incident $ref créé ($type)");
            // Interconnexions (flags ; intégration dispatch SAMS/LSPD si tables présentes)
            $notif = [];
            if ($injured) $notif[] = 'SAMS (blessés)';
            if (in_array($cat, $tax['lspd'], true) || in_array($type, $tax['lspd_types'], true)) $notif[] = 'LSPD (périmètre)';
            $_SESSION['lsfd_flash'] = "Incident $ref créé." . ($notif ? ' Notification : ' . implode(', ', $notif) . '.' : '');
            header("Location: $selfUrl?id=$newId"); exit;
        }
        if ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $s = in_array($_POST['status'] ?? '', ['ouvert','en_cours','cloture'], true) ? $_POST['status'] : 'ouvert';
            $extra = $s === 'cloture' ? ', time_closed=NOW()' : '';
            $db->prepare("UPDATE lsfd_incidents SET status=?$extra WHERE id=?")->execute([$s, $id]);
            lsfd_log('status', 'incident', $id, "Statut → $s");
            $_SESSION['lsfd_flash'] = 'Statut mis à jour.';
            header("Location: $selfUrl?id=$id"); exit;
        }
        if ($action === 'add_unit') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("INSERT INTO lsfd_incident_units (incident_id, user_id, `role`, unit_status) VALUES (?,?,?,?)")
               ->execute([$id, (int)($_POST['user_id']??0) ?: null, trim($_POST['role']??''), $_POST['unit_status'] ?? 'en_route']);
            lsfd_log('add_unit', 'incident', $id, 'Unité engagée');
            header("Location: $selfUrl?id=$id"); exit;
        }
        if ($action === 'unit_status') {
            $uid = (int)($_POST['unit_id'] ?? 0); $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE lsfd_incident_units SET unit_status=? WHERE id=?")
               ->execute([$_POST['unit_status'] ?? 'en_route', $uid]);
            header("Location: $selfUrl?id=$id"); exit;
        }
        if ($action === 'remove_unit') {
            $uid = (int)($_POST['unit_id'] ?? 0); $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM lsfd_incident_units WHERE id=?")->execute([$uid]);
            header("Location: $selfUrl?id=$id"); exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM lsfd_incidents WHERE id=?")->execute([$id]);
            $db->prepare("DELETE FROM lsfd_incident_units WHERE incident_id=?")->execute([$id]);
            lsfd_log('delete', 'incident', $id, 'Incident supprimé');
            $_SESSION['lsfd_flash'] = 'Incident supprimé.';
            header("Location: $selfUrl"); exit;
        }
    } catch (Exception $e) {
        $_SESSION['lsfd_flash'] = "Erreur (migration non appliquée ?) : " . $e->getMessage();
        header("Location: $selfUrl"); exit;
    }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash = $_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$catLabels = $tax['categories'];
$prioLabels = ['critique'=>['Critique','red'],'haute'=>['Haute','orange'],'normale'=>['Normale','blue'],'basse'=>['Basse','gray']];
$statusLabels = ['ouvert'=>['Ouvert','orange'],'en_cours'=>['En cours','blue'],'cloture'=>['Clôturé','green']];
$unitStatusLabels = ['en_route'=>['En route','blue'],'sur_scene'=>['Sur scène','orange'],'transit_hopital'=>['Transit hôpital','purple'],'disponible'=>['Disponible','green'],'hors_service'=>['Hors service','gray']];

$viewId = (int)($_GET['id'] ?? 0);
$pageTitle = 'Interventions';
include __DIR__ . '/includes/header.php';
?>

<?php if ($flash): ?>
<div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<?php
// ════════════════════════════ MODE FICHE DÉTAILLÉE ════════════════════════════
if ($viewId):
    $inc = null;
    try { $s = $db->prepare("SELECT * FROM lsfd_incidents WHERE id=?"); $s->execute([$viewId]); $inc = $s->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    if (!$inc): ?>
      <div class="lf-empty"><i class="fas fa-triangle-exclamation"></i><p>Incident introuvable.</p><a href="<?php echo $selfUrl; ?>" class="lf-btn lf-btn-ghost">Retour</a></div>
    <?php else:
      $units = [];
      try { $s = $db->prepare("SELECT * FROM lsfd_incident_units WHERE incident_id=? ORDER BY created_at"); $s->execute([$viewId]); $units = $s->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
      $cat = $catLabels[$inc['category']] ?? [ucfirst($inc['category']),'gray','fa-circle'];
      $pr  = $prioLabels[$inc['priority']] ?? [ucfirst($inc['priority']),'gray'];
      $stt = $statusLabels[$inc['status']] ?? [ucfirst($inc['status']),'gray'];
    ?>
    <div class="lf-pagehead">
      <div>
        <div class="lf-ph-title"><i class="fas <?php echo $cat[2]; ?>"></i> <?php echo htmlspecialchars($inc['ref'] ?: 'Incident'); ?></div>
        <div class="lf-ph-sub"><?php echo htmlspecialchars($inc['type']); ?></div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <span class="lf-badge <?php echo $cat[1]; ?>"><?php echo htmlspecialchars($cat[0]); ?></span>
        <span class="lf-badge <?php echo $pr[1]; ?>">Priorité <?php echo htmlspecialchars($pr[0]); ?></span>
        <span class="lf-badge <?php echo $stt[1]; ?>"><?php echo htmlspecialchars($stt[0]); ?></span>
        <a href="<?php echo $selfUrl; ?>" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-arrow-left"></i> Liste</a>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
      <div class="lf-card">
        <div class="lf-card-h"><h3><i class="fas fa-circle-info"></i> Détails</h3></div>
        <table class="lf-table"><tbody>
          <tr><td style="color:#64748b;width:40%">Adresse</td><td><?php echo htmlspecialchars($inc['address'] ?: '—'); ?></td></tr>
          <tr><td style="color:#64748b">Coordonnées</td><td><?php echo htmlspecialchars($inc['coords'] ?: '—'); ?></td></tr>
          <tr><td style="color:#64748b">Déclarant</td><td><?php echo htmlspecialchars($inc['caller_name'] ?: '—'); ?></td></tr>
          <tr><td style="color:#64748b">Canal radio</td><td><?php echo htmlspecialchars($inc['radio_channel'] ?: '—'); ?></td></tr>
          <tr><td style="color:#64748b">Caserne</td><td><?php echo htmlspecialchars($inc['station'] ?: '—'); ?></td></tr>
          <tr><td style="color:#64748b">Blessés</td><td><?php echo $inc['injured'] ? '<span class="lf-badge orange">Oui — co-dispatch SAMS</span>' : 'Non'; ?></td></tr>
          <tr><td style="color:#64748b">Appel</td><td><?php echo $inc['time_call'] ? date('d/m/Y H:i', strtotime($inc['time_call'])) : '—'; ?></td></tr>
          <tr><td style="color:#64748b">Clôture</td><td><?php echo $inc['time_closed'] ? date('d/m/Y H:i', strtotime($inc['time_closed'])) : '—'; ?></td></tr>
        </tbody></table>
        <?php if ($inc['description']): ?>
          <div style="margin-top:1rem;color:#cbd5e1;line-height:1.6;font-size:.9rem"><?php echo nl2br(htmlspecialchars($inc['description'])); ?></div>
        <?php endif; ?>
        <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap">
          <form method="POST" style="margin:0"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo $viewId; ?>"><input type="hidden" name="status" value="en_cours"><button class="lf-btn lf-btn-ghost lf-btn-sm" <?php echo $inc['status']==='en_cours'?'disabled':''; ?>><i class="fas fa-play"></i> En cours</button></form>
          <form method="POST" style="margin:0" data-lsfd-confirm="Clôturer cet incident ?" data-lsfd-confirm-title="Clôture" data-lsfd-confirm-ok="Clôturer" data-lsfd-confirm-danger="false"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo $viewId; ?>"><input type="hidden" name="status" value="cloture"><button class="lf-btn lf-btn-ghost lf-btn-sm" <?php echo $inc['status']==='cloture'?'disabled':''; ?>><i class="fas fa-flag-checkered"></i> Clôturer</button></form>
          <a href="<?php echo SITE_URL; ?>/pages/panels/lsfd/rapports.php?incident=<?php echo $viewId; ?>" class="lf-btn lf-btn-primary lf-btn-sm"><i class="fas fa-file-pen"></i> Rédiger le rapport</a>
        </div>
      </div>

      <div class="lf-card">
        <div class="lf-card-h"><h3><i class="fas fa-helmet-safety"></i> Unités engagées (<?php echo count($units); ?>)</h3></div>
        <table class="lf-table">
          <thead><tr><th>Agent</th><th>Rôle</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($units)): ?>
              <tr class="lf-empty-row"><td colspan="4">Aucune unité engagée</td></tr>
            <?php else: foreach ($units as $u):
              $us = $unitStatusLabels[$u['unit_status']] ?? [ucfirst((string)$u['unit_status']),'gray']; ?>
              <tr>
                <td><?php echo htmlspecialchars($agentName[$u['user_id']] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($u['role'] ?: '—'); ?></td>
                <td>
                  <form method="POST" style="margin:0;display:flex;gap:.3rem;align-items:center">
                    <input type="hidden" name="action" value="unit_status"><input type="hidden" name="id" value="<?php echo $viewId; ?>"><input type="hidden" name="unit_id" value="<?php echo $u['id']; ?>">
                    <select name="unit_status" onchange="this.form.submit()" style="max-width:150px">
                      <?php foreach ($unitStatusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $u['unit_status']===$k?'selected':''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td style="text-align:right">
                  <form method="POST" style="margin:0" data-lsfd-confirm="Retirer cette unité ?"><input type="hidden" name="action" value="remove_unit"><input type="hidden" name="id" value="<?php echo $viewId; ?>"><input type="hidden" name="unit_id" value="<?php echo $u['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-xmark"></i></button></form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <form method="POST" style="margin-top:1rem;border-top:1px solid rgba(100,116,139,.12);padding-top:1rem">
          <input type="hidden" name="action" value="add_unit"><input type="hidden" name="id" value="<?php echo $viewId; ?>">
          <div class="lf-grid-2">
            <div class="lf-field"><label>Agent</label>
              <select name="user_id" data-search="true">
                <option value="">— Sélectionner —</option>
                <?php foreach ($agents as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars(($a['rp_name'] ?: 'Profil non renseigné').' · '.($a['faction_rank']?:'')); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="lf-field"><label>Rôle</label>
              <select name="role">
                <option value="Commandant de scène">Commandant de scène</option>
                <option value="Équipe attaque">Équipe attaque</option>
                <option value="Équipe sécurité">Équipe sécurité</option>
                <option value="Équipe médicale">Équipe médicale</option>
              </select>
            </div>
          </div>
          <button class="lf-btn lf-btn-primary lf-btn-sm"><i class="fas fa-plus"></i> Engager l'unité</button>
        </form>
      </div>
    </div>

<?php else:
// ════════════════════════════════ MODE LISTE ════════════════════════════════
  $filter = $_GET['status'] ?? '';
  $where = in_array($filter, ['ouvert','en_cours','cloture'], true) ? "WHERE status='".$filter."'" : '';
  $incidents = [];
  try { $incidents = $db->query("SELECT * FROM lsfd_incidents $where ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
?>
    <div class="lf-pagehead">
      <div>
        <div class="lf-ph-title"><i class="fas fa-truck-fast"></i> Interventions</div>
        <div class="lf-ph-sub"><?php echo count($incidents); ?> incident<?php echo count($incidents)>1?'s':''; ?></div>
      </div>
      <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newIncModal').classList.add('open')"><i class="fas fa-plus"></i> Nouvel incident</button>
    </div>

    <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
      <a href="<?php echo $selfUrl; ?>" class="lf-btn <?php echo $filter===''?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Tous</a>
      <a href="<?php echo $selfUrl; ?>?status=ouvert" class="lf-btn <?php echo $filter==='ouvert'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Ouverts</a>
      <a href="<?php echo $selfUrl; ?>?status=en_cours" class="lf-btn <?php echo $filter==='en_cours'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">En cours</a>
      <a href="<?php echo $selfUrl; ?>?status=cloture" class="lf-btn <?php echo $filter==='cloture'?'lf-btn-primary':'lf-btn-ghost'; ?> lf-btn-sm">Clôturés</a>
    </div>

    <div class="lf-card">
      <table class="lf-table">
        <thead><tr><th>Réf.</th><th>Catégorie</th><th>Type</th><th>Priorité</th><th>Adresse</th><th>Statut</th><th>Appel</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($incidents)): ?>
            <tr class="lf-empty-row"><td colspan="8"><i class="fas fa-inbox"></i> Aucun incident</td></tr>
          <?php else: foreach ($incidents as $inc):
            $cat = $catLabels[$inc['category']] ?? [ucfirst($inc['category']),'gray','fa-circle'];
            $pr  = $prioLabels[$inc['priority']] ?? [ucfirst($inc['priority']),'gray'];
            $stt = $statusLabels[$inc['status']] ?? [ucfirst($inc['status']),'gray']; ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($inc['ref'] ?: '—'); ?></strong></td>
              <td><span class="lf-badge <?php echo $cat[1]; ?>"><?php echo htmlspecialchars($cat[0]); ?></span></td>
              <td><?php echo htmlspecialchars($inc['type']); ?></td>
              <td><span class="lf-badge <?php echo $pr[1]; ?>"><?php echo htmlspecialchars($pr[0]); ?></span></td>
              <td><?php echo htmlspecialchars($inc['address'] ?: '—'); ?></td>
              <td><span class="lf-badge <?php echo $stt[1]; ?>"><?php echo htmlspecialchars($stt[0]); ?></span></td>
              <td style="color:#64748b;font-size:.78rem"><?php echo $inc['time_call'] ? date('d/m H:i', strtotime($inc['time_call'])) : '—'; ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a href="<?php echo $selfUrl; ?>?id=<?php echo $inc['id']; ?>" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-eye"></i></a>
                <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer l'incident <?php echo htmlspecialchars($inc['ref']); ?> ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $inc['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Modal nouvel incident -->
    <div class="lf-modal-ov" id="newIncModal">
      <div class="lf-modal">
        <div class="lf-modal-h"><h3><i class="fas fa-fire"></i> Nouvel incident</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
        <form method="POST">
          <input type="hidden" name="action" value="create">
          <div class="lf-modal-b">
            <div class="lf-grid-2">
              <div class="lf-field"><label>Type d'incident</label>
                <select name="type" id="incType" data-search="true" required>
                  <?php foreach ($tax['types'] as $catKey=>$list): ?>
                    <optgroup label="<?php echo htmlspecialchars($tax['categories'][$catKey][0]); ?>">
                    <?php foreach ($list as $t): ?>
                      <option value="<?php echo htmlspecialchars($t); ?>" data-cat="<?php echo $catKey; ?>"><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="lf-field"><label>Catégorie (auto)</label>
                <select name="category" id="incCat" required>
                  <?php foreach ($tax['categories'] as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($v[0]); ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Priorité</label>
                <select name="priority"><option value="normale">Normale</option><option value="haute">Haute</option><option value="critique">Critique</option><option value="basse">Basse</option></select>
              </div>
              <div class="lf-field"><label>Caserne</label><input class="lf-input" name="station" placeholder="Station 1 / 2 / 3"></div>
            </div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Adresse</label><input class="lf-input" name="address" placeholder="Lieu de l'intervention"></div>
              <div class="lf-field"><label>Coordonnées</label><input class="lf-input" name="coords" placeholder="GPS / repère"></div>
            </div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Déclarant (appel 911)</label><input class="lf-input" name="caller_name" placeholder="Nom du déclarant"></div>
              <div class="lf-field"><label>Canal radio</label><input class="lf-input" name="radio_channel" placeholder="Ex: Canal 3"></div>
            </div>
            <div class="lf-field"><label>Description</label><textarea class="lf-textarea" name="description" placeholder="Détails de l'appel…"></textarea></div>
            <input type="hidden" name="injured" id="injuredInput">
            <span class="lsfd-switch" data-target="injuredInput"><span class="sw-track"></span><span class="sw-lbl">Blessés (déclenche un co-dispatch SAMS)</span></span>
          </div>
          <div class="lf-modal-f">
            <button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button>
            <button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Créer l'incident</button>
          </div>
        </form>
      </div>
    </div>
<?php endif; ?>

<script>
// Déduit automatiquement la catégorie depuis le type sélectionné.
(function(){
  const cat = document.getElementById('incCat'); const type = document.getElementById('incType');
  if (!cat || !type) return;
  function sync() {
    const opt = type.options[type.selectedIndex];
    if (opt && opt.dataset.cat) { cat.value = opt.dataset.cat; cat.dispatchEvent(new Event('change', {bubbles:true})); }
  }
  type.addEventListener('change', sync); sync();
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
