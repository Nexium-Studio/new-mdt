<?php
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';

// Grade lu directement depuis users.faction_grade (faction_rank est souvent NULL)
$__isStaffAll = hasRole(['*','Administrateur','Développeur','Staff']);
$__samsDb     = null;
$__samsGrade  = '';
try {
    $__samsDb      = Database::getInstance()->getConnection();
    $__gradeDbStmt = $__samsDb->prepare('SELECT faction_grade FROM users WHERE id = ? LIMIT 1');
    $__gradeDbStmt->execute([$_SESSION['user_id']]);
    $__gradeDbRow  = $__gradeDbStmt->fetch(PDO::FETCH_ASSOC);
    $__samsGrade   = $__gradeDbRow['faction_grade'] ?? '';
} catch (Exception $e) {
    $__samsGrade = $_SESSION['user_faction_rank'] ?? $_SESSION['user_grade'] ?? '';
}

// Charge les grades SAMS (rank_order DESC = du plus bas au plus haut => niveau croissant)
$SAMS_GRADES_LEVEL = [];
try {
    if (!$__samsDb) { $__samsDb = Database::getInstance()->getConnection(); }
    $__samsGradesQ = $__samsDb->prepare(
        "SELECT fg.name, fg.rank_order FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = 'SAMS' ORDER BY fg.rank_order DESC"
    );
    $__samsGradesQ->execute();
    $__lvl = 1;
    foreach ($__samsGradesQ->fetchAll(PDO::FETCH_ASSOC) as $__gr) {
        $SAMS_GRADES_LEVEL[$__gr['name']] = $__lvl++;
    }
} catch (Exception $e) {
    $SAMS_GRADES_LEVEL = [
        'Stagiaire'=>1,'Paramedic I'=>2,'Paramedic II'=>3,
        'Medecin I'=>4,'Medecin II'=>5,'Clinical Supervisor'=>6,'Director'=>7,
    ];
}

$__samsLevel     = $SAMS_GRADES_LEVEL[$__samsGrade] ?? 0;
$__lvlMedecin    = $SAMS_GRADES_LEVEL['Medecin I']          ?? 4;
$__lvlSupervisor = $SAMS_GRADES_LEVEL['Clinical Supervisor'] ?? 6;
$__lvlDirecteur  = $SAMS_GRADES_LEVEL['Director']            ?? 7;
$__isMedecin    = $__isStaffAll || $__samsLevel >= $__lvlMedecin;
$__isSupervisor = $__isStaffAll || $__samsLevel >= $__lvlSupervisor;
$__isDirecteur  = $__isStaffAll || $__samsLevel >= $__lvlDirecteur;

$__samsAllPages = [
    ['file'=>'dashboard',           'label'=>'Tableau de bord',     'icon'=>'fas fa-heartbeat',        'path'=>'/pages/sams/dashboard.php',                      'section'=>''],
    ['file'=>'patients',            'label'=>'Dossiers Patients',   'icon'=>'fas fa-user-injured',     'path'=>'/pages/sams/patients.php',                       'section'=>'Soins'],
    ['file'=>'triage',              'label'=>'Triage / Urgences',   'icon'=>'fas fa-hospital',         'path'=>'/pages/sams/triage.php',                         'section'=>''],
    ['file'=>'interventions',       'label'=>'Interventions',       'icon'=>'fas fa-ambulance',        'path'=>'/pages/sams/interventions.php',                   'section'=>''],
    ['file'=>'rapports',            'label'=>'Rapports médicaux',   'icon'=>'fas fa-file-medical',     'path'=>'/pages/sams/rapports.php',                       'section'=>''],
    ['file'=>'ordonnance',          'label'=>'Ordonnances',         'icon'=>'fas fa-prescription',     'path'=>'/pages/sams/documents/ordonnance.php',           'section'=>'Documents'],
    ['file'=>'certificat-deces',    'label'=>'Certificats décès',   'icon'=>'fas fa-cross',            'path'=>'/pages/sams/documents/certificat-deces.php',     'section'=>''],
    ['file'=>'arret-travail',       'label'=>'Arrêts de travail',   'icon'=>'fas fa-bed',              'path'=>'/pages/sams/documents/arret-travail.php',        'section'=>''],
    ['file'=>'certificat-aptitude', 'label'=>'Aptitude',            'icon'=>'fas fa-clipboard-check',  'path'=>'/pages/sams/documents/certificat-aptitude.php',  'section'=>''],
    ['file'=>'traumatisme',         'label'=>'Traumatismes',        'icon'=>'fas fa-bone',             'path'=>'/pages/sams/documents/traumatisme.php',          'section'=>''],
    ['file'=>'grossesse',           'label'=>'Suivi grossesse',     'icon'=>'fas fa-baby',             'path'=>'/pages/sams/documents/grossesse.php',            'section'=>''],
    ['file'=>'suivi-psy',           'label'=>'Suivi psy',           'icon'=>'fas fa-brain',            'path'=>'/pages/sams/documents/suivi-psy.php',            'section'=>''],
    ['file'=>'evaluation-psy',      'label'=>'Évaluation psy',      'icon'=>'fas fa-notes-medical',    'path'=>'/pages/sams/documents/evaluation-psy.php',       'section'=>''],
    ['file'=>'naissance',           'label'=>'Naissances',          'icon'=>'fas fa-star',             'path'=>'/pages/sams/documents/naissance.php',            'section'=>''],
    ['file'=>'aptitude-ppa',        'label'=>'Aptitude PPA',        'icon'=>'fas fa-shield-halved',    'path'=>'/pages/sams/documents/aptitude-ppa.php',         'section'=>''],
    ['file'=>'facturation',         'label'=>'Facturation',         'icon'=>'fas fa-receipt',          'path'=>'/pages/sams/facturation.php',                    'section'=>'Gestion'],
    ['file'=>'pharmacie',           'label'=>'Pharmacie / Stock',   'icon'=>'fas fa-pills',            'path'=>'/pages/sams/pharmacie.php',                      'section'=>''],
    ['file'=>'statistiques',        'label'=>'Statistiques',        'icon'=>'fas fa-chart-bar',        'path'=>'/pages/sams/statistiques.php',                   'section'=>''],
    ['file'=>'planning',            'label'=>'Planning des gardes', 'icon'=>'fas fa-calendar-days',    'path'=>'/pages/sams/planning.php',                       'section'=>'Administration'],
    ['file'=>'effectifs',           'label'=>'Effectifs',           'icon'=>'fas fa-users-gear',       'path'=>'/pages/sams/effectifs.php',                      'section'=>''],
    ['file'=>'logs',                'label'=>"Logs d'activité",     'icon'=>'fas fa-clipboard-list',   'path'=>'/pages/sams/logs.php',                           'section'=>''],
];

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$__now = new DateTime();

// ── Active pages & permissions depuis mdt.php ─────────────────────────────
// Charge active_pages (admin_panel_config) + filtre les permissions de grade
// (panel_page_permissions). Staff bypass toujours.
$__samsActivePages = [];
try {
    if (!$__samsDb) { $__samsDb = Database::getInstance()->getConnection(); }
    $__samsQ  = $__samsDb->prepare(
        "SELECT active_pages FROM admin_panel_config WHERE panel_key='SAMS' LIMIT 1"
    );
    $__samsQ->execute();
    $__samsRow = $__samsQ->fetch();
    if ($__samsRow && !empty($__samsRow['active_pages'])) {
        $__samsActivePages = json_decode($__samsRow['active_pages'], true) ?: [];
    }
} catch (Exception $e) { $__samsDb = null; }

// Rediriger si la page courante est désactivée dans mdt.php
if (isset($__samsActivePages[$currentPage]) && $__samsActivePages[$currentPage] === false) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Rediriger si le grade n'est pas autorisé sur la page courante
if (!$__isStaffAll && isset($__samsDb)) {
    try {
        $__samsPQ = $__samsDb->prepare(
            "SELECT allowed_grades FROM panel_page_permissions
             WHERE panel_key='SAMS' AND page_key=? LIMIT 1"
        );
        $__samsPQ->execute([$currentPage]);
        $__samsPR = $__samsPQ->fetch();
        if ($__samsPR) {
            $__samsAllowed = json_decode($__samsPR['allowed_grades'], true) ?: ['everyone'];
            if (!in_array('everyone', $__samsAllowed) && !in_array($__samsGrade, $__samsAllowed)) {
                redirect(SITE_URL . '/pages/dashboard.php');
            }
        }
    } catch (Exception $e) {}
}

// Construire la nav filtrée :
//  1. Exclut les pages désactivées dans mdt.php
//  2. Exclut les pages dont le grade n'est pas dans la whitelist
$__samsNavPages = [];
foreach ($__samsAllPages as $__samsPage) {
    $__spFile = $__samsPage['file'];

    // Filtre active_pages
    if (isset($__samsActivePages[$__spFile]) && $__samsActivePages[$__spFile] === false) {
        continue;
    }

    // Filtre grade (staff bypass)
    if (!$__isStaffAll && isset($__samsDb)) {
        try {
            $__samsGQ = $__samsDb->prepare(
                "SELECT allowed_grades FROM panel_page_permissions
                 WHERE panel_key='SAMS' AND page_key=? LIMIT 1"
            );
            $__samsGQ->execute([$__spFile]);
            $__samsGR = $__samsGQ->fetch();
            if ($__samsGR) {
                $__samsGA = json_decode($__samsGR['allowed_grades'], true) ?: ['everyone'];
                if (!in_array('everyone', $__samsGA) && !in_array($__samsGrade, $__samsGA)) {
                    continue;
                }
            }
        } catch (Exception $e) {}
    }

    $__samsNavPages[] = $__samsPage;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'SAMS'); ?> – SAMS Medical Command</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700&family=Barlow:wght@300;400;500;600&family=Barlow+Condensed:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════════════════
   SAMS MEDICAL — Design System ROUGE
   Couleur principale : --red (#e11d48)
   ══════════════════════════════════════════════════════════════════════════ */
:root {
    --red:      #e11d48;
    --red-d:    #9f1239;
    --red-l:    #fb7185;
    --red-xl:   #fecdd3;
    --green:    #10b981;
    --green-l:  #6ee7b7;
    --yellow:   #f59e0b;
    --yellow-l: #fde68a;
    --orange:   #f97316;
    --purple:   #8b5cf6;
    --blue:     #3b82f6;
    --cyan:     #06b6d4;
    --cyan-l:   #67e8f9;
    --bg:       #030609;
    --bg-b:     #05090f;
    --bg-c:     #0c0810;
    --bg-e:     #110c18;
    --bg-ov:    #160f1e;
    --bdr:      rgba(225,29,72,.12);
    --bdr-h:    rgba(225,29,72,.32);
    --txt:      #f0e6e8;
    --txt-s:    #9c8a90;
    --txt-m:    #5c4a52;
    --sidebar-w: 262px;
    --topbar-h:  58px;
}
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Barlow', system-ui, sans-serif;
    background: var(--bg);
    color: var(--txt);
    overflow-x: hidden;
    font-size: 14px;
    background-image:
        repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(225,29,72,.006) 2px, rgba(225,29,72,.006) 4px);
}
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: rgba(225,29,72,.3); border-radius: 2px; }
::-webkit-scrollbar-thumb:hover { background: rgba(225,29,72,.55); }

/* ══ SIDEBAR ══ */
.s-sidebar {
    position: fixed; left:0; top:0;
    width: var(--sidebar-w); height: 100vh;
    background: linear-gradient(180deg, #0e060a 0%, #030609 100%);
    border-right: 1px solid var(--bdr);
    overflow-y: auto; z-index: 1000;
    display: flex; flex-direction: column;
    background-image:
        repeating-linear-gradient(45deg, rgba(225,29,72,.015) 0px, rgba(225,29,72,.015) 1px, transparent 1px, transparent 14px),
        repeating-linear-gradient(-45deg, rgba(225,29,72,.015) 0px, rgba(225,29,72,.015) 1px, transparent 1px, transparent 14px),
        linear-gradient(180deg, #0e060a 0%, #030609 100%);
}
.s-sidebar::-webkit-scrollbar { width: 2px; }

.s-brand {
    padding: 1.2rem 1.1rem .9rem;
    border-bottom: 1px solid var(--bdr);
    display: flex; align-items: center; gap: .75rem;
    background: rgba(225,29,72,.04);
    position: relative; overflow: hidden;
}
.s-brand::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    animation: scanline 3s linear infinite;
}
@keyframes scanline { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }

.s-brand-icon {
    width: 40px; height: 40px; border-radius: 9px;
    background: linear-gradient(135deg, var(--red), var(--red-d));
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem; flex-shrink: 0;
    box-shadow: 0 0 20px rgba(225,29,72,.5), 0 0 40px rgba(225,29,72,.2);
}
.s-brand-name { font-family: 'Rajdhani', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; letter-spacing: .08em; text-transform: uppercase; }
.s-brand-sub  { font-size: .58rem; color: var(--txt-m); letter-spacing: .12em; text-transform: uppercase; margin-top: .1rem; }

.s-user-bar {
    margin: .6rem .9rem;
    padding: .5rem .8rem;
    background: rgba(225,29,72,.06);
    border: 1px solid rgba(225,29,72,.18);
    border-radius: 8px;
    display: flex; align-items: center; gap: .5rem;
}
.s-user-ava {
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, var(--red), var(--red-d));
    display: grid; place-items: center;
    font-family: 'Rajdhani', sans-serif;
    font-size: .7rem; font-weight: 700; color: #fff;
    flex-shrink: 0; border: 1px solid rgba(225,29,72,.4);
}
.s-user-name  { font-size: .77rem; font-weight: 600; color: var(--txt); }
.s-user-grade { font-size: .6rem; color: var(--red-l); letter-spacing: .04em; }

.s-nav { padding: .3rem 0 2rem; flex: 1; }
.s-section {
    padding: .9rem .9rem .22rem;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .18em;
    color: var(--txt-m);
    display: flex; align-items: center; gap: .5rem;
}
.s-section::before { content: ''; display: block; width: 12px; height: 1px; background: var(--red); opacity: .45; }

.s-link {
    display: flex; align-items: center; gap: .6rem;
    padding: .55rem .9rem;
    color: var(--txt-m); text-decoration: none;
    font-size: .8rem; font-weight: 500;
    transition: all .15s; position: relative;
    font-family: 'Barlow', sans-serif;
}
.s-link::before {
    content: '';
    position: absolute; left: 0; top: 50%;
    transform: translateY(-50%);
    width: 2px; height: 0;
    background: var(--red);
    border-radius: 0 2px 2px 0;
    transition: height .17s;
    box-shadow: 0 0 8px var(--red);
}
.s-link i { width: 14px; text-align: center; font-size: .75rem; flex-shrink: 0; }
.s-link:hover, .s-link.active {
    color: var(--red-l);
    background: linear-gradient(90deg, rgba(225,29,72,.09), transparent);
}
.s-link:hover::before, .s-link.active::before { height: 60%; }
.s-link.active { font-weight: 600; color: var(--red-xl); }
.s-link.active::before { background: linear-gradient(180deg, var(--red), var(--red-d)); }

.s-link .nav-alert {
    margin-left: auto; width: 8px; height: 8px; border-radius: 50%;
    background: var(--red); box-shadow: 0 0 6px var(--red);
    animation: pulse-red 1.2s ease-in-out infinite;
}
@keyframes pulse-red { 0%, 100% { opacity:1; transform:scale(1); } 50% { opacity:.35; transform:scale(.65); } }

.s-divider { height: 1px; background: linear-gradient(90deg, transparent, var(--bdr), transparent); margin: .5rem .9rem; }

/* ══ MAIN ══ */
.s-main { margin-left: var(--sidebar-w); min-height: 100vh; }

.s-topbar {
    position: sticky; top: 0; z-index: 100;
    height: var(--topbar-h);
    background: rgba(3,2,4,.97);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--bdr);
    padding: 0 1.6rem;
    display: flex; justify-content: space-between; align-items: center;
    background-image:
        url("data:image/svg+xml,%3Csvg width='200' height='58' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 29 L20 29 L28 10 L36 48 L40 29 L50 29 L54 20 L58 38 L62 29 L80 29' fill='none' stroke='rgba(225,29,72,0.1)' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"),
        rgba(3,2,4,.97);
    background-repeat: no-repeat;
    background-position: center right 200px;
}
.s-topbar-left { display: flex; flex-direction: column; }
.s-topbar-title { font-family: 'Rajdhani', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; letter-spacing: .08em; text-transform: uppercase; line-height: 1.1; }
.s-topbar-sub   { font-size: .62rem; color: var(--txt-m); letter-spacing: .06em; font-family: 'JetBrains Mono', monospace; }
.s-topbar-right { display: flex; align-items: center; gap: .7rem; }

.s-clock {
    font-family: 'JetBrains Mono', monospace;
    font-size: .8rem; font-weight: 600; color: var(--red-l);
    padding: .3rem .7rem;
    background: rgba(225,29,72,.07); border: 1px solid rgba(225,29,72,.22); border-radius: 6px;
    letter-spacing: .08em;
}
.s-clock .dot { animation: blink .8s step-end infinite; }
@keyframes blink { 50% { opacity: 0; } }

.s-status-chip {
    display: flex; align-items: center; gap: .45rem;
    padding: .3rem .75rem;
    background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.22); border-radius: 20px;
    font-size: .72rem; font-weight: 600; color: var(--green-l);
    font-family: 'Barlow', sans-serif;
}
.s-status-chip .pulse {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--green); box-shadow: 0 0 6px var(--green);
    animation: pulse-g 2s ease-in-out infinite;
}
@keyframes pulse-g { 0%, 100% { transform:scale(1); opacity:1; } 50% { transform:scale(1.4); opacity:.6; } }

.s-grade-badge {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    padding: .28rem .65rem;
    background: rgba(225,29,72,.1); border: 1px solid rgba(225,29,72,.3); border-radius: 5px; color: var(--red-l);
}

/* ══ CONTENU ══ */
.s-content { padding: 1.4rem 1.65rem; }

.page-hdr { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:1.5rem; gap:1rem; flex-wrap:wrap; }
.page-hdr h1 { font-family:'Rajdhani',sans-serif; font-size:1.5rem; font-weight:700; color:#fff; letter-spacing:.06em; text-transform:uppercase; display:flex; align-items:center; gap:.6rem; }
.page-hdr h1 i { color: var(--red-l); }
.page-hdr-sub { font-size:.72rem; color:var(--txt-m); margin-top:.22rem; letter-spacing:.03em; }
.page-hdr-right { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
.breadcrumb { display:flex; align-items:center; gap:.4rem; font-size:.65rem; color:var(--txt-m); margin-bottom:.6rem; }
.breadcrumb a { color:var(--red-l); text-decoration:none; }
.breadcrumb a:hover { text-decoration:underline; }
.breadcrumb-sep { opacity:.3; font-size:.6rem; }

/* ══ CARDS ══ */
.card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; padding:1.3rem; margin-bottom:1.3rem; transition:border-color .2s; position:relative; overflow:hidden; }
.card::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent,rgba(225,29,72,.22),transparent); pointer-events:none; }
.card:hover { border-color:var(--bdr-h); }
.card.card-red { border-color:rgba(225,29,72,.25); }
.card-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.1rem; }
.card-title { font-family:'Barlow Condensed',sans-serif; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.14em; color:var(--txt-s); display:flex; align-items:center; gap:.5rem; }
.card-title i { color:var(--red-l); }

/* ══ KPI ══ */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:.9rem; margin-bottom:1.4rem; }
.kpi { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; padding:1.1rem; position:relative; overflow:hidden; transition:all .2s; cursor:default; }
.kpi::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--kpi-c,var(--red)),transparent); opacity:.3; transition:opacity .2s; }
.kpi:hover { border-color:var(--bdr-h); transform:translateY(-2px); }
.kpi:hover::after { opacity:1; }
.kpi-ico { width:32px; height:32px; border-radius:8px; background:rgba(225,29,72,.12); color:var(--red-l); display:flex; align-items:center; justify-content:center; font-size:.85rem; margin-bottom:.7rem; position:relative; z-index:1; }
.kpi[data-color="red"]    .kpi-ico { background:rgba(225,29,72,.14); color:var(--red-l);   --kpi-c:var(--red); }
.kpi[data-color="green"]  .kpi-ico { background:rgba(16,185,129,.12); color:var(--green-l); --kpi-c:var(--green); }
.kpi[data-color="yellow"] .kpi-ico { background:rgba(245,158,11,.12); color:var(--yellow-l);--kpi-c:var(--yellow); }
.kpi[data-color="purple"] .kpi-ico { background:rgba(139,92,246,.12); color:#c4b5fd;        --kpi-c:var(--purple); }
.kpi[data-color="blue"]   .kpi-ico { background:rgba(59,130,246,.12); color:#93c5fd;        --kpi-c:var(--blue); }
.kpi[data-color="orange"] .kpi-ico { background:rgba(249,115,22,.12); color:#fdba74;        --kpi-c:var(--orange); }
.kpi-lbl { font-family:'Barlow Condensed',sans-serif; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--txt-m); margin-bottom:.2rem; }
.kpi-val { font-family:'JetBrains Mono',monospace; font-size:1.55rem; font-weight:700; color:#f0e6e8; line-height:1; }
.kpi[data-color="red"]    .kpi-val { color:var(--red-l); }
.kpi[data-color="green"]  .kpi-val { color:var(--green-l); }
.kpi[data-color="yellow"] .kpi-val { color:var(--yellow-l); }
.kpi[data-color="purple"] .kpi-val { color:#c4b5fd; }
.kpi[data-color="blue"]   .kpi-val { color:#93c5fd; }
.kpi[data-color="orange"] .kpi-val { color:#fdba74; }
.kpi-sub { font-size:.67rem; color:var(--txt-m); margin-top:.28rem; }

/* ══ TABLES ══ */
.tbl-wrap { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; margin-bottom:1.3rem; }
.tbl { width:100%; border-collapse:collapse; }
.tbl thead tr { background:linear-gradient(90deg,rgba(225,29,72,.07),rgba(225,29,72,.03)); border-bottom:1px solid var(--bdr-h); }
.tbl th { padding:.75rem 1rem; font-family:'Barlow Condensed',sans-serif; font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.14em; color:var(--txt-s); text-align:left; }
.tbl td { padding:.72rem 1rem; border-bottom:1px solid rgba(255,255,255,.03); font-size:.81rem; vertical-align:middle; }
.tbl tr:last-child td { border-bottom:none; }
.tbl tbody tr:hover td { background:rgba(225,29,72,.04); }

/* ══ BOUTONS ══ */
.btn { display:inline-flex; align-items:center; gap:.42rem; padding:.6rem 1.2rem; border-radius:8px; font-weight:600; font-size:.81rem; cursor:pointer; transition:all .17s; text-decoration:none; border:none; font-family:'Barlow',sans-serif; letter-spacing:.02em; white-space:nowrap; position:relative; overflow:hidden; }
.btn::before { content:''; position:absolute; inset:0; background:rgba(255,255,255,.08); opacity:0; transition:opacity .17s; }
.btn:hover::before { opacity:1; }
.btn-primary   { background:linear-gradient(135deg,var(--red),var(--red-d)); color:#fff; box-shadow:0 3px 14px rgba(225,29,72,.35); }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 22px rgba(225,29,72,.5); }
.btn-secondary { background:rgba(225,29,72,.1); color:var(--red-l); border:1px solid rgba(225,29,72,.28); }
.btn-secondary:hover { background:rgba(225,29,72,.18); border-color:rgba(225,29,72,.5); }
.btn-ghost   { background:transparent; color:var(--txt-s); border:1px solid var(--bdr); }
.btn-ghost:hover { background:var(--bg-ov); color:var(--txt); border-color:var(--bdr-h); }
.btn-success { background:rgba(16,185,129,.12); color:var(--green-l); border:1px solid rgba(16,185,129,.25); }
.btn-success:hover { background:rgba(16,185,129,.22); }
.btn-danger  { background:rgba(225,29,72,.12); color:var(--red-l); border:1px solid rgba(225,29,72,.3); }
.btn-danger:hover  { background:rgba(225,29,72,.25); }
.btn-warning { background:rgba(245,158,11,.1); color:var(--yellow-l); border:1px solid rgba(245,158,11,.25); }
.btn-warning:hover { background:rgba(245,158,11,.2); }
.btn-sm  { padding:.35rem .75rem; font-size:.75rem; border-radius:7px; }
.btn-xs  { padding:.22rem .55rem; font-size:.68rem; border-radius:5px; }
.btn-icon{ width:32px; height:32px; padding:0; justify-content:center; border-radius:7px; }

/* ══ BADGES ══ */
.badge { display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .55rem; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; }
.badge-red    { background:rgba(225,29,72,.12);  color:var(--red-l);   border:1px solid rgba(225,29,72,.28); }
.badge-green  { background:rgba(16,185,129,.12); color:var(--green-l); border:1px solid rgba(16,185,129,.25); }
.badge-yellow { background:rgba(245,158,11,.1);  color:var(--yellow-l);border:1px solid rgba(245,158,11,.22); }
.badge-purple { background:rgba(139,92,246,.12); color:#c4b5fd;        border:1px solid rgba(139,92,246,.25); }
.badge-blue   { background:rgba(59,130,246,.12); color:#93c5fd;        border:1px solid rgba(59,130,246,.25); }
.badge-gray   { background:rgba(100,116,139,.1); color:#94a3b8;        border:1px solid rgba(100,116,139,.2); }
.badge-orange { background:rgba(249,115,22,.1);  color:#fdba74;        border:1px solid rgba(249,115,22,.2); }

/* Priorités triage */
.triage-P1 { border-left:3px solid var(--red) !important; }
.triage-P2 { border-left:3px solid var(--yellow) !important; }
.triage-P3 { border-left:3px solid var(--blue) !important; }
.triage-P4 { border-left:3px solid var(--green) !important; }

/* ══ FORMULAIRES ══ */
.form-grid   { display:grid; grid-template-columns:repeat(2,1fr); gap:1rem; }
.form-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
.form-full   { grid-column:1/-1; }
.form-group  { display:flex; flex-direction:column; gap:.32rem; }
.form-label  { font-family:'Barlow Condensed',sans-serif; font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--txt-m); display:flex; align-items:center; gap:.35rem; }
.form-label i { color:var(--red-l); font-size:.7rem; }
.form-label.required::after { content:' *'; color:var(--red-l); }
.form-input, .form-select, .form-textarea {
    width:100%; padding:.62rem .9rem;
    background:rgba(225,29,72,.04); border:1px solid rgba(225,29,72,.15);
    border-radius:7px; color:var(--txt);
    font-family:'Barlow',inherit; font-size:.83rem; transition:all .17s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline:none; border-color:var(--red);
    background:rgba(225,29,72,.07); box-shadow:0 0 0 3px rgba(225,29,72,.12);
}
.form-input::placeholder { color:var(--txt-m); }
.form-select option { background:#110c18; }
.form-textarea { resize:vertical; min-height:88px; }

/* ══ MODALS ══ */
.modal-ov {
    position:fixed; inset:0;
    background:rgba(0,0,0,.92); backdrop-filter:blur(14px);
    display:flex; align-items:center; justify-content:center;
    z-index:9999; padding:1.5rem;
}
.modal-ov.hidden { display:none !important; }
.modal {
    background:linear-gradient(145deg,var(--bg-e),var(--bg-c));
    border:1px solid var(--bdr-h); border-radius:14px;
    box-shadow:0 0 60px rgba(0,0,0,.8),0 0 120px rgba(225,29,72,.06);
    width:90%; max-width:640px; max-height:90vh;
    display:flex; flex-direction:column; overflow:hidden;
    animation:modal-in .22s cubic-bezier(.34,1.56,.64,1);
    position:relative;
}
.modal::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent,var(--red),transparent); opacity:.45; }
@keyframes modal-in { from { opacity:0; transform:scale(.94) translateY(12px); } to { opacity:1; transform:scale(1) translateY(0); } }
.modal-sm { max-width:420px; }
.modal-lg { max-width:860px; }
.modal-hd { padding:1.1rem 1.4rem; border-bottom:1px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; background:linear-gradient(90deg,rgba(225,29,72,.06),transparent); }
.modal-hd h2 { font-family:'Rajdhani',sans-serif; font-size:.92rem; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.07em; display:flex; align-items:center; gap:.6rem; }
.modal-hd h2 i { color:var(--red-l); }
.modal-close { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,.05); border:1px solid var(--bdr); color:var(--txt-s); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.95rem; transition:all .17s; }
.modal-close:hover { background:rgba(225,29,72,.18); color:var(--red-l); transform:rotate(90deg); }
.modal-bd { padding:1.4rem; overflow-y:auto; flex:1; }
.modal-ft { padding:1rem 1.4rem; border-top:1px solid var(--bdr); background:rgba(0,0,0,.2); display:flex; justify-content:flex-end; gap:.6rem; }

/* ══ ALERTES ══ */
.alert { padding:.78rem 1rem; border-radius:9px; margin-bottom:1.2rem; display:flex; align-items:center; gap:.65rem; font-size:.81rem; font-weight:500; animation:slide-down .25s ease; }
@keyframes slide-down { from { transform:translateY(-8px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.alert-success { background:rgba(16,185,129,.08);  border:1px solid rgba(16,185,129,.22);  color:var(--green-l); }
.alert-error   { background:rgba(225,29,72,.08);   border:1px solid rgba(225,29,72,.22);   color:var(--red-l); }
.alert-info    { background:rgba(225,29,72,.06);   border:1px solid rgba(225,29,72,.18);   color:var(--red-xl); }
.alert-warning { background:rgba(245,158,11,.08);  border:1px solid rgba(245,158,11,.22);  color:var(--yellow-l); }

/* ══ TOAST ══ */
#toast-container { position:fixed; top:70px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:.55rem; pointer-events:none; }
.toast { display:flex; align-items:center; gap:.7rem; padding:.75rem 1.1rem; background:var(--bg-e); border-radius:10px; border:1px solid var(--bdr-h); font-size:.81rem; font-weight:500; min-width:260px; max-width:380px; pointer-events:all; animation:toast-in .25s cubic-bezier(.34,1.56,.64,1); box-shadow:0 8px 32px rgba(0,0,0,.5); }
.toast.out { animation:toast-out .2s ease forwards; }
@keyframes toast-in  { from { transform:translateX(100%); opacity:0; } }
@keyframes toast-out { to   { transform:translateX(100%); opacity:0; } }
.toast-success { border-color:rgba(16,185,129,.3); } .toast-success .toast-icon { color:var(--green-l); }
.toast-error   { border-color:rgba(225,29,72,.4);  } .toast-error   .toast-icon { color:var(--red-l); }
.toast-info    { border-color:rgba(225,29,72,.25); } .toast-info    .toast-icon { color:var(--red-xl); }
.toast-warning { border-color:rgba(245,158,11,.3); } .toast-warning .toast-icon { color:var(--yellow-l); }
.toast-close   { margin-left:auto; background:none; border:none; color:var(--txt-m); cursor:pointer; font-size:.85rem; }

/* ══ DIVERS ══ */
.hr { height:1px; background:linear-gradient(90deg,transparent,var(--bdr),transparent); margin:1.2rem 0; }
.grid2 { display:grid; grid-template-columns:repeat(2,1fr); gap:1.3rem; }
.grid3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
.grid4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
.hidden { display:none !important; }
.mono   { font-family:'JetBrains Mono',monospace; }
.text-red    { color:var(--red-l); }
.text-green  { color:var(--green-l); }
.text-yellow { color:var(--yellow-l); }
.text-muted  { color:var(--txt-s); }
.empty-state { text-align:center; padding:3rem 2rem; color:var(--txt-m); }
.empty-state .empty-icon { font-size:2.5rem; opacity:.2; display:block; margin-bottom:1rem; color:var(--red); }
.empty-state p { font-size:.85rem; }
.pagination { display:flex; align-items:center; gap:.4rem; padding:.75rem 1rem; border-top:1px solid var(--bdr); font-size:.78rem; }
.page-btn { display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; border:1px solid var(--bdr); color:var(--txt-s); text-decoration:none; transition:all .14s; font-size:.78rem; }
.page-btn:hover { border-color:var(--bdr-h); color:var(--red-l); }
.page-btn.active { background:rgba(225,29,72,.18); border-color:var(--red); color:var(--red-xl); font-weight:700; }
.page-info { color:var(--txt-m); font-size:.72rem; margin-right:auto; }

/* ══ DOCUMENT CARDS ══ */
.doc-card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; transition:all .2s; margin-bottom:.75rem; position:relative; }
.doc-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--red); opacity:.5; transition:opacity .2s; }
.doc-card:hover { border-color:var(--bdr-h); box-shadow:0 4px 20px rgba(225,29,72,.07); }
.doc-card:hover::before { opacity:1; }
.doc-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.2rem 1rem 1.4rem; gap:.75rem; flex-wrap:wrap; }
.doc-card-left { display:flex; align-items:center; gap:.85rem; flex:1; min-width:0; }
.doc-avatar { width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg,rgba(225,29,72,.2),rgba(159,18,57,.1)); border:1px solid rgba(225,29,72,.2); display:flex; align-items:center; justify-content:center; font-family:'Rajdhani',sans-serif; font-weight:700; font-size:.85rem; color:var(--red-l); flex-shrink:0; }
.doc-name { font-weight:700; font-size:.9rem; color:var(--txt); }
.doc-meta { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-top:.2rem; }
.doc-meta span { display:flex; align-items:center; gap:.3rem; font-size:.73rem; color:var(--txt-m); }
.doc-meta i { color:var(--red); opacity:.55; font-size:.68rem; }
.doc-card-right { display:flex; align-items:center; gap:.5rem; flex-shrink:0; }
.doc-card-body  { padding:.25rem 1.4rem 1rem; border-top:1px solid rgba(225,29,72,.06); }
.doc-tags  { display:flex; gap:.4rem; flex-wrap:wrap; padding:0 1.4rem .8rem; }
.doc-dates { display:flex; align-items:center; gap:.75rem; padding:0 1.4rem .75rem; flex-wrap:wrap; }
.doc-date-box { background:rgba(225,29,72,.05); border:1px solid rgba(225,29,72,.12); border-radius:7px; padding:.45rem .8rem; text-align:center; }
.doc-date-label { font-size:.58rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--txt-m); }
.doc-date-val   { font-family:'JetBrains Mono',monospace; font-size:.82rem; color:var(--red-l); font-weight:600; }
.doc-date-arrow { color:var(--red); opacity:.35; font-size:.8rem; }
.doc-duration   { background:rgba(245,158,11,.07); border:1px solid rgba(245,158,11,.18); border-radius:6px; padding:.35rem .75rem; font-family:'JetBrains Mono',monospace; font-size:.72rem; font-weight:700; color:var(--yellow-l); }
.doc-actions { display:flex; gap:.5rem; padding:.5rem 1.4rem 1rem; flex-wrap:wrap; }
.doc-toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.1rem; gap:.75rem; flex-wrap:wrap; }
.search-wrap { position:relative; flex:1; min-width:180px; max-width:340px; }
.search-wrap i { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:var(--txt-m); font-size:.78rem; pointer-events:none; }
.search-wrap input { width:100%; padding:.58rem .9rem .58rem 2.2rem; background:var(--bg-c); border:1px solid var(--bdr); border-radius:8px; color:var(--txt); font-family:'Barlow',sans-serif; font-size:.83rem; transition:all .17s; }
.search-wrap input:focus { outline:none; border-color:var(--red); box-shadow:0 0 0 3px rgba(225,29,72,.1); }
.search-wrap input::placeholder { color:var(--txt-m); }

@media (max-width:1100px) {
    .s-sidebar { transform:translateX(-100%); transition:transform .25s; }
    .s-sidebar.open { transform:translateX(0); }
    .s-main { margin-left:0; }
    .form-grid { grid-template-columns:1fr; }
    .grid2 { grid-template-columns:1fr; }
    .kpi-grid { grid-template-columns:repeat(2,1fr); }
}
</style>
<?php /* Thème SAMS configurable (sams_settings) — surcharge les variables ci-dessus */ include __DIR__ . '/theme.php'; ?>
</head>
<body>

<div id="toast-container"></div>

<aside class="s-sidebar" id="sidebar">
    <div class="s-brand">
        <div class="s-brand-icon"><i class="fas fa-heartbeat"></i></div>
        <div>
            <div class="s-brand-name">S.A.M.S</div>
            <div class="s-brand-sub">Services Médicaux</div>
        </div>
    </div>
    <div class="s-user-bar">
        <div class="s-user-ava"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
        <div>
            <div class="s-user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
            <?php if ($__samsGrade): ?><div class="s-user-grade"><?php echo htmlspecialchars($__samsGrade); ?></div><?php endif; ?>
        </div>
    </div>
    <nav class="s-nav">
        <?php
        $lastSec = '';
        foreach ($__samsNavPages as $p):
            $sec = $p['section'] ?? '';
            if (!empty($sec) && $sec !== $lastSec):
                $lastSec = $sec;
                echo '<div class="s-section">' . htmlspecialchars($sec) . '</div>';
            endif;
            $isActive = ($currentPage === $p['file']) ? ' active' : '';
            echo '<a href="' . SITE_URL . htmlspecialchars($p['path'] ?? '#') . '" class="s-link' . $isActive . '">'
               . '<i class="' . htmlspecialchars($p['icon']) . '"></i>'
               . '<span>' . htmlspecialchars($p['label']) . '</span>'
               . '</a>';
        endforeach;
        ?>
        <div class="s-divider"></div>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="s-link"><i class="fas fa-home"></i><span>Retour MDT</span></a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="s-link"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
    </nav>
</aside>

<main class="s-main">
    <div class="s-topbar">
        <div class="s-topbar-left">
            <div class="s-topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></div>
            <div class="s-topbar-sub">SAMS &mdash; Services d'Aide Médicale de Los Santos</div>
        </div>
        <div class="s-topbar-right">
            <div class="s-clock" id="live-clock">
                <span id="clock-h"><?php echo $__now->format('H'); ?></span><span class="dot">:</span><span id="clock-m"><?php echo $__now->format('i'); ?></span><span class="dot">:</span><span id="clock-s"><?php echo $__now->format('s'); ?></span>
            </div>
            <?php if ($__samsGrade): ?><span class="s-grade-badge"><?php echo htmlspecialchars($__samsGrade); ?></span><?php endif; ?>
            <div class="s-status-chip"><span class="pulse"></span>EN SERVICE</div>
        </div>
    </div>
    <div class="s-content">