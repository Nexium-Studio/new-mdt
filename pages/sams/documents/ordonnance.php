<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Ordonnances';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Migration idempotente : colonne data (prescripteur + médicaments + notes structurés).
try { $db->exec("ALTER TABLE `medical_prescriptions` ADD COLUMN IF NOT EXISTS `data` LONGTEXT NULL DEFAULT NULL"); } catch(Exception $e){}
$mpHasData = false; try { $db->query("SELECT `data` FROM medical_prescriptions LIMIT 0"); $mpHasData = true; } catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            $patientId = intval($_POST['patient_id'] ?? 0);
            if ($patientId <= 0) { throw new Exception('Veuillez sélectionner un patient.'); }

            // Prescripteur
            $prescriber = [
                'nom'           => trim($_POST['presc_nom'] ?? ''),
                'prenom'        => trim($_POST['presc_prenom'] ?? ''),
                'specialite'    => trim($_POST['specialite'] ?? ''),
                'date_emission' => ($_POST['date_emission'] ?? '') ?: date('Y-m-d'),
            ];

            // Médicaments (cartes multiples → tableaux parallèles)
            $medNoms = $_POST['med_nom'] ?? []; $medDos = $_POST['med_dosage'] ?? [];
            $medDur  = $_POST['med_duree'] ?? []; $medPos = $_POST['med_posologie'] ?? [];
            $meds = [];
            for ($i = 0, $n = count($medNoms); $i < $n; $i++) {
                $nom = trim((string)($medNoms[$i] ?? ''));
                if ($nom === '') continue;
                $meds[] = [
                    'nom'       => $nom,
                    'dosage'    => trim((string)($medDos[$i] ?? '')),
                    'duree'     => trim((string)($medDur[$i] ?? '')),
                    'posologie' => trim((string)($medPos[$i] ?? '')),
                ];
            }
            if (empty($meds)) { throw new Exception('Ajoutez au moins un médicament.'); }

            $notes = trim($_POST['notes'] ?? '');
            $data  = ['prescriber' => $prescriber, 'medications' => $meds, 'notes' => $notes];

            // Résumé texte lisible (aperçu liste + espace perso)
            $summaryLines = [];
            foreach ($meds as $m) {
                $summaryLines[] = trim($m['nom']
                    . ($m['dosage']    ? ' — '.$m['dosage']    : '')
                    . ($m['duree']     ? ' — '.$m['duree']     : '')
                    . ($m['posologie'] ? ' — '.$m['posologie'] : ''));
            }
            $summary = implode("\n", $summaryLines);

            if ($mpHasData) {
                $db->prepare("INSERT INTO medical_prescriptions (patient_id,doctor_id,prescription_rich,data,status) VALUES (?,?,?,?,'active')")
                   ->execute([$patientId, $_SESSION['user_id'], $summary, json_encode($data, JSON_UNESCAPED_UNICODE)]);
            } else {
                $db->prepare("INSERT INTO medical_prescriptions (patient_id,doctor_id,prescription_rich,status) VALUES (?,?,?,'active')")
                   ->execute([$patientId, $_SESSION['user_id'], $summary]);
            }
            $certId = $db->lastInsertId();
            $msg = 'Ordonnance créée.'; $msgType = 'success';

            // Génération automatique du document (logo SAMS via le helper centralisé)
            $patient = sams_patient_row($db, $patientId);
            $pname   = trim(($patient['firstname']??'').' '.($patient['lastname']??''));
            $prescName = trim($prescriber['prenom'].' '.$prescriber['nom']);
            if ($prescName === '') $prescName = sams_doctor_name($db, $_SESSION['user_id']);

            $ref = sprintf('ORD-%s-%04d', date('Ym'), (int)$certId);
            $content  = '<div class="doc-grid2">';
            $content .= '<div class="doc-section" style="margin-bottom:0"><div class="doc-section-title">Patient</div>';
            $content .= '<div class="doc-field-value" style="font-size:1.05rem">'.htmlspecialchars($pname !== '' ? $pname : '—').'</div>';
            $content .= '<div class="doc-field" style="margin-top:.5rem"><div class="doc-field-label">N° Patient</div><div>PAT-'.str_pad((string)$patientId,6,'0',STR_PAD_LEFT).'</div></div>';
            $content .= '</div>';
            $content .= '<div class="doc-section" style="margin-bottom:0"><div class="doc-section-title">Médecin prescripteur</div>';
            $content .= '<div class="doc-field-value" style="font-size:1.05rem">'.htmlspecialchars($prescName).'</div>';
            if ($prescriber['specialite'] !== '') $content .= '<div class="doc-field-label" style="margin-top:.35rem">'.htmlspecialchars($prescriber['specialite']).'</div>';
            $content .= '<div class="doc-field-label" style="margin-top:.15rem">SAMS · Los Santos, San Andreas</div>';
            $content .= '</div></div>';
            $content .= '<div class="doc-section" style="margin-top:1.3rem"><div class="doc-section-title">Médicaments prescrits</div>';
            $content .= '<table class="doc-med-table"><thead><tr><th style="width:34px">N°</th><th>Médicament</th><th style="text-align:right">Instructions</th></tr></thead><tbody>';
            $i = 1;
            foreach ($meds as $m) {
                $sub = [];
                if ($m['dosage'] !== '') $sub[] = 'Dosage : '.$m['dosage'];
                if ($m['duree']  !== '') $sub[] = 'Durée : '.$m['duree'];
                $content .= '<tr><td class="doc-med-num">'.$i.'</td>'
                          . '<td><div class="doc-med-name">'.htmlspecialchars($m['nom']).'</div>'
                          . ($sub ? '<div class="doc-med-sub">'.htmlspecialchars(implode(' | ', $sub)).'</div>' : '').'</td>'
                          . '<td class="doc-med-instr">'.($m['posologie'] !== '' ? nl2br(htmlspecialchars($m['posologie'])) : '—').'</td></tr>';
                $i++;
            }
            $content .= '</tbody></table></div>';
            if ($notes !== '') $content .= '<div class="doc-section"><div class="doc-section-title">Notes complémentaires</div><p>'.nl2br(htmlspecialchars($notes)).'</p></div>';

            $meta = [
                'layout'           => 'clean',
                'doc_label'        => 'Ordonnance',
                'reference'        => $ref,
                'date'             => date('d/m/Y', strtotime($prescriber['date_emission'])),
                'signatory_name'   => $prescName,
                'signatory_sub'    => 'SAMS',
                'footer_right_html'=> '<div class="doc-sign-label">Validité</div><div style="font-weight:600;color:#374151;font-size:.82rem">3 mois à compter du '.date('d/m/Y', strtotime($prescriber['date_emission'])).'</div>',
                'foot_note'        => 'SAMS — Document officiel · '.$ref,
            ];
            $msg .= sams_doc_link(sams_generate_document($db,'medical_prescriptions',(int)$certId,"Ordonnance — ".$pname,$content,'ordonnance',$meta));
        } catch(Exception $e){ $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_prescriptions WHERE id=?")->execute([intval($_POST['cert_id'])]);
        $msg = 'Ordonnance supprimée.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_prescriptions SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$items=[];try{$items=$db->query("SELECT mp.*,p.firstname,p.lastname,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_prescriptions mp JOIN profiles p ON p.id=mp.patient_id LEFT JOIN users u ON u.id=mp.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id ORDER BY mp.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
// Pré-remplissage du prescripteur avec le profil du médecin connecté
$docProfile=['firstname'=>'','lastname'=>''];
try{$s=$db->prepare("SELECT pr.firstname,pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id=?");$s->execute([$_SESSION['user_id']]);$docProfile=$s->fetch()?:$docProfile;}catch(Exception $e){}
$total=count($items); $actives=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<style>
.med-card{background:var(--bg-e,rgba(255,255,255,.02));border:1px solid var(--bdr);border-radius:11px;padding:.9rem 1rem;margin-bottom:.7rem}
.med-card-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:.7rem}
.med-card-title{font-size:.82rem;font-weight:700;color:var(--red-l);display:flex;align-items:center;gap:.4rem}
.med-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.med-grid .form-full{grid-column:1/-1}
@media(max-width:560px){.med-grid{grid-template-columns:1fr}}
/* Corps du formulaire : hauteur fixe + scrollbar personnalisée (charte SAMS) */
#addModal .modal-bd{max-height:60vh;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--red,#e11d48) rgba(255,255,255,.05);}
#addModal .modal-bd::-webkit-scrollbar{width:10px;}
#addModal .modal-bd::-webkit-scrollbar-track{background:rgba(255,255,255,.03);border-radius:10px;margin:4px 0;}
#addModal .modal-bd::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--red,#e11d48),var(--red-l,#f43f5e));border-radius:10px;border:2px solid transparent;background-clip:padding-box;}
#addModal .modal-bd::-webkit-scrollbar-thumb:hover{background:var(--red-l,#f43f5e);}
</style>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-prescription"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-capsules"></i></div><div class="kpi-lbl">Actives</div><div class="kpi-val"><?php echo $actives;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-check-double"></i></div><div class="kpi-lbl">Terminées</div><div class="kpi-val"><?php echo $total-$actives;?></div></div>
</div>
<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher un patient…"></div>
    <button class="btn btn-primary btn-sm" onclick="Sams.openModal('addModal')"><i class="fas fa-plus"></i> Nouvelle Ordonnance</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-prescription empty-icon"></i><p>Aucune ordonnance</p></div>
<?php else: foreach($items as $item):
    $sc=match($item['status']??'active'){'active'=>'badge-green','completed'=>'badge-blue',default=>'badge-gray'};
    $sl=match($item['status']??'active'){'active'=>'Active','completed'=>'Terminée',default=>'Annulée'};
    $init=strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
?>
<div class="doc-card">
    <div class="doc-card-hdr">
        <div class="doc-card-left">
            <div class="doc-avatar"><?php echo $init;?></div>
            <div>
                <div class="doc-name"><?php echo htmlspecialchars($item['firstname'].' '.$item['lastname']);?></div>
                <div class="doc-meta">
                    <span><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($item['doctor_name']??'—');?></span>
                    <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i',strtotime($item['created_at']));?></span>
                    <span><i class="fas fa-hashtag"></i> #<?php echo str_pad($item['id'],5,'0',STR_PAD_LEFT);?></span>
                </div>
            </div>
        </div>
        <div class="doc-card-right"><span class="badge <?php echo $sc;?>"><?php echo $sl;?></span></div>
    </div>
    <?php if(!empty($item['prescription_rich'])):?><div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);line-height:1.6;padding:.5rem 1.4rem 1rem"><?php echo nl2br(htmlspecialchars(mb_substr($item['prescription_rich'],0,200)));?><?php if(mb_strlen($item['prescription_rich'])>200) echo '…';?></div><?php endif;?>
    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?><a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Document</a><?php else:?><span class="btn btn-ghost btn-sm" style="cursor:default;opacity:.4"><i class="fas fa-file-circle-xmark"></i> Pas de document</span><?php endif;?>
        <?php if(($item['status']??'active')==='active'):?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check-double"></i> Terminer</button></form>
        <?php endif;?>
        <form method="POST" data-confirm data-confirm-title="Supprimer l'ordonnance" data-confirm-target="<?php echo htmlspecialchars($item['firstname'].' '.$item['lastname'].' — #'.str_pad($item['id'],5,'0',STR_PAD_LEFT));?>" data-confirm-ok="Supprimer" data-confirm-msg="Cette action est <strong>irréversible</strong>." style="display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="cert_id" value="<?php echo $item['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach; endif;?>

<div id="addModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-prescription"></i> Nouvelle Ordonnance</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">

    <!-- Section Patient -->
    <div class="form-label" style="text-transform:uppercase;letter-spacing:.08em;font-size:.68rem;color:var(--txt-s);margin-bottom:.5rem"><i class="fas fa-user-injured"></i> Patient</div>
    <div class="form-group form-full" style="margin-bottom:1.1rem">
        <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
        <input type="hidden" name="patient_id" id="docPatId">
        <div id="docPatChip" style="margin-top:.5rem"></div>
    </div>

    <!-- Section Prescripteur -->
    <div class="form-label" style="text-transform:uppercase;letter-spacing:.08em;font-size:.68rem;color:var(--txt-s);margin-bottom:.5rem"><i class="fas fa-user-md"></i> Prescripteur</div>
    <div class="form-grid" style="margin-bottom:1.1rem">
        <div class="form-group"><label class="form-label required">Nom du prescripteur</label><input type="text" name="presc_nom" class="form-input" value="<?php echo htmlspecialchars($docProfile['lastname']??'');?>" required></div>
        <div class="form-group"><label class="form-label required">Prénom du prescripteur</label><input type="text" name="presc_prenom" class="form-input" value="<?php echo htmlspecialchars($docProfile['firstname']??'');?>" required></div>
        <div class="form-group"><label class="form-label">Spécialité</label><input type="text" name="specialite" class="form-input" placeholder="Médecine générale, Chirurgie…"></div>
        <div class="form-group"><label class="form-label required">Date d'émission</label><input type="date" name="date_emission" class="form-input" value="<?php echo date('Y-m-d');?>" required></div>
    </div>

    <!-- Section Médicaments -->
    <div class="form-label" style="text-transform:uppercase;letter-spacing:.08em;font-size:.68rem;color:var(--txt-s);margin-bottom:.5rem"><i class="fas fa-pills"></i> Médicaments prescrits</div>
    <div id="medList"></div>
    <button type="button" class="btn btn-secondary btn-sm" style="border-style:dashed;margin-bottom:1.1rem" onclick="addMed()"><i class="fas fa-plus"></i> Ajouter un traitement</button>

    <!-- Section Notes -->
    <div class="form-group form-full">
        <label class="form-label"><i class="fas fa-note-sticky"></i> Notes complémentaires <span style="color:var(--txt-m);font-weight:400">(optionnel)</span></label>
        <textarea name="notes" class="form-textarea" rows="3" placeholder="Recommandations, précautions, suivi…"></textarea>
    </div>

</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Émettre l'Ordonnance</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});

/* ── Médicaments : cartes dynamiques ── */
function addMed(){
    const list=document.getElementById('medList');
    const card=document.createElement('div');
    card.className='med-card';
    card.innerHTML=`
        <div class="med-card-hd">
            <span class="med-card-title"><i class="fas fa-pills"></i> Médicament <span class="med-num"></span></span>
            <button type="button" class="btn btn-danger btn-xs" onclick="removeMed(this)" title="Retirer"><i class="fas fa-trash"></i></button>
        </div>
        <div class="med-grid">
            <div class="form-group"><label class="form-label required">Nom du médicament</label><input type="text" name="med_nom[]" class="form-input" placeholder="Ibuprofène" required></div>
            <div class="form-group"><label class="form-label">Dosage</label><input type="text" name="med_dosage[]" class="form-input" placeholder="400 mg"></div>
            <div class="form-group form-full"><label class="form-label">Durée du traitement</label><input type="text" name="med_duree[]" class="form-input" placeholder="7 jours"></div>
            <div class="form-group form-full"><label class="form-label">Instructions / Posologie</label><textarea name="med_posologie[]" class="form-textarea" rows="2" placeholder="1 comprimé matin et soir, après repas…"></textarea></div>
        </div>`;
    list.appendChild(card);
    renumberMeds();
}
function removeMed(btn){
    const list=document.getElementById('medList');
    btn.closest('.med-card').remove();
    if(list.children.length===0) addMed();
    renumberMeds();
}
function renumberMeds(){ document.querySelectorAll('#medList .med-card .med-num').forEach((el,i)=>el.textContent=(i+1)); }
document.addEventListener('DOMContentLoaded',()=>{ if(document.getElementById('medList').children.length===0) addMed(); });
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
