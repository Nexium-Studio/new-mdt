<?php
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';

if (($_SESSION['user_faction'] ?? '') !== 'GOUV' && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$__gouvUserGrade = $_SESSION['user_faction_rank'] ?? '';
$__isSuperAdmin  = hasRole(['*','Administrateur','Développeur','Staff']);
$__gouvCurPage   = basename($_SERVER['PHP_SELF'], '.php');

// ── Active pages & grade permissions depuis mdt.php ───────────────────────
$__gouvActivePages = [];
$__gouvIsStaff     = $__isSuperAdmin;
try {
    $__gs=$db->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key='GOUV' LIMIT 1");
    $__gs->execute(); $__gr=$__gs->fetch();
    if($__gr&&!empty($__gr['active_pages'])) $__gouvActivePages=json_decode($__gr['active_pages'],true)?:[];
} catch(Exception $e){}
// Redirect si page désactivée
if(isset($__gouvActivePages[$__gouvCurPage])&&$__gouvActivePages[$__gouvCurPage]===false)
    redirect(SITE_URL.'/pages/dashboard.php');
// Redirect si grade non autorisé
if(!$__gouvIsStaff){
    try{$__gp=$db->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='GOUV' AND page_key=? LIMIT 1");
        $__gp->execute([$__gouvCurPage]);$__gpr=$__gp->fetch();
        if($__gpr){$__al=json_decode($__gpr['allowed_grades'],true)?:['everyone'];
            if(!in_array('everyone',$__al)&&!in_array($__gouvUserGrade,$__al)) redirect(SITE_URL.'/pages/dashboard.php');}
    }catch(Exception $e){}
}

$__gouvPages = [
    ['file'=>'dashboard',       'label'=>'Vue d\'ensemble',          'icon'=>'fas fa-chart-line',          'section'=>''],
    ['file'=>'finances',        'label'=>'Finances',                  'icon'=>'fas fa-building-columns',    'section'=>'Économie'],
    ['file'=>'fiscalite',       'label'=>'Fiscalité',                 'icon'=>'fas fa-scale-balanced',      'section'=>''],
    ['file'=>'facturation',     'label'=>'Facturation',               'icon'=>'fas fa-file-invoice-dollar', 'section'=>''],
    ['file'=>'budget',          'label'=>'Budget de l\'État',         'icon'=>'fas fa-landmark',            'section'=>''],
    ['file'=>'subventions',     'label'=>'Subventions',               'icon'=>'fas fa-hand-holding-dollar', 'section'=>''],
    ['file'=>'lois',            'label'=>'Textes de Loi',             'icon'=>'fas fa-balance-scale',       'section'=>'Législation'],
    ['file'=>'decrets',         'label'=>'Décrets & Arrêtés',         'icon'=>'fas fa-scroll',              'section'=>''],
    ['file'=>'licences',        'label'=>'Licences',                  'icon'=>'fas fa-id-card',             'section'=>''],
    ['file'=>'registre',        'label'=>'Registre Civil',            'icon'=>'fas fa-book-open',           'section'=>''],
    ['file'=>'amendes',         'label'=>'Amendes',                   'icon'=>'fas fa-gavel',               'section'=>'Ordre Public'],
    ['file'=>'alertes',         'label'=>'Alertes Publiques',         'icon'=>'fas fa-triangle-exclamation','section'=>''],
    // Secrétariats
    ['file'=>'sec_evenementiel','label'=>'Événements',                'icon'=>'fas fa-calendar-star',       'section'=>'🎭 Événementiel','grp'=>'evt'],
    ['file'=>'sec_permis_evt',  'label'=>'Permis & Autorisations',    'icon'=>'fas fa-ticket',              'section'=>'','grp'=>'evt'],
    ['file'=>'sec_agenda',      'label'=>'Agenda Public',             'icon'=>'fas fa-calendar-days',       'section'=>'','grp'=>'evt'],
    ['file'=>'sec_entreprises', 'label'=>'Registre Entreprises',      'icon'=>'fas fa-building',            'section'=>'🏢 Entreprises','grp'=>'ent'],
    ['file'=>'sec_audits',      'label'=>'Audits & Contrôles',        'icon'=>'fas fa-magnifying-glass-chart','section'=>'','grp'=>'ent'],
    ['file'=>'sec_partenariats','label'=>'Partenariats Public-Privé', 'icon'=>'fas fa-handshake',           'section'=>'','grp'=>'ent'],
    ['file'=>'sec_justice',     'label'=>'Dossiers Judiciaires',      'icon'=>'fas fa-gavel',               'section'=>'⚖️ Justice & Service Public','grp'=>'jus'],
    ['file'=>'sec_recours',     'label'=>'Recours & Appels',          'icon'=>'fas fa-reply',               'section'=>'','grp'=>'jus'],
    ['file'=>'sec_services',    'label'=>'Services Publics',          'icon'=>'fas fa-city',                'section'=>'','grp'=>'jus'],
    ['file'=>'sec_rehabilitation','label'=>'Réhabilitation',          'icon'=>'fas fa-person-circle-check', 'section'=>'','grp'=>'jus'],
    ['file'=>'sec_tresorerie',  'label'=>'Trésorerie d\'État',        'icon'=>'fas fa-vault',               'section'=>'💰 Finances Publiques','grp'=>'fin2'],
    ['file'=>'sec_emprunts',    'label'=>'Emprunts d\'État',          'icon'=>'fas fa-money-bill-transfer',  'section'=>'','grp'=>'fin2'],
    ['file'=>'sec_rapports_fin','label'=>'Rapports Financiers',       'icon'=>'fas fa-chart-pie',           'section'=>'','grp'=>'fin2'],
];

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$__grpColors = ['evt'=>'#7c3aed','ent'=>'#0891b2','jus'=>'#b45309','fin2'=>'#15803d'];
$__grpLight  = ['evt'=>'#c4b5fd','ent'=>'#67e8f9','jus'=>'#fde68a','fin2'=>'#86efac'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle??'Gouvernement'); ?> — État de Los Santos</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
:root{--gov:#1d4ed8;--gov-d:#1e3a8a;--gov-l:#93c5fd;--gov-xl:#dbeafe;--bg:#05090f;--bg-c:#090f1c;--bg-e:#0d1526;--bg-ov:#111d33;--bdr:rgba(29,78,216,.18);--bdr-h:rgba(29,78,216,.38);--txt:#e2e8f0;--txt-s:#94a3b8;--txt-m:#475569}
body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--txt);overflow-x:hidden;font-size:14px}
/* SIDEBAR */
.gov-sidebar{position:fixed;left:0;top:0;width:266px;height:100vh;background:linear-gradient(180deg,#080f22,var(--bg));border-right:1px solid var(--bdr);overflow-y:auto;z-index:1000;display:flex;flex-direction:column}
.gov-sidebar::-webkit-scrollbar{width:3px}.gov-sidebar::-webkit-scrollbar-thumb{background:rgba(29,78,216,.3);border-radius:2px}
.gov-brand{padding:1.35rem 1.25rem 1.1rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:.875rem}
.gov-seal{width:44px;height:44px;flex-shrink:0;background:linear-gradient(135deg,var(--gov),var(--gov-d));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.05rem;color:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.25),0 4px 16px rgba(29,78,216,.4)}
.gov-brand-name{font-weight:700;font-size:.9rem;color:#f1f5f9;letter-spacing:.02em}
.gov-brand-sub{font-size:.66rem;color:var(--txt-m);margin-top:.12rem;letter-spacing:.05em;text-transform:uppercase}
.gov-nav{padding:.5rem 0 1.5rem;flex:1}
.nav-section{padding:.75rem 1.25rem .28rem;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-m)}
.nav-link{display:flex;align-items:center;gap:.65rem;padding:.66rem 1.25rem;color:var(--txt-s);text-decoration:none;font-size:.83rem;font-weight:500;transition:all .18s;position:relative}
.nav-link::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:0;background:var(--nl-c,var(--gov));border-radius:0 3px 3px 0;transition:height .18s}
.nav-link:hover,.nav-link.active{color:var(--nl-l,var(--gov-l));background:var(--nl-bg,rgba(29,78,216,.07))}
.nav-link:hover::before,.nav-link.active::before{height:60%}
.nav-link i{width:16px;text-align:center;font-size:.8rem}
.nav-divider{height:1px;background:var(--bdr);margin:.6rem 1.25rem}
.nav-sec-head{padding:.75rem 1.25rem .28rem;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;border-top:1px solid rgba(255,255,255,.04);margin-top:.5rem}
/* MAIN */
.gov-main{margin-left:266px;min-height:100vh;display:flex;flex-direction:column}
.gov-topbar{position:sticky;top:0;z-index:100;background:rgba(5,9,15,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--bdr);padding:.9rem 1.75rem;display:flex;justify-content:space-between;align-items:center}
.topbar-title{font-size:1.12rem;font-weight:700;color:#f1f5f9;letter-spacing:-.3px}
.topbar-breadcrumb{font-size:.68rem;color:var(--txt-m);margin-top:.1rem}
.topbar-right{display:flex;align-items:center;gap:.65rem}
.gov-badge{display:flex;align-items:center;gap:.45rem;padding:.4rem .9rem;background:rgba(29,78,216,.1);border:1px solid rgba(29,78,216,.25);border-radius:20px;font-size:.74rem;font-weight:600;color:var(--gov-l)}
.gov-content{padding:1.5rem 1.75rem;flex:1}
/* CARDS */
.card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.35rem;margin-bottom:1.35rem;transition:border-color .2s}
.card:hover{border-color:var(--bdr-h)}
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txt-s);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.card-title i{color:var(--gov)}
/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.4rem}
.kpi{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.2rem;position:relative;overflow:hidden}
.kpi-ico{width:34px;height:34px;border-radius:8px;background:rgba(29,78,216,.12);color:var(--gov);display:flex;align-items:center;justify-content:center;font-size:.88rem;margin-bottom:.7rem}
.kpi-lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.28rem}
.kpi-val{font-size:1.55rem;font-weight:700;color:#f1f5f9;font-family:'IBM Plex Mono',monospace;line-height:1}
.kpi-val.blue{color:var(--gov-l)}.kpi-val.green{color:#4ade80}.kpi-val.red{color:#f87171}.kpi-val.gold{color:#fbbf24}.kpi-val.purple{color:#c4b5fd}.kpi-val.cyan{color:#67e8f9}.kpi-val.amber{color:#fde68a}.kpi-val.teal{color:#86efac}
.kpi-sub{font-size:.7rem;color:var(--txt-m);margin-top:.28rem}
/* BTNS */
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.15rem;border-radius:8px;font-weight:600;font-size:.81rem;cursor:pointer;transition:all .18s;text-decoration:none;border:none;font-family:inherit;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--gov),var(--gov-d));color:#fff;box-shadow:0 3px 12px rgba(29,78,216,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(29,78,216,.4)}
.btn-secondary{background:rgba(29,78,216,.1);color:var(--gov-l);border:1px solid rgba(29,78,216,.25)}
.btn-secondary:hover{background:rgba(29,78,216,.18)}
.btn-ghost{background:transparent;color:var(--txt-s);border:1px solid var(--bdr)}
.btn-ghost:hover{background:var(--bg-ov);color:var(--txt)}
.btn-danger{background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.25)}
.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-success{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.btn-success:hover{background:rgba(34,197,94,.2)}
.btn-warning{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.btn-warning:hover{background:rgba(245,158,11,.2)}
.btn-purple{background:rgba(124,58,237,.1);color:#c4b5fd;border:1px solid rgba(124,58,237,.25)}
.btn-purple:hover{background:rgba(124,58,237,.2)}
.btn-cyan{background:rgba(8,145,178,.1);color:#67e8f9;border:1px solid rgba(8,145,178,.25)}
.btn-cyan:hover{background:rgba(8,145,178,.2)}
.btn-sm{padding:.35rem .78rem;font-size:.75rem}.btn-xs{padding:.22rem .55rem;font-size:.68rem}
/* FORMS */
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1.05rem}
.form-full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:.3rem}
.form-label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m)}
.form-input,.form-select,.form-textarea{width:100%;padding:.63rem .88rem;background:#060b14;border:1px solid rgba(29,78,216,.2);border-radius:7px;color:var(--txt);font-family:inherit;font-size:.83rem;transition:all .18s}
.form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--gov);box-shadow:0 0 0 3px rgba(29,78,216,.12)}
.form-input::placeholder{color:var(--txt-m)}
.form-select option{background:#0d1526}
.form-textarea{resize:vertical;min-height:80px}
/* MODALS */
.modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.82);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:9999;padding:1.5rem}
.modal-ov:not(.hidden){display:flex}
.modal{background:linear-gradient(140deg,var(--bg-e),var(--bg));border:1px solid rgba(29,78,216,.3);border-radius:16px;box-shadow:0 0 80px rgba(0,0,0,.7);width:90%;max-width:620px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.modal-sm{max-width:440px}.modal-lg{max-width:880px}
.modal-hd{padding:1.15rem 1.4rem;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;background:rgba(29,78,216,.04)}
.modal-hd h2{font-size:.9rem;font-weight:700;color:#f1f5f9;display:flex;align-items:center;gap:.55rem}
.modal-hd h2 i{color:var(--gov)}
.modal-cl{width:29px;height:29px;border-radius:7px;background:rgba(29,78,216,.08);border:1px solid var(--bdr);color:var(--txt-s);font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .18s}
.modal-cl:hover{background:rgba(239,68,68,.15);color:#f87171;transform:rotate(90deg)}
.modal-bd{padding:1.35rem;overflow-y:auto;flex:1}
.modal-ft{padding:.95rem 1.35rem;border-top:1px solid var(--bdr);background:rgba(0,0,0,.2);display:flex;justify-content:flex-end;gap:.55rem}
/* ALERTS */
.alert{padding:.75rem 1.05rem;border-radius:9px;margin-bottom:1.2rem;display:flex;align-items:center;gap:.62rem;font-size:.81rem;font-weight:500}
.alert-ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22);color:#4ade80}
.alert-err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);color:#f87171}
.alert-inf{background:rgba(29,78,216,.08);border:1px solid rgba(29,78,216,.22);color:var(--gov-l)}
.alert-wrn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);color:#fcd34d}
/* TABLE */
.tbl{width:100%;border-collapse:collapse}
.tbl th{text-align:left;padding:.7rem 1rem;background:rgba(29,78,216,.06);border-bottom:2px solid rgba(29,78,216,.18);font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);white-space:nowrap}
.tbl td{padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.81rem;vertical-align:middle}
.tbl tr:hover td{background:rgba(29,78,216,.04)}
.tbl tr:last-child td{border-bottom:none}
.tbl-wrap{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;overflow:hidden;margin-bottom:1.35rem}
/* BADGES */
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .55rem;border-radius:5px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.bg-green{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.bg-red{background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2)}
.bg-yellow{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.bg-blue{background:rgba(29,78,216,.1);color:var(--gov-l);border:1px solid rgba(29,78,216,.2)}
.bg-gray{background:rgba(100,116,139,.1);color:#94a3b8;border:1px solid rgba(100,116,139,.2)}
.bg-gold{background:rgba(251,191,36,.1);color:#fbbf24;border:1px solid rgba(251,191,36,.2)}
.bg-purple{background:rgba(124,58,237,.1);color:#c4b5fd;border:1px solid rgba(124,58,237,.2)}
.bg-cyan{background:rgba(8,145,178,.1);color:#67e8f9;border:1px solid rgba(8,145,178,.2)}
.bg-amber{background:rgba(180,83,9,.1);color:#fde68a;border:1px solid rgba(180,83,9,.2)}
.bg-teal{background:rgba(21,128,61,.1);color:#86efac;border:1px solid rgba(21,128,61,.2)}
/* MISC */
.mono{font-family:'IBM Plex Mono',monospace}.hidden{display:none!important}
.txt-green{color:#4ade80}.txt-red{color:#f87171}.txt-blue{color:var(--gov-l)}.txt-muted{color:var(--txt-m)}.txt-gold{color:#fbbf24}.txt-purple{color:#c4b5fd}.txt-cyan{color:#67e8f9}.txt-teal{color:#86efac}
.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:1.35rem}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:1.05rem}
.progress-bar{height:5px;background:rgba(29,78,216,.15);border-radius:3px;overflow:hidden;margin-top:.4rem}
.progress-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--gov),#60a5fa);transition:width .6s}
.pag{display:flex;align-items:center;gap:.38rem;flex-wrap:wrap;margin-top:.6rem}
.pag a,.pag span{padding:.28rem .6rem;border-radius:6px;font-size:.72rem;border:1px solid var(--bdr);background:var(--bg-c);color:var(--txt-s);text-decoration:none;transition:all .14s}
.pag a:hover{background:var(--bg-ov);color:var(--txt)}
.pag .pag-cur{background:var(--gov);border-color:var(--gov);color:#fff}
@media(max-width:1024px){.gov-sidebar{transform:translateX(-100%)}.gov-main{margin-left:0}.form-grid{grid-template-columns:1fr}.grid2,.grid3{grid-template-columns:1fr}}
</style>
<?php echo $__panelThemeCSS??''; ?>
</head><body>

<aside class="gov-sidebar">
<div class="gov-brand">
  <div class="gov-seal"><i class="fas fa-landmark"></i></div>
  <div>
    <div class="gov-brand-name">État de Los Santos</div>
    <div class="gov-brand-sub">Panel Gouvernemental</div>
  </div>
</div>
<nav class="gov-nav">
<?php
$sec=''; $curGrp=''; $grpOpen=false;
foreach($__gouvPages as $p):
  $__gf=$p['file'];
  // Filtre active_pages
  if(isset($__gouvActivePages[$__gf])&&$__gouvActivePages[$__gf]===false) continue;
  // Filtre grade
  if(!$__gouvIsStaff){
    try{$__xq=$db->prepare("SELECT allowed_grades FROM panel_page_permissions WHERE panel_key='GOUV' AND page_key=? LIMIT 1");
        $__xq->execute([$__gf]);$__xr=$__xq->fetch();
        if($__xr){$__xa=json_decode($__xr['allowed_grades'],true)?:['everyone'];
            if(!in_array('everyone',$__xa)&&!in_array($__gouvUserGrade,$__xa)) continue;}
    }catch(Exception $e){}
  }
  $pSec=$p['section']??''; $grp=$p['grp']??'';
  $active=($currentPage===$p['file'])?' active':'';

  if(!empty($pSec)&&$pSec!==$sec):
    $sec=$pSec;
    if($grp&&$grp!==$curGrp):
      if($grpOpen) echo '</div>';
      $cl=$__grpColors[$grp]??'var(--gov)'; $ll=$__grpLight[$grp]??'var(--gov-l)';
      echo '<div class="nav-grp" style="--nl-c:'.$cl.';--nl-l:'.$ll.';--nl-bg:'.$cl.'11">';
      $grpOpen=true; $curGrp=$grp;
    endif;
    echo '<div class="nav-sec-head" style="color:'.($grp?($__grpLight[$grp]??'var(--txt-m)'):'var(--txt-m)').'">'.htmlspecialchars($pSec).'</div>';
  elseif(empty($pSec)&&$grp&&$grp!==$curGrp):
    if($grpOpen) echo '</div>';
    $cl=$__grpColors[$grp]??'var(--gov)'; $ll=$__grpLight[$grp]??'var(--gov-l)';
    echo '<div class="nav-grp" style="--nl-c:'.$cl.';--nl-l:'.$ll.';--nl-bg:'.$cl.'11">';
    $grpOpen=true; $curGrp=$grp;
  elseif(empty($grp)&&$grpOpen):
    echo '</div>'; $grpOpen=false; $curGrp='';
  endif;
?>
<a href="<?php echo $p['file']; ?>.php" class="nav-link<?php echo $active; ?>">
  <i class="<?php echo $p['icon']; ?>"></i><span><?php echo htmlspecialchars($p['label']); ?></span>
</a>
<?php endforeach;
if($grpOpen) echo '</div>';
?>
<div class="nav-divider"></div>
<?php if(hasRole(['*','Administrateur','Développeur','Staff'])):?>
<a href="<?php echo SITE_URL;?>/pages/staff/dashboard.php" class="nav-link"><i class="fas fa-layer-group"></i><span>Panel Staff</span></a>
<?php endif;?>
<a href="<?php echo SITE_URL;?>/pages/dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Retour MDT</span></a>
<a href="<?php echo SITE_URL;?>/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
</nav>
</aside>

<main class="gov-main">
<div class="gov-topbar">
  <div>
    <div class="topbar-title"><?php echo htmlspecialchars($pageTitle??''); ?></div>
    <div class="topbar-breadcrumb">Gouvernement de Los Santos &nbsp;/&nbsp; <?php echo htmlspecialchars($pageTitle??''); ?></div>
  </div>
  <div class="topbar-right">
    <?php if(isset($__secLabel,$__secColor)):?>
    <div style="display:flex;align-items:center;gap:.4rem;padding:.38rem .85rem;border-radius:20px;font-size:.72rem;font-weight:700;border:1px solid <?php echo $__secColor;?>44;background:<?php echo $__secColor;?>18;color:<?php echo $__secColor;?>">
      <i class="<?php echo $__secIcon??'fas fa-building-columns';?>"></i><?php echo $__secLabel;?>
    </div>
    <?php endif;?>
    <div class="gov-badge"><i class="fas fa-user-shield"></i>
      <?php echo htmlspecialchars(mdt_session_display_name());?> &nbsp;·&nbsp;
      <?php echo htmlspecialchars($_SESSION['user_faction_rank']??'Gouvernement');?>
    </div>
  </div>
</div>
<div class="gov-content">