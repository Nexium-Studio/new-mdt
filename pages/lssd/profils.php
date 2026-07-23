<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Profils Civils'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

// ── Détection / création des colonnes ────────────────────────────────────────
function pColExists($db,$col){try{$db->query("SELECT `$col` FROM profiles LIMIT 0");return true;}catch(PDOException $e){return false;}}
$pCols=['age'=>true,'blood_type'=>pColExists($db,'blood_type'),'nationality'=>pColExists($db,'nationality'),'address'=>pColExists($db,'address'),'job'=>pColExists($db,'job'),'notes'=>pColExists($db,'notes'),'iban'=>pColExists($db,'iban'),'tags'=>pColExists($db,'tags'),'license_car'=>pColExists($db,'license_car'),'license_ppa'=>pColExists($db,'license_ppa'),'license_boat'=>pColExists($db,'license_boat'),'license_plane'=>pColExists($db,'license_plane'),'created_by'=>pColExists($db,'created_by'),'avatar'=>pColExists($db,'avatar'),'email'=>pColExists($db,'email'),'date_of_birth'=>pColExists($db,'date_of_birth'),'appartenance'=>pColExists($db,'appartenance'),'eye_color'=>pColExists($db,'eye_color'),'gender'=>pColExists($db,'gender'),'has_scar'=>pColExists($db,'has_scar'),'scar_location'=>pColExists($db,'scar_location')];
// Auto-créer colonnes manquantes
if(!$pCols['avatar']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `avatar` VARCHAR(500) NULL DEFAULT NULL");$pCols['avatar']=true;}catch(Exception $e){}}
if(!$pCols['email']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `email` VARCHAR(190) NULL DEFAULT NULL");$pCols['email']=true;}catch(Exception $e){}}
if(!$pCols['date_of_birth']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `date_of_birth` DATE NULL DEFAULT NULL");$pCols['date_of_birth']=true;}catch(Exception $e){}}
// Description physique (refonte profils citoyens)
if(!$pCols['appartenance']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `appartenance` VARCHAR(120) NULL DEFAULT NULL");$pCols['appartenance']=true;}catch(Exception $e){}}
if(!$pCols['eye_color']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `eye_color` VARCHAR(40) NULL DEFAULT NULL");$pCols['eye_color']=true;}catch(Exception $e){}}
if(!$pCols['gender']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `gender` VARCHAR(10) NULL DEFAULT NULL");$pCols['gender']=true;}catch(Exception $e){}}
if(!$pCols['has_scar']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `has_scar` TINYINT(1) NOT NULL DEFAULT 0");$pCols['has_scar']=true;}catch(Exception $e){}}
if(!$pCols['scar_location']){try{$db->exec("ALTER TABLE profiles ADD COLUMN `scar_location` VARCHAR(160) NULL DEFAULT NULL");$pCols['scar_location']=true;}catch(Exception $e){}}

// Calcule l'âge depuis une date de naissance (fallback colonne age héritée).
function pAge($dob, $fallback=null){
    if(!empty($dob) && $dob!=='0000-00-00'){ try{ $d=new DateTime($dob); return (new DateTime('today'))->diff($d)->y; }catch(Exception $e){} }
    return ($fallback!==null && $fallback!=='') ? (int)$fallback : null;
}

// Notes publiques / privées sur un citoyen
try{$db->exec("CREATE TABLE IF NOT EXISTS `lssd_citizen_notes` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `citizen_id` INT NOT NULL,
    `author_id` INT NULL,
    `visibility` ENUM('public','private') NOT NULL DEFAULT 'public',
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_citizen` (`citizen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

// ── Helper : valider l'URL d'avatar ─────────────────────────────────────────
function sanitizeAvatarUrl($url) {
    $url = trim($url);
    if (empty($url)) return null;
    if (!preg_match('/^https?:\/\/.+/i', $url)) return null;
    return $url;
}

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            if (empty(trim($_POST['firstname']??''))) throw new Exception('Le prénom est requis.');
            if (empty(trim($_POST['lastname']??'')))  throw new Exception('Le nom est requis.');
            $fields=['firstname','lastname']; $vals=[trim($_POST['firstname']),trim($_POST['lastname'])];
            foreach(['phone','nationality','address','job','notes','blood_type','iban','email','date_of_birth'] as $col){
                if($pCols[$col]??false){if(isset($_POST[$col])&&$_POST[$col]!==''){$fields[]=$col;$vals[]=trim($_POST[$col]);}}
            }
            // ── Description physique ──
            $genderVal = in_array($_POST['gender']??'',['Homme','Femme'],true) ? $_POST['gender'] : '';
            if(($pCols['gender']??false)){
                if($genderVal===''){ throw new Exception('Le genre est requis.'); }
                $fields[]='gender'; $vals[]=$genderVal;
            }
            if($pCols['appartenance']??false){ $ap=trim($_POST['appartenance']??''); if($ap!==''){$fields[]='appartenance';$vals[]=$ap;} }
            if($pCols['eye_color']??false){ $ec=trim($_POST['eye_color']??''); if($ec==='Autre'){$ec=trim($_POST['eye_color_other']??'');} if($ec!==''){$fields[]='eye_color';$vals[]=$ec;} }
            if($pCols['has_scar']??false){ $hs=(!empty($_POST['has_scar'])&&$_POST['has_scar']!=='0')?1:0; $fields[]='has_scar'; $vals[]=$hs;
                if(($pCols['scar_location']??false)&&$hs){ $sl=trim($_POST['scar_location']??''); if($sl!==''){$fields[]='scar_location';$vals[]=$sl;} } }
            // Âge dérivé de la date de naissance (compat colonne age)
            if(($pCols['age']??false) && !empty($_POST['date_of_birth'])){ $a=pAge($_POST['date_of_birth']); if($a!==null){$fields[]='age';$vals[]=$a;} }
            foreach(['license_car','license_ppa','license_boat','license_plane'] as $lf){
                if($pCols[$lf]??false){$fields[]=$lf;$vals[]=isset($_POST[$lf])?1:0;}
            }
            if(($pCols['tags']??false)&&!empty($_POST['tags_json'])){$fields[]='tags';$vals[]=$_POST['tags_json'];}
            if($pCols['created_by']??false){$fields[]='created_by';$vals[]=$uid;}
            $db->prepare("INSERT INTO profiles (".implode(',',$fields).") VALUES (".implode(',',array_fill(0,count($fields),'?')).")")->execute($vals);
            $newId=$db->lastInsertId();
            // URL avatar
            $avatarUrl = sanitizeAvatarUrl($_POST['avatar_url'] ?? '');
            if ($avatarUrl) $db->prepare("UPDATE profiles SET avatar=? WHERE id=?")->execute([$avatarUrl, $newId]);
            $msg='Profil créé avec succès.';$msgType='success';
            header('Location: profils.php?id='.$newId.'&created=1');exit;
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='edit') {
        try {
            $pid=intval($_POST['pid']); if(!$pid) throw new Exception('ID invalide.');
            $sets=[];$vals=[];
            foreach(['firstname','lastname','phone','nationality','address','job','notes','blood_type','iban','email','date_of_birth'] as $f){
                if(isset($_POST[$f]) && ($pCols[$f]??true)){$sets[]="$f=?";$vals[]=trim($_POST[$f]);}
            }
            // ── Description physique ──
            if(($pCols['gender']??false)&&isset($_POST['gender'])){ $gv=in_array($_POST['gender'],['Homme','Femme'],true)?$_POST['gender']:''; $sets[]='gender=?'; $vals[]=$gv; }
            if(($pCols['appartenance']??false)&&isset($_POST['appartenance'])){ $sets[]='appartenance=?'; $vals[]=trim($_POST['appartenance']); }
            if(($pCols['eye_color']??false)&&isset($_POST['eye_color'])){ $ec=trim($_POST['eye_color']); if($ec==='Autre'){$ec=trim($_POST['eye_color_other']??'');} $sets[]='eye_color=?'; $vals[]=$ec; }
            if($pCols['has_scar']??false){ $hs=(!empty($_POST['has_scar'])&&$_POST['has_scar']!=='0')?1:0; $sets[]='has_scar=?'; $vals[]=$hs;
                if($pCols['scar_location']??false){ $sets[]='scar_location=?'; $vals[]=$hs?trim($_POST['scar_location']??''):''; } }
            // Recalcule l'âge si une date de naissance est fournie
            if(($pCols['age']??false) && isset($_POST['date_of_birth']) && $_POST['date_of_birth']!==''){ $a=pAge($_POST['date_of_birth']); if($a!==null){$sets[]='age=?';$vals[]=$a;} }
            foreach(['license_car','license_ppa','license_boat','license_plane'] as $lf){
                if($pCols[$lf]??false){$sets[]="$lf=?";$vals[]=isset($_POST[$lf])?1:0;}
            }
            if(($pCols['tags']??false)&&isset($_POST['tags_json'])){$sets[]='tags=?';$vals[]=$_POST['tags_json'];}
            // URL avatar
            if (isset($_POST['avatar_url'])) {
                $avatarUrl = sanitizeAvatarUrl($_POST['avatar_url']);
                $sets[] = 'avatar=?';
                $vals[] = $avatarUrl; // null si vide = supprime la photo
            }
            if(!empty($sets)){$vals[]=$pid;$db->prepare("UPDATE profiles SET ".implode(',',$sets)." WHERE id=?")->execute($vals);}
            $msg='Profil mis à jour.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    // ── Notes citoyen (publiques / privées) ──
    if ($action==='add_citizen_note') {
        $cid=intval($_POST['citizen_id']); $vis=in_array($_POST['visibility']??'public',['public','private'],true)?$_POST['visibility']:'public'; $content=trim($_POST['content']??'');
        if($cid && $content!==''){ try{$db->prepare("INSERT INTO lssd_citizen_notes (citizen_id,author_id,visibility,content) VALUES (?,?,?,?)")->execute([$cid,$uid,$vis,$content]);}catch(Exception $e){} }
        header('Location: profils.php?id='.$cid); exit;
    }
    if ($action==='edit_citizen_note') {
        $nid=intval($_POST['note_id']); $cid=intval($_POST['citizen_id']); $content=trim($_POST['content']??'');
        try{$n=$db->prepare("SELECT author_id,visibility FROM lssd_citizen_notes WHERE id=?");$n->execute([$nid]);$nr=$n->fetch();
            if($nr && $content!=='' && ((int)$nr['author_id']===$uid || hasRole(['*','Administrateur','Développeur','Staff']))){
                $db->prepare("UPDATE lssd_citizen_notes SET content=? WHERE id=?")->execute([$content,$nid]); }
        }catch(Exception $e){}
        header('Location: profils.php?id='.$cid); exit;
    }
    if ($action==='delete_citizen_note') {
        $nid=intval($_POST['note_id']); $cid=intval($_POST['citizen_id']);
        try{$n=$db->prepare("SELECT author_id,visibility FROM lssd_citizen_notes WHERE id=?");$n->execute([$nid]);$nr=$n->fetch();
            // Privée : auteur ou staff. Publique : tout agent.
            if($nr && ($nr['visibility']==='public' || (int)$nr['author_id']===$uid || hasRole(['*','Administrateur','Développeur','Staff']))){
                $db->prepare("DELETE FROM lssd_citizen_notes WHERE id=?")->execute([$nid]); }
        }catch(Exception $e){}
        header('Location: profils.php?id='.$cid); exit;
    }

    // Purge civils
    if ($action==='purge_profiles' && hasRole(['*','Administrateur','Développeur'])) {
        if (trim($_POST['confirm_word']??'') !== 'PURGE') {
            $msg='Confirmation incorrecte. Tapez exactement PURGE.'; $msgType='error';
        } else {
            try {
                $count=(int)$db->query("SELECT COUNT(*) FROM profiles")->fetchColumn();
                $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                try { $db->exec("DELETE FROM profiles"); $msg="$count profil(s) supprimé(s)."; $msgType='success'; }
                finally { $db->exec("SET FOREIGN_KEY_CHECKS = 1"); }
            } catch(Exception $e){$msg='Erreur purge: '.$e->getMessage(); $msgType='error';}
        }
    }
}

$search=trim($_GET['q']??''); $viewId=intval($_GET['id']??0);
$profile=null; $casiers=[]; $warrants=[]; $vehicles=[];
$ppaList=[]; $convocs=[]; $bracelets=[]; $civArmes=[]; $cityReports=[]; $procs=[]; $sents=[]; $realLoc=[]; $realVente=[]; $notesPub=[]; $notesPriv=[];
$ppaActive=false; $braceletActif=false; $convocPending=false;
if ($viewId>0) {
    $s=$db->prepare("SELECT * FROM profiles WHERE id=?");$s->execute([$viewId]);$profile=$s->fetch();
    if ($profile) {
        try{$s=$db->prepare("SELECT cr.*,u.username AS officer FROM criminal_records cr LEFT JOIN users u ON u.id=cr.officer_id WHERE cr.citizen_id=? ORDER BY cr.created_at DESC LIMIT 20");$s->execute([$viewId]);$casiers=$s->fetchAll();}catch(Exception $e){}
        try{$s=$db->prepare("SELECT aw.*,u.username AS officer FROM arrest_warrants aw LEFT JOIN users u ON u.id=aw.issued_by WHERE aw.citizen_id=? ORDER BY aw.issued_date DESC LIMIT 10");$s->execute([$viewId]);$warrants=$s->fetchAll();}catch(Exception $e){}
        try{$s=$db->prepare("SELECT * FROM vehicles WHERE owner_id=? ORDER BY created_at DESC");$s->execute([$viewId]);$vehicles=$s->fetchAll();}catch(Exception $e){}
        // PPA
        try{$s=$db->prepare("SELECT d.*,u.username AS officer FROM lssd_ppa_demandes d LEFT JOIN users u ON u.id=d.officer_id WHERE d.citizen_id=? ORDER BY d.created_at DESC LIMIT 10");$s->execute([$viewId]);$ppaList=$s->fetchAll();}catch(Exception $e){}
        $ppaActive = ((int)($profile['license_ppa']??0)===1) || (!empty($ppaList) && ($ppaList[0]['statut']??'')==='valide');
        // Convocations
        try{$s=$db->prepare("SELECT c.*,u.username AS agent FROM lssd_convocations c LEFT JOIN users u ON u.id=c.agent_convoquant WHERE c.citoyen_id=? ORDER BY c.date_convocation DESC LIMIT 15");$s->execute([$viewId]);$convocs=$s->fetchAll();}catch(Exception $e){}
        foreach($convocs as $cv){ if(in_array($cv['statut']??'',['envoyee','reportee'],true)){$convocPending=true;break;} }
        // Bracelets
        try{$s=$db->prepare("SELECT * FROM lssd_bracelets WHERE citoyen_id=? ORDER BY FIELD(statut,'actif','suspendu','violation','expire','retire'),date_pose DESC LIMIT 10");$s->execute([$viewId]);$bracelets=$s->fetchAll();}catch(Exception $e){}
        foreach($bracelets as $br){ if(($br['statut']??'')==='actif'){$braceletActif=true;break;} }
        // Armes civiles
        try{$s=$db->prepare("SELECT * FROM lssd_armes_civils WHERE citizen_id=? ORDER BY date_enregistrement DESC LIMIT 20");$s->execute([$viewId]);$civArmes=$s->fetchAll();}catch(Exception $e){}
        // Rapports liés — les citoyens sont stockés en JSON dans civilians/suspects/witnesses
        // (pas de colonne citizen_id sur reports). On préfiltre par LIKE puis on vérifie en PHP.
        try{
            $like='%'.$viewId.'%';
            $s=$db->prepare("SELECT r.id,r.title,r.type,r.created_at,r.civilians,r.suspects,r.witnesses,
                                    u.username AS author, p.firstname AS af, p.lastname AS al
                             FROM reports r LEFT JOIN users u ON u.id=r.created_by LEFT JOIN profiles p ON p.id=u.profile_id
                             WHERE r.civilians LIKE ? OR r.suspects LIKE ? OR r.witnesses LIKE ?
                             ORDER BY r.created_at DESC LIMIT 200");
            $s->execute([$like,$like,$like]);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                $ids=[];
                foreach(['civilians','suspects','witnesses'] as $f){ $a=json_decode($r[$f]??'[]',true); if(is_array($a)){ foreach($a as $v){ $ids[]=(int)(is_array($v)?($v['id']??0):$v); } } }
                if(in_array($viewId,$ids,true)){ $r['author_disp']=mdt_rp_display_name($r['af']??null,$r['al']??null,(string)($r['author']??'')); $cityReports[]=$r; }
            }
        }catch(Exception $e){}
        // Historique judiciaire DOJ
        try{$s=$db->prepare("SELECT case_number,case_title,case_type,status,priority,hearing_date,verdict,created_at FROM court_cases WHERE defendant_id=? ORDER BY created_at DESC LIMIT 20");$s->execute([$viewId]);$procs=$s->fetchAll();}catch(Exception $e){}
        try{$s=$db->prepare("SELECT sentence_type,duration_months,duration_hours,fine_amount,start_date,end_date,status FROM sentences WHERE citizen_id=? ORDER BY created_at DESC LIMIT 20");$s->execute([$viewId]);$sents=$s->fetchAll();}catch(Exception $e){}
        // Immobilier Dynasty (lien par nom — pas de FK profil)
        $fullName=trim(($profile['firstname']??'').' '.($profile['lastname']??''));
        if($fullName!==''){
            try{$s=$db->prepare("SELECT l.*,b.titre AS bien_titre,b.adresse AS bien_adresse,b.quartier AS bien_quartier,b.surface AS bien_surface,b.pieces AS bien_pieces,b.type_bien AS bien_type FROM dynasty_locations l LEFT JOIN dynasty_biens b ON b.id=l.bien_id WHERE l.locataire_nom LIKE ? ORDER BY l.id DESC LIMIT 15");$s->execute([$fullName]);$realLoc=$s->fetchAll();}catch(Exception $e){}
            try{$s=$db->prepare("SELECT v.*,b.titre AS bien_titre,b.adresse AS bien_adresse,b.quartier AS bien_quartier,b.surface AS bien_surface,b.pieces AS bien_pieces,b.type_bien AS bien_type FROM dynasty_ventes v LEFT JOIN dynasty_biens b ON b.id=v.bien_id WHERE v.acheteur_nom LIKE ? ORDER BY v.id DESC LIMIT 15");$s->execute([$fullName]);$realVente=$s->fetchAll();}catch(Exception $e){}
        }
        // Notes (avec identité RP + avatar de l'auteur)
        $noteSel="SELECT n.*, u.username AS author, p.firstname AS af, p.lastname AS al, p.avatar AS aavatar FROM lssd_citizen_notes n LEFT JOIN users u ON u.id=n.author_id LEFT JOIN profiles p ON p.id=u.profile_id";
        try{$s=$db->prepare($noteSel." WHERE n.citizen_id=? AND n.visibility='public' ORDER BY n.created_at ASC");$s->execute([$viewId]);$notesPub=$s->fetchAll();}catch(Exception $e){}
        try{$s=$db->prepare($noteSel." WHERE n.citizen_id=? AND n.visibility='private' AND n.author_id=? ORDER BY n.created_at ASC");$s->execute([$viewId,$uid]);$notesPriv=$s->fetchAll();}catch(Exception $e){}

        // ── Auto-tag PPA : synchronise le tag selon le statut PPA ──
        if($pCols['tags']??false){
            $curTags=json_decode($profile['tags']??'[]',true)?:[]; $hasTag=in_array('PPA',$curTags,true);
            if($ppaActive && !$hasTag){ $curTags[]='PPA'; }
            elseif(!$ppaActive && $hasTag){ $curTags=array_values(array_diff($curTags,['PPA'])); }
            if(($ppaActive&&!$hasTag)||(!$ppaActive&&$hasTag)){
                try{$db->prepare("UPDATE profiles SET tags=? WHERE id=?")->execute([json_encode($curTags),$viewId]);$profile['tags']=json_encode($curTags);}catch(Exception $e){}
            }
        }
    }
}
if (!$profile) {
    $q="SELECT p.*,(SELECT COUNT(*) FROM criminal_records WHERE citizen_id=p.id AND status='active') AS nb_casiers,(SELECT COUNT(*) FROM arrest_warrants WHERE citizen_id=p.id AND status='active') AS nb_mandat FROM profiles p WHERE 1=1";
    $params=[];
    if ($search){$q.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR p.phone LIKE ? OR p.iban LIKE ?)";$pa="%$search%";$params=[$pa,$pa,$pa,$pa];}
    $q.=" ORDER BY p.lastname,p.firstname LIMIT 120";
    $stmt=$db->prepare($q);$stmt->execute($params);$profiles=$stmt->fetchAll();
}
$BLOOD=['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$EYE_COLORS=['Marron','Bleu','Vert','Gris','Noir','Noisette','Rouge','Violet','Autre'];
$COMMON_TAGS=['Dangereux','Fiché S','Armé','Récidiviste','Recherché','Gang','Cartel','Mineur','VIP','Immunité diplomatique','Criminel connu','Agressif'];
include __DIR__ . '/includes/header.php';
?>
<style>
:root{--glass:rgba(10,14,32,.72);--glass-bdr:rgba(16,185,129,.18);--glass-bdr-h:rgba(16,185,129,.45)}
.prof-hero{position:relative;padding:2rem 1.75rem 1.5rem;margin-bottom:1.5rem;border-radius:16px;overflow:hidden;border:1px solid var(--glass-bdr)}
.prof-hero::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(16,185,129,.12) 0%,rgba(52,211,153,.06) 50%,rgba(139,92,246,.08) 100%);pointer-events:none}
.prof-hero::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(16,185,129,.08) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;opacity:.6}
.prof-hero-content{position:relative;z-index:1}
.pcard{position:relative;background:var(--glass);border:1px solid var(--glass-bdr);border-radius:14px;padding:1.15rem;cursor:pointer;transition:all .22s;overflow:hidden}
.pcard::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--blue),var(--blue-l));opacity:0;transition:opacity .22s}
.pcard:hover{border-color:var(--glass-bdr-h);transform:translateY(-3px);box-shadow:0 12px 35px rgba(0,0,0,.35)}
.pcard:hover::before{opacity:1}
.pcard.wanted{border-color:rgba(239,68,68,.35)}.pcard.wanted::before{background:linear-gradient(90deg,#ef4444,#f87171);opacity:1}
.ptag-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:20px;font-size:.67rem;font-weight:700;cursor:pointer;transition:all .15s;margin:.15rem;border:1px solid rgba(239,68,68,.25);background:rgba(239,68,68,.06);color:#fca5a5}
.ptag-chip:hover{background:rgba(239,68,68,.15);transform:scale(1.04)}
.ptag-chip.sel{background:rgba(239,68,68,.22);border-color:rgba(239,68,68,.55);box-shadow:0 0 8px rgba(239,68,68,.2)}
.detail-section{background:var(--glass);border:1px solid var(--glass-bdr);border-radius:14px;padding:1.25rem;margin-bottom:1.1rem}
.field-section{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);padding:.65rem 0 .35rem;border-top:1px solid rgba(16,185,129,.1);margin-top:.65rem;display:flex;align-items:center;gap:.45rem}
.license-toggle{display:flex;align-items:center;gap:.55rem;padding:.5rem .8rem;background:rgba(0,0,0,.2);border:1px solid rgba(16,185,129,.12);border-radius:8px;cursor:pointer;transition:all .17s;font-size:.8rem;color:var(--txt-s)}
.license-toggle:hover{border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.06)}
.license-toggle.active{border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.08);color:#6ee7b7}
.license-toggle input{display:none}
.lt-dot{width:20px;height:20px;border-radius:50%;background:rgba(100,116,139,.2);border:2px solid rgba(100,116,139,.3);display:flex;align-items:center;justify-content:center;transition:all .17s;flex-shrink:0;font-size:.7rem}
.license-toggle.active .lt-dot{background:rgba(16,185,129,.3);border-color:rgba(16,185,129,.6);color:#6ee7b7}
.view-toggle{display:flex;background:rgba(0,0,0,.25);border:1px solid var(--bdr);border-radius:9px;padding:.2rem;gap:.1rem}
.vt-btn{padding:.38rem .72rem;border-radius:7px;border:none;background:transparent;cursor:pointer;color:var(--txt-m);font-size:.82rem;transition:all .17s}
.vt-btn.active{background:rgba(16,185,129,.15);color:var(--blue-xl)}
@keyframes stripe-move{to{background-position:40px 0}}
.wanted-alert{background:repeating-linear-gradient(45deg,rgba(239,68,68,.08),rgba(239,68,68,.08) 10px,rgba(239,68,68,.04) 10px,rgba(239,68,68,.04) 20px);border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:.75rem 1.1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;animation:stripe-move 2s linear infinite}

/* ── Avatar styles ── */
.profile-avatar-wrap {
    width:80px; height:80px; border-radius:18px; flex-shrink:0;
    position:relative; overflow:hidden;
    border:2px solid rgba(16,185,129,.3);
    box-shadow:0 0 20px rgba(16,185,129,.15);
}
.profile-avatar-wrap img { width:100%; height:100%; object-fit:cover; }
.profile-avatar-initials {
    width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    font-family:'Syne',serif; font-weight:800; font-size:1.8rem; color:#f9fafb;
}
.profile-avatar-wrap.wanted-border { border-color:rgba(239,68,68,.7); box-shadow:0 0 20px rgba(239,68,68,.3); }

/* Card avatar */
.pcard-av {
    width:48px; height:48px; border-radius:12px;
    overflow:hidden; flex-shrink:0;
    border:1.5px solid rgba(16,185,129,.3);
    display:flex; align-items:center; justify-content:center;
    font-family:'Syne',serif; font-weight:800; font-size:1.15rem;
}
.pcard-av img { width:100%; height:100%; object-fit:cover; }

/* Avatar via URL — no upload zone needed */

/* ── Description physique : segmented radios + reveal ── */
.pd-seg{display:inline-flex;gap:.3rem;flex-wrap:wrap}
.pd-opt{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:8px;border:1px solid rgba(16,185,129,.18);background:rgba(0,0,0,.2);color:var(--txt-s);font-size:.8rem;font-weight:600;cursor:pointer;transition:all .17s;user-select:none}
.pd-opt:hover{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.06)}
.pd-opt input{display:none}
.pd-opt.on{border-color:rgba(16,185,129,.5);background:rgba(16,185,129,.14);color:var(--blue-xl);box-shadow:0 0 10px rgba(16,185,129,.12)}
.pd-opt .fa-mars{color:#34d399}.pd-opt .fa-venus{color:#f472b6}
.pd-reveal{overflow:hidden;max-height:0;opacity:0;transition:max-height .3s ease,opacity .3s ease,margin .3s ease;margin-top:0}
.pd-reveal.show{max-height:120px;opacity:1;margin-top:.65rem}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo htmlspecialchars($msg);?></div><?php endif;?>
<?php if(isset($_GET['created'])):?><div class="alert alert-success"><i class="fas fa-check-circle"></i> Profil créé avec succès !</div><?php endif;?>

<?php if($profile):
// ── VUE DÉTAIL (dossier MDT) ──────────────────────────────────────────────
$tags=json_decode($profile['tags']??'[]',true)?:[];
$nbCasAct=count(array_filter($casiers,fn($c)=>($c['status']??'')==='active'));
$nbMandAct=count(array_filter($warrants,fn($w)=>($w['status']??'')==='active'));
$init=mdt_rp_initials($profile['firstname']??null,$profile['lastname']??null,'?');
$hasAvatar=!empty($profile['avatar']);
$avatarSrc=$hasAvatar?(preg_match('#^https?://#',$profile['avatar'])?$profile['avatar']:SITE_URL.$profile['avatar']):'';
$vAge=pAge($profile['date_of_birth']??null,$profile['age']??null);
// Niveau de dangerosité
if($nbMandAct>0){$threat=['ÉLEVÉ','#ef4444','rgba(239,68,68,.12)','rgba(239,68,68,.4)'];}
elseif($nbCasAct>0){$threat=['MODÉRÉ','#fb923c','rgba(249,115,22,.12)','rgba(249,115,22,.4)'];}
else{$threat=['FAIBLE','#6ee7b7','rgba(16,185,129,.1)','rgba(16,185,129,.3)'];}
// Maps de statuts
$PPA_ST=['demande'=>['En attente','bg-yellow'],'en_cours'=>['En cours','bg-blue'],'valide'=>['Validé','bg-green'],'refuse'=>['Refusé','bg-red']];
$CV_ST=['envoyee'=>['Envoyée','bg-yellow'],'presentee'=>['Présentée','bg-green'],'absente'=>['Absent','bg-red'],'annulee'=>['Annulée','bg-gray'],'reportee'=>['Reportée','bg-blue']];
$BR_ST=['actif'=>['Actif','bg-green'],'suspendu'=>['Suspendu','bg-yellow'],'violation'=>['Violation','bg-red'],'expire'=>['Expiré','bg-gray'],'retire'=>['Retiré','bg-gray']];
$CC_ST=['filed'=>['Déposé','bg-blue'],'scheduled'=>['Programmé','bg-yellow'],'in_progress'=>['En cours','bg-yellow'],'concluded'=>['Conclu','bg-green'],'dismissed'=>['Classé','bg-gray']];
$SE_ST=['active'=>['Active','bg-red'],'pending'=>['En attente','bg-yellow'],'completed'=>['Purgée','bg-green'],'suspended'=>['Suspendue','bg-gray']];
$ARM_ST=['enregistree'=>['Enregistrée','bg-green'],'suspendue'=>['Suspendue','bg-yellow'],'revoquee'=>['Révoquée','bg-red']];
$WR_ST=['active'=>'bg-red','executed'=>'bg-green','cancelled'=>'bg-gray','expired'=>'bg-gray'];
$CR_ST=['active'=>'bg-red','served'=>'bg-green','pardoned'=>'bg-yellow','expired'=>'bg-gray'];
?>
<style>
/* ═══════════ DOSSIER CITOYEN (cz-) ═══════════ */
.cz-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.6rem}
.cz-actions-row{display:flex;gap:.5rem;flex-wrap:wrap}
.cz-header{position:relative;display:grid;grid-template-columns:auto 1fr auto;gap:1.6rem;align-items:center;padding:1.6rem 1.8rem;border-radius:18px;overflow:hidden;border:1px solid rgba(16,185,129,.22);margin-bottom:1.3rem;background:linear-gradient(135deg,rgba(13,22,46,.9),rgba(6,11,26,.92))}
.cz-header::before{content:'';position:absolute;inset:0;background:radial-gradient(120% 120% at 0% 0%,rgba(16,185,129,.16),transparent 55%),radial-gradient(80% 80% at 100% 100%,rgba(139,92,246,.1),transparent 55%);pointer-events:none}
.cz-header::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(16,185,129,.06) 1px,transparent 1px);background-size:24px 24px;opacity:.5;pointer-events:none}
.cz-header>*{position:relative;z-index:1}
@media(max-width:1100px){.cz-header{grid-template-columns:1fr;text-align:center}.cz-id-grid{justify-content:center}}
/* avatar */
.cz-avatar{width:128px;height:128px;border-radius:22px;overflow:hidden;flex-shrink:0;border:2px solid rgba(16,185,129,.4);box-shadow:0 10px 40px rgba(0,0,0,.5),0 0 0 4px rgba(16,185,129,.06);cursor:pointer;transition:transform .25s,box-shadow .25s;position:relative;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(0,0,0,.5))}
.cz-avatar:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 16px 50px rgba(0,0,0,.6),0 0 0 4px rgba(16,185,129,.18)}
.cz-avatar img{width:100%;height:100%;object-fit:cover}
.cz-avatar .cz-av-ph{width:100%;height:100%;display:grid;place-items:center;font-family:'Syne',serif;font-weight:800;font-size:3rem;color:#fff}
.cz-avatar .cz-av-zoom{position:absolute;inset:0;display:grid;place-items:center;background:rgba(3,8,15,.55);color:#fff;opacity:0;transition:opacity .2s;font-size:1.3rem}
.cz-avatar:hover .cz-av-zoom{opacity:1}
.cz-avatar.wanted{border-color:rgba(239,68,68,.6);box-shadow:0 10px 40px rgba(239,68,68,.25),0 0 0 4px rgba(239,68,68,.1)}
/* identity */
.cz-name{font-family:'Syne',serif;font-weight:900;font-size:2rem;line-height:1.05;color:#fff;letter-spacing:-.02em}
.cz-name small{display:block;font-size:1.05rem;font-weight:700;color:var(--blue-l);letter-spacing:0;margin-top:.1rem}
.cz-chips{display:flex;gap:.4rem;flex-wrap:wrap;margin:.7rem 0}
.cz-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .7rem;border-radius:8px;font-size:.72rem;font-weight:700}
.cz-id-grid{display:grid;grid-template-columns:repeat(2,minmax(150px,1fr));gap:.5rem 1.6rem;margin-top:.4rem}
.cz-id-grid .lbl{font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m)}
.cz-id-grid .val{font-size:.84rem;font-weight:600;color:var(--txt)}
/* stat tiles */
.cz-stats{display:grid;grid-template-columns:repeat(3,72px);gap:.55rem}
.cz-stat{background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);border-radius:11px;padding:.6rem .3rem;text-align:center;transition:border-color .18s,transform .18s}
.cz-stat:hover{border-color:rgba(16,185,129,.4);transform:translateY(-2px)}
.cz-stat .n{font-family:'IBM Plex Mono',monospace;font-size:1.3rem;font-weight:800;line-height:1}
.cz-stat .t{font-size:.56rem;text-transform:uppercase;letter-spacing:.06em;color:var(--txt-m);margin-top:.25rem}
/* tabs */
.cz-tabs{display:flex;gap:.25rem;flex-wrap:wrap;border-bottom:1px solid rgba(16,185,129,.15);margin-bottom:1.2rem;padding-bottom:.15rem}
.cz-tab{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem .9rem;border-radius:9px 9px 0 0;border:none;background:transparent;color:var(--txt-s);font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .16s;position:relative}
.cz-tab:hover{color:var(--blue-xl);background:rgba(16,185,129,.06)}
.cz-tab.active{color:#fff;background:rgba(16,185,129,.12)}
.cz-tab.active::after{content:'';position:absolute;left:.6rem;right:.6rem;bottom:-.2rem;height:2px;border-radius:2px;background:linear-gradient(90deg,var(--blue),var(--blue-l))}
.cz-tab .cz-count{font-size:.62rem;background:rgba(16,185,129,.18);color:var(--blue-xl);border-radius:10px;padding:.05rem .4rem;font-family:'IBM Plex Mono',monospace}
.cz-panel{display:none;animation:czFade .25s ease}
.cz-panel.active{display:block}
@keyframes czFade{from{opacity:0;transform:translateY(6px)}}
/* generic list rows */
.cz-row{display:flex;align-items:center;justify-content:space-between;gap:.7rem;padding:.7rem .9rem;background:var(--glass);border:1px solid var(--glass-bdr);border-radius:11px;margin-bottom:.5rem;transition:border-color .15s,transform .15s;text-decoration:none;color:var(--txt)}
.cz-row:hover{border-color:var(--glass-bdr-h);transform:translateX(2px)}
.cz-empty{text-align:center;padding:2.5rem 1rem;color:var(--txt-m);font-size:.85rem}
.cz-empty i{font-size:2rem;opacity:.18;display:block;margin-bottom:.6rem}
.cz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.7rem}
.cz-veh:hover{border-color:var(--glass-bdr-h)!important;transform:translateY(-2px)}
/* note discord-style */
.cz-note{display:flex;gap:.7rem;padding:.55rem .2rem;align-items:flex-start}
.cz-note-av{width:38px;height:38px;border-radius:50%;flex-shrink:0;object-fit:cover;border:1px solid rgba(16,185,129,.25);background:linear-gradient(135deg,#10b981,#047857);display:grid;place-items:center;color:#fff;font-weight:700;font-size:.85rem;overflow:hidden}
.cz-note-av img{width:100%;height:100%;object-fit:cover}
.cz-note-body{flex:1;min-width:0}
.cz-note-head{display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap}
.cz-note-author{font-weight:700;font-size:.84rem;color:#fff}
.cz-note-time{font-size:.66rem;color:var(--txt-m)}
.cz-note-bubble{margin-top:.25rem;padding:.6rem .85rem;background:var(--glass);border:1px solid var(--glass-bdr);border-radius:4px 12px 12px 12px;font-size:.85rem;line-height:1.6;white-space:pre-wrap;color:var(--txt)}
.cz-note:hover .cz-note-actions{opacity:1}
.cz-note-actions{opacity:0;transition:opacity .15s;display:flex;gap:.25rem;flex-shrink:0;align-self:flex-start;margin-top:.2rem}
.cz-sec-h{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:rgba(16,185,129,.75);margin:0 0 .8rem;display:flex;align-items:center;gap:.45rem}
.cz-sec-h i{color:var(--blue-l)}
/* lightbox */
.cz-lightbox{position:fixed;inset:0;background:rgba(2,5,10,.92);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100001;padding:2rem;cursor:zoom-out}
.cz-lightbox.open{display:flex;animation:czFade .2s ease}
.cz-lightbox img{max-width:90vw;max-height:86vh;border-radius:14px;border:2px solid rgba(16,185,129,.35);box-shadow:0 30px 90px rgba(0,0,0,.7)}
.cz-lightbox .cz-lb-close{position:absolute;top:1.3rem;right:1.5rem;width:42px;height:42px;border-radius:50%;background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.3);color:#fff;font-size:1.1rem;cursor:pointer;display:grid;place-items:center;transition:all .18s}
.cz-lightbox .cz-lb-close:hover{background:rgba(239,68,68,.25);transform:rotate(90deg)}
.cz-lb-cap{position:absolute;bottom:1.4rem;left:50%;transform:translateX(-50%);font-size:.8rem;color:var(--txt-s);font-family:'IBM Plex Mono',monospace}
</style>

<!-- BARRE D'ACTIONS -->
<div class="cz-top">
    <a href="profils.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Citoyens</a>
    <div class="cz-actions-row">
        <button onclick="LSSD.openModal('editModal')" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i> Modifier</button>
        <a href="casiers.php?new=1&cid=<?php echo $profile['id'];?>" class="btn btn-ghost btn-sm"><i class="fas fa-gavel"></i> Casier</a>
        <a href="recherches.php?new=1&cid=<?php echo $profile['id'];?>" class="btn btn-danger btn-sm"><i class="fas fa-crosshairs"></i> Mandat</a>
        <a href="ppa.php?cid=<?php echo $profile['id'];?>" class="btn btn-ghost btn-sm"><i class="fas fa-gun"></i> PPA</a>
        <a href="reports.php?new=1&cid=<?php echo $profile['id'];?>" class="btn btn-primary btn-sm"><i class="fas fa-file-plus"></i> Rapport</a>
    </div>
</div>

<!-- HEADER DOSSIER -->
<div class="cz-header">
    <div class="cz-avatar <?php echo $nbMandAct>0?'wanted':'';?>" onclick="czAvatarOpen()" title="Voir la photo officielle">
        <?php if($hasAvatar):?><img src="<?php echo htmlspecialchars($avatarSrc);?>" alt=""><?php else:?><div class="cz-av-ph"><?php echo htmlspecialchars($init);?></div><?php endif;?>
        <div class="cz-av-zoom"><i class="fas fa-magnifying-glass-plus"></i></div>
    </div>
    <div>
        <div class="cz-name"><?php echo htmlspecialchars(strtoupper($profile['lastname']));?><small><?php echo htmlspecialchars($profile['firstname']);?></small></div>
        <div class="cz-chips">
            <span class="cz-chip" style="color:<?php echo $threat[1];?>;background:<?php echo $threat[2];?>;border:1px solid <?php echo $threat[3];?>"><i class="fas fa-triangle-exclamation"></i> Dangerosité <?php echo $threat[0];?></span>
            <?php if($ppaActive):?><span class="cz-chip" style="color:#6ee7b7;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3)"><i class="fas fa-gun"></i> PPA actif</span><?php endif;?>
            <?php if($braceletActif):?><span class="cz-chip" style="color:#fb923c;background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.3)"><i class="fas fa-circle-dot"></i> Bracelet</span><?php endif;?>
            <?php if($nbMandAct>0):?><span class="cz-chip" style="color:#fca5a5;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35)"><i class="fas fa-crosshairs"></i> Recherché</span><?php endif;?>
            <?php if($convocPending):?><span class="cz-chip" style="color:#fde68a;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3)"><i class="fas fa-envelope-open-text"></i> Convocation</span><?php endif;?>
            <?php foreach(array_slice($tags,0,4) as $t): if($t==='PPA')continue;?><span class="cz-chip" style="color:#fca5a5;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25)"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($t);?></span><?php endforeach;?>
        </div>
        <div class="cz-id-grid">
            <div><div class="lbl">Date de naissance</div><div class="val"><?php echo !empty($profile['date_of_birth'])?date('d/m/Y',strtotime($profile['date_of_birth'])):'—';?><?php echo $vAge!==null?' ('.$vAge.' ans)':'';?></div></div>
            <div><div class="lbl">Téléphone</div><div class="val mono"><?php echo htmlspecialchars($profile['phone']??'—');?></div></div>
            <div><div class="lbl">E-mail</div><div class="val"><?php echo htmlspecialchars($profile['email']??'—');?></div></div>
            <div><div class="lbl">Nationalité</div><div class="val"><?php echo htmlspecialchars($profile['nationality']??'—');?></div></div>
            <div style="grid-column:1/-1"><div class="lbl">Adresse de résidence</div><div class="val"><?php echo htmlspecialchars($profile['address']??'—');?></div></div>
        </div>
    </div>
    <div class="cz-stats">
        <?php
        $tiles=[['Mandats',count($warrants),'#fca5a5'],['Rapports',count($cityReports),'#fde68a'],['Casiers',count($casiers),'#fca5a5'],['Véhicules',count($vehicles),'#6ee7b7'],['Armes',count($civArmes),'#c4b5fd'],['Convoc.',count($convocs),'#6ee7b7']];
        foreach($tiles as [$tl,$tn,$tc]):?>
        <div class="cz-stat"><div class="n" style="color:<?php echo $tn>0?$tc:'var(--txt-m)';?>"><?php echo $tn;?></div><div class="t"><?php echo $tl;?></div></div>
        <?php endforeach;?>
    </div>
</div>

<!-- ONGLETS -->
<div class="cz-tabs">
    <button class="cz-tab active" onclick="czTab('civil',this)"><i class="fas fa-id-card"></i> Infos civiles</button>
    <button class="cz-tab" onclick="czTab('reports',this)"><i class="fas fa-file-lines"></i> Rapports <?php if($cityReports):?><span class="cz-count"><?php echo count($cityReports);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('vehicles',this)"><i class="fas fa-car"></i> Véhicules <?php if($vehicles):?><span class="cz-count"><?php echo count($vehicles);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('weapons',this)"><i class="fas fa-gun"></i> Armes <?php if($civArmes):?><span class="cz-count"><?php echo count($civArmes);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('ppa',this)"><i class="fas fa-id-badge"></i> PPA</button>
    <button class="cz-tab" onclick="czTab('convoc',this)"><i class="fas fa-envelope-open-text"></i> Convocations <?php if($convocs):?><span class="cz-count"><?php echo count($convocs);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('bracelet',this)"><i class="fas fa-circle-dot"></i> Bracelet</button>
    <button class="cz-tab" onclick="czTab('notespub',this)"><i class="fas fa-note-sticky"></i> Notes pub. <?php if($notesPub):?><span class="cz-count"><?php echo count($notesPub);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('notespriv',this)"><i class="fas fa-lock"></i> Notes priv. <?php if($notesPriv):?><span class="cz-count"><?php echo count($notesPriv);?></span><?php endif;?></button>
    <button class="cz-tab" onclick="czTab('realestate',this)"><i class="fas fa-house"></i> Immobilier</button>
    <button class="cz-tab" onclick="czTab('judicial',this)"><i class="fas fa-scale-balanced"></i> Historique judiciaire</button>
</div>

<!-- ════ PANEL : INFOS CIVILES ════ -->
<div class="cz-panel active" id="czp-civil">
  <div class="detail-section">
    <div class="cz-sec-h"><i class="fas fa-id-card"></i> Informations civiles</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.55rem">
        <?php $vAge2=pAge($profile['date_of_birth']??null,$profile['age']??null);
        $dobD=!empty($profile['date_of_birth'])?date('d/m/Y',strtotime($profile['date_of_birth'])).($vAge2!==null?' ('.$vAge2.' ans)':''):($vAge2!==null?$vAge2.' ans':'');
        $infos=[['Date de naissance',$dobD,'fa-cake-candles'],['Téléphone',$profile['phone']??'','fa-phone'],['E-mail',$profile['email']??'','fa-envelope'],['IBAN',$profile['iban']??'','fa-building-columns'],['Nationalité',$profile['nationality']??'','fa-flag'],['Métier',$profile['job']??'','fa-briefcase'],['Groupe sanguin',$profile['blood_type']??'','fa-droplet'],['Adresse',$profile['address']??'','fa-map-pin']];
        foreach($infos as [$l,$v,$ico]): if($v===''||$v===null)continue;?>
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .75rem;border:1px solid rgba(255,255,255,.04)">
            <div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem"><i class="fas <?php echo $ico;?>" style="font-size:.6rem;margin-right:.3rem;color:var(--blue-l)"></i><?php echo $l;?></div>
            <div style="font-size:.84rem;color:var(--txt);font-weight:500"><?php echo htmlspecialchars($v);?></div>
        </div>
        <?php endforeach;?>
    </div>
    <div style="margin-top:.85rem"><div style="font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.45rem">Permis</div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php foreach([['license_car','Voiture','fa-car'],['license_ppa','PPA','fa-gun'],['license_boat','Bateau','fa-ship'],['license_plane','Avion','fa-plane']] as [$k,$l,$i]):$has=$profile[$k]??0;?>
        <div style="display:flex;align-items:center;gap:.35rem;padding:.32rem .7rem;border-radius:7px;font-size:.74rem;font-weight:600;background:<?php echo $has?'rgba(16,185,129,.1)':'rgba(107,114,128,.07)';?>;border:1px solid <?php echo $has?'rgba(16,185,129,.3)':'rgba(107,114,128,.12)';?>;color:<?php echo $has?'#6ee7b7':'#475569';?>"><i class="fas <?php echo $i;?>" style="font-size:.65rem"></i><?php echo $has?'✓':'✗';?> <?php echo $l;?></div>
        <?php endforeach;?>
    </div></div>
    <?php if(!empty($tags)):?><div style="margin-top:.85rem"><div style="font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.45rem">Tags</div><div style="display:flex;gap:.3rem;flex-wrap:wrap"><?php foreach($tags as $t):?><span class="badge bg-red" style="font-size:.66rem"><i class="fas fa-tag" style="font-size:.55rem"></i> <?php echo htmlspecialchars($t);?></span><?php endforeach;?></div></div><?php endif;?>
    <?php if(!empty($profile['notes']??'')):?><div style="margin-top:.85rem;padding:.75rem;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);border-radius:9px;font-size:.8rem;color:var(--txt-s);line-height:1.65"><i class="fas fa-sticky-note" style="color:#fde68a;margin-right:.45rem"></i><?php echo nl2br(htmlspecialchars($profile['notes']));?></div><?php endif;?>
  </div>
  <div class="detail-section">
    <div class="cz-sec-h"><i class="fas fa-eye"></i> Description physique</div>
    <?php
    $genderD=$profile['gender']??''; $eyeD=$profile['eye_color']??''; $appD=$profile['appartenance']??'';
    $scarD=(int)($profile['has_scar']??0); $scarLocD=$profile['scar_location']??'';
    $physItems=[
        ['Genre', $genderD!==''?$genderD:'—', 'fa-venus-mars'],
        ['Couleur des yeux', $eyeD!==''?$eyeD:'—', 'fa-eye'],
        ['Appartenance', $appD!==''?$appD:'—', 'fa-people-group'],
        ['Cicatrice', $scarD?'Oui':'Non', 'fa-bandage'],
    ];
    if($scarD && $scarLocD!==''){ $physItems[]=['Localisation cicatrice',$scarLocD,'fa-location-dot']; }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.55rem">
        <?php foreach($physItems as [$l,$v,$ico]):?>
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .75rem;border:1px solid rgba(255,255,255,.04)">
            <div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem"><i class="fas <?php echo $ico;?>" style="font-size:.6rem;margin-right:.3rem;color:var(--blue-l)"></i><?php echo $l;?></div>
            <div style="font-size:.84rem;color:var(--txt);font-weight:500"><?php echo htmlspecialchars($v);?></div>
        </div>
        <?php endforeach;?>
    </div>
  </div>
</div>

<!-- ════ PANEL : RAPPORTS ════ -->
<div class="cz-panel" id="czp-reports">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-file-lines"></i> Rapports (<?php echo count($cityReports);?>)</div>
    <?php if(empty($cityReports)):?><div class="cz-empty"><i class="fas fa-file-lines"></i>Aucun rapport lié à ce citoyen.</div>
    <?php else:foreach($cityReports as $rp):?>
    <a href="reports.php?id=<?php echo (int)$rp['id'];?>" class="cz-row">
        <div style="min-width:0"><div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($rp['title']?:('Rapport #'.$rp['id']));?></div>
        <div style="font-size:.7rem;color:var(--txt-m)"><span class="badge bg-blue" style="font-size:.55rem;margin-right:.3rem"><?php echo htmlspecialchars($rp['type']??'—');?></span><i class="fas fa-user-shield" style="opacity:.5;font-size:.6rem"></i> <?php echo htmlspecialchars($rp['author_disp']??$rp['author']??'—');?></div></div>
        <span style="font-size:.72rem;color:var(--txt-m);flex-shrink:0;display:flex;align-items:center;gap:.5rem"><?php echo !empty($rp['created_at'])?date('d/m/Y',strtotime($rp['created_at'])):'';?><i class="fas fa-chevron-right" style="opacity:.4"></i></span>
    </a>
    <?php endforeach;endif;?>
  </div>
</div>

<!-- ════ PANEL : VÉHICULES ════ -->
<?php
$VEH_ST=['normal'=>['Normal','#6ee7b7','rgba(16,185,129,.12)','rgba(16,185,129,.3)'],'vole'=>['Volé','#fca5a5','rgba(239,68,68,.14)','rgba(239,68,68,.35)'],'recherche'=>['Recherché','#fde68a','rgba(245,158,11,.14)','rgba(245,158,11,.35)'],'suspect'=>['Suspect','#fdba74','rgba(249,115,22,.14)','rgba(249,115,22,.35)'],'epave'=>['Épave','#9ca3af','rgba(107,114,128,.14)','rgba(107,114,128,.3)'],'fourriere'=>['Fourrière','#c4b5fd','rgba(139,92,246,.14)','rgba(139,92,246,.3)'],'signale'=>['Signalé','#6ee7b7','rgba(16,185,129,.14)','rgba(16,185,129,.35)'],'autre'=>['Autre','#9ca3af','rgba(107,114,128,.14)','rgba(107,114,128,.3)']];
?>
<div class="cz-panel" id="czp-vehicles">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-car"></i> Véhicules (<?php echo count($vehicles);?>)</div>
    <?php if(empty($vehicles)):?><div class="cz-empty"><i class="fas fa-car"></i>Aucun véhicule enregistré.</div>
    <?php else:?><div class="cz-grid"><?php foreach($vehicles as $v):
        $vst=$v['status']??'normal'; if(stripos($v['color']??'','[SIGNAL')!==false && $vst==='normal') $vst='signale';
        [$vstL,$vstC,$vstBg,$vstBd]=$VEH_ST[$vst]??$VEH_ST['autre'];
        $vImg=trim($v['image_url']??'');
        $vColorClean=trim(preg_replace('/\s*\[.*?\]/','',$v['color']??''));
        $vData=['id'=>(int)$v['id'],'model'=>$v['model']??'','plate'=>$v['plate']??'','color'=>$vColorClean,'status'=>$vstL,'statusKey'=>$vst,'note'=>$v['status_note']??'','img'=>$vImg,'date'=>!empty($v['created_at'])?date('d/m/Y',strtotime($v['created_at'])):''];
    ?>
    <div class="cz-veh" onclick='openVehDetail(<?php echo json_encode($vData,JSON_HEX_APOS|JSON_HEX_QUOT);?>)' style="background:var(--glass);border:1px solid var(--glass-bdr);border-radius:12px;overflow:hidden;cursor:pointer;transition:border-color .15s,transform .15s">
        <div style="height:120px;background:#020508 center/cover no-repeat;<?php echo $vImg?'background-image:url(\''.htmlspecialchars($vImg).'\')':'';?>;position:relative;display:grid;place-items:center">
            <?php if(!$vImg):?><i class="fas fa-car" style="font-size:2rem;color:var(--txt-m);opacity:.3"></i><?php endif;?>
            <span style="position:absolute;top:.5rem;right:.5rem;padding:.22rem .55rem;border-radius:6px;font-size:.62rem;font-weight:700;color:<?php echo $vstC;?>;background:<?php echo $vstBg;?>;border:1px solid <?php echo $vstBd;?>;backdrop-filter:blur(4px)"><?php echo $vstL;?></span>
        </div>
        <div style="padding:.7rem .85rem">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                <div style="font-weight:700;font-size:.86rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($v['model']??'Véhicule');?></div>
                <span class="mono" style="font-size:.74rem;color:var(--blue-xl);font-weight:700;background:rgba(16,185,129,.1);padding:.15rem .5rem;border-radius:5px;border:1px solid rgba(16,185,129,.2);flex-shrink:0"><?php echo htmlspecialchars($v['plate']??'—');?></span>
            </div>
            <div style="font-size:.7rem;color:var(--txt-m);margin-top:.25rem"><?php echo $vColorClean!==''?htmlspecialchars($vColorClean):'Couleur —';?></div>
            <?php if(!empty($v['status_note'])):?><div style="font-size:.68rem;color:#fca5a5;margin-top:.3rem"><i class="fas fa-triangle-exclamation" style="font-size:.6rem"></i> <?php echo htmlspecialchars($v['status_note']);?></div><?php endif;?>
        </div>
    </div>
    <?php endforeach;?></div><?php endif;?>
  </div>
</div>

<!-- ════ PANEL : ARMES ════ -->
<div class="cz-panel" id="czp-weapons">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-gun"></i> Armes civiles (<?php echo count($civArmes);?>)</div>
    <?php if(empty($civArmes)):?><div class="cz-empty"><i class="fas fa-gun"></i>Aucune arme enregistrée.</div>
    <?php else:foreach($civArmes as $aw):[$al,$ab]=$ARM_ST[$aw['statut']]??[$aw['statut'],'bg-gray'];$aImgs=json_decode($aw['images']??'[]',true)?:[];$aImg=$aImgs[0]??'';?>
    <div class="cz-row" style="cursor:default">
        <div style="display:flex;align-items:center;gap:.7rem">
            <?php if($aImg):?><img src="<?php echo htmlspecialchars($aImg);?>" alt="" onclick="czLightboxSrc('<?php echo htmlspecialchars($aImg);?>')" style="width:46px;height:46px;border-radius:9px;object-fit:cover;cursor:zoom-in;border:1px solid rgba(16,185,129,.25)"><?php endif;?>
            <div><div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($aw['type_arme']);?><?php if(!empty($aw['modele'])):?> · <?php echo htmlspecialchars($aw['modele']);?><?php endif;?></div>
            <div class="mono" style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($aw['numero_serie']??'');?> · <?php echo !empty($aw['date_enregistrement'])?date('d/m/Y',strtotime($aw['date_enregistrement'])):'';?></div></div>
        </div>
        <span class="badge <?php echo $ab;?>" style="font-size:.62rem"><?php echo $al;?></span>
    </div>
    <?php endforeach;endif;?>
  </div>
</div>

<!-- ════ PANEL : PPA ════ -->
<div class="cz-panel" id="czp-ppa">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-id-badge"></i> Permis de port d'arme <span class="badge <?php echo $ppaActive?'bg-green':'bg-gray';?>" style="margin-left:auto;font-size:.62rem"><?php echo $ppaActive?'ACTIF':'INACTIF';?></span></div>
    <?php if(empty($ppaList)):?><div class="cz-empty"><i class="fas fa-id-badge"></i>Aucune demande PPA.</div>
    <?php else:foreach($ppaList as $pp):[$pl,$pb]=$PPA_ST[$pp['statut']]??[$pp['statut'],'bg-gray'];?>
    <div class="cz-row" style="cursor:default">
        <div><div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($pp['type_arme']?:'Demande PPA');?></div>
        <div style="font-size:.7rem;color:var(--txt-m)"><?php echo !empty($pp['date_demande'])?date('d/m/Y',strtotime($pp['date_demande'])):'';?><?php if(!empty($pp['officer'])):?> · <?php echo htmlspecialchars($pp['officer']);?><?php endif;?></div></div>
        <span class="badge <?php echo $pb;?>" style="font-size:.62rem"><?php echo $pl;?></span>
    </div>
    <?php endforeach;endif;?>
    <a href="ppa.php?cid=<?php echo $viewId;?>" class="btn btn-ghost btn-sm" style="margin-top:.5rem"><i class="fas fa-arrow-up-right-from-square"></i> Gérer le PPA</a>
  </div>
</div>

<!-- ════ PANEL : CONVOCATIONS ════ -->
<div class="cz-panel" id="czp-convoc">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-envelope-open-text"></i> Convocations (<?php echo count($convocs);?>)</div>
    <?php if(empty($convocs)):?><div class="cz-empty"><i class="fas fa-envelope-open-text"></i>Aucune convocation.</div>
    <?php else:foreach($convocs as $cv):[$cl,$cb]=$CV_ST[$cv['statut']]??[$cv['statut'],'bg-gray'];?>
    <div class="cz-row" style="cursor:default">
        <div><div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($cv['motif']??$cv['objet']??'Convocation');?></div>
        <div style="font-size:.7rem;color:var(--txt-m)"><i class="fas fa-clock" style="opacity:.5"></i> <?php echo !empty($cv['date_convocation'])?date('d/m/Y H:i',strtotime($cv['date_convocation'])):'—';?><?php if(!empty($cv['agent'])):?> · <?php echo htmlspecialchars($cv['agent']);?><?php endif;?></div></div>
        <span class="badge <?php echo $cb;?>" style="font-size:.62rem"><?php echo $cl;?></span>
    </div>
    <?php endforeach;endif;?>
  </div>
</div>

<!-- ════ PANEL : BRACELET ════ -->
<div class="cz-panel" id="czp-bracelet">
  <div class="detail-section"><div class="cz-sec-h" style="color:rgba(249,115,22,.8)"><i class="fas fa-circle-dot" style="color:#fb923c"></i> Bracelet électronique</div>
    <?php if(empty($bracelets)):?><div class="cz-empty"><i class="fas fa-circle-dot"></i>Aucun bracelet.</div>
    <?php else:foreach($bracelets as $br):[$bl,$bb]=$BR_ST[$br['statut']]??[$br['statut'],'bg-gray'];?>
    <div style="padding:.8rem .95rem;background:var(--glass);border:1px solid <?php echo ($br['statut']??'')==='actif'?'rgba(249,115,22,.3)':'var(--glass-bdr)';?>;border-radius:11px;margin-bottom:.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center"><span class="mono" style="font-weight:700;font-size:.82rem;color:var(--blue-xl)"><?php echo htmlspecialchars($br['numero']??'');?></span><span class="badge <?php echo $bb;?>" style="font-size:.62rem"><?php echo $bl;?></span></div>
        <div style="font-size:.74rem;color:var(--txt-s);margin-top:.4rem">Du <strong><?php echo !empty($br['date_pose'])?date('d/m/Y',strtotime($br['date_pose'])):'—';?></strong> au <strong><?php echo !empty($br['date_fin_prevue'])?date('d/m/Y',strtotime($br['date_fin_prevue'])):'—';?></strong></div>
        <?php if(!empty($br['zone_autorisee'])):?><div style="font-size:.72rem;color:var(--txt-m);margin-top:.25rem"><i class="fas fa-location-dot" style="opacity:.5"></i> <?php echo htmlspecialchars($br['zone_autorisee']);?></div><?php endif;?>
        <?php if(!empty($br['conditions_speciales'])):?><div style="font-size:.72rem;color:var(--txt-m);margin-top:.25rem"><?php echo htmlspecialchars($br['conditions_speciales']);?></div><?php endif;?>
    </div>
    <?php endforeach;endif;?>
  </div>
</div>

<!-- ════ PANEL : NOTES PUBLIQUES ════ -->
<div class="cz-panel" id="czp-notespub">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-note-sticky"></i> Notes publiques (<?php echo count($notesPub);?>)</div>
    <?php
    // Bulle de note style messagerie (avatar + identité RP + date/heure)
    $noteBubble=function($nt) use($uid,$viewId){
        $aname=mdt_rp_display_name($nt['af']??null,$nt['al']??null,(string)($nt['author']??''));
        $av=$nt['aavatar']??''; $avSrc=$av?(preg_match('#^https?://#',$av)?$av:SITE_URL.$av):'';
        $ainit=mdt_rp_initials($nt['af']??null,$nt['al']??null,(string)($nt['author']??'?'));
        $canMod=((int)($nt['author_id']??0)===$uid || hasRole(['*','Administrateur','Développeur','Staff']));
        ob_start(); ?>
        <div class="cz-note">
            <div class="cz-note-av"><?php if($avSrc):?><img src="<?php echo htmlspecialchars($avSrc);?>" alt=""><?php else:?><?php echo htmlspecialchars($ainit);?><?php endif;?></div>
            <div class="cz-note-body">
                <div class="cz-note-head"><span class="cz-note-author"><?php echo htmlspecialchars($aname);?></span><span class="cz-note-time"><i class="fas fa-clock" style="opacity:.4;font-size:.55rem"></i> <?php echo date('d/m/Y',strtotime($nt['created_at']));?> à <?php echo date('H:i',strtotime($nt['created_at']));?></span></div>
                <div class="cz-note-bubble"><?php echo htmlspecialchars($nt['content']);?></div>
            </div>
            <?php if($canMod):?>
            <div class="cz-note-actions">
                <button class="btn btn-ghost btn-xs" onclick='editCitizenNote(<?php echo (int)$nt['id'];?>,<?php echo json_encode($nt['content'],JSON_HEX_APOS|JSON_HEX_QUOT);?>)'><i class="fas fa-pen"></i></button>
                <form method="POST" style="display:inline" data-confirm data-confirm-title="Supprimer la note" data-confirm-ok="Supprimer"><input type="hidden" name="action" value="delete_citizen_note"><input type="hidden" name="note_id" value="<?php echo (int)$nt['id'];?>"><input type="hidden" name="citizen_id" value="<?php echo $viewId;?>"><button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button></form>
            </div>
            <?php endif;?>
        </div>
    <?php return ob_get_clean(); };
    foreach($notesPub as $nt) echo $noteBubble($nt);
    if(empty($notesPub)):?><div class="cz-empty"><i class="fas fa-note-sticky"></i>Aucune note publique.</div><?php endif;?>
    <form method="POST" style="margin-top:.5rem">
        <input type="hidden" name="action" value="add_citizen_note"><input type="hidden" name="visibility" value="public"><input type="hidden" name="citizen_id" value="<?php echo $viewId;?>">
        <textarea name="content" class="form-textarea" rows="2" placeholder="Ajouter une note publique..." required style="margin-bottom:.4rem"></textarea>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </form>
  </div>
</div>

<!-- ════ PANEL : NOTES PRIVÉES ════ -->
<div class="cz-panel" id="czp-notespriv">
  <div class="detail-section"><div class="cz-sec-h" style="color:rgba(139,92,246,.8)"><i class="fas fa-lock" style="color:#c4b5fd"></i> Notes privées (<?php echo count($notesPriv);?>) <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--txt-m);font-size:.7rem">— visibles par vous seul</span></div>
    <?php foreach($notesPriv as $nt) echo $noteBubble($nt); if(empty($notesPriv)):?><div class="cz-empty"><i class="fas fa-lock"></i>Aucune note privée.</div><?php endif;?>
    <form method="POST" style="margin-top:.5rem">
        <input type="hidden" name="action" value="add_citizen_note"><input type="hidden" name="visibility" value="private"><input type="hidden" name="citizen_id" value="<?php echo $viewId;?>">
        <textarea name="content" class="form-textarea" rows="2" placeholder="Ajouter une note privée..." required style="margin-bottom:.4rem"></textarea>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
    </form>
  </div>
</div>

<!-- ════ PANEL : IMMOBILIER ════ -->
<div class="cz-panel" id="czp-realestate">
  <div class="detail-section"><div class="cz-sec-h"><i class="fas fa-house"></i> Biens immobiliers</div>
    <?php
    // Carte immobilière réutilisable
    $reCard=function($titre,$adresse,$quartier,$surface,$pieces,$type,$statutLbl,$statutCls,$lines) {
        $meta=[];
        if($quartier) $meta[]='<i class="fas fa-location-dot" style="opacity:.5"></i> '.htmlspecialchars($quartier);
        if($type)     $meta[]='<i class="fas fa-building" style="opacity:.5"></i> '.htmlspecialchars($type);
        if($surface)  $meta[]='<i class="fas fa-ruler-combined" style="opacity:.5"></i> '.htmlspecialchars($surface).' m²';
        if($pieces)   $meta[]='<i class="fas fa-door-open" style="opacity:.5"></i> '.(int)$pieces.' pièce'.($pieces>1?'s':'');
        echo '<div style="background:var(--glass);border:1px solid var(--glass-bdr);border-radius:12px;padding:.8rem .95rem;margin-bottom:.55rem">';
        echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem"><div style="font-weight:700;font-size:.86rem">'.htmlspecialchars($titre?:'Bien').'</div><span class="badge '.$statutCls.'" style="font-size:.6rem;flex-shrink:0">'.htmlspecialchars($statutLbl).'</span></div>';
        if($adresse) echo '<div style="font-size:.74rem;color:var(--txt-s);margin-top:.15rem">'.htmlspecialchars($adresse).'</div>';
        if($meta) echo '<div style="display:flex;gap:.8rem;flex-wrap:wrap;font-size:.7rem;color:var(--txt-m);margin-top:.4rem">'.implode('',array_map(fn($m)=>'<span>'.$m.'</span>',$meta)).'</div>';
        if($lines) echo '<div style="display:flex;gap:.8rem;flex-wrap:wrap;font-size:.72rem;color:var(--blue-xl);margin-top:.4rem;padding-top:.4rem;border-top:1px solid rgba(16,185,129,.08)">'.implode('',array_map(fn($m)=>'<span>'.$m.'</span>',$lines)).'</div>';
        echo '</div>';
    };
    ?>
    <?php if(!$realLoc && !$realVente):?><div class="cz-empty"><i class="fas fa-house"></i>Aucun bien immobilier trouvé.</div><?php else:?>
    <div class="grid2">
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;color:var(--txt-m);margin-bottom:.5rem"><i class="fas fa-key" style="margin-right:.3rem"></i> Locations</div>
            <?php if(empty($realLoc)):?><div style="color:var(--txt-m);font-size:.8rem">Aucune.</div>
            <?php else:foreach($realLoc as $lo):
                $lines=[];
                if(!empty($lo['date_debut'])) $lines[]='<i class="fas fa-play" style="opacity:.5;font-size:.6rem"></i> dès '.date('d/m/Y',strtotime($lo['date_debut']));
                if(!empty($lo['date_fin']))   $lines[]='<i class="fas fa-flag-checkered" style="opacity:.5;font-size:.6rem"></i> fin '.date('d/m/Y',strtotime($lo['date_fin']));
                if(isset($lo['loyer'])&&$lo['loyer']!=='') $lines[]='<i class="fas fa-coins" style="opacity:.5;font-size:.6rem"></i> $'.number_format((float)$lo['loyer'],0).'/mois';
                $st=$lo['statut']??''; $stc=$st==='actif'?'bg-green':($st==='resilie'?'bg-red':'bg-gray');
                $reCard($lo['bien_titre']??'',$lo['bien_adresse']??'',$lo['bien_quartier']??'',$lo['bien_surface']??'',$lo['bien_pieces']??0,$lo['bien_type']??'','Location · '.ucfirst($st),$stc,$lines);
            endforeach;endif;?>
        </div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;color:var(--txt-m);margin-bottom:.5rem"><i class="fas fa-house-circle-check" style="margin-right:.3rem"></i> Propriétés (achats)</div>
            <?php if(empty($realVente)):?><div style="color:var(--txt-m);font-size:.8rem">Aucune.</div>
            <?php else:foreach($realVente as $ve):
                $lines=[];
                if(isset($ve['prix_negocie'])) $lines[]='<i class="fas fa-tag" style="opacity:.5;font-size:.6rem"></i> $'.number_format((float)$ve['prix_negocie'],0);
                if(!empty($ve['date_vente']))  $lines[]='<i class="fas fa-calendar-check" style="opacity:.5;font-size:.6rem"></i> acquis le '.date('d/m/Y',strtotime($ve['date_vente']));
                $reCard($ve['bien_titre']??'',$ve['bien_adresse']??'',$ve['bien_quartier']??'',$ve['bien_surface']??'',$ve['bien_pieces']??0,$ve['bien_type']??'','Propriété',('bg-green'),$lines);
            endforeach;endif;?>
        </div>
    </div>
    <?php endif;?>
  </div>
</div>

<!-- ════ PANEL : HISTORIQUE JUDICIAIRE ════ -->
<div class="cz-panel" id="czp-judicial">
  <div class="grid2">
    <div class="detail-section" style="<?php echo $nbMandAct>0?'border-color:rgba(239,68,68,.25)':'';?>">
        <div class="cz-sec-h" style="color:rgba(239,68,68,.75)"><i class="fas fa-crosshairs" style="color:#fca5a5"></i> Mandats & avis de recherche (<?php echo count($warrants);?>)</div>
        <?php if(empty($warrants)):?><div class="cz-empty"><i class="fas fa-crosshairs"></i>Aucun mandat.</div>
        <?php else:foreach($warrants as $w):$sc=$WR_ST[$w['status']]??'bg-gray';?>
        <div style="background:rgba(239,68,68,.04);border:1px solid rgba(239,68,68,.15);border-radius:10px;padding:.7rem .85rem;margin-bottom:.45rem">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem"><span style="font-weight:700;font-size:.84rem;color:<?php echo $w['status']==='active'?'#fca5a5':'var(--txt)';?>"><?php echo htmlspecialchars($w['offense_title']??'Mandat');?></span><span class="badge <?php echo $sc;?>" style="font-size:.6rem"><?php echo strtoupper($w['status']);?></span></div>
            <div style="font-size:.7rem;color:var(--txt-s);margin-top:.25rem"><?php echo ucfirst($w['warrant_type']??'');?> · <?php echo htmlspecialchars($w['officer']??'—');?> · <?php echo !empty($w['issued_date'])?date('d/m/Y',strtotime($w['issued_date'])):'';?></div>
        </div>
        <?php endforeach;endif;?>
    </div>
    <div class="detail-section">
        <div class="cz-sec-h" style="color:rgba(239,68,68,.75)"><i class="fas fa-gavel" style="color:#fca5a5"></i> Casier judiciaire (<?php echo count($casiers);?>)</div>
        <?php if(empty($casiers)):?><div class="cz-empty"><i class="fas fa-circle-check" style="color:#6ee7b7;opacity:.4"></i>Casier vierge.</div>
        <?php else:foreach($casiers as $c):$sc=$CR_ST[$c['status']]??'bg-gray';?>
        <div style="background:rgba(0,0,0,.2);border:1px solid rgba(239,68,68,.12);border-radius:10px;padding:.7rem .85rem;margin-bottom:.45rem">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem"><span style="font-weight:700;font-size:.84rem"><?php echo htmlspecialchars($c['offense']??$c['offense_title']??'—');?></span><span class="badge <?php echo $sc;?>" style="font-size:.6rem"><?php echo strtoupper($c['status']);?></span></div>
            <div style="font-size:.7rem;color:var(--txt-s);margin-top:.25rem"><?php echo htmlspecialchars($c['officer']??'—');?> · <?php echo !empty($c['created_at'])?date('d/m/Y',strtotime($c['created_at'])):'';?></div>
        </div>
        <?php endforeach;endif;?>
    </div>
  </div>
  <div class="grid2">
    <div class="detail-section">
        <div class="cz-sec-h"><i class="fas fa-scale-balanced"></i> Procès (<?php echo count($procs);?>)</div>
        <?php if(empty($procs)):?><div class="cz-empty"><i class="fas fa-scale-balanced"></i>Aucun procès.</div>
        <?php else:foreach($procs as $pc):[$cl,$cb]=$CC_ST[$pc['status']]??[$pc['status'],'bg-gray'];?>
        <div class="cz-row" style="cursor:default"><div><div style="font-weight:700;font-size:.82rem"><?php echo htmlspecialchars($pc['case_title']?:$pc['case_number']);?></div><div class="mono" style="font-size:.66rem;color:var(--txt-m)"><?php echo htmlspecialchars($pc['case_number']);?><?php if(!empty($pc['verdict'])):?> · <?php echo htmlspecialchars($pc['verdict']);?><?php endif;?></div></div><span class="badge <?php echo $cb;?>" style="font-size:.58rem"><?php echo $cl;?></span></div>
        <?php endforeach;endif;?>
    </div>
    <div class="detail-section">
        <div class="cz-sec-h"><i class="fas fa-handcuffs"></i> Sentences (<?php echo count($sents);?>)</div>
        <?php if(empty($sents)):?><div class="cz-empty"><i class="fas fa-handcuffs"></i>Aucune sentence.</div>
        <?php else:foreach($sents as $se):[$sl,$sb]=$SE_ST[$se['status']]??[$se['status'],'bg-gray'];?>
        <div class="cz-row" style="cursor:default"><div><div style="font-weight:700;font-size:.82rem"><?php echo htmlspecialchars($se['sentence_type']??'Sentence');?></div><div style="font-size:.68rem;color:var(--txt-m)"><?php if(!empty($se['duration_months'])):?><?php echo (int)$se['duration_months'];?> mois<?php endif;?><?php if(!empty($se['duration_hours'])):?> · <?php echo (int)$se['duration_hours'];?> h<?php endif;?><?php if(!empty($se['fine_amount'])):?> · $<?php echo number_format((float)$se['fine_amount'],0);?><?php endif;?></div></div><span class="badge <?php echo $sb;?>" style="font-size:.58rem"><?php echo $sl;?></span></div>
        <?php endforeach;endif;?>
    </div>
  </div>
</div>

<!-- LIGHTBOX AVATAR / IMAGES -->
<div class="cz-lightbox" id="czLightbox" onclick="czAvatarClose()">
    <button class="cz-lb-close" onclick="czAvatarClose()"><i class="fas fa-times"></i></button>
    <img id="czLightboxImg" src="<?php echo htmlspecialchars($avatarSrc);?>" alt="" onclick="event.stopPropagation()">
    <div class="cz-lb-cap">Photo officielle — <?php echo htmlspecialchars(trim(($profile['firstname']??'').' '.($profile['lastname']??'')));?></div>
</div>

<!-- MODAL DÉTAIL VÉHICULE -->
<div id="vehDetailModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-car" style="color:var(--blue-l)"></i> <span id="vd-title">Véhicule</span></h2><button class="modal-cl" onclick="LSSD.closeModal('vehDetailModal')">×</button></div>
<div class="modal-bd">
    <div id="vd-img" style="height:180px;border-radius:11px;background:#020508 center/cover no-repeat;display:grid;place-items:center;margin-bottom:1rem"><i class="fas fa-car" style="font-size:2.4rem;color:var(--txt-m);opacity:.3"></i></div>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem">
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .8rem"><div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em">Modèle</div><div id="vd-model" style="font-weight:600;font-size:.86rem;margin-top:.15rem">—</div></div>
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .8rem"><div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em">Plaque</div><div id="vd-plate" class="mono" style="font-weight:700;font-size:.86rem;margin-top:.15rem;color:var(--blue-xl)">—</div></div>
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .8rem"><div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em">Couleur</div><div id="vd-color" style="font-weight:600;font-size:.86rem;margin-top:.15rem">—</div></div>
        <div style="background:rgba(0,0,0,.2);border-radius:9px;padding:.6rem .8rem"><div style="font-size:.58rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em">Statut</div><div id="vd-status" style="margin-top:.25rem"></div></div>
    </div>
    <div id="vd-note-wrap" style="margin-top:.8rem;padding:.7rem .9rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:9px;font-size:.82rem;color:#fca5a5;display:none"><i class="fas fa-triangle-exclamation" style="margin-right:.4rem"></i><span id="vd-note"></span></div>
</div>
<div class="modal-ft"><a id="vd-link" href="#" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-up-right-from-square"></i> Ouvrir dans Véhicules</a><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('vehDetailModal')">Fermer</button></div>
</div></div>

<!-- MODAL EDIT NOTE -->
<div id="editNoteModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier la note</h2><button class="modal-cl" onclick="LSSD.closeModal('editNoteModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="edit_citizen_note"><input type="hidden" name="note_id" id="enNoteId"><input type="hidden" name="citizen_id" value="<?php echo $viewId;?>">
    <div class="form-group"><label class="form-label">Contenu</label><textarea name="content" id="enContent" class="form-textarea" rows="4" required></textarea></div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('editNoteModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
const CZ_AVATAR = <?php echo json_encode($avatarSrc?:'');?>;
function czTab(id, btn){ document.querySelectorAll('.cz-tab').forEach(t=>t.classList.remove('active')); btn.classList.add('active'); document.querySelectorAll('.cz-panel').forEach(p=>p.classList.toggle('active',p.id==='czp-'+id)); }
function czAvatarOpen(){ if(!CZ_AVATAR) return; document.getElementById('czLightboxImg').src=CZ_AVATAR; document.getElementById('czLightbox').classList.add('open'); document.body.style.overflow='hidden'; }
function czLightboxSrc(src){ const lb=document.getElementById('czLightbox'); document.getElementById('czLightboxImg').src=src; lb.classList.add('open'); document.body.style.overflow='hidden'; }
function czAvatarClose(){ document.getElementById('czLightbox').classList.remove('open'); document.body.style.overflow=''; }
function editCitizenNote(id, content){ document.getElementById('enNoteId').value=id; document.getElementById('enContent').value=content; LSSD.openModal('editNoteModal'); }
const VEH_STJS={normal:['Normal','#6ee7b7','rgba(16,185,129,.14)','rgba(16,185,129,.3)'],vole:['Volé','#fca5a5','rgba(239,68,68,.14)','rgba(239,68,68,.35)'],recherche:['Recherché','#fde68a','rgba(245,158,11,.14)','rgba(245,158,11,.35)'],suspect:['Suspect','#fdba74','rgba(249,115,22,.14)','rgba(249,115,22,.35)'],epave:['Épave','#9ca3af','rgba(107,114,128,.14)','rgba(107,114,128,.3)'],fourriere:['Fourrière','#c4b5fd','rgba(139,92,246,.14)','rgba(139,92,246,.3)'],signale:['Signalé','#6ee7b7','rgba(16,185,129,.14)','rgba(16,185,129,.35)'],autre:['Autre','#9ca3af','rgba(107,114,128,.14)','rgba(107,114,128,.3)']};
function czEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function openVehDetail(v){
    document.getElementById('vd-title').textContent=v.model||'Véhicule';
    document.getElementById('vd-model').textContent=v.model||'—';
    document.getElementById('vd-plate').textContent=v.plate||'—';
    document.getElementById('vd-color').textContent=v.color||'—';
    const img=document.getElementById('vd-img');
    if(v.img){ img.style.backgroundImage="url('"+v.img+"')"; img.innerHTML=''; } else { img.style.backgroundImage=''; img.innerHTML='<i class="fas fa-car" style="font-size:2.4rem;color:var(--txt-m);opacity:.3"></i>'; }
    const st=VEH_STJS[v.statusKey]||VEH_STJS.autre;
    document.getElementById('vd-status').innerHTML='<span style="display:inline-flex;align-items:center;padding:.22rem .6rem;border-radius:6px;font-size:.7rem;font-weight:700;color:'+st[1]+';background:'+st[2]+';border:1px solid '+st[3]+'">'+czEsc(v.status||st[0])+'</span>';
    const nw=document.getElementById('vd-note-wrap');
    if(v.note){ nw.style.display='block'; document.getElementById('vd-note').textContent=v.note; } else nw.style.display='none';
    document.getElementById('vd-link').href='vehicles.php?q='+encodeURIComponent(v.plate||'');
    LSSD.openModal('vehDetailModal');
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') czAvatarClose(); });
</script>


<!-- MODAL EDIT -->
<div id="editModal" class="modal-ov hidden">
<div class="modal modal-lg">
<div class="modal-hd" style="background:rgba(0,0,0,.3);border-bottom:1px solid rgba(16,185,129,.2)">
    <h2><i class="fas fa-pen" style="color:var(--blue-l)"></i> Modifier le profil</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('editModal')">×</button>
</div>
<form method="POST"><div class="modal-bd">
    <div style="position:absolute;inset:0;background-image:radial-gradient(rgba(16,185,129,.04) 1px,transparent 1px);background-size:18px 18px;pointer-events:none;z-index:0"></div>
    <div style="position:relative;z-index:1">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="pid" value="<?php echo $profile['id'];?>">

    <!-- ── AVATAR SECTION ── -->
    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-image" style="color:var(--blue-l)"></i> Photo du personnage</div>
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
        <div id="editAvatarPreview" style="width:64px;height:64px;border-radius:14px;overflow:hidden;flex-shrink:0;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:rgba(16,185,129,.5)">
            <?php if($hasAvatar):?><img src="<?php echo htmlspecialchars($profile['avatar']);?>" style="width:100%;height:100%;object-fit:cover"><?php else:?><i class="fas fa-image"></i><?php endif;?>
        </div>
        <div style="flex:1">
            <label class="form-label" style="margin-bottom:.4rem"><i class="fas fa-link" style="color:var(--blue-l)"></i> Lien de l'image (URL)</label>
            <input type="url" name="avatar_url" id="editAvatarUrl" class="form-input"
                   placeholder="https://exemple.com/photo.jpg"
                   value="<?php echo htmlspecialchars($profile['avatar']??'');?>"
                   oninput="previewAvatarUrl(this.value,'editAvatarPreview')">
            <div style="font-size:.68rem;color:var(--txt-m);margin-top:.3rem">Coller le lien direct d'une image. Laisser vide pour supprimer la photo.</div>
        </div>
    </div>

    <div class="form-grid">
        <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="firstname" class="form-input" required value="<?php echo htmlspecialchars($profile['firstname']);?>"></div>
        <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="lastname" class="form-input" required value="<?php echo htmlspecialchars($profile['lastname']);?>"></div>
        <div class="form-group"><label class="form-label">Date de naissance</label><input type="date" name="date_of_birth" class="form-input" max="<?php echo date('Y-m-d');?>" value="<?php echo htmlspecialchars($profile['date_of_birth']??'');?>" oninput="updAgePreview(this,'editAgePreview')"><div id="editAgePreview" style="font-size:.68rem;color:var(--txt-m);margin-top:.2rem"><?php $ea=pAge($profile['date_of_birth']??null,$profile['age']??null); echo $ea!==null?$ea.' ans':''; ?></div></div>
        <div class="form-group"><label class="form-label">Groupe sanguin</label><select name="blood_type" class="form-select"><option value="">—</option><?php foreach($BLOOD as $b):?><option value="<?php echo $b;?>" <?php echo ($profile['blood_type']??'')===$b?'selected':'';?>><?php echo $b;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Nationalité</label><input type="text" name="nationality" class="form-input" value="<?php echo htmlspecialchars($profile['nationality']??'');?>"></div>
        <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="phone" class="form-input" value="<?php echo htmlspecialchars($profile['phone']??'');?>"></div>
        <div class="form-group"><label class="form-label">Adresse e-mail</label><input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($profile['email']??'');?>"></div>
        <div class="form-group"><label class="form-label">IBAN</label><input type="text" name="iban" class="form-input" value="<?php echo htmlspecialchars($profile['iban']??'');?>"></div>
        <div class="form-group"><label class="form-label">Métier</label><input type="text" name="job" class="form-input" value="<?php echo htmlspecialchars($profile['job']??'');?>"></div>
        <div class="form-group form-full"><label class="form-label">Adresse</label><input type="text" name="address" class="form-input" value="<?php echo htmlspecialchars($profile['address']??'');?>"></div>
        <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea"><?php echo htmlspecialchars($profile['notes']??'');?></textarea></div>
    </div>
    <?php
    $eGender=$profile['gender']??'';
    $eEye=$profile['eye_color']??'';
    $eEyeIsListed=($eEye!=='' && in_array($eEye,$EYE_COLORS,true) && $eEye!=='Autre');
    $eEyeSel=$eEye==='' ? '' : ($eEyeIsListed ? $eEye : 'Autre');
    $eEyeOther=($eEye!=='' && !$eEyeIsListed) ? $eEye : '';
    $eScar=(int)($profile['has_scar']??0);
    $eScarLoc=$profile['scar_location']??'';
    ?>
    <div class="field-section"><i class="fas fa-eye"></i> Description physique</div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Genre *</label>
            <div class="pd-seg" id="eGenderSeg">
                <label class="pd-opt <?php echo $eGender==='Homme'?'on':'';?>" onclick="pdPick(this)"><input type="radio" name="gender" value="Homme" <?php echo $eGender==='Homme'?'checked':'';?>><i class="fas fa-mars"></i> Homme</label>
                <label class="pd-opt <?php echo $eGender==='Femme'?'on':'';?>" onclick="pdPick(this)"><input type="radio" name="gender" value="Femme" <?php echo $eGender==='Femme'?'checked':'';?>><i class="fas fa-venus"></i> Femme</label>
            </div>
        </div>
        <div class="form-group"><label class="form-label">Couleur des yeux</label>
            <select name="eye_color" class="form-select" onchange="pdEye(this,'eEyeOther')"><option value="">—</option><?php foreach($EYE_COLORS as $eco):?><option value="<?php echo $eco;?>" <?php echo $eEyeSel===$eco?'selected':'';?>><?php echo $eco;?></option><?php endforeach;?></select>
            <div class="pd-reveal <?php echo $eEyeSel==='Autre'?'show':'';?>" id="eEyeOther"><input type="text" name="eye_color_other" class="form-input" placeholder="Préciser la couleur..." value="<?php echo htmlspecialchars($eEyeOther);?>"></div>
        </div>
        <div class="form-group form-full"><label class="form-label">Appartenance</label><input type="text" name="appartenance" class="form-input" value="<?php echo htmlspecialchars($profile['appartenance']??'');?>" placeholder="Famille Moretti, Ballas, Vagos, Gouvernement, Aucun..."></div>
        <div class="form-group form-full"><label class="form-label">Présence de cicatrice</label>
            <div class="pd-seg" id="eScarSeg">
                <label class="pd-opt <?php echo $eScar?'':'on';?>" onclick="pdPick(this);pdScar('e')"><input type="radio" name="has_scar" value="0" <?php echo $eScar?'':'checked';?>> Non</label>
                <label class="pd-opt <?php echo $eScar?'on':'';?>" onclick="pdPick(this);pdScar('e')"><input type="radio" name="has_scar" value="1" <?php echo $eScar?'checked':'';?>> Oui</label>
            </div>
            <div class="pd-reveal <?php echo $eScar?'show':'';?>" id="eScarLoc"><label class="form-label" style="margin-bottom:.3rem"><i class="fas fa-location-dot" style="color:var(--blue-l);margin-right:.3rem"></i>Sur quelle partie du corps ?</label><input type="text" name="scar_location" class="form-input" value="<?php echo htmlspecialchars($eScarLoc);?>" placeholder="Visage, Bras gauche, Torse, Dos, Jambe droite..."></div>
        </div>
    </div>
    <div class="field-section"><i class="fas fa-id-card"></i> Permis</div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:.85rem">
        <?php foreach([['license_car','Voiture','fa-car'],['license_ppa','PPA','fa-gun'],['license_boat','Bateau','fa-ship'],['license_plane','Avion','fa-plane']] as [$k,$l,$i]):$has=$profile[$k]??0;?>
        <label class="license-toggle <?php echo $has?'active':'';?>" id="elt_<?php echo $k;?>"><input type="checkbox" name="<?php echo $k;?>" value="1" <?php echo $has?'checked':'';?> onchange="document.getElementById('elt_<?php echo $k;?>').classList.toggle('active',this.checked)"><div class="lt-dot"><i class="fas <?php echo $i;?>"></i></div><?php echo $l;?></label>
        <?php endforeach;?>
    </div>
    <div class="field-section"><i class="fas fa-tags"></i> Tags</div>
    <div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.55rem" id="editTagsCont">
        <?php $currentTags=json_decode($profile['tags']??'[]',true)?:[];
        foreach($COMMON_TAGS as $t):?><div class="ptag-chip <?php echo in_array($t,$currentTags)?'sel':'';?>" onclick="ptogTag(this,'<?php echo addslashes($t);?>')"><?php echo htmlspecialchars($t);?></div><?php endforeach;?>
        <?php foreach(array_diff($currentTags,$COMMON_TAGS) as $t):?><div class="ptag-chip sel" title="Cliquer pour retirer" onclick="removeCustomTag(this,'<?php echo addslashes($t);?>','edit')"><?php echo htmlspecialchars($t);?> <i class="fas fa-times"></i></div><?php endforeach;?>
    </div>
    <div style="display:flex;gap:.4rem"><input type="text" id="editCustomTag" class="form-input" placeholder="Tag personnalisé..." style="flex:1" onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomTag('edit');}"><button type="button" onclick="addCustomTag('edit')" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i></button></div>
    <input type="hidden" name="tags_json" id="editTagsJson" value="<?php echo htmlspecialchars(json_encode($currentTags));?>">
    </div>
</div><div class="modal-ft" style="background:rgba(0,0,0,.3)"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('editModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Sauvegarder</button></div>
</form></div></div>

<?php else: // ── LISTE ────────────────────────────────────────────────────────
$total=count($profiles??[]);
?>
<!-- TOOLBAR -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap;align-items:center">
        <div style="flex:1;min-width:220px;position:relative">
            <i class="fas fa-search" style="position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.78rem;pointer-events:none"></i>
            <input type="text" name="q" class="form-input" placeholder="Nom, prénom, téléphone, IBAN..." value="<?php echo htmlspecialchars($search);?>" style="padding-left:2.3rem">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search):?><a href="profils.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <div style="display:flex;align-items:center;gap:.6rem">
        <span style="font-size:.78rem;color:var(--txt-m)"><?php echo $total;?> profil(s)</span>
        <div class="view-toggle" id="viewToggle">
            <button class="vt-btn active" id="btn-cards" onclick="setView('cards')" title="Vue cartes"><i class="fas fa-grip"></i></button>
            <button class="vt-btn" id="btn-table" onclick="setView('table')" title="Vue tableau"><i class="fas fa-table-list"></i></button>
        </div>
        <button onclick="LSSD.openModal('createModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter un citoyen</button>
        <?php if(hasRole(['*','Administrateur','Développeur'])):?>
        <button onclick="LSSD.openModal('purgeProfilesModal')" class="btn btn-danger btn-sm" title="Supprimer tous les profils"><i class="fas fa-trash-can"></i> Purge</button>
        <?php endif;?>
    </div>
</div>

<!-- VUE CARTES -->
<div id="view-cards">
<?php if(empty($profiles??[])):?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)"><i class="fas fa-users" style="font-size:3rem;opacity:.15;display:block;margin-bottom:.75rem"></i>Aucun profil</div>
<?php else:?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1rem">
<?php foreach($profiles as $p):
    $pInit=strtoupper(substr($p['firstname'],0,1));
    $isWanted=$p['nb_mandat']>0;
    $gradMap2=['A'=>'#059669','B'=>'#10b981','C'=>'#8b5cf6','D'=>'#f59e0b','E'=>'#ec4899','F'=>'#06b6d4'];
    $pColor=$gradMap2[$pInit]??'#475569';
    $hasAv=!empty($p['avatar']);
?>
<div class="pcard <?php echo $isWanted?'wanted':'';?>" onclick="location.href='profils.php?id=<?php echo $p['id'];?>'">
    <div style="display:flex;align-items:flex-start;gap:.85rem;margin-bottom:.8rem">
        <!-- Avatar avec photo si disponible -->
        <div class="pcard-av" style="background:<?php echo $pColor;?>22;border-color:<?php echo $pColor;?>44;color:<?php echo $pColor;?>">
            <?php if($hasAv):?><img src="<?php echo htmlspecialchars($p['avatar']);?>" alt=""><?php else:?><?php echo $pInit;?><?php endif;?>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:.9rem;margin-bottom:.12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></div>
            <div style="font-size:.72rem;color:var(--txt-m)"><?php $pa=pAge($p['date_of_birth']??null,$p['age']??null); echo ($pa!==null?$pa.' ans':'—');?><?php if($p['job']??''):?> · <?php echo htmlspecialchars(mb_substr($p['job'],0,20));?><?php endif;?></div>
        </div>
        <?php if($p['blood_type']??''):?><span class="badge bg-red" style="font-size:.63rem;flex-shrink:0"><?php echo $p['blood_type'];?></span><?php endif;?>
    </div>
    <?php $cardTags=json_decode($p['tags']??'[]',true)?:[]; if($cardTags):?>
    <div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.55rem">
        <?php foreach(array_slice($cardTags,0,4) as $ct):?><span class="badge bg-red" style="font-size:.58rem"><i class="fas fa-tag" style="font-size:.5rem"></i> <?php echo htmlspecialchars($ct);?></span><?php endforeach;?>
        <?php if(count($cardTags)>4):?><span style="font-size:.62rem;color:var(--txt-m)">+<?php echo count($cardTags)-4;?></span><?php endif;?>
    </div>
    <?php endif;?>
    <div style="display:flex;gap:.4rem;margin-bottom:.65rem">
        <?php if($p['nb_casiers']>0):?><span class="badge bg-red" style="font-size:.62rem"><i class="fas fa-gavel" style="font-size:.55rem"></i> <?php echo $p['nb_casiers'];?> casier(s)</span><?php endif;?>
        <?php if($p['nb_mandat']>0):?><span class="badge bg-yellow" style="font-size:.62rem"><i class="fas fa-crosshairs" style="font-size:.55rem"></i> <?php echo $p['nb_mandat'];?> mandat(s)</span><?php endif;?>
        <?php if(!$p['nb_casiers']&&!$p['nb_mandat']):?><span style="font-size:.68rem;color:var(--txt-m)"><i class="fas fa-check" style="color:#6ee7b7;margin-right:.25rem"></i>Dossier vierge</span><?php endif;?>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;padding-top:.6rem;border-top:1px solid rgba(255,255,255,.04)">
        <span class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($p['phone']??'—');?></span>
        <div style="display:flex;gap:.2rem">
            <?php foreach([['license_car','V'],['license_ppa','P'],['license_boat','B'],['license_plane','A']] as [$k,$l]):if($p[$k]??0):?><span style="width:18px;height:18px;border-radius:4px;background:rgba(16,185,129,.15);color:#6ee7b7;display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:700;border:1px solid rgba(16,185,129,.2)"><?php echo $l;?></span><?php endif;endforeach;?>
        </div>
    </div>
</div>
<?php endforeach;?>
</div><?php endif;?>
</div>

<!-- VUE TABLE -->
<div id="view-table" style="display:none">
<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Photo</th><th>Identité</th><th>Âge</th><th>Téléphone</th><th>Métier</th><th>Permis</th><th>Casiers</th><th>Mandats</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($profiles??[])):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun profil</td></tr>
    <?php else:foreach($profiles as $p):
        $pInit=strtoupper(substr($p['firstname'],0,1));
        $gradMap2=['A'=>'#059669','B'=>'#10b981','C'=>'#8b5cf6','D'=>'#f59e0b','E'=>'#ec4899','F'=>'#06b6d4'];
        $pColor=$gradMap2[$pInit]??'#475569';
        $hasAv=!empty($p['avatar']);
    ?>
    <tr style="cursor:pointer" onclick="location.href='profils.php?id=<?php echo $p['id'];?>'">
        <td><div class="pcard-av" style="background:<?php echo $pColor;?>22;border-color:<?php echo $pColor;?>44;color:<?php echo $pColor;?>;width:36px;height:36px;border-radius:9px;font-size:.9rem"><?php if($hasAv):?><img src="<?php echo htmlspecialchars($p['avatar']);?>" alt=""><?php else:?><?php echo $pInit;?><?php endif;?></div></td>
        <td><div style="font-weight:700"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></div></td>
        <td><?php $pa=pAge($p['date_of_birth']??null,$p['age']??null); echo $pa!==null?$pa:'—';?></td>
        <td class="mono" style="font-size:.75rem"><?php echo htmlspecialchars($p['phone']??'—');?></td>
        <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars(mb_substr($p['job']??'—',0,25));?></td>
        <td style="font-size:.7rem"><?php echo ($p['license_car']??0)?'<span style="color:#6ee7b7;font-weight:700">V</span> ':'';?><?php echo ($p['license_ppa']??0)?'<span style="color:#6ee7b7;font-weight:700">P</span>':'';?></td>
        <td><?php if($p['nb_casiers']>0):?><span class="badge bg-red" style="font-size:.65rem"><?php echo $p['nb_casiers'];?></span><?php else:?>—<?php endif;?></td>
        <td><?php if($p['nb_mandat']>0):?><span class="badge bg-yellow" style="font-size:.65rem"><?php echo $p['nb_mandat'];?></span><?php else:?>—<?php endif;?></td>
        <td><a href="profils.php?id=<?php echo $p['id'];?>" class="btn btn-ghost btn-xs" onclick="event.stopPropagation()"><i class="fas fa-folder-open"></i></a></td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
</table></div>
</div>

<?php if(hasRole(['*','Administrateur','Développeur'])):?>
<div id="purgeProfilesModal" class="modal-ov hidden">
<div class="modal modal-sm">
<div class="modal-hd" style="background:rgba(239,68,68,.08);border-bottom:1px solid rgba(239,68,68,.25)">
    <h2 style="color:#f87171"><i class="fas fa-trash-can" style="color:#ef4444"></i> Purge des Profils Civils</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('purgeProfilesModal')">×</button>
</div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="purge_profiles">
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:1rem;margin-bottom:1.1rem">
        <p style="font-size:.82rem;color:var(--txt-s);line-height:1.6">Action <strong style="color:#f87171">irréversible</strong> — supprime tous les profils civils et leurs données liées.</p>
    </div>
    <div class="form-group">
        <label class="form-label">Tapez <strong style="color:#f87171;font-family:'IBM Plex Mono',monospace">PURGE</strong> pour confirmer</label>
        <input type="text" name="confirm_word" id="purgeProfilesInput" class="form-input" placeholder="PURGE" autocomplete="off" style="border-color:rgba(239,68,68,.3);letter-spacing:.08em;font-family:'IBM Plex Mono',monospace" oninput="document.getElementById('purgeProfilesBtn').disabled=this.value!=='PURGE'">
    </div>
</div>
<div class="modal-ft" style="background:rgba(0,0,0,.25)">
    <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('purgeProfilesModal')">Annuler</button>
    <button type="submit" id="purgeProfilesBtn" class="btn btn-danger" disabled><i class="fas fa-trash-can"></i> Supprimer tous les profils</button>
</div>
</form></div></div>
<?php endif;?>

<!-- MODAL CRÉATION -->
<div id="createModal" class="modal-ov hidden">
<div class="modal modal-lg">
<div class="modal-hd" style="background:rgba(0,0,0,.3);border-bottom:1px solid rgba(16,185,129,.2)">
    <h2 style="font-family:'Syne',serif"><i class="fas fa-user-plus" style="color:var(--blue-l)"></i> Ajouter un citoyen</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('createModal')">×</button>
</div>
<form method="POST" onsubmit="return pdCheckGender(this)"><div class="modal-bd" style="position:relative">
    <div style="position:absolute;inset:0;background-image:radial-gradient(rgba(16,185,129,.04) 1px,transparent 1px);background-size:18px 18px;pointer-events:none;z-index:0"></div>
    <div style="position:relative;z-index:1">
    <input type="hidden" name="action" value="create">

    <!-- AVATAR URL -->
    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-image" style="color:var(--blue-l)"></i> Photo du personnage <span style="font-weight:400;color:var(--txt-m);text-transform:none;letter-spacing:0">(optionnel)</span></div>
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.1rem">
        <div id="createAvatarPreview" style="width:64px;height:64px;border-radius:14px;overflow:hidden;flex-shrink:0;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:rgba(16,185,129,.5)">
            <i class="fas fa-image"></i>
        </div>
        <div style="flex:1">
            <label class="form-label" style="margin-bottom:.4rem"><i class="fas fa-link" style="color:var(--blue-l)"></i> Lien de l'image (URL)</label>
            <input type="url" name="avatar_url" id="createAvatarUrl" class="form-input"
                   placeholder="https://exemple.com/photo.jpg"
                   oninput="previewAvatarUrl(this.value,'createAvatarPreview')">
            <div style="font-size:.68rem;color:var(--txt-m);margin-top:.3rem">Coller le lien direct d'une image. L'aperçu se met à jour automatiquement.</div>
        </div>
    </div>

    <!-- Aperçu initiales (fallback) -->
    <div style="text-align:center;margin-bottom:1.1rem">
        <div id="createAvatar" style="width:52px;height:52px;border-radius:13px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(0,0,0,.4));color:#f9fafb;display:inline-flex;align-items:center;justify-content:center;font-family:'Syne',serif;font-weight:800;font-size:1.3rem;border:2px solid rgba(16,185,129,.3)">?</div>
        <div style="font-size:.68rem;color:var(--txt-m);margin-top:.25rem">Aperçu initiales</div>
    </div>

    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-user"></i> Identité</div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="firstname" class="form-input" required placeholder="John" oninput="updateAvatar(this.value)"></div>
        <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="lastname" class="form-input" required placeholder="DOE"></div>
        <div class="form-group"><label class="form-label">Date de naissance</label><input type="date" name="date_of_birth" class="form-input" max="<?php echo date('Y-m-d');?>" oninput="updAgePreview(this,'createAgePreview')"><div id="createAgePreview" style="font-size:.68rem;color:var(--txt-m);margin-top:.2rem"></div></div>
        <div class="form-group"><label class="form-label">Groupe sanguin</label><select name="blood_type" class="form-select"><option value="">—</option><?php foreach($BLOOD as $b):?><option value="<?php echo $b;?>"><?php echo $b;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Nationalité</label><input type="text" name="nationality" class="form-input" placeholder="Américain(e)"></div>
        <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="phone" class="form-input" placeholder="555-0001"></div>
        <div class="form-group"><label class="form-label">Adresse e-mail</label><input type="email" name="email" class="form-input" placeholder="john.doe@email.com"></div>
        <div class="form-group"><label class="form-label">IBAN</label><input type="text" name="iban" class="form-input" placeholder="LS..."></div>
        <div class="form-group"><label class="form-label">Métier déclaré</label><input type="text" name="job" class="form-input" placeholder="Mécanicien, Sans emploi..."></div>
        <div class="form-group form-full"><label class="form-label">Adresse</label><input type="text" name="address" class="form-input" placeholder="123 Main St, Los Santos"></div>
        <div class="form-group form-full"><label class="form-label">Notes initiales</label><textarea name="notes" class="form-textarea" style="min-height:65px" placeholder="Observations lors du premier contact..."></textarea></div>
    </div>
    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);margin:.85rem 0 .65rem;display:flex;align-items:center;gap:.4rem;border-top:1px solid rgba(16,185,129,.1);padding-top:.75rem"><i class="fas fa-eye" style="color:var(--blue-l)"></i> Description physique</div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Genre *</label>
            <div class="pd-seg" id="cGenderSeg">
                <label class="pd-opt" onclick="pdPick(this)"><input type="radio" name="gender" value="Homme"><i class="fas fa-mars"></i> Homme</label>
                <label class="pd-opt" onclick="pdPick(this)"><input type="radio" name="gender" value="Femme"><i class="fas fa-venus"></i> Femme</label>
            </div>
        </div>
        <div class="form-group"><label class="form-label">Couleur des yeux</label>
            <select name="eye_color" class="form-select" onchange="pdEye(this,'cEyeOther')"><option value="">—</option><?php foreach($EYE_COLORS as $eco):?><option value="<?php echo $eco;?>"><?php echo $eco;?></option><?php endforeach;?></select>
            <div class="pd-reveal" id="cEyeOther"><input type="text" name="eye_color_other" class="form-input" placeholder="Préciser la couleur..."></div>
        </div>
        <div class="form-group form-full"><label class="form-label">Appartenance</label><input type="text" name="appartenance" class="form-input" placeholder="Famille Moretti, Ballas, Vagos, Gouvernement, Aucun..."></div>
        <div class="form-group form-full"><label class="form-label">Présence de cicatrice</label>
            <div class="pd-seg" id="cScarSeg">
                <label class="pd-opt on" onclick="pdPick(this);pdScar('c')"><input type="radio" name="has_scar" value="0" checked> Non</label>
                <label class="pd-opt" onclick="pdPick(this);pdScar('c')"><input type="radio" name="has_scar" value="1"> Oui</label>
            </div>
            <div class="pd-reveal" id="cScarLoc"><label class="form-label" style="margin-bottom:.3rem"><i class="fas fa-location-dot" style="color:var(--blue-l);margin-right:.3rem"></i>Sur quelle partie du corps ?</label><input type="text" name="scar_location" class="form-input" placeholder="Visage, Bras gauche, Torse, Dos, Jambe droite..."></div>
        </div>
    </div>
    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(16,185,129,.7);margin:.85rem 0 .65rem;display:flex;align-items:center;gap:.4rem;border-top:1px solid rgba(16,185,129,.1);padding-top:.75rem"><i class="fas fa-id-card" style="color:var(--blue-l)"></i> Permis</div>
    <div style="font-size:.68rem;color:var(--txt-m);margin-bottom:.5rem"><i class="fas fa-circle-info" style="margin-right:.3rem"></i>Le PPA est désormais géré automatiquement via la page <strong>PPA</strong>.</div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:.85rem">
        <?php foreach([['license_car','Voiture','fa-car'],['license_boat','Bateau','fa-ship'],['license_plane','Avion','fa-plane']] as [$k,$l,$i]):?>
        <label class="license-toggle" id="clt_<?php echo $k;?>"><input type="checkbox" name="<?php echo $k;?>" value="1" onchange="document.getElementById('clt_<?php echo $k;?>').classList.toggle('active',this.checked)"><div class="lt-dot"><i class="fas <?php echo $i;?>"></i></div><?php echo $l;?></label>
        <?php endforeach;?>
    </div>
    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(239,68,68,.7);margin:.85rem 0 .65rem;display:flex;align-items:center;gap:.4rem;border-top:1px solid rgba(16,185,129,.1);padding-top:.75rem"><i class="fas fa-tags" style="color:#fca5a5"></i> Tags</div>
    <div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.55rem" id="createTagsCont">
        <?php foreach($COMMON_TAGS as $t):?><div class="ptag-chip" onclick="ptogTagCreate(this,'<?php echo addslashes($t);?>')"><?php echo htmlspecialchars($t);?></div><?php endforeach;?>
    </div>
    <div style="display:flex;gap:.4rem"><input type="text" id="createCustomTag" class="form-input" placeholder="Tag personnalisé..." style="flex:1" onkeydown="if(event.key==='Enter'){event.preventDefault();addCustomTag('create');}"><button type="button" onclick="addCustomTag('create')" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i></button></div>
    <input type="hidden" name="tags_json" id="createTagsJson" value="[]">
    </div>
</div><div class="modal-ft" style="background:rgba(0,0,0,.3)"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('createModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Créer le profil</button></div>
</form></div></div>

<?php endif;?>
<script>
function setView(v){const saved=v||localStorage.getItem('profils_view')||'cards';document.getElementById('view-cards').style.display=saved==='cards'?'':'none';document.getElementById('view-table').style.display=saved==='table'?'':'none';document.getElementById('btn-cards')?.classList.toggle('active',saved==='cards');document.getElementById('btn-table')?.classList.toggle('active',saved==='table');if(v)localStorage.setItem('profils_view',v);}
document.addEventListener('DOMContentLoaded',()=>setView());
const avColors={'A':'#059669','B':'#10b981','C':'#8b5cf6','D':'#f59e0b','E':'#ec4899','F':'#06b6d4'};
function updateAvatar(v){const el=document.getElementById('createAvatar');if(!el)return;const c=v.charAt(0).toUpperCase()||'?';const col=avColors[c]||'#475569';el.textContent=c;el.style.background='linear-gradient(135deg,'+col+'44,rgba(0,0,0,.4))';el.style.borderColor=col+'66';}

// ── Preview avatar depuis URL ─────────────────────────────────────────────
function previewAvatarUrl(url, previewId) {
    const wrap = document.getElementById(previewId);
    if (!wrap) return;
    if (url && url.match(/^https?:\/\/.+/i)) {
        const img = new Image();
        img.onload  = function() { wrap.innerHTML = '<img src="'+url+'" style="width:100%;height:100%;object-fit:cover;border-radius:12px">'; };
        img.onerror = function() { wrap.innerHTML = '<i class="fas fa-image-slash" style="color:rgba(239,68,68,.6);font-size:1.2rem"></i>'; };
        img.src = url;
    } else {
        wrap.innerHTML = '<i class="fas fa-image" style="font-size:1.4rem;color:rgba(16,185,129,.5)"></i>';
    }
}

// ── Tags ───────────────────────────────────────────────────────────────────
let editTags=<?php echo json_encode($currentTags??[]);?>;
let createTags=[];
function ptogTag(el,t){editTags.includes(t)?(editTags=editTags.filter(x=>x!==t),el.classList.remove('sel')):(editTags.push(t),el.classList.add('sel'));document.getElementById('editTagsJson').value=JSON.stringify(editTags);}
function ptogTagCreate(el,t){createTags.includes(t)?(createTags=createTags.filter(x=>x!==t),el.classList.remove('sel')):(createTags.push(t),el.classList.add('sel'));document.getElementById('createTagsJson').value=JSON.stringify(createTags);}
// Supprime un tag personnalisé : retire de la liste + retire l'élément du DOM.
function removeCustomTag(el,t,mode){
    if(mode==='edit'){editTags=editTags.filter(x=>x!==t);document.getElementById('editTagsJson').value=JSON.stringify(editTags);}
    else{createTags=createTags.filter(x=>x!==t);document.getElementById('createTagsJson').value=JSON.stringify(createTags);}
    el.remove();
}
function addCustomTag(mode){const inp=document.getElementById(mode==='edit'?'editCustomTag':'createCustomTag');const v=inp.value.trim();if(!v)return;const tags=mode==='edit'?editTags:createTags;if(tags.includes(v)){inp.value='';return;}tags.push(v);const esc=document.createElement('div');esc.textContent=v;const d=document.createElement('div');d.className='ptag-chip sel';d.innerHTML=esc.innerHTML+' <i class="fas fa-times"></i>';d.title='Cliquer pour retirer';d.onclick=()=>removeCustomTag(d,v,mode);document.getElementById(mode==='edit'?'editTagsCont':'createTagsCont').appendChild(d);document.getElementById(mode==='edit'?'editTagsJson':'createTagsJson').value=JSON.stringify(tags);inp.value='';}
// Aperçu de l'âge calculé depuis la date de naissance
function updAgePreview(inp,previewId){const el=document.getElementById(previewId);if(!el)return;const v=inp.value;if(!v){el.textContent='';return;}const d=new Date(v);if(isNaN(d)){el.textContent='';return;}const n=new Date();let a=n.getFullYear()-d.getFullYear();const m=n.getMonth()-d.getMonth();if(m<0||(m===0&&n.getDate()<d.getDate()))a--;el.textContent=(a>=0?a+' ans':'');}
// ── Description physique : radios segmentés + champs révélés ──
function pdPick(el){el.parentNode.querySelectorAll('.pd-opt').forEach(o=>o.classList.remove('on'));el.classList.add('on');const inp=el.querySelector('input');if(inp)inp.checked=true;}
function pdEye(sel,revealId){const r=document.getElementById(revealId);if(r)r.classList.toggle('show',sel.value==='Autre');}
function pdScar(p){const seg=document.getElementById(p+'ScarSeg');if(!seg)return;const c=seg.querySelector('input[name=has_scar]:checked');const loc=document.getElementById(p+'ScarLoc');if(loc)loc.classList.toggle('show',!!c&&c.value==='1');}
function pdCheckGender(f){if(!f.querySelector('input[name=gender]:checked')){if(window.LSSD&&LSSD.toast)LSSD.toast('Le genre est requis.','error');else alert('Le genre est requis.');return false;}return true;}
</script>
<?php include __DIR__ . '/includes/footer.php';?>