<?php
// ════════════════════════════════════════════════════════════
// pages/avocats/includes/header.php
// ════════════════════════════════════════════════════════════
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';

// Accès : faction AVOCATS ou staff
if (($_SESSION['user_faction'] ?? '') !== 'AVOCATS' &&
    !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Permissions selon grade
$__grade      = $_SESSION['user_faction_rank'] ?? '';
$__isStaff    = hasRole(['*','Administrateur','Développeur','Staff']);
$__isManaging = $__isStaff || in_array($__grade, ['Avocat associé principal']);
$__isPartner  = $__isStaff || in_array($__grade, ['Avocat associé principal','Avocat associé']);
$__isSenior   = $__isStaff || in_array($__grade, ['Avocat associé principal','Avocat associé','Avocat senior']);
$__canFinance = $__isStaff || $__isPartner;
$__canRecruit = $__isStaff || $__isPartner;
// $__canCreate : staff + tous les membres avocat peuvent créer clients/dossiers
$__canCreate  = $__isStaff || in_array($_SESSION['user_faction'] ?? '', ['AVOCATS']);

$__avocatPages = [
    ['file'=>'dashboard',   'label'=>'Tableau de bord',  'icon'=>'fas fa-chart-line',        'section'=>''],
    ['file'=>'clients',     'label'=>'Clients',          'icon'=>'fas fa-users',              'section'=>'Juridique'],
    ['file'=>'dossiers',    'label'=>'Dossiers',         'icon'=>'fas fa-folder-open',        'section'=>''],
    ['file'=>'proces',      'label'=>'Procès',           'icon'=>'fas fa-gavel',              'section'=>''],
    ['file'=>'documents',   'label'=>'Documents',        'icon'=>'fas fa-paperclip',          'section'=>''],
    ['file'=>'lois',        'label'=>'Base de Lois',     'icon'=>'fas fa-book',               'section'=>''],
    ['file'=>'facturation', 'label'=>'Facturation',      'icon'=>'fas fa-file-invoice-dollar','section'=>'Cabinet'],
    ['file'=>'cabinet',     'label'=>'Gestion Cabinet',  'icon'=>'fas fa-building-columns',   'section'=>''],
    ['file'=>'personnel',   'label'=>'Personnel',        'icon'=>'fas fa-id-badge',           'section'=>''],
    ['file'=>'generateur',  'label'=>'Générateur',       'icon'=>'fas fa-wand-magic-sparkles','section'=>'Outils'],
];
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// ── Active pages & grade permissions depuis mdt.php ───────────────────────
$__avGrade    = $__grade ?? ($_SESSION['user_faction_rank'] ?? '');
$__avIsStaff  = $__isStaff ?? hasRole(['*','Administrateur','Développeur','Staff']);
$__avActPages = [];
try {
    $__avDb = Database::getInstance()->getConnection();
    $__avS  = $__avDb->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key='AVOCATS' LIMIT 1");
    $__avS->execute(); $__avR=$__avS->fetch();
    if($__avR&&!empty($__avR['active_pages'])) $__avActPages=json_decode($__avR['active_pages'],true)?:[];
} catch(Exception $e){ $__avDb=null; }
// Redirect si page désactivée
if(isset($__avActPages[$currentPage])&&$__avActPages[$currentPage]===false)
    redirect(SITE_URL.'/pages/dashboard.php');
// Redirect si grade non autorisé sur la page courante
if(!$__avIsStaff && isset($__avDb)){
    try{$__avPq=$__avDb->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='AVOCATS' AND page_key=? LIMIT 1");
        $__avPq->execute([$currentPage]);$__avPr=$__avPq->fetch();
        if($__avPr){$__avAl=json_decode($__avPr['allowed_grades'],true)?:['everyone'];
            if(!in_array('everyone',$__avAl)&&!in_array($__avGrade,$__avAl)) redirect(SITE_URL.'/pages/dashboard.php');}
    }catch(Exception $e){}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Cabinet'); ?> — Cabinet d'Avocats</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;600&display=swap');

        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold:   #d97706;
            --gold-d: #92400e;
            --gold-l: #fcd34d;
            --gold-xl:#fef3c7;
            --bg:     #06080f;
            --bg-c:   #0a0d18;
            --bg-e:   #0f1422;
            --bg-ov:  #141928;
            --bdr:    rgba(217,119,6,.16);
            --bdr-h:  rgba(217,119,6,.35);
            --txt:    #e2d9c8;
            --txt-s:  #94856a;
            --txt-m:  #4a4232;
        }
        body { font-family:'IBM Plex Sans',system-ui,sans-serif; background:var(--bg); color:var(--txt); overflow-x:hidden; font-size:14px; }

        /* ── SIDEBAR ─── */
        .av-sidebar {
            position:fixed; left:0; top:0; width:265px; height:100vh;
            background:linear-gradient(180deg,#08091a 0%,var(--bg) 100%);
            border-right:1px solid var(--bdr);
            overflow-y:auto; z-index:1000; display:flex; flex-direction:column;
        }
        .av-sidebar::-webkit-scrollbar{width:3px}
        .av-sidebar::-webkit-scrollbar-thumb{background:rgba(217,119,6,.25);border-radius:2px}

        .av-brand {
            padding:1.5rem 1.25rem 1.25rem;
            border-bottom:1px solid var(--bdr);
            display:flex; align-items:center; gap:.875rem;
        }
        .av-seal {
            width:48px; height:48px; flex-shrink:0;
            background:linear-gradient(135deg,var(--gold),var(--gold-d));
            border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.25rem; color:#fff;
            box-shadow:0 4px 20px rgba(217,119,6,.4);
        }
        .av-brand-name { font-family:'Playfair Display',serif; font-size:1rem; font-weight:700; color:#f5edd6; letter-spacing:.01em; line-height:1.2; }
        .av-brand-sub  { font-size:.68rem; color:var(--txt-m); margin-top:.2rem; text-transform:uppercase; letter-spacing:.08em; }
        .av-grade-badge {
            margin:.75rem 1.25rem;
            padding:.45rem .9rem;
            background:rgba(217,119,6,.08);
            border:1px solid rgba(217,119,6,.2);
            border-radius:8px; font-size:.72rem;
            display:flex; align-items:center; gap:.5rem; color:var(--gold-l);
        }
        .av-grade-badge i { color:var(--gold); font-size:.65rem; }

        .av-nav { padding:.5rem 0 2rem; flex:1; }
        .nav-sec { padding:.75rem 1.25rem .3rem; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--txt-m); }
        .nav-link {
            display:flex; align-items:center; gap:.7rem;
            padding:.72rem 1.25rem; color:var(--txt-s); text-decoration:none;
            font-size:.835rem; font-weight:500; transition:all .18s; position:relative;
        }
        .nav-link::before { content:''; position:absolute; left:0; top:50%; transform:translateY(-50%); width:3px; height:0; background:var(--gold); border-radius:0 3px 3px 0; transition:height .18s; }
        .nav-link:hover, .nav-link.active { color:var(--gold-l); background:rgba(217,119,6,.07); }
        .nav-link:hover::before, .nav-link.active::before { height:60%; }
        .nav-link i { width:17px; text-align:center; font-size:.82rem; }
        .nav-divider { height:1px; background:var(--bdr); margin:.75rem 1.25rem; }

        /* ── MAIN ─── */
        .av-main { margin-left:265px; min-height:100vh; }
        .av-topbar {
            position:sticky; top:0; z-index:100;
            background:rgba(6,8,15,.95); backdrop-filter:blur(12px);
            border-bottom:1px solid var(--bdr);
            padding:1rem 1.75rem;
            display:flex; justify-content:space-between; align-items:center;
        }
        .topbar-title { font-family:'Playfair Display',serif; font-size:1.25rem; font-weight:700; color:#f5edd6; letter-spacing:-.2px; }
        .topbar-sub { font-size:.72rem; color:var(--txt-m); margin-top:.12rem; }
        .user-badge {
            display:flex; align-items:center; gap:.5rem;
            padding:.45rem 1rem;
            background:rgba(217,119,6,.09); border:1px solid rgba(217,119,6,.22);
            border-radius:20px; font-size:.78rem; font-weight:600; color:var(--gold-l);
        }
        .av-content { padding:1.5rem 1.75rem; }

        /* ── CARDS ─── */
        .card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; padding:1.4rem; margin-bottom:1.4rem; transition:border-color .2s; }
        .card:hover { border-color:var(--bdr-h); }
        .card-title { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:var(--txt-s); margin-bottom:1.1rem; display:flex; align-items:center; gap:.5rem; }
        .card-title i { color:var(--gold); }

        /* ── KPI ─── */
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
        .kpi { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; padding:1.2rem; position:relative; overflow:hidden; }
        .kpi::after { content:''; position:absolute; top:-20px; right:-20px; width:70px; height:70px; background:radial-gradient(circle,rgba(217,119,6,.1) 0%,transparent 70%); pointer-events:none; }
        .kpi-ico { width:34px; height:34px; border-radius:9px; background:rgba(217,119,6,.12); color:var(--gold); display:flex; align-items:center; justify-content:center; font-size:.85rem; margin-bottom:.75rem; }
        .kpi-lbl { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--txt-m); margin-bottom:.28rem; }
        .kpi-val { font-size:1.6rem; font-weight:700; font-family:'IBM Plex Mono',monospace; color:#f5edd6; line-height:1; }
        .kpi-val.gold  { color:var(--gold-l); } .kpi-val.green{ color:#4ade80; }
        .kpi-val.red   { color:#f87171; }       .kpi-val.blue { color:#93c5fd; }
        .kpi-sub { font-size:.72rem; color:var(--txt-m); margin-top:.3rem; }

        /* ── BUTTONS ─── */
        .btn { display:inline-flex; align-items:center; gap:.5rem; padding:.65rem 1.2rem; border-radius:8px; font-weight:600; font-size:.825rem; cursor:pointer; transition:all .18s; text-decoration:none; border:none; font-family:inherit; }
        .btn-primary { background:linear-gradient(135deg,var(--gold),var(--gold-d)); color:#fff; box-shadow:0 3px 12px rgba(217,119,6,.3); }
        .btn-primary:hover { transform:translateY(-1px); box-shadow:0 5px 18px rgba(217,119,6,.4); }
        .btn-secondary { background:rgba(217,119,6,.1); color:var(--gold-l); border:1px solid rgba(217,119,6,.25); }
        .btn-secondary:hover { background:rgba(217,119,6,.18); }
        .btn-ghost { background:transparent; color:var(--txt-s); border:1px solid var(--bdr); }
        .btn-ghost:hover { background:var(--bg-ov); color:var(--txt); }
        .btn-danger { background:rgba(239,68,68,.1); color:#f87171; border:1px solid rgba(239,68,68,.25); }
        .btn-danger:hover { background:rgba(239,68,68,.2); }
        .btn-sm { padding:.38rem .8rem; font-size:.77rem; }
        .btn-success { background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.25); }

        /* ── FORMS ─── */
        .form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:1.1rem; }
        .form-full  { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:.35rem; }
        .form-label { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--txt-m); }
        .form-input, .form-select, .form-textarea {
            width:100%; padding:.68rem .9rem;
            background:#04060e; border:1px solid rgba(217,119,6,.2);
            border-radius:7px; color:var(--txt);
            font-family:'IBM Plex Sans',inherit; font-size:.845rem; transition:all .18s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(217,119,6,.12); }
        .form-input::placeholder { color:var(--txt-m); }
        .form-select option { background:#0f1422; }
        .form-textarea { resize:vertical; min-height:90px; }

        /* ── MODALS ─── */
        .modal-ov { position:fixed; inset:0; background:rgba(0,0,0,.85); backdrop-filter:blur(8px); display:none; align-items:center; justify-content:center; z-index:9999; padding:1.5rem; }
        .modal-ov:not(.hidden) { display:flex; }
        .modal { background:linear-gradient(140deg,var(--bg-e),var(--bg)); border:1px solid rgba(217,119,6,.28); border-radius:16px; box-shadow:0 0 80px rgba(0,0,0,.7); width:90%; max-width:640px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; }
        .modal-sm { max-width:440px; }
        .modal-lg { max-width:860px; }
        .modal-hd { padding:1.25rem 1.5rem; border-bottom:1px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; background:rgba(217,119,6,.04); }
        .modal-hd h2 { font-family:'Playfair Display',serif; font-size:1rem; font-weight:700; color:#f5edd6; display:flex; align-items:center; gap:.65rem; }
        .modal-hd h2 i { color:var(--gold); }
        .modal-cl { width:32px; height:32px; border-radius:7px; background:rgba(217,119,6,.08); border:1px solid var(--bdr); color:var(--txt-s); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1.1rem; transition:all .18s; }
        .modal-cl:hover { background:rgba(239,68,68,.15); color:#f87171; transform:rotate(90deg); }
        .modal-bd { padding:1.5rem; overflow-y:auto; flex:1; }
        .modal-ft { padding:1.1rem 1.5rem; border-top:1px solid var(--bdr); background:rgba(0,0,0,.2); display:flex; justify-content:flex-end; gap:.65rem; }

        /* ── ALERTS ─── */
        .alert { padding:.8rem 1.1rem; border-radius:9px; margin-bottom:1.25rem; display:flex; align-items:center; gap:.65rem; font-size:.825rem; font-weight:500; }
        .alert-ok  { background:rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.22); color:#4ade80; }
        .alert-err { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.22); color:#f87171; }
        .alert-inf { background:rgba(217,119,6,.08); border:1px solid rgba(217,119,6,.22); color:var(--gold-l); }

        /* ── TABLE ─── */
        .tbl { width:100%; border-collapse:collapse; }
        .tbl th { text-align:left; padding:.75rem 1rem; background:rgba(217,119,6,.06); border-bottom:2px solid rgba(217,119,6,.2); font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--txt-s); }
        .tbl td { padding:.75rem 1rem; border-bottom:1px solid rgba(255,255,255,.04); font-size:.825rem; }
        .tbl tr:hover td { background:rgba(217,119,6,.03); }
        .tbl tr:last-child td { border-bottom:none; }
        .tbl-wrap { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; margin-bottom:1.4rem; }

        /* ── BADGES ─── */
        .badge { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .6rem; border-radius:5px; font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
        .bg-green  { background:rgba(34,197,94,.1);  color:#4ade80;  border:1px solid rgba(34,197,94,.2); }
        .bg-red    { background:rgba(239,68,68,.1);  color:#f87171;  border:1px solid rgba(239,68,68,.2); }
        .bg-yellow { background:rgba(245,158,11,.1); color:#fcd34d;  border:1px solid rgba(245,158,11,.2); }
        .bg-blue   { background:rgba(59,130,246,.1); color:#93c5fd;  border:1px solid rgba(59,130,246,.2); }
        .bg-gray   { background:rgba(100,116,139,.1);color:#94a3b8;  border:1px solid rgba(100,116,139,.2); }
        .bg-gold   { background:rgba(217,119,6,.12); color:var(--gold-l); border:1px solid rgba(217,119,6,.25); }
        .bg-purple { background:rgba(139,92,246,.1); color:#c084fc;  border:1px solid rgba(139,92,246,.2); }

        /* ── MISC ─── */
        .mono { font-family:'IBM Plex Mono',monospace; }
        .hidden { display:none !important; }
        .grid2 { display:grid; grid-template-columns:repeat(2,1fr); gap:1.4rem; }
        .grid3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1.1rem; }
        .txt-gold { color:var(--gold-l); } .txt-green { color:#4ade80; }
        .txt-red   { color:#f87171; }      .txt-muted { color:var(--txt-m); }

        @media(max-width:1024px) { .av-sidebar{transform:translateX(-100%)} .av-main{margin-left:0} .form-grid{grid-template-columns:1fr} .grid2{grid-template-columns:1fr} }
    </style>
    <?php echo $__panelThemeCSS ?? ''; ?>
</head>
<body>
<aside class="av-sidebar">
    <div class="av-brand">
        <div class="av-seal"><i class="fas fa-scale-balanced"></i></div>
        <div>
            <div class="av-brand-name">Cabinet d'Avocats</div>
            <div class="av-brand-sub">Los Santos</div>
        </div>
    </div>
    <div class="av-grade-badge">
        <i class="fas fa-id-badge"></i>
        <?php echo htmlspecialchars(mdt_session_display_name()); ?>
        <?php if($__grade): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($__grade); ?><?php endif; ?>
    </div>
    <nav class="av-nav">
        <?php $sec=''; foreach($__avocatPages as $p):
            // Filtre active_pages + grade
            $__apf=$p['file'];
            if(isset($__avActPages[$__apf])&&$__avActPages[$__apf]===false) continue;
            if(!$__avIsStaff && isset($__avDb)){
                try{$__axq=$__avDb->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='AVOCATS' AND page_key=? LIMIT 1");
                    $__axq->execute([$__apf]);$__axr=$__axq->fetch();
                    if($__axr){$__axa=json_decode($__axr['allowed_grades'],true)?:['everyone'];
                        if(!in_array('everyone',$__axa)&&!in_array($__avGrade,$__axa)) continue;}
                }catch(Exception $e){}
            }
            if(!empty($p['section']) && $p['section']!==$sec): $sec=$p['section'];
                echo '<div class="nav-sec">'.htmlspecialchars($sec).'</div>';
            endif;
            $active = ($currentPage===$p['file'])?' active':'';
        ?>
        <a href="<?php echo $p['file']; ?>.php" class="nav-link<?php echo $active; ?>">
            <i class="<?php echo $p['icon']; ?>"></i>
            <span><?php echo htmlspecialchars($p['label']); ?></span>
        </a>
        <?php endforeach; ?>
        <div class="nav-divider"></div>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Retour MDT</span></a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
    </nav>
</aside>
<main class="av-main">
    <div class="av-topbar">
        <div>
            <div class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></div>
            <div class="topbar-sub">Cabinet d'Avocats de Los Santos</div>
        </div>
        <div class="user-badge"><i class="fas fa-user-tie"></i><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
    </div>
    <div class="av-content">
<?php
// Helpers globaux
function avPerms(): array {
    global $__isManaging, $__isPartner, $__isSenior, $__canFinance, $__canRecruit;
    return compact('__isManaging','__isPartner','__isSenior','__canFinance','__canRecruit');
}
?>