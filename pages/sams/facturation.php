<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Facturation';
$db = Database::getInstance()->getConnection();
$msg='';$msgType='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='create') {
        try {
            $items=[];
            foreach(($_POST['item_name']??[]) as $i=>$name){
                if(!empty(trim($name)))$items[]=['name'=>trim($name),'price'=>floatval($_POST['item_price'][$i]??0)];
            }
            if(empty($items))throw new Exception("Ajoutez au moins un soin.");
            if(empty($_POST['patient_id']))throw new Exception("Sélectionnez un patient.");
            $total=array_sum(array_column($items,'price'));
            $db->prepare("INSERT INTO sams_invoices (patient_id,items,total_amount,status,created_by) VALUES (?,?,?,'pending',?)")
               ->execute([intval($_POST['patient_id']),json_encode($items),$total,$_SESSION['user_id']]);
            $msg='Facture créée ($'.number_format($total,2).').';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update_status') {
        $extra=$_POST['status']==='paid'?',paid_at=NOW()':'';
        $db->prepare("UPDATE sams_invoices SET status=?{$extra} WHERE id=?")->execute([$_POST['status'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
    if ($action==='delete') {
        $db->prepare("DELETE FROM sams_invoices WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Facture supprimée.';$msgType='success';
    }
}

$filterStatus=$_GET['s']??'all';$search=trim($_GET['q']??'');
// Praticien affiché en Nom + Prénom (profil), jamais le pseudo (règle SAMS §5)
$q="SELECT si.*,p.firstname,p.lastname,cp.firstname AS prat_fn,cp.lastname AS prat_ln
    FROM sams_invoices si
    LEFT JOIN profiles p ON p.id=si.patient_id
    LEFT JOIN users u ON u.id=si.created_by
    LEFT JOIN profiles cp ON cp.id=u.profile_id
    WHERE 1=1";
$params=[];
if($filterStatus!=='all'){$q.=" AND si.status=?";$params[]=$filterStatus;}
if($search){$q.=" AND (p.firstname LIKE ? OR p.lastname LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
$q.=" ORDER BY si.created_at DESC LIMIT 100";
$stmt=$db->prepare($q);$stmt->execute($params);$invoices=$stmt->fetchAll();

$statMap=[];try{foreach($db->query("SELECT status,COUNT(*) as n,COALESCE(SUM(total_amount),0) as total FROM sams_invoices GROUP BY status")->fetchAll() as $r)$statMap[$r['status']]=['n'=>$r['n'],'total'=>$r['total']];}catch(Exception $e){}
$revMois=0;try{$revMois=(float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE status='paid' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();}catch(Exception $e){}

// Patients (SamsPicker)
$patientsPicker=[];
try{ foreach($db->query("SELECT id,firstname,lastname,phone,avatar FROM profiles ORDER BY lastname LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) as $p){
    $patientsPicker[]=['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname']),'sub'=>$p['phone']??'','avatar'=>$p['avatar']??null];
}}catch(Exception $e){}

// Soins fréquents (configurés dans l'admin SAMS) — fallback si table absente
$SOINS_FALLBACK=[['Consultation générale',50],['Soin de plaie',80],['Médicament',100],['Intervention chirurgicale',350],['Déplacement',50],['Radio / Imagerie',200],['Prise de sang',75],['Hospitalisation (journée)',200]];
$soins=[];
try{ foreach($db->query("SELECT nom,prix_defaut FROM sams_soins WHERE actif=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC) as $s){ $soins[]=['nom'=>$s['nom'],'prix'=>(float)$s['prix_defaut']]; } }catch(Exception $e){}
if(empty($soins)){ foreach($SOINS_FALLBACK as [$n,$p]) $soins[]=['nom'=>$n,'prix'=>(float)$p]; }

include __DIR__ . '/includes/header.php';
?>
<style>
.soin-chip{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem .8rem;border-radius:10px;cursor:pointer;
  background:rgba(225,29,72,.06);border:1px solid rgba(225,29,72,.2);color:var(--txt);font-size:.8rem;font-weight:600;transition:all .15s;font-family:'Barlow',sans-serif}
.soin-chip:hover{background:rgba(225,29,72,.14);border-color:var(--red);transform:translateY(-1px)}
.soin-chip .price{font-family:'JetBrains Mono',monospace;color:var(--green-l);font-weight:700;font-size:.78rem}
.inv-pat-chip{display:flex;align-items:center;gap:.7rem;padding:.6rem .8rem;background:rgba(225,29,72,.07);border:1px solid rgba(225,29,72,.25);border-radius:10px;margin-top:.5rem}
.inv-pat-chip.hidden{display:none}
.inv-pat-chip .ip-av{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,rgba(225,29,72,.25),rgba(159,18,57,.12));border:1px solid rgba(225,29,72,.25);display:grid;place-items:center;font-weight:700;color:var(--red-l);overflow:hidden;flex-shrink:0}
.inv-pat-chip .ip-av img{width:100%;height:100%;object-fit:cover}
.inv-pat-chip .ip-rm{margin-left:auto;width:26px;height:26px;border-radius:7px;border:1px solid var(--bdr);background:rgba(255,255,255,.04);color:var(--txt-s);cursor:pointer;display:grid;place-items:center}
.inv-pat-chip .ip-rm:hover{background:rgba(225,29,72,.18);color:var(--red-l)}
.item-row{display:flex;gap:.5rem;margin-bottom:.5rem;align-items:center}
</style>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-hourglass-half"></i></div><div class="kpi-lbl">Impayées</div><div class="kpi-val"><?php echo $statMap['pending']['n']??0;?></div><div class="kpi-sub">$<?php echo number_format($statMap['pending']['total']??0,0,'.',',');?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-circle-check"></i></div><div class="kpi-lbl">Payées</div><div class="kpi-val"><?php echo $statMap['paid']['n']??0;?></div><div class="kpi-sub">$<?php echo number_format($statMap['paid']['total']??0,0,'.',',');?></div></div>
    <div class="kpi" data-color="gray"><div class="kpi-ico"><i class="fas fa-ban"></i></div><div class="kpi-lbl">Annulées</div><div class="kpi-val"><?php echo $statMap['cancelled']['n']??0;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-sack-dollar"></i></div><div class="kpi-lbl">CA ce mois</div><div class="kpi-val">$<?php echo number_format($revMois,0,'.',',');?></div></div>
</div>

<div class="doc-toolbar">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <div class="search-wrap"><i class="fas fa-search"></i><input type="text" name="q" placeholder="Rechercher patient..." value="<?php echo htmlspecialchars($search);?>"></div>
        <select name="s" class="form-select" style="width:140px" onchange="this.form.submit()">
            <option value="all" <?php echo $filterStatus==='all'?'selected':'';?>>Tous statuts</option>
            <option value="pending" <?php echo $filterStatus==='pending'?'selected':'';?>>Impayées</option>
            <option value="paid" <?php echo $filterStatus==='paid'?'selected':'';?>>Payées</option>
            <option value="cancelled" <?php echo $filterStatus==='cancelled'?'selected':'';?>>Annulées</option>
        </select>
        <?php if($search||$filterStatus!=='all'):?><a href="facturation.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="openInv()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle facture</button>
</div>

<?php if(empty($invoices)):?>
<div class="empty-state"><i class="fas fa-receipt empty-icon"></i><p>Aucune facture.</p></div>
<?php else:?>
<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>#</th><th>Patient</th><th>Soins</th><th>Montant</th><th>Praticien</th><th>Date</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach($invoices as $inv):
            $items=json_decode($inv['items']??'[]',true)?:[];
            $sc=['paid'=>'badge-green','pending'=>'badge-yellow','cancelled'=>'badge-gray'][$inv['status']]??'badge-gray';
            $sl=['paid'=>'Payée','pending'=>'En attente','cancelled'=>'Annulée'][$inv['status']]??$inv['status'];
            $prat=mdt_rp_display_name($inv['prat_fn']??null,$inv['prat_ln']??null,''); if($prat===''||$prat==='—') $prat='—';
        ?>
        <tr>
            <td class="mono" style="color:var(--txt-m);font-size:.72rem">#<?php echo $inv['id'];?></td>
            <td style="font-weight:700"><?php echo htmlspecialchars(trim(($inv['firstname']??'').' '.($inv['lastname']??'')) ?: 'Patient inconnu');?></td>
            <td style="font-size:.78rem;color:var(--txt-s);max-width:220px"><?php echo htmlspecialchars(implode(', ',array_column($items,'name')));?></td>
            <td class="mono" style="font-weight:700;color:<?php echo $inv['status']==='paid'?'#6ee7b7':'#fde68a';?>">$<?php echo number_format($inv['total_amount'],2,'.',',');?></td>
            <td style="font-size:.78rem"><?php echo htmlspecialchars($prat);?></td>
            <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i',strtotime($inv['created_at']));?></td>
            <td><span class="badge <?php echo $sc;?>"><?php echo $sl;?></span></td>
            <td>
                <div style="display:flex;gap:.3rem">
                    <?php if($inv['status']==='pending'):?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?php echo $inv['id'];?>"><input type="hidden" name="status" value="paid"><button type="submit" class="btn btn-success btn-xs" title="Marquer payée"><i class="fas fa-check"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?php echo $inv['id'];?>"><input type="hidden" name="status" value="cancelled"><button type="submit" class="btn btn-ghost btn-xs" title="Annuler"><i class="fas fa-ban"></i></button></form>
                    <?php endif;?>
                    <button type="button" class="btn btn-danger btn-xs" title="Supprimer" onclick="deleteInvoice(<?php echo $inv['id'];?>)"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
</div>
<?php endif;?>

<!-- MODAL NOUVELLE FACTURE -->
<div id="newInvModal" class="modal-ov hidden">
    <div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-receipt"></i> Nouvelle facture</h2><button class="modal-close" onclick="Sams.closeModal('newInvModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" id="invForm"><div class="modal-bd"><input type="hidden" name="action" value="create"><input type="hidden" name="patient_id" id="invPatientId">
        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label required">Patient</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" onclick="pickInvPatient()"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
            <div class="inv-pat-chip hidden" id="invPatientChip"></div>
        </div>
        <div style="margin-bottom:.85rem">
            <label class="form-label" style="margin-bottom:.6rem;display:block">Soins fréquents — cliquez pour ajouter (prix pré-rempli)</label>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <?php foreach($soins as $s):?>
                <button type="button" class="soin-chip" onclick="addItem(<?php echo htmlspecialchars(json_encode($s['nom']),ENT_QUOTES);?>,<?php echo (float)$s['prix'];?>)">
                    <i class="fas fa-kit-medical" style="color:var(--red-l)"></i> <?php echo htmlspecialchars($s['nom']);?> <span class="price">$<?php echo number_format($s['prix'],0,'.',' ');?></span>
                </button>
                <?php endforeach;?>
            </div>
        </div>
        <div style="margin-bottom:.75rem">
            <label class="form-label" style="margin-bottom:.6rem;display:block">Lignes de la facture *</label>
            <div id="itemsList"></div>
            <button type="button" onclick="addItem()" class="btn btn-secondary btn-sm" style="margin-top:.5rem"><i class="fas fa-plus"></i> Ajouter une ligne libre</button>
        </div>
        <div style="background:var(--bg-ov);border-radius:9px;padding:.85rem 1rem;display:flex;align-items:center;justify-content:space-between;font-size:.875rem">
            <span style="color:var(--txt-s)">Total :</span>
            <strong class="mono" style="font-size:1.2rem;color:var(--green-l)" id="totalDisplay">$0.00</strong>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('newInvModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer la facture</button></div>
    </form></div>
</div>

<?php include __DIR__ . '/includes/components.php'; ?>
<script>
const INV_PATIENTS = <?php echo json_encode($patientsPicker, JSON_UNESCAPED_UNICODE); ?>;
function escapeHtmlF(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

function openInv(){
    document.getElementById('invPatientId').value='';
    const c=document.getElementById('invPatientChip');c.classList.add('hidden');c.innerHTML='';
    document.getElementById('itemsList').innerHTML='';
    calcTotal();
    Sams.openModal('newInvModal');
}
function pickInvPatient(){
    SamsPicker.open({title:'Sélection du patient',items:INV_PATIENTS,onConfirm:function(it){
        if(!it)return;
        document.getElementById('invPatientId').value=it.id;
        const c=document.getElementById('invPatientChip');
        const ava=it.avatar?('<img src="'+it.avatar+'" alt="">'):((it.name||'?').charAt(0).toUpperCase());
        c.innerHTML='<div class="ip-av">'+ava+'</div><div><div style="font-weight:700;font-size:.85rem">'+escapeHtmlF(it.name)+'</div>'+(it.sub?'<div style="font-size:.72rem;color:var(--txt-m)">'+escapeHtmlF(it.sub)+'</div>':'')+'</div><button type="button" class="ip-rm" onclick="clearInvPatient()"><i class="fas fa-times"></i></button>';
        c.classList.remove('hidden');
    }});
}
function clearInvPatient(){document.getElementById('invPatientId').value='';const c=document.getElementById('invPatientChip');c.classList.add('hidden');c.innerHTML='';}

function addItem(name='',price=''){
    const d=document.createElement('div');
    d.className='item-row';
    d.innerHTML='<input type="text" name="item_name[]" class="form-input" style="flex:1" placeholder="Description du soin..." value="'+escapeHtmlF(name)+'" oninput="calcTotal()" required>'
      +'<div style="position:relative;width:120px"><span style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-family:\'JetBrains Mono\',monospace">$</span>'
      +'<input type="number" name="item_price[]" class="form-input" style="width:120px;padding-left:1.4rem" placeholder="0" value="'+(price!==''?price:'')+'" step="1" min="0" oninput="calcTotal()" required></div>'
      +'<button type="button" onclick="this.parentNode.remove();calcTotal()" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></button>';
    document.getElementById('itemsList').appendChild(d);
    calcTotal();
}
function calcTotal(){let t=0;document.querySelectorAll('input[name="item_price[]"]').forEach(i=>t+=parseFloat(i.value)||0);document.getElementById('totalDisplay').textContent='$'+t.toFixed(2);}

document.getElementById('invForm').addEventListener('submit',function(e){
    if(!document.getElementById('invPatientId').value){e.preventDefault();showToast('Sélectionnez un patient','error');return;}
    if(!document.querySelectorAll('#itemsList .item-row').length){e.preventDefault();showToast('Ajoutez au moins un soin','error');}
});

function deleteInvoice(id){
    SamsConfirm({title:'Supprimer la facture',target:'Facture #'+id,message:'Cette action est irréversible.',okText:'Supprimer',onConfirm:function(){
        const f=document.createElement('form');f.method='POST';f.style.display='none';
        f.innerHTML='<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }});
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>
