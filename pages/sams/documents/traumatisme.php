<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Certificats de Traumatisme';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $data=['type_traumatisme'=>$_POST['type_traumatisme'],'gravite'=>$_POST['gravite'],'soins_requis'=>$_POST['soins_requis']??''];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,status) VALUES ('traumatisme',?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],json_encode($data),trim($_POST['description']??''),date('Y-m-d')]);
            $msg = 'Certificats créé(e) avec succès.'; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='traumatisme'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Supprimé avec succès.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$items=[];try{$items=$db->query("SELECT mc.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='traumatisme' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
$total=count($items); $actifs=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-bone"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Terminés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Certificat</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-bone empty-icon"></i><p>Aucun enregistrement</p></div>
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
    
    <?php if(!empty($data['type_traumatisme'])):?><div class="doc-tags"><span class="badge badge-red"><i class="fas fa-bone"></i> <?php echo htmlspecialchars($data['type_traumatisme']);?></span><?php if(!empty($data['gravite'])):?><span class="badge <?php echo match($data['gravite']??''){'Léger'=>'badge-green','Modéré'=>'badge-yellow','Grave'=>'badge-red','Critique'=>'badge-red',default=>'badge-gray'};?>"> <?php echo htmlspecialchars($data['gravite']);?></span><?php endif;?></div><?php endif;?>
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
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-bone"></i> Nouveau Certificat de Traumatisme</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Patient</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label required">Type de traumatisme</label>
            <select name="type_traumatisme" class="form-select" required>
                <option value="">Sélectionner…</option>
                <option>Traumatisme crânien</option><option>Fracture</option><option>Brûlure</option><option>Blessure par balle</option><option>Lacération</option><option>Traumatisme thoracique</option><option>Traumatisme abdominal</option><option>Contusion</option><option>Autre</option>
            </select>
        </div>
        <div class="form-group"><label class="form-label required">Gravité</label>
            <select name="gravite" class="form-select" required><option value="Léger">Léger</option><option value="Modéré">Modéré</option><option value="Grave">Grave</option><option value="Critique">Critique</option></select>
        </div>
        <div class="form-group form-full"><label class="form-label">Soins requis</label><input type="text" name="soins_requis" class="form-input" placeholder="Chirurgie, immobilisation…"></div>
        <div class="form-group form-full"><label class="form-label required">Description détaillée</label><textarea name="description" class="form-textarea" rows="4" required placeholder="Circonstances, lésions, examens réalisés…"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>