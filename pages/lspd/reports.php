<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/lspd_log.php';
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Rapports'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0);
$_uf=$_SESSION['user_faction']??'';
$_ur=$_SESSION['user_role']??'';
$msg='';$msgType='';$newId=null;

// ── POST ACTIONS ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_report'])) {
    try {
        $type    =trim($_POST['type']??''); if(!$type) throw new Exception('Type obligatoire.');
        $title   =trim($_POST['title']??''); if(!$title) throw new Exception('Titre obligatoire.');
        $status  =trim($_POST['status']??'submitted');
        $priority=trim($_POST['priority']??'normal');
        $location=trim($_POST['location']??'');
        $incidentAt=!empty($_POST['incident_at'])?$_POST['incident_at']:null;
        $tags    =!empty($_POST['tags'])?json_encode($_POST['tags']):null;
        $officers=!empty($_POST['officers'])?json_encode($_POST['officers']):null;
        $civilians=!empty($_POST['civilians'])?json_encode($_POST['civilians']):null;
        $witnesses=!empty($_POST['witnesses'])?json_encode($_POST['witnesses']):null;
        $suspects=!empty($_POST['suspects'])?json_encode($_POST['suspects']):null;
        $vehicles=!empty($_POST['vehicles'])?json_encode($_POST['vehicles']):null;
        $descRich=$_POST['description_rich']??'';
        $descPlain=strip_tags($descRich);
        $internalNotes=trim($_POST['internal_notes']??'');

        // Evidence URLs
        $evidenceUrls=[];
        if(!empty($_POST['evidence_urls'])){
            foreach(preg_split('/[\n\r]+/',trim($_POST['evidence_urls'])) as $line){
                $u=trim($line); if($u!='')$evidenceUrls[]=$u;
            }
        }
        $evidence=!empty($evidenceUrls)?json_encode($evidenceUrls):null;

        // Build query dynamically
        $existingCols=array_column($db->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_ASSOC),'Field');
        $hasStatus  =in_array('status',$existingCols);
        $hasLocation=in_array('location',$existingCols);
        $hasVehicles=in_array('vehicles',$existingCols);
        $hasNotes   =in_array('internal_notes',$existingCols);

        $cols=['type','title','tags','evidence','officers','officers_involved','civilians','witnesses','suspects','description','description_rich','faction','created_by'];
        $vals=[$type,$title,$tags,$evidence,$officers,$officers,$civilians,$witnesses,$suspects,$descPlain,$descRich,$_uf,$uid];

        if($hasStatus){$cols[]='status';$vals[]=$status;$cols[]='priority';$vals[]=$priority;}
        if($hasLocation){$cols[]='location';$vals[]=$location?:null;$cols[]='incident_at';$vals[]=$incidentAt;}
        if($hasVehicles){$cols[]='vehicles';$vals[]=$vehicles;}
        if($hasNotes){$cols[]='internal_notes';$vals[]=$internalNotes?:null;}

        $db->prepare("INSERT INTO reports (".implode(',',$cols).") VALUES (".implode(',',array_fill(0,count($cols),'?')).")")->execute($vals);
        $newId=$db->lastInsertId();

        // Penal codes
        if(!empty($_POST['penal_codes'])){
            $pcIds=json_decode($_POST['penal_codes'],true);
            if(is_array($pcIds)){
                try{
                    $sp=$db->prepare("INSERT IGNORE INTO report_penal_codes (report_id,penal_code_id) VALUES (?,?)");
                    foreach($pcIds as $pcEntry){
                        // Nouveau format [{id,level,fine,up}] ou ancien format [int]
                        $pcId = is_array($pcEntry) ? intval($pcEntry['id']) : intval($pcEntry);
                        if($pcId>0) $sp->execute([$newId,$pcId]);
                    }
                }catch(Exception $e){}
            }
        }
        $msg='Rapport #'.str_pad($newId,6,'0',STR_PAD_LEFT).' créé avec succès.';
        $msgType='success';
        lspd_log($db,$uid,'submit_report','reports',(int)$newId,'Rapport #'.str_pad($newId,6,'0',STR_PAD_LEFT),$type.' — '.$title);

        // ── SYNC AUTO → criminal_records ─────────────────────────────────
        // Pour chaque suspect × chaque code pénal → INSERT dans criminal_records
        // Pas de doublon : vérifié par (citizen_id, offense_code, incident_date)
        if ($newId && !empty($_POST['penal_codes'])) {
            // suspects[] vient de checkboxes HTML → PHP array direct, PAS json_decode
            $suspectIds = !empty($_POST['suspects']) ? array_filter(array_map('intval', (array)$_POST['suspects'])) : [];
            // penal_codes vient d'un hidden input rempli par JS → JSON string
            $pcIds      = json_decode($_POST['penal_codes'] ?? '[]', true) ?: [];
            // Normaliser : si ancien format [int], convertir en [{id,level}]

            if (!empty($suspectIds) && !empty($pcIds)) {
                try {
                    // pcIds peut être soit [{id, level, fine, up}] soit [int] (ancien format)
                    $pcIdsInt = [];
                    $pcLevelMap = []; // id → {level, fine, up}
                    foreach ($pcIds as $entry) {
                        if (is_array($entry) && isset($entry['id'])) {
                            $pcIdsInt[]              = intval($entry['id']);
                            $pcLevelMap[$entry['id']] = ['level'=>$entry['level']??'nominal','fine'=>floatval($entry['fine']??0),'up'=>floatval($entry['up']??0)];
                        } else {
                            $pcIdsInt[] = intval($entry);
                        }
                    }
                    if (empty($pcIdsInt)) throw new Exception('Aucun code pénal.');
                    $phs = implode(',', array_fill(0, count($pcIdsInt), '?'));
                    $pcRows = $db->prepare("SELECT id,code,title,max_fine,max_jail_months,gravity_fines,gravity_up,points,category FROM penal_code WHERE id IN ($phs) AND active=1");
                    $pcRows->execute($pcIdsInt);
                    $pcData = $pcRows->fetchAll(PDO::FETCH_ASSOC);

                    $incDate = !empty($incidentAt) ? date('Y-m-d', strtotime($incidentAt)) : date('Y-m-d');
                    $catToType = ['crime'=>'felony','delit_majeur'=>'misdemeanor','delit_mineur'=>'misdemeanor','infraction'=>'infraction'];

                    $stmtDup = $db->prepare("SELECT id FROM criminal_records WHERE citizen_id=? AND offense_code=? AND incident_date=?");
                    $stmtIns = $db->prepare("INSERT INTO criminal_records (citizen_id,record_type,offense_code,offense_title,description_rich,severity,fine_amount,jail_time,points,officer_id,incident_date,created_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active')");
                    $reportRef = "Issu du rapport #" . str_pad($newId, 6, '0', STR_PAD_LEFT);

                    foreach ($suspectIds as $suspectId) {
                        if (!$suspectId) continue;
                        foreach ($pcData as $pc) {
                            $stmtDup->execute([$suspectId, $pc['code'], $incDate]);
                            if ($stmtDup->fetchColumn()) continue;

                            // Résoudre amende / UP selon le niveau de gravité sélectionné
                            $lvlData  = $pcLevelMap[$pc['id']] ?? null;
                            $fineAmt  = 0;
                            $upTime   = 0;
                            if ($lvlData && ($lvlData['fine'] > 0 || $lvlData['up'] > 0)) {
                                $fineAmt = $lvlData['fine'];
                                $upTime  = $lvlData['up'];
                            } elseif ($lvlData && !empty($lvlData['level'])) {
                                // Essayer de lire depuis gravity_fines/gravity_up
                                $gF = json_decode($pc['gravity_fines']??'{}',true)?:[];
                                $gU = json_decode($pc['gravity_up']??'{}',true)?:[];
                                $lvl = $lvlData['level'];
                                $fineAmt = floatval($gF[$lvl] ?? $pc['max_fine'] ?? 0);
                                $upTime  = floatval($gU[$lvl] ?? $pc['max_jail_months'] ?? 0);
                            } else {
                                $fineAmt = floatval($pc['max_fine'] ?? 0);
                                $upTime  = floatval($pc['max_jail_months'] ?? 0);
                            }

                            $stmtIns->execute([
                                $suspectId,
                                $catToType[$pc['category']] ?? 'infraction',
                                $pc['code'],
                                $pc['title'],
                                $reportRef,
                                1,
                                $fineAmt,
                                intval($upTime),
                                intval($pc['points'] ?? 0),
                                $uid,
                                $incDate,
                                $uid,
                            ]);
                        }
                    }
                } catch (Exception $eSyncCr) {
                    error_log('Sync criminal_records: ' . $eSyncCr->getMessage());
                }
            }
        }
        // ── LIER LES SAISIES EXISTANTES ──────────────────────────────────
        if ($newId && !empty($_POST['linked_saisies_ids'])) {
            $linkedIds = json_decode($_POST['linked_saisies_ids'], true);
            if (is_array($linkedIds) && !empty($linkedIds)) {
                try {
                    $phs = implode(',', array_fill(0, count($linkedIds), '?'));
                    $vals = array_map('intval', $linkedIds);
                    $vals[] = $newId;
                    $db->prepare("UPDATE lspd_saisies SET report_id=? WHERE id IN ($phs) AND report_id IS NULL")
                       ->execute(array_merge([$newId], array_map('intval', $linkedIds)));
                } catch (Exception $eLk) {
                    error_log('Link saisies: ' . $eLk->getMessage());
                }
            }
        }
        // ── FIN SYNC criminal_records ────────────────────────────────────

        // ── SYNC SAISIES → lspd_saisies ──────────────────────────────────
        // Les saisies déclarées dans le formulaire sont sérialisées en JSON
        if ($newId && !empty($_POST['saisies_data'])) {
            $saisiesData = json_decode($_POST['saisies_data'], true);
            if (is_array($saisiesData) && !empty($saisiesData)) {
                try {
                    // S'assurer que la table existe
                    $db->exec("CREATE TABLE IF NOT EXISTS `lspd_saisies` (
                        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        `criminal_record_id` int(11) DEFAULT NULL,
                        `report_id` int(11) DEFAULT NULL,
                        `citizen_id` int(11) DEFAULT NULL,
                        `categorie` enum('arme','drogue','vehicule','argent','bijou','electronique','document','autre') NOT NULL DEFAULT 'autre',
                        `description` varchar(300) NOT NULL,
                        `quantite` varchar(100) DEFAULT NULL,
                        `valeur_estimee` decimal(10,2) DEFAULT NULL,
                        `lieu_saisie` varchar(200) DEFAULT NULL,
                        `numero_evidence` varchar(50) DEFAULT NULL,
                        `statut` enum('saisie','detruit','restitue','en_evidence','transfiere') DEFAULT 'saisie',
                        `notes` text DEFAULT NULL,
                        `created_by` int(11) DEFAULT NULL,
                        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $szSeq = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM lspd_saisies")->fetchColumn();
                    $stmtSz = $db->prepare("INSERT INTO lspd_saisies (report_id, citizen_id, categorie, description, quantite, valeur_estimee, lieu_saisie, numero_evidence, statut, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");

                    foreach ($saisiesData as $sz) {
                        if (empty($sz['description'])) continue;
                        $szSeq++;
                        $szNum = 'SZ-'.date('Y').'-'.str_pad($szSeq, 4, '0', STR_PAD_LEFT);
                        $stmtSz->execute([
                            $newId,
                            !empty($sz['citizen_id']) ? (int)$sz['citizen_id'] : null,
                            $sz['categorie'] ?? 'autre',
                            mb_substr(trim($sz['description']), 0, 300),
                            mb_substr(trim($sz['quantite'] ?? ''), 0, 100) ?: null,
                            !empty($sz['valeur']) ? floatval($sz['valeur']) : null,
                            mb_substr(trim($sz['lieu'] ?? ''), 0, 200) ?: null,
                            $szNum,
                            'saisie',
                            $uid,
                        ]);
                    }
                } catch (Exception $eSz) {
                    error_log('Sync lspd_saisies: ' . $eSz->getMessage());
                }
            }
        }
        // ── FIN SYNC saisies ─────────────────────────────────────────────
    } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_report'])) {
    $rid=intval($_POST['report_id']);
    $r=$db->prepare("SELECT created_by FROM reports WHERE id=?");$r->execute([$rid]);$row=$r->fetch();
    if($row&&($row['created_by']==$uid||hasRole(['*','Administrateur','Développeur','Staff']))){
        $db->prepare("DELETE FROM reports WHERE id=?")->execute([$rid]);
        $msg='Rapport supprimé.';$msgType='success';
        lspd_log($db,$uid,'delete_report','reports',$rid,'Rapport #'.str_pad($rid,6,'0',STR_PAD_LEFT));
    }
}

// ── EDIT REPORT ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_report'])) {
    $rid = intval($_POST['report_id']??0);
    $rChk = $db->prepare("SELECT created_by FROM reports WHERE id=?");
    $rChk->execute([$rid]); $rChkRow = $rChk->fetch();
    $canEdit = $rChkRow && ($rChkRow['created_by']==$uid || hasRole(['*','Administrateur','Développeur','Staff']));
    if (!$canEdit) { $msg='Permission refusée.'; $msgType='error'; }
    else {
        try {
            $type     = trim($_POST['type']??'');     if(!$type)  throw new Exception('Type obligatoire.');
            $title    = trim($_POST['title']??'');    if(!$title) throw new Exception('Titre obligatoire.');
            $status   = trim($_POST['status']??'submitted');
            $priority = trim($_POST['priority']??'normal');
            $location = trim($_POST['location']??'');
            $incidentAt = !empty($_POST['incident_at'])?$_POST['incident_at']:null;
            $tags     = !empty($_POST['tags'])    ? json_encode($_POST['tags'])     : null;
            $officers = !empty($_POST['officers'])? json_encode($_POST['officers']) : null;
            $civilians= !empty($_POST['civilians'])?json_encode($_POST['civilians']): null;
            $witnesses= !empty($_POST['witnesses'])?json_encode($_POST['witnesses']): null;
            $suspects = !empty($_POST['suspects'])? json_encode($_POST['suspects'])  : null;
            $vehicles = !empty($_POST['vehicles'])? json_encode($_POST['vehicles'])  : null;
            $descRich = $_POST['description_rich']??'';
            $descPlain= strip_tags($descRich);
            $internalNotes = trim($_POST['internal_notes']??'');
            $evidenceUrls=[];
            if(!empty($_POST['evidence_urls'])){
                foreach(preg_split('/[

]+/',trim($_POST['evidence_urls'])) as $line){
                    $u=trim($line); if($u!='')$evidenceUrls[]=$u;
                }
            }
            $evidence = !empty($evidenceUrls)?json_encode($evidenceUrls):null;

            $existingCols=array_column($db->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_ASSOC),'Field');
            $hasStatus  =in_array('status',$existingCols);
            $hasLocation=in_array('location',$existingCols);
            $hasVehicles=in_array('vehicles',$existingCols);
            $hasNotes   =in_array('internal_notes',$existingCols);

            $sets=[]; $vals=[];
            $sets[]='type=?';              $vals[]=$type;
            $sets[]='title=?';             $vals[]=$title;
            $sets[]='tags=?';              $vals[]=$tags;
            $sets[]='evidence=?';          $vals[]=$evidence;
            $sets[]='officers=?';          $vals[]=$officers;
            $sets[]='officers_involved=?'; $vals[]=$officers;
            $sets[]='civilians=?';         $vals[]=$civilians;
            $sets[]='witnesses=?';         $vals[]=$witnesses;
            $sets[]='suspects=?';          $vals[]=$suspects;
            $sets[]='description=?';       $vals[]=$descPlain;
            $sets[]='description_rich=?';  $vals[]=$descRich;
            if($hasStatus)  { $sets[]='status=?';         $vals[]=$status;       $sets[]='priority=?';    $vals[]=$priority; }
            if($hasLocation){ $sets[]='location=?';       $vals[]=$location?:null; $sets[]='incident_at=?'; $vals[]=$incidentAt; }
            if($hasVehicles){ $sets[]='vehicles=?';       $vals[]=$vehicles; }
            if($hasNotes)   { $sets[]='internal_notes=?'; $vals[]=$internalNotes?:null; }
            $vals[]=$rid;
            $db->prepare("UPDATE reports SET ".implode(',',$sets)." WHERE id=?")->execute($vals);

            // Mise à jour des codes pénaux
            $db->prepare("DELETE FROM report_penal_codes WHERE report_id=?")->execute([$rid]);
            if(!empty($_POST['penal_codes'])){
                $pcIds=json_decode($_POST['penal_codes'],true);
                if(is_array($pcIds)){
                    $sp=$db->prepare("INSERT IGNORE INTO report_penal_codes (report_id,penal_code_id) VALUES (?,?)");
                    foreach($pcIds as $pcEntry){
                        $pcId=is_array($pcEntry)?intval($pcEntry['id']):intval($pcEntry);
                        if($pcId>0) $sp->execute([$rid,$pcId]);
                    }
                }
            }
            header('Location: reports.php?id='.$rid.'&edited=1'); exit;
        lspd_log($db,$uid,'edit_report','reports',$rid,'Rapport #'.str_pad($rid,6,'0',STR_PAD_LEFT),$type.' — '.$title);
        } catch(Exception $e){ $msg=$e->getMessage(); $msgType='error'; }
    }
}

// ── DATA FOR FORM ─────────────────────────────────────────────────────────
$officers_list=[];try{$officers_list=$db->query("SELECT id,username,faction,faction_grade FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}
$profiles_list=[];try{$profiles_list=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 300")->fetchAll();}catch(Exception $e){}
$vehicles_list=[];try{$vehicles_list=$db->query("SELECT v.id,v.plate,v.model,CONCAT(p.firstname,' ',p.lastname) AS owner_name FROM vehicles v LEFT JOIN profiles p ON p.id=v.owner_id ORDER BY v.plate LIMIT 200")->fetchAll();}catch(Exception $e){}
$penalCodes=[];try{$penalCodes=$db->query("SELECT id,code,nom_code,title,category,min_fine,max_fine,min_jail_months,max_jail_months,severity,gravity_fines,gravity_up FROM penal_code WHERE active=1 ORDER BY category,severity DESC,code LIMIT 500")->fetchAll();}catch(Exception $e){}

// Tags from DB (staff-managed)
$predefinedTags=[];
try{$predefinedTags=$db->query("SELECT label,color FROM report_tags ORDER BY sort_order,id")->fetchAll();}catch(Exception $e){
    $predefinedTags=[
        ['label'=>'LSPD','color'=>'#3b82f6'],['label'=>'LSSD','color'=>'#f59e0b'],
        ['label'=>'Urgent','color'=>'#ef4444'],['label'=>'Remis DOJ','color'=>'#8b5cf6'],
        ['label'=>'Attente DOJ','color'=>'#f97316'],['label'=>'Validé','color'=>'#22c55e'],
        ['label'=>'Payé','color'=>'#10b981'],['label'=>'Confidentiel','color'=>'#a855f7'],
        ['label'=>'Code Rouge','color'=>'#dc2626'],['label'=>'Décès','color'=>'#374151'],
        ['label'=>'Blessé grave','color'=>'#f43f5e'],['label'=>'Gang','color'=>'#7c3aed'],
    ];
}

// Grade check
$userGrade='';try{$sg=$db->prepare("SELECT faction_grade FROM users WHERE id=?");$sg->execute([$uid]);$userGrade=$sg->fetch()['faction_grade']??'';}catch(Exception $e){}
$_allowedLspdGrades=['Sergeant I','Sergeant II','Lieutenant I','Lieutenant II','Captain','Commander','Assistant Chief','Chief of Police'];
$_canAdmin=hasRole(['*','Administrateur','Développeur','Staff'])||($_uf==='LSPD'&&in_array($userGrade,$_allowedLspdGrades));

// Saisies existantes disponibles (non liées à un rapport)
$existingSaisies=[];

// LIST / FILTERS
$fType=$_GET['type']??'all';$fDate=$_GET['date']??'all';$search=trim($_GET['q']??'');$viewId=intval($_GET['id']??0);
$viewReport=null;
if($viewId>0){$s=$db->prepare("SELECT r.*,u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.id=? AND r.faction='LSPD'");$s->execute([$viewId]);$viewReport=$s->fetch();}

// ── Mode édition ──────────────────────────────────────────────────────────
$editId = intval($_GET['edit']??0);
$editReport = null; $editPenalCodes = [];
if ($editId > 0) {
    $s2=$db->prepare("SELECT r.*,u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.id=? AND r.faction='LSPD'");
    $s2->execute([$editId]); $editReport=$s2->fetch();
    // Vérif permission : auteur ou admin
    if ($editReport && $editReport['created_by']!=$uid && !hasRole(['*','Administrateur','Développeur','Staff'])) {
        $editReport=null;
    }
    if ($editReport) {
        try {
            $epSt=$db->prepare("SELECT rpc.penal_code_id AS id FROM report_penal_codes rpc WHERE rpc.report_id=?");
            $epSt->execute([$editId]);
            $editPenalCodes=array_column($epSt->fetchAll(PDO::FETCH_ASSOC),'id');
        } catch(Exception $e){}
    }
}
// Message "modifié avec succès"
if(isset($_GET['edited'])&&$viewReport){ $msg='Rapport modifié avec succès.'; $msgType='success'; }

// ── Saisies disponibles à lier (pour le formulaire de création) ──────────
try{
    $existingSaisies=$db->query("
        SELECT s.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen
        FROM lspd_saisies s
        LEFT JOIN profiles p ON p.id=s.citizen_id
        WHERE s.report_id IS NULL AND s.statut='saisie'
        ORDER BY s.created_at DESC LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){}

// ── Saisies déjà liées au rapport consulté (pour la vue) ─────────────────
$viewReportSaisies=[];
if($viewId>0){
    try{
        $sr=$db->prepare("
            SELECT s.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen
            FROM lspd_saisies s
            LEFT JOIN profiles p ON p.id=s.citizen_id
            WHERE s.report_id=? ORDER BY s.created_at ASC
        ");
        $sr->execute([$viewId]);
        $viewReportSaisies=$sr->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){}
}
if(!$viewReport&&!($msgType==='success'&&$newId)){
    $q="SELECT r.*,u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.faction='LSPD'";
    $params=[];
    if($fType!=='all'){$q.=" AND r.type=?";$params[]=$fType;}
    if($fDate==='today')$q.=" AND DATE(r.created_at)=CURDATE()";
    elseif($fDate==='week')$q.=" AND r.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)";
    elseif($fDate==='month')$q.=" AND r.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)";
    if($search){$q.=" AND (r.title LIKE ? OR r.description_rich LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;}
    $q.=" ORDER BY r.created_at DESC LIMIT 150";
    $stmt=$db->prepare($q);$stmt->execute($params);$reports=$stmt->fetchAll();
}

$catNames=['crime'=>'Crime','delit_majeur'=>'Délit Majeur','delit_mineur'=>'Délit Mineur','infraction'=>'Infraction'];
$catIcons=['crime'=>'fa-exclamation-triangle','delit_majeur'=>'fa-exclamation-circle','delit_mineur'=>'fa-info-circle','infraction'=>'fa-flag'];
$RTYPES=['RA'=>['RA — Rapport d\'Arrestation','bg-red'],'Shooting Report'=>['Rapport de Tirs','bg-orange'],'Traffic Ticket'=>['Contravention','bg-yellow'],'Investigation'=>['Investigation','bg-purple'],'Use of Force'=>['Usage de la Force','bg-orange'],'Arrest Report'=>['Rapport d\'Arrestation','bg-blue'],'Missing Person'=>['Personne Disparue','bg-gray'],'Incident Report'=>['Rapport d\'Incident','bg-blue']];
$RSTATUS=['draft'=>['Brouillon','bg-gray'],'submitted'=>['Soumis','bg-blue'],'approved'=>['Approuvé','bg-green'],'closed'=>['Clôturé','bg-gray']];
$RPRIO=['low'=>['Basse','bg-green'],'normal'=>['Normale','bg-blue'],'high'=>['Haute','bg-yellow'],'critical'=>['Critique','bg-red']];

include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Report page styles ── */
:root{--cp:#2563eb;--cb:rgba(37,99,235,.12);--cbh:rgba(37,99,235,.3);--cok:#10b981;--cw:#f59e0b;--cd:#ef4444;--cm:var(--txt-s)}
.rp-layout{display:grid;grid-template-columns:220px 1fr;gap:1.25rem}
.filter-box{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1rem;margin-bottom:.85rem}
.filter-title{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-m);margin-bottom:.65rem}
.filter-btn{display:flex;align-items:center;gap:.5rem;padding:.42rem .7rem;border-radius:7px;font-size:.79rem;font-weight:500;cursor:pointer;transition:all .17s;text-decoration:none;color:var(--txt-s);width:100%;background:transparent;border:none;font-family:inherit}
.filter-btn:hover,.filter-btn.active{background:rgba(37,99,235,.08);color:var(--blue-xl)}
.rp-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1rem;margin-bottom:.65rem;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.rp-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--blue);opacity:0;transition:opacity .2s}
.rp-card:hover{border-color:var(--bdr-h);transform:translateX(2px)}.rp-card:hover::before{opacity:1}
/* Editor */
.ew{border:1px solid rgba(37,99,235,.2);border-radius:9px;overflow:hidden}.ew:focus-within{border-color:rgba(37,99,235,.4)}
.etb{display:flex;flex-wrap:wrap;gap:2px;padding:.45rem .55rem;background:rgba(0,0,0,.15);border-bottom:1px solid rgba(37,99,235,.1)}
.ebtn{width:28px;height:28px;border-radius:5px;background:transparent;border:1px solid transparent;color:var(--txt-s);cursor:pointer;transition:all .15s;font-size:.78rem}
.ebtn:hover{background:rgba(37,99,235,.1);border-color:rgba(37,99,235,.2);color:var(--blue-xl)}
.esel{background:rgba(0,0,0,.2);border:1px solid rgba(37,99,235,.12);border-radius:5px;color:var(--txt-s);font-size:.72rem;padding:3px 6px}.esel option{background:#0a1020}
.re{min-height:200px;padding:1rem;background:rgba(0,0,0,.08);color:var(--txt);font-family:'IBM Plex Sans',inherit;font-size:.86rem;line-height:1.7;outline:none}
.re:empty::before{content:attr(data-ph);color:var(--txt-m);font-style:italic}
/* MultiSelect */
.msb{max-height:180px;overflow-y:auto;background:rgba(0,0,0,.15);border:1px solid rgba(37,99,235,.18);border-radius:9px}
.msb::-webkit-scrollbar{width:3px}.msb::-webkit-scrollbar-thumb{background:rgba(37,99,235,.15)}
.ms-sw{position:relative}.ms-sw i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.67rem;color:var(--txt-m);pointer-events:none}
.ms-si{width:100%;padding:.5rem .7rem .5rem 2rem;background:rgba(0,0,0,.2);border:none;border-bottom:1px solid rgba(37,99,235,.1);color:var(--txt);font-size:.78rem;font-family:inherit}
.ms-si::placeholder{color:var(--txt-m)}.ms-si:focus{outline:none}
.msi{display:flex;align-items:center;gap:.6rem;padding:.4rem .7rem;cursor:pointer;transition:background .12s}.msi:hover{background:rgba(37,99,235,.06)}
.msi input[type=checkbox]{accent-color:var(--blue);width:13px;height:13px;cursor:pointer;flex-shrink:0}
.msi label{cursor:pointer;font-size:.8rem;color:var(--txt-s);flex:1}
.ms-bge{font-family:'IBM Plex Mono',monospace;font-size:.56rem;padding:1px 6px;border-radius:4px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.15);color:var(--blue-xl)}
/* Tags — états visuellement distincts (non-sélectionné sombre/mat, sélectionné couleur pleine) */
.tch{
    --tag-color:#94a3b8;
    --tag-rgb:148,163,184;
    padding:.4rem .82rem;border-radius:7px;font-size:.72rem;font-weight:600;
    cursor:pointer;border:1px solid;transition:background .18s ease,color .18s ease,border-color .18s ease,box-shadow .18s ease,transform .18s ease,filter .18s ease,opacity .18s ease;
    display:inline-flex;align-items:center;gap:.35rem;margin:.22rem;user-select:none;
    /* état par défaut : tag NON sélectionné → volontairement sombre/mat */
    color:rgba(var(--tag-rgb),.55);
    background:rgba(var(--tag-rgb),.04);
    border-color:rgba(var(--tag-rgb),.18);
    filter:saturate(.55) brightness(.85);
    opacity:.72;
}
.tch:hover{
    filter:saturate(.9) brightness(1);
    opacity:.92;
    border-color:rgba(var(--tag-rgb),.45);
    background:rgba(var(--tag-rgb),.09);
    transform:translateY(-1px);
}
.tch.sel{
    color:var(--tag-color);
    background:rgba(var(--tag-rgb),.2);
    border-color:rgba(var(--tag-rgb),.75);
    box-shadow:0 0 10px rgba(var(--tag-rgb),.3),inset 0 0 0 1px rgba(var(--tag-rgb),.25);
    font-weight:700;
    filter:saturate(1.15) brightness(1.08);
    opacity:1;
    transform:none;
}
.tch.sel:hover{transform:translateY(-1px);box-shadow:0 0 14px rgba(var(--tag-rgb),.45),inset 0 0 0 1px rgba(var(--tag-rgb),.3)}
.tch.sel::before{content:'\f00c';font-family:'Font Awesome 5 Free';font-weight:900;font-size:.6rem;margin-right:.1rem;opacity:.9}
/* PC */
.pcc{padding:.75rem;background:rgba(37,99,235,.03);border:2px solid rgba(37,99,235,.08);border-radius:9px;cursor:pointer;transition:all .2s;position:relative}
.pc-grav-btns{display:flex;flex-wrap:wrap;gap:.28rem}
.pc-grav-btn{padding:.22rem .52rem;border-radius:5px;border:1px solid;cursor:pointer;transition:all .15s;font-size:.64rem;font-weight:700;font-family:'IBM Plex Mono',monospace;background:var(--lc,#3b82f6)11;border-color:var(--lc,#3b82f6)33;color:var(--lc,#3b82f6);display:flex;flex-direction:column;align-items:center;gap:0;line-height:1.25}
.pc-grav-btn .pc-grav-btn-info{font-size:.58rem;font-weight:400;opacity:.75}
.pc-grav-btn.active{background:var(--lc,#3b82f6)25;border-color:var(--lc,#3b82f6);box-shadow:0 0 10px var(--lc,#3b82f6)22;transform:scale(1.05)}
.pc-grav-result{margin-top:.45rem;padding:.3rem .55rem;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);border-radius:6px;font-size:.7rem;display:flex;gap:.65rem;align-items:center}
.pc-grav-result span{font-family:'IBM Plex Mono',monospace;font-weight:700}
.pcc:hover{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.2);transform:translateY(-1px)}
.pcc.sel{background:rgba(37,99,235,.12);border-color:var(--blue)}
.pcc.sel::after{content:'\f00c';font-family:'Font Awesome 5 Free';font-weight:900;position:absolute;top:6px;right:6px;width:18px;height:18px;background:var(--blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff}
.pcgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.5rem;max-height:340px;overflow-y:auto;padding:.3rem}
.pcgrid::-webkit-scrollbar{width:4px}.pcgrid::-webkit-scrollbar-thumb{background:rgba(37,99,235,.12)}
.pccode{display:inline-block;padding:1px 6px;background:rgba(37,99,235,.12);border-radius:4px;font-size:.62rem;color:var(--blue-xl);font-family:'IBM Plex Mono',monospace;margin-bottom:.3rem}
.pctitle{font-size:.82rem;font-weight:700;margin:.2rem 0}
.pccat{font-size:.65rem;color:var(--txt-m);margin-bottom:.35rem}
.pcpen{display:flex;gap:1.25rem;font-size:.7rem;color:var(--txt-s);margin-top:.4rem}
/* Step bar */
.steps{display:flex;align-items:center;margin-bottom:1.5rem;background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:.9rem 1.25rem;gap:.5rem;flex-wrap:wrap}
.step{display:flex;align-items:center;gap:.5rem;flex-shrink:0;cursor:pointer}
.s-num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;border:1px solid rgba(37,99,235,.25);color:var(--txt-s);font-family:'IBM Plex Mono',monospace}
.step.act .s-num{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:0 0 12px rgba(37,99,235,.35)}
.step.done .s-num{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.4);color:#6ee7b7}
.s-lbl{font-size:.72rem;font-weight:600;color:var(--txt-m);white-space:nowrap}
.step.act .s-lbl{color:var(--blue-xl)}.step.done .s-lbl{color:#6ee7b7}
.s-line{flex:1;min-width:20px;height:1px;background:var(--bdr)}.s-line.done{background:rgba(16,185,129,.3)}
.section-head{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);padding:.6rem 0 .4rem;border-top:1px solid var(--bdr);margin:.8rem 0 .6rem;display:flex;align-items:center;gap:.4rem}
.section-head i{color:var(--blue-l)}
/* Status chips */
.chip{padding:.38rem .85rem;border-radius:8px;font-size:.72rem;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .15s;display:inline-flex;align-items:center;gap:.4rem;margin:.2rem}
.chip.sel{outline:2px solid currentColor;box-shadow:0 0 10px currentColor}
/* Submit bar */
.subbar{position:sticky;bottom:0;background:rgba(3,8,15,.95);backdrop-filter:blur(10px);border-top:1px solid var(--bdr);padding:.9rem 1.65rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:0 -1.65rem -1.4rem;z-index:50}
@media(max-width:1100px){.rp-layout{grid-template-columns:1fr}}
</style>

<?php // ── SUCCESS STATE ────────────────────────────────────────────────
if($msgType==='success'&&$newId):?>
<div style="text-align:center;padding:4rem 2rem;background:var(--bg-c);border:1px solid rgba(16,185,129,.3);border-radius:16px">
    <div style="width:72px;height:72px;border-radius:50%;background:rgba(16,185,129,.15);border:2px solid rgba(16,185,129,.4);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:1.75rem;color:#6ee7b7"><i class="fas fa-check"></i></div>
    <div style="font-family:'Syne',serif;font-size:1.4rem;font-weight:800;color:#fff;margin-bottom:.5rem">Rapport créé avec succès</div>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:1.1rem;color:#6ee7b7;margin-bottom:1rem">#<?php echo str_pad($newId,6,'0',STR_PAD_LEFT);?></div>
    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
        <a href="reports.php" class="btn btn-secondary"><i class="fas fa-list"></i> Tous les rapports</a>
        <a href="reports.php?new=1" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau rapport</a>
    </div>
</div>

<?php // ── VIEW REPORT ──────────────────────────────────────────────────
elseif($viewReport):
    $rtags=json_decode($viewReport['tags']??'[]',true)?:[];
    $revid=json_decode($viewReport['evidence']??'[]',true)?:[];
    [$rtl,$rtb]=$RTYPES[$viewReport['type']??'']??[$viewReport['type']??'—','bg-gray'];
?>
<?php if($msg&&$msgType==='success'):?><div class="alert alert-success" style="margin-bottom:.85rem"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg);?></div><?php endif;?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem">
    <a href="reports.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Rapports</a>
    <div style="display:flex;gap:.5rem">
        <?php if($viewReport['created_by']==$uid||$_canAdmin):?>
        <a href="reports.php?edit=<?php echo $viewReport['id'];?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-pen-to-square"></i> Modifier
        </a>
        <form method="POST" style="margin:0" id="delReportForm" data-confirm data-confirm-title="Supprimer le rapport" data-confirm-msg="Cette action est <strong>irréversible</strong>. Le rapport sera définitivement supprimé." data-confirm-target="Rapport #<?php echo str_pad($viewReport['id'],6,'0',STR_PAD_LEFT);?>" data-confirm-ok="Supprimer" data-confirm-class="btn-danger"><input type="hidden" name="delete_report" value="1"><input type="hidden" name="report_id" value="<?php echo $viewReport['id'];?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form>
        <?php endif;?>
        <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fas fa-print"></i></button>
    </div>
</div>
<div class="card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem">
        <div>
            <span class="badge <?php echo $rtb;?>" style="margin-bottom:.45rem;font-size:.72rem"><?php echo $rtl;?></span>
            <div style="font-family:'Syne',serif;font-size:1.2rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($viewReport['title']);?></div>
        </div>
        <div style="text-align:right">
            <div style="font-size:.72rem;color:var(--txt-m)">Par <strong style="color:var(--txt)"><?php echo htmlspecialchars($viewReport['auteur']??'—');?></strong></div>
            <div style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y H:i',strtotime($viewReport['created_at']));?></div>
            <?php $sc=$RSTATUS[$viewReport['status']??'draft']??['—','bg-gray'];?>
            <span class="badge <?php echo $sc[1];?>" style="margin-top:.35rem;display:inline-flex"><?php echo $sc[0];?></span>
        </div>
    </div>
    <?php if(!empty($rtags)):?>
    <div style="margin-bottom:.75rem"><?php foreach($rtags as $t):
        $tc='#94a3b8';
        foreach($predefinedTags as $pt){if($pt['label']===$t){$tc=$pt['color'];break;}}
    ?><span style="padding:.22rem .6rem;border-radius:5px;font-size:.65rem;font-weight:700;border:1px solid <?php echo $tc;?>33;background:<?php echo $tc;?>1a;color:<?php echo $tc;?>;margin:.1rem;display:inline-flex"><?php echo htmlspecialchars($t);?></span><?php endforeach;?></div>
    <?php endif;?>
    <?php if(!empty($viewReport['location'])):?><div style="font-size:.78rem;color:var(--txt-s);margin-bottom:.5rem"><i class="fas fa-location-dot" style="color:var(--blue-l);margin-right:.4rem"></i><?php echo htmlspecialchars($viewReport['location']);?></div><?php endif;?>
</div>
<?php if(!empty($viewReport['description_rich'])):?>
<div class="card"><div class="card-title"><i class="fas fa-file-lines"></i> Rapport</div>
    <div style="font-size:.875rem;line-height:1.85;color:var(--txt)"><?php echo $viewReport['description_rich'];?></div>
</div>
<?php endif;?>
<?php if(!empty($revid)):?>
<div class="card"><div class="card-title"><i class="fas fa-camera"></i> Preuves (<?php echo count($revid);?>)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.65rem">
        <?php foreach($revid as $url):$isImg=preg_match('/\.(jpe?g|png|gif|webp)(\?.*)?$/i',$url);?>
        <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;overflow:hidden">
            <?php if($isImg):?><a href="<?php echo htmlspecialchars($url);?>" target="_blank"><img src="<?php echo htmlspecialchars($url);?>" style="width:100%;height:140px;object-fit:cover" onerror="this.parentNode.style.display='none'"></a>
            <?php else:?><a href="<?php echo htmlspecialchars($url);?>" target="_blank" style="padding:.75rem;display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:var(--blue-xl);text-decoration:none"><i class="fas fa-link"></i><?php echo htmlspecialchars(substr($url,0,40));?></a><?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>
<?php
// ── SAISIES LIÉES AU RAPPORT ──────────────────────────────────────────────
$SCAT_VIEW=['arme'=>['Arme','fas fa-gun','#ef4444'],'drogue'=>['Drogue','fas fa-pills','#f97316'],'vehicule'=>['Véhicule','fas fa-car','#3b82f6'],'argent'=>['Argent','fas fa-coins','#f59e0b'],'bijou'=>['Bijou','fas fa-gem','#a855f7'],'electronique'=>['Élec.','fas fa-laptop','#06b6d4'],'document'=>['Doc.','fas fa-file-lines','#64748b'],'autre'=>['Autre','fas fa-box','#6b7280']];
if(!empty($viewReportSaisies)):?>
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
        <div class="card-title" style="margin:0"><i class="fas fa-box-archive"></i> Saisies (<?php echo count($viewReportSaisies);?>)</div>
        <a href="saisies.php" class="btn btn-ghost btn-xs"><i class="fas fa-external-link-alt"></i> Gérer</a>
    </div>
    <div style="display:flex;flex-direction:column;gap:.4rem">
    <?php foreach($viewReportSaisies as $sz):[$cl,$ci,$cc]=$SCAT_VIEW[$sz['categorie']]??['Autre','fas fa-box','#6b7280'];?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.55rem .8rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px">
        <div style="width:30px;height:30px;border-radius:7px;background:<?php echo $cc;?>1a;border:1px solid <?php echo $cc;?>33;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="<?php echo $ci;?>" style="color:<?php echo $cc;?>;font-size:.78rem"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.84rem"><?php echo htmlspecialchars($sz['description']);?></div>
            <div style="font-size:.68rem;color:var(--txt-m);display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.1rem">
                <?php if($sz['quantite']):?><span><?php echo htmlspecialchars($sz['quantite']);?></span><?php endif;?>
                <?php if($sz['valeur_estimee']>0):?><span style="color:#fde68a">$<?php echo number_format($sz['valeur_estimee'],0);?></span><?php endif;?>
                <?php if($sz['lieu_saisie']):?><span><i class="fas fa-location-dot" style="opacity:.5;margin-right:.2rem"></i><?php echo htmlspecialchars($sz['lieu_saisie']);?></span><?php endif;?>
                <?php if($sz['citoyen']):?><span><i class="fas fa-user" style="opacity:.5;margin-right:.2rem"></i><?php echo htmlspecialchars($sz['citoyen']);?></span><?php endif;?>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <div class="mono" style="font-size:.68rem;color:var(--blue-l)"><?php echo htmlspecialchars($sz['numero_evidence']??'—');?></div>
            <span class="badge bg-<?php echo $sz['statut']==='saisie'?'red':($sz['statut']==='en_evidence'?'yellow':'gray');?>" style="font-size:.58rem;margin-top:.2rem"><?php echo ucfirst(str_replace('_',' ',$sz['statut']??'saisie'));?></span>
        </div>
    </div>
    <?php endforeach;?>
    </div>
    <div style="margin-top:.65rem;padding:.5rem .75rem;background:rgba(37,99,235,.04);border:1px solid rgba(37,99,235,.12);border-radius:8px;display:flex;gap:2rem;font-size:.75rem;flex-wrap:wrap">
        <span><span style="color:var(--txt-m)">Objets : </span><strong><?php echo count($viewReportSaisies);?></strong></span>
        <span><span style="color:var(--txt-m)">Valeur totale : </span><strong style="color:#fde68a">$<?php echo number_format(array_sum(array_column($viewReportSaisies,'valeur_estimee')),0);?></strong></span>
    </div>
</div>
<?php endif;?>

<?php // ── NEW / EDIT REPORT FORM ──────────────────────────────────────────
elseif(isset($_GET['new']) || ($editId>0 && $editReport)):
$isEdit = ($editId>0 && $editReport);
?>
<?php if($msgType==='error'):?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg);?></div><?php endif;?>
<?php if($isEdit):?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
    <a href="reports.php?id=<?php echo $editReport['id'];?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour au rapport</a>
    <div style="display:flex;align-items:center;gap:.65rem;padding:.45rem .9rem;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);border-radius:9px">
        <i class="fas fa-pen-to-square" style="color:#f59e0b;font-size:.85rem"></i>
        <span style="font-size:.82rem;font-weight:600;color:#fde68a">Modification du rapport <span class="mono">#<?php echo str_pad($editReport['id'],6,'0',STR_PAD_LEFT);?></span></span>
    </div>
</div>
<?php endif;?>

<!-- STEPS INDICATOR -->
<div class="steps" id="stepsBar">
    <div class="step act" id="stp1"><div class="s-num">1</div><span class="s-lbl">Type & Titre</span></div>
    <div class="s-line" id="sl1"></div>
    <div class="step" id="stp2"><div class="s-num">2</div><span class="s-lbl">Personnes</span></div>
    <div class="s-line" id="sl2"></div>
    <div class="step" id="stp3"><div class="s-num">3</div><span class="s-lbl">Code Pénal</span></div>
    <div class="s-line" id="sl3"></div>
    <div class="step" id="stp4"><div class="s-num">4</div><span class="s-lbl">Description</span></div>
    <div class="s-line" id="sl4"></div>
    <div class="step" id="stp5"><div class="s-num">5</div><span class="s-lbl">Saisies</span></div>
    <div class="s-line" id="sl5"></div>
    <div class="step" id="stp6"><div class="s-num">6</div><span class="s-lbl">Preuves</span></div>
</div>

<form method="POST" id="rForm" enctype="multipart/form-data">
<?php if($isEdit):?>
<input type="hidden" name="edit_report" value="1">
<input type="hidden" name="report_id" value="<?php echo $editReport['id'];?>">
<?php else:?>
<input type="hidden" name="submit_report" value="1">
<?php endif;?>
<input type="hidden" name="penal_codes" id="pcInput" value="[]">
<input type="hidden" name="description_rich" id="dRichInput">
<input type="hidden" name="saisies_data" id="saisiesInput" value="[]">
<input type="hidden" name="linked_saisies_ids" id="linkedSaisiesInput" value="[]">

<!-- 1. TYPE & INFOS -->
<div class="card" id="s1">
    <div class="card-title"><i class="fas fa-clipboard-list"></i> Type de Rapport *</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.75rem;margin-bottom:1.25rem">
        <?php $types=[['val'=>'RA','icon'=>'fas fa-handcuffs','color'=>'#ef4444','name'=>'RA','desc'=>"Rapport d'Arrestation"],['val'=>'Shooting Report','icon'=>'fas fa-gun','color'=>'#f97316','name'=>'Tirs','desc'=>'Rapport de Tirs'],['val'=>'Traffic Ticket','icon'=>'fas fa-car','color'=>'#f59e0b','name'=>'Circulation','desc'=>'Contravention'],['val'=>'Incident Report','icon'=>'fas fa-file-alt','color'=>'#3b82f6','name'=>'Incident','desc'=>"Rapport d'Incident"],['val'=>'Investigation','icon'=>'fas fa-magnifying-glass','color'=>'#8b5cf6','name'=>'Enquête','desc'=>'Investigation'],['val'=>'Use of Force','icon'=>'fas fa-fist-raised','color'=>'#dc2626','name'=>'Force','desc'=>"Usage de la Force"],['val'=>'Missing Person','icon'=>'fas fa-user-slash','color'=>'#6b7280','name'=>'Disparition','desc'=>'Personne Disparue']];
        foreach($types as $t):?>
        <label style="padding:1rem .85rem;background:rgba(0,0,0,.2);border:2px solid rgba(37,99,235,.08);border-radius:12px;text-align:center;cursor:pointer;transition:all .2s;position:relative" onclick="selType(this)">
            <input type="radio" name="type" value="<?php echo $t['val'];?>" required style="display:none" <?php echo ($isEdit&&($editReport['type']??'')===$t['val'])?'checked':''; ?>>
            <i class="<?php echo $t['icon'];?>" style="font-size:1.4rem;margin-bottom:.5rem;display:block;color:<?php echo $t['color'];?>"></i>
            <div style="font-size:.72rem;font-weight:700;margin-bottom:.2rem"><?php echo $t['name'];?></div>
            <div style="font-size:.62rem;color:var(--txt-m)"><?php echo $t['desc'];?></div>
        </label>
        <?php endforeach;?>
    </div>
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" class="form-input" required placeholder="Ex: Arrestation John Doe — Braquage Fleeca Bank" value="<?php echo $isEdit?htmlspecialchars($editReport['title']??''):'';?>"></div>
        <div class="form-group"><label class="form-label">Statut</label>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                <?php foreach([['draft'=>['Brouillon','#94a3b8']],['submitted'=>['Soumis','#3b82f6']],['approved'=>['Approuvé','#10b981']]] as $st):foreach($st as $v=>[$l,$c]):?>
                <label class="chip" style="background:<?php echo $c;?>18;border-color:<?php echo $c;?>33;color:<?php echo $c;?>" id="chip_<?php echo $v;?>"><input type="radio" name="status" value="<?php echo $v;?>" <?php echo $v==='submitted'?'checked':'';?> style="display:none"><?php echo $l;?></label>
                <?php endforeach;endforeach;?>
            </div>
        </div>
        <div class="form-group"><label class="form-label">Priorité</label>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                <?php foreach([['low'=>['Basse','#10b981']],['normal'=>['Normale','#3b82f6']],['high'=>['Haute','#f59e0b']],['critical'=>['Critique','#ef4444']]] as $pr):foreach($pr as $v=>[$l,$c]):?>
                <label class="chip" style="background:<?php echo $c;?>18;border-color:<?php echo $c;?>33;color:<?php echo $c;?>" id="chip_<?php echo $v;?>"><input type="radio" name="priority" value="<?php echo $v;?>" <?php echo $v==='normal'?'checked':'';?> style="display:none"><?php echo $l;?></label>
                <?php endforeach;endforeach;?>
            </div>
        </div>
        <div class="form-group"><label class="form-label"><i class="fas fa-location-dot" style="color:var(--blue-l)"></i> Lieu de l'incident</label><input type="text" name="location" class="form-input" placeholder="Ex: Fleeca Bank, Mission Row" value="<?php echo $isEdit?htmlspecialchars($editReport['location']??''):'';?>"></div>
        <div class="form-group"><label class="form-label"><i class="fas fa-clock" style="color:var(--blue-l)"></i> Date & heure de l'incident</label><input type="datetime-local" name="incident_at" class="form-input" value="<?php echo $isEdit&&!empty($editReport['incident_at'])?date('Y-m-d\TH:i',strtotime($editReport['incident_at'])):'';?>"></div>
    </div>
</div>

<!-- TAGS -->
<div class="card">
    <div class="card-title"><i class="fas fa-tags"></i> Tags <?php if(!empty($predefinedTags)):?><span style="font-size:.65rem;color:var(--txt-m);font-weight:400">(gérés depuis Panel Staff)</span><?php endif;?></div>
    <div style="display:flex;flex-wrap:wrap">
        <?php foreach($predefinedTags as $tg):
            $r=hexdec(substr($tg['color'],1,2)); $g2=hexdec(substr($tg['color'],3,2)); $b=hexdec(substr($tg['color'],5,2));
        ?><label class="tch" style="--tag-color:<?php echo htmlspecialchars($tg['color']);?>;--tag-rgb:<?php echo $r;?>,<?php echo $g2;?>,<?php echo $b;?>">
            <input type="checkbox" name="tags[]" value="<?php echo htmlspecialchars($tg['label']);?>" style="display:none" onchange="togTag(this)">
            <i class="fas fa-tag" style="font-size:.6rem"></i><?php echo htmlspecialchars($tg['label']);?>
        </label><?php endforeach;?>
    </div>
    <div style="font-size:.68rem;color:var(--txt-m);margin-top:.45rem" id="tcount">Aucun tag sélectionné</div>
</div>

<!-- 2. PERSONNES -->
<div class="card" id="s2">
    <div class="card-title"><i class="fas fa-users"></i> Personnes Impliquées</div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label"><i class="fas fa-shield-halved" style="color:var(--blue-l)"></i> Officiers impliqués</label>
            <div class="msb"><div class="ms-sw"><i class="fas fa-search"></i><input type="text" class="ms-si" placeholder="Filtrer..." oninput="fMS(this)"></div>
                <?php foreach($officers_list as $o):?><div class="msi"><input type="checkbox" name="officers[]" value="<?php echo $o['id'];?>" id="o<?php echo $o['id'];?>" onchange="cntMS(this,'offCnt')" <?php echo $o['id']==$uid?'checked':'';?>><label for="o<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username']);?></label><span class="ms-bge"><?php echo $o['faction'];echo $o['faction_grade']?' · '.htmlspecialchars($o['faction_grade']):'';?></span></div><?php endforeach;?>
            </div><div style="font-size:.7rem;color:var(--txt-m);margin-top:.3rem"><span id="offCnt">1</span> sélectionné(s)</div>
        </div>
        <div class="form-group"><label class="form-label"><i class="fas fa-user-secret" style="color:#ef4444"></i> Suspects</label>
            <div class="msb"><div class="ms-sw"><i class="fas fa-search"></i><input type="text" class="ms-si" placeholder="Filtrer..." oninput="fMS(this)"></div>
                <?php foreach($profiles_list as $p):?><div class="msi"><input type="checkbox" name="suspects[]" value="<?php echo $p['id'];?>" id="su<?php echo $p['id'];?>" onchange="cntMS(this,'susCnt')"><label for="su<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></label></div><?php endforeach;?>
            </div><div style="font-size:.7rem;color:var(--txt-m);margin-top:.3rem"><span id="susCnt">0</span> sélectionné(s)</div>
        </div>
        <div class="form-group"><label class="form-label"><i class="fas fa-user" style="color:#22c55e"></i> Civils impliqués</label>
            <div class="msb"><div class="ms-sw"><i class="fas fa-search"></i><input type="text" class="ms-si" placeholder="Filtrer..." oninput="fMS(this)"></div>
                <?php foreach($profiles_list as $p):?><div class="msi"><input type="checkbox" name="civilians[]" value="<?php echo $p['id'];?>" id="ci<?php echo $p['id'];?>" onchange="cntMS(this,'civCnt')"><label for="ci<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></label></div><?php endforeach;?>
            </div><div style="font-size:.7rem;color:var(--txt-m);margin-top:.3rem"><span id="civCnt">0</span> sélectionné(s)</div>
        </div>
        <div class="form-group"><label class="form-label"><i class="fas fa-eye" style="color:#f59e0b"></i> Témoins</label>
            <div class="msb"><div class="ms-sw"><i class="fas fa-search"></i><input type="text" class="ms-si" placeholder="Filtrer..." oninput="fMS(this)"></div>
                <?php foreach($profiles_list as $p):?><div class="msi"><input type="checkbox" name="witnesses[]" value="<?php echo $p['id'];?>" id="wi<?php echo $p['id'];?>" onchange="cntMS(this,'witCnt')"><label for="wi<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></label></div><?php endforeach;?>
            </div><div style="font-size:.7rem;color:var(--txt-m);margin-top:.3rem"><span id="witCnt">0</span> sélectionné(s)</div>
        </div>
    </div>
    <?php if(!empty($vehicles_list)):?>
    <div style="margin-top:1rem"><label class="form-label"><i class="fas fa-car" style="color:var(--blue-l)"></i> Véhicules impliqués</label>
        <div class="msb"><div class="ms-sw"><i class="fas fa-search"></i><input type="text" class="ms-si" placeholder="Filtrer par plaque..." oninput="fMS(this)"></div>
            <?php foreach($vehicles_list as $v):?><div class="msi"><input type="checkbox" name="vehicles[]" value="<?php echo $v['id'];?>" id="ve<?php echo $v['id'];?>" onchange="cntMS(this,'vehCnt')"><label for="ve<?php echo $v['id'];?>"><span style="font-family:'IBM Plex Mono',monospace;color:var(--blue-xl);font-size:.75rem"><?php echo htmlspecialchars($v['plate']);?></span> — <?php echo htmlspecialchars($v['model']);?></label><?php if(!empty($v['owner_name'])):?><span class="ms-bge"><?php echo htmlspecialchars($v['owner_name']);?></span><?php endif;?></div><?php endforeach;?>
        </div><div style="font-size:.7rem;color:var(--txt-m);margin-top:.3rem"><span id="vehCnt">0</span> véhicule(s)</div>
    </div>
    <?php endif;?>
</div>

<!-- 3. CODE PÉNAL -->
<?php if(!empty($penalCodes)):?>
<div class="card" id="s3">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem"><div class="card-title" style="margin:0"><i class="fas fa-gavel"></i> Code Pénal — Infractions</div><span id="pcCnt" style="font-family:'IBM Plex Mono',monospace;font-size:.67rem;color:var(--txt-m)">0 sélectionné</span></div>
    <input type="text" id="pcSrch" class="form-input" placeholder="Rechercher par code ou intitulé..." style="margin-bottom:.65rem" oninput="fPC()">
    <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.65rem">
        <button type="button" class="btn btn-xs btn-secondary pcfb act" onclick="fPCCat('all',this)">Tout</button>
        <?php foreach($catNames as $k=>$v):?><button type="button" class="btn btn-xs btn-ghost pcfb" onclick="fPCCat('<?php echo $k;?>',this)"><i class="fas <?php echo $catIcons[$k];?>"></i> <?php echo $v;?></button><?php endforeach;?>
    </div>
    <div class="pcgrid" id="pcGrid">
        <?php
        $__gLvls=['min1'=>'Min 1','min2'=>'Min 2','min3'=>'Min 3','nominal'=>'Nominal','max1'=>'Max 1','max2'=>'Max 2','max3'=>'Max 3'];
        foreach($penalCodes as $pc):
            $cat=$pc['category'];
            $__gF=json_decode($pc['gravity_fines']??'{}',true)?:[];
            $__gU=json_decode($pc['gravity_up']??'{}',true)?:[];
            $__hasGrav=false; foreach($__gLvls as $lvl=>$lbl){if(($__gF[$lvl]??0)>0||($__gU[$lvl]??0)>0){$__hasGrav=true;break;}}
            // Build gravity data-attr (JSON)
            $__gDataF=htmlspecialchars(json_encode($__gF),ENT_QUOTES);
            $__gDataU=htmlspecialchars(json_encode($__gU),ENT_QUOTES);
        ?>
        <div class="pcc" data-id="<?php echo $pc['id'];?>" data-cat="<?php echo $cat;?>"
             data-q="<?php echo strtolower($pc['code'].' '.($pc['nom_code']??'').' '.$pc['title']);?>"
             data-fm="<?php echo $pc['max_fine']??0;?>" data-jm="<?php echo $pc['max_jail_months']??0;?>"
             data-gf="<?php echo $__gDataF;?>" data-gu="<?php echo $__gDataU;?>"
             data-hasgrav="<?php echo $__hasGrav?'1':'0';?>"
             onclick="togPC(this)">
            <div class="pccode"><?php echo htmlspecialchars($pc['code']);?></div>
            <div class="pccat"><?php echo $catNames[$cat]??$cat;?></div>
            <div class="pctitle"><?php echo htmlspecialchars($pc['title']);?></div>
            <?php if(!empty($pc['nom_code'])):?><div style="font-size:.67rem;color:var(--txt-m);margin-top:.1rem;font-style:italic"><?php echo htmlspecialchars($pc['nom_code']);?></div><?php endif;?>
            <div class="pcpen">
                <div>$<?php echo number_format($pc['max_fine']??0,0,'.',',');?></div>
                <?php if($pc['max_jail_months']??0):?><div><?php echo number_format($pc['max_jail_months']??0,0);?>h UP</div><?php endif;?>
            </div>
            <!-- Gravity level selector (visible once selected) -->
            <?php if($__hasGrav):?>
            <div class="pc-grav-sel" id="pgsel_<?php echo $pc['id'];?>" onclick="event.stopPropagation()" style="display:none;margin-top:.6rem;padding-top:.55rem;border-top:1px solid rgba(37,99,235,.12);">
                <div style="font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.35rem"><i class="fas fa-scale-balanced" style="font-size:.5rem;margin-right:.25rem"></i>Niveau de gravité</div>
                <div class="pc-grav-btns">
                <?php foreach($__gLvls as $lvl=>$lbl):
                    $f=$__gF[$lvl]??0; $u=$__gU[$lvl]??0;
                    if($f==0&&$u==0) continue;
                    $lc=match($lvl){'min1'=>'#22c55e','min2'=>'#84cc16','min3'=>'#eab308','nominal'=>'#3b82f6','max1'=>'#f97316','max2'=>'#ef4444','max3'=>'#7f1d1d',default=>'#64748b'};
                ?>
                <button type="button" class="pc-grav-btn"
                        data-lvl="<?php echo $lvl;?>" data-f="<?php echo $f;?>" data-u="<?php echo $u;?>"
                        data-col="<?php echo $lc;?>"
                        onclick="selectGravLvl(<?php echo $pc['id'];?>,this)"
                        style="--lc:<?php echo $lc;?>"
                        title="Amende: $<?php echo number_format($f,0,'.',',');?> | UP: <?php echo $u;?>h">
                    <span><?php echo $lbl;?></span>
                    <span class="pc-grav-btn-info">$<?php echo number_format($f,0,'.',',');?><?php if($u>0):?> · <?php echo number_format($u,1);?>h<?php endif;?></span>
                </button>
                <?php endforeach;?>
                </div>
            </div>
            <?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <div id="pcSelTags" style="margin-top:.65rem;display:flex;flex-wrap:wrap;gap:.35rem"></div>
    <div id="pcTots" style="display:none;margin-top:.65rem;background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.2);border-radius:8px;padding:.75rem 1rem">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;font-size:.8rem">
            <div><div style="font-size:.63rem;color:var(--txt-m)">Infractions</div><div class="mono" style="font-weight:700" id="pcTotC">0</div></div>
            <div><div style="font-size:.63rem;color:var(--txt-m)">Amende max</div><div class="mono" style="font-weight:700;color:#fde68a">$<span id="pcTotF">0</span></div></div>
            <div><div style="font-size:.63rem;color:var(--txt-m)">UP max (heures)</div><div class="mono" style="font-weight:700;color:#fca5a5"><span id="pcTotJ">0</span>h</div></div>
        </div>
    </div>
</div>
<?php endif;?>

<!-- 4. DESCRIPTION -->
<div class="card" id="s4">
    <div class="card-title"><i class="fas fa-pen-fancy"></i> Description du rapport *</div>
    <div class="ew">
        <div class="etb">
            <select class="esel" onchange="fmtB(this.value);this.selectedIndex=0"><option value="">Paragraphe</option><option value="h2">Titre</option><option value="h3">Sous-titre</option></select>
            <button type="button" class="ebtn" onclick="ec('bold')" title="Gras"><i class="fas fa-bold"></i></button>
            <button type="button" class="ebtn" onclick="ec('italic')" title="Italique"><i class="fas fa-italic"></i></button>
            <button type="button" class="ebtn" onclick="ec('underline')" title="Souligné"><i class="fas fa-underline"></i></button>
            <button type="button" class="ebtn" onclick="ec('strikeThrough')"><i class="fas fa-strikethrough"></i></button>
            <span style="width:1px;background:rgba(255,255,255,.06);margin:4px 2px"></span>
            <button type="button" class="ebtn" onclick="ec('insertUnorderedList')"><i class="fas fa-list-ul"></i></button>
            <button type="button" class="ebtn" onclick="ec('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
            <span style="width:1px;background:rgba(255,255,255,.06);margin:4px 2px"></span>
            <button type="button" class="ebtn" onclick="ec('justifyLeft')"><i class="fas fa-align-left"></i></button>
            <button type="button" class="ebtn" onclick="ec('justifyCenter')"><i class="fas fa-align-center"></i></button>
            <button type="button" class="ebtn" onclick="ec('justifyRight')"><i class="fas fa-align-right"></i></button>
            <span style="width:1px;background:rgba(255,255,255,.06);margin:4px 2px"></span>
            <button type="button" class="ebtn" onclick="ec('insertHTML','<hr style=&quot;border-color:rgba(37,99,235,.3)&quot;>')"><i class="fas fa-minus"></i></button>
            <button type="button" class="ebtn" onclick="ec('removeFormat')"><i class="fas fa-eraser"></i></button>
        </div>
        <div id="rEditor" class="re" contenteditable="true" data-ph="Décrivez les faits, le déroulement de l'intervention, les observations, les actions entreprises..." oninput="updCC()"></div>
    </div>
    <div style="font-size:.68rem;color:var(--txt-m);text-align:right;margin-top:.35rem"><span id="charCnt">0</span> caractères</div>
</div>

<!-- 5. SAISIES -->
<div class="card" id="s5">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
        <div class="card-title" style="margin:0"><i class="fas fa-box-archive"></i> Objets saisis</div>
        <div style="display:flex;align-items:center;gap:.65rem">
            <span id="szCnt" style="font-size:.68rem;color:var(--txt-m)">Aucun objet</span>
        </div>
    </div>

    <!-- ONGLETS -->
    <div style="display:flex;gap:0;margin-bottom:1rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.25rem;width:fit-content">
        <button type="button" id="sz-tab-new" class="sz-tab active" onclick="switchSzTab('new')"
                style="padding:.38rem .85rem;border-radius:7px;font-size:.75rem;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:rgba(37,99,235,.15);color:var(--blue-xl)">
            <i class="fas fa-plus" style="margin-right:.3rem"></i>Créer nouvelle
        </button>
        <button type="button" id="sz-tab-link" class="sz-tab" onclick="switchSzTab('link')"
                style="padding:.38rem .85rem;border-radius:7px;font-size:.75rem;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:transparent;color:var(--txt-m)">
            <i class="fas fa-link" style="margin-right:.3rem"></i>Lier existante
            <?php if(!empty($existingSaisies)):?>
            <span style="background:rgba(37,99,235,.2);color:var(--blue-xl);font-size:.62rem;padding:1px 6px;border-radius:10px;margin-left:.3rem"><?php echo count($existingSaisies);?></span>
            <?php endif;?>
        </button>
    </div>

    <!-- ── PANNEAU : CRÉER NOUVELLES SAISIES ── -->
    <div id="sz-panel-new">
        <!-- En-tête colonnes -->
        <div id="szHead" style="display:none;grid-template-columns:130px 1fr 90px 90px 130px 36px;gap:.5rem;padding:.3rem .4rem;margin-bottom:.3rem">
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)">Catégorie</div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)">Description *</div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)">Qté</div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)">Valeur ($)</div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txt-m)">Lieu saisie</div>
            <div></div>
        </div>
        <!-- Lignes de saisies (injectées par JS) -->
        <div id="szList" style="display:flex;flex-direction:column;gap:.4rem"></div>
        <!-- État vide -->
        <div id="szEmpty" style="text-align:center;padding:1.85rem;border:1.5px dashed rgba(37,99,235,.2);border-radius:10px;color:var(--txt-m);cursor:pointer" onclick="addSaisieRow()">
            <i class="fas fa-box-open" style="font-size:1.5rem;opacity:.35;display:block;margin-bottom:.5rem"></i>
            <div style="font-size:.8rem">Cliquer pour déclarer un objet saisi</div>
            <div style="font-size:.7rem;margin-top:.25rem;opacity:.6">Armes, drogues, espèces, véhicules, bijoux, électronique…</div>
        </div>
        <!-- Bouton ajouter -->
        <div id="szAddBtn" style="display:none;margin-top:.55rem">
            <button type="button" class="btn btn-secondary btn-sm" onclick="addSaisieRow()">
                <i class="fas fa-plus"></i> Ajouter un autre objet
            </button>
        </div>
        <!-- Récap totaux -->
        <div id="szTotals" style="display:none;margin-top:.75rem;background:rgba(37,99,235,.05);border:1px solid rgba(37,99,235,.15);border-radius:9px;padding:.65rem 1rem">
            <div style="display:flex;gap:2rem;flex-wrap:wrap;font-size:.78rem">
                <div><span style="color:var(--txt-m)">Objets déclarés : </span><strong id="szTotCount" style="color:var(--txt)">0</strong></div>
                <div><span style="color:var(--txt-m)">Valeur totale estimée : </span><strong id="szTotVal" style="color:#fde68a">$0</strong></div>
            </div>
        </div>
    </div>

    <!-- ── PANNEAU : LIER SAISIES EXISTANTES ── -->
    <div id="sz-panel-link" style="display:none">
        <?php if(empty($existingSaisies)):?>
        <div style="text-align:center;padding:2rem;border:1.5px dashed rgba(37,99,235,.15);border-radius:10px;color:var(--txt-m)">
            <i class="fas fa-inbox" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
            <div style="font-size:.8rem">Aucune saisie disponible à lier</div>
            <div style="font-size:.72rem;margin-top:.25rem;opacity:.7">
                Toutes les saisies sont déjà liées à un rapport, ou aucune saisie n'a encore été créée.
            </div>
            <a href="saisies.php" class="btn btn-ghost btn-sm" style="margin-top:.75rem" target="_blank">
                <i class="fas fa-external-link-alt"></i> Créer une saisie
            </a>
        </div>
        <?php else:?>
        <!-- Filtre rapide -->
        <div style="position:relative;margin-bottom:.6rem">
            <i class="fas fa-search" style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.68rem;color:var(--txt-m);pointer-events:none"></i>
            <input type="text" id="szLinkSearch" placeholder="Filtrer par description, n° évidence, citoyen…"
                   oninput="filterSzLink(this.value)"
                   style="width:100%;padding:.5rem .7rem .5rem 2.1rem;background:rgba(0,0,0,.2);border:1px solid var(--bdr);border-radius:8px;color:var(--txt);font-size:.78rem;font-family:inherit">
        </div>
        <!-- Compteur sélection -->
        <div style="font-size:.7rem;color:var(--txt-m);margin-bottom:.5rem">
            <span id="szLinkCount" style="font-weight:700;color:var(--blue-xl)">0</span> saisie(s) sélectionnée(s)
        </div>
        <!-- Liste des saisies existantes -->
        <div id="szLinkList" style="display:flex;flex-direction:column;gap:.35rem;max-height:320px;overflow-y:auto;padding-right:.2rem">
            <?php
            $SCAT_ICO=['arme'=>['fas fa-gun','#ef4444'],'drogue'=>['fas fa-pills','#f97316'],'vehicule'=>['fas fa-car','#3b82f6'],'argent'=>['fas fa-coins','#f59e0b'],'bijou'=>['fas fa-gem','#a855f7'],'electronique'=>['fas fa-laptop','#06b6d4'],'document'=>['fas fa-file-lines','#64748b'],'autre'=>['fas fa-box','#6b7280']];
            foreach($existingSaisies as $sz):[$sico,$scol]=$SCAT_ICO[$sz['categorie']]??['fas fa-box','#6b7280'];
            ?>
            <label class="sz-link-item" data-q="<?php echo strtolower(htmlspecialchars($sz['description'].' '.($sz['numero_evidence']??'').' '.($sz['citoyen']??'')));?>"
                   style="display:flex;align-items:center;gap:.7rem;padding:.55rem .75rem;background:var(--bg-ov);border:1.5px solid var(--bdr);border-radius:9px;cursor:pointer;transition:all .15s">
                <input type="checkbox" class="sz-link-cb" value="<?php echo $sz['id'];?>"
                       style="width:15px;height:15px;accent-color:var(--blue);flex-shrink:0;cursor:pointer"
                       onchange="updSzLink()">
                <div style="width:28px;height:28px;border-radius:6px;background:<?php echo $scol;?>1a;border:1px solid <?php echo $scol;?>33;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="<?php echo $sico;?>" style="color:<?php echo $scol;?>;font-size:.72rem"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($sz['description']);?></div>
                    <div style="font-size:.68rem;color:var(--txt-m);display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.1rem">
                        <?php if($sz['quantite']):?><span><?php echo htmlspecialchars($sz['quantite']);?></span><?php endif;?>
                        <?php if($sz['valeur_estimee']>0):?><span style="color:#fde68a">$<?php echo number_format($sz['valeur_estimee'],0);?></span><?php endif;?>
                        <?php if($sz['lieu_saisie']):?><span><?php echo htmlspecialchars($sz['lieu_saisie']);?></span><?php endif;?>
                        <?php if($sz['citoyen']):?><span style="color:var(--blue-xl)"><?php echo htmlspecialchars($sz['citoyen']);?></span><?php endif;?>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <?php if($sz['numero_evidence']):?>
                    <div class="mono" style="font-size:.65rem;color:var(--blue-l)"><?php echo htmlspecialchars($sz['numero_evidence']);?></div>
                    <?php endif;?>
                    <div style="font-size:.65rem;color:var(--txt-m);margin-top:.15rem"><?php echo date('d/m/Y',strtotime($sz['created_at']));?></div>
                </div>
            </label>
            <?php endforeach;?>
        </div>
        <!-- Sélection rapide tout / rien -->
        <div style="display:flex;gap:.4rem;margin-top:.55rem">
            <button type="button" class="btn btn-ghost btn-xs" onclick="selectAllSz(true)"><i class="fas fa-check-double"></i> Tout sélectionner</button>
            <button type="button" class="btn btn-ghost btn-xs" onclick="selectAllSz(false)"><i class="fas fa-times"></i> Tout désélectionner</button>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- 6. PREUVES -->
<div class="card" id="s6">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem"><div class="card-title" style="margin:0"><i class="fas fa-camera"></i> Preuves & Pièces jointes</div><span id="evCnt" style="font-size:.65rem;color:var(--txt-m)">0 fichier(s)</span></div>
    <textarea name="evidence_urls" class="form-textarea" rows="3" placeholder="Une URL par ligne&#10;https://i.imgur.com/exemple.jpg" oninput="updEC()"><?php if($isEdit&&!empty($editReport['evidence'])){$__evArr=json_decode($editReport['evidence'],true)?:[];echo htmlspecialchars(implode("
",$__evArr));}?></textarea>
</div>

<!-- NOTES INTERNES -->
<?php if($_canAdmin):?>
<div class="card">
    <div class="card-title"><i class="fas fa-lock"></i> Notes Internes <span style="font-size:.65rem;font-weight:400;color:var(--txt-m)">(superviseurs uniquement)</span></div>
    <textarea name="internal_notes" class="form-textarea" rows="3" placeholder="Notes confidentielles, remarques de supervision..."><?php echo $isEdit ? htmlspecialchars($editReport['internal_notes']??'') : ''; ?></textarea>
</div>
<?php endif;?>

<!-- SUBMIT BAR -->
<div class="subbar">
    <div style="font-size:.82rem;color:var(--txt-s)"><i class="fas fa-user-shield" style="color:var(--blue-l);margin-right:.4rem"></i><strong style="color:var(--txt)"><?php echo htmlspecialchars(mdt_session_display_name());?></strong> — <?php echo $_uf;?></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="reports.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Annuler</a>
        <button type="submit" class="btn btn-primary" onclick="prepSub()">
            <?php if($isEdit):?><i class="fas fa-floppy-disk"></i> Enregistrer les modifications
            <?php else:?><i class="fas fa-paper-plane"></i> Soumettre le rapport
            <?php endif;?>
        </button>
    </div>
</div>
</form>

<?php // ── LIST ─────────────────────────────────────────────────────────────
else:?>

<?php if($msg&&$msgType!=='success'):?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="rp-layout">
  <div>
    <div class="filter-box">
        <div class="filter-title"><i class="fas fa-tag"></i> Type de rapport</div>
        <a href="?type=all&date=<?php echo $fDate;?>" class="filter-btn <?php echo $fType==='all'?'active':'';?>"><i class="fas fa-list"></i> Tous</a>
        <?php foreach($RTYPES as $k=>[$l,$b]):?><a href="?type=<?php echo urlencode($k);?>&date=<?php echo $fDate;?>" class="filter-btn <?php echo $fType===$k?'active':'';?>"><i class="fas fa-file-alt"></i> <?php echo $l;?></a><?php endforeach;?>
    </div>
    <div class="filter-box">
        <div class="filter-title"><i class="fas fa-calendar"></i> Période</div>
        <?php foreach(['all'=>'Tout','today'=>"Aujourd'hui",'week'=>'7 jours','month'=>'30 jours'] as $k=>$l):?><a href="?type=<?php echo $fType;?>&date=<?php echo $k;?>" class="filter-btn <?php echo $fDate===$k?'active':'';?>"><?php echo $l;?></a><?php endforeach;?>
    </div>
    <a href="reports.php?new=1" class="btn btn-primary" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Nouveau rapport</a>
  </div>
  <div>
    <form method="GET" style="display:flex;gap:.5rem;margin-bottom:1rem">
        <input type="hidden" name="type" value="<?php echo $fType;?>"><input type="hidden" name="date" value="<?php echo $fDate;?>">
        <input type="text" name="q" class="form-input" placeholder="Titre, contenu..." value="<?php echo htmlspecialchars($search);?>" style="flex:1">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search):?><a href="reports.php?type=<?php echo $fType;?>&date=<?php echo $fDate;?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <?php if(empty($reports??[])):?><div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)"><i class="fas fa-file-alt" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun rapport</div>
    <?php else:foreach($reports as $r):
        $rtags=json_decode($r['tags']??'[]',true)?:[];
        $evid=json_decode($r['evidence']??'[]',true)?:[];
        [$rtl,$rtb]=$RTYPES[$r['type']??'']??[$r['type']??'—','bg-gray'];
    ?>
    <div class="rp-card" onclick="location.href='reports.php?id=<?php echo $r['id'];?>'" style="position:relative">
        <?php if($r['created_by']==$uid||$_canAdmin):?>
        <a href="reports.php?edit=<?php echo $r['id'];?>" onclick="event.stopPropagation()"
           style="position:absolute;top:.55rem;right:.55rem;width:28px;height:28px;border-radius:7px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);color:var(--blue-xl);display:flex;align-items:center;justify-content:center;font-size:.7rem;text-decoration:none;z-index:2;transition:all .17s"
           onmouseover="this.style.background='rgba(37,99,235,.2)'"
           onmouseout="this.style.background='rgba(37,99,235,.08)'"
           title="Modifier">
            <i class="fas fa-pen"></i>
        </a>
        <?php endif;?>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.45rem">
            <div style="flex:1;min-width:0">
                <span class="badge <?php echo $rtb;?>" style="margin-bottom:.3rem;font-size:.62rem"><?php echo $rtl;?></span>
                <div style="font-weight:700;font-size:.875rem"><?php echo htmlspecialchars($r['title']??'—');?></div>
                <div style="font-size:.72rem;color:var(--txt-s);margin-top:.1rem">Par <?php echo htmlspecialchars($r['auteur']??'—');?> · <?php echo date('d/m/Y H:i',strtotime($r['created_at']));?></div>
            </div>
            <?php $sc=$RSTATUS[$r['status']??'draft']??['—','bg-gray'];?><span class="badge <?php echo $sc[1];?>" style="font-size:.62rem;flex-shrink:0"><?php echo $sc[0];?></span>
        </div>
        <?php if(!empty($r['description_rich'])):?><div style="font-size:.78rem;color:var(--txt-s);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin-bottom:.4rem"><?php echo htmlspecialchars(mb_substr(strip_tags($r['description_rich']),0,150));?></div><?php endif;?>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap;align-items:center">
            <?php foreach(array_slice($rtags,0,5) as $t):
                $tc='#94a3b8'; foreach($predefinedTags as $pt){if($pt['label']===$t){$tc=$pt['color'];break;}}
            ?><span style="padding:.18rem .52rem;border-radius:4px;font-size:.63rem;font-weight:700;border:1px solid <?php echo $tc;?>33;background:<?php echo $tc;?>1a;color:<?php echo $tc;?>"><?php echo htmlspecialchars($t);?></span><?php endforeach;?>
            <?php if(!empty($evid)):?><span class="badge bg-blue" style="font-size:.62rem"><i class="fas fa-camera"></i><?php echo count($evid);?></span><?php endif;?>
        </div>
    </div>
    <?php endforeach;endif;?>
  </div>
</div>

<?php endif;?>
<script>
// Type selection
function selType(el){document.querySelectorAll('label[onclick="selType(this)"]').forEach(l=>{l.style.borderColor='rgba(37,99,235,.08)';l.style.background='rgba(0,0,0,.2)'});el.style.borderColor='var(--blue)';el.style.background='rgba(37,99,235,.1)';}
// Chips
function selChip(n,v){document.querySelectorAll('input[name="'+n+'"]').forEach(i=>{const l=i.closest('label');l.style.outline='';l.style.boxShadow=''});const i=document.querySelector('input[name="'+n+'"][value="'+v+'"]');if(i){const l=i.closest('label');l.style.outline='2px solid currentColor';l.style.boxShadow='0 0 10px currentColor';i.checked=true;}}
document.addEventListener('DOMContentLoaded',()=>{selChip('status','submitted');selChip('priority','normal');});
document.querySelectorAll('.chip').forEach(c=>{c.addEventListener('click',()=>{const i=c.querySelector('input');if(i)i.checked=true;const n=i.name;document.querySelectorAll('input[name="'+n+'"]').forEach(x=>{const l=x.closest('label');l.style.outline='';l.style.boxShadow=''});c.style.outline='2px solid currentColor';c.style.boxShadow='0 0 10px currentColor';});});
// Tags — on écoute l'événement `change` de la checkbox (déclenché une seule
// fois par changement d'état) plutôt que l'onclick du label, qui était tiré
// deux fois à cause du bubbling natif du label→input→label.
function togTag(inp){
    const lbl = inp.closest('.tch');
    if (lbl) lbl.classList.toggle('sel', inp.checked);
    const n = document.querySelectorAll('.tch input[name="tags[]"]:checked').length;
    const c = document.getElementById('tcount');
    if (c) c.textContent = n === 0 ? 'Aucun tag sélectionné' : (n + ' tag' + (n>1?'s':'') + ' sélectionné' + (n>1?'s':''));
}
// Multiselect
function fMS(inp){const q=inp.value.toLowerCase();inp.closest('.msb').querySelectorAll('.msi').forEach(it=>it.style.display=it.textContent.toLowerCase().includes(q)?'':'none')}
function cntMS(cb,cntId){const cnt=cb.closest('.msb').querySelectorAll('input:checked').length;const el=document.getElementById(cntId);if(el)el.textContent=cnt;}
// Editor
function ec(cmd,val=null){document.execCommand(cmd,false,val);document.getElementById('rEditor')?.focus()}
function fmtB(tag){if(tag)document.execCommand('formatBlock',false,'<'+tag+'>');document.getElementById('rEditor')?.focus()}
function updCC(){const n=document.getElementById('rEditor')?.innerText.length||0;document.getElementById('charCnt').textContent=n.toLocaleString('fr-FR')}
function updEC(){const ta=document.querySelector('textarea[name=evidence_urls]');const uc=ta?.value.split('\n').filter(l=>l.trim()).length||0;const el=document.getElementById('evCnt');if(el)el.textContent=uc+' fichier(s)'}
// ── Code pénal — avec niveaux de gravité ─────────────────────────────────
const sPC = new Map(); // id → { level, fine, up }
let pcCat = 'all';

function fPC(){
    const q=document.getElementById('pcSrch').value.toLowerCase().trim();
    document.querySelectorAll('.pcc').forEach(c=>{
        const mCat=pcCat==='all'||c.dataset.cat===pcCat;
        const mQ=!q||c.dataset.q.includes(q);
        c.style.display=(mCat&&mQ)?'':'none';
    });
}
function fPCCat(cat,btn){pcCat=cat;document.querySelectorAll('.pcfb').forEach(b=>b.classList.remove('act'));btn.classList.add('act');fPC();}

function togPC(card) {
    const id = parseInt(card.dataset.id);
    if (sPC.has(id)) {
        // Désélectionner
        sPC.delete(id);
        card.classList.remove('sel');
        const sel = document.getElementById('pgsel_'+id);
        if (sel) sel.style.display = 'none';
        // Reset gravity buttons
        card.querySelectorAll('.pc-grav-btn').forEach(b=>b.classList.remove('active'));
        const res = card.querySelector('.pc-grav-result');
        if (res) res.remove();
    } else {
        // Sélectionner
        sPC.set(id, {level: null, fine: 0, up: 0});
        card.classList.add('sel');
        // Afficher le sélecteur de niveau si disponible
        const sel = document.getElementById('pgsel_'+id);
        if (sel) {
            sel.style.display = 'block';
            // Si pas de gravity définie → utiliser valeur max par défaut
        } else {
            // Pas de gravity → utiliser data-fm / data-jm
            sPC.set(id, {level: 'nominal', fine: parseFloat(card.dataset.fm)||0, up: parseFloat(card.dataset.jm)||0});
        }
    }
    updPCT();
}

function selectGravLvl(id, btn) {
    const card = document.querySelector('.pcc[data-id="'+id+'"]');
    if (!card) return;
    // Deselect others
    card.querySelectorAll('.pc-grav-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const f = parseFloat(btn.dataset.f) || 0;
    const u = parseFloat(btn.dataset.u) || 0;
    const lvl = btn.dataset.lvl;
    sPC.set(id, {level: lvl, fine: f, up: u});
    // Update result preview inside card
    let res = card.querySelector('.pc-grav-result');
    if (!res) {
        res = document.createElement('div');
        res.className = 'pc-grav-result';
        card.querySelector('.pc-grav-sel').appendChild(res);
    }
    res.innerHTML =
        '<i class="fas fa-check-circle" style="color:'+btn.dataset.col+';font-size:.7rem"></i>'+
        '<span style="color:#fde68a">$'+f.toLocaleString('fr-FR')+'</span>'+
        (u>0?'<span style="color:#fca5a5">'+u+'h UP</span>':'');
    updPCT();
}

function updPCT() {
    const cnt  = document.getElementById('pcCnt');
    const tags = document.getElementById('pcSelTags');
    const tots = document.getElementById('pcTots');
    if (!cnt) return;
    cnt.textContent = sPC.size+' sélectionné'+(sPC.size>1?'s':'');
    tags.innerHTML = '';
    if (sPC.size === 0) {
        tots.style.display = 'none';
        document.getElementById('pcInput').value = '[]';
        return;
    }
    tots.style.display = 'block';
    let tf = 0, tu = 0;
    const pcArr = [];
    sPC.forEach((data, id) => {
        const card = document.querySelector('.pcc[data-id="'+id+'"]');
        if (!card) return;
        tf += data.fine;
        tu += data.up;
        pcArr.push({id, level: data.level || 'nominal', fine: data.fine, up: data.up});
        // Tag badge
        const t = document.createElement('span');
        t.className = 'badge bg-blue';
        t.style.fontSize = '.63rem';
        t.style.cursor   = 'pointer';
        const code = card.querySelector('.pccode')?.textContent || '#'+id;
        const lvlTxt = data.level ? ' ['+data.level+']' : '';
        t.innerHTML = code+lvlTxt+' <i class="fas fa-times"></i>';
        t.onclick = ()=>{ sPC.delete(id); card.classList.remove('sel'); const sel=document.getElementById('pgsel_'+id); if(sel)sel.style.display='none'; card.querySelectorAll('.pc-grav-btn').forEach(b=>b.classList.remove('active')); updPCT(); };
        tags.appendChild(t);
    });
    document.getElementById('pcTotC').textContent = sPC.size;
    document.getElementById('pcTotF').textContent = tf.toLocaleString('fr-FR');
    document.getElementById('pcTotJ').textContent = tu.toLocaleString('fr-FR');
    document.getElementById('pcInput').value = JSON.stringify(pcArr);
}

// ── SAISIES DYNAMIQUES ──────────────────────────────────────────────────
const SAISIES_CATS = {
    arme:        {label:'Arme',          icon:'fa-gun',           color:'#ef4444'},
    drogue:      {label:'Drogue',        icon:'fa-pills',         color:'#f97316'},
    vehicule:    {label:'Véhicule',      icon:'fa-car',           color:'#3b82f6'},
    argent:      {label:'Argent',        icon:'fa-coins',         color:'#f59e0b'},
    bijou:       {label:'Bijou',         icon:'fa-gem',           color:'#a855f7'},
    electronique:{label:'Électronique',  icon:'fa-laptop',        color:'#06b6d4'},
    document:    {label:'Document',      icon:'fa-file-lines',    color:'#64748b'},
    autre:       {label:'Autre',         icon:'fa-box',           color:'#6b7280'},
};

// ── ONGLETS CRÉER / LIER ──────────────────────────────────────────────────
let currentSzTab = 'new';
function switchSzTab(tab) {
    currentSzTab = tab;
    document.getElementById('sz-panel-new').style.display  = tab === 'new'  ? 'block' : 'none';
    document.getElementById('sz-panel-link').style.display = tab === 'link' ? 'block' : 'none';
    document.querySelectorAll('.sz-tab').forEach(b => {
        const isActive = b.id === 'sz-tab-' + tab;
        b.style.background = isActive ? 'rgba(37,99,235,.15)' : 'transparent';
        b.style.color      = isActive ? 'var(--blue-xl)'      : 'var(--txt-m)';
    });
    updSzTotals();
}

// ── LIER SAISIES EXISTANTES ───────────────────────────────────────────────
function filterSzLink(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.sz-link-item').forEach(item => {
        item.style.display = !q || item.dataset.q.includes(q) ? '' : 'none';
    });
}

function updSzLink() {
    const checked = Array.from(document.querySelectorAll('.sz-link-cb:checked'));
    const cnt = document.getElementById('szLinkCount');
    if (cnt) cnt.textContent = checked.length;

    // Styler les labels cochés
    document.querySelectorAll('.sz-link-item').forEach(item => {
        const cb = item.querySelector('.sz-link-cb');
        item.style.borderColor     = cb?.checked ? 'rgba(37,99,235,.55)' : 'var(--bdr)';
        item.style.background      = cb?.checked ? 'rgba(37,99,235,.08)' : 'var(--bg-ov)';
        item.style.boxShadow       = cb?.checked ? '0 0 0 1px rgba(37,99,235,.2)' : '';
    });

    // Sérialiser les IDs
    const ids = checked.map(cb => parseInt(cb.value));
    const inp = document.getElementById('linkedSaisiesInput');
    if (inp) inp.value = JSON.stringify(ids);

    updSzTotals();
}

function selectAllSz(val) {
    document.querySelectorAll('.sz-link-cb').forEach(cb => { cb.checked = val; });
    updSzLink();
}

// ── CRÉER NOUVELLES SAISIES ───────────────────────────────────────────────
function getSuspectOptions() {
    const opts = ['<option value="">— Sans citoyen —</option>'];
    document.querySelectorAll('#rForm .msb input[name="suspects[]"]:checked').forEach(cb => {
        const lbl = cb.closest('.msi')?.querySelector('label');
        if (lbl) opts.push(`<option value="${cb.value}">${lbl.textContent.trim()}</option>`);
    });
    return opts.join('');
}

let szCounter = 0;

function addSaisieRow(preset = {}) {
    szCounter++;
    const id = 'sz_' + szCounter;
    const list  = document.getElementById('szList');
    const empty = document.getElementById('szEmpty');
    const head  = document.getElementById('szHead');
    const addB  = document.getElementById('szAddBtn');
    if (empty) empty.style.display = 'none';
    if (head)  head.style.display  = 'grid';
    if (addB)  addB.style.display  = 'block';

    const catOpts = Object.entries(SAISIES_CATS).map(([k,v]) =>
        `<option value="${k}" ${k===(preset.categorie||'arme')?'selected':''}>${v.label}</option>`
    ).join('');
    const suspOpts = getSuspectOptions();

    const row = document.createElement('div');
    row.id = id;
    row.style.cssText = 'display:grid;grid-template-columns:130px 1fr 90px 90px 130px 36px;gap:.5rem;align-items:start;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.55rem .65rem;animation:szIn .2s ease-out';
    row.innerHTML = `
        <div><select class="form-select sz-cat" style="font-size:.76rem;padding:.38rem .5rem" onchange="updSzCatIcon(this)">${catOpts}</select></div>
        <div>
            <input type="text" class="form-input sz-desc" style="font-size:.8rem;padding:.38rem .6rem" placeholder="Description *" value="${preset.description||''}" required>
            <select class="form-select sz-cit" style="font-size:.7rem;padding:.3rem .45rem;margin-top:.3rem;color:var(--txt-m)">${suspOpts}</select>
        </div>
        <div><input type="text" class="form-input sz-qty" style="font-size:.76rem;padding:.38rem .5rem" placeholder="x1, 50g…" value="${preset.quantite||''}"></div>
        <div><input type="number" class="form-input sz-val" style="font-size:.76rem;padding:.38rem .5rem" placeholder="0" min="0" step="10" value="${preset.valeur||''}" oninput="updSzTotals()"></div>
        <div><input type="text" class="form-input sz-lieu" style="font-size:.76rem;padding:.38rem .5rem" placeholder="Sur personne…" value="${preset.lieu||''}"></div>
        <div style="display:flex;align-items:center;justify-content:center;padding-top:.2rem">
            <button type="button" onclick="removeSaisieRow('${id}')" style="width:28px;height:28px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:7px;cursor:pointer;color:#fca5a5;display:flex;align-items:center;justify-content:center;font-size:.75rem"><i class="fas fa-times"></i></button>
        </div>
    `;
    list.appendChild(row);
    updSzCatIcon(row.querySelector('.sz-cat'));
    updSzTotals();
    row.querySelector('.sz-desc')?.focus();
}

function removeSaisieRow(id) {
    const row = document.getElementById(id);
    if (!row) return;
    row.style.opacity = '0'; row.style.transform = 'translateX(20px)'; row.style.transition = 'all .15s';
    setTimeout(() => { row.remove(); updSzTotals(); }, 150);
}

function updSzCatIcon(sel) {
    const cat = SAISIES_CATS[sel.value];
    if (!cat) return;
    sel.style.borderLeftColor = cat.color;
    sel.style.borderLeftWidth = '3px';
}

function updSzTotals() {
    const rows   = document.querySelectorAll('#szList > div');
    const linked = document.querySelectorAll('.sz-link-cb:checked');
    const head   = document.getElementById('szHead');
    const empty  = document.getElementById('szEmpty');
    const totals = document.getElementById('szTotals');
    const addB   = document.getElementById('szAddBtn');
    const cntEl  = document.getElementById('szCnt');
    const totC   = document.getElementById('szTotCount');
    const totV   = document.getElementById('szTotVal');

    const nNew    = rows.length;
    const nLinked = linked.length;
    const nTotal  = nNew + nLinked;

    if (head)   head.style.display   = nNew > 0 ? 'grid'  : 'none';
    if (empty)  empty.style.display  = nNew > 0 ? 'none'  : 'block';
    if (totals) totals.style.display = nTotal > 0 ? 'block' : 'none';
    if (addB)   addB.style.display   = nNew > 0 ? 'block' : 'none';

    if (cntEl) {
        if (nTotal === 0) cntEl.textContent = 'Aucun objet';
        else cntEl.textContent = nTotal + ' objet' + (nTotal > 1 ? 's' : '') +
            (nNew > 0 && nLinked > 0 ? ` (${nNew} nouvelle${nNew>1?'s':''} + ${nLinked} existante${nLinked>1?'s':''})` :
             nNew > 0 ? ` nouvelle${nNew>1?'s':''}` : ` existante${nLinked>1?'s':''}`);
    }
    if (totC) totC.textContent = nTotal;

    let totalVal = 0;
    rows.forEach(row => { const v = parseFloat(row.querySelector('.sz-val')?.value||0); totalVal += isNaN(v)?0:v; });
    if (totV) totV.textContent = '$' + totalVal.toLocaleString('fr-FR');

    // Sérialiser les nouvelles saisies
    const arr = [];
    rows.forEach(row => {
        arr.push({
            categorie:   row.querySelector('.sz-cat')?.value  || 'autre',
            description: row.querySelector('.sz-desc')?.value || '',
            quantite:    row.querySelector('.sz-qty')?.value  || '',
            valeur:      row.querySelector('.sz-val')?.value  || '',
            lieu:        row.querySelector('.sz-lieu')?.value || '',
            citizen_id:  row.querySelector('.sz-cit')?.value  || '',
        });
    });
    const inp = document.getElementById('saisiesInput');
    if (inp) inp.value = JSON.stringify(arr);
}

// Mettre à jour les options suspects dans les lignes saisies quand on coche/décoche
document.addEventListener('change', function(e) {
    if (!e.target.name || e.target.name !== 'suspects[]') return;
    document.querySelectorAll('.sz-cit').forEach(sel => {
        const prev = sel.value;
        sel.innerHTML = getSuspectOptions();
        if (prev) sel.value = prev;
    });
});

(function(){
    const s = document.createElement('style');
    s.textContent = '@keyframes szIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}';
    document.head.appendChild(s);
})();

// Submit
function prepSub(){
    const el=document.getElementById('rEditor');
    if(el) document.getElementById('dRichInput').value=el.innerHTML;
    updSzTotals(); // Sérialiser les saisies au dernier moment
}
document.getElementById('rForm')?.addEventListener('submit', prepSub);

// ── PRÉ-REMPLISSAGE MODE ÉDITION ─────────────────────────────────────────
<?php if($isEdit && $editReport): ?>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Type de rapport
    (function(){
        const targetVal = <?php echo json_encode($editReport['type']??''); ?>;
        document.querySelectorAll('input[name="type"]').forEach(inp => {
            if (inp.value === targetVal) {
                inp.checked = true;
                const lbl = inp.closest('label');
                if (lbl) selType(lbl);
            }
        });
    })();

    // 2. Statut & Priorité
    (function(){
        const st = <?php echo json_encode($editReport['status']??'submitted'); ?>;
        const pr = <?php echo json_encode($editReport['priority']??'normal'); ?>;
        setTimeout(()=>{ selChip('status',st); selChip('priority',pr); }, 50);
    })();

    // 3. Tags — pré-sélection visuelle ET checkbox cochée (sinon tag perdu à la sauvegarde)
    (function(){
        const tags = <?php echo json_encode(json_decode($editReport['tags']??'[]',true)?:[]); ?>;
        document.querySelectorAll('.tch').forEach(lbl => {
            const inp = lbl.querySelector('input[name="tags[]"]');
            if (inp && tags.includes(inp.value)) {
                inp.checked = true;
                lbl.classList.add('sel');
            }
        });
        const n = document.querySelectorAll('.tch.sel').length;
        const c = document.getElementById('tcount');
        if (c) c.textContent = n === 0 ? 'Aucun tag sélectionné' : (n + ' tag' + (n>1?'s':'') + ' sélectionné' + (n>1?'s':''));
    })();

    // 4. Officiers
    (function(){
        const ids = <?php echo json_encode(json_decode($editReport['officers']??'[]',true)?:[]); ?>;
        ids.forEach(id => {
            const cb = document.querySelector('input[name="officers[]"][value="'+id+'"]');
            if (cb) { cb.checked = true; cntMS(cb,'offCnt'); }
        });
        const cnt = document.querySelectorAll('input[name="officers[]"]:checked').length;
        const el = document.getElementById('offCnt'); if(el) el.textContent = cnt;
    })();

    // 5. Suspects
    (function(){
        const ids = <?php echo json_encode(json_decode($editReport['suspects']??'[]',true)?:[]); ?>;
        ids.forEach(id => {
            const cb = document.querySelector('input[name="suspects[]"][value="'+id+'"]');
            if (cb) { cb.checked = true; }
        });
        const cnt = document.querySelectorAll('input[name="suspects[]"]:checked').length;
        const el = document.getElementById('susCnt'); if(el) el.textContent = cnt;
    })();

    // 6. Civils
    (function(){
        const ids = <?php echo json_encode(json_decode($editReport['civilians']??'[]',true)?:[]); ?>;
        ids.forEach(id => {
            const cb = document.querySelector('input[name="civilians[]"][value="'+id+'"]');
            if (cb) cb.checked = true;
        });
        const cnt = document.querySelectorAll('input[name="civilians[]"]:checked').length;
        const el = document.getElementById('civCnt'); if(el) el.textContent = cnt;
    })();

    // 7. Témoins
    (function(){
        const ids = <?php echo json_encode(json_decode($editReport['witnesses']??'[]',true)?:[]); ?>;
        ids.forEach(id => {
            const cb = document.querySelector('input[name="witnesses[]"][value="'+id+'"]');
            if (cb) cb.checked = true;
        });
        const cnt = document.querySelectorAll('input[name="witnesses[]"]:checked').length;
        const el = document.getElementById('witCnt'); if(el) el.textContent = cnt;
    })();

    // 8. Description rich (rEditor)
    (function(){
        const editor = document.getElementById('rEditor');
        if (editor) {
            editor.innerHTML = <?php echo json_encode($editReport['description_rich']??''); ?>;
            updCC();
        }
    })();

    // 9. Codes pénaux
    (function(){
        const pcIds = <?php echo json_encode(array_map('intval',$editPenalCodes)); ?>;
        pcIds.forEach(id => {
            const card = document.querySelector('.pcc[data-id="'+id+'"]');
            if (card && !sPC.has(id)) {
                togPC(card);
            }
        });
    })();

    // 10. Compteur preuves
    updEC();
});
<?php endif; ?>

// Steps scroll — 6 steps
function updSteps(){
    const secs=[{id:'s1',n:1},{id:'s2',n:2},{id:'s3',n:3},{id:'s4',n:4},{id:'s5',n:5},{id:'s6',n:6}];
    let act=1;
    const winY=window.scrollY+120;
    secs.forEach(s=>{const el=document.getElementById(s.id);if(el&&el.getBoundingClientRect().top+window.scrollY<=winY)act=s.n;});
    for(let i=1;i<=6;i++){
        const st=document.getElementById('stp'+i),sl=document.getElementById('sl'+i);
        if(st){st.className='step'+(i===act?' act':(i<act?' done':''));if(sl)sl.className='s-line'+(i<act?' done':'');}
    }
}
document.addEventListener('scroll',updSteps,{passive:true});
</script>
<?php include __DIR__ . '/includes/footer.php';?>