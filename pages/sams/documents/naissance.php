<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Certificats de Naissance';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $data=['prenom_bebe'=>$_POST['prenom_bebe'],'nom_bebe'=>$_POST['nom_bebe'],'sexe'=>$_POST['sexe'],'poids'=>$_POST['poids']??'','taille'=>$_POST['taille']??'','nom_pere'=>$_POST['nom_pere']??'','nom_mere'=>$_POST['nom_mere']??''];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,status) VALUES ('naissance',?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],json_encode($data),trim($_POST['observations']??''),$_POST['date_naissance']]);
            $certId = $db->lastInsertId();
            $msg = 'Certificats créé(e) avec succès.'; $msgType = 'success';
            // Génération automatique du document
            $patient = sams_patient_row($db, $_POST['patient_id']);
            $bebe    = trim(($data['prenom_bebe']??'').' '.($data['nom_bebe']??''));
            $content  = "<h1>Certificat de Naissance</h1>";
            $content .= "<p><strong>Enfant :</strong> ".htmlspecialchars($bebe)." — ".htmlspecialchars($data['sexe']??'')."</p>";
            $content .= "<p><strong>Date de naissance :</strong> ".date('d/m/Y',strtotime($_POST['date_naissance']))."</p>";
            if(!empty($data['poids'])||!empty($data['taille'])) $content .= "<p><strong>Poids :</strong> ".htmlspecialchars($data['poids']?:'—')." kg — <strong>Taille :</strong> ".htmlspecialchars($data['taille']?:'—')." cm</p>";
            $content .= "<p><strong>Mère :</strong> ".htmlspecialchars(trim(($patient['firstname']??'').' '.($patient['lastname']??'')))."</p>";
            if(!empty($data['nom_pere'])) $content .= "<p><strong>Père :</strong> ".htmlspecialchars($data['nom_pere'])."</p>";
            if(trim($_POST['observations']??'')!=='') $content .= "<hr><h2>Observations</h2><p>".nl2br(htmlspecialchars(trim($_POST['observations'])))."</p>";
            $content .= "<hr><p><strong>Dr. ".htmlspecialchars(sams_doctor_name($db,$_SESSION['user_id']))."</strong></p>";
            $msg .= sams_doc_link(sams_generate_document($db,'medical_certificates',(int)$certId,"Certificat de Naissance — ".$bebe,$content,'naissance'));
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='naissance'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Supprimé avec succès.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$items=[];try{$items=$db->query("SELECT mc.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='naissance' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
$total=count($items); $actifs=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-baby"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Terminés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Certificat</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-baby empty-icon"></i><p>Aucun enregistrement</p></div>
<?php else: foreach($items as $item):
    $data=json_decode($item['data']??'{}',true)?:[];
    $init=strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
    $sc=match($item['status']??'active'){'active'=>'badge-green','completed'=>'badge-blue',default=>'badge-gray'};
    $sl=match($item['status']??'active'){'active'=>'Actif','completed'=>'Terminé',default=>'Annulé'};
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
        <div class="doc-card-right"><span class="badge <?php echo $sc;?>"><?php echo $sl;?></span></div>
    </div>
    
    <?php if(!empty($data['prenom_bebe'])||!empty($data['sexe'])):?><div class="doc-tags"><span class="badge badge-purple"><i class="fas fa-baby"></i> <?php echo htmlspecialchars(($data['prenom_bebe']??'').' '.($data['nom_bebe']??''));?></span><?php if(!empty($data['sexe'])):?><span class="badge <?php echo $data['sexe']==='Garçon'?'badge-blue':'badge-red';?>"><?php echo htmlspecialchars($data['sexe']);?></span><?php endif;?><?php if(!empty($data['poids'])):?><span class="badge badge-gray"><i class="fas fa-weight-scale"></i> <?php echo htmlspecialchars($data['poids']);?></span><?php endif;?></div><?php endif;?>
    <?php if(!empty($item['description_rich'])):?>
    <div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo htmlspecialchars(mb_substr(strip_tags($item['description_rich']??''),0,180));?><?php if(mb_strlen(strip_tags($item['description_rich']??''))>180) echo '…';?></div>
    <?php endif;?>
    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?><a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Voir le document</a><?php else:?><span class="btn btn-ghost btn-sm" style="cursor:default;opacity:.4"><i class="fas fa-file-circle-xmark"></i> Pas de document</span><?php endif;?>
        <?php if(($item['status']??'active')==='active'):?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check-double"></i> Terminer</button></form>
        <?php endif;?>
        <form method="POST" data-confirm data-confirm-title="Supprimer ce document" data-confirm-target="<?php echo htmlspecialchars($item['firstname'].' '.$item['lastname'].' — '.date('d/m/Y',strtotime($item['created_at'])));?>" data-confirm-ok="Supprimer" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<div id="addModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-baby"></i> Nouveau Certificat de Naissance</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Mère (patient enregistré)</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner la mère</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label required">Prénom du bébé</label><input type="text" name="prenom_bebe" class="form-input" required></div>
        <div class="form-group"><label class="form-label required">Nom du bébé</label><input type="text" name="nom_bebe" class="form-input" required></div>
        <div class="form-group"><label class="form-label required">Sexe</label>
            <select name="sexe" class="form-select" required><option value="Garçon">Garçon</option><option value="Fille">Fille</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Date de naissance</label><input type="date" name="date_naissance" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label class="form-label">Poids (kg)</label><input type="text" name="poids" class="form-input" placeholder="3.2"></div>
        <div class="form-group"><label class="form-label">Taille (cm)</label><input type="text" name="taille" class="form-input" placeholder="50"></div>
        <div class="form-group"><label class="form-label">Nom du père</label><input type="text" name="nom_pere" class="form-input"></div>
        <div class="form-group"><label class="form-label">Nom de la mère</label><input type="text" name="nom_mere" class="form-input"></div>
        <div class="form-group form-full"><label class="form-label">Observations médicales</label><textarea name="observations" class="form-textarea" rows="3"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection de la mère',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>