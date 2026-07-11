<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Arrêts de Travail';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        try {
            $data = [
                'repos_complet'      => isset($_POST['repos_complet']) ? 1 : 0,
                'sorties_autorisees' => isset($_POST['sorties_autorisees']) ? 1 : 0,
                'motif'              => trim($_POST['motif'] ?? ''),
            ];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,valid_until,status) VALUES ('arret_travail',?,?,?,?,?,?,'active')")
               ->execute([intval($_POST['patient_id']),$_SESSION['user_id'],json_encode($data),trim($_POST['diagnostic']??''),$_POST['date_debut'],$_POST['date_fin']]);
            $certId = $db->lastInsertId();
            $msg = "Arrêt de travail #".str_pad($certId,5,'0',STR_PAD_LEFT)." créé avec succès."; $msgType = 'success';
            // Google Docs generation (optional)
            if (class_exists('GoogleDocsGenerator')) {
                try {
                    $generator = new GoogleDocsGenerator();
                    $p = $db->prepare("SELECT firstname,lastname,age FROM profiles WHERE id=?"); $p->execute([intval($_POST['patient_id'])]); $patient=$p->fetch();
                    $d = $db->prepare("SELECT pr.firstname,pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id=?"); $d->execute([$_SESSION['user_id']]); $doctor=$d->fetch(); $doctorName=mdt_rp_display_name($doctor['firstname']??null,$doctor['lastname']??null,'');
                    $content = "<h1>Arrêt de Travail Médical</h1><p><strong>Patient :</strong> ".htmlspecialchars($patient['firstname'].' '.$patient['lastname'])."</p>";
                    $content .= "<p><strong>Période :</strong> Du ".date('d/m/Y',strtotime($_POST['date_debut']))." au ".date('d/m/Y',strtotime($_POST['date_fin']))."</p>";
                    $content .= "<p><strong>Repos complet :</strong> ".(isset($_POST['repos_complet'])?'Oui':'Non')."</p>";
                    $content .= "<p><strong>Sorties autorisées :</strong> ".(isset($_POST['sorties_autorisees'])?'Oui':'Non')."</p>";
                    $content .= "<hr><p>".($data['motif'])."</p><p><strong>Dr. ".htmlspecialchars($doctorName ?: '')."</strong></p>";
                    $url = $generator->generateDocument("Arrêt de Travail — ".($patient['firstname']??'')." ".($patient['lastname']??''), $content, 'arret_travail');
                    if ($url) { $db->prepare("UPDATE medical_certificates SET google_docs_url=? WHERE id=?")->execute([$url,$certId]); $msg .= ' <a href="'.htmlspecialchars($url).'" target="_blank" style="color:var(--red-l);text-decoration:underline">Voir le document →</a>'; }
                } catch(Exception $e) {}
            }
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='arret_travail'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Arrêt supprimé.'; $msgType = 'success';
    }

    if ($action === 'toggle_status') {
        $id = intval($_POST['cert_id']); $new = $_POST['new_status'];
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$new,$id]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}
if (isset($_GET['msg'])) { $msg = htmlspecialchars($_GET['msg']); $msgType = $_GET['type'] ?? 'success'; }

// ── DATA ─────────────────────────────────────────────────────────────────────
$arrets = [];
try { $arrets = $db->query("SELECT mc.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='arret_travail' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll(); } catch(Exception $e) {}
$patients = [];
try { $patients = $db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll(); } catch(Exception $e) {}

$total  = count($arrets);
$actifs = count(array_filter($arrets, fn($a) => $a['status'] === 'active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-bed"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Terminés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>

<!-- Toolbar -->
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher un patient…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouvel Arrêt</button>
</div>

<!-- Liste -->
<?php if(empty($arrets)):?>
<div class="empty-state"><i class="fas fa-bed empty-icon"></i><p>Aucun arrêt de travail enregistré</p></div>
<?php else: foreach($arrets as $item):
    $data   = json_decode($item['data']??'{}',true) ?: [];
    $init   = strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
    $debut  = $item['valid_from'] ? new DateTime($item['valid_from']) : null;
    $fin    = $item['valid_until'] ? new DateTime($item['valid_until']) : null;
    $duree  = ($debut&&$fin) ? $debut->diff($fin)->days : null;
    $sc     = match($item['status']??'active'){'active'=>'badge-green','completed'=>'badge-blue',default=>'badge-gray'};
    $sl     = match($item['status']??'active'){'active'=>'Actif','completed'=>'Terminé',default=>'Annulé'};
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
                    <span><i class="fas fa-hashtag"></i> <?php echo str_pad($item['id'],5,'0',STR_PAD_LEFT);?></span>
                </div>
            </div>
        </div>
        <div class="doc-card-right">
            <span class="badge <?php echo $sc;?>"><?php echo $sl;?></span>
        </div>
    </div>

    <?php if($debut&&$fin):?>
    <div class="doc-dates">
        <div class="doc-date-box"><div class="doc-date-label">Début</div><div class="doc-date-val"><?php echo $debut->format('d/m/Y');?></div></div>
        <span class="doc-date-arrow">→</span>
        <div class="doc-date-box"><div class="doc-date-label">Fin</div><div class="doc-date-val"><?php echo $fin->format('d/m/Y');?></div></div>
        <?php if($duree!==null):?><div class="doc-duration"><i class="fas fa-clock"></i> <?php echo $duree;?> jour(s)</div><?php endif;?>
    </div>
    <?php endif;?>

    <?php if(!empty($data['repos_complet'])||!empty($data['sorties_autorisees'])||!empty($data['motif'])):?>
    <div class="doc-tags">
        <?php if(!empty($data['repos_complet'])):?><span class="badge badge-red"><i class="fas fa-bed"></i> Repos complet</span><?php endif;?>
        <?php if(!empty($data['sorties_autorisees'])):?><span class="badge badge-green"><i class="fas fa-walking"></i> Sorties autorisées</span><?php endif;?>
        <?php if(!empty($data['motif'])):?><span class="badge badge-gray"><?php echo htmlspecialchars(mb_substr($data['motif'],0,40));?></span><?php endif;?>
    </div>
    <?php endif;?>

    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?>
        <a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Voir le document</a>
        <?php else:?>
        <span class="btn btn-ghost btn-sm" style="cursor:default;opacity:.45"><i class="fas fa-file-circle-xmark"></i> Pas de document</span>
        <?php endif;?>

        <!-- Toggle statut -->
        <?php if($item['status']==='active'):?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <input type="hidden" name="new_status" value="completed">
            <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check-double"></i> Marquer terminé</button>
        </form>
        <?php endif;?>

        <!-- Suppression -->
        <form method="POST" data-confirm data-confirm-title="Supprimer l'arrêt de travail"
              data-confirm-target="<?php echo htmlspecialchars($item['firstname'].' '.$item['lastname']);?> — #<?php echo str_pad($item['id'],5,'0',STR_PAD_LEFT);?>"
              data-confirm-ok="Supprimer" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Supprimer</button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<!-- MODAL CRÉATION -->
<div id="addModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-bed"></i> Nouvel Arrêt de Travail</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required"><i class="fas fa-user-injured"></i> Patient</label>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="docPatChip" style="margin-top:.5rem"></div>
        </div>
        <div class="form-group"><label class="form-label required"><i class="fas fa-calendar-day"></i> Date de début</label><input type="date" name="date_debut" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label class="form-label required"><i class="fas fa-calendar-check"></i> Date de fin</label><input type="date" name="date_fin" class="form-input" required></div>
        <div class="form-group"><input type="hidden" name="repos_complet" id="reposInput" value=""><div class="sams-switch" data-target="reposInput"><div class="sw-track"></div><div class="sw-lbl">Repos complet prescrit</div></div></div>
        <div class="form-group"><input type="hidden" name="sorties_autorisees" id="sortiesInput" value=""><div class="sams-switch" data-target="sortiesInput"><div class="sw-track"></div><div class="sw-lbl">Sorties autorisées</div></div></div>
        <div class="form-group form-full"><label class="form-label"><i class="fas fa-tag"></i> Motif (résumé)</label><input type="text" name="motif" class="form-input" placeholder="Ex: Lombalgies, fracture…"></div>
        <div class="form-group form-full"><label class="form-label required"><i class="fas fa-stethoscope"></i> Diagnostic / Détails</label><textarea name="diagnostic" class="form-textarea" rows="4" placeholder="Détails médicaux de l'arrêt de travail…" required></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer l'Arrêt</button></div>
</form></div></div>

<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>