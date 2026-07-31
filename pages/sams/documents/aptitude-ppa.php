<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (isset($_SERVER['DOCUMENT_ROOT'])) { @include_once $_SERVER['DOCUMENT_ROOT'] . '/config/googledocsgenerator.php'; }
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Aptitude PPA';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Workflow PPA unifié (migration + helpers partagés)
require_once ROOT_PATH . '/includes/ppa.php';
mdt_ppa_migrate($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        try {
            // Pré-demande liée (workflow civil) ou dossier manuel (staff).
            $demandeId = intval($_POST['demande_id'] ?? 0);
            $dem = [];
            if ($demandeId) { try { $s=$db->prepare("SELECT * FROM lspd_ppa_demandes WHERE id=?"); $s->execute([$demandeId]); $dem=$s->fetch() ?: []; } catch(Exception $e){} }
            $hasDem    = !empty($dem);
            $patientId = $hasDem ? (int)$dem['citizen_id'] : intval($_POST['patient_id'] ?? 0);
            if ($patientId <= 0) throw new Exception('Veuillez sélectionner un patient.');

            $data=['aptitude_physique'=>$_POST['aptitude_physique'],'aptitude_mentale'=>$_POST['aptitude_mentale'],'decision_finale'=>$_POST['decision_finale'],'restrictions'=>$_POST['restrictions']??''];
            $db->prepare("INSERT INTO medical_certificates (type,patient_id,doctor_id,data,description_rich,valid_from,valid_until,status) VALUES ('aptitude_ppa',?,?,?,?,?,?,'active')")
               ->execute([$patientId,$_SESSION['user_id'],json_encode($data),trim($_POST['observations']??''),date('Y-m-d'),$_POST['valid_until']]);
            $certId = $db->lastInsertId();
            $msg = 'Aptitude créé(e) avec succès.'; $msgType = 'success';

            // ── Données civil (snapshot pré-demande complété par le profil) ──
            $profileRow = [];
            try { $s=$db->prepare("SELECT firstname,lastname,avatar,date_of_birth,address FROM profiles WHERE id=?"); $s->execute([$patientId]); $profileRow=$s->fetch() ?: []; } catch(Exception $e){}

            $docName  = sams_doctor_name($db,$_SESSION['user_id']);
            $ref      = sprintf('PPA-%s-%04d', date('Ym'), (int)$certId);
            $prenom   = trim((string)(($hasDem ? ($dem['prenom'] ?? '') : '') ?: ($profileRow['firstname'] ?? '')));
            $nom      = trim((string)(($hasDem ? ($dem['nom'] ?? '') : '') ?: ($profileRow['lastname'] ?? '')));
            $pname    = trim($prenom.' '.$nom); if($pname==='') $pname='—';
            $dobRaw   = ($hasDem ? ($dem['date_naissance'] ?? '') : '') ?: ($profileRow['date_of_birth'] ?? '');
            $dobStr   = ($dobRaw && $dobRaw!=='0000-00-00') ? date('d/m/Y',strtotime((string)$dobRaw)) : '—';
            $adresse  = trim((string)(($hasDem ? ($dem['adresse'] ?? '') : '') ?: ($profileRow['address'] ?? '')));
            $typeArme = $hasDem ? (string)($dem['type_arme'] ?? '') : '';
            $piece    = mdt_ppa_abs_url($hasDem ? ($dem['piece_identite'] ?? '') : '');
            $avatarUrl= mdt_ppa_abs_url($profileRow['avatar'] ?? '');
            $casier   = mdt_ppa_casier($db, $patientId);

            $avatarImg = $avatarUrl!=='' ? '<img src="'.htmlspecialchars($avatarUrl).'" alt="" referrerpolicy="no-referrer" style="width:74px;height:74px;border-radius:10px;object-fit:cover;border:2px solid #e5e7eb;margin-bottom:.6rem">' : '';

            $content  = '<div class="doc-grid2">';
            $content .= '<div class="doc-section" style="margin-bottom:0"><div class="doc-section-title">Demandeur</div>';
            $content .= $avatarImg;
            $content .= '<div class="doc-field-value" style="font-size:1.05rem">'.htmlspecialchars($pname).'</div>';
            $content .= '<div class="doc-field" style="margin-top:.5rem"><div class="doc-field-label">Date de naissance</div><div>'.htmlspecialchars($dobStr).'</div></div>';
            if($adresse!=='') $content .= '<div class="doc-field"><div class="doc-field-label">Adresse</div><div>'.htmlspecialchars($adresse).'</div></div>';
            $content .= '</div>';
            $content .= '<div class="doc-section" style="margin-bottom:0"><div class="doc-section-title">Demande</div>';
            $content .= '<div class="doc-field"><div class="doc-field-label">Type d\'arme</div><div>'.htmlspecialchars($typeArme!==''?$typeArme:'—').'</div></div>';
            $content .= '<div class="doc-field"><div class="doc-field-label">Praticien</div><div>'.htmlspecialchars($docName).'</div></div>';
            $content .= '</div></div>';

            $content .= '<div class="doc-section" style="margin-top:1.3rem"><div class="doc-section-title">Évaluation médicale et psychologique</div>';
            $content .= '<div class="doc-vitals">';
            $content .= '<div class="doc-vital '.($data['aptitude_physique']==='Inapte'?'v-red':'v-blue').'"><div class="vl">Aptitude physique</div><div class="vv" style="font-size:1rem">'.htmlspecialchars($data['aptitude_physique']).'</div></div>';
            $content .= '<div class="doc-vital '.($data['aptitude_mentale']==='Inapte'?'v-red':'v-blue').'"><div class="vl">Test psychologique</div><div class="vv" style="font-size:1rem">'.htmlspecialchars($data['aptitude_mentale']).'</div></div>';
            $content .= '<div class="doc-vital '.($data['decision_finale']==='Inapte'?'v-red':'v-amber').'"><div class="vl">Décision SAMS</div><div class="vv" style="font-size:1rem">'.htmlspecialchars($data['decision_finale']).'</div></div>';
            $content .= '</div></div>';
            if(!empty($data['restrictions'])) $content .= '<div class="doc-section"><div class="doc-section-title">Restrictions / Conditions</div><p>'.nl2br(htmlspecialchars($data['restrictions'])).'</p></div>';
            if(trim($_POST['observations']??'')!=='') $content .= '<div class="doc-section"><div class="doc-section-title">Observations</div><p>'.nl2br(htmlspecialchars(trim($_POST['observations']))).'</p></div>';
            if($piece!=='') {
                $content .= '<div class="doc-section"><div class="doc-section-title">Pièce d\'identité</div>'
                          . '<img src="'.htmlspecialchars($piece).'" alt="Pièce d\'identité" referrerpolicy="no-referrer" style="max-width:100%;max-height:340px;border-radius:8px;border:1px solid #e5e7eb">'
                          . '<div style="margin-top:.35rem;font-size:.82rem"><a href="'.htmlspecialchars($piece).'" target="_blank" style="color:#2563eb">Ouvrir la pièce d\'identité</a></div></div>';
            }
            $content .= '<div class="doc-section"><div class="doc-section-title">Casier judiciaire</div>'.mdt_ppa_casier_doc_html($casier).'</div>';

            $meta = [
                'layout'           => 'clean',
                'doc_label'        => "Aptitude au Port d'Arme",
                'reference'        => $ref,
                'date'             => date('d/m/Y'),
                'signatory_name'   => $docName,
                'signatory_sub'    => 'SAMS',
                'footer_right_html'=> '<div class="doc-sign-label">Validité</div><div style="font-weight:600;color:#374151;font-size:.82rem">Jusqu\'au '.date('d/m/Y',strtotime($_POST['valid_until'])).'</div>',
                'foot_note'        => 'SAMS — Document officiel · '.$ref,
            ];
            $docUrl = sams_generate_document($db,'medical_certificates',(int)$certId,"Aptitude PPA — ".$pname,$content,'aptitude_ppa',$meta);
            $msg .= sams_doc_link($docUrl);

            // ── Transmission automatique vers le LSPD (crée/actualise la demande) ──
            $apte = in_array($data['decision_finale'], ['Apte','Apte temporairement'], true);
            try {
                if ($hasDem) {
                    if ($apte) {
                        $db->prepare("UPDATE lspd_ppa_demandes SET statut='en_cours', sams_cert_id=?, sams_doc_url=? WHERE id=?")
                           ->execute([(int)$certId, $docUrl, $demandeId]);
                        $msg .= ' Certificat validé : la demande a été transmise automatiquement au LSPD.';
                    } else {
                        $db->prepare("UPDATE lspd_ppa_demandes SET statut='refuse', sams_cert_id=?, sams_doc_url=?, motif_refus=? WHERE id=?")
                           ->execute([(int)$certId, $docUrl, "Inapte au port d'arme (évaluation SAMS).", $demandeId]);
                        $msg .= ' Civil déclaré inapte : la pré-demande a été refusée.';
                    }
                } elseif ($apte) {
                    // Pas de demande_id : rattacher une pré-demande en attente du même
                    // citoyen si elle existe, sinon créer une nouvelle demande LSPD.
                    $existing = 0;
                    try { $ex=$db->prepare("SELECT id FROM lspd_ppa_demandes WHERE citizen_id=? AND statut='demande' ORDER BY created_at ASC LIMIT 1"); $ex->execute([$patientId]); $existing=(int)$ex->fetchColumn(); } catch(Exception $e){}
                    if ($existing > 0) {
                        $db->prepare("UPDATE lspd_ppa_demandes SET statut='en_cours', sams_cert_id=?, sams_doc_url=? WHERE id=?")
                           ->execute([(int)$certId, $docUrl, $existing]);
                        $msg .= ' Certificat validé : la demande a été transmise automatiquement au LSPD.';
                    } else {
                        $db->prepare("INSERT INTO lspd_ppa_demandes
                            (citizen_id, source, nom, prenom, date_naissance, adresse, type_arme, sams_cert_id, sams_doc_url, date_demande, statut, created_by)
                            VALUES (?, 'staff', ?, ?, ?, ?, ?, ?, ?, ?, 'en_cours', ?)")
                           ->execute([
                               $patientId, $nom, $prenom,
                               ($dobRaw && $dobRaw!=='0000-00-00') ? $dobRaw : null,
                               $adresse, $typeArme, (int)$certId, $docUrl, date('Y-m-d'), $_SESSION['user_id'],
                           ]);
                        $msg .= ' Certificat validé : une demande a été créée automatiquement côté LSPD.';
                    }
                }
            } catch(Exception $e) { $msg .= ' (⚠ Transmission LSPD à vérifier : '.htmlspecialchars($e->getMessage()).')'; }
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medical_certificates WHERE id=? AND type='aptitude_ppa'")->execute([intval($_POST['cert_id'])]);
        $msg = 'Supprimé avec succès.'; $msgType = 'success';
    }
    if ($action === 'toggle_status') {
        $db->prepare("UPDATE medical_certificates SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['cert_id'])]);
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    }
}

$items=[];try{$items=$db->query("SELECT mc.*,p.firstname,p.lastname,p.avatar AS p_avatar,NULLIF(TRIM(CONCAT(COALESCE(cp.firstname,''),' ',COALESCE(cp.lastname,''))),'') AS doctor_name FROM medical_certificates mc JOIN profiles p ON p.id=mc.patient_id LEFT JOIN users u ON u.id=mc.doctor_id LEFT JOIN profiles cp ON cp.id=u.profile_id WHERE mc.type='aptitude_ppa' ORDER BY mc.created_at DESC LIMIT 150")->fetchAll();}catch(Exception $e){}
$patients=[];try{$patients=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll();}catch(Exception $e){}
// Pré-demandes de licence PPA transmises par les civils (en attente d'évaluation SAMS)
$preDemandes=[];
try{
    $preDemandes=$db->query("SELECT d.*, p.firstname AS p_firstname, p.lastname AS p_lastname, p.avatar AS p_avatar
        FROM lspd_ppa_demandes d LEFT JOIN profiles p ON p.id=d.citizen_id
        WHERE d.statut='demande' ORDER BY d.created_at ASC")->fetchAll();
}catch(Exception $e){}
$total=count($items); $actifs=count(array_filter($items,fn($c)=>($c['status']??'active')==='active'));

include __DIR__ . '/../includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.4rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-shield-halved"></i></div><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo $total;?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-check-circle"></i></div><div class="kpi-lbl">Actifs</div><div class="kpi-val"><?php echo $actifs;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-archive"></i></div><div class="kpi-lbl">Terminés</div><div class="kpi-val"><?php echo $total-$actifs;?></div></div>
</div>
<!-- ══ PRÉ-DEMANDES DE LICENCE PPA (transmises par les civils) ══════════════ -->
<div style="margin-bottom:1.6rem">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.9rem">
        <h2 style="font-size:1rem;font-weight:800;display:flex;align-items:center;gap:.5rem"><i class="fas fa-inbox" style="color:var(--red-l,#f43f5e)"></i> Pré-demandes de licence PPA</h2>
        <span class="badge badge-yellow"><?php echo count($preDemandes);?></span>
    </div>
    <?php if(empty($preDemandes)): ?>
    <div class="empty-state" style="padding:2rem"><i class="fas fa-inbox empty-icon"></i><p>Aucune pré-demande en attente</p></div>
    <?php else: foreach($preDemandes as $pd):
        $casier = mdt_ppa_casier($db,(int)$pd['citizen_id']);
        $prenom = trim((string)($pd['prenom'] ?: $pd['p_firstname']));
        $nom    = trim((string)($pd['nom']    ?: $pd['p_lastname']));
        $fullName = trim($prenom.' '.$nom);
        $init   = strtoupper(mb_substr($prenom,0,1).mb_substr($nom,0,1));
        $dob    = (!empty($pd['date_naissance']) && $pd['date_naissance']!=='0000-00-00') ? date('d/m/Y',strtotime($pd['date_naissance'])) : '—';
        $pdAvatar = mdt_ppa_abs_url($pd['p_avatar'] ?? '');
        $pdPiece  = mdt_ppa_abs_url($pd['piece_identite'] ?? '');
    ?>
    <div class="doc-card">
        <div class="doc-card-hdr">
            <div class="doc-card-left">
                <div class="doc-avatar" style="overflow:hidden">
                    <?php if($pdAvatar!==''):?><img src="<?php echo htmlspecialchars($pdAvatar);?>" alt="" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;border-radius:inherit"><?php else:?><?php echo htmlspecialchars($init!==''?$init:'?');?><?php endif;?>
                </div>
                <div>
                    <div class="doc-name"><?php echo htmlspecialchars($fullName!==''?$fullName:'—');?></div>
                    <div class="doc-meta">
                        <span><i class="fas fa-cake-candles"></i> <?php echo htmlspecialchars($dob);?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y',strtotime($pd['created_at']));?></span>
                        <span><i class="fas fa-hashtag"></i> #<?php echo str_pad((string)$pd['id'],5,'0',STR_PAD_LEFT);?></span>
                    </div>
                </div>
            </div>
            <div class="doc-card-right"><span class="badge badge-yellow"><i class="fas fa-clock"></i> En attente</span></div>
        </div>
        <div class="doc-tags">
            <?php if(!empty($pd['type_arme'])):?><span class="badge badge-blue"><i class="fas fa-gun"></i> <?php echo htmlspecialchars($pd['type_arme']);?></span><?php endif;?>
            <span class="badge <?php echo $casier['active']>0?'badge-red':'badge-gray';?>"><i class="fas fa-scale-balanced"></i> <?php echo $casier['count']>0?($casier['count'].' infraction(s)'):'Casier vierge';?></span>
        </div>
        <?php if(!empty($pd['adresse'])):?><div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);padding:.3rem 1.4rem"><i class="fas fa-location-dot" style="opacity:.6;margin-right:.35rem"></i><?php echo htmlspecialchars($pd['adresse']);?></div><?php endif;?>
        <?php if(!empty($pd['motif_demande'])):?><div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);line-height:1.6;padding:.3rem 1.4rem .3rem"><i class="fas fa-quote-left" style="opacity:.5;margin-right:.35rem"></i><?php echo htmlspecialchars($pd['motif_demande']);?></div><?php endif;?>

        <div style="padding:.5rem 1.4rem 0;display:grid;grid-template-columns:200px 1fr;gap:1.1rem;align-items:start">
            <div>
                <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--txt-m);margin-bottom:.35rem"><i class="fas fa-id-card" style="margin-right:.3rem"></i>Pièce d'identité</div>
                <?php if($pdPiece!==''):?>
                <a href="<?php echo htmlspecialchars($pdPiece);?>" target="_blank" onclick="return mdtOpenDoc('<?php echo htmlspecialchars($pdPiece);?>','img')" style="display:block">
                    <img src="<?php echo htmlspecialchars($pdPiece);?>" alt="Pièce d'identité" referrerpolicy="no-referrer" style="width:100%;max-height:150px;border-radius:8px;border:1px solid var(--bdr);object-fit:contain;background:rgba(0,0,0,.2)">
                </a>
                <?php else:?><div style="font-size:.74rem;color:var(--txt-m)">Aucune pièce fournie</div><?php endif;?>
            </div>
            <div>
                <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--txt-m);margin-bottom:.35rem"><i class="fas fa-scale-balanced" style="margin-right:.3rem"></i>Casier judiciaire complet</div>
                <?php echo mdt_ppa_casier_list_html($casier);?>
            </div>
        </div>

        <div class="doc-actions">
            <button class="btn btn-primary btn-sm" onclick="openPredemande(<?php echo (int)$pd['id'];?>,<?php echo (int)$pd['citizen_id'];?>,'<?php echo htmlspecialchars(addslashes($fullName),ENT_QUOTES);?>')"><i class="fas fa-clipboard-check"></i> Valider la pré-demande</button>
        </div>
    </div>
    <?php endforeach; endif;?>
</div>

<div class="doc-toolbar">
    <div class="search-wrap"><i class="fas fa-search"></i><input type="text" id="docSearch" placeholder="Rechercher…"></div>
    <button class="btn btn-secondary btn-sm" onclick="openManualPPA()"><i class="fas fa-plus"></i> Créer un dossier PPA (manuel)</button>
</div>
<?php if(empty($items)):?><div class="empty-state"><i class="fas fa-shield-halved empty-icon"></i><p>Aucun enregistrement</p></div>
<?php else: foreach($items as $item):
    $data=json_decode($item['data']??'{}',true)?:[];
    $init=strtoupper(substr($item['firstname'],0,1).substr($item['lastname'],0,1));
    $sc=match($item['status']??'active'){'active'=>'badge-green','completed'=>'badge-blue',default=>'badge-gray'};
    $sl=match($item['status']??'active'){'active'=>'Actif','completed'=>'Terminé',default=>'Annulé'};
?>
<div class="doc-card">
    <div class="doc-card-hdr">
        <div class="doc-card-left">
            <?php $itAvatar = mdt_ppa_abs_url($item['p_avatar'] ?? ''); ?>
            <div class="doc-avatar" style="overflow:hidden"><?php if($itAvatar!==''):?><img src="<?php echo htmlspecialchars($itAvatar);?>" alt="" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;border-radius:inherit"><?php else:?><?php echo $init;?><?php endif;?></div>
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
    
    <?php if(!empty($data['decision_finale'])):?><div class="doc-tags"><span class="badge <?php echo $data['decision_finale']==='Apte'?'badge-green':'badge-red';?>"><i class="fas fa-shield-halved"></i> PPA : <?php echo htmlspecialchars($data['decision_finale']);?></span><?php if(!empty($data['aptitude_physique'])):?><span class="badge badge-gray">Physique : <?php echo htmlspecialchars($data['aptitude_physique']);?></span><?php endif;?><?php if(!empty($data['aptitude_mentale'])):?><span class="badge badge-gray">Mental : <?php echo htmlspecialchars($data['aptitude_mentale']);?></span><?php endif;?></div><?php endif;?>
    <?php if(!empty($item['description_rich'])):?>
    <div class="doc-card-body" style="font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo htmlspecialchars(mb_substr(strip_tags($item['description_rich']??''),0,180));?><?php if(mb_strlen(strip_tags($item['description_rich']??''))>180) echo '…';?></div>
    <?php endif;?>
    <div class="doc-actions">
        <?php if(!empty($item['google_docs_url'])):?><a href="<?php echo htmlspecialchars($item['google_docs_url']);?>" target="_blank" onclick="return mdtOpenDoc('<?php echo htmlspecialchars($item['google_docs_url']);?>','doc','Aptitude PPA')" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Voir le document</a><?php else:?><span class="btn btn-ghost btn-sm" style="cursor:default;opacity:.4"><i class="fas fa-file-circle-xmark"></i> Pas de document</span><?php endif;?>
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
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-shield-halved"></i> Certificat d'Aptitude PPA</h2><button class="modal-close" onclick="Sams.closeModal('addModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label required">Patient (demandeur PPA)</label>
            <input type="hidden" name="demande_id" id="ppaDemandeId" value="">
            <input type="hidden" name="patient_id" id="docPatId">
            <div id="ppaPatFixed" style="display:none;padding:.6rem .85rem;border-radius:8px;background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.2);font-weight:600"></div>
            <div id="ppaPatPicker">
                <button type="button" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;border-style:dashed" id="docPatBtn"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
                <div id="docPatChip" style="margin-top:.5rem"></div>
            </div>
        </div>
        <div class="form-group"><label class="form-label required">Aptitude physique</label>
            <select name="aptitude_physique" class="form-select" required><option value="Apte">Apte</option><option value="Apte avec réserves">Apte avec réserves</option><option value="Inapte">Inapte</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Aptitude mentale</label>
            <select name="aptitude_mentale" class="form-select" required><option value="Apte">Apte</option><option value="Apte avec réserves">Apte avec réserves</option><option value="Inapte">Inapte</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Décision finale</label>
            <select name="decision_finale" class="form-select" required><option value="Apte">Apte au port d'arme</option><option value="Inapte">Inapte au port d'arme</option><option value="Apte temporairement">Apte temporairement</option></select>
        </div>
        <div class="form-group"><label class="form-label required">Valable jusqu'au</label><input type="date" name="valid_until" class="form-input" required></div>
        <div class="form-group form-full"><label class="form-label">Restrictions / Conditions</label><input type="text" name="restrictions" class="form-input" placeholder="Ex: Arme de poing uniquement…"></div>
        <div class="form-group form-full"><label class="form-label required">Observations médicales</label><textarea name="observations" class="form-textarea" rows="3" required></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/components.php'; ?>
<script>
const DOC_PATIENTS = <?php echo json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname'])], $patients), JSON_UNESCAPED_UNICODE); ?>;
samsPatientField({button:'docPatBtn',hidden:'docPatId',chip:'docPatChip',title:'Sélection du patient',items:DOC_PATIENTS});

// Ouvrir le formulaire d'aptitude pré-rempli depuis une pré-demande civile.
function openPredemande(demandeId, citizenId, citizenName){
    document.getElementById('ppaDemandeId').value = demandeId;
    document.getElementById('docPatId').value = citizenId;
    const fixed = document.getElementById('ppaPatFixed');
    fixed.textContent = citizenName;
    fixed.style.display = 'block';
    document.getElementById('ppaPatPicker').style.display = 'none';
    Sams.openModal('addModal');
}
// Ouvrir le formulaire en mode manuel (dossier staff, sélecteur de patient actif).
function openManualPPA(){
    document.getElementById('ppaDemandeId').value = '';
    document.getElementById('docPatId').value = '';
    document.getElementById('ppaPatFixed').style.display = 'none';
    document.getElementById('ppaPatPicker').style.display = 'block';
    const chip = document.getElementById('docPatChip'); if(chip) chip.innerHTML = '';
    Sams.openModal('addModal');
}
window.openPredemande = openPredemande;
window.openManualPPA  = openManualPPA;
</script>
<?php include ROOT_PATH . '/includes/doc_viewer.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>