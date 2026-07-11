<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Facturation';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='create') {
        try {
            $db->prepare("INSERT INTO avocat_invoices (client_id,dossier_id,avocat_id,service,amount,statut,notes) VALUES (?,?,?,?,?,'pending',?)")
               ->execute([intval($_POST['client_id'])?:null,intval($_POST['dossier_id'])?:null,$uid,trim($_POST['service']),floatval($_POST['amount']),trim($_POST['notes']??'')]);
            $msg='Facture créée.'; $msgType='ok';
        } catch(Exception $e){$msg='Erreur : '.$e->getMessage();$msgType='err';}
    }
    if ($action==='pay') {
        $db->prepare("UPDATE avocat_invoices SET statut='paid',paid_at=NOW() WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Facture marquée payée.'; $msgType='ok';
    }
    if ($action==='cancel') {
        $db->prepare("UPDATE avocat_invoices SET statut='cancelled' WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Facture annulée.'; $msgType='ok';
    }
    if ($action==='delete' && $__isPartner) {
        $db->prepare("DELETE FROM avocat_invoices WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Supprimée.'; $msgType='ok';
    }
}

$filterStatut=$_GET['statut']??'all';
$q="SELECT ai.*,p.firstname,p.lastname,u.username AS avocat_name,ad.numero AS dos_num
    FROM avocat_invoices ai
    LEFT JOIN avocat_clients ac ON ac.id=ai.client_id
    LEFT JOIN profiles p ON p.id=ac.profile_id
    LEFT JOIN users u ON u.id=ai.avocat_id
    LEFT JOIN avocat_dossiers ad ON ad.id=ai.dossier_id
    WHERE 1=1";
$params=[];
if($filterStatut!=='all'){$q.=" AND ai.statut=?";$params[]=$filterStatut;}
$q.=" ORDER BY ai.created_at DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$invoices=$stmt->fetchAll();

$statMap=[];
foreach($db->query("SELECT statut,COUNT(*) as n,COALESCE(SUM(amount),0) as total FROM avocat_invoices GROUP BY statut")->fetchAll() as $r)
    $statMap[$r['statut']]=['n'=>$r['n'],'total'=>$r['total']];

$allClients=$db->query("SELECT ac.id,p.firstname,p.lastname FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id WHERE ac.statut='actif' ORDER BY p.lastname")->fetchAll();
$allDossiers=$db->query("SELECT id,numero,titre FROM avocat_dossiers WHERE statut NOT IN ('ferme','gagne','perdu') ORDER BY updated_at DESC")->fetchAll();
$SERVICES=['Consultation juridique','Représentation tribunal','Rédaction de contrat','Préparation de dossier','Recherche juridique','Plaidoirie','Conseil penal','Autre'];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="margin-bottom:1.4rem">
    <div class="kpi"><div class="kpi-lbl">Total facturé</div><div class="kpi-val gold">$<?php echo number_format(array_sum(array_column($statMap,'total')),0,'.',',');?></div></div>
    <div class="kpi"><div class="kpi-lbl">Payées</div><div class="kpi-val green">$<?php echo number_format($statMap['paid']['total']??0,0,'.',',');?></div><div class="kpi-sub"><?php echo $statMap['paid']['n']??0;?> facture(s)</div></div>
    <div class="kpi"><div class="kpi-lbl">Impayées</div><div class="kpi-val red">$<?php echo number_format($statMap['pending']['total']??0,0,'.',',');?></div><div class="kpi-sub"><?php echo $statMap['pending']['n']??0;?> facture(s)</div></div>
    <div class="kpi"><div class="kpi-lbl">Taux recouvrement</div><div class="kpi-val"><?php $tot=array_sum(array_column($statMap,'total'));echo $tot>0?number_format(($statMap['paid']['total']??0)/$tot*100,1).'%':'—';?></div></div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.5rem">
        <?php foreach(['all'=>'Toutes','pending'=>'Impayées','paid'=>'Payées','cancelled'=>'Annulées'] as $s=>$l):?>
        <a href="?statut=<?php echo $s;?>" class="btn btn-sm <?php echo $filterStatut===$s?'btn-primary':'btn-ghost';?>"><?php echo $l;?></a>
        <?php endforeach;?>
    </div>
    <?php if(!empty($allClients)):?>
    <button onclick="Cabinet.openModal('newInvModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle facture</button>
    <?php endif;?>
</div>

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>#</th><th>Client</th><th>Service</th><th>Dossier</th><th>Avocat</th><th>Montant</th><th>Statut</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($invoices)):?>
        <tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune facture</td></tr>
        <?php else:?>
        <?php foreach($invoices as $inv):
            $stCls=['paid'=>'bg-green','pending'=>'bg-red','cancelled'=>'bg-gray'][$inv['statut']]??'bg-gray';
            $stLbl=['paid'=>'Payée','pending'=>'Impayée','cancelled'=>'Annulée'][$inv['statut']]??$inv['statut'];
        ?>
        <tr>
            <td class="mono" style="font-size:.7rem;color:var(--txt-m)">#<?php echo $inv['id'];?></td>
            <td style="font-weight:600"><?php echo htmlspecialchars(($inv['firstname']??'').' '.($inv['lastname']??''));?></td>
            <td style="font-size:.82rem"><?php echo htmlspecialchars($inv['service']);?></td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($inv['dos_num']??'—');?></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($inv['avocat_name']??'—');?></td>
            <td class="mono" style="font-weight:700;color:<?php echo $inv['statut']==='paid'?'#4ade80':'#f5edd6';?>">$<?php echo number_format($inv['amount'],2,'.',',');?></td>
            <td><span class="badge <?php echo $stCls;?>"><?php echo $stLbl;?></span></td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/y',strtotime($inv['created_at']));?></td>
            <td>
                <div style="display:flex;gap:.3rem">
                    <?php if($inv['statut']==='pending'):?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?php echo $inv['id'];?>"><button type="submit" class="btn btn-success btn-sm" title="Marquer payée"><i class="fas fa-check"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo $inv['id'];?>"><button type="submit" class="btn btn-ghost btn-sm" title="Annuler"><i class="fas fa-ban"></i></button></form>
                    <?php endif;?>
                    <?php if($__isPartner):?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $inv['id'];?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form>
                    <?php endif;?>
                </div>
            </td>
        </tr>
        <?php endforeach;?>
        <?php endif;?>
        </tbody>
    </table>
</div>

<?php if(!empty($allClients)):?>
<div id="newInvModal" class="modal-ov hidden">
    <div class="modal"><div class="modal-hd"><h2><i class="fas fa-file-invoice-dollar"></i> Nouvelle facture</h2><button class="modal-cl" onclick="Cabinet.closeModal('newInvModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Client *</label><select name="client_id" class="form-select" required>
                <option value="">Sélectionner…</option>
                <?php foreach($allClients as $c):?><option value="<?php echo $c['id'];?>"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Dossier lié</label><select name="dossier_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($allDossiers as $d):?><option value="<?php echo $d['id'];?>"><?php echo htmlspecialchars($d['numero'].' — '.mb_substr($d['titre'],0,35));?></option><?php endforeach;?></select></div>
            <div class="form-group form-full"><label class="form-label">Service *</label>
                <input type="text" name="service" class="form-input" required placeholder="Ex: Défense tribunal" list="servicesList">
                <datalist id="servicesList"><?php foreach($SERVICES as $s):?><option value="<?php echo $s;?>"><?php endforeach;?></datalist>
            </div>
            <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="amount" class="form-input" step="0.01" min="0" required placeholder="5000.00"></div>
            <div class="form-group form-full"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" placeholder="Précisions…"></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('newInvModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
    </form></div>
</div>
<?php endif;?>

<?php include __DIR__ . '/includes/footer.php';?>