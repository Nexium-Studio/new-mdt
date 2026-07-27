<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Certificats de Décès';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$db->exec("CREATE TABLE IF NOT EXISTS `death_certificates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `numero` VARCHAR(30) UNIQUE,
  `deceased_name` VARCHAR(200) NOT NULL,
  `date_of_birth` DATE,
  `date_of_death` DATE NOT NULL,
  `time_of_death` TIME,
  `place_of_death` VARCHAR(200),
  `manner_of_death` ENUM('natural','accident','suicide','homicide','unknown') DEFAULT 'unknown',
  `cause_of_death` TEXT,
  `secondary_causes` TEXT,
  `certifying_doc` INT,
  `status` ENUM('active','archived') DEFAULT 'active',
  `google_docs_url` TEXT,
  `notes` TEXT,
  `patient_id` INT NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Colonne patient_id (lien vers le profil sélectionné) — idempotent pour les bases existantes.
try { $db->exec("ALTER TABLE `death_certificates` ADD COLUMN IF NOT EXISTS `patient_id` INT NULL DEFAULT NULL"); } catch(Exception $e){}
$dcHasPatient=false; try { $db->query("SELECT `patient_id` FROM death_certificates LIMIT 0"); $dcHasPatient=true; } catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            // Défunt = profil sélectionné (même sélecteur que les autres documents)
            $patientId = intval($_POST['patient_id'] ?? 0);
            $patient   = $patientId > 0 ? sams_patient_row($db, $patientId) : [];
            $deceasedName = trim(($patient['firstname']??'').' '.($patient['lastname']??''));
            if ($deceasedName === '') $deceasedName = trim($_POST['deceased_name'] ?? '');
            if ($deceasedName === '') { throw new Exception('Veuillez sélectionner le défunt.'); }

            $year=(int)date('Y');
            $seq=(int)$db->query("SELECT COUNT(*)+1 FROM death_certificates WHERE YEAR(created_at)=$year")->fetchColumn();
            $num=sprintf("DC-%d-%04d",$year,$seq);
            $cols=['numero','deceased_name','date_of_birth','date_of_death','time_of_death','place_of_death','manner_of_death','cause_of_death','secondary_causes','certifying_doc','notes'];
            $vals=[$num,$deceasedName,$_POST['date_of_birth']?:null,$_POST['date_of_death'],trim($_POST['time_of_death']??''),trim($_POST['place_of_death']??''),$_POST['manner_of_death'],trim($_POST['cause_of_death']??''),trim($_POST['secondary_causes']??''),$_SESSION['user_id'],trim($_POST['notes']??'')];
            if ($dcHasPatient) { $cols[]='patient_id'; $vals[]=$patientId ?: null; }
            $ph=implode(',',array_fill(0,count($cols),'?'));
            $db->prepare("INSERT INTO death_certificates (".implode(',',$cols).") VALUES ($ph)")->execute($vals);
            $certId = $db->lastInsertId();
            $msg="Certificat de décès $num enregistré."; $msgType='success';
            // Génération automatique du document
            $mannerLabels=['natural'=>'Naturelle','accident'=>'Accident','suicide'=>'Suicide','homicide'=>'Homicide','unknown'=>'Inconnue'];
            $dname   = $deceasedName;
            $content  = "<h1>Certificat de Décès</h1>";
            $content .= "<p><strong>N° :</strong> ".htmlspecialchars($num)."</p>";
            $content .= "<p><strong>Défunt :</strong> ".htmlspecialchars($dname)."</p>";
            if(!empty($_POST['date_of_birth'])) $content .= "<p><strong>Date de naissance :</strong> ".date('d/m/Y',strtotime($_POST['date_of_birth']))."</p>";
            $content .= "<p><strong>Date du décès :</strong> ".date('d/m/Y',strtotime($_POST['date_of_death'])).(!empty($_POST['time_of_death'])?" à ".htmlspecialchars($_POST['time_of_death']):'')."</p>";
            if(!empty($_POST['place_of_death'])) $content .= "<p><strong>Lieu :</strong> ".htmlspecialchars($_POST['place_of_death'])."</p>";
            $content .= "<p><strong>Nature :</strong> ".htmlspecialchars($mannerLabels[$_POST['manner_of_death']]??($_POST['manner_of_death']??'—'))."</p>";
            if(trim($_POST['cause_of_death']??'')!=='') $content .= "<hr><h2>Cause du décès</h2><p>".nl2br(htmlspecialchars(trim($_POST['cause_of_death'])))."</p>";
            if(trim($_POST['secondary_causes']??'')!=='') $content .= "<p><strong>Causes secondaires :</strong> ".nl2br(htmlspecialchars(trim($_POST['secondary_causes'])))."</p>";
            if(trim($_POST['notes']??'')!=='') $content .= "<p><strong>Notes :</strong> ".nl2br(htmlspecialchars(trim($_POST['notes'])))."</p>";
            $content .= "<hr><p><strong>Dr. ".htmlspecialchars(sams_doctor_name($db,$_SESSION['user_id']))."</strong></p>";
            $msg .= sams_doc_link(sams_generate_document($db,'death_certificates',(int)$certId,"Certificat de Décès — ".$dname,$content,'certificat'));
        } catch(Exception $e){ $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM death_certificates WHERE id=?")->execute([intval($_POST['cert_id'])]);
        $msg='Certificat supprimé.'; $msgType='success';
    }
    if ($action === 'archive') {
        $db->prepare("UPDATE death_certificates SET status='archived' WHERE id=?")->execute([intval($_POST['cert_id'])]);
        $msg='Certificat archivé.'; $msgType='success';
    }
}

$certs=[];try{$certs=$db->query("SELECT dc.*,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM death_certificates dc LEFT JOIN users u ON u.id=dc.certifying_doc LEFT JOIN profiles cp ON cp.id=u.profile_id ORDER BY dc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$total=count($certs); $actifs=count(array_filter($certs,fn($c)=>$c['status']==='active'));
$mois=0;try{$mois=(int)$db->query("SELECT COUNT(*) FROM death_certificates WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}

$mannerLabels=['natural'=>'Naturelle','accident'=>'Accident','suicide'=>'Suicide','homicide'=>'Homicide','unknown'=>'Inconnue'];
$mannerBadge=['natural'=>'badge-green','accident'=>'badge-yellow','suicide'=>'badge-purple','homicide'=>'badge-red','unknown'=>'badge-gray'];

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-cross"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-calendar-month"></i></div><div class="kpi-lbl">Ce mois</div><div class="kpi-val"><?php echo $mois;?></div></div>
    <div class="kpi" data-color="yellow"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Archivés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher un défunt…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Certificat</button>
</div>
<?php if(empty($certs)):?><div class="empty-state"><i class="fas fa-cross empty-icon"></i><p>Aucun certificat de décès</p></div>
<?php else: foreach($certs as $item):
    $sc=$item['status']==='active'?'badge-red':'badge-gray';
    $sl=$item['status']==='active'?'Actif':'Archivé';
    $mb=$mannerBadge[$item['manner_of_death']]??'badge-gray';
    $ml=$mannerLabels[$item['manner_of_death']]??'—';
?>
<div class="doc-card">
    <div class="doc-card-hdr">
        <div class="doc-card-left">
            <div class="doc-avatar" style="background:rgba(225,29,72,.1)"><i class="fas fa-cross" style="color:var(--red-l);font-size:.85rem"></i></div>
            <div>
                <div class="doc-name"><?php echo htmlspecialchars($item['deceased_name']);?></div>
                <div class="doc-meta">
                    <span><i class="fas fa-hashtag"></i> <?php echo $item['numero']??'—';?></span>
                    <span><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($item['doctor_name']??'—');?></span>
                    <span><i class="fas fa-calendar"></i> Décès : <?php echo date('d/m/Y',strtotime($item['date_of_death']));?></span>
                    <?php if($item['time_of_death']):?><span><i class="fas fa-clock"></i> <?php echo $item['time_of_death'];?></span><?php endif;?>
                </div>
            </div>
        </div>
        <div class="doc-card-right">
            <span class="badge <?php echo $mb;?>"><?php echo $ml;?></span>
            <span class="badge <?php echo $sc;?>"><?php echo $sl;?></span>
        </div>
    </div>
    <?php if($item['place_of_death']||$item['cause_of_death']):?>
    <div class="doc-tags">
        <?php if($item['place_of_death']):?><span class="badge badge-gray"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($item['place_of_death']);?></span><?php endif;?>
        <?php if($item['cause_of_death']):?><span class="badge badge-red"><?php echo htmlspecialchars(mb_substr($item['cause_of_death'],0,50));?></span><?php endif;?>
    </div>
    <?php endif;?>
    <div class="doc-actions">
        <?php if($item['status']==='active'):?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="archive"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>"><button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-archive"></i> Archiver</button></form>
        <?php endif;?>
        <form method="POST" data-confirm data-confirm-title="Supprimer le certificat de décès" data-confirm-target="<?php echo htmlspecialchars($item['deceased_name'].' — '.$item['numero']);?>" data-confirm-ok="Supprimer définitivement" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<div id="addModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-cross"></i> Nouveau Certificat de Décès</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Défunt (patient enregistré)</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner le défunt</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label">Date de naissance</label><input type="date" name="date_of_birth" class="form-input"></div>
        <div class="form-group"><label class="form-label required">Date du décès</label><input type="date" name="date_of_death" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label class="form-label">Heure du décès</label><input type="time" name="time_of_death" class="form-input"></div>
        <div class="form-group"><label class="form-label">Lieu du décès</label><input type="text" name="place_of_death" class="form-input" placeholder="Hôpital, rue, domicile…"></div>
        <div class="form-group"><label class="form-label required">Cause (manner)</label>
            <select name="manner_of_death" class="form-select" required>
                <option value="natural">Naturelle</option><option value="accident">Accident</option><option value="suicide">Suicide</option><option value="homicide">Homicide</option><option value="unknown">Inconnue</option>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label required">Cause principale du décès</label><textarea name="cause_of_death" class="form-textarea" rows="2" required></textarea></div>
        <div class="form-group form-full"><label class="form-label">Causes secondaires</label><textarea name="secondary_causes" class="form-textarea" rows="2"></textarea></div>
        <div class="form-group form-full"><label class="form-label">Notes additionnelles</label><textarea name="notes" class="form-textarea" rows="2"></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Certifier le Décès</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du défunt',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>