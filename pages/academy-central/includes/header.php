<?php
// pages/academy-central/includes/header.php
if (!defined('ACADEMY_CENTRAL')) {
    define('ACADEMY_CENTRAL', true);
}
$__acCurrentFile = basename($_SERVER['PHP_SELF'], '.php');
$__acSiteName = 'LysoriaMDT';
try {
    $__acDb = Database::getInstance()->getConnection();
    $__acRow = $__acDb->query("SELECT setting_value FROM admin_settings WHERE setting_key='site_name' LIMIT 1")->fetch();
    if ($__acRow && $__acRow['setting_value']) $__acSiteName = $__acRow['setting_value'];
} catch (Exception $e) {}

$__acUid = (int)($_SESSION['user_id'] ?? 0);
$__acAccessibleFactions = academy_user_accessible_factions($__acUid);

function __ac_is_active(string $file): string {
    global $__acCurrentFile;
    return $__acCurrentFile === $file ? 'active' : '';
}

function __ac_can(string $pageKey): bool {
    global $__acUid;
    return academy_user_can($__acUid, $pageKey);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle ?? 'Académie Centralisée'); ?> — <?php echo htmlspecialchars($__acSiteName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg-base:#03070f;--bg-surface:#080f1d;--bg-elevated:#0d1526;--bg-card:rgba(13,21,38,.85);--bg-hover:rgba(255,255,255,.04);--bg-active:rgba(139,92,246,.14);--border:rgba(255,255,255,.07);--border-hover:rgba(255,255,255,.14);--text-primary:#e2e8f0;--text-secondary:#64748b;--text-muted:#334155;--blue:#3b82f6;--purple:#8b5cf6;--green:#10b981;--red:#ef4444;--orange:#f97316;--yellow:#eab308;--cyan:#06b6d4;--sidebar-w:275px;--sidebar-collapsed-w:68px;--topbar-h:64px}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg-base);color:var(--text-primary);font-size:14px;line-height:1.6;min-height:100vh;overflow-x:hidden}
h1,h2,h3,h4,h5{font-family:'Syne',sans-serif;font-weight:700;line-height:1.2}
code,.mono{font-family:'DM Mono',monospace}
a{color:inherit;text-decoration:none}
.ac-layout{display:flex;min-height:100vh}

/* SIDEBAR */
.ac-sidebar{width:var(--sidebar-w);background:var(--bg-surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:width .3s cubic-bezier(.4,0,.2,1);overflow:hidden}
.ac-sidebar.collapsed{width:var(--sidebar-collapsed-w)}
.ac-brand{display:flex;align-items:center;gap:10px;padding:0 20px;height:var(--topbar-h);border-bottom:1px solid var(--border);flex-shrink:0}
.ac-brand .b-ic{width:32px;height:32px;flex-shrink:0;background:linear-gradient(135deg,#8b5cf6,#6366f1);border-radius:8px;display:grid;place-items:center;font-size:16px;color:#fff}
.ac-brand .b-tx{font-family:'Syne',sans-serif;font-weight:800;font-size:1.05rem;color:#fff;white-space:nowrap;overflow:hidden}
.ac-sidebar.collapsed .b-tx{opacity:0}
.ac-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:12px 0;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.ac-nav::-webkit-scrollbar{width:4px}
.ac-nav::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.ac-sec{margin-bottom:6px}
.ac-sec-label{font-size:.65rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);padding:8px 20px 4px;white-space:nowrap;overflow:hidden;transition:opacity .2s}
.ac-sidebar.collapsed .ac-sec-label{opacity:0}
.ac-item{display:flex;align-items:center;gap:12px;padding:9px 20px;color:var(--text-secondary);transition:all .2s;position:relative;white-space:nowrap;cursor:pointer;border:none;background:none;width:100%;font-size:.875rem;font-family:'DM Sans',sans-serif;text-align:left}
.ac-item:hover{background:var(--bg-hover);color:var(--text-primary)}
.ac-item.active{background:var(--bg-active);color:#c4b5fd;font-weight:600}
.ac-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--purple);border-radius:0 2px 2px 0}
.ac-item .ac-ic{width:20px;height:20px;display:grid;place-items:center;flex-shrink:0;font-size:14px}
.ac-item .ac-lb{overflow:hidden;transition:opacity .2s;flex:1}
.ac-sidebar.collapsed .ac-lb{opacity:0;width:0}
.ac-chev{margin-left:auto;font-size:10px;transition:transform .3s;color:var(--text-muted)}
.ac-submenu{overflow:hidden;max-height:0;transition:max-height .3s ease;background:rgba(0,0,0,.18)}
.ac-submenu.open{max-height:800px}
.ac-sub-group{font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);padding:8px 20px 4px 36px;white-space:nowrap}
.ac-sidebar.collapsed .ac-sub-group{display:none}
.ac-sub-item{display:flex;align-items:center;gap:10px;padding:7px 20px 7px 50px;color:var(--text-secondary);font-size:.82rem;transition:all .15s;white-space:nowrap;border-left:2px solid transparent}
.ac-sub-item:hover{color:#fff;background:var(--bg-hover);border-left-color:rgba(139,92,246,.3)}
.ac-sub-item.active{color:#c4b5fd;font-weight:600;background:var(--bg-active);border-left-color:var(--purple)}
.ac-sub-item i{opacity:.7;flex-shrink:0;width:14px;text-align:center}
.ac-sub-item.active i,.ac-sub-item:hover i{opacity:1}
.ac-sidebar.collapsed .ac-sub-item{display:none}
.ac-foot{padding:14px 16px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;gap:10px}
.ac-user-ava{width:34px;height:34px;border-radius:8px;background:var(--purple);display:grid;place-items:center;font-weight:700;font-size:13px;overflow:hidden;flex-shrink:0}
.ac-user-ava img{width:100%;height:100%;object-fit:cover}
.ac-user-info{overflow:hidden;flex:1}
.ac-user-name{font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ac-user-role{font-size:.7rem;color:var(--text-secondary)}
.ac-sidebar.collapsed .ac-user-info{display:none}
.ac-logout{width:30px;height:30px;border-radius:7px;background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2);display:grid;place-items:center;flex-shrink:0;transition:all .2s;font-size:12px}
.ac-logout:hover{background:var(--red);color:#fff;border-color:var(--red)}

/* TOPBAR */
.ac-top{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--topbar-h);background:rgba(8,15,29,.85);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;z-index:90;transition:left .3s}
.ac-sidebar.collapsed~.ac-main .ac-top{left:var(--sidebar-collapsed-w)}
.ac-top-left{display:flex;align-items:center;gap:16px}
.ac-toggle{width:34px;height:34px;border:1px solid var(--border);border-radius:8px;background:none;color:var(--text-secondary);cursor:pointer;display:grid;place-items:center;transition:all .2s}
.ac-toggle:hover{border-color:var(--border-hover);color:#fff}
.ac-bc{display:flex;align-items:center;gap:8px;font-size:.85rem;color:var(--text-secondary)}
.ac-bc a{color:var(--text-secondary)}
.ac-bc a:hover{color:var(--text-primary)}
.ac-bc .sep{opacity:.3}
.ac-bc .cur{color:var(--text-primary);font-weight:600}
.ac-top-right{display:flex;align-items:center;gap:10px}
.ac-top-btn{width:34px;height:34px;border:1px solid var(--border);border-radius:8px;background:none;color:var(--text-secondary);cursor:pointer;display:grid;place-items:center;transition:all .2s;position:relative}
.ac-top-btn:hover{border-color:var(--border-hover);color:#fff}

/* MAIN */
.ac-main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s}
.ac-sidebar.collapsed~.ac-main{margin-left:var(--sidebar-collapsed-w)}
.ac-content{margin-top:var(--topbar-h);padding:28px 28px 64px;flex:1;max-width:1600px;width:100%;margin-left:auto;margin-right:auto}
.page-header{margin-bottom:24px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-bottom:18px;border-bottom:1px solid var(--border)}
.page-header-left{flex:1;min-width:240px}
.page-header-left .ph-eyebrow{font-size:.7rem;color:var(--purple);text-transform:uppercase;letter-spacing:.12em;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.page-header-left h1{font-size:1.7rem;margin-bottom:4px;letter-spacing:-.01em;display:flex;align-items:center;gap:12px}
.page-header-left h1 .ph-ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:1rem;flex-shrink:0;background:rgba(139,92,246,.14);color:var(--purple)}
.page-header-left p{color:var(--text-secondary);font-size:.88rem;line-height:1.55;max-width:680px}
.page-header-right{display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap}

/* CARDS / TABLES / BADGES / BUTTONS */
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;backdrop-filter:blur(8px)}
.card-header{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);gap:12px;flex-wrap:wrap}
.card-title{font-family:'Syne',sans-serif;font-size:.92rem;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff}
.card-title i{color:var(--purple);font-size:13px;opacity:.85}
.card-body{padding:18px 20px}
.card-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;background:rgba(0,0,0,.12)}

.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:22px}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .25s;position:relative;overflow:hidden}
.stat-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--stat-c,var(--purple));opacity:.7}
.stat-card:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.stat-ic{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;flex-shrink:0;background:var(--stat-bg,rgba(139,92,246,.14));color:var(--stat-c,#c4b5fd)}
.stat-info{flex:1;min-width:0}
.stat-label{font-size:.68rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:3px}
.stat-value{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;line-height:1;color:#fff}
.stat-meta{font-size:.7rem;color:var(--text-secondary);margin-top:3px}
/* Variantes statistiques */
.stat-blue{--stat-c:#60a5fa;--stat-bg:rgba(59,130,246,.14)}
.stat-green{--stat-c:#34d399;--stat-bg:rgba(16,185,129,.14)}
.stat-red{--stat-c:#f87171;--stat-bg:rgba(239,68,68,.14)}
.stat-orange{--stat-c:#fb923c;--stat-bg:rgba(249,115,22,.14)}
.stat-yellow{--stat-c:#fde047;--stat-bg:rgba(234,179,8,.14)}
.stat-cyan{--stat-c:#22d3ee;--stat-bg:rgba(6,182,212,.14)}

/* Toolbar : barre filtres/actions au-dessus d'un tableau ou d'une grille */
.toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px 18px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;margin-bottom:14px}
.toolbar > .grow{flex:1;min-width:200px}
.search-input{position:relative;display:flex;align-items:center}
.search-input input{padding-left:34px;height:36px;font-size:.84rem}
.search-input i{position:absolute;left:11px;color:var(--text-secondary);font-size:13px;pointer-events:none}

/* Segmented control pour filtres rapides */
.seg{display:inline-flex;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;padding:3px;gap:2px}
.seg button{background:none;border:0;padding:6px 12px;font-size:.78rem;font-weight:600;color:var(--text-secondary);cursor:pointer;border-radius:7px;transition:all .15s;display:inline-flex;align-items:center;gap:6px;font-family:inherit}
.seg button:hover{color:#fff}
.seg button.on{background:rgba(139,92,246,.18);color:#c4b5fd}

.table-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:600px}
thead th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--text-secondary);padding:11px 16px;background:rgba(255,255,255,.025);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left;position:sticky;top:0}
tbody td{padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.04);font-size:.86rem;vertical-align:middle;color:#cbd5e1}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover{background:var(--bg-hover)}
tbody td .row-strong{color:#fff;font-weight:600}
tbody td .row-muted{color:var(--text-secondary);font-size:.78rem}

/* Avatar utilisateur dans une cellule */
.usr{display:inline-flex;align-items:center;gap:8px}
.usr-ava{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;display:grid;place-items:center;font-weight:700;font-size:.7rem;flex-shrink:0;font-family:'Syne',sans-serif}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.badge-blue{background:rgba(59,130,246,.15);color:#93c5fd}
.badge-purple{background:rgba(139,92,246,.15);color:#c4b5fd}
.badge-green{background:rgba(16,185,129,.15);color:#6ee7b7}
.badge-red{background:rgba(239,68,68,.15);color:#fca5a5}
.badge-orange{background:rgba(249,115,22,.15);color:#fdba74}
.badge-yellow{background:rgba(234,179,8,.15);color:#fde047}
.badge-slate{background:rgba(100,116,139,.15);color:#94a3b8}

.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;text-decoration:none;line-height:1}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:#2563eb}
.btn-purple{background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff}
.btn-purple:hover{opacity:.92;transform:translateY(-1px)}
.btn-secondary{background:var(--bg-hover);color:var(--text-secondary);border:1px solid var(--border)}
.btn-secondary:hover{border-color:var(--border-hover);color:#fff}
.btn-danger{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-success{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
.btn-success:hover{background:var(--green);color:#fff}
.btn-sm{padding:5px 11px;font-size:.78rem}
.btn-icon{padding:7px;gap:0}

.form-group{margin-bottom:16px}
.form-label{display:block;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary);margin-bottom:5px}
.form-control{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:9px 13px;font-size:.875rem;font-family:'DM Sans',sans-serif;outline:none;transition:all .2s}
.form-control:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(139,92,246,.15)}
textarea.form-control{resize:vertical;min-height:90px}
select.form-control{cursor:pointer}

.alert{padding:11px 16px;border-radius:10px;border-left:3px solid;font-size:.875rem;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:rgba(16,185,129,.08);border-color:var(--green);color:#a7f3d0}
.alert-danger{background:rgba(239,68,68,.08);border-color:var(--red);color:#fecaca}
.alert-info{background:rgba(59,130,246,.08);border-color:var(--blue);color:#bfdbfe}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px}
.modal-overlay.open{display:grid}
.modal{background:var(--bg-elevated);border:1px solid var(--border);border-radius:18px;width:100%;max-width:560px;padding:24px;max-height:90vh;overflow-y:auto}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.modal-close{width:30px;height:30px;border-radius:7px;background:var(--bg-hover);border:1px solid var(--border);color:var(--text-secondary);cursor:pointer;display:grid;place-items:center}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:22px}

.empty-state{text-align:center;padding:48px 22px;color:var(--text-secondary)}
.empty-state i{font-size:2.4rem;margin-bottom:12px;opacity:.32;display:block;color:var(--purple)}
.empty-state .es-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#fff;margin-bottom:6px}
.empty-state p{font-size:.85rem;max-width:420px;margin:0 auto 14px}

.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:1024px){.grid-2,.grid-3{grid-template-columns:1fr}}

/* Faction strip : bandeau identitaire en haut des pages faction */
.fac-strip{display:flex;align-items:center;gap:14px;padding:12px 16px;background:linear-gradient(135deg,var(--fac-c-15,rgba(139,92,246,.12)) 0%,transparent 100%);border:1px solid var(--fac-c-25,rgba(139,92,246,.25));border-radius:12px;margin-bottom:18px}
.fac-strip .fs-ic{width:36px;height:36px;border-radius:10px;background:var(--fac-c,var(--purple));color:#fff;display:grid;place-items:center;font-size:1rem;flex-shrink:0;box-shadow:0 4px 12px -4px var(--fac-c,var(--purple))}
.fac-strip .fs-info{flex:1;min-width:0}
.fac-strip .fs-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;color:#fff}
.fac-strip .fs-sub{font-size:.74rem;color:var(--text-secondary)}

/* Tabs réutilisables */
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:18px;overflow-x:auto;scrollbar-width:none}
.tabs::-webkit-scrollbar{display:none}
.tab{background:none;border:0;padding:11px 16px;font-size:.82rem;font-weight:600;color:var(--text-secondary);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;display:inline-flex;align-items:center;gap:6px;font-family:inherit;white-space:nowrap}
.tab:hover{color:#fff}
.tab.on{color:var(--purple);border-color:var(--purple)}
.tab .tab-pill{background:rgba(139,92,246,.18);color:#c4b5fd;border-radius:50px;padding:1px 8px;font-size:.65rem;font-weight:700}

/* Toast léger */
.ac-toast{position:fixed;bottom:22px;right:22px;background:#10b981;color:#fff;padding:11px 18px;border-radius:9px;font-weight:600;font-size:.85rem;z-index:9999;box-shadow:0 12px 30px -10px rgba(16,185,129,.5);transform:translateY(20px);opacity:0;transition:all .25s;display:inline-flex;align-items:center;gap:8px}
.ac-toast.on{transform:none;opacity:1}
.ac-toast.err{background:#ef4444;box-shadow:0 12px 30px -10px rgba(239,68,68,.5)}
@media(max-width:768px){
  .ac-sidebar{transform:translateX(-100%)}
  .ac-sidebar.mobile-open{transform:translateX(0)}
  .ac-main{margin-left:0!important}.ac-top{left:0!important}
}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
</style>
<?php if (!empty($extraCss)): ?><style><?php echo $extraCss; ?></style><?php endif; ?>
</head>
<body>
<div class="ac-layout">

<aside class="ac-sidebar" id="acSidebar">
  <a href="<?php echo SITE_URL; ?>/pages/academy-central/dashboard.php" class="ac-brand">
    <div class="b-ic"><i class="fa-solid fa-building-columns"></i></div>
    <div class="b-tx">Académie<br><span style="font-size:.6rem;color:var(--purple);letter-spacing:.15em">CENTRALISÉE</span></div>
  </a>

  <nav class="ac-nav">

    <div class="ac-sec">
      <div class="ac-sec-label">Global</div>
      <a href="<?php echo SITE_URL; ?>/pages/academy-central/dashboard.php" class="ac-item <?php echo __ac_is_active('dashboard'); ?>">
        <span class="ac-ic"><i class="fa-solid fa-gauge-high"></i></span>
        <span class="ac-lb">Dashboard</span>
      </a>
      <?php if (__ac_can('forums') || academy_is_staff_bypass()): ?>
      <a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php" class="ac-item <?php echo __ac_is_active('forums'); ?>">
        <span class="ac-ic"><i class="fa-solid fa-comments"></i></span>
        <span class="ac-lb">Forums</span>
      </a>
      <?php endif; ?>
    </div>

    <?php foreach ($__acAccessibleFactions as $fk):
      $m = academy_faction_meta($fk);
      $slug = $m['slug'];
      $subOpen = (strpos($__acCurrentFile, '_' . $slug) !== false) ? 'open' : '';
      // Pages regroupées par usage : Équipe → Recrutement → Pédagogie → Configuration
      $pageGroups = [
        'Équipe' => [
          ['effectifs',          'users',            'Effectifs'],
          ['matricules',         'id-card',          'Matricules'],
        ],
        'Recrutement' => [
          ['log_candidature',    'file-signature',   'Candidatures'],
          ['log_qcm',            'clipboard-list',   'Log QCM'],
        ],
        'Pédagogie' => [
          ['formations',         'chalkboard-user',  'Formations'],
          ['documents',          'folder-open',      'Documents'],
        ],
        'Configuration' => [
          ['config_candidature', 'sliders',          'Config candidature'],
          ['config_qcm',         'circle-question',  'Config QCM'],
        ],
      ];
      $anyAccess = false;
      foreach ($pageGroups as $gp) foreach ($gp as $p) if (__ac_can($p[0] . '_' . $slug)) { $anyAccess = true; break 2; }
      if (!$anyAccess) continue;
    ?>
    <div class="ac-sec">
      <button class="ac-item" onclick="acToggle('sub-<?php echo $slug; ?>', this)">
        <span class="ac-ic" style="color:<?php echo $m['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($m['icon']); ?>"></i></span>
        <span class="ac-lb"><?php echo htmlspecialchars($m['name']); ?></span>
        <i class="fa-solid fa-chevron-down ac-chev"></i>
      </button>
      <div class="ac-submenu <?php echo $subOpen; ?>" id="sub-<?php echo $slug; ?>">
        <?php foreach ($pageGroups as $groupLabel => $pages):
          $groupAny = false;
          foreach ($pages as $p) if (__ac_can($p[0] . '_' . $slug)) { $groupAny = true; break; }
          if (!$groupAny) continue;
        ?>
        <div class="ac-sub-group"><?php echo $groupLabel; ?></div>
        <?php foreach ($pages as $p):
          $pageKey = $p[0] . '_' . $slug;
          if (!__ac_can($pageKey)) continue;
          $file = $p[0] . '_' . $slug;
          $active = $__acCurrentFile === $file ? 'active' : '';
        ?>
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/<?php echo $file; ?>.php" class="ac-sub-item <?php echo $active; ?>">
          <i class="fa-solid fa-<?php echo $p[1]; ?>" style="font-size:.7rem"></i> <?php echo $p[2]; ?>
        </a>
        <?php endforeach; endforeach; ?>
        <?php if (__ac_can('forums') || academy_is_staff_bypass()):
          $isForumActive = ($__acCurrentFile === 'forums' && (($_GET['faction'] ?? '') === $fk));
        ?>
        <div class="ac-sub-group">Communication</div>
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php?view=faction&faction=<?php echo $fk; ?>" class="ac-sub-item <?php echo $isForumActive ? 'active' : ''; ?>">
          <i class="fa-solid fa-comments" style="font-size:.7rem"></i> Forum
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="ac-sec">
      <div class="ac-sec-label">Retour</div>
      <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="ac-item">
        <span class="ac-ic"><i class="fa-solid fa-arrow-left"></i></span>
        <span class="ac-lb">MDT principal</span>
      </a>
      <a href="<?php echo SITE_URL; ?>/pages/academy/dashboard.php" class="ac-item">
        <span class="ac-ic"><i class="fa-solid fa-graduation-cap"></i></span>
        <span class="ac-lb">Académie publique</span>
      </a>
    </div>

  </nav>

  <div class="ac-foot">
    <div class="ac-user-ava">
      <?php if (!empty($_SESSION['user_avatar'])): ?>
        <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="">
      <?php else: ?>
        <?php echo htmlspecialchars(strtoupper(substr($_SESSION['user_username'] ?? 'U', 0, 1))); ?>
      <?php endif; ?>
    </div>
    <div class="ac-user-info">
      <div class="ac-user-name"><?php echo htmlspecialchars($_SESSION['user_username'] ?? ''); ?></div>
      <div class="ac-user-role"><?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?></div>
    </div>
    <a href="<?php echo SITE_URL; ?>/logout.php" class="ac-logout" title="Déconnexion">
      <i class="fa-solid fa-power-off"></i>
    </a>
  </div>
</aside>

<div class="ac-main">
  <header class="ac-top">
    <div class="ac-top-left">
      <button class="ac-toggle" id="acSidebarToggle"><i class="fa-solid fa-bars" style="font-size:14px"></i></button>
      <nav class="ac-bc">
        <a href="<?php echo SITE_URL; ?>/pages/academy-central/dashboard.php"><i class="fa-solid fa-house" style="font-size:12px"></i></a>
        <?php if (!empty($breadcrumb)): foreach ($breadcrumb as $b): ?>
          <span class="sep">/</span>
          <?php if (isset($b['url'])): ?>
            <a href="<?php echo $b['url']; ?>"><?php echo htmlspecialchars($b['label']); ?></a>
          <?php else: ?>
            <span class="cur"><?php echo htmlspecialchars($b['label']); ?></span>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </nav>
    </div>
    <div class="ac-top-right">
      <?php $__acUnread = academy_forum_unread_count($__acUid); ?>
      <div class="f-notif-bell" style="position:relative">
        <button class="ac-top-btn" title="Notifications du forum" onclick="acForumNotifsToggle(this)"><i class="fa-solid fa-bell"></i></button>
        <?php if ($__acUnread > 0): ?><span class="fnb-dot" id="acFnDot"><?php echo $__acUnread; ?></span><?php endif; ?>
        <div class="f-notif-pop" id="acFnPop">
          <div class="fnp-head">
            <h4><i class="fa-solid fa-bell"></i> Notifications</h4>
            <button class="ac-top-btn" style="width:28px;height:28px;font-size:11px" onclick="acForumNotifsRead()" title="Tout marquer comme lu"><i class="fa-solid fa-check-double"></i></button>
          </div>
          <div id="acFnList"><div class="fnp-empty">Chargement…</div></div>
        </div>
      </div>
      <a href="<?php echo SITE_URL; ?>/pages/espace-perso.php" class="ac-top-btn" title="Espace perso"><i class="fa-solid fa-user"></i></a>
    </div>
  </header>
  <main class="ac-content">
<script>
function acToggle(id, btn){
  const el = document.getElementById(id); if (!el) return;
  el.classList.toggle('open');
  const chev = btn.querySelector('.ac-chev');
  if (chev) chev.style.transform = el.classList.contains('open') ? 'rotate(180deg)' : '';
}
window.acToast = function(msg, isErr){
  const t = document.createElement('div');
  t.className = 'ac-toast' + (isErr ? ' err' : '');
  t.innerHTML = '<i class="fa-solid fa-' + (isErr ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
  document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('on'));
  setTimeout(() => { t.classList.remove('on'); setTimeout(() => t.remove(), 300); }, 2800);
};
document.addEventListener('DOMContentLoaded', () => {
  const tg = document.getElementById('acSidebarToggle');
  const sb = document.getElementById('acSidebar');
  if (tg && sb) tg.addEventListener('click', () => {
    if (window.innerWidth <= 768) sb.classList.toggle('mobile-open');
    else sb.classList.toggle('collapsed');
  });
  // Fermeture des modales par clic sur l'overlay ou touche Esc
  document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(o => o.classList.remove('open'));
    const np = document.getElementById('acFnPop'); if (np) np.classList.remove('open');
  }});
  // Ferme la pop notif si clic à l'extérieur
  document.addEventListener('click', e => {
    const wrap = document.querySelector('.f-notif-bell');
    if (wrap && !wrap.contains(e.target)) {
      const np = document.getElementById('acFnPop'); if (np) np.classList.remove('open');
    }
  });
});
async function acForumNotifsToggle(btn){
  const pop = document.getElementById('acFnPop'); if (!pop) return;
  const wasOpen = pop.classList.contains('open');
  pop.classList.toggle('open');
  if (!wasOpen) await acForumNotifsLoad();
}
async function acForumNotifsLoad(){
  const list = document.getElementById('acFnList'); if (!list) return;
  list.innerHTML = '<div class="fnp-empty">Chargement…</div>';
  try {
    const r = await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/forum_notifications.php?action=list&limit=10');
    const j = await r.json();
    if (!j || !j.success) { list.innerHTML = '<div class="fnp-empty">Impossible de charger</div>'; return; }
    if (!j.items || j.items.length === 0) { list.innerHTML = '<div class="fnp-empty"><i class="fa-solid fa-bell-slash" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:.6"></i>Aucune notification</div>'; return; }
    const ICONS = { reply:'reply', mention:'at', quote:'quote-right', like:'heart' };
    const LABELS= { reply:'a répondu à votre sujet', mention:'vous a mentionné', quote:'a cité votre message', like:'a aimé votre message' };
    list.innerHTML = j.items.map(n => {
      const ic = ICONS[n.kind] || 'bell';
      const lb = LABELS[n.kind] || 'a interagi';
      const av = n.from_username ? n.from_username.charAt(0).toUpperCase() : '?';
      const url = n.thread_id ? '<?php echo SITE_URL; ?>/pages/academy-central/forums.php?view=thread&thread=' + n.thread_id + (n.post_id ? '#post-' + n.post_id : '') : '#';
      const when = relTime(n.created_at);
      const author = escapeHtml(n.from_username || 'Quelqu’un');
      const titleSnip = n.thread_title ? ' « <em>' + escapeHtml(n.thread_title.substring(0,60)) + (n.thread_title.length>60?'…':'') + '</em> »' : '';
      return '<a href="' + url + '" class="fnp-item ' + (n.is_read == 0 ? 'unread' : '') + '">'
        + '<div class="fnp-ic"><i class="fa-solid fa-' + ic + '"></i></div>'
        + '<div class="fnp-info"><div class="fnp-text"><strong>' + author + '</strong> ' + lb + titleSnip + '</div>'
        + '<div class="fnp-when">' + when + '</div></div></a>';
    }).join('');
  } catch(e) { list.innerHTML = '<div class="fnp-empty">Erreur réseau</div>'; }
}
async function acForumNotifsRead(){
  const fd = new FormData();
  fd.append('csrf', '<?php echo academy_csrf_token(); ?>');
  await fetch('<?php echo SITE_URL; ?>/pages/academy-central/ajax/forum_notifications.php?action=read', { method:'POST', body:fd });
  const dot = document.getElementById('acFnDot'); if (dot) dot.remove();
  await acForumNotifsLoad();
}
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
function relTime(s){ if (!s) return '—'; const d = new Date(s.replace(' ', 'T')); const diff = (Date.now() - d.getTime())/1000; if (diff<60) return "à l'instant"; if (diff<3600) return 'il y a ' + Math.floor(diff/60) + ' min'; if (diff<86400) return 'il y a ' + Math.floor(diff/3600) + ' h'; if (diff<604800) return 'il y a ' + Math.floor(diff/86400) + ' j'; return d.toLocaleDateString('fr-FR'); }
</script>
<style>
/* Notif bell — disponibles sur toutes les pages academy-central */
.f-notif-bell{position:relative}
.f-notif-bell .fnb-dot{position:absolute;top:-3px;right:-3px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:grid;place-items:center;padding:0 4px;border:2px solid var(--bg-base);font-family:'DM Mono',monospace;pointer-events:none}
.f-notif-pop{position:absolute;top:48px;right:0;width:380px;max-height:480px;overflow-y:auto;background:var(--bg-elevated);border:1px solid var(--border);border-radius:12px;box-shadow:0 18px 40px -12px rgba(0,0,0,.6);z-index:200;display:none}
.f-notif-pop.open{display:block}
.f-notif-pop .fnp-head{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--bg-elevated);z-index:1}
.f-notif-pop .fnp-head h4{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;color:#fff}
.f-notif-pop .fnp-empty{padding:30px 18px;text-align:center;color:var(--text-secondary);font-size:.82rem}
.f-notif-pop .fnp-item{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;text-decoration:none;color:inherit;transition:background .15s}
.f-notif-pop .fnp-item:hover{background:var(--bg-hover)}
.f-notif-pop .fnp-item.unread{background:rgba(139,92,246,.05);border-left:3px solid var(--purple)}
.f-notif-pop .fnp-item .fnp-ic{width:32px;height:32px;border-radius:8px;background:rgba(139,92,246,.14);color:#c4b5fd;display:grid;place-items:center;font-size:.78rem;flex-shrink:0}
.f-notif-pop .fnp-item .fnp-info{flex:1;min-width:0}
.f-notif-pop .fnp-item .fnp-text{font-size:.82rem;color:#cbd5e1;line-height:1.4;margin-bottom:3px}
.f-notif-pop .fnp-item .fnp-text strong{color:#fff;font-weight:600}
.f-notif-pop .fnp-item .fnp-when{font-size:.7rem;color:var(--text-muted)}
@media(max-width:520px){.f-notif-pop{position:fixed;top:64px;right:8px;left:8px;width:auto}}
</style>
