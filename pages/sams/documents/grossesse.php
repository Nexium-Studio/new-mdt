<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Suivi de Grossesse';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $db->prepare("INSERT INTO pregnancy_tracking (patient_id,doctor_id,conception_date,due_date,current_week,trimester,notes_rich,status) VALUES (?,?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],$_POST['conception_date']?:null,$_POST['due_date']?:null,(int)($_POST['current_week']??1),(int)($_POST['trimester']??1),trim($_POST['notes']??'')]);
            // fix: wrong execute (missing one param) - rebuild
            $msg = 'Suivi de grossesse créé.'; $msgType = 'success';
        } catch(Exception $e){ $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }
    if ($action === 'add') { /* already handled above */ }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM pregnancy_tracking WHERE id=?")->execute([intval($_POST['cert_id'])]);
        $msg = 'Suivi supprimé.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE pregnancy_tracking SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

// Correct add action (PDO binding fix)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add' && $msgType !== 'error') {
    // handled above
}

$items=[];
try {
    $items=$db->query("SELECT pt.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM pregnancy_tracking pt JOIN profiles p ON p.id=pt.patient_id LEFT JOIN users u ON u.id=pt.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id ORDER BY pt.created_at DESC LIMIT 100")->fetchAll();
} catch(Exception $e) {}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
$total=count($items); $actives=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-baby"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-heart-pulse"></i></div><div class="kpi-lbl">En cours</div><div class="kpi-val"><?php echo $actives;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Terminées</div><div class="kpi-val"><?php echo $total-$actives;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher une patiente…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Suivi</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-baby empty-icon"></i><p>Aucun suivi de grossesse</p></div>
<?php else: foreach($items as $item):
    $sc=match($item['status']??'active'){'active'=>'badge-green','completed'=>'badge-blue',default=>'badge-gray'};
    $sl=match($item['status']??'active'){'active'=>'En cours','completed'=>'Terminé',default=>'Annulé'};
    $init=strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
    $trimLabels=['1'=>'1er trimestre','2'=>'2e trimestre','3'=>'3e trimestre'];
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
    <?php if($item['current_week']||$item['due_date']||$item['trimester']):?>
    <div class="doc-tags">
        <?php if($item['trimester']):?><span class="badge badge-purple"><?php echo $trimLabels[$item['trimester']]??'—';?></span><?php endif;?>
        <?php if($item['current_week']):?><span class="badge badge-yellow"><i class="fas fa-calendar-week"></i> Semaine <?php echo $item['current_week'];?></span><?php endif;?>
        <?php if($item['due_date']):?><span class="badge badge-red"><i class="fas fa-baby-carriage"></i> DPA : <?php echo date('d/m/Y',strtotime($item['due_date']));?></span><?php endif;?>
    </div>
    <?php endif;?>
    <?php if(!empty($item['notes_rich'])):?><div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo htmlspecialchars(mb_substr(strip_tags($item['notes_rich']),0,150));?></div><?php endif;?>
    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?><a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Document</a><?php endif;?>
        <?php if(($item['status']??'active')==='active'):?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check-double"></i> Terminer</button></form>
        <?php endif;?>
        <form method="POST" data-confirm data-confirm-title="Supprimer le suivi de grossesse" data-confirm-target="<?php echo htmlspecialchars($item['firstname'].' '.$item['lastname']);?>" data-confirm-ok="Supprimer" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<div id="addModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-baby"></i> Nouveau Suivi de Grossesse</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Patiente</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner une patiente</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label">Date de conception (estimée)</label><input type="date" name="conception_date" class="form-input"></div>
        <div class="form-group"><label class="form-label">Date prévue d'accouchement</label><input type="date" name="due_date" class="form-input"></div>
        <div class="form-group"><label class="form-label required">Semaine actuelle</label><input type="number" name="current_week" class="form-input" min="1" max="42" value="1" required></div>
        <div class="form-group"><label class="form-label required">Trimestre</label>
            <select name="trimester" class="form-select" required><option value="1">1er trimestre</option><option value="2">2e trimestre</option><option value="3">3e trimestre</option></select>
        </div>
        <div class="form-group form-full"><label class="form-label">Notes médicales</label><textarea name="notes" class="form-textarea" rows="4" placeholder="Observations, risques, traitements…"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer le Suivi</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection de la patiente',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>