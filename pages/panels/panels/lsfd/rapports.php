<?php
/**
 * §5 — Rapports d'intervention LSFD (post-incident)
 * CRUD sur lsfd_reports : narratif, causes, victimes (profiles/SAMS), dommages,
 * ressources, observations, signatures, tags. Indicateur de suspicion criminelle
 * (→ transmission LSPD). Mode liste + ?id=X (fiche) + ?incident=X (pré-rempli).
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/rapports.php';

// Tags actifs
$tags = [];
try { $tags = $db->query("SELECT id, label, color FROM lsfd_report_tags WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
$tagById = []; foreach ($tags as $t) { $tagById[$t['id']] = $t; }

// Incidents (pour lier un rapport)
$incidents = [];
try { $incidents = $db->query("SELECT id, ref, type FROM lsfd_incidents ORDER BY created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

// Citoyens (victimes) — table partagée profiles
$citizens = [];
try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname, firstname LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $incidentId = (int)($_POST['incident_id'] ?? 0) ?: null;
            $title = trim($_POST['title'] ?? '');
            $type  = trim($_POST['type'] ?? 'Intervention');
            $tagSel = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
            $vicSel = array_values(array_filter(array_map('intval', explode(',', $_POST['victims'] ?? ''))));
            $suspect = (int)!empty($_POST['suspect_crime']);
            $fields = [
                $incidentId, $title, $type, json_encode($tagSel),
                trim($_POST['summary']??''), trim($_POST['causes']??''), json_encode($vicSel),
                trim($_POST['damages']??''), trim($_POST['resources']??''), trim($_POST['observations']??''),
                $suspect, trim($_POST['signatures']??''),
            ];
            if ($action === 'create') {
                $st = $db->prepare("INSERT INTO lsfd_reports
                    (incident_id, title, type, tags, summary, causes, victims, damages, resources, observations, suspect_crime, signatures, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute(array_merge($fields, [$_SESSION['user_id'] ?? null]));
                $rid = (int)$db->lastInsertId();
                lsfd_log('create','report',$rid,"Rapport « $title »");
                $_SESSION['lsfd_flash'] = 'Rapport créé.' . ($suspect ? ' Suspicion criminelle signalée au LSPD.' : '');
                header("Location: $selfUrl?id=$rid"); exit;
            } else {
                $rid = (int)($_POST['id'] ?? 0);
                $st = $db->prepare("UPDATE lsfd_reports SET incident_id=?, title=?, type=?, tags=?, summary=?, causes=?, victims=?, damages=?, resources=?, observations=?, suspect_crime=?, signatures=? WHERE id=?");
                $st->execute(array_merge($fields, [$rid]));
                lsfd_log('update','report',$rid,"Rapport modifié");
                $_SESSION['lsfd_flash'] = 'Rapport mis à jour.';
                header("Location: $selfUrl?id=$rid"); exit;
            }
        }
        if ($action === 'delete') {
            $rid = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM lsfd_reports WHERE id=?")->execute([$rid]);
            lsfd_log('delete','report',$rid,'Rapport supprimé');
            $_SESSION['lsfd_flash'] = 'Rapport supprimé.';
            header("Location: $selfUrl"); exit;
        }
    } catch (Exception $e) {
        $_SESSION['lsfd_flash'] = "Erreur (migration non appliquée ?) : " . $e->getMessage();
        header("Location: $selfUrl"); exit;
    }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash = $_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$citName = function($id) use ($citizens) {
    foreach ($citizens as $c) if ((int)$c['id'] === (int)$id) return trim(($c['firstname']??'').' '.($c['lastname']??'')) ?: 'Profil #'.$id;
    return 'Profil #'.$id;
};

$viewId = (int)($_GET['id'] ?? 0);
$prefillIncident = (int)($_GET['incident'] ?? 0);
$pageTitle = "Rapports d'intervention";
include __DIR__ . '/includes/header.php';
?>

<?php if ($flash): ?>
<div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<?php
if ($viewId):
  $rep = null;
  try { $s = $db->prepare("SELECT r.*, i.ref AS inc_ref FROM lsfd_reports r LEFT JOIN lsfd_incidents i ON i.id=r.incident_id WHERE r.id=?"); $s->execute([$viewId]); $rep = $s->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
  if (!$rep): ?>
    <div class="lf-empty"><i class="fas fa-triangle-exclamation"></i><p>Rapport introuvable.</p><a href="<?php echo $selfUrl; ?>" class="lf-btn lf-btn-ghost">Retour</a></div>
  <?php else:
    $rtags = json_decode($rep['tags'] ?? '[]', true) ?: [];
    $rvics = json_decode($rep['victims'] ?? '[]', true) ?: [];
  ?>
    <div class="lf-pagehead">
      <div>
        <div class="lf-ph-title"><i class="fas fa-file-lines"></i> <?php echo htmlspecialchars($rep['title'] ?: 'Rapport'); ?></div>
        <div class="lf-ph-sub"><?php echo htmlspecialchars($rep['type']); ?><?php echo $rep['inc_ref'] ? ' · Incident '.htmlspecialchars($rep['inc_ref']) : ''; ?> · <?php echo date('d/m/Y H:i', strtotime($rep['created_at'])); ?></div>
      </div>
      <a href="<?php echo $selfUrl; ?>" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-arrow-left"></i> Liste</a>
    </div>

    <?php if ($rep['suspect_crime']): ?>
    <div class="lf-badge red" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-gavel"></i> Suspicion criminelle — ce rapport peut alimenter une enquête LSPD/DOJ.</div>
    <?php endif; ?>

    <div class="lf-card">
      <?php if ($rtags): ?>
      <div style="margin-bottom:1rem;display:flex;gap:.4rem;flex-wrap:wrap">
        <?php foreach ($rtags as $tid): if(isset($tagById[$tid])): $t=$tagById[$tid]; $c=htmlspecialchars($t['color']); ?>
          <span class="lf-badge" style="color:<?php echo $c; ?>;border-color:<?php echo $c; ?>55;background:<?php echo $c; ?>1a"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($t['label']); ?></span>
        <?php endif; endforeach; ?>
      </div>
      <?php endif; ?>

      <?php
      $blocks = [
        ['Résumé narratif', $rep['summary']],
        ["Causes de l'incident", $rep['causes']],
        ['Dommages constatés', $rep['damages']],
        ['Ressources utilisées', $rep['resources']],
        ['Observations & recommandations', $rep['observations']],
        ['Signatures des agents', $rep['signatures']],
      ];
      foreach ($blocks as $b): if (trim((string)$b[1]) !== ''): ?>
        <div style="margin-bottom:1.2rem">
          <div class="lf-sec-title" style="margin:0 0 .4rem"><?php echo htmlspecialchars($b[0]); ?></div>
          <div style="color:#cbd5e1;line-height:1.6;font-size:.9rem"><?php echo nl2br(htmlspecialchars($b[1])); ?></div>
        </div>
      <?php endif; endforeach; ?>

      <?php if ($rvics): ?>
      <div style="margin-bottom:1rem">
        <div class="lf-sec-title" style="margin:0 0 .4rem">Victimes / personnes prises en charge</div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          <?php foreach ($rvics as $vid): ?><span class="lf-badge orange"><i class="fas fa-user"></i> <?php echo htmlspecialchars($citName($vid)); ?></span><?php endforeach; ?>
        </div>
        <div style="color:#64748b;font-size:.78rem;margin-top:.5rem"><i class="fas fa-link"></i> Profils liés au dossier médical SAMS partagé.</div>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:.5rem;margin-top:1rem;border-top:1px solid rgba(100,116,139,.12);padding-top:1rem">
        <form method="POST" data-lsfd-confirm="Supprimer ce rapport ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $viewId; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i> Supprimer</button></form>
      </div>
    </div>

<?php else:
  $reports = [];
  try { $reports = $db->query("SELECT r.*, i.ref AS inc_ref,
      NULLIF(TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))),'') AS author
      FROM lsfd_reports r LEFT JOIN lsfd_incidents i ON i.id=r.incident_id
      LEFT JOIN users u ON u.id=r.created_by LEFT JOIN profiles p ON p.id=u.profile
      ORDER BY r.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
?>
    <div class="lf-pagehead">
      <div>
        <div class="lf-ph-title"><i class="fas fa-file-lines"></i> Rapports d'intervention</div>
        <div class="lf-ph-sub"><?php echo count($reports); ?> rapport<?php echo count($reports)>1?'s':''; ?></div>
      </div>
      <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newRepModal').classList.add('open')"><i class="fas fa-plus"></i> Nouveau rapport</button>
    </div>

    <div class="lf-card">
      <table class="lf-table">
        <thead><tr><th>Titre</th><th>Type</th><th>Incident</th><th>Auteur</th><th>Date</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($reports)): ?>
            <tr class="lf-empty-row"><td colspan="6"><i class="fas fa-inbox"></i> Aucun rapport</td></tr>
          <?php else: foreach ($reports as $r): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($r['title'] ?: '—'); ?></strong> <?php echo $r['suspect_crime'] ? '<span class="lf-badge red" style="font-size:.6rem">CRIMINEL</span>' : ''; ?></td>
              <td><?php echo htmlspecialchars($r['type']); ?></td>
              <td><?php echo htmlspecialchars($r['inc_ref'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars($r['author'] ?: '—'); ?></td>
              <td style="color:#64748b;font-size:.78rem"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a href="<?php echo $selfUrl; ?>?id=<?php echo $r['id']; ?>" class="lf-btn lf-btn-ghost lf-btn-sm"><i class="fas fa-eye"></i></a>
                <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer ce rapport ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Modal nouveau rapport -->
    <div class="lf-modal-ov <?php echo $prefillIncident ? 'open' : ''; ?>" id="newRepModal">
      <div class="lf-modal">
        <div class="lf-modal-h"><h3><i class="fas fa-file-pen"></i> Nouveau rapport</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
        <form method="POST">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="victims" id="repVictims">
          <div class="lf-modal-b">
            <div class="lf-grid-2">
              <div class="lf-field"><label>Titre</label><input class="lf-input" name="title" required placeholder="Titre du rapport"></div>
              <div class="lf-field"><label>Type</label>
                <select name="type"><option>Intervention</option><option>Secours</option><option>HazMat</option><option>Sauvetage</option><option>Autre</option></select>
              </div>
            </div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Incident lié</label>
                <select name="incident_id" data-search="true">
                  <option value="">— Aucun —</option>
                  <?php foreach ($incidents as $i): ?><option value="<?php echo $i['id']; ?>" <?php echo $prefillIncident===(int)$i['id']?'selected':''; ?>><?php echo htmlspecialchars(($i['ref']?:'#'.$i['id']).' · '.$i['type']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="lf-field"><label>Tags</label>
                <select name="tags[]" multiple data-placeholder="Sélectionner des tags…">
                  <?php foreach ($tags as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['label']); ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="lf-field"><label>Résumé narratif</label><textarea class="lf-textarea" name="summary" placeholder="Déroulé de l'intervention…"></textarea></div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Causes</label><textarea class="lf-textarea" name="causes"></textarea></div>
              <div class="lf-field"><label>Dommages constatés</label><textarea class="lf-textarea" name="damages"></textarea></div>
            </div>
            <div class="lf-grid-2">
              <div class="lf-field"><label>Ressources utilisées</label><textarea class="lf-textarea" name="resources" placeholder="Eau, mousse, extincteurs…"></textarea></div>
              <div class="lf-field"><label>Observations & recommandations</label><textarea class="lf-textarea" name="observations"></textarea></div>
            </div>
            <div class="lf-field">
              <label>Victimes / personnes prises en charge</label>
              <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                <button type="button" class="lf-btn lf-btn-ghost lf-btn-sm" id="repVicBtn"><i class="fas fa-user-plus"></i> Ajouter</button>
                <span id="repVicChips" style="display:flex;gap:.4rem;flex-wrap:wrap"></span>
              </div>
            </div>
            <div class="lf-field"><label>Signatures des agents impliqués</label><input class="lf-input" name="signatures" placeholder="Noms des agents"></div>
            <input type="hidden" name="suspect_crime" id="suspectInput">
            <span class="lsfd-switch" data-target="suspectInput"><span class="sw-track"></span><span class="sw-lbl">Suspicion criminelle (transmission LSPD/DOJ)</span></span>
          </div>
          <div class="lf-modal-f">
            <button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button>
            <button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Enregistrer</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    (function(){
      const CIT = <?php echo json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>trim(($c['firstname']??'').' '.($c['lastname']??'')) ?: ('Profil #'.$c['id']),'avatar'=>$c['avatar']??'']; }, $citizens), JSON_UNESCAPED_UNICODE); ?>;
      let selected = [];
      function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
      function render(){
        document.getElementById('repVictims').value = selected.map(x=>x.id).join(',');
        document.getElementById('repVicChips').innerHTML = selected.map(x =>
          `<span class="lp-field-chip">${esc(x.name)}<span class="x" data-id="${x.id}"><i class="fas fa-times"></i></span></span>`).join('');
        document.querySelectorAll('#repVicChips .x').forEach(el => el.onclick = () => { selected = selected.filter(s=>String(s.id)!==el.dataset.id); render(); });
      }
      const btn = document.getElementById('repVicBtn');
      if (btn) btn.addEventListener('click', () => LsfdPicker.open({
        title:'Victimes', multiple:true, selected:selected.map(s=>s.id),
        items: CIT.map(c=>({id:c.id,name:c.name,avatar:c.avatar})),
        onConfirm:(chosen)=>{ selected = chosen.map(c=>({id:c.id,name:c.name})); render(); }
      }));
    })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php';
