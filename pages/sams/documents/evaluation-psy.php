<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Évaluations Psychologiques';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $data=['type_evaluation'=>$_POST['type_evaluation'],'tests_realises'=>$_POST['tests_realises']??'','resultat_global'=>$_POST['resultat_global'],'recommandations'=>$_POST['recommandations']??''];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,status) VALUES ('psy_eval',?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],json_encode($data),trim($_POST['conclusions']??''),date('Y-m-d')]);
            $msg = 'Évaluations créé(e) avec succès.'; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='psy_eval'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Supprimé avec succès.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$items=[];try{$items=$db->query("SELECT mc.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='psy_eval' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
$total=count($items); $actifs=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-notes-medical"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Terminés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouvelle Évaluation</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-notes-medical empty-icon"></i><p>Aucun enregistrement</p></div>
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
    
    <?php if(!empty($data['type_evaluation'])||!empty($data['resultat_global'])):?><div class="doc-tags"><?php if(!empty($data['type_evaluation'])):?><span class="badge badge-purple"><i class="fas fa-clipboard-list"></i> <?php echo htmlspecialchars($data['type_evaluation']);?></span><?php endif;?><?php if(!empty($data['resultat_global'])):?><span class="badge <?php echo in_array($data['resultat_global'],['Favorable','Très favorable'])?'badge-green':'badge-yellow';?>"><i class="fas fa-chart-bar"></i> <?php echo htmlspecialchars($data['resultat_global']);?></span><?php endif;?></div><?php endif;?>
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
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-notes-medical"></i> Nouvelle Évaluation Psychologique</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Patient</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label required">Type d'évaluation</label>
            <select name="type_evaluation" class="form-select" required><option value="">Sélectionner…</option><option>Évaluation initiale</option><option>Évaluation de suivi</option><option>Bilan de compétences</option><option>Évaluation neuropsychologique</option><option>Évaluation pour aptitude</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Résultat global</label>
            <select name="resultat_global" class="form-select" required><option value="Favorable">Favorable</option><option value="Très favorable">Très favorable</option><option value="Réservé">Réservé</option><option value="Défavorable">Défavorable</option></select>
        </div>
        <div class="form-group form-full"><label class="form-label">Tests réalisés</label><input type="text" name="tests_realises" class="form-input" placeholder="HAD, MMSE, BDI…"></div>
        <div class="form-group form-full"><label class="form-label">Recommandations</label><textarea name="recommandations" class="form-textarea" rows="2" placeholder="Suivi, thérapie, médication…"></textarea></div>
        <div class="form-group form-full"><label class="form-label required">Conclusions détaillées</label><textarea name="conclusions" class="form-textarea" rows="4" required placeholder="Analyse des résultats, observations cliniques…"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>