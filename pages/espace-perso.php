<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/nexium_theme.php';
requireLogin();

// Multi-personnages : un personnage actif est requis
require_once ROOT_PATH . '/includes/characters.php';
mdt_require_character();

$pageTitle = 'Mon Espace Personnel';
$db  = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];
$msg = ''; $msgType = '';

if (empty($_SESSION['characters_csrf'])) $_SESSION['characters_csrf'] = bin2hex(random_bytes(16));
$charCsrf = $_SESSION['characters_csrf'];

$siteName = 'LysoriaMDT';
try {
    $row = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='site_name' LIMIT 1")->fetch();
    if ($row && $row['setting_value']) $siteName = $row['setting_value'];
} catch (Exception $e) {}

$mailEnabled = false;
try { $db->query("SELECT 1 FROM mail_messages LIMIT 1"); $mailEnabled = true; } catch(Exception $e){}

$isStaff = hasRole(['*','Administrateur','Développeur','Staff']);

// ── ACTIONS ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'link_profile') {
        $pid = intval($_POST['profile_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("UPDATE users SET profile_id=? WHERE id=?")->execute([$pid,$uid]);
            // Multi-personnages : le personnage actif suit la fiche officielle du recensement
            if (!empty($_SESSION['character_id'])) {
                $charId = (int)$_SESSION['character_id'];
                try {
                    $db->prepare("UPDATE characters SET profile_id=? WHERE id=? AND user_id=?")->execute([$pid,$charId,$uid]);
                    $s=$db->prepare("SELECT firstname,lastname FROM profiles WHERE id=? LIMIT 1"); $s->execute([$pid]);
                    if ($p=$s->fetch()) {
                        $db->prepare("UPDATE characters SET firstname=?,lastname=? WHERE id=? AND user_id=?")->execute([$p['firstname'],$p['lastname'],$charId,$uid]);
                    }
                } catch (Exception $e) {}
            }
            if (function_exists('mdt_session_refresh_display_name')) mdt_session_refresh_display_name();
            $msg='Profil lié.'; $msgType='ok';
        }
    }
    if ($action === 'update_character' && !empty($_SESSION['character_id'])) {
        if (!hash_equals($charCsrf, $_POST['csrf'] ?? '')) {
            $msg='Session expirée, veuillez réessayer.'; $msgType='err';
        } else {
            $charId    = (int)$_SESSION['character_id'];
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname  = trim($_POST['lastname'] ?? '');
            $heightRaw = trim($_POST['height_cm'] ?? '');
            $ageRaw    = trim($_POST['age'] ?? '');

            $errs = [];
            if ($firstname === '' || $lastname === '') $errs[] = 'Le nom et le prénom sont requis.';
            if (mb_strlen($firstname) > 100 || mb_strlen($lastname) > 100) $errs[] = 'Nom ou prénom trop long (100 caractères max).';
            if ($heightRaw !== '' && ((int)$heightRaw < 100 || (int)$heightRaw > 250)) $errs[] = 'La taille doit être comprise entre 100 et 250 cm.';
            if ($ageRaw !== '' && ((int)$ageRaw < 18 || (int)$ageRaw > 120)) $errs[] = 'L\'âge doit être compris entre 18 et 120 ans.';

            $data = ['firstname'=>$firstname,'lastname'=>$lastname,'height_cm'=>$heightRaw,'age'=>$ageRaw];
            if (!$errs) {
                $up = mdt_character_handle_avatar_upload($_FILES['avatar'] ?? null);
                if (!$up['ok']) $errs[] = $up['error'];
                elseif ($up['path']) $data['avatar'] = $up['path'];
            }

            if ($errs) {
                $msg = implode(' ', $errs); $msgType='err';
            } elseif (mdt_update_character($db, $charId, $data)) {
                $msg='Personnage mis à jour.'; $msgType='ok';
            } else {
                $msg='Erreur lors de la mise à jour du personnage.'; $msgType='err';
            }
        }
    }
    if ($action === 'sign_contract') {
        $docId = intval($_POST['doc_id'] ?? 0);
        $db->prepare("INSERT INTO avocat_signatures (document_id,user_id,statut,signed_at) VALUES (?,?,'signe',NOW()) ON DUPLICATE KEY UPDATE statut='signe',signed_at=NOW(),refus_motif=NULL")->execute([$docId,$uid]);
        $msg='Contrat signé.'; $msgType='ok';
    }
    if ($action === 'refuse_contract') {
        $docId = intval($_POST['doc_id'] ?? 0); $motif = trim($_POST['motif'] ?? '');
        $db->prepare("INSERT INTO avocat_signatures (document_id,user_id,statut,refus_motif) VALUES (?,?,'refuse',?) ON DUPLICATE KEY UPDATE statut='refuse',signed_at=NULL,refus_motif=?")->execute([$docId,$uid,$motif,$motif]);
        $msg='Contrat refusé.'; $msgType='ok';
    }
}

// ── DONNÉES ──────────────────────────────────────────────────
$user = $db->prepare("SELECT u.*,f.name AS faction_name,f.color AS faction_color FROM users u LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci=u.faction WHERE u.id=?");
$user->execute([$uid]); $user=$user->fetch();

$activeChar = mdt_get_active_character($db);

$profile = null;
if (!empty($user['profile_id'])) {
    $s=$db->prepare("SELECT * FROM profiles WHERE id=?"); $s->execute([$user['profile_id']]); $profile=$s->fetch();
}

$profileSuggestions = [];
if (!$profile) {
    $uname=trim($user['username']??''); $parts=explode(' ',$uname,2); $fn=$parts[0]??''; $ln=$parts[1]??'';
    $s=$db->prepare("SELECT * FROM profiles WHERE firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname,' ',lastname) LIKE ? LIMIT 6");
    $s->execute(["%$fn%","%$uname%","%$uname%"]); $profileSuggestions=$s->fetchAll();
}

$allProfiles=$db->query("SELECT id,firstname,lastname,job FROM profiles ORDER BY lastname,firstname")->fetchAll();

$employment=null;
if (!empty($user['faction'])&&$user['faction']!=='NONE') {
    $s=$db->prepare("SELECT ce.*,COALESCE(f.name,ce.faction) AS faction_name,COALESCE(f.color,'#3b82f6') AS faction_color FROM company_employees ce LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci=ce.faction WHERE ce.user_id=? LIMIT 1");
    $s->execute([$uid]); $employment=$s->fetch();
}

$myInvoicesEmp=$db->prepare("SELECT ci.*,COALESCE(f.name,ci.faction) AS faction_name,COALESCE(f.color,'#3b82f6') AS fcolor FROM company_invoices ci LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci=ci.faction WHERE ci.employee_id=? ORDER BY ci.created_at DESC LIMIT 30");
$myInvoicesEmp->execute([$uid]); $myInvoicesEmp=$myInvoicesEmp->fetchAll();

$mySamsInvoices=[];
if ($profile) {
    $s=$db->prepare("SELECT si.*,u.username AS practitioner FROM sams_invoices si LEFT JOIN users u ON u.id=si.created_by WHERE si.patient_id=? ORDER BY si.created_at DESC LIMIT 20");
    $s->execute([$profile['id']]); $mySamsInvoices=$s->fetchAll();
}

// ── MES DOCUMENTS (documents SAMS reçus par le profil actif) ────────────────
$myDocuments = [];
if ($profile) {
    $pid = (int)$profile['id'];
    // Ordonnances
    try {
        $s=$db->prepare("SELECT created_at,status,google_docs_url FROM medical_prescriptions WHERE patient_id=? ORDER BY created_at DESC");
        $s->execute([$pid]);
        foreach($s->fetchAll() as $r){ $myDocuments[]=['label'=>'Ordonnance','icon'=>'fa-prescription','date'=>$r['created_at']??'','url'=>$r['google_docs_url']??'']; }
    } catch(Exception $e){}
    // Certificats médicaux (tous types)
    $mcLabels=[
        'arret_travail'=>['Arrêt de travail','fa-bed'],
        'aptitude'     =>["Certificat d'aptitude",'fa-clipboard-check'],
        'aptitude_ppa' =>['Aptitude au port d\'arme','fa-shield-halved'],
        'traumatisme'  =>['Certificat de traumatisme','fa-bone'],
        'naissance'    =>['Certificat de naissance','fa-baby'],
        'psy_suivi'    =>['Suivi psychologique','fa-brain'],
        'psy_eval'     =>['Évaluation psychologique','fa-notes-medical'],
    ];
    try {
        $s=$db->prepare("SELECT type,created_at,status,google_docs_url FROM medical_certificates WHERE patient_id=? ORDER BY created_at DESC");
        $s->execute([$pid]);
        foreach($s->fetchAll() as $r){ $meta=$mcLabels[$r['type']]??['Document médical','fa-file-medical']; $myDocuments[]=['label'=>$meta[0],'icon'=>$meta[1],'date'=>$r['created_at']??'','url'=>$r['google_docs_url']??'']; }
    } catch(Exception $e){}
    // Suivis de grossesse
    try {
        $s=$db->prepare("SELECT created_at,status,google_docs_url FROM pregnancy_tracking WHERE patient_id=? ORDER BY created_at DESC");
        $s->execute([$pid]);
        foreach($s->fetchAll() as $r){ $myDocuments[]=['label'=>'Suivi de grossesse','icon'=>'fa-baby','date'=>$r['created_at']??'','url'=>$r['google_docs_url']??'']; }
    } catch(Exception $e){}
    usort($myDocuments, fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
}

$pendingContracts=[];
$myContracts=[];
try {
    $s=$db->prepare("SELECT ad.*,asig.statut AS sig_statut,asig.signed_at,u_a.username AS author_name FROM avocat_documents ad LEFT JOIN avocat_signatures asig ON asig.document_id=ad.id AND asig.user_id=? LEFT JOIN users u_a ON u_a.id=ad.created_by WHERE ad.type='contrat' AND ad.created_by!=? AND (asig.statut IS NULL OR asig.statut='en_attente') AND ad.contenu LIKE ? ORDER BY ad.created_at DESC LIMIT 10");
    $uname=trim($user['username']??''); $s->execute([$uid,$uid,"%$uname%"]); $pendingContracts=$s->fetchAll();
    $s=$db->prepare("SELECT ad.*,asig.statut AS sig_statut,asig.signed_at,u_a.username AS author_name FROM avocat_signatures asig JOIN avocat_documents ad ON ad.id=asig.document_id LEFT JOIN users u_a ON u_a.id=ad.created_by WHERE asig.user_id=? ORDER BY asig.created_at DESC LIMIT 20");
    $s->execute([$uid]); $myContracts=$s->fetchAll();
} catch(Exception $e) {}

$nbImpayees=count(array_filter($myInvoicesEmp,fn($i)=>$i['status']==='pending'));
$nbSams=count(array_filter($mySamsInvoices,fn($i)=>$i['status']==='pending'));
$totalCA=array_sum(array_column(array_filter($myInvoicesEmp,fn($i)=>$i['status']==='paid'),'final_amount'));
$nbContracts=count($pendingContracts);

// ── PPA & ARMES (si profil civil lié) ───────────────────────────────────
$myPPA   = [];
$myArmes = [];
$nbPPAEnAttente = 0;
if ($profile) {
    try {
        $s = $db->prepare("
            SELECT d.*, u.username AS officer_name
            FROM lspd_ppa_demandes d
            LEFT JOIN users u ON u.id = d.officer_id
            WHERE d.citizen_id = ?
            ORDER BY d.created_at DESC
            LIMIT 20
        ");
        $s->execute([$profile['id']]);
        $myPPA = $s->fetchAll();
        $nbPPAEnAttente = count(array_filter($myPPA, fn($p) => in_array($p['statut'], ['demande','en_cours'])));
    } catch(Exception $e) {}

    try {
        $s = $db->prepare("
            SELECT a.*, u.username AS officer_name
            FROM lspd_armes_civils a
            LEFT JOIN users u ON u.id = a.officer_id
            WHERE a.citizen_id = ?
            ORDER BY a.created_at DESC
            LIMIT 30
        ");
        $s->execute([$profile['id']]);
        $myArmes = $s->fetchAll();
    } catch(Exception $e) {}
}

// ── ACADÉMIE (candidatures / QCM / formations) ──────────────────────────
$myCandidatures = [];
$myQCMs         = [];
$myFormations   = [];
$nbCandEnCours  = 0;
try {
    $s = $db->prepare("SELECT c.*, u.username AS decided_by_name
                       FROM academy_candidatures c
                       LEFT JOIN users u ON u.id = c.decided_by
                       WHERE c.user_id = ?
                       ORDER BY c.created_at DESC LIMIT 30");
    $s->execute([$user['id']]);
    $myCandidatures = $s->fetchAll(PDO::FETCH_ASSOC);
    $nbCandEnCours = count(array_filter($myCandidatures, fn($c) => in_array($c['status'], ['en_attente','en_cours','en_examen'], true)));
} catch(Exception $e) {}
try {
    $s = $db->prepare("SELECT * FROM academy_qcm_attempts WHERE user_id = ? ORDER BY started_at DESC LIMIT 30");
    $s->execute([$user['id']]);
    $myQCMs = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}
try {
    $s = $db->prepare("SELECT uf.*, f.name AS formation_name, f.icon AS formation_icon, f.color AS formation_color, f.faction_key
                       FROM academy_user_formations uf
                       JOIN academy_formations f ON f.id = uf.formation_id
                       WHERE uf.user_id = ?
                       ORDER BY uf.updated_at DESC LIMIT 30");
    $s->execute([$user['id']]);
    $myFormations = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$fColor=!empty($user['faction_color'])?$user['faction_color']:'#3b82f6';
$uInit=strtoupper(substr($user['username']??'U',0,1));

// ── TEXTES DE LOI ────────────────────────────────────────────────────────
$view = $_GET['view'] ?? 'perso';
$laws = [];
try {
    $laws = $db->query(
        "SELECT l.*, (SELECT COUNT(*) FROM gov_law_articles WHERE law_id=l.id AND status='en_vigueur') AS nb_actifs,
         (SELECT COUNT(*) FROM gov_law_articles WHERE law_id=l.id) AS nb_total
         FROM gov_laws l ORDER BY l.sort_order, l.code"
    )->fetchAll();
} catch(Exception $e){}
$activeLawId  = intval($_GET['law'] ?? 0);
$lawSearch    = trim($_GET['lq']  ?? '');
$lawChapter   = trim($_GET['lch'] ?? '');
$lawArticles  = [];
$activeLawObj = null;
$lawChapters  = [];
if (!empty($laws)) {
    if (!$activeLawId) $activeLawId = (int)$laws[0]['id'];
    foreach ($laws as $ll) if ((int)$ll['id']===$activeLawId){ $activeLawObj=$ll; break; }
}
if ($activeLawObj) {
    $lq = "SELECT * FROM gov_law_articles WHERE law_id=? AND status='en_vigueur'";
    $lp = [(int)$activeLawObj['id']];
    if ($lawChapter!=='') { $lq.=" AND chapter=?"; $lp[]=$lawChapter; }
    if ($lawSearch !=='') { $lq.=" AND (article_number LIKE ? OR title LIKE ? OR content LIKE ?)"; $sw="%$lawSearch%"; $lp[]=$sw;$lp[]=$sw;$lp[]=$sw; }
    $lq .= " ORDER BY sort_order, chapter, article_number";
    try { $st=$db->prepare($lq); $st->execute($lp); $lawArticles=$st->fetchAll(); } catch(Exception $e){}
    try {
        $ch2=$db->prepare("SELECT DISTINCT chapter,chapter_title FROM gov_law_articles WHERE law_id=? AND chapter!='' AND status='en_vigueur' ORDER BY sort_order");
        $ch2->execute([(int)$activeLawObj['id']]); $lawChapters=$ch2->fetchAll();
    } catch(Exception $e){}
}
$isLP = ($activeLawObj && $activeLawObj['code']==='LP');
$LP_COLORS = ['Crime'=>'#ef4444','Délit Majeur'=>'#f97316','Délit Mineur'=>'#f59e0b','Infraction'=>'#3b82f6','Financier'=>'#8b5cf6'];
$lawGrouped = [];
foreach ($lawArticles as $la) {
    $gk = ($la['chapter']!==''&&$la['chapter']!==null) ? $la['chapter'] : '__none__';
    $gs = ($la['section']!==''&&$la['section']!==null) ? $la['section'] : '__none__';
    $lawGrouped[$gk][$gs][] = $la;
}
$LTYPE_META = ['constitution'=>['Constitution','#a78bfa'],'code'=>['Code','#3b82f6'],'loi'=>['Loi','#38bdf8'],'decret'=>['Décret','#2dd4bf'],'autre'=>['Autre','#64748b']];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> — <?php echo htmlspecialchars($siteName); ?></title>
<?php nexium_theme_head('dark'); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* Charte Nexium — bleu #1E6FE5 → violet #6C3FC5 · Montserrat / Inter */
:root,:root[data-theme="dark"]{--bg:#0C1023;--bg-2:#171C31;--bg-card:#141a2e;--border:rgba(255,255,255,.08);--border-2:rgba(255,255,255,.16);--text:#F4F6FB;--muted:#565E78;--muted-2:#9aa3bc;--grad:linear-gradient(120deg,#1E6FE5,#6C3FC5);--surface:var(--bg-card);--text-2:var(--muted-2);--border-strong:var(--border-2)}
:root[data-theme="light"]{--bg:#F5F6FA;--bg-2:#eef0f6;--bg-card:#FFFFFF;--border:#E4E7EF;--border-2:#D5DAE6;--text:#1A1F30;--muted:#98a0b4;--muted-2:#565E78}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;display:flex;flex-direction:column;transition:background .25s,color .25s}
.container{max-width:1400px;margin:0 auto;padding:0 2rem;width:100%}
/* ── HEADER ── */
header{background:color-mix(in srgb,var(--bg) 82%,transparent);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.header-inner{height:64px;display:flex;justify-content:space-between;align-items:center}
.brand{font-family:'Montserrat',sans-serif;font-size:1.15rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px;text-decoration:none}
.brand-icon{width:34px;height:34px;background:var(--grad);border-radius:9px;display:grid;place-items:center;font-size:15px;color:#fff}
.header-right{display:flex;align-items:center;gap:12px}
.user-chip{display:flex;align-items:center;gap:10px;padding:6px 14px 6px 6px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:50px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#3b82f6;display:grid;place-items:center;font-weight:700;font-size:13px;overflow:hidden;flex-shrink:0}
.user-avatar img{width:100%;height:100%;object-fit:cover}
.user-info-text{line-height:1.2}
.user-name{font-size:.83rem;font-weight:600}
.user-role{font-size:.68rem;color:var(--muted-2)}
.header-btn{width:36px;height:36px;border-radius:9px;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--muted-2);display:grid;place-items:center;text-decoration:none;transition:all .2s;font-size:14px}
.header-btn:hover{background:rgba(255,255,255,.08);border-color:var(--border-2);color:var(--text)}
.header-btn.active{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.3);color:#93c5fd}
.header-btn.logout:hover{color:#ef4444;border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.08)}
/* ── MAIN ── */
main{padding:2.5rem 0 5rem;flex:1}
footer{border-top:1px solid var(--border);padding:1.5rem 0;margin-top:auto}
.footer-inner{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;color:var(--muted-2)}
.footer-inner a{color:var(--muted-2);text-decoration:none}
/* ── PAGE LAYOUT ── */
.page-hero{margin-bottom:2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.page-eyebrow{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.5rem;display:flex;align-items:center;gap:8px}
.page-eyebrow::before{content:'';display:inline-block;width:20px;height:2px;background:#3b82f6;border-radius:1px}
.page-title{font-family:'Montserrat',sans-serif;font-size:clamp(1.5rem,2.5vw,2rem);font-weight:800;letter-spacing:-.03em;line-height:1.1;margin-bottom:.35rem}
.page-sub{color:var(--muted-2);font-size:.9rem}
/* ── GRID ── */
.ep-grid{display:grid;grid-template-columns:320px 1fr;gap:1.5rem}
.ep-col{display:flex;flex-direction:column;gap:1.25rem}
/* ── CARDS ── */
.ep-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;backdrop-filter:blur(4px)}
.ep-card-hd{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ep-card-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted-2);display:flex;align-items:center;gap:.5rem}
.ep-card-bd{padding:1.25rem}
/* ── PROFIL HERO ── */
.ep-hero{display:flex;flex-direction:column;align-items:center;text-align:center;padding:1.75rem 1.25rem 1.25rem}
.ep-avatar{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.65rem;font-weight:700;color:#fff;margin-bottom:.9rem;border:3px solid}
.ep-username{font-family:'Montserrat',sans-serif;font-size:1.1rem;font-weight:800;margin-bottom:.25rem}
.ep-faction-badge{display:inline-flex;align-items:center;gap:.45rem;padding:.32rem .85rem;border-radius:20px;font-size:.75rem;font-weight:700;margin-bottom:.6rem}
/* ── FIELDS ── */
.ep-field{display:flex;justify-content:space-between;align-items:flex-start;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.825rem;gap:.75rem}
.ep-field:last-child{border-bottom:none}
.ep-field-lbl{color:var(--muted-2);font-size:.75rem;flex-shrink:0;min-width:100px}
.ep-field-val{color:#e2e8f0;text-align:right;font-weight:500}
.ep-perm{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:5px;font-size:.68rem;font-weight:700;margin:.1rem}
.ep-perm-ok{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.ep-perm-no{background:rgba(100,116,139,.1);color:#475569;border:1px solid rgba(100,116,139,.15)}
/* ── SALARY ── */
.ep-salary-box{background:linear-gradient(135deg,rgba(59,130,246,.08),rgba(139,92,246,.05));border:1px solid rgba(59,130,246,.15);border-radius:10px;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.ep-salary-val{font-family:'Montserrat',sans-serif;font-size:1.4rem;font-weight:800;color:#93c5fd}
/* ── KPI ROW ── */
.ep-kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.5rem}
.ep-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.1rem;backdrop-filter:blur(4px)}
.ep-kpi-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted-2);margin-bottom:.35rem}
.ep-kpi-val{font-family:'Montserrat',sans-serif;font-size:1.5rem;font-weight:800;line-height:1}
.ep-kpi-val.green{color:#4ade80}.ep-kpi-val.red{color:#f87171}.ep-kpi-val.blue{color:#93c5fd}.ep-kpi-val.gold{color:#fcd34d}
.ep-kpi-sub{font-size:.7rem;color:var(--muted-2);margin-top:.2rem}
/* ── TABS ── */
.ep-tabs{display:flex;gap:.35rem;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:1.25rem;padding-bottom:.75rem;flex-wrap:wrap}
.ep-tab{padding:.42rem .95rem;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;background:transparent;border:1px solid rgba(255,255,255,.07);color:var(--muted-2);transition:all .18s}
.ep-tab:hover{background:rgba(255,255,255,.04);color:var(--text)}
.ep-tab.on{background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.25);color:#93c5fd}
.ep-tab-panel{display:none}.ep-tab-panel.on{display:block}
/* ── INVOICE ROW ── */
.ep-inv-row{display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;border-radius:9px;background:rgba(255,255,255,.02);border:1px solid var(--border);margin-bottom:.4rem;font-size:.82rem;transition:background .15s}
.ep-inv-row:hover{background:rgba(255,255,255,.04)}
.ep-inv-desc{flex:1;min-width:0}
.ep-inv-title{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ep-inv-sub{font-size:.7rem;color:var(--muted-2);margin-top:.1rem}
.ep-inv-amount{font-family:'Montserrat',sans-serif;font-weight:800;flex-shrink:0;font-size:.9rem}
/* ── CONTRAT ── */
.ep-contract{background:rgba(217,119,6,.04);border:1px solid rgba(217,119,6,.2);border-radius:12px;padding:1.1rem;margin-bottom:.75rem}
.ep-contract-title{font-weight:700;font-size:.9rem;margin-bottom:.35rem}
.ep-contract-meta{font-size:.72rem;color:var(--muted-2);margin-bottom:.75rem}
.ep-contract-preview{background:rgba(0,0,0,.2);border-radius:7px;padding:.75rem;font-size:.78rem;color:#94a3b8;max-height:75px;overflow:hidden;line-height:1.6;white-space:pre-wrap;margin-bottom:.75rem;position:relative}
.ep-contract-preview::after{content:'';position:absolute;bottom:0;left:0;right:0;height:28px;background:linear-gradient(transparent,rgba(0,0,0,.3));border-radius:0 0 7px 7px}
/* ── BADGES ── */
.ep-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:5px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.b-paid,.b-signe{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.b-pending,.b-en_attente{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.b-cancelled,.b-refuse{background:rgba(100,116,139,.1);color:#94a3b8;border:1px solid rgba(100,116,139,.2)}
.b-contrat{background:rgba(217,119,6,.1);color:#fcd34d;border:1px solid rgba(217,119,6,.2)}
/* ── ALERT ── */
.ep-alert{padding:.8rem 1.1rem;border-radius:9px;margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;font-size:.825rem;font-weight:500}
.ep-alert.ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22);color:#4ade80}
.ep-alert.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);color:#f87171}
/* ── INFOS RP ── */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.info-item{display:flex;align-items:center;gap:.65rem;padding:.65rem .75rem;background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:9px;transition:background .15s}
.info-item:hover{background:rgba(255,255,255,.04)}
.info-ico{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.info-lbl{font-size:.7rem;color:var(--muted-2)}
.info-val{font-size:.82rem;font-weight:600}
/* ── MODAL ── */
.ep-modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:9999;padding:1.5rem}
.ep-modal-ov.open{display:flex}
.ep-modal{background:var(--bg-card);border:1px solid var(--border-2);border-radius:14px;width:90%;max-width:540px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.35)}
.ep-modal-lg{max-width:700px}
.ep-modal-hd{padding:1.1rem 1.4rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.ep-modal-hd h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
.ep-modal-cl{background:none;border:none;color:var(--muted-2);font-size:1.3rem;cursor:pointer;padding:.25rem;transition:color .18s}
.ep-modal-cl:hover{color:#f87171}
.ep-modal-bd{padding:1.4rem;max-height:65vh;overflow-y:auto}
.ep-modal-ft{padding:1rem 1.4rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.65rem}
/* ── BTNS ── */
.ep-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.15rem;border-radius:8px;font-weight:600;font-size:.815rem;cursor:pointer;transition:all .18s;text-decoration:none;border:none;font-family:inherit}
.ep-btn-primary{background:var(--grad);color:#fff}
.ep-btn-primary:hover{filter:brightness(1.08)}
.ep-btn-success{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.ep-btn-success:hover{background:rgba(34,197,94,.18)}
.ep-btn-danger{background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.25)}
.ep-btn-danger:hover{background:rgba(239,68,68,.18)}
.ep-btn-ghost{background:transparent;color:var(--muted-2);border:1px solid var(--border)}
.ep-btn-ghost:hover{background:rgba(255,255,255,.05);color:var(--text)}
.ep-btn-sm{padding:.38rem .8rem;font-size:.77rem}
.ep-btn-xs{padding:.25rem .6rem;font-size:.7rem}
/* ── FORM ── */
.ep-input{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:7px;color:#f1f5f9;font-size:.845rem;font-family:inherit;transition:border-color .18s}
.ep-input:focus{outline:none;border-color:#3b82f6}
.ep-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted-2);margin-bottom:.35rem;display:block}
@media(max-width:1024px){.ep-grid{grid-template-columns:1fr}.ep-kpi-row{grid-template-columns:repeat(2,1fr)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeUp .35s ease both}

.page-nav{background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);position:sticky;top:64px;z-index:90}
.page-nav-inner{display:flex;align-items:center;height:46px}
.pnav{display:inline-flex;align-items:center;gap:.48rem;padding:0 1.3rem;height:100%;font-size:.82rem;font-weight:600;color:var(--muted-2);text-decoration:none;border-bottom:2px solid transparent;transition:all .18s;white-space:nowrap}
.pnav:hover{color:var(--text);background:rgba(255,255,255,.02)}
.pnav.on{color:#93c5fd;border-bottom-color:#3b82f6;background:rgba(59,130,246,.04)}
.pnav-badge{background:rgba(59,130,246,.15);color:#93c5fd;padding:.1rem .48rem;border-radius:10px;font-size:.63rem;font-weight:700}
.lois-grid{display:grid;grid-template-columns:250px 1fr;gap:1.25rem}
.lois-sidebar{display:flex;flex-direction:column;gap:1rem}
.lois-code-list{max-height:430px;overflow-y:auto}
.lois-code-list::-webkit-scrollbar{width:3px}.lois-code-list::-webkit-scrollbar-thumb{background:rgba(59,130,246,.15)}
.lc-item{display:flex;align-items:center;gap:.6rem;padding:.62rem 1rem;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.035);transition:background .15s;text-decoration:none}
.lc-item:last-child{border-bottom:none}
.lc-item:hover{background:rgba(255,255,255,.03)}
.lc-item.on{background:rgba(59,130,246,.08);border-right:2px solid #3b82f6}
.lc-badge{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-weight:800;font-size:.59rem;text-align:center;flex-shrink:0;line-height:1.1}
.lc-name{font-size:.77rem;font-weight:600;color:#e2e8f0;line-height:1.22;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lc-sub{font-size:.61rem;color:var(--muted-2)}
.lc-n{margin-left:auto;font-size:.63rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:7px;padding:.1rem .42rem;white-space:nowrap;flex-shrink:0}
.lois-panel{display:flex;flex-direction:column;gap:1rem;min-width:0}
.law-filters{padding:.78rem 1.15rem;border-bottom:1px solid var(--border);display:flex;gap:.45rem;flex-wrap:wrap;align-items:center}
.lois-inp-wrap{position:relative;flex:1;min-width:160px}
.lois-inp-wrap i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.72rem;pointer-events:none}
.lois-inp{padding:.44rem .75rem .44rem 1.9rem;background:rgba(255,255,255,.04);border:1px solid var(--border-2);border-radius:7px;color:#f1f5f9;font-family:'Inter',sans-serif;font-size:.78rem;width:100%;transition:border-color .18s}
.lois-inp:focus{outline:none;border-color:#3b82f6}
.lois-sel{padding:.44rem .6rem;background:rgba(255,255,255,.04);border:1px solid var(--border-2);border-radius:7px;color:#e2e8f0;font-family:'Inter',sans-serif;font-size:.76rem;cursor:pointer}
.lois-sel:focus{outline:none;border-color:#3b82f6}
.law-list{max-height:calc(100vh - 320px);overflow-y:auto;padding:.6rem}
.law-list::-webkit-scrollbar{width:3px}.law-list::-webkit-scrollbar-thumb{background:rgba(59,130,246,.12)}
.law-item{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:9px;margin-bottom:.38rem;overflow:hidden;transition:border-color .18s}
.law-item:hover{border-color:rgba(59,130,246,.2)}
.law-item-hd{padding:.6rem .85rem;display:flex;align-items:center;gap:.6rem;cursor:pointer;user-select:none}
.law-num{font-family:'DM Mono',monospace;font-size:.67rem;font-weight:700;color:#93c5fd;background:rgba(59,130,246,.08);padding:.15rem .48rem;border-radius:4px;border:1px solid rgba(59,130,246,.14);flex-shrink:0;white-space:nowrap}
.law-ttl{flex:1;min-width:0;font-size:.79rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.law-item-body{display:none;padding:.7rem .85rem;border-top:1px solid rgba(255,255,255,.04);font-size:.79rem;line-height:1.88;color:#94a3b8;white-space:pre-wrap;max-height:320px;overflow-y:auto}
.law-item.open .law-item-body{display:block}
.law-item.open .law-chev{transform:rotate(180deg)}
.law-chev{font-size:.63rem;color:var(--muted);flex-shrink:0;transition:transform .2s}
.law-ch{padding:.4rem .85rem;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(59,130,246,.5);background:rgba(59,130,246,.03);border-radius:5px;margin:.48rem 0 .3rem;display:flex;align-items:center;justify-content:space-between}
.law-sec{padding:.2rem .85rem;font-size:.6rem;color:var(--muted);margin-bottom:.22rem}
.lp-tbl{width:100%;border-collapse:collapse}
.lp-tbl th{text-align:left;padding:.5rem .75rem;background:rgba(255,255,255,.02);border-bottom:2px solid var(--border-2);font-size:.59rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted)}
.lp-tbl td{padding:.48rem .75rem;border-bottom:1px solid rgba(255,255,255,.03);font-size:.78rem;cursor:pointer}
.lp-tbl tr:hover td{background:rgba(59,130,246,.03)}
.lp-sh{display:flex;align-items:center;gap:.48rem;padding:.36rem .7rem;border-radius:6px;margin:.52rem 0 .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em}
@media(max-width:880px){.lois-grid{grid-template-columns:1fr}}
/* ── Overrides thème clair (lisibilité des couleurs codées en dur) ── */
:root[data-theme="light"] .ep-input,:root[data-theme="light"] .lois-inp{background:#F5F6FA;color:#1A1F30}
:root[data-theme="light"] .ep-input:focus,:root[data-theme="light"] .lois-inp:focus{background:#fff}
:root[data-theme="light"] .ep-field-val,:root[data-theme="light"] .lc-name,:root[data-theme="light"] .lois-sel{color:#1A1F30}
:root[data-theme="light"] .law-item-body,:root[data-theme="light"] .ep-contract-preview{color:#3B4258}
:root[data-theme="light"] .header-btn.active,:root[data-theme="light"] .ep-tab.on,:root[data-theme="light"] .pnav.on,:root[data-theme="light"] .ep-salary-val,:root[data-theme="light"] .ep-kpi-val.blue,:root[data-theme="light"] .law-num,:root[data-theme="light"] .pnav-badge{color:#1d4ed8}
:root[data-theme="light"] .ep-inv-row,:root[data-theme="light"] .info-item,:root[data-theme="light"] .ep-kpi{background:#fff}
<?php echo nexium_theme_toggle_css(); ?>
</style>
</head>
<body>

<header>
    <div class="container header-inner">
        <a href="/pages/dashboard.php" class="brand">
            <div class="brand-icon"><i class="fa-solid fa-microchip"></i></div>
            <div><?php echo htmlspecialchars($siteName); ?></div>
        </a>
        <div class="header-right">
            <div class="user-chip">
                <div class="user-avatar">
                    <?php if(!empty($_SESSION['user_avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="">
                    <?php else: ?>
                    <?php echo htmlspecialchars(mdt_session_display_initial()); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_role']); ?></div>
                </div>
            </div>
            <?php nexium_theme_toggle('header-btn'); ?>
            <a href="/pages/espace-perso.php" class="header-btn active" title="Mon Espace Personnel"><i class="fa-solid fa-id-card-clip"></i></a>
            <a href="/profile.php" class="header-btn" title="Paramètres"><i class="fa-solid fa-user-gear"></i></a>
            <a href="/pages/dashboard.php" class="header-btn" title="Retour au MDT"><i class="fa-solid fa-home"></i></a>
            <a href="/logout.php" class="header-btn logout" title="Déconnexion"><i class="fa-solid fa-power-off"></i></a>
        </div>
    </div>
</header>

<nav class="page-nav">
    <div class="container page-nav-inner">
        <a href="?view=perso" class="pnav <?php echo $view==='perso'?'on':''; ?>"><i class="fas fa-id-card-clip"></i> Mon Espace</a>
        <a href="?view=lois" class="pnav <?php echo $view==='lois'?'on':''; ?>"><i class="fas fa-balance-scale"></i> Textes de Loi<?php if(!empty($laws)): ?> <span class="pnav-badge"><?php echo count($laws); ?></span><?php endif; ?></a>
    </div>
</nav>

<?php if($isStaff): ?>
<div style="background:rgba(59,130,246,.06);border-bottom:1px solid rgba(59,130,246,.15);padding:10px 0;font-size:.8rem;color:#93c5fd">
    <div class="container" style="display:flex;align-items:center;gap:10px">
        <i class="fa-solid fa-eye"></i><span>Mode <strong>Staff</strong></span>
        <a href="/pages/dashboard.php" style="margin-left:auto;color:#3b82f6;text-decoration:none;font-size:.75rem;font-weight:600"><i class="fa-solid fa-arrow-left"></i> Retour MDT</a>
    </div>
</div>
<?php endif; ?>

<main>
<?php if($view==='perso'): ?>
<div class="container">

<?php if($msg): ?>
<div class="ep-alert <?php echo $msgType; ?>" style="margin-top:.5rem">
    <i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- HERO -->
<div class="page-hero fade-in">
    <div>
        <div class="page-eyebrow"><i class="fa-solid fa-id-card-clip"></i> Espace Personnel</div>
        <div class="page-title">Bonjour, <?php echo htmlspecialchars(explode(' ',$user['username'])[0]); ?> 👋</div>
        <div class="page-sub">Vos informations, factures et contrats en un seul endroit.</div>
    </div>
    <?php if($nbContracts>0): ?>
    <div style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);border-radius:12px;padding:.75rem 1.25rem;display:flex;align-items:center;gap:.75rem;color:#fcd34d;font-weight:600;font-size:.875rem">
        <i class="fas fa-file-signature" style="font-size:1.1rem"></i>
        <?php echo $nbContracts; ?> contrat(s) en attente de signature
    </div>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="ep-kpi-row fade-in" style="grid-template-columns:repeat(5,1fr)">
    <div class="ep-kpi">
        <div class="ep-kpi-lbl">Factures impayées</div>
        <div class="ep-kpi-val <?php echo $nbImpayees>0?'red':'green'; ?>"><?php echo $nbImpayees; ?></div>
        <div class="ep-kpi-sub">entreprise</div>
    </div>
    <div class="ep-kpi">
        <div class="ep-kpi-lbl">Soins SAMS</div>
        <div class="ep-kpi-val <?php echo $nbSams>0?'red':'green'; ?>"><?php echo $nbSams; ?></div>
        <div class="ep-kpi-sub">impayé(s)</div>
    </div>
    <div class="ep-kpi">
        <div class="ep-kpi-lbl">Contrats</div>
        <div class="ep-kpi-val <?php echo $nbContracts>0?'gold':''; ?>"><?php echo $nbContracts; ?></div>
        <div class="ep-kpi-sub">à signer</div>
    </div>
    <div class="ep-kpi">
        <div class="ep-kpi-lbl">Mon CA total</div>
        <div class="ep-kpi-val blue">$<?php echo number_format($totalCA,0,'.',','); ?></div>
        <div class="ep-kpi-sub">factures payées</div>
    </div>
    <?php if($profile): ?>
    <div class="ep-kpi" style="border-color:<?php echo $nbPPAEnAttente>0?'rgba(59,130,246,.3)':'rgba(255,255,255,.07)';?>">
        <div class="ep-kpi-lbl"><i class="fas fa-gun" style="margin-right:.3rem;opacity:.6"></i>PPA / Armes</div>
        <div class="ep-kpi-val <?php echo count($myArmes)>0?'blue':''; ?>"><?php echo count($myArmes); ?></div>
        <div class="ep-kpi-sub"><?php echo $nbPPAEnAttente>0?"$nbPPAEnAttente demande(s) en cours":(count($myPPA)>0?count($myPPA).' PPA':'Aucune arme'); ?></div>
    </div>
    <?php else: ?>
    <div class="ep-kpi"><div class="ep-kpi-lbl">PPA / Armes</div><div class="ep-kpi-val" style="color:var(--muted)">—</div><div class="ep-kpi-sub">profil non lié</div></div>
    <?php endif; ?>
</div>

<div class="ep-grid fade-in">
<!-- ═══ COLONNE GAUCHE ═══════════════════════════════ -->
<div class="ep-col">

    <!-- IDENTITÉ -->
    <div class="ep-card">
        <div class="ep-hero">
            <div class="ep-avatar" style="background:<?php echo $fColor;?>20;border-color:<?php echo $fColor;?>;color:<?php echo $fColor;?>;overflow:hidden">
                <?php if($activeChar && !empty($activeChar['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($activeChar['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <?php echo $uInit; ?>
                <?php endif; ?>
            </div>
            <div class="ep-username"><?php echo htmlspecialchars($activeChar ? ($activeChar['firstname'].' '.$activeChar['lastname']) : $user['username']); ?></div>
            <?php if($activeChar): ?>
            <div style="font-size:.72rem;color:var(--muted);margin-bottom:.35rem"><i class="fas fa-at" style="margin-right:.25rem"></i><?php echo htmlspecialchars($user['username']); ?></div>
            <?php endif; ?>
            <?php if(!empty($user['faction'])&&$user['faction']!=='NONE'): ?>
            <div class="ep-faction-badge" style="background:<?php echo $fColor;?>15;border:1px solid <?php echo $fColor;?>30;color:<?php echo $fColor;?>">
                <i class="fas fa-building"></i><?php echo htmlspecialchars($user['faction_name']??$user['faction']); ?>
            </div>
            <?php endif; ?>
            <?php if(!empty($user['faction_rank'])): ?>
            <div style="font-size:.82rem;color:var(--muted-2)"><i class="fas fa-medal" style="color:<?php echo $fColor;?>;margin-right:.35rem"></i><?php echo htmlspecialchars($user['faction_rank']); ?></div>
            <?php endif; ?>
            <?php if(!empty($user['role'])): ?>
            <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem"><?php echo htmlspecialchars($user['role']); ?></div>
            <?php endif; ?>
            <?php if($activeChar): ?>
            <div style="display:flex;gap:.5rem;margin-top:.9rem;flex-wrap:wrap;justify-content:center">
                <button onclick="openModal('editCharModal')" class="ep-btn ep-btn-primary ep-btn-sm"><i class="fas fa-user-pen"></i> Modifier mon personnage</button>
                <a href="<?php echo SITE_URL; ?>/pages/personnage.php" class="ep-btn ep-btn-ghost ep-btn-sm"><i class="fas fa-repeat"></i> Changer</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- EMPLOI & SALAIRE -->
    <?php if($employment): ?>
    <div class="ep-card">
        <div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-coins" style="color:#fcd34d"></i> Mon emploi</div></div>
        <div class="ep-card-bd">
            <div class="ep-salary-box">
                <div>
                    <div style="font-size:.7rem;color:var(--muted-2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem">Salaire calculé</div>
                    <div class="ep-salary-val">$<?php echo number_format($employment['calculated_salary']??0,0,'.',','); ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:.7rem;color:var(--muted-2);margin-bottom:.2rem">% du CA</div>
                    <div style="font-size:1.1rem;font-weight:700"><?php echo $employment['salary_percentage']??0; ?>%</div>
                </div>
            </div>
            <?php foreach([
                ['Grade',$employment['rank']??'—','#c084fc'],
                ['CA individuel','$'.number_format($employment['individual_ca']??0,0,'.',','),'#93c5fd'],
                ['Prime','$'.number_format($employment['bonus']??0,0,'.',','),'#4ade80'],
                ['Embauché le',$employment['hired_date']?date('d/m/Y',strtotime($employment['hired_date'])):'—','var(--muted-2)'],
            ] as [$l,$v,$c]): ?>
            <div class="ep-field"><span class="ep-field-lbl"><?php echo $l;?></span><span class="ep-field-val" style="color:<?php echo $c;?>"><?php echo htmlspecialchars($v);?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FICHE CIVILE -->
    <div class="ep-card">
        <div class="ep-card-hd">
            <div class="ep-card-title"><i class="fas fa-id-card" style="color:#38bdf8"></i> Fiche civile (recensement LSPD)</div>
            <?php if($profile): ?>
            <button onclick="openModal('unlinkModal')" class="ep-btn ep-btn-ghost ep-btn-xs" title="Changer de profil"><i class="fas fa-sync-alt"></i></button>
            <?php endif; ?>
        </div>
        <div class="ep-card-bd">
        <?php if(!$profile): ?>
            <div style="text-align:center;padding:1.25rem 0;color:var(--muted-2)">
                <i class="fas fa-user-slash" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.65rem"></i>
                <p style="font-size:.82rem;margin-bottom:1rem">Aucun profil du recensement lié.</p>
                <button onclick="openModal('linkModal')" class="ep-btn ep-btn-primary ep-btn-sm" style="width:100%;justify-content:center"><i class="fas fa-link"></i> Lier mon profil LSPD</button>
            </div>
            <?php if(!empty($profileSuggestions)): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
                <div class="ep-label">Suggestions automatiques :</div>
                <?php foreach($profileSuggestions as $ps): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem .65rem;background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:8px;margin-bottom:.35rem">
                    <div>
                        <div style="font-size:.82rem;font-weight:600"><?php echo htmlspecialchars($ps['firstname'].' '.$ps['lastname']); ?></div>
                        <div style="font-size:.7rem;color:var(--muted-2)"><?php echo htmlspecialchars($ps['job']??'—'); ?></div>
                    </div>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="action" value="link_profile">
                        <input type="hidden" name="profile_id" value="<?php echo $ps['id']; ?>">
                        <button type="submit" class="ep-btn ep-btn-primary ep-btn-xs"><i class="fas fa-check"></i> C'est moi</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="display:flex;align-items:center;gap:.85rem;padding:.85rem;background:rgba(56,189,248,.04);border:1px solid rgba(56,189,248,.15);border-radius:10px;margin-bottom:1rem">
                <div style="width:38px;height:38px;border-radius:9px;background:rgba(56,189,248,.15);color:#38bdf8;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">
                    <?php echo strtoupper(substr($profile['firstname'],0,1)); ?>
                </div>
                <div style="flex:1">
                    <div style="font-weight:700"><?php echo htmlspecialchars($profile['firstname'].' '.$profile['lastname']); ?></div>
                    <div style="font-size:.7rem;color:var(--muted-2)"><?php echo htmlspecialchars($profile['job']??'—'); ?></div>
                </div>
                <?php if(!empty($profile['blood_type'])): ?>
                <span class="ep-badge" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2)"><?php echo $profile['blood_type']; ?></span>
                <?php endif; ?>
            </div>
            <?php foreach([
                ['Âge',$profile['age'].' ans'],
                ['Nationalité',$profile['nationality']],
                ['Téléphone',$profile['phone']],
                ['IBAN',$profile['iban']],
            ] as [$l,$v]): if(empty($v)) continue; ?>
            <div class="ep-field"><span class="ep-field-lbl"><?php echo $l; ?></span><span class="ep-field-val"><?php echo htmlspecialchars($v); ?></span></div>
            <?php endforeach; ?>
            <div class="ep-field">
                <span class="ep-field-lbl">Permis</span>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">
                    <?php foreach([['license_car','Voiture'],['license_ppa','PPA'],['license_boat','Bateau'],['license_plane','Avion']] as [$k,$l]): ?>
                    <span class="ep-perm <?php echo $profile[$k]?'ep-perm-ok':'ep-perm-no'; ?>"><?php echo $profile[$k]?'✓':'✗'; ?> <?php echo $l; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if(!empty($profile['tags'])):
                $tags=json_decode($profile['tags'],true)?:[];
                if(!empty($tags)): ?>
            <div class="ep-field">
                <span class="ep-field-lbl">Tags</span>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">
                    <?php foreach($tags as $t): ?>
                    <span class="ep-badge" style="background:rgba(239,68,68,.08);color:#fca5a5;border:1px solid rgba(239,68,68,.2)"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($t); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>
            <?php if(!empty($profile['notes'])): ?>
            <div style="margin-top:.75rem;padding:.75rem;background:rgba(255,255,255,.025);border-radius:8px;font-size:.78rem;color:#94a3b8;line-height:1.6;border:1px solid var(--border)">
                <div class="ep-label" style="margin-bottom:.35rem"><i class="fas fa-note-sticky"></i> Notes</div>
                <?php echo nl2br(htmlspecialchars($profile['notes'])); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>

</div><!-- /col gauche -->

<!-- ═══ COLONNE DROITE ══════════════════════════════ -->
<div class="ep-col">

    <?php if($nbContracts>0): ?>
    <!-- CONTRATS EN ATTENTE -->
    <div class="ep-card" style="border-color:rgba(217,119,6,.3)">
        <div class="ep-card-hd" style="background:rgba(217,119,6,.05)">
            <div class="ep-card-title" style="color:#fcd34d"><i class="fas fa-file-signature" style="color:#f59e0b"></i> Contrats à signer <span style="background:rgba(245,158,11,.15);color:#fcd34d;padding:.12rem .5rem;border-radius:12px;font-size:.65rem;margin-left:.35rem;font-weight:700"><?php echo $nbContracts; ?></span></div>
        </div>
        <div class="ep-card-bd">
            <?php foreach($pendingContracts as $c): ?>
            <div class="ep-contract">
                <div class="ep-contract-title"><i class="fas fa-scroll" style="color:#f59e0b;margin-right:.4rem"></i><?php echo htmlspecialchars($c['titre']); ?></div>
                <div class="ep-contract-meta">
                    Par <strong style="color:#f1f5f9"><?php echo htmlspecialchars($c['author_name']??'Cabinet d\'Avocats'); ?></strong>
                    · <?php echo date('d/m/Y',strtotime($c['created_at'])); ?>
                </div>
                <div class="ep-contract-preview"><?php echo htmlspecialchars(mb_substr($c['contenu']??'',0,280)); ?></div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="sign_contract"><input type="hidden" name="doc_id" value="<?php echo $c['id']; ?>"><button type="submit" class="ep-btn ep-btn-success ep-btn-sm"><i class="fas fa-signature"></i> Signer & Accepter</button></form>
                    <button onclick="openRefuseModal(<?php echo $c['id']; ?>,'<?php echo addslashes(htmlspecialchars($c['titre'])); ?>')" class="ep-btn ep-btn-danger ep-btn-sm"><i class="fas fa-times"></i> Refuser</button>
                    <button onclick="previewContract('<?php echo addslashes(mb_substr($c['contenu']??'',0,5000)); ?>')" class="ep-btn ep-btn-ghost ep-btn-sm"><i class="fas fa-eye"></i> Lire</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FINANCES : TABS -->
    <div class="ep-card">
        <div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-file-invoice-dollar" style="color:#38bdf8"></i> Mes finances</div></div>
        <div class="ep-card-bd">
            <div class="ep-tabs">
                <button class="ep-tab on" onclick="switchTab(this,'tab-emp')">
                    <i class="fas fa-building"></i> Factures
                    <?php if($nbImpayees>0): ?><span style="background:#ef4444;color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo $nbImpayees;?></span><?php endif;?>
                </button>
                <?php if($profile): ?>
                <button class="ep-tab" onclick="switchTab(this,'tab-sams')">
                    <i class="fas fa-heart-pulse"></i> SAMS
                    <?php if($nbSams>0): ?><span style="background:#ef4444;color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo $nbSams;?></span><?php endif;?>
                </button>
                <?php endif;?>
                <button class="ep-tab" onclick="switchTab(this,'tab-contracts')"><i class="fas fa-scroll"></i> Mes contrats</button>
            </div>

            <!-- FACTURES ENTREPRISE -->
            <div class="ep-tab-panel on" id="tab-emp">
                <?php if(empty($myInvoicesEmp)): ?>
                <div style="text-align:center;padding:2.5rem;color:var(--muted-2)"><i class="fas fa-receipt" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucune facture</div>
                <?php else: foreach($myInvoicesEmp as $inv):
                    $bc='b-'.$inv['status'];
                    $bl=['paid'=>'Payée','pending'=>'En attente','cancelled'=>'Annulée'][$inv['status']]??$inv['status'];
                ?>
                <div class="ep-inv-row">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $inv['fcolor'];?>;flex-shrink:0"></div>
                    <div class="ep-inv-desc">
                        <div class="ep-inv-title"><?php echo htmlspecialchars($inv['customer_name']??'—'); ?></div>
                        <div class="ep-inv-sub"><span style="color:<?php echo $inv['fcolor'];?>"><?php echo htmlspecialchars($inv['faction']); ?></span> · <?php echo date('d/m/Y',strtotime($inv['created_at'])); ?></div>
                    </div>
                    <div class="ep-inv-amount" style="color:<?php echo $inv['status']==='paid'?'#4ade80':'#fcd34d';?>">$<?php echo number_format($inv['final_amount'],0,'.',','); ?></div>
                    <span class="ep-badge <?php echo $bc;?>"><?php echo $bl;?></span>
                </div>
                <?php endforeach; endif;?>
            </div>

            <!-- SAMS -->
            <?php if($profile): ?>
            <div class="ep-tab-panel" id="tab-sams">
                <?php if(empty($mySamsInvoices)): ?>
                <div style="text-align:center;padding:2.5rem;color:var(--muted-2)"><i class="fas fa-heart-pulse" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun soin</div>
                <?php else: foreach($mySamsInvoices as $si):
                    $items=json_decode($si['items']??'[]',true)?:[];
                    $bc='b-'.$si['status'];
                    $bl=['paid'=>'Payée','pending'=>'En attente','cancelled'=>'Annulée'][$si['status']]??$si['status'];
                ?>
                <div class="ep-inv-row">
                    <i class="fas fa-heart-pulse" style="color:#f43f5e;font-size:.85rem;flex-shrink:0"></i>
                    <div class="ep-inv-desc">
                        <div class="ep-inv-title"><?php echo htmlspecialchars(implode(', ',array_column($items,'name'))); ?></div>
                        <div class="ep-inv-sub">SAMS · <?php echo htmlspecialchars($si['practitioner']??'—'); ?> · <?php echo date('d/m/Y',strtotime($si['created_at'])); ?></div>
                    </div>
                    <div class="ep-inv-amount" style="color:<?php echo $si['status']==='paid'?'#4ade80':'#fcd34d';?>">$<?php echo number_format($si['total_amount'],0,'.',','); ?></div>
                    <span class="ep-badge <?php echo $bc;?>"><?php echo $bl;?></span>
                </div>
                <?php endforeach; endif;?>
            </div>
            <?php endif; ?>

            <!-- CONTRATS SIGNÉS -->
            <div class="ep-tab-panel" id="tab-contracts">
                <?php if(empty($myContracts)): ?>
                <div style="text-align:center;padding:2.5rem;color:var(--muted-2)"><i class="fas fa-scroll" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun contrat</div>
                <?php else: foreach($myContracts as $c):
                    $bc='b-'.($c['sig_statut']??'en_attente');
                    $bl=['signe'=>'Signé','refuse'=>'Refusé','en_attente'=>'En attente'][$c['sig_statut']]??$c['sig_statut'];
                ?>
                <div class="ep-inv-row" style="cursor:pointer" onclick="previewContract('<?php echo addslashes(mb_substr($c['contenu']??'',0,5000)); ?>')">
                    <i class="fas fa-file-contract" style="color:#a78bfa;font-size:.85rem;flex-shrink:0"></i>
                    <div class="ep-inv-desc">
                        <div class="ep-inv-title"><?php echo htmlspecialchars($c['titre']); ?></div>
                        <div class="ep-inv-sub">Par <?php echo htmlspecialchars($c['author_name']??'—'); ?><?php if($c['signed_at']): ?> · <?php echo date('d/m/Y',strtotime($c['signed_at'])); ?><?php endif;?></div>
                    </div>
                    <span class="ep-badge <?php echo $bc;?>"><?php echo $bl;?></span>
                </div>
                <?php endforeach; endif;?>
            </div>
        </div>
    </div>

    <!-- DÉMARCHES ET ARMEMENT -->
    <?php if($profile): ?>
    <div class="ep-card" style="<?php echo ($nbPPAEnAttente + $nbCandEnCours)>0?'border-color:rgba(139,92,246,.3)':'';?>">
        <div class="ep-card-hd" style="<?php echo ($nbPPAEnAttente + $nbCandEnCours)>0?'background:rgba(139,92,246,.04)':'';?>">
            <div class="ep-card-title">
                <i class="fas fa-clipboard-list" style="color:#c4b5fd"></i> Démarches et armement
                <?php $totalEnAttente = $nbPPAEnAttente + $nbCandEnCours; if($totalEnAttente>0): ?>
                <span style="background:rgba(139,92,246,.15);color:#c4b5fd;padding:.1rem .48rem;border-radius:10px;font-size:.63rem;margin-left:.35rem;font-weight:700"><?php echo $totalEnAttente;?> en cours</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="ep-card-bd">

            <!-- Tabs : PPA / Armes / Candidatures / QCM / Formations -->
            <div class="ep-tabs" style="overflow-x:auto">
                <button class="ep-tab <?php echo empty($myArmes)?'on':''; ?>" onclick="switchTab(this,'tab-ppa')">
                    <i class="fas fa-id-card-clip"></i> PPA
                    <?php if($nbPPAEnAttente>0): ?>
                    <span style="background:#3b82f6;color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo $nbPPAEnAttente;?></span>
                    <?php endif; ?>
                </button>
                <button class="ep-tab <?php echo !empty($myArmes)?'on':''; ?>" onclick="switchTab(this,'tab-armes')">
                    <i class="fas fa-gun"></i> Armes
                    <?php if(!empty($myArmes)): ?>
                    <span style="background:rgba(59,130,246,.2);color:#93c5fd;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo count($myArmes);?></span>
                    <?php endif; ?>
                </button>
                <button class="ep-tab" onclick="switchTab(this,'tab-cand')">
                    <i class="fas fa-file-signature"></i> Candidatures
                    <?php if(count($myCandidatures)>0): ?>
                    <span style="background:rgba(139,92,246,.2);color:#c4b5fd;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo count($myCandidatures);?></span>
                    <?php endif; ?>
                </button>
                <button class="ep-tab" onclick="switchTab(this,'tab-qcm')">
                    <i class="fas fa-circle-question"></i> QCM
                    <?php if(count($myQCMs)>0): ?>
                    <span style="background:rgba(139,92,246,.2);color:#c4b5fd;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo count($myQCMs);?></span>
                    <?php endif; ?>
                </button>
                <button class="ep-tab" onclick="switchTab(this,'tab-formations')">
                    <i class="fas fa-chalkboard-user"></i> Formations
                    <?php if(count($myFormations)>0): ?>
                    <span style="background:rgba(139,92,246,.2);color:#c4b5fd;border-radius:10px;padding:.1rem .45rem;font-size:.62rem;margin-left:.35rem"><?php echo count($myFormations);?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- ONGLET PPA -->
            <div class="ep-tab-panel <?php echo empty($myArmes)?'on':''; ?>" id="tab-ppa">
                <?php if(empty($myPPA)): ?>
                <div style="text-align:center;padding:2.25rem;color:var(--muted-2)">
                    <i class="fas fa-file-circle-question" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.83rem">Aucune demande de PPA enregistrée</div>
                    <?php if(!($profile['license_ppa']??0)): ?>
                    <div style="font-size:.75rem;margin-top:.4rem;color:var(--muted)">Vous n'avez pas de permis PPA actif.</div>
                    <?php endif; ?>
                </div>
                <?php else:
                    $PPA_STATUT = [
                        'demande'  => ['Demande',  'rgba(245,158,11,.1)', 'rgba(245,158,11,.25)', '#fde68a', 'fas fa-clock'],
                        'en_cours' => ['En cours', 'rgba(59,130,246,.1)', 'rgba(59,130,246,.25)',  '#93c5fd', 'fas fa-hourglass-half'],
                        'valide'   => ['Validé',   'rgba(34,197,94,.1)',  'rgba(34,197,94,.25)',   '#4ade80', 'fas fa-check-circle'],
                        'refuse'   => ['Refusé',   'rgba(239,68,68,.1)',  'rgba(239,68,68,.25)',   '#f87171', 'fas fa-times-circle'],
                    ];
                    foreach($myPPA as $ppa):
                        [$psl, $pbg, $pbdr, $ptc, $pico] = $PPA_STATUT[$ppa['statut']] ?? ['—','rgba(100,116,139,.1)','rgba(100,116,139,.2)','#94a3b8','fas fa-question'];
                ?>
                <div class="ep-inv-row" style="margin-bottom:.4rem;border-color:<?php echo $pbdr;?>;background:<?php echo $pbg;?>">
                    <i class="<?php echo $pico;?>" style="color:<?php echo $ptc;?>;font-size:.9rem;flex-shrink:0"></i>
                    <div class="ep-inv-desc">
                        <div class="ep-inv-title" style="font-size:.84rem"><?php echo htmlspecialchars($ppa['type_arme']??'PPA'); ?></div>
                        <div class="ep-inv-sub">
                            <?php echo date('d/m/Y', strtotime($ppa['date_demande'])); ?>
                            <?php if(!empty($ppa['officer_name'])): ?> · Officier : <?php echo htmlspecialchars($ppa['officer_name']); ?><?php endif; ?>
                            <?php if(!empty($ppa['date_traitement'])): ?> · Traité le <?php echo date('d/m/Y',strtotime($ppa['date_traitement'])); ?><?php endif;?>
                        </div>
                        <?php if($ppa['statut']==='refuse' && !empty($ppa['motif_refus'])): ?>
                        <div style="margin-top:.3rem;font-size:.71rem;color:#f87171;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.18);border-radius:5px;padding:.2rem .5rem;display:inline-block">
                            <i class="fas fa-times-circle" style="font-size:.6rem;margin-right:.2rem"></i><?php echo htmlspecialchars(mb_substr($ppa['motif_refus'],0,80)); ?>
                        </div>
                        <?php endif; ?>
                        <?php if(!empty($ppa['notes_officier'])): ?>
                        <div style="margin-top:.25rem;font-size:.7rem;color:var(--muted-2);font-style:italic">
                            <i class="fas fa-sticky-note" style="font-size:.6rem;margin-right:.2rem;opacity:.5"></i><?php echo htmlspecialchars(mb_substr($ppa['notes_officier'],0,80)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <span class="ep-badge" style="background:<?php echo $pbg;?>;color:<?php echo $ptc;?>;border:1px solid <?php echo $pbdr;?>">
                            <i class="<?php echo $pico;?>" style="font-size:.6rem"></i> <?php echo $psl; ?>
                        </span>
                        <div style="font-size:.65rem;color:var(--muted-2);margin-top:.25rem">#<?php echo str_pad($ppa['id'],5,'0',STR_PAD_LEFT); ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>

                <!-- Statut permis PPA actuel -->
                <div style="margin-top:.85rem;padding:.75rem .9rem;border-radius:9px;border:1px solid <?php echo ($profile['license_ppa']??0)?'rgba(34,197,94,.25)':'rgba(100,116,139,.2)';?>;background:<?php echo ($profile['license_ppa']??0)?'rgba(34,197,94,.06)':'rgba(100,116,139,.06)';?>;display:flex;align-items:center;gap:.65rem">
                    <i class="fas <?php echo ($profile['license_ppa']??0)?'fa-shield-check':'fa-shield-xmark';?>" style="font-size:1.1rem;color:<?php echo ($profile['license_ppa']??0)?'#4ade80':'#64748b';?>;flex-shrink:0"></i>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:<?php echo ($profile['license_ppa']??0)?'#4ade80':'#94a3b8';?>">
                            <?php echo ($profile['license_ppa']??0) ? 'Permis PPA accordé' : 'Pas de permis PPA actif'; ?>
                        </div>
                        <div style="font-size:.7rem;color:var(--muted-2)">
                            <?php echo ($profile['license_ppa']??0) ? 'Vous êtes autorisé à porter une arme déclarée.' : 'Aucune autorisation de port d\'arme.'; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /tab-ppa -->

            <!-- ONGLET ARMES -->
            <div class="ep-tab-panel <?php echo !empty($myArmes)?'on':''; ?>" id="tab-armes">
                <?php if(empty($myArmes)): ?>
                <div style="text-align:center;padding:2.25rem;color:var(--muted-2)">
                    <i class="fas fa-gun" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.83rem">Aucune arme enregistrée à votre nom</div>
                </div>
                <?php else:
                    $ARME_ST = [
                        'enregistree' => ['Enregistrée', 'rgba(34,197,94,.1)',   'rgba(34,197,94,.25)',  '#4ade80', 'fas fa-check-circle'],
                        'suspendue'   => ['Suspendue',   'rgba(245,158,11,.1)',  'rgba(245,158,11,.25)', '#fcd34d', 'fas fa-pause-circle'],
                        'revoquee'    => ['Révoquée',    'rgba(239,68,68,.1)',   'rgba(239,68,68,.25)',  '#f87171', 'fas fa-ban'],
                    ];
                    $today = new DateTime();
                    foreach($myArmes as $arme):
                        [$asl,$abg,$abdr,$atc,$aico] = $ARME_ST[$arme['statut']] ?? ['—','rgba(100,116,139,.1)','rgba(100,116,139,.2)','#94a3b8','fas fa-question'];
                        $isExp = !empty($arme['date_expiration']) && new DateTime($arme['date_expiration']) < $today && $arme['statut']==='enregistree';
                ?>
                <div class="ep-inv-row" style="margin-bottom:.4rem;<?php echo $arme['statut']==='revoquee'?'opacity:.55':'';?>">
                    <!-- Icône arme -->
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0;font-size:.85rem">
                        <i class="fas fa-gun"></i>
                    </div>
                    <div class="ep-inv-desc">
                        <div class="ep-inv-title" style="font-size:.84rem">
                            <?php echo htmlspecialchars($arme['type_arme']); ?>
                            <?php if(!empty($arme['modele'])): ?>
                            <span style="font-weight:400;color:var(--muted-2)"> — <?php echo htmlspecialchars($arme['modele']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ep-inv-sub" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.18rem">
                            <?php if(!empty($arme['numero_serie'])): ?>
                            <span style="font-family:'DM Mono','Courier New',monospace;font-size:.68rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.15);border-radius:4px;padding:.1rem .4rem;color:#93c5fd"><?php echo htmlspecialchars($arme['numero_serie']); ?></span>
                            <?php endif; ?>
                            <?php if(!empty($arme['calibre'])): ?>
                            <span><?php echo htmlspecialchars($arme['calibre']); ?></span>
                            <?php endif; ?>
                            <span>Enreg. <?php echo date('d/m/Y',strtotime($arme['date_enregistrement'])); ?></span>
                            <?php if(!empty($arme['date_expiration'])): ?>
                            <span style="color:<?php echo $isExp?'#f87171':'var(--muted-2)';?>">
                                <i class="fas fa-calendar-xmark" style="font-size:.6rem;margin-right:.2rem"></i>Exp. <?php echo date('d/m/Y',strtotime($arme['date_expiration'])); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($arme['notes'])): ?>
                        <div style="margin-top:.2rem;font-size:.7rem;color:var(--muted-2);font-style:italic"><?php echo htmlspecialchars(mb_substr($arme['notes'],0,80)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <span class="ep-badge" style="background:<?php echo $abg;?>;color:<?php echo $atc;?>;border:1px solid <?php echo $abdr;?>">
                            <i class="<?php echo $aico;?>" style="font-size:.6rem"></i> <?php echo $asl; ?>
                        </span>
                        <?php if($isExp): ?>
                        <span class="ep-badge" style="margin-top:.25rem;display:flex;background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.22)">
                            <i class="fas fa-clock" style="font-size:.6rem"></i> Expirée
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div><!-- /tab-armes -->

            <!-- ONGLET CANDIDATURES -->
            <div class="ep-tab-panel" id="tab-cand">
                <?php if (empty($myCandidatures)): ?>
                <div style="text-align:center;padding:2.25rem;color:var(--muted-2)">
                    <i class="fas fa-file-circle-question" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.83rem">Aucune candidature déposée</div>
                    <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ep-btn ep-btn-primary ep-btn-sm" style="margin-top:.75rem"><i class="fas fa-plus"></i> Postuler à une faction</a>
                </div>
                <?php else:
                    $CAND_STATUSES = function_exists('academy_candidature_statuses') ? academy_candidature_statuses() : [];
                    $CAND_PROGRESS = ['en_attente'=>25,'en_cours'=>50,'en_examen'=>75,'valide'=>100,'refuse'=>100];
                ?>
                <div style="display:flex;flex-direction:column;gap:.6rem">
                <?php foreach ($myCandidatures as $c):
                    $fm = function_exists('academy_faction_meta') ? academy_faction_meta($c['faction_key']) : ['name'=>$c['faction_key'],'color'=>'#8b5cf6','icon'=>'building','slug'=>strtolower($c['faction_key'])];
                    $st = $CAND_STATUSES[$c['status']] ?? null;
                    $pct = $CAND_PROGRESS[$c['status']] ?? 0;
                ?>
                <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem">
                    <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.5rem">
                        <div style="width:28px;height:28px;border-radius:7px;background:<?php echo $fm['color']; ?>22;color:<?php echo $fm['color']; ?>;display:grid;place-items:center"><i class="fas fa-<?php echo htmlspecialchars($fm['icon']); ?>"></i></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:700;font-size:.88rem"><?php echo htmlspecialchars($fm['name']); ?></div>
                            <div style="font-size:.72rem;color:var(--muted-2)">Déposée le <?php echo date('d/m/Y', strtotime($c['created_at'])); ?></div>
                        </div>
                        <?php if ($st): ?>
                        <span class="ep-badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>"><i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo htmlspecialchars($st['label']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="height:5px;background:var(--border);border-radius:3px;overflow:hidden">
                        <div style="height:100%;background:<?php echo $st ? $st['color'] : '#8b5cf6'; ?>;width:<?php echo $pct; ?>%;transition:width .4s"></div>
                    </div>
                    <?php if (!empty($c['decision_note'])): ?>
                    <div style="margin-top:.6rem;padding:.55rem .7rem;background:rgba(255,255,255,.03);border-radius:7px;font-size:.78rem;color:#cbd5e1"><i class="fa-solid fa-comment-dots" style="color:var(--muted-2)"></i> <?php echo nl2br(htmlspecialchars($c['decision_note'])); ?></div>
                    <?php endif; ?>
                    <div style="margin-top:.55rem;display:flex;gap:.4rem;flex-wrap:wrap">
                        <a href="<?php echo SITE_URL; ?>/pages/academy/candidature_<?php echo $fm['slug']; ?>.php" class="ep-btn ep-btn-sm ep-btn-outline"><i class="fa-solid fa-eye"></i> Voir détails</a>
                        <?php if ($c['status'] === 'en_examen'): ?>
                        <a href="<?php echo SITE_URL; ?>/pages/academy/qcm_<?php echo $fm['slug']; ?>.php" class="ep-btn ep-btn-sm ep-btn-primary"><i class="fa-solid fa-clipboard-question"></i> Passer le QCM</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div><!-- /tab-cand -->

            <!-- ONGLET QCM -->
            <div class="ep-tab-panel" id="tab-qcm">
                <?php if (empty($myQCMs)): ?>
                <div style="text-align:center;padding:2.25rem;color:var(--muted-2)">
                    <i class="fas fa-circle-question" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.83rem">Aucun QCM passé</div>
                    <div style="font-size:.72rem;color:var(--muted);margin-top:.35rem">Les QCM sont accessibles une fois la candidature en statut « En cours ».</div>
                </div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:.55rem">
                <?php foreach ($myQCMs as $q):
                    $fm = function_exists('academy_faction_meta') ? academy_faction_meta($q['faction_key']) : ['name'=>$q['faction_key'],'color'=>'#8b5cf6','icon'=>'building'];
                    $passed = (bool)$q['passed'];
                ?>
                <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.7rem .95rem;display:flex;align-items:center;gap:.75rem">
                    <div style="width:30px;height:30px;border-radius:7px;background:<?php echo $fm['color']; ?>22;color:<?php echo $fm['color']; ?>;display:grid;place-items:center;flex-shrink:0"><i class="fas fa-<?php echo $fm['icon']; ?>"></i></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($fm['name']); ?></div>
                        <div style="font-size:.72rem;color:var(--muted-2)"><?php echo date('d/m/Y H:i', strtotime($q['started_at'])); ?></div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.1rem;color:<?php echo $passed?'#6ee7b7':'#fca5a5'; ?>"><?php echo (int)$q['percentage']; ?>%</div>
                        <div style="font-size:.68rem;color:var(--muted-2)"><?php echo (int)$q['score']; ?>/<?php echo (int)$q['total']; ?></div>
                    </div>
                    <span class="ep-badge" style="background:<?php echo $passed?'rgba(16,185,129,.14)':'rgba(239,68,68,.14)'; ?>;color:<?php echo $passed?'#6ee7b7':'#fca5a5'; ?>"><i class="fa-solid fa-<?php echo $passed?'check':'xmark'; ?>"></i> <?php echo $passed?'Réussi':'Échoué'; ?></span>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div><!-- /tab-qcm -->

            <!-- ONGLET FORMATIONS -->
            <div class="ep-tab-panel" id="tab-formations">
                <?php if (empty($myFormations)): ?>
                <div style="text-align:center;padding:2.25rem;color:var(--muted-2)">
                    <i class="fas fa-chalkboard-user" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
                    <div style="font-size:.83rem">Aucune formation assignée</div>
                </div>
                <?php else:
                    $F_STATUSES = function_exists('academy_formation_statuses') ? academy_formation_statuses() : [];
                ?>
                <div style="display:flex;flex-direction:column;gap:.55rem">
                <?php foreach ($myFormations as $f):
                    $fm = function_exists('academy_faction_meta') ? academy_faction_meta($f['faction_key']) : ['name'=>$f['faction_key'],'color'=>'#10b981','icon'=>'building'];
                    $st = $F_STATUSES[$f['status']] ?? null;
                ?>
                <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:.8rem .95rem;display:flex;align-items:center;gap:.75rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $f['formation_color']; ?>22;color:<?php echo $f['formation_color']; ?>;display:grid;place-items:center;flex-shrink:0"><i class="fas fa-<?php echo $f['formation_icon']; ?>"></i></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:.88rem"><?php echo htmlspecialchars($f['formation_name']); ?></div>
                        <div style="font-size:.72rem;color:var(--muted-2)"><?php echo htmlspecialchars($fm['name']); ?> · Màj <?php echo date('d/m/Y', strtotime($f['updated_at'])); ?></div>
                    </div>
                    <?php if ($st): ?>
                    <span class="ep-badge" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>"><i class="fa-solid fa-<?php echo $st['icon']; ?>"></i> <?php echo htmlspecialchars($st['label']); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div><!-- /tab-formations -->

        </div>
    </div>
    <?php else: ?>
    <!-- Fallback quand pas de profil lié -->
    <div class="ep-card" style="border-color:rgba(139,92,246,.18)">
        <div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-clipboard-list" style="color:#c4b5fd"></i> Démarches et armement</div></div>
        <div class="ep-card-bd" style="text-align:center;padding:2rem 1.25rem">
            <i class="fas fa-link" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.75rem;color:#60a5fa"></i>
            <div style="font-size:.9rem;font-weight:600;margin-bottom:.4rem">Profil civil non lié</div>
            <div style="font-size:.8rem;color:var(--muted-2);margin-bottom:1.1rem">Liez votre profil du recensement LSPD pour accéder à vos demandes PPA et armes enregistrées.</div>
            <button onclick="openModal('linkModal')" class="ep-btn ep-btn-primary ep-btn-sm" style="width:100%;justify-content:center"><i class="fas fa-link"></i> Lier mon profil LSPD</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- MES DOCUMENTS -->
    <?php if($profile): ?>
    <div class="ep-card">
        <div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-folder-open" style="color:#c4b5fd"></i> Mes documents
            <?php if($myDocuments): ?><span style="background:rgba(139,92,246,.15);color:#c4b5fd;padding:.1rem .48rem;border-radius:10px;font-size:.63rem;margin-left:.35rem;font-weight:700"><?php echo count($myDocuments); ?></span><?php endif; ?>
        </div></div>
        <div class="ep-card-bd">
            <?php if(empty($myDocuments)): ?>
            <div style="text-align:center;padding:1.5rem 1rem;color:var(--muted-2)"><i class="fas fa-folder-open" style="font-size:1.8rem;opacity:.18;display:block;margin-bottom:.5rem"></i><div style="font-size:.82rem">Aucun document pour le moment.</div></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:.5rem">
                <?php foreach($myDocuments as $doc): ?>
                <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem .8rem;background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:9px">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(139,92,246,.12);color:#c4b5fd;display:grid;place-items:center;flex-shrink:0"><i class="fas <?php echo htmlspecialchars($doc['icon']); ?>"></i></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:.83rem;color:#f1f5f9"><?php echo htmlspecialchars($doc['label']); ?></div>
                        <div style="font-size:.7rem;color:var(--muted-2)"><i class="fas fa-calendar" style="opacity:.6;margin-right:.25rem"></i><?php echo !empty($doc['date'])?date('d/m/Y H:i',strtotime($doc['date'])):'—'; ?></div>
                    </div>
                    <?php if(!empty($doc['url'])): ?>
                    <a href="<?php echo htmlspecialchars($doc['url']); ?>" target="_blank" class="ep-btn ep-btn-primary ep-btn-sm"><i class="fas fa-external-link-alt"></i> Voir</a>
                    <?php else: ?>
                    <span style="font-size:.72rem;color:var(--muted-2);opacity:.7" title="Pas de document"><i class="fas fa-file-circle-xmark"></i></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- INFOS RP -->
    <div class="ep-card">
        <div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-circle-info" style="color:#a78bfa"></i> Infos utiles en jeu</div></div>
        <div class="ep-card-bd">
            <div class="info-grid">
                <?php foreach([
                    ['fa-phone','Urgences','555-0911','#4ade80'],
                    ['fa-ambulance','SAMS','555-0118','#f43f5e'],
                    ['fa-shield-halved','LSPD','555-0110','#3b82f6'],
                    ['fa-gavel','Avocat','/pages/avocats/','#d97706'],
                    ['fa-building','Gouvernement','/pages/gouv/','#1d4ed8'],
                    ['fa-car','Fourrière','555-IMPOUND','#f59e0b'],
                ] as [$ico,$lbl,$val,$col]): ?>
                <div class="info-item">
                    <div class="info-ico" style="background:<?php echo $col;?>18;color:<?php echo $col;?>"><i class="fas <?php echo $ico;?>"></i></div>
                    <div><div class="info-lbl"><?php echo $lbl;?></div><div class="info-val"><?php echo $val;?></div></div>
                </div>
                <?php endforeach;?>
            </div>
            <?php if($employment): ?>
            <div style="margin-top:.85rem;padding:.85rem;background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:9px;font-size:.82rem;line-height:1.7;color:#94a3b8">
                <div class="ep-label" style="margin-bottom:.4rem"><i class="fas fa-building" style="margin-right:.35rem;color:<?php echo $fColor;?>"></i>Mon employeur</div>
                Faction : <strong style="color:#f1f5f9"><?php echo htmlspecialchars($employment['faction_name']??$employment['faction']); ?></strong>
                · Grade : <strong style="color:#c084fc"><?php echo htmlspecialchars($employment['rank']??'—'); ?></strong>
                · Salaire : <strong style="color:#4ade80">$<?php echo number_format($employment['calculated_salary']??0,0,'.',','); ?></strong>
            </div>
            <?php endif;?>
        </div>
    </div>

</div><!-- /col droite -->
</div><!-- /ep-grid -->

<?php else: ?>
<div class="container">
<?php if($msg): ?><div class="ep-alert <?php echo $msgType; ?>" style="margin:.5rem 0 1rem"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<div class="page-hero fade-in"><div><div class="page-eyebrow"><i class="fas fa-balance-scale"></i> Législation</div><div class="page-title">Textes de Loi</div><div class="page-sub">Législation de l'État de San Andreas — <?php echo count($laws); ?> codes.</div></div></div>
<?php if(empty($laws)): ?>
<div class="ep-card"><div class="ep-card-bd" style="text-align:center;padding:3rem;color:var(--muted-2)"><i class="fas fa-book" style="font-size:2.5rem;opacity:.15;display:block;margin-bottom:.75rem"></i>Aucun texte de loi disponible.</div></div>
<?php else: ?>
<div class="lois-grid fade-in">
<div class="lois-sidebar">
<div class="ep-card">
<div class="ep-card-hd"><div class="ep-card-title"><i class="fas fa-landmark" style="color:#3b82f6"></i> Codes &amp; Constitutions</div></div>
<div style="padding:.55rem .75rem;border-bottom:1px solid var(--border)"><div style="position:relative"><i class="fas fa-search" style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.68rem;pointer-events:none"></i><input type="text" style="padding:.4rem .65rem .4rem 1.75rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;color:#e2e8f0;font-family:'Inter',sans-serif;font-size:.75rem;width:100%" placeholder="Filtrer..." oninput="filterLC(this.value)"></div></div>
<div class="lois-code-list">
<?php foreach($laws as $ll): [$llt,$llc]=$LTYPE_META[$ll['type']]??['Code','#3b82f6']; $llact=(int)$ll['id']===$activeLawId; ?>
<a class="lc-item <?php echo $llact?'on':''; ?>" href="?view=lois&law=<?php echo (int)$ll['id']; ?>" data-s="<?php echo strtolower(htmlspecialchars($ll['title'].' '.$ll['code'].' '.$llt)); ?>">
<div class="lc-badge" style="background:<?php echo $llc; ?>18;border:1.5px solid <?php echo $llc; ?>28;color:<?php echo $llc; ?>"><?php echo htmlspecialchars($ll['code']); ?></div>
<div style="flex:1;min-width:0"><div class="lc-name"><?php echo htmlspecialchars($ll['title']); ?></div><div class="lc-sub"><?php echo $llt; ?></div></div>
<span class="lc-n"><?php echo $ll['nb_actifs']; ?></span>
</a>
<?php endforeach; ?>
</div>
</div>
<?php if($activeLawObj): [$llt,$llc]=$LTYPE_META[$activeLawObj['type']]??['Code','#3b82f6']; ?>
<div class="ep-card"><div class="ep-card-bd">
<div style="display:flex;align-items:center;gap:.7rem;margin-bottom:.85rem">
<div style="width:38px;height:38px;border-radius:9px;background:<?php echo $llc; ?>18;border:1.5px solid <?php echo $llc; ?>28;color:<?php echo $llc; ?>;display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-weight:800;font-size:.6rem;flex-shrink:0"><?php echo htmlspecialchars($activeLawObj['code']); ?></div>
<div><div style="font-weight:700;font-size:.83rem;color:#f1f5f9"><?php echo htmlspecialchars($activeLawObj['title']); ?></div><div style="font-size:.67rem;color:<?php echo $llc; ?>"><?php echo $llt; ?></div></div>
</div>
<?php if($activeLawObj['description']): ?><div style="font-size:.74rem;color:var(--muted-2);margin-bottom:.75rem;line-height:1.55"><?php echo htmlspecialchars($activeLawObj['description']); ?></div><?php endif; ?>
<div class="ep-field"><span class="ep-field-lbl">Articles actifs</span><span class="ep-field-val" style="color:#93c5fd;font-family:'Montserrat',serif;font-weight:800"><?php echo $activeLawObj['nb_actifs']; ?></span></div>
<div class="ep-field" style="border:none"><span class="ep-field-lbl">Version</span><span class="ep-field-val"><?php echo htmlspecialchars($activeLawObj['version']??'1.0'); ?></span></div>
</div></div>
<?php endif; ?>
</div>
<div class="lois-panel">
<div class="ep-card">
<div class="ep-card-hd" style="flex-wrap:wrap;gap:.5rem">
<?php if($activeLawObj): ?><div class="ep-card-title"><span style="font-family:'DM Mono',monospace;font-size:.7rem;font-weight:700;color:<?php echo $llc; ?>;background:<?php echo $llc; ?>15;padding:.18rem .55rem;border-radius:4px;border:1px solid <?php echo $llc; ?>25"><?php echo htmlspecialchars($activeLawObj['code']); ?></span> <span style="font-weight:700;font-size:.85rem;color:#f1f5f9"><?php echo htmlspecialchars($activeLawObj['title']); ?></span></div><?php endif; ?>
<?php if($lawSearch!==''||$lawChapter!==''): ?><a href="?view=lois&law=<?php echo $activeLawId; ?>" class="ep-btn ep-btn-ghost ep-btn-xs" style="margin-left:auto"><i class="fas fa-times"></i> Effacer</a><?php endif; ?>
</div>
<div class="law-filters">
<form method="GET" style="display:contents">
<input type="hidden" name="view" value="lois"><input type="hidden" name="law" value="<?php echo $activeLawId; ?>">
<div class="lois-inp-wrap"><i class="fas fa-search"></i><input type="text" name="lq" class="lois-inp" value="<?php echo htmlspecialchars($lawSearch); ?>" placeholder="Rechercher dans les articles..."></div>
<?php if(!empty($lawChapters)): ?>
<select name="lch" class="lois-sel" onchange="this.form.submit()"><option value="">Tous chapitres</option>
<?php foreach($lawChapters as $lch): ?><option value="<?php echo htmlspecialchars($lch['chapter']); ?>" <?php echo $lawChapter===$lch['chapter']?'selected':''; ?>>Ch.<?php echo htmlspecialchars($lch['chapter']); ?><?php if($lch['chapter_title']): ?> — <?php echo htmlspecialchars(mb_substr($lch['chapter_title'],0,22)); ?><?php endif; ?></option><?php endforeach; ?>
</select>
<?php endif; ?>
<button type="submit" class="ep-btn ep-btn-primary ep-btn-sm"><i class="fas fa-search"></i></button>
</form>
</div>
</div>
<div class="ep-card">
<div class="law-list">
<?php if(empty($lawArticles)): ?>
<div style="text-align:center;padding:3rem 1rem;color:var(--muted-2)"><i class="fas fa-scroll" style="font-size:2.5rem;opacity:.1;display:block;margin-bottom:.85rem"></i><div style="font-weight:600;font-size:.87rem">Aucun article<?php if($lawSearch): ?> pour "<?php echo htmlspecialchars($lawSearch); ?>"<?php endif; ?></div></div>
<?php elseif($isLP):
$lpBySection=[];
foreach($lawArticles as $la2) $lpBySection[$la2['section']?:'Autre'][]=$la2;
$LP_ORDER=['Crime','Délit Majeur','Délit Mineur','Infraction','Financier','Autre'];
uksort($lpBySection,fn($a,$b)=>(array_search($a,$LP_ORDER)??99)-(array_search($b,$LP_ORDER)??99));
foreach($lpBySection as $sn=>$sarts):
$sc2=$LP_COLORS[$sn]??'#64748b';
?>
<div class="lp-sh" style="background:<?php echo $sc2; ?>0d;border:1px solid <?php echo $sc2; ?>25;color:<?php echo $sc2; ?>"><span style="width:7px;height:7px;border-radius:50%;background:<?php echo $sc2; ?>;flex-shrink:0"></span><?php echo htmlspecialchars($sn); ?> <span style="font-weight:400;opacity:.5;font-size:.6rem">(<?php echo count($sarts); ?>)</span></div>
<div style="overflow-x:auto;margin-bottom:.6rem"><table class="lp-tbl"><thead><tr><th>Article</th><th>Infraction</th><th>Prison</th><th>Amende</th></tr></thead><tbody>
<?php foreach($sarts as $la2):
$pr='—';$am='—';
foreach(explode("\n",$la2['content']) as $ln2){
if(str_starts_with($ln2,'Temps de prison :')){ $v=trim(substr($ln2,17)); if($v!=='Aucun')$pr=$v; }
if(str_starts_with($ln2,'Amende :')) $am=trim(substr($ln2,8));
}
?>
<tr onclick="showLaw(<?php echo htmlspecialchars(json_encode($la2)); ?>)">
<td><span style="font-family:'DM Mono',monospace;font-size:.67rem;color:#93c5fd"><?php echo htmlspecialchars($la2['article_number']); ?></span></td>
<td style="font-weight:600"><?php echo htmlspecialchars($la2['title']); ?></td>
<td style="font-family:'DM Mono',monospace;font-size:.72rem;color:<?php echo $pr==='—'?'var(--muted)':'#fca5a5'; ?>"><?php echo htmlspecialchars($pr); ?></td>
<td style="font-family:'DM Mono',monospace;font-size:.72rem;font-weight:700;color:<?php echo $am==='—'?'var(--muted)':'#fde68a'; ?>"><?php echo htmlspecialchars($am); ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endforeach; ?>
<?php else:
foreach($lawGrouped['__none__']??[] as $sk=>$sa){ foreach($sa as $la2): ?>
<div class="law-item" id="li-<?php echo $la2['id']; ?>"><div class="law-item-hd" onclick="toggleLaw(<?php echo $la2['id']; ?>)"><span class="law-num"><?php echo htmlspecialchars($la2['article_number']); ?></span><div class="law-ttl"><?php echo htmlspecialchars($la2['title']??''); ?></div><button class="ep-btn ep-btn-ghost ep-btn-xs" style="flex-shrink:0;margin-left:.4rem" onclick="event.stopPropagation();showLaw(<?php echo htmlspecialchars(json_encode($la2)); ?>)"><i class="fas fa-expand-alt"></i></button><i class="fas fa-chevron-down law-chev"></i></div><div class="law-item-body"><?php echo htmlspecialchars($la2['content']); ?></div></div>
<?php endforeach; } ?>
<?php foreach($lawGrouped as $gk=>$gsections):
if($gk==='__none__') continue;
$gcht='';$gcc=0;
foreach($gsections as $gsa){$gcc+=count($gsa);if(!$gcht&&!empty($gsa[0]['chapter_title']))$gcht=$gsa[0]['chapter_title'];}
?>
<div class="law-ch"><span><i class="fas fa-book-open" style="opacity:.4;margin-right:.38rem"></i>Chap. <?php echo htmlspecialchars($gk); ?><?php if($gcht): ?> — <?php echo htmlspecialchars($gcht); ?><?php endif; ?></span><span style="font-weight:400;font-size:.62rem;color:var(--muted)"><?php echo $gcc; ?> art.</span></div>
<?php foreach($gsections as $gsk=>$gsa):
if($gsk!=='__none__'): ?><div class="law-sec"><i class="fas fa-minus" style="opacity:.3;font-size:.57rem;margin-right:.3rem"></i><?php echo htmlspecialchars($gsk); ?></div><?php endif; ?>
<?php foreach($gsa as $la2): ?>
<div class="law-item" id="li-<?php echo $la2['id']; ?>"><div class="law-item-hd" onclick="toggleLaw(<?php echo $la2['id']; ?>)"><span class="law-num"><?php echo htmlspecialchars($la2['article_number']); ?></span><div class="law-ttl"><?php echo htmlspecialchars($la2['title']??''); ?></div><button class="ep-btn ep-btn-ghost ep-btn-xs" style="flex-shrink:0;margin-left:.4rem" onclick="event.stopPropagation();showLaw(<?php echo htmlspecialchars(json_encode($la2)); ?>)"><i class="fas fa-expand-alt"></i></button><i class="fas fa-chevron-down law-chev"></i></div><div class="law-item-body"><?php echo htmlspecialchars($la2['content']); ?></div></div>
<?php endforeach; endforeach; endforeach; ?>
<?php endif; ?>
</div></div>
</div>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</main>

<footer>
    <div class="container footer-inner">
        <div>&copy; <?php echo date('Y'); ?> <a href="/pages/dashboard.php"><?php echo htmlspecialchars($siteName); ?></a>. Tous droits réservés.</div>
        <div>Propulsé par Wolf12_ — développeur de Lysoria</div>
    </div>
</footer>


<div id="lawModal" class="ep-modal-ov">
    <div class="ep-modal ep-modal-lg">
        <div class="ep-modal-hd"><h3><i class="fas fa-scroll" style="color:#93c5fd"></i> <span id="lmTitle">Article</span></h3><button class="ep-modal-cl" onclick="closeModal('lawModal')">×</button></div>
        <div class="ep-modal-bd">
            <div id="lmNum" style="display:inline-block;font-family:'DM Mono',monospace;font-size:.84rem;font-weight:700;color:#93c5fd;background:rgba(59,130,246,.1);padding:.2rem .7rem;border-radius:6px;border:1px solid rgba(59,130,246,.2);margin-bottom:.7rem"></div>
            <div id="lmMeta" style="font-size:.7rem;color:var(--muted-2);display:flex;flex-wrap:wrap;gap:.55rem;margin-bottom:.75rem"></div>
            <div id="lmBody" style="font-size:.84rem;line-height:2;color:#cbd5e1;white-space:pre-wrap;background:rgba(0,0,0,.18);border-radius:8px;padding:1rem;max-height:54vh;overflow-y:auto"></div>
        </div>
        <div class="ep-modal-ft"><button class="ep-btn ep-btn-ghost" onclick="closeModal('lawModal')"><i class="fas fa-times"></i> Fermer</button></div>
    </div>
</div>

<!-- ── MODALES ── -->
<div id="linkModal" class="ep-modal-ov">
    <div class="ep-modal">
        <div class="ep-modal-hd"><h3><i class="fas fa-link" style="color:#38bdf8"></i> Lier mon profil du recensement</h3><button class="ep-modal-cl" onclick="closeModal('linkModal')">×</button></div>
        <form method="POST"><div class="ep-modal-bd"><input type="hidden" name="action" value="link_profile">
            <div style="margin-bottom:.85rem"><label class="ep-label">Filtrer</label><input type="text" id="psi1" class="ep-input" placeholder="Nom, prénom…" oninput="filterSel('profileSel1',this.value)"></div>
            <label class="ep-label">Sélectionner le profil</label>
            <select name="profile_id" id="profileSel1" class="ep-input" size="8" required style="height:200px;padding:.4rem">
                <?php foreach($allProfiles as $ap): ?><option value="<?php echo $ap['id'];?>" data-s="<?php echo strtolower($ap['firstname'].' '.$ap['lastname'].' '.($ap['job']??'')); ?>"><?php echo htmlspecialchars($ap['firstname'].' '.$ap['lastname']); ?><?php if($ap['job']): ?> — <?php echo htmlspecialchars($ap['job']);?><?php endif;?></option><?php endforeach;?>
            </select>
        </div><div class="ep-modal-ft"><button type="button" class="ep-btn ep-btn-ghost" onclick="closeModal('linkModal')">Annuler</button><button type="submit" class="ep-btn ep-btn-primary"><i class="fas fa-link"></i> Lier</button></div></form>
    </div>
</div>

<div id="unlinkModal" class="ep-modal-ov">
    <div class="ep-modal">
        <div class="ep-modal-hd"><h3><i class="fas fa-sync-alt" style="color:#38bdf8"></i> Changer de profil</h3><button class="ep-modal-cl" onclick="closeModal('unlinkModal')">×</button></div>
        <form method="POST"><div class="ep-modal-bd"><input type="hidden" name="action" value="link_profile">
            <input type="text" id="psi2" class="ep-input" placeholder="Filtrer…" oninput="filterSel('profileSel2',this.value)" style="margin-bottom:.75rem">
            <select name="profile_id" id="profileSel2" class="ep-input" size="7" required style="height:180px;padding:.4rem">
                <?php foreach($allProfiles as $ap): ?><option value="<?php echo $ap['id'];?>" data-s="<?php echo strtolower($ap['firstname'].' '.$ap['lastname'].' '.($ap['job']??'')); ?>" <?php echo (!empty($user['profile_id'])&&$user['profile_id']==$ap['id'])?'selected':'';?>><?php echo htmlspecialchars($ap['firstname'].' '.$ap['lastname']); ?><?php if($ap['job']): ?> — <?php echo htmlspecialchars($ap['job']);?><?php endif;?></option><?php endforeach;?>
            </select>
        </div><div class="ep-modal-ft"><button type="button" class="ep-btn ep-btn-ghost" onclick="closeModal('unlinkModal')">Annuler</button><button type="submit" class="ep-btn ep-btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div></form>
    </div>
</div>

<?php if($activeChar):
    $ecFaction = ($activeChar['faction'] ?? 'NONE') !== 'NONE' ? ($activeChar['faction_name'] ?: $activeChar['faction']) : 'Civil';
    $ecColor   = ($activeChar['faction'] ?? 'NONE') !== 'NONE' && !empty($activeChar['faction_color']) ? $activeChar['faction_color'] : '#64748b';
    $ecGrade   = trim((string)($activeChar['faction_rank'] ?: $activeChar['faction_grade'] ?: ''));
?>
<div id="editCharModal" class="ep-modal-ov">
    <div class="ep-modal">
        <div class="ep-modal-hd"><h3><i class="fas fa-user-pen" style="color:#38bdf8"></i> Modifier mon personnage</h3><button class="ep-modal-cl" onclick="closeModal('editCharModal')">×</button></div>
        <form method="POST" enctype="multipart/form-data">
            <div class="ep-modal-bd">
                <input type="hidden" name="action" value="update_character">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($charCsrf); ?>">

                <label id="ecUpload" style="position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;border:1px dashed rgba(255,255,255,.14);border-radius:12px;min-height:150px;cursor:pointer;overflow:hidden;color:var(--muted-2);font-size:.78rem;margin-bottom:1rem;transition:border-color .2s">
                    <img id="ecPreview" src="<?php echo htmlspecialchars($activeChar['avatar'] ?? ''); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;<?php echo empty($activeChar['avatar'])?'display:none':''; ?>">
                    <span id="ecUploadHint" style="display:<?php echo empty($activeChar['avatar'])?'flex':'none'; ?>;flex-direction:column;align-items:center;gap:.4rem;text-align:center;padding:0 1rem">
                        <i class="fas fa-image" style="font-size:1.4rem;color:#3b82f6"></i>
                        Cliquez pour ajouter une image.<br>Formats acceptés : PNG, JPG, JPEG (max 4 Mo)
                    </span>
                    <input type="file" name="avatar" id="ecAvatarInput" accept="image/png,image/jpeg" style="display:none">
                </label>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:.85rem">
                    <div><label class="ep-label">Nom</label><input type="text" name="lastname" class="ep-input" maxlength="100" required value="<?php echo htmlspecialchars($activeChar['lastname']); ?>"></div>
                    <div><label class="ep-label">Prénom</label><input type="text" name="firstname" class="ep-input" maxlength="100" required value="<?php echo htmlspecialchars($activeChar['firstname']); ?>"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1rem">
                    <div><label class="ep-label">Taille (cm)</label><input type="number" name="height_cm" class="ep-input" min="100" max="250" value="<?php echo htmlspecialchars((string)($activeChar['height_cm'] ?? '')); ?>"></div>
                    <div><label class="ep-label">Âge</label><input type="number" name="age" class="ep-input" min="18" max="120" value="<?php echo htmlspecialchars((string)($activeChar['age'] ?? '')); ?>"></div>
                </div>

                <div style="border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;background:rgba(255,255,255,.02)">
                    <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted-2);margin-bottom:.6rem"><i class="fas fa-lock" style="margin-right:.3rem"></i>Géré par l'administration</div>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;font-size:.8rem">
                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .65rem;border-radius:12px;background:<?php echo $ecColor;?>15;border:1px solid <?php echo $ecColor;?>30;color:<?php echo $ecColor;?>;font-weight:600"><i class="fas fa-building"></i> <?php echo htmlspecialchars($ecFaction); ?></span>
                        <span style="color:var(--muted-2)"><i class="fas fa-medal" style="margin-right:.3rem"></i><?php echo $ecGrade !== '' ? htmlspecialchars($ecGrade) : '—'; ?></span>
                        <span style="color:var(--muted-2)"><i class="fas fa-calendar" style="margin-right:.3rem"></i>Créé le <?php echo !empty($activeChar['created_at']) ? date('d/m/Y', strtotime($activeChar['created_at'])) : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="ep-modal-ft">
                <button type="button" class="ep-btn ep-btn-ghost" onclick="closeModal('editCharModal')">Annuler</button>
                <button type="submit" class="ep-btn ep-btn-primary"><i class="fas fa-save"></i> Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="refuseModal" class="ep-modal-ov">
    <div class="ep-modal">
        <div class="ep-modal-hd"><h3><i class="fas fa-times" style="color:#f87171"></i> Refuser le contrat</h3><button class="ep-modal-cl" onclick="closeModal('refuseModal')">×</button></div>
        <form method="POST"><div class="ep-modal-bd"><input type="hidden" name="action" value="refuse_contract"><input type="hidden" name="doc_id" id="refDocId">
            <p style="font-size:.82rem;color:#94a3b8;margin-bottom:1rem">Contrat : <strong id="refDocTitle" style="color:#f1f5f9"></strong></p>
            <label class="ep-label">Motif (optionnel)</label>
            <textarea name="motif" class="ep-input" style="min-height:80px;resize:vertical" placeholder="Expliquez votre refus…"></textarea>
        </div><div class="ep-modal-ft"><button type="button" class="ep-btn ep-btn-ghost" onclick="closeModal('refuseModal')">Annuler</button><button type="submit" class="ep-btn ep-btn-danger"><i class="fas fa-times"></i> Confirmer</button></div></form>
    </div>
</div>

<div id="previewModal" class="ep-modal-ov">
    <div class="ep-modal ep-modal-lg">
        <div class="ep-modal-hd"><h3><i class="fas fa-scroll" style="color:#a78bfa"></i> Lecture du contrat</h3><button class="ep-modal-cl" onclick="closeModal('previewModal')">×</button></div>
        <div class="ep-modal-bd"><pre id="contractText" style="white-space:pre-wrap;font-size:.82rem;line-height:1.75;color:#cbd5e1;font-family:'DM Mono','Courier New',monospace"></pre></div>
        <div class="ep-modal-ft"><button class="ep-btn ep-btn-ghost" onclick="closeModal('previewModal')">Fermer</button></div>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden'}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow=''}
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.ep-modal-ov.open').forEach(m=>{m.classList.remove('open');document.body.style.overflow=''})});
document.querySelectorAll('.ep-modal-ov').forEach(o=>o.addEventListener('click',e=>{if(e.target===o){o.classList.remove('open');document.body.style.overflow=''}}));
function switchTab(btn,id){
    const p=btn.closest('.ep-card-bd');
    p.querySelectorAll('.ep-tab').forEach(t=>t.classList.remove('on'));
    p.querySelectorAll('.ep-tab-panel').forEach(t=>t.classList.remove('on'));
    btn.classList.add('on');document.getElementById(id).classList.add('on');
}
function openRefuseModal(id,title){document.getElementById('refDocId').value=id;document.getElementById('refDocTitle').textContent=title;openModal('refuseModal')}
function previewContract(txt){document.getElementById('contractText').textContent=txt;openModal('previewModal')}
function filterSel(selId,q){q=q.toLowerCase();document.querySelectorAll('#'+selId+' option').forEach(o=>{o.style.display=(!q||o.dataset.s.includes(q))?'':'none'})}

// Aperçu photo — modale "Modifier mon personnage"
document.getElementById('ecAvatarInput')?.addEventListener('change',function(){
    const f=this.files&&this.files[0];
    if(f){
        const img=document.getElementById('ecPreview');
        img.src=URL.createObjectURL(f);img.style.display='block';
        document.getElementById('ecUploadHint').style.display='none';
    }
});

function toggleLaw(id){document.getElementById('li-'+id)?.classList.toggle('open');}
function filterLC(q){q=q.toLowerCase();document.querySelectorAll('.lc-item').forEach(el=>{el.style.display=(!q||el.dataset.s.includes(q))?'':'none';});}
function showLaw(a){
    document.getElementById('lmNum').textContent=a.article_number||'';
    document.getElementById('lmTitle').textContent=a.title||a.article_number||'Article';
    var eh=function(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;};
    var meta=[];
    if(a.chapter&&a.chapter!=='__none__'&&a.chapter.trim()) meta.push('<i class="fas fa-book-open" style="opacity:.4"></i> Chap. '+eh(a.chapter)+(a.chapter_title?' \u2014 '+eh(a.chapter_title.substring(0,50)):''));
    if(a.section&&a.section!=='__none__'&&a.section.trim()) meta.push('<i class="fas fa-minus" style="opacity:.35"></i> '+eh(a.section));
    document.getElementById('lmMeta').innerHTML=meta.join('<span style="opacity:.2;margin:0 .3rem">·</span>');
    document.getElementById('lmBody').textContent=a.content||'';
    openModal('lawModal');
}

</script>
<?php nexium_theme_script(); ?>
</body>
</html>