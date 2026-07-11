<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = "Certificats d'Aptitude";
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $data = ['type_aptitude'=>$_POST['type_aptitude'],'resultat'=>$_POST['resultat']??'Apte'];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,valid_until,status) VALUES ('aptitude',?,?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],json_encode($data),trim($_POST['observations']??''),$_POST['valid_from'],$_POST['valid_until']]);
            $msg = "Certificat d'aptitude créé."; $msgType = 'success';
        } catch(Exception $e){ $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='aptitude'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Certificat supprimé.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$certs=[];try{$certs=$db->query("SELECT mc.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='aptitude' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
$total=count($certs); $actifs=count(array_filter($certs,fn($c)=>$c['status']==='active'));
$TYPES=['Aptitude au travail','Aptitude au sport','Aptitude à la conduite','Aptitude médicale générale','Aptitude port d\'arme','Autre'];

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-clipboard-check"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Valides</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="yellow"><div class="kpi-ico"><i class="fas fa-hourglass-half"></i></div><div class="kpi-lbl">Expirés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Certificat</button>
</div>
<?php if(empty($certs)):?><div class="empty-state"><i class="fas fa-clipboard-check empty-icon"></i><p>Aucun certificat d'aptitude</p></div>
<?php else: foreach($certs as $item):
    $data=json_decode($item['data']??'{}',true)?:[];
    $init=strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
    $debut=$item['valid_from']?new DateTime($item['valid_from']):null;
    $fin=$item['valid_until']?new DateTime($item['valid_until']):null;
    $now=new DateTime(); $expired=$fin&&$fin<$now&&$item['status']==='active';
    $sc=match($item['status']??'active'){'active'=>($expired?'badge-yellow':'badge-green'),'completed'=>'badge-blue',default=>'badge-gray'};
    $sl=match($item['status']??'active'){'active'=>($expired?'Expiré':'Valide'),'completed'=>'Archivé',default=>'Annulé'};
?>
<div class="doc-card">
    <div class="doc-card-hdr">
        <div class="doc-card-left">
            <div class="doc-avatar"><?php echo $init;?></div>
            <div>
                <div class="doc-name"><?php echo htmlspecialchars($item['firstname'].' '.$item['lastname']);?></div>
                <div class="doc-meta">
                    <span><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($item['doctor_name']??'—');?></span>
                    <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y',strtotime($item['created_at']));?></span>
                </div>
            </div>
        </div>
        <div class="doc-card-right">
            <?php if(!empty($data['type_aptitude'])):?><span class="badge badge-purple" style="margin-right:.35rem"><?php echo htmlspecialchars($data['type_aptitude']);?></span><?php endif;?>
            <span class="badge <?php echo $sc;?>"><?php echo $sl;?></span>
        </div>
    </div>
    <?php if($debut&&$fin):?>
    <div class="doc-dates">
        <div class="doc-date-box"><div class="doc-date-label">Valable du</div><div class="doc-date-val"><?php echo $debut->format('d/m/Y');?></div></div>
        <span class="doc-date-arrow">→</span>
        <div class="doc-date-box"><div class="doc-date-label">Au</div><div class="doc-date-val" style="<?php echo $expired?'color:var(--red-l)':'';?>"><?php echo $fin->format('d/m/Y');?></div></div>
        <?php if($expired):?><span class="badge badge-red">EXPIRÉ</span><?php endif;?>
    </div>
    <?php endif;?>
    <?php if(!empty($data['resultat'])):?><div class="doc-tags"><span class="badge <?php echo $data['resultat']==='Apte'?'badge-green':'badge-red';?>"><i class="fas fa-<?php echo $data['resultat']==='Apte'?'check':'times';?>"></i> <?php echo htmlspecialchars($data['resultat']);?></span></div><?php endif;?>
    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?><a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Voir le document</a><?php else:?><span class="btn btn-ghost btn-sm" style="cursor:default;opacity:.4"><i class="fas fa-file-circle-xmark"></i> Pas de document</span><?php endif;?>
        <?php if($item['status']==='active'):?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-archive"></i> Archiver</button></form>
        <?php endif;?>
        <form method="POST" data-confirm data-confirm-title="Supprimer le certificat" data-confirm-target="<?php echo htmlspecialchars($item['firstname'].' '.$item['lastname']);?>" data-confirm-ok="Supprimer" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<div id="addModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-clipboard-check"></i> Nouveau Certificat d'Aptitude</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Patient</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label required">Type d'aptitude</label>
            <select name="type_aptitude" class="form-select" required><option value="">Sélectionner…</option><?php foreach($TYPES as $t):?><option value="<?php echo $t;?>"><?php echo $t;?></option><?php endforeach;?></select>
        </div>
        <div class="form-group"><label class="form-label required">Résultat</label>
            <select name="resultat" class="form-select" required><option value="Apte">Apte</option><option value="Inapte">Inapte</option><option value="Apte avec restrictions">Apte avec restrictions</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Valable à partir du</label><input type="date" name="valid_from" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label class="form-label required">Valable jusqu'au</label><input type="date" name="valid_until" class="form-input" required></div>
        <div class="form-group form-full"><label class="form-label">Observations / Commentaires</label><textarea name="observations" class="form-textarea" rows="3"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>