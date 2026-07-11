<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Dossiers Patients';
$db = Database::getInstance()->getConnection();
$msg=''; $msgType='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='update_blood') {
        try { $db->prepare("UPDATE profiles SET blood_type=? WHERE id=?")->execute([$_POST['blood_type'],$_POST['pid']]); $msg='Groupe sanguin mis à jour.'; $msgType='success'; } catch(Exception $e){$msg='Erreur';$msgType='error';}
    }
    if ($action==='upsert_dossier') {
        try {
            $db->prepare("INSERT INTO sams_dossiers_patients (patient_id,allergies,antecedents,traitements_en_cours,notes_confidentielles,medical_alert,alert_detail,created_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE allergies=VALUES(allergies),antecedents=VALUES(antecedents),traitements_en_cours=VALUES(traitements_en_cours),notes_confidentielles=VALUES(notes_confidentielles),medical_alert=VALUES(medical_alert),alert_detail=VALUES(alert_detail)")
               ->execute([$_POST['pid'],trim($_POST['allergies']??''),trim($_POST['antecedents']??''),trim($_POST['traitements']??''),trim($_POST['notes_conf']??''),isset($_POST['alert'])?1:0,trim($_POST['alert_detail']??''),$_SESSION['user_id']]);
            $msg='Dossier médical mis à jour.'; $msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
}

$search=trim($_GET['q']??''); $viewId=intval($_GET['id']??0);

// Vue détail patient
$patient=null; $dossier=null; $lastReports=[]; $lastInvoices=[]; $lastCerts=[]; $lastOrdo=[];
if ($viewId>0) {
    $s=$db->prepare("SELECT * FROM profiles WHERE id=?"); $s->execute([$viewId]); $patient=$s->fetch();
    if ($patient) {
        try{$s=$db->prepare("SELECT * FROM sams_dossiers_patients WHERE patient_id=?");$s->execute([$viewId]);$dossier=$s->fetch();}catch(Exception $e){}
        try{$lastReports=$db->prepare("SELECT sr.*,u.username AS med FROM sams_reports sr LEFT JOIN users u ON u.id=sr.created_by WHERE sr.patient_id=? ORDER BY sr.created_at DESC LIMIT 5");$lastReports->execute([$viewId]);$lastReports=$lastReports->fetchAll();}catch(Exception $e){}
        try{$lastInvoices=$db->prepare("SELECT * FROM sams_invoices WHERE patient_id=? ORDER BY created_at DESC LIMIT 10");$lastInvoices->execute([$viewId]);$lastInvoices=$lastInvoices->fetchAll();}catch(Exception $e){}
        try{$lastCerts=$db->prepare("SELECT mc.*,u.username AS med FROM medical_certificates mc LEFT JOIN users u ON u.id=mc.doctor_id WHERE mc.patient_id=? ORDER BY mc.created_at DESC LIMIT 8");$lastCerts->execute([$viewId]);$lastCerts=$lastCerts->fetchAll();}catch(Exception $e){}
        try{$lastOrdo=$db->prepare("SELECT mp.*,u.username AS med FROM medical_prescriptions mp LEFT JOIN users u ON u.id=mp.doctor_id WHERE mp.patient_id=? ORDER BY mp.created_at DESC LIMIT 5");$lastOrdo->execute([$viewId]);$lastOrdo=$lastOrdo->fetchAll();}catch(Exception $e){}
    }
}

if (!$patient) {
    // medical_alert via sous-requête (la table peut être absente : try/catch)
    $hasDossierTable = true;
    try { $db->query("SELECT 1 FROM sams_dossiers_patients LIMIT 0"); } catch(Exception $e){ $hasDossierTable = false; }
    $alertSel = $hasDossierTable
        ? "(SELECT d.medical_alert FROM sams_dossiers_patients d WHERE d.patient_id=p.id) AS medical_alert,"
        : "0 AS medical_alert,";
    $q="SELECT p.*, {$alertSel} (SELECT COUNT(*) FROM sams_reports WHERE patient_id=p.id) AS nb_rap,(SELECT COUNT(*) FROM sams_invoices WHERE patient_id=p.id AND status='pending') AS nb_imp FROM profiles p WHERE 1=1";
    $params=[];
    if ($search){$q.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR p.phone LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
    $q.=" ORDER BY p.lastname,p.firstname LIMIT 120";
    $stmt=$db->prepare($q);$stmt->execute($params);$patients=$stmt->fetchAll();
}
include __DIR__ . '/includes/header.php';
$BLOOD=['A+','A-','B+','B-','AB+','AB-','O+','O-'];

/** Couleur d'avatar dérivée de l'initiale. */
function pat_av_color(string $init): string {
    $map=['A'=>'#e11d48','B'=>'#f97316','C'=>'#8b5cf6','D'=>'#06b6d4','E'=>'#10b981','F'=>'#3b82f6','G'=>'#ec4899','H'=>'#f59e0b'];
    return $map[strtoupper($init)] ?? '#e11d48';
}
?>
<style>
/* ── Cartes patients ── */
.pat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem; }
.pat-card { position:relative; background:var(--bg-c); border:1px solid var(--bdr); border-radius:14px;
  padding:1.15rem; cursor:pointer; transition:all .2s; overflow:hidden; }
.pat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px;
  background:linear-gradient(90deg,var(--red),var(--red-l)); opacity:0; transition:opacity .2s; }
.pat-card:hover { border-color:var(--bdr-h); transform:translateY(-3px); box-shadow:0 12px 32px rgba(0,0,0,.4); }
.pat-card:hover::before { opacity:1; }
.pat-card.alert { border-color:rgba(225,29,72,.4); }
.pat-card.alert::before { opacity:1; }
.pat-top { display:flex; align-items:flex-start; gap:.85rem; margin-bottom:.85rem; }
.pat-av { width:50px; height:50px; border-radius:13px; flex-shrink:0; overflow:hidden;
  display:grid; place-items:center; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:1.25rem; border:1.5px solid; }
.pat-av img { width:100%; height:100%; object-fit:cover; }
.pat-name { font-weight:700; font-size:.95rem; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pat-meta { font-size:.73rem; color:var(--txt-m); margin-top:.12rem; }
.pat-badges { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.7rem; min-height:22px; }
.pat-foot { display:flex; align-items:center; justify-content:space-between; padding-top:.65rem; border-top:1px solid rgba(255,255,255,.05); }
.pat-alert-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.18rem .55rem; border-radius:20px;
  font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
  background:rgba(225,29,72,.14); color:var(--red-l); border:1px solid rgba(225,29,72,.35); }
.pat-alert-pill i { animation:pulse-red 1.3s ease-in-out infinite; }

/* ── Hero détail ── */
.pat-hero { position:relative; border:1px solid var(--bdr); border-radius:16px; padding:1.6rem; margin-bottom:1.3rem; overflow:hidden; background:var(--bg-c); }
.pat-hero::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(225,29,72,.1),transparent 60%); pointer-events:none; }
.pat-hero-row { position:relative; display:flex; align-items:flex-start; gap:1.25rem; flex-wrap:wrap; }
.pat-hero-av { width:78px; height:78px; border-radius:18px; flex-shrink:0; overflow:hidden; display:grid; place-items:center;
  font-family:'Rajdhani',sans-serif; font-weight:700; font-size:1.9rem; border:2px solid; box-shadow:0 0 20px rgba(225,29,72,.15); }
.pat-hero-av img { width:100%; height:100%; object-fit:cover; }
.pat-hero-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:.55rem; flex-shrink:0; }
.pat-stat { background:rgba(0,0,0,.28); border:1px solid rgba(255,255,255,.05); border-radius:10px; padding:.6rem .85rem; text-align:center; min-width:74px; }
.pat-stat-l { font-size:.58rem; color:var(--txt-m); text-transform:uppercase; letter-spacing:.08em; }
.pat-stat-v { font-family:'JetBrains Mono',monospace; font-size:1.15rem; font-weight:700; }

/* ── Composant alerte médicale custom (pas de checkbox native) ── */
.med-alert-box { padding:.9rem 1.1rem; border-radius:11px; border:1px solid var(--bdr);
  background:rgba(0,0,0,.2); margin-top:.4rem; transition:all .2s; }
.med-alert-box.on { background:rgba(225,29,72,.08); border-color:rgba(225,29,72,.4); }
.med-alert-switch { display:flex; align-items:center; gap:.8rem; cursor:pointer; user-select:none; }
.mas-track { width:48px; height:27px; border-radius:27px; background:rgba(255,255,255,.12);
  border:1px solid var(--bdr); position:relative; flex-shrink:0; transition:all .2s; }
.mas-track::before { content:''; position:absolute; left:3px; top:2px; width:20px; height:20px; border-radius:50%;
  background:#fff; transition:transform .2s; }
.med-alert-box.on .mas-track { background:var(--red); border-color:var(--red); box-shadow:0 0 12px rgba(225,29,72,.4); }
.med-alert-box.on .mas-track::before { transform:translateX(20px); }
.mas-label { font-weight:700; font-size:.85rem; color:var(--txt-s); display:flex; align-items:center; gap:.45rem; }
.med-alert-box.on .mas-label { color:var(--red-xl); }
.mas-label i { color:var(--red-l); }

.info-tile { background:rgba(0,0,0,.2); border-radius:9px; padding:.6rem .8rem; border:1px solid rgba(255,255,255,.04); }
.info-tile-l { font-size:.58rem; color:var(--txt-m); text-transform:uppercase; letter-spacing:.08em; margin-bottom:.2rem; }
.info-tile-l i { margin-right:.3rem; color:var(--red-l); font-size:.6rem; }
.info-tile-v { font-size:.84rem; color:var(--txt); font-weight:500; }
.sub-item { display:flex; justify-content:space-between; align-items:center; padding:.6rem .75rem; background:var(--bg-ov);
  border:1px solid var(--bdr); border-radius:9px; margin-bottom:.4rem; }
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<?php if($patient):
$pInit=strtoupper(mb_substr($patient['firstname']??'?',0,1));
$pCol=pat_av_color($pInit);
$hasAv=!empty($patient['avatar']);
$alertOn=!empty($dossier['medical_alert']);
?>
<!-- ── VUE DÉTAIL PATIENT ── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <a href="patients.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Patients</a>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="rapports.php?new=1&pid=<?php echo $patient['id'];?>" class="btn btn-secondary btn-sm"><i class="fas fa-file-medical"></i> Nouveau rapport</a>
        <a href="documents/ordonnance.php?pid=<?php echo $patient['id'];?>" class="btn btn-secondary btn-sm"><i class="fas fa-prescription"></i> Ordonnance</a>
        <a href="facturation.php?pid=<?php echo $patient['id'];?>" class="btn btn-primary btn-sm"><i class="fas fa-receipt"></i> Facturer</a>
    </div>
</div>

<?php if($alertOn):?>
<div class="alert alert-error" style="align-items:center">
    <i class="fas fa-triangle-exclamation" style="font-size:1.1rem"></i>
    <div><strong style="text-transform:uppercase;letter-spacing:.04em">Alerte médicale active</strong>
    <?php if(!empty($dossier['alert_detail'])):?> — <?php echo htmlspecialchars($dossier['alert_detail']);?><?php endif;?></div>
</div>
<?php endif;?>

<!-- HERO -->
<div class="pat-hero">
    <div class="pat-hero-row">
        <div class="pat-hero-av" style="background:linear-gradient(135deg,<?php echo $pCol;?>33,rgba(0,0,0,.4));border-color:<?php echo $pCol;?>66;color:<?php echo $pCol;?>">
            <?php if($hasAv):?><img src="<?php echo htmlspecialchars($patient['avatar']);?>" alt=""><?php else:?><?php echo $pInit;?><?php endif;?>
        </div>
        <div style="flex:1;min-width:200px">
            <div style="font-family:'Rajdhani',sans-serif;font-size:1.45rem;font-weight:700;color:#fff;letter-spacing:.02em"><?php echo htmlspecialchars($patient['firstname'].' '.$patient['lastname']);?></div>
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.4rem">
                <?php if(($patient['age']??'')!==''):?><span style="font-size:.8rem;color:var(--txt-s)"><?php echo (int)$patient['age'];?> ans</span><?php endif;?>
                <?php if(!empty($patient['nationality'])):?><span style="font-size:.75rem;color:var(--txt-m)">· <?php echo htmlspecialchars($patient['nationality']);?></span><?php endif;?>
                <?php if(!empty($patient['blood_type'])):?><span class="badge badge-red"><i class="fas fa-droplet"></i> <?php echo htmlspecialchars($patient['blood_type']);?></span><?php endif;?>
                <?php if(!empty($patient['job'])):?><span class="badge badge-gray"><?php echo htmlspecialchars($patient['job']);?></span><?php endif;?>
            </div>
        </div>
        <div class="pat-hero-stats">
            <div class="pat-stat"><div class="pat-stat-l">Rapports</div><div class="pat-stat-v" style="color:var(--red-l)"><?php echo count($lastReports);?></div></div>
            <div class="pat-stat"><div class="pat-stat-l">Ordonn.</div><div class="pat-stat-v" style="color:#93c5fd"><?php echo count($lastOrdo);?></div></div>
            <div class="pat-stat"><div class="pat-stat-l">Factures</div><div class="pat-stat-v" style="color:var(--green-l)"><?php echo count($lastInvoices);?></div></div>
        </div>
    </div>
</div>

<div class="grid2">
  <div>
    <!-- INFOS CIVILES -->
    <div class="card">
        <div class="card-title"><i class="fas fa-id-card"></i> Informations civiles</div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.55rem">
            <?php $infos=[['Téléphone',$patient['phone']??'','fa-phone'],['IBAN',$patient['iban']??'','fa-building-columns'],['Métier',$patient['job']??'','fa-briefcase'],['Adresse',$patient['address']??'','fa-map-pin']];
            foreach($infos as [$l,$v,$ico]):if(empty($v))continue;?>
            <div class="info-tile"><div class="info-tile-l"><i class="fas <?php echo $ico;?>"></i><?php echo $l;?></div><div class="info-tile-v"><?php echo htmlspecialchars($v);?></div></div>
            <?php endforeach;?>
        </div>
        <div style="margin-top:.75rem;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.4rem">Permis</div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <?php foreach([['license_car','Voiture','fa-car'],['license_ppa','PPA','fa-gun'],['license_boat','Bateau','fa-ship'],['license_plane','Avion','fa-plane']] as [$k,$l,$i]):$has=$patient[$k]??0;?>
            <span class="badge <?php echo $has?'badge-green':'badge-gray';?>"><i class="fas <?php echo $i;?>"></i> <?php echo $has?'✓':'✗';?> <?php echo $l;?></span>
            <?php endforeach;?>
        </div>
    </div>

    <!-- DOSSIER MÉDICAL -->
    <div class="card">
        <div class="card-title"><i class="fas fa-notes-medical"></i> Dossier médical <span style="margin-left:auto;font-size:.62rem;color:var(--txt-m);font-family:'Barlow Condensed',sans-serif;letter-spacing:.1em">CONFIDENTIEL SAMS</span></div>
        <form method="POST">
            <input type="hidden" name="action" value="upsert_dossier">
            <input type="hidden" name="pid" value="<?php echo $patient['id'];?>">
            <input type="hidden" name="alert" id="alertHidden" value="<?php echo $alertOn?'1':'';?>">
            <div class="form-grid">
                <div class="form-group"><label class="form-label">Allergies connues</label><textarea name="allergies" class="form-textarea" style="min-height:75px"><?php echo htmlspecialchars($dossier['allergies']??'');?></textarea></div>
                <div class="form-group"><label class="form-label">Antécédents médicaux</label><textarea name="antecedents" class="form-textarea" style="min-height:75px"><?php echo htmlspecialchars($dossier['antecedents']??'');?></textarea></div>
                <div class="form-group form-full"><label class="form-label">Traitements en cours</label><textarea name="traitements" class="form-textarea" style="min-height:60px"><?php echo htmlspecialchars($dossier['traitements_en_cours']??'');?></textarea></div>
                <div class="form-group form-full"><label class="form-label">Notes confidentielles (médecins)</label><textarea name="notes_conf" class="form-textarea" style="min-height:60px"><?php echo htmlspecialchars($dossier['notes_confidentielles']??'');?></textarea></div>
                <div class="form-group form-full">
                    <!-- Composant custom : alerte médicale -->
                    <div class="med-alert-box <?php echo $alertOn?'on':'';?>" id="medAlertBox">
                        <div class="med-alert-switch" onclick="toggleMedAlert()">
                            <div class="mas-track"></div>
                            <div class="mas-label"><i class="fas fa-triangle-exclamation"></i> Alerte médicale active</div>
                        </div>
                        <input type="text" name="alert_detail" class="form-input" style="margin-top:.7rem" placeholder="Détail de l'alerte (allergie grave, pathologie...)" value="<?php echo htmlspecialchars($dossier['alert_detail']??'');?>">
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.85rem">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Sauvegarder le dossier</button>
            </div>
        </form>
        <!-- Groupe sanguin (formulaire séparé) -->
        <form method="POST" style="display:flex;gap:.5rem;align-items:center;margin-top:.7rem;padding-top:.7rem;border-top:1px solid var(--bdr)">
            <input type="hidden" name="action" value="update_blood">
            <input type="hidden" name="pid" value="<?php echo $patient['id'];?>">
            <span style="font-size:.78rem;color:var(--txt-s)">Groupe sanguin :</span>
            <select name="blood_type" class="form-select" style="width:90px;font-size:.78rem"><?php foreach($BLOOD as $b):?><option value="<?php echo $b;?>" <?php echo ($patient['blood_type']??'')===$b?'selected':'';?>><?php echo $b;?></option><?php endforeach;?></select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-droplet"></i> Mettre à jour</button>
        </form>
    </div>
  </div>
  <div>
    <!-- Rapports -->
    <div class="card">
        <div class="card-title"><i class="fas fa-file-medical"></i> Rapports médicaux (<?php echo count($lastReports);?>)</div>
        <?php foreach($lastReports as $r):?>
        <div class="sub-item">
            <div><div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($r['title']);?></div>
            <div style="font-size:.7rem;color:var(--txt-s)"><?php echo date('d/m/Y H:i',strtotime($r['created_at']));?></div></div>
        </div>
        <?php endforeach; if(empty($lastReports)):?><div style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucun rapport</div><?php endif;?>
    </div>
    <!-- Ordonnances -->
    <div class="card">
        <div class="card-title"><i class="fas fa-prescription"></i> Ordonnances</div>
        <?php foreach($lastOrdo as $o):$sc=['active'=>'badge-green','completed'=>'badge-gray','cancelled'=>'badge-red'][$o['status']]??'badge-gray';$sl=['active'=>'Active','completed'=>'Terminée','cancelled'=>'Annulée'][$o['status']]??$o['status'];?>
        <div class="sub-item">
            <div><div style="font-size:.82rem;font-weight:600">Ordonnance #<?php echo $o['id'];?></div><div style="font-size:.7rem;color:var(--txt-s)"><?php echo date('d/m/Y',strtotime($o['created_at']));?></div></div>
            <span class="badge <?php echo $sc;?>"><?php echo $sl;?></span>
        </div>
        <?php endforeach; if(empty($lastOrdo)):?><div style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucune ordonnance</div><?php endif;?>
    </div>
    <!-- Factures -->
    <div class="card">
        <div class="card-title"><i class="fas fa-receipt"></i> Factures</div>
        <?php foreach($lastInvoices as $inv):$sc=['paid'=>'badge-green','pending'=>'badge-yellow','cancelled'=>'badge-gray'][$inv['status']]??'badge-gray';?>
        <div class="sub-item">
            <?php $items=json_decode($inv['items']??'[]',true)?:[];?>
            <div style="font-size:.81rem"><?php echo htmlspecialchars(implode(', ',array_column($items,'name')));?></div>
            <div style="display:flex;gap:.4rem;align-items:center">
                <span class="mono" style="font-weight:700;font-size:.82rem;color:<?php echo $inv['status']==='paid'?'#6ee7b7':'#fde68a';?>">$<?php echo number_format($inv['total_amount'],0,'.',',');?></span>
                <span class="badge <?php echo $sc;?>"><?php echo htmlspecialchars($inv['status']);?></span>
            </div>
        </div>
        <?php endforeach; if(empty($lastInvoices)):?><div style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucune facture</div><?php endif;?>
    </div>
  </div>
</div>

<script>
function toggleMedAlert(){
    const box=document.getElementById('medAlertBox');
    const on=box.classList.toggle('on');
    document.getElementById('alertHidden').value=on?'1':'';
}
</script>

<?php else: ?>
<!-- ── LISTE PATIENTS (cartes) ── -->
<div class="doc-toolbar">
    <form method="GET" class="search-wrap" style="max-width:380px">
        <i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="Rechercher par nom, prénom, téléphone..." value="<?php echo htmlspecialchars($search);?>">
    </form>
    <div style="display:flex;align-items:center;gap:.6rem">
        <?php if($search):?><a href="patients.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Effacer</a><?php endif;?>
        <span style="font-size:.78rem;color:var(--txt-m)"><?php echo count($patients);?> patient(s)</span>
    </div>
</div>

<?php if(empty($patients)):?>
<div class="empty-state"><i class="fas fa-user-injured empty-icon"></i><p>Aucun patient trouvé.</p></div>
<?php else:?>
<div class="pat-grid">
<?php foreach($patients as $p):
    $pInit=strtoupper(mb_substr($p['firstname']??'?',0,1));
    $pCol=pat_av_color($pInit);
    $hasAv=!empty($p['avatar']);
    $isAlert=!empty($p['medical_alert']);
?>
<div class="pat-card <?php echo $isAlert?'alert':'';?>" onclick="location.href='patients.php?id=<?php echo $p['id'];?>'">
    <div class="pat-top">
        <div class="pat-av" style="background:<?php echo $pCol;?>22;border-color:<?php echo $pCol;?>55;color:<?php echo $pCol;?>">
            <?php if($hasAv):?><img src="<?php echo htmlspecialchars($p['avatar']);?>" alt=""><?php else:?><?php echo $pInit;?><?php endif;?>
        </div>
        <div style="flex:1;min-width:0">
            <div class="pat-name"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></div>
            <div class="pat-meta"><?php echo (($p['age']??'')!==''?(int)$p['age'].' ans':'Âge inconnu');?><?php if(!empty($p['nationality'])):?> · <?php echo htmlspecialchars(mb_substr($p['nationality'],0,16));?><?php endif;?></div>
        </div>
        <?php if(!empty($p['blood_type'])):?><span class="badge badge-red" style="flex-shrink:0"><?php echo htmlspecialchars($p['blood_type']);?></span><?php endif;?>
    </div>
    <div class="pat-badges">
        <?php if($isAlert):?><span class="pat-alert-pill"><i class="fas fa-triangle-exclamation"></i> Alerte médicale</span><?php endif;?>
        <?php if($p['nb_rap']>0):?><span class="badge badge-blue"><i class="fas fa-file-medical"></i> <?php echo $p['nb_rap'];?></span><?php endif;?>
        <?php if($p['nb_imp']>0):?><span class="badge badge-yellow"><i class="fas fa-receipt"></i> <?php echo $p['nb_imp'];?> impayée(s)</span><?php endif;?>
        <?php if(!$isAlert && !$p['nb_rap'] && !$p['nb_imp']):?><span style="font-size:.68rem;color:var(--txt-m)"><i class="fas fa-check" style="color:#6ee7b7;margin-right:.25rem"></i>Dossier à jour</span><?php endif;?>
    </div>
    <div class="pat-foot">
        <span class="mono" style="font-size:.72rem;color:var(--txt-m)"><i class="fas fa-phone" style="font-size:.6rem;opacity:.6;margin-right:.3rem"></i><?php echo htmlspecialchars($p['phone']??'—');?></span>
        <span class="btn btn-ghost btn-xs"><i class="fas fa-folder-open"></i></span>
    </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
<?php endif;?>
<?php include __DIR__ . '/includes/components.php'; ?>
<?php include __DIR__ . '/includes/footer.php';?>
