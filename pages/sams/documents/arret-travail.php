<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Arrêts de Travail';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

/**
 * Crée une absence LSPD validée (motif « Arrêt de travail », couleur violette)
 * lorsque le patient d'un arrêt de travail est un agent LSPD. Idempotent : pas
 * de doublon pour le même agent et les mêmes dates. Best-effort — un échec ne
 * doit jamais empêcher la création de l'arrêt.
 */
if (!function_exists('sams_arret_sync_lspd_absence')) {
    function sams_arret_sync_lspd_absence(PDO $db, int $patientId, string $patientName, string $start, string $end): void {
        if ($patientId <= 0) return;
        $d1 = $start ? date('Y-m-d', strtotime($start)) : '';
        $d2 = $end   ? date('Y-m-d', strtotime($end))   : '';
        if ($d1 === '' || $d2 === '') return;
        try {
            // Colonnes annexes (couleur d'événement + provenance) — idempotent (MariaDB).
            try { $db->exec("ALTER TABLE `lspd_absences` ADD COLUMN IF NOT EXISTS `color` VARCHAR(20) NULL DEFAULT NULL"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE `lspd_absences` ADD COLUMN IF NOT EXISTS `source` VARCHAR(30) NULL DEFAULT NULL"); } catch (Throwable $e) {}

            // Le patient est-il un agent LSPD ? Personnage lié en priorité, sinon compte.
            $agent = null;
            $s = $db->prepare("SELECT user_id, id AS character_id, avatar, firstname, lastname FROM characters WHERE profile_id=? AND faction='LSPD' AND is_deleted=0 LIMIT 1");
            $s->execute([$patientId]);
            $c = $s->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $agent = ['user_id'=>(int)$c['user_id'],'character_id'=>$c['character_id']?:null,'avatar'=>$c['avatar']??null,
                          'name'=>trim(($c['firstname']??'').' '.($c['lastname']??''))];
            } else {
                $s = $db->prepare("SELECT id AS user_id, avatar FROM users WHERE profile_id=? AND faction='LSPD' LIMIT 1");
                $s->execute([$patientId]);
                $u = $s->fetch(PDO::FETCH_ASSOC);
                if ($u) $agent = ['user_id'=>(int)$u['user_id'],'character_id'=>null,'avatar'=>$u['avatar']??null,'name'=>''];
            }
            if (!$agent) return;

            $agentName = $agent['name'] !== '' ? $agent['name'] : $patientName;
            $uid = (int) $agent['user_id'];

            // Dé-doublonnage : même agent, même arrêt.
            $chk = $db->prepare("SELECT id FROM lspd_absences WHERE user_id=? AND source='sams_arret' AND date_debut=? AND date_fin=? LIMIT 1");
            $chk->execute([$uid, $d1, $d2]);
            if ($chk->fetchColumn()) return;

            $db->prepare("INSERT INTO lspd_absences (user_id,character_id,profile_id,agent_name,agent_avatar,date_debut,date_fin,motif,statut,color,source,notif_seen)
                          VALUES (?,?,?,?,?,?,?,?,'validee',?, 'sams_arret',1)")
               ->execute([$uid, $agent['character_id'], $patientId, $agentName, $agent['avatar'], $d1, $d2, 'Arrêt de travail', '#8b5cf6']);
        } catch (Throwable $e) {
            error_log('sams_arret_sync_lspd_absence: ' . $e->getMessage());
        }
    }
}

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        try {
            $patientId = intval($_POST['patient_id']);
            $data = [
                'repos_complet'      => isset($_POST['repos_complet']) ? 1 : 0,
                'sorties_autorisees' => isset($_POST['sorties_autorisees']) ? 1 : 0,
                'motif'              => trim($_POST['motif'] ?? ''),
            ];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,valid_until,status) VALUES ('arret_travail',?,?,?,?,?,?,'active')")
               ->execute([$patientId,$_SESSION['user_id'],json_encode($data),trim($_POST['diagnostic']??''),$_POST['date_debut'],$_POST['date_fin']]);
            $certId = $db->lastInsertId();
            $msg = "Arrêt de travail #".str_pad($certId,5,'0',STR_PAD_LEFT)." créé avec succès."; $msgType = 'success';

            // Génération automatique du document (logo SAMS via le helper centralisé)
            $patient = sams_patient_row($db, $patientId);
            $pname   = trim(($patient['firstname']??'').' '.($patient['lastname']??''));
            $doctorName = sams_doctor_name($db,$_SESSION['user_id']);
            $d1 = new DateTime(date('Y-m-d', strtotime($_POST['date_debut'])));
            $d2 = new DateTime(date('Y-m-d', strtotime($_POST['date_fin'])));
            $duree = max(1, $d1->diff($d2)->days + 1);
            $ref = sprintf('ATT-%s-%04d', date('Ym'), (int)$certId);
            $content  = '<p style="color:#6b7280;margin-bottom:1rem">Rédigé à <strong>Los Santos</strong> le '.date('d/m/Y').'</p>';
            $content .= '<div class="doc-letter">';
            $content .= '<p>Je soussigné(e) <span class="hl">'.htmlspecialchars($doctorName !== '' ? $doctorName : 'le médecin').'</span>, médecin agréé du SAMS, déclare par la présente que <span class="hl">'.htmlspecialchars($pname).'</span> est actuellement en arrêt de travail pour une durée de <span class="hl">'.$duree.' jour'.($duree>1?'s':'').'</span>, à compter du <span class="hl">'.date('d/m/Y',strtotime($_POST['date_debut'])).'</span> et jusqu\'au <span class="hl">'.date('d/m/Y',strtotime($_POST['date_fin'])).'</span>'.($data['motif']!==''?', pour le motif suivant :':'.').'</p>';
            if ($data['motif'] !== '') $content .= '<p>- '.htmlspecialchars($data['motif']).'</p>';
            if (trim($_POST['diagnostic']??'') !== '') $content .= '<p>'.nl2br(htmlspecialchars(trim($_POST['diagnostic']))).'</p>';
            $content .= '<p>Il est demandé à l\'employeur de prendre en compte cet arrêt de travail et de ne pas solliciter le patient durant cette période, sous peine de poursuites.</p>';
            $flags = [];
            if ($data['repos_complet'])      $flags[] = 'Repos complet prescrit';
            if ($data['sorties_autorisees']) $flags[] = 'Sorties autorisées';
            if ($flags) $content .= '<p style="color:#6b7280;font-size:.82rem">'.htmlspecialchars(implode(' · ', $flags)).'</p>';
            $content .= '</div>';
            $meta = [
                'layout'         => 'letter',
                'doc_label'      => 'Arrêt de travail : '.$pname,
                'reference'      => $ref,
                'date'           => date('d/m/Y'),
                'signatory_name' => $doctorName,
                'signatory_sub'  => 'SAMS — Los Santos, San Andreas',
                'foot_note'      => 'SAMS — Document officiel · Los Santos, San Andreas · '.$ref,
            ];
            $msg .= sams_doc_link(sams_generate_document($db,'medical_certificates',(int)$certId,"Arrêt de Travail — ".$pname,$content,'arret_travail',$meta));

            // Si le patient est un agent LSPD : absence validée automatique (violette)
            sams_arret_sync_lspd_absence($db, $patientId, $pname, $_POST['date_debut'], $_POST['date_fin']);
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