<?php
/**
 * §10 — Prévention & inspections
 * Registre des inspections (commerces, industries, bâtiments publics), rapports de
 * non-conformité avec délais, certificats de conformité, permis de manifestation.
 * Liens : Gouvernement (amendes/fermetures) et panneaux Entreprises.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
requireLogin();

$panelCfg = require __DIR__ . '/config.php';
require_once dirname(__DIR__, 3) . '/config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance()->getConnection();
$selfUrl = SITE_URL . '/pages/panels/lsfd/inspections.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $kind = in_array($_POST['kind']??'',['inspection','permis_manifestation'],true)?$_POST['kind']:'inspection';
            $st = in_array($_POST['status']??'',['en_cours','conforme','non_conforme','ferme'],true)?$_POST['status']:'en_cours';
            $db->prepare("INSERT INTO lsfd_inspections (establishment, address, kind, nonconformities, deadline, status, certificate, created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([trim($_POST['establishment']??''), trim($_POST['address']??''), $kind, trim($_POST['nonconformities']??''),
                trim($_POST['deadline']??'')?:null, $st, (int)!empty($_POST['certificate']), $_SESSION['user_id']??null]);
            lsfd_log('create','inspection',(int)$db->lastInsertId(),'Inspection');
            $_SESSION['lsfd_flash']='Inspection enregistrée.'; header("Location: $selfUrl"); exit;
        }
        if ($action === 'status') {
            $st=in_array($_POST['status']??'',['en_cours','conforme','non_conforme','ferme'],true)?$_POST['status']:'en_cours';
            $cert=$st==='conforme'?1:0;
            $db->prepare("UPDATE lsfd_inspections SET status=?, certificate=GREATEST(certificate,?) WHERE id=?")->execute([$st,$cert,(int)($_POST['id']??0)]);
            header("Location: $selfUrl"); exit;
        }
        if ($action === 'certificate') { $db->prepare("UPDATE lsfd_inspections SET certificate=1, status='conforme' WHERE id=?")->execute([(int)($_POST['id']??0)]); lsfd_log('certificate','inspection',(int)($_POST['id']??0),'Certificat délivré'); $_SESSION['lsfd_flash']='Certificat de conformité délivré (visible côté entreprise).'; header("Location: $selfUrl"); exit; }
        if ($action === 'delete') { $db->prepare("DELETE FROM lsfd_inspections WHERE id=?")->execute([(int)($_POST['id']??0)]); $_SESSION['lsfd_flash']='Inspection supprimée.'; header("Location: $selfUrl"); exit; }
    } catch (Exception $e) { $_SESSION['lsfd_flash']="Erreur (migration ?) : ".$e->getMessage(); header("Location: $selfUrl"); exit; }
}
if (!empty($_SESSION['lsfd_flash'])) { $flash=$_SESSION['lsfd_flash']; unset($_SESSION['lsfd_flash']); }

$rows = [];
try { $rows = $db->query("SELECT * FROM lsfd_inspections ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}

$statusLabels = ['en_cours'=>['En cours','orange'],'conforme'=>['Conforme','green'],'non_conforme'=>['Non conforme','red'],'ferme'=>['Fermé','gray']];

$pageTitle = 'Prévention & inspections';
include __DIR__ . '/includes/header.php';
?>
<?php if ($flash): ?><div class="lf-badge blue" style="display:block;padding:.8rem 1rem;margin-bottom:1rem"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="lf-pagehead">
  <div><div class="lf-ph-title"><i class="fas fa-clipboard-check"></i> Prévention & inspections</div><div class="lf-ph-sub"><?php echo count($rows); ?> inspection(s)</div></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?php echo SITE_URL; ?>/pages/gouv" class="lf-btn lf-btn-ghost"><i class="fas fa-landmark"></i> Gouvernement</a>
    <button class="lf-btn lf-btn-primary" onclick="document.getElementById('newInspModal').classList.add('open')"><i class="fas fa-plus"></i> Nouvelle inspection</button>
  </div>
</div>

<div class="lf-card">
  <table class="lf-table">
    <thead><tr><th>Établissement</th><th>Type</th><th>Non-conformités</th><th>Délai</th><th>Statut</th><th>Certif.</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr class="lf-empty-row"><td colspan="7"><i class="fas fa-clipboard"></i> Aucune inspection</td></tr>
      <?php else: foreach ($rows as $r):
        $stt=$statusLabels[$r['status']]??[ucfirst($r['status']),'gray']; ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($r['establishment']); ?></strong><br><span style="color:#64748b;font-size:.75rem"><?php echo htmlspecialchars($r['address'] ?: ''); ?></span></td>
          <td><span class="lf-badge <?php echo $r['kind']==='permis_manifestation'?'purple':'blue'; ?>"><?php echo $r['kind']==='permis_manifestation'?'Permis manif.':'Inspection'; ?></span></td>
          <td style="max-width:260px"><?php echo htmlspecialchars(mb_strimwidth((string)$r['nonconformities'],0,90,'…')) ?: '—'; ?></td>
          <td style="color:#64748b;font-size:.78rem"><?php echo $r['deadline'] ? date('d/m/Y', strtotime($r['deadline'])) : '—'; ?></td>
          <td>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
              <select name="status" onchange="this.form.submit()" style="max-width:150px">
                <?php foreach ($statusLabels as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $r['status']===$k?'selected':''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td><?php echo $r['certificate'] ? '<span class="lf-badge green"><i class="fas fa-certificate"></i></span>' : '—'; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!$r['certificate']): ?><form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Délivrer un certificat de conformité ?" data-lsfd-confirm-danger="false"><input type="hidden" name="action" value="certificate"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-ghost lf-btn-sm" title="Délivrer certificat"><i class="fas fa-certificate"></i></button></form><?php endif; ?>
            <form method="POST" style="display:inline;margin:0" data-lsfd-confirm="Supprimer cette inspection ?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><button class="lf-btn lf-btn-danger lf-btn-sm"><i class="fas fa-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="lf-modal-ov" id="newInspModal">
  <div class="lf-modal">
    <div class="lf-modal-h"><h3><i class="fas fa-clipboard-check"></i> Nouvelle inspection</h3><button type="button" class="lf-x" onclick="this.closest('.lf-modal-ov').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="create">
      <div class="lf-modal-b">
        <div class="lf-grid-2">
          <div class="lf-field"><label>Établissement</label><input class="lf-input" name="establishment" required></div>
          <div class="lf-field"><label>Type</label><select name="kind"><option value="inspection">Inspection</option><option value="permis_manifestation">Permis de manifestation</option></select></div>
        </div>
        <div class="lf-field"><label>Adresse</label><input class="lf-input" name="address"></div>
        <div class="lf-field"><label>Non-conformités relevées</label><textarea class="lf-textarea" name="nonconformities"></textarea></div>
        <div class="lf-grid-2">
          <div class="lf-field"><label>Délai de mise en conformité</label><input type="date" name="deadline"></div>
          <div class="lf-field"><label>Statut</label><select name="status"><option value="en_cours">En cours</option><option value="conforme">Conforme</option><option value="non_conforme">Non conforme</option></select></div>
        </div>
        <input type="hidden" name="certificate" id="inspCert">
        <span class="lsfd-switch" data-target="inspCert"><span class="sw-track"></span><span class="sw-lbl">Délivrer un certificat de conformité</span></span>
      </div>
      <div class="lf-modal-f"><button type="button" class="lf-btn lf-btn-ghost" onclick="this.closest('.lf-modal-ov').classList.remove('open')">Annuler</button><button type="submit" class="lf-btn lf-btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';
