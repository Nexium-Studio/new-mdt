<?php
// pages/staff/includes/header.php
if (!defined('STAFF_PANEL')) die('Accès interdit');

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function isActiveStaff($page, $dir = null) {
    global $currentPage, $currentDir;
    if ($dir && $currentDir !== $dir) return false;
    if (is_array($page)) return in_array($currentPage, $page) ? 'active' : '';
    return $currentPage === $page ? 'active' : '';
}

$db = Database::getInstance()->getConnection();
$dispatchActive    = false;
$lifeInvaderActive = false;
$mailEnabled       = false;
$hackMdtActive     = false;
$mailUnreadTotal   = 0;
$siteName          = 'LysoriaMDT';
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ('addon_dispatch','addon_life_invader','addon_hack_mdt','site_name','mail_enabled')")->fetchAll();
    foreach ($rows as $r) {
        if ($r['setting_key'] === 'addon_dispatch')      $dispatchActive    = (bool)$r['setting_value'];
        if ($r['setting_key'] === 'addon_life_invader')  $lifeInvaderActive = (bool)$r['setting_value'];
        if ($r['setting_key'] === 'addon_hack_mdt')      $hackMdtActive     = (bool)$r['setting_value'];
        if ($r['setting_key'] === 'mail_enabled')        $mailEnabled       = (bool)$r['setting_value'];
        if ($r['setting_key'] === 'site_name' && $r['setting_value']) $siteName = $r['setting_value'];
    }
} catch (Exception $e) {}
// Compteur global mails non-lus (vue staff — toutes boîtes)
if ($mailEnabled) {
    try {
        $mailUnreadTotal = (int)$db->query("
            SELECT COUNT(*) FROM mail_boxes
            WHERE folder = 'inbox' AND is_read = 0 AND deleted_at IS NULL
        ")->fetchColumn();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Staff'); ?> — <?php echo htmlspecialchars($siteName); ?></title>
    <script>
    (function(){
        try {
            var t = localStorage.getItem('staffTheme');
            if (t !== 'light' && t !== 'dark') t = 'dark';
            document.documentElement.setAttribute('data-theme', t);
        } catch(e) { document.documentElement.setAttribute('data-theme','dark'); }
    })();
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
/* Marque Nexium — bleu → violet, filet dégradé signature */
--bleu:#1E6FE5;--violet:#6C3FC5;--cyan:#2EA8E6;--indigo:#241C63;
--grad:linear-gradient(120deg,var(--bleu),var(--violet));
/* Fonds — thème sombre (nuit / anthracite) */
--bg-base:#0C1023;--bg-surface:#0e142a;--bg-elevated:#171C31;
--bg-card:#141a2e;--bg-hover:rgba(255,255,255,.04);--bg-active:rgba(30,111,229,.14);
--border:rgba(255,255,255,.08);--border-hover:rgba(255,255,255,.16);
--text-primary:#F4F6FB;--text-secondary:#9aa3bc;--text-muted:#565E78;
/* Accents — bleu/violet Nexium + couleurs de statut */
--blue:#1E6FE5;--purple:#6C3FC5;--green:#10b981;--red:#ef4444;
--orange:#f97316;--yellow:#eab308;
--sidebar-w:260px;--sidebar-collapsed-w:68px;--topbar-h:64px;
}
body{font-family:'Inter',sans-serif;background:var(--bg-base);color:var(--text-primary);font-size:14px;line-height:1.6;min-height:100vh;overflow-x:hidden}
h1,h2,h3,h4,h5{font-family:'Montserrat',sans-serif;font-weight:700;line-height:1.2}
code,.mono{font-family:'DM Mono',monospace}
/* LAYOUT */
.staff-layout{display:flex;min-height:100vh}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--bg-surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:width .3s cubic-bezier(.4,0,.2,1);overflow:hidden}
.sidebar.collapsed{width:var(--sidebar-collapsed-w)}
.sidebar-brand{display:flex;align-items:center;gap:10px;padding:0 20px;height:var(--topbar-h);border-bottom:1px solid var(--border);text-decoration:none;flex-shrink:0}
.sidebar-brand .brand-icon{width:32px;height:32px;flex-shrink:0;background:var(--grad);border-radius:8px;display:grid;place-items:center;font-size:16px;color:#fff}
.sidebar-brand .brand-text{font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.1rem;color:#fff;white-space:nowrap;overflow:hidden}
.sidebar-brand .brand-text span{color:var(--blue)}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:12px 0;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.nav-section{margin-bottom:8px}
.nav-section-label{font-size:.65rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);padding:8px 20px 4px;white-space:nowrap;overflow:hidden;transition:opacity .2s}
.sidebar.collapsed .nav-section-label{opacity:0}
.nav-item{display:flex;align-items:center;gap:12px;padding:9px 20px;color:var(--text-secondary);text-decoration:none;transition:all .2s;position:relative;white-space:nowrap;cursor:pointer;border:none;background:none;width:100%;font-size:.875rem;font-family:'Inter',sans-serif}
.nav-item:hover{background:var(--bg-hover);color:var(--text-primary)}
.nav-item.active{background:var(--bg-active);color:var(--blue);font-weight:600}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--blue);border-radius:0 2px 2px 0}
.nav-item .nav-icon{width:20px;height:20px;display:grid;place-items:center;flex-shrink:0;font-size:15px}
.nav-item .nav-label{overflow:hidden;transition:opacity .2s}
.sidebar.collapsed .nav-label{opacity:0;width:0}
.nav-item .nav-badge{margin-left:auto;background:var(--blue);color:#fff;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:10px;flex-shrink:0}
.sidebar.collapsed .nav-badge{display:none}
.nav-chevron{margin-left:auto!important;font-size:10px!important;transition:transform .3s;color:var(--text-muted)}
.nav-submenu{overflow:hidden;max-height:0;transition:max-height .3s ease}
.nav-submenu.open{max-height:400px}
.nav-sub-item{display:flex;align-items:center;gap:10px;padding:7px 20px 7px 50px;color:var(--text-secondary);text-decoration:none;font-size:.83rem;transition:all .2s;white-space:nowrap}
.nav-sub-item:hover{color:var(--text-primary);background:var(--bg-hover)}
.nav-sub-item.active{color:var(--blue)}
.nav-sub-item::before{content:'–';opacity:.4;flex-shrink:0}
.sidebar.collapsed .nav-sub-item{display:none}
.sidebar-footer{padding:16px;border-top:1px solid var(--border);flex-shrink:0}
.sidebar-user{display:flex;align-items:center;gap:10px}
.sidebar-user .u-avatar{width:34px;height:34px;border-radius:8px;background:var(--blue);display:grid;place-items:center;font-weight:700;font-size:13px;flex-shrink:0;overflow:hidden}
.sidebar-user .u-info{overflow:hidden}
.sidebar-user .u-name{font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-user .u-role{font-size:.7rem;color:var(--text-secondary)}
.sidebar.collapsed .u-info{display:none}
.sidebar-logout{margin-left:auto;width:30px;height:30px;border-radius:7px;background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2);display:grid;place-items:center;flex-shrink:0;text-decoration:none;transition:all .2s;font-size:12px;line-height:1}
.sidebar-logout:hover{background:var(--red);color:#fff;border-color:var(--red)}
.sidebar-logout i{display:block;line-height:1}
/* TOPBAR */
.topbar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--topbar-h);background:rgba(8,15,29,.85);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;z-index:90;transition:left .3s cubic-bezier(.4,0,.2,1)}
.sidebar.collapsed~.main-wrapper .topbar{left:var(--sidebar-collapsed-w)}
.topbar-left{display:flex;align-items:center;gap:16px}
.topbar-toggle{width:34px;height:34px;border:1px solid var(--border);border-radius:8px;background:none;color:var(--text-secondary);cursor:pointer;display:grid;place-items:center;transition:all .2s}
.topbar-toggle:hover{border-color:var(--border-hover);color:#fff}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:.85rem;color:var(--text-secondary)}
.breadcrumb a{color:var(--text-secondary);text-decoration:none}
.breadcrumb a:hover{color:var(--text-primary)}
.breadcrumb .sep{opacity:.3}
.breadcrumb .current{color:var(--text-primary);font-weight:600}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-btn{width:34px;height:34px;border:1px solid var(--border);border-radius:8px;background:none;color:var(--text-secondary);cursor:pointer;display:grid;place-items:center;transition:all .2s;text-decoration:none;position:relative}
.topbar-btn:hover{border-color:var(--border-hover);color:#fff}
.topbar-btn .notif-dot{position:absolute;top:5px;right:5px;width:7px;height:7px;background:var(--red);border-radius:50%;border:2px solid var(--bg-surface)}
/* MAIN */
.main-wrapper{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s cubic-bezier(.4,0,.2,1)}
.sidebar.collapsed~.main-wrapper{margin-left:var(--sidebar-collapsed-w)}
.main-content{margin-top:var(--topbar-h);padding:32px 28px;flex:1}
/* PAGE HEADER */
.page-header{margin-bottom:32px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.page-header-left h1{font-size:1.75rem;margin-bottom:4px}
.page-header-left p{color:var(--text-secondary);font-size:.9rem}
.page-header-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
/* CARDS */
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.card-header{padding:20px 24px 0;display:flex;align-items:center;justify-content:space-between}
.card-title{font-family:'Montserrat',sans-serif;font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}
.card-title i{color:var(--text-secondary);font-size:14px}
.card-body{padding:20px 24px}
/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-bottom:32px}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:22px;display:flex;align-items:flex-start;gap:16px;position:relative;overflow:hidden;transition:border-color .3s,transform .2s}
.stat-card:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--grad);opacity:.55;pointer-events:none}
.stat-icon{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:1.2rem;flex-shrink:0;background:rgba(255,255,255,.05);color:var(--accent-color,var(--blue));border:1px solid rgba(255,255,255,.08)}
.stat-info{flex:1;min-width:0}
.stat-label{font-size:.75rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-bottom:6px}
.stat-value{font-family:'Montserrat',sans-serif;font-size:1.8rem;font-weight:800;line-height:1;margin-bottom:6px}
.stat-meta{font-size:.75rem;color:var(--text-secondary)}
.stat-meta .up{color:var(--green)}
/* TABLES */
.table-wrapper{overflow-x:auto;border-radius:0 0 16px 16px}
table{width:100%;border-collapse:collapse}
thead th{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-secondary);padding:10px 16px;background:rgba(255,255,255,.02);border-bottom:1px solid var(--border);white-space:nowrap}
tbody td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.04);font-size:.875rem;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--bg-hover)}
/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.badge-blue{background:rgba(59,130,246,.15);color:#93c5fd}
.badge-purple{background:rgba(139,92,246,.15);color:#c4b5fd}
.badge-green{background:rgba(16,185,129,.15);color:#6ee7b7}
.badge-red{background:rgba(239,68,68,.15);color:#fca5a5}
.badge-orange{background:rgba(249,115,22,.15);color:#fdba74}
.badge-yellow{background:rgba(234,179,8,.15);color:#fde047}
.badge-slate{background:rgba(100,116,139,.15);color:#94a3b8}
.badge-cyan{background:rgba(6,182,212,.15);color:#67e8f9}
.badge-pink{background:rgba(244,114,182,.15);color:#f9a8d4}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;font-size:.83rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'Inter',sans-serif;white-space:nowrap}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{filter:brightness(1.08)}
.btn-secondary{background:var(--bg-hover);color:var(--text-secondary);border:1px solid var(--border)}
.btn-secondary:hover{border-color:var(--border-hover);color:#fff}
.btn-danger{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-success{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
.btn-success:hover{background:var(--green);color:#fff}
.btn-sm{padding:5px 11px;font-size:.78rem}
.btn-icon-only{padding:7px;gap:0}
.w-full{width:100%;justify-content:center}
/* FORMS */
.form-group{margin-bottom:20px}
.form-label{display:block;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary);margin-bottom:6px}
.form-control{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:9px 13px;font-size:.875rem;font-family:'Inter',sans-serif;transition:border-color .2s,box-shadow .2s;outline:none;-webkit-appearance:none}
.form-control:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.form-control::placeholder{color:var(--text-muted)}
select.form-control{cursor:pointer}
.form-hint{font-size:.75rem;color:var(--text-secondary);margin-top:5px}
/* TOGGLE */
.toggle-wrap{display:flex;align-items:center;gap:12px}
.toggle{position:relative;width:42px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:var(--text-muted);border-radius:24px;cursor:pointer;transition:.3s}
.toggle-slider::before{content:'';position:absolute;left:3px;bottom:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.3s}
.toggle input:checked+.toggle-slider{background:var(--blue)}
.toggle input:checked+.toggle-slider::before{transform:translateX(18px)}
.toggle-label{font-size:.875rem;font-weight:500}
/* TABS */
.tabs{display:flex;gap:2px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:3px;margin-bottom:28px;width:fit-content}
.tab-btn{padding:7px 16px;border:none;background:none;color:var(--text-secondary);border-radius:8px;cursor:pointer;font-size:.85rem;font-weight:600;font-family:'Inter',sans-serif;transition:all .2s;display:flex;align-items:center;gap:7px}
.tab-btn:hover{color:var(--text-primary)}
.tab-btn.active{background:var(--bg-elevated);color:var(--text-primary);box-shadow:0 1px 3px rgba(0,0,0,.3)}
.tab-pane{display:none}
.tab-pane.active{display:block}
/* GRIDS */
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
/* PANEL ITEMS */
.panel-item{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:14px;transition:border-color .2s}
.panel-item:hover{border-color:var(--border-hover)}
.panel-item.active{border-color:rgba(59,130,246,.3)}
.panel-item .p-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:1.1rem;flex-shrink:0;background:rgba(255,255,255,.04)}
.panel-item .p-info{flex:1;min-width:0}
.panel-item .p-name{font-weight:700;font-size:.9rem}
.panel-item .p-faction{font-size:.72rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em}
/* LOGS */
.log-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.log-item:last-child{border-bottom:none}
.log-avatar{width:34px;height:34px;flex-shrink:0;border-radius:8px;overflow:hidden;background:var(--bg-elevated);display:grid;place-items:center;font-weight:700;font-size:13px;color:var(--blue)}
.log-avatar img{width:100%;height:100%;object-fit:cover}
.log-body{flex:1;min-width:0}
.log-meta{display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap}
.log-user{font-weight:700;font-size:.85rem}
.log-action{font-size:.8rem;color:var(--text-secondary)}
.log-time{font-size:.72rem;color:var(--text-muted);flex-shrink:0;white-space:nowrap;font-family:'DM Mono',monospace}
/* AVATARS */
.u-ava{width:36px;height:36px;border-radius:9px;overflow:hidden;background:var(--blue);display:grid;place-items:center;font-weight:700;font-size:13px;flex-shrink:0}
.u-ava img{width:100%;height:100%;object-fit:cover}
/* EMPTY STATE */
.empty-state{text-align:center;padding:48px 24px;color:var(--text-secondary)}
.empty-state i{font-size:2.5rem;margin-bottom:12px;opacity:.3;display:block}
.empty-state p{font-size:.9rem}
/* MODALS */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:none;place-items:center;padding:20px}
.modal-overlay.open{display:grid}
.modal{background:var(--bg-elevated);border:1px solid var(--border);border-radius:20px;width:100%;max-width:520px;padding:28px;animation:modalIn .2s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.modal-header h3{font-size:1.1rem}
.modal-close{width:30px;height:30px;border-radius:7px;background:var(--bg-hover);border:1px solid var(--border);color:var(--text-secondary);cursor:pointer;display:grid;place-items:center;transition:all .2s}
.modal-close:hover{background:rgba(239,68,68,.15);color:var(--red);border-color:rgba(239,68,68,.3)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:24px}
/* SEARCH */
.search-bar{position:relative;display:flex;align-items:center}
.search-bar i{position:absolute;left:12px;color:var(--text-secondary);font-size:13px;pointer-events:none}
.search-bar input{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:8px 12px 8px 34px;font-size:.875rem;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;min-width:220px}
.search-bar input:focus{border-color:var(--blue)}
.search-bar input::placeholder{color:var(--text-muted)}
/* ALERTS */
.alert{padding:12px 16px;border-radius:10px;border-left:3px solid;font-size:.875rem;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:rgba(16,185,129,.08);border-color:var(--green);color:#a7f3d0}
.alert-danger{background:rgba(239,68,68,.08);border-color:var(--red);color:#fecaca}
.alert-info{background:rgba(59,130,246,.08);border-color:var(--blue);color:#bfdbfe}
.alert-warning{background:rgba(234,179,8,.08);border-color:var(--yellow);color:#fef08a}
/* PROGRESS */
.progress{height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-top:6px}
.progress-fill{height:100%;border-radius:2px;transition:width .6s ease}
/* HIERARCHY */
.hierarchy-faction{margin-bottom:20px}
.faction-header{display:flex;align-items:center;gap:10px;padding:12px 16px;background:rgba(255,255,255,.03);border-radius:10px 10px 0 0;border:1px solid var(--border)}
.faction-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.faction-name{font-weight:700;font-family:'Montserrat',sans-serif}
.faction-count{font-size:.75rem;color:var(--text-secondary);margin-left:auto}
.grade-row{display:flex;align-items:center;gap:12px;padding:10px 16px 10px 36px;border:1px solid var(--border);border-top:none}
.grade-row:last-child{border-radius:0 0 10px 10px}
.grade-rank{width:22px;height:22px;border-radius:5px;background:rgba(255,255,255,.07);display:grid;place-items:center;font-size:.65rem;font-weight:700;flex-shrink:0}
.grade-name{font-weight:600;font-size:.85rem;flex:1}
.grade-members{display:flex;gap:6px;flex-wrap:wrap}
.grade-member{display:flex;align-items:center;gap:5px;font-size:.72rem;color:var(--text-secondary);background:rgba(255,255,255,.04);padding:2px 8px;border-radius:12px}
/* MAINTENANCE CARDS */
.maintenance-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;justify-content:space-between;gap:16px;transition:border-color .2s}
.maintenance-card.on{border-color:rgba(249,115,22,.3);background:rgba(249,115,22,.05)}
.mc-info{display:flex;align-items:center;gap:14px}
.mc-icon{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.05);display:grid;place-items:center;font-size:1.1rem}
.mc-name{font-weight:700;font-size:.9rem;margin-bottom:2px}
.mc-desc{font-size:.75rem;color:var(--text-secondary)}
/* MISC */
.divider{height:1px;background:var(--border);margin:20px 0}
.text-blue{color:var(--blue)}.text-green{color:var(--green)}.text-red{color:var(--red)}.text-muted{color:var(--text-secondary)}
.fw-bold{font-weight:700}.mt-4{margin-top:16px}.mt-6{margin-top:24px}
.flex{display:flex}.flex-center{display:flex;align-items:center}.gap-2{gap:8px}.gap-3{gap:12px}.ml-auto{margin-left:auto}
/* RESPONSIVE */
@media(max-width:1024px){.grid-4{grid-template-columns:repeat(2,1fr)}.grid-3{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
.sidebar{transform:translateX(-100%)}.sidebar.mobile-open{transform:translateX(0)}
.main-wrapper{margin-left:0!important}.topbar{left:0!important}
.grid-2,.grid-3,.grid-4{grid-template-columns:1fr}.page-header{flex-direction:column}
}
/* SCROLLBAR */
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}

/* ── THEME TOGGLE ICONS ─────────────────────────────────────── */
.theme-toggle .ti-moon{display:none}
[data-theme="light"] .theme-toggle .ti-sun{display:none}
[data-theme="light"] .theme-toggle .ti-moon{display:inline-block}

/* ── LIGHT THEME ─────────────────────────────────────────────── */
[data-theme="light"]{
    --bg-base:#F5F6FA;
    --bg-surface:#ffffff;
    --bg-elevated:#ffffff;
    --bg-card:#ffffff;
    --bg-hover:rgba(26,31,48,.05);
    --bg-active:rgba(30,111,229,.10);
    --border:#E4E7EF;
    --border-hover:#D5DAE6;
    --text-primary:#1A1F30;
    --text-secondary:#565E78;
    --text-muted:#98a0b4;
    color-scheme:light;
}
/* Sidebar / topbar */
[data-theme="light"] .sidebar{box-shadow:1px 0 0 rgba(15,23,42,.05)}
[data-theme="light"] .sidebar-brand .brand-text{color:#0f172a}
[data-theme="light"] .topbar{background:rgba(255,255,255,.85)}
[data-theme="light"] .topbar-toggle:hover,
[data-theme="light"] .topbar-btn:hover{color:#0f172a}
[data-theme="light"] .topbar-btn .notif-dot{border-color:#fff}
[data-theme="light"] .nav-item.active{background:rgba(30,111,229,.10);color:#1d4ed8}
[data-theme="light"] .nav-sub-item.active{color:#1d4ed8}
/* Cards / tables */
[data-theme="light"] .card,
[data-theme="light"] .stat-card,
[data-theme="light"] .panel-item,
[data-theme="light"] .maintenance-card{box-shadow:0 1px 3px rgba(15,23,42,.05),0 1px 2px rgba(15,23,42,.03)}
[data-theme="light"] thead th{background:rgba(15,23,42,.035)}
[data-theme="light"] tbody td{border-bottom-color:rgba(15,23,42,.06)}
[data-theme="light"] tbody tr:hover{background:rgba(15,23,42,.03)}
/* Surfaces */
[data-theme="light"] .stat-icon,
[data-theme="light"] .panel-item .p-icon,
[data-theme="light"] .grade-rank,
[data-theme="light"] .mc-icon,
[data-theme="light"] .log-avatar{background:rgba(15,23,42,.05);border-color:rgba(15,23,42,.08)}
[data-theme="light"] .stat-card::after{opacity:.7}
[data-theme="light"] .faction-header,
[data-theme="light"] .grade-row{background:#fff}
[data-theme="light"] .grade-member{background:rgba(15,23,42,.05);color:#475569}
/* Forms / inputs */
[data-theme="light"] .form-control,
[data-theme="light"] .search-bar input{background:#fff;color:#0f172a}
[data-theme="light"] .form-control:focus{box-shadow:0 0 0 3px rgba(59,130,246,.15)}
/* Buttons */
[data-theme="light"] .btn-secondary{background:#fff;border-color:rgba(15,23,42,.10);color:#475569}
[data-theme="light"] .btn-secondary:hover{color:#0f172a;background:rgba(15,23,42,.04)}
[data-theme="light"] .btn-danger{color:#b91c1c}
[data-theme="light"] .btn-success{color:#047857}
/* Tabs */
[data-theme="light"] .tabs{background:rgba(15,23,42,.04);border-color:rgba(15,23,42,.06)}
[data-theme="light"] .tab-btn.active{background:#fff;color:#0f172a;box-shadow:0 1px 2px rgba(15,23,42,.08)}
/* Badges (couleurs plus foncées sur fond clair) */
[data-theme="light"] .badge-blue{background:rgba(59,130,246,.12);color:#1d4ed8}
[data-theme="light"] .badge-purple{background:rgba(139,92,246,.12);color:#6d28d9}
[data-theme="light"] .badge-green{background:rgba(16,185,129,.12);color:#047857}
[data-theme="light"] .badge-red{background:rgba(239,68,68,.12);color:#b91c1c}
[data-theme="light"] .badge-orange{background:rgba(249,115,22,.12);color:#c2410c}
[data-theme="light"] .badge-yellow{background:rgba(234,179,8,.18);color:#854d0e}
[data-theme="light"] .badge-slate{background:rgba(100,116,139,.14);color:#334155}
[data-theme="light"] .badge-cyan{background:rgba(6,182,212,.12);color:#0e7490}
[data-theme="light"] .badge-pink{background:rgba(244,114,182,.12);color:#be185d}
/* Alerts (texte plus contrasté) */
[data-theme="light"] .alert-success{background:rgba(16,185,129,.10);color:#065f46}
[data-theme="light"] .alert-danger{background:rgba(239,68,68,.10);color:#991b1b}
[data-theme="light"] .alert-info{background:rgba(59,130,246,.10);color:#1e40af}
[data-theme="light"] .alert-warning{background:rgba(234,179,8,.12);color:#854d0e}
/* Modals / scrim */
[data-theme="light"] .modal-overlay{background:rgba(15,23,42,.45)}
[data-theme="light"] .modal{box-shadow:0 25px 50px -12px rgba(15,23,42,.25)}
[data-theme="light"] .modal-close{background:rgba(15,23,42,.04)}
/* Sidebar user / brand icons */
[data-theme="light"] .sidebar-user .u-name{color:#0f172a}
[data-theme="light"] .progress{background:rgba(15,23,42,.08)}
[data-theme="light"] .toggle-slider{background:#cbd5e1}
/* Toast (override fond + texte) */
[data-theme="light"] #toastContainer > div{background:#fff!important;color:#0f172a;box-shadow:0 10px 25px rgba(15,23,42,.10)!important}
/* Scrollbar */
[data-theme="light"] ::-webkit-scrollbar-thumb{background:rgba(15,23,42,.18)}
    </style>
    <?php if (isset($extraCss) && $extraCss !== ''): ?><style><?php echo $extraCss; ?></style><?php endif; ?>
</head>
<body>
<div class="staff-layout">

<aside class="sidebar" id="sidebar">

    <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-microchip"></i></div>
        <div class="brand-text"><?php echo htmlspecialchars($siteName); ?></div>
    </a>

    <nav class="sidebar-nav">

        <!-- VUE D'ENSEMBLE -->
        <div class="nav-section">
            <div class="nav-section-label">Vue d'ensemble</div>
            <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php"
               class="nav-item <?php echo isActiveStaff('dashboard', 'staff'); ?>" title="Dashboard">
                <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span>
                <span class="nav-label">Dashboard</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/logs.php"
               class="nav-item <?php echo isActiveStaff('logs', 'staff'); ?>" title="Logs">
                <span class="nav-icon"><i class="fa-solid fa-scroll"></i></span>
                <span class="nav-label">Logs</span>
            </a>
        </div>

        <!-- UTILISATEURS -->
        <div class="nav-section">
            <div class="nav-section-label">Utilisateurs</div>
            <button class="nav-item <?php echo $currentDir === 'users' ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-users')" title="Utilisateurs">
                <span class="nav-icon"><i class="fa-solid fa-users"></i></span>
                <span class="nav-label">Utilisateurs</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $currentDir === 'users' ? 'open' : ''; ?>" id="sub-users">
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/index.php"
                   class="nav-sub-item <?php echo isActiveStaff('index', 'users'); ?>">Gestion</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/roles.php"
                   class="nav-sub-item <?php echo isActiveStaff('roles', 'users'); ?>">Rôles & Factions</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/mdt-roles.php"
                   class="nav-sub-item <?php echo isActiveStaff('mdt-roles', 'users'); ?>"><i class="fa-brands fa-discord" style="margin-right:4px;color:#7289da"></i>Rôles & Discord</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/bans.php"
                   class="nav-sub-item <?php echo isActiveStaff('bans', 'users'); ?>">Bannissements</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/sanctions.php"
                   class="nav-sub-item <?php echo isActiveStaff('sanctions', 'users'); ?>">Sanctions</a>
                <?php if (function_exists('hasRole') && hasRole(['*', 'Administrateur', 'Développeur'])): ?>
                <a href="<?php echo SITE_URL; ?>/pages/staff/users/div_grad.php"
                   class="nav-sub-item <?php echo isActiveStaff('div_grad', 'users'); ?>">Division & Grade</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- CONTENU -->
        <?php
        // États d'activité / ouverture des sous-menus du bloc Contenu.
        // NB : sams/general.php vit dans content/sams/ donc $currentDir vaut 'sams'.
        $inContent         = ($currentDir === 'content');
        $samsGeneralActive = ($currentDir === 'sams' && $currentPage === 'general');
        $dojGeneralActive  = ($currentDir === 'doj' && $currentPage === 'general');
        $lsfdGeneralActive = ($currentDir === 'lsfd' && $currentPage === 'general');
        $lspdGeneralActive = ($currentDir === 'lspd' && $currentPage === 'general');
        $lssdGeneralActive = ($currentDir === 'lssd' && $currentPage === 'general');
        $grpPanelsOpen     = $inContent && in_array($currentPage, ['panels','mdt','panel-builder','division'], true);
        $grpLspdOpen       = $lspdGeneralActive || ($inContent && in_array($currentPage, ['report-tags','dispatch-custom','dispatch-config','dispatch-theme'], true));
        $grpLssdOpen       = ($currentDir === 'lssd');
        $grpSamsOpen       = $samsGeneralActive || ($inContent && in_array($currentPage, ['report-tags-sams','soins-sams'], true));
        $grpDojOpen        = $dojGeneralActive || ($inContent && in_array($currentPage, ['report-tags-doj'], true));
        $grpLsfdOpen       = $lsfdGeneralActive || ($inContent && in_array($currentPage, ['report-tags-lsfd'], true));
        $grpIllegalOpen    = $inContent && $currentPage === 'config_illegale';
        ?>
        <div class="nav-section">
            <div class="nav-section-label">Contenu</div>

            <!-- Groupe : Panels -->
            <button class="nav-item <?php echo $grpPanelsOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-panels')" title="Panels">
                <span class="nav-icon"><i class="fa-solid fa-layer-group"></i></span>
                <span class="nav-label">Panels</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpPanelsOpen ? 'open' : ''; ?>" id="sub-panels">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/panels.php"
                   class="nav-sub-item <?php echo isActiveStaff('panels', 'content'); ?>">Gestion des Panels</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/mdt.php"
                   class="nav-sub-item <?php echo isActiveStaff('mdt', 'content'); ?>">MDT Actif</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/panel-builder.php"
                   class="nav-sub-item <?php echo isActiveStaff('panel-builder', 'content'); ?>">Panel Builder</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/custom_stock.php"
                   class="nav-sub-item <?php echo isActiveStaff('custom_stock', 'content'); ?>">
                    <i class="fa-solid fa-boxes-stacking" style="font-size:.65rem;color:#3b82f6;margin-right:2px;"></i> Catalogues & Stock</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/division.php"
                   class="nav-sub-item <?php echo isActiveStaff('division', 'content'); ?>">Division</a>
            </div>

            <!-- Groupe : LSPD -->
            <button class="nav-item <?php echo $grpLspdOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-lspd')" title="LSPD">
                <span class="nav-icon"><i class="fa-solid fa-shield-halved"></i></span>
                <span class="nav-label">LSPD</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpLspdOpen ? 'open' : ''; ?>" id="sub-lspd">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/lspd/general.php"
                   class="nav-sub-item <?php echo $lspdGeneralActive ? 'active' : ''; ?>">
                    <i class="fa-solid fa-palette" style="font-size:.65rem;color:#60a5fa;margin-right:2px;"></i> Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/report-tags.php"
                   class="nav-sub-item <?php echo isActiveStaff('report-tags', 'content'); ?>">
                    <i class="fa-solid fa-tags" style="font-size:.65rem;opacity:.6;margin-right:2px;"></i> Tag LSPD</a>
                <?php if ($dispatchActive): ?>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/dispatch-custom.php"
                   class="nav-sub-item <?php echo isActiveStaff(['dispatch-custom','dispatch-config','dispatch-theme'], 'content'); ?>">
                    <i class="fa-solid fa-radio" style="font-size:.65rem;color:#06b6d4;margin-right:2px;"></i>
                    Custom Dispatch <?php
                        static $ldcn=null; if($ldcn===null){ try{ $r=$db->prepare("SELECT custom_name FROM admin_panel_config WHERE panel_key='LSPD' LIMIT 1"); $r->execute(); $rw=$r->fetch(); $ldcn=($rw&&$rw['custom_name'])?htmlspecialchars($rw['custom_name']):'SASP'; }catch(Exception $e){$ldcn='SASP';} } echo $ldcn; ?></a>
                <?php endif; ?>
            </div>

            <!-- Groupe : LSSD -->
            <button class="nav-item <?php echo $grpLssdOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-lssd')" title="LSSD">
                <span class="nav-icon"><i class="fa-solid fa-star"></i></span>
                <span class="nav-label">LSSD</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpLssdOpen ? 'open' : ''; ?>" id="sub-lssd">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/lssd/general.php"
                   class="nav-sub-item <?php echo $lssdGeneralActive ? 'active' : ''; ?>">
                    <i class="fa-solid fa-palette" style="font-size:.65rem;color:#34d399;margin-right:2px;"></i> Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/lssd/report-tags.php"
                   class="nav-sub-item <?php echo ($currentDir === 'lssd' && $currentPage === 'report-tags') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-tags" style="font-size:.65rem;color:#34d399;margin-right:2px;"></i> Tag LSSD</a>
                <?php if ($dispatchActive): ?>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/lssd/dispatch-custom.php"
                   class="nav-sub-item <?php echo ($currentDir === 'lssd' && in_array($currentPage, ['dispatch-custom','dispatch-config','dispatch-theme'], true)) ? 'active' : ''; ?>">
                    <i class="fa-solid fa-radio" style="font-size:.65rem;color:#10b981;margin-right:2px;"></i>
                    Custom Dispatch <?php
                        static $lsdcn=null; if($lsdcn===null){ try{ $r=$db->prepare("SELECT custom_name FROM admin_panel_config WHERE panel_key='LSSD' LIMIT 1"); $r->execute(); $rw=$r->fetch(); $lsdcn=($rw&&$rw['custom_name'])?htmlspecialchars($rw['custom_name']):'LSSD'; }catch(Exception $e){$lsdcn='LSSD';} } echo $lsdcn; ?></a>
                <?php endif; ?>
            </div>

            <!-- Groupe : SAMS -->
            <button class="nav-item <?php echo $grpSamsOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-sams')" title="SAMS">
                <span class="nav-icon"><i class="fa-solid fa-heart-pulse"></i></span>
                <span class="nav-label">SAMS</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpSamsOpen ? 'open' : ''; ?>" id="sub-sams">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/sams/general.php"
                   class="nav-sub-item <?php echo $samsGeneralActive ? 'active' : ''; ?>">
                    <i class="fa-solid fa-palette" style="font-size:.65rem;color:#fb7185;margin-right:2px;"></i> Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/report-tags-sams.php"
                   class="nav-sub-item <?php echo isActiveStaff('report-tags-sams', 'content'); ?>">
                    <i class="fa-solid fa-tags" style="font-size:.65rem;color:#fb7185;margin-right:2px;"></i> Tag SAMS</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/soins-sams.php"
                   class="nav-sub-item <?php echo isActiveStaff('soins-sams', 'content'); ?>">
                    <i class="fa-solid fa-kit-medical" style="font-size:.65rem;color:#fb7185;margin-right:2px;"></i> Soins SAMS</a>
            </div>

            <!-- Groupe : DOJ -->
            <button class="nav-item <?php echo $grpDojOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-doj')" title="DOJ">
                <span class="nav-icon"><i class="fa-solid fa-scale-balanced"></i></span>
                <span class="nav-label">DOJ</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpDojOpen ? 'open' : ''; ?>" id="sub-doj">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/doj/general.php"
                   class="nav-sub-item <?php echo $dojGeneralActive ? 'active' : ''; ?>">
                    <i class="fa-solid fa-palette" style="font-size:.65rem;color:#60a5fa;margin-right:2px;"></i> Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/report-tags-doj.php"
                   class="nav-sub-item <?php echo isActiveStaff('report-tags-doj', 'content'); ?>">
                    <i class="fa-solid fa-tags" style="font-size:.65rem;color:#60a5fa;margin-right:2px;"></i> Tag DOJ</a>
            </div>

            <!-- Groupe : LSFD -->
            <button class="nav-item <?php echo $grpLsfdOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-lsfd')" title="LSFD">
                <span class="nav-icon"><i class="fa-solid fa-fire-flame-curved"></i></span>
                <span class="nav-label">LSFD</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpLsfdOpen ? 'open' : ''; ?>" id="sub-lsfd">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/lsfd/general.php"
                   class="nav-sub-item <?php echo $lsfdGeneralActive ? 'active' : ''; ?>">
                    <i class="fa-solid fa-palette" style="font-size:.65rem;color:#f87171;margin-right:2px;"></i> Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/report-tags-lsfd.php"
                   class="nav-sub-item <?php echo isActiveStaff('report-tags-lsfd', 'content'); ?>">
                    <i class="fa-solid fa-tags" style="font-size:.65rem;color:#f87171;margin-right:2px;"></i> Tag LSFD</a>
            </div>

            <!-- Groupe : Illégale -->
            <button class="nav-item <?php echo $grpIllegalOpen ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-illegal')" title="Illégale">
                <span class="nav-icon"><i class="fa-solid fa-user-secret"></i></span>
                <span class="nav-label">Illégale</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo $grpIllegalOpen ? 'open' : ''; ?>" id="sub-illegal">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/config_illegale.php"
                   class="nav-sub-item <?php echo isActiveStaff('config_illegale', 'content'); ?>">
                    <i class="fa-solid fa-user-secret" style="font-size:.65rem;color:#a78bfa;margin-right:2px;"></i> Accès DarkNet</a>
            </div>
        </div>

        <!-- SERVICES -->
        <div class="nav-section">
            <div class="nav-section-label">Services</div>
            <button class="nav-item <?php echo ($currentDir === 'content' && $currentPage === 'mail-manager') ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-services')" title="Service Mail">
                <span class="nav-icon"><i class="fa-solid fa-envelope-open-text"></i></span>
                <span class="nav-label">Service Mail</span>
                <?php if ($mailEnabled && $mailUnreadTotal > 0): ?>
                    <span class="nav-badge"><?php echo $mailUnreadTotal; ?></span>
                <?php endif; ?>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo ($currentDir === 'content' && $currentPage === 'mail-manager') ? 'open' : ''; ?>" id="sub-services">
                <a href="<?php echo SITE_URL; ?>/pages/staff/content/mail-manager.php"
                   class="nav-sub-item <?php echo isActiveStaff('mail-manager', 'content'); ?>">Gestionnaire Mail</a>
                <a href="<?php echo SITE_URL; ?>/pages/mail/index.php" target="_blank"
                   class="nav-sub-item">Ouvrir le mail <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.55rem;opacity:.5;margin-left:3px;"></i></a>
            </div>
        </div>

        <!-- EXTENSIONS -->
        <div class="nav-section">
            <div class="nav-section-label">Extensions</div>
            <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/addons.php"
               class="nav-item <?php echo isActiveStaff('addons', 'extensions'); ?>" title="Addons">
                <span class="nav-icon"><i class="fa-solid fa-puzzle-piece"></i></span>
                <span class="nav-label">Addons</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/map.php"
               class="nav-item <?php echo isActiveStaff('map', 'extensions'); ?>" title="Système de Cartes">
                <span class="nav-icon"><i class="fa-solid fa-map-location-dot"></i></span>
                <span class="nav-label">Système de Cartes</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/plugin-dispatch.php"
               class="nav-item <?php echo isActiveStaff('plugin-dispatch', 'extensions'); ?>" title="Dispatch">
                <span class="nav-icon"><i class="fa-solid fa-radio"></i></span>
                <span class="nav-label">Dispatch</span>
                <?php if ($dispatchActive): ?><span class="nav-badge">ON</span><?php endif; ?>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/plugin-life-invader.php"
               class="nav-item <?php echo isActiveStaff('plugin-life-invader', 'extensions'); ?>" title="Life Invader">
                <span class="nav-icon"><i class="fa-solid fa-clapperboard"></i></span>
                <span class="nav-label">Life Invader</span>
                <?php if ($lifeInvaderActive): ?><span class="nav-badge">ON</span><?php endif; ?>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/content/hack-manager.php"
               class="nav-item <?php echo (($currentDir === 'content' && $currentPage === 'hack-manager') ? 'active' : ''); ?>" title="Hack MDT">
                <span class="nav-icon"><i class="fa-solid fa-skull-crossbones"></i></span>
                <span class="nav-label">Hack MDT</span>
                <?php if ($hackMdtActive): ?><span class="nav-badge">ON</span><?php endif; ?>
            </a>
        </div>

        <!-- ACADÉMIE -->
        <div class="nav-section">
            <div class="nav-section-label">Académie</div>
            <button class="nav-item <?php echo (basename(dirname($_SERVER['PHP_SELF'])) === 'academy') ? 'active' : ''; ?>"
                    onclick="toggleSubmenu('sub-academy')" title="Gestion Académie">
                <span class="nav-icon"><i class="fa-solid fa-graduation-cap"></i></span>
                <span class="nav-label">Gestion Académie</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo (basename(dirname($_SERVER['PHP_SELF'])) === 'academy') ? 'open' : ''; ?>" id="sub-academy">
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/accueil.php"
                   class="nav-sub-item <?php echo $currentPage === 'accueil' ? 'active' : ''; ?>">Pages d'accueil</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/grades.php"
                   class="nav-sub-item <?php echo $currentPage === 'grades' ? 'active' : ''; ?>">Grades</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/access.php"
                   class="nav-sub-item <?php echo $currentPage === 'access' ? 'active' : ''; ?>">Accès</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/qcm.php"
                   class="nav-sub-item <?php echo $currentPage === 'qcm' ? 'active' : ''; ?>">QCM</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/formations.php"
                   class="nav-sub-item <?php echo $currentPage === 'formations' ? 'active' : ''; ?>">Formations</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/matricules.php"
                   class="nav-sub-item <?php echo $currentPage === 'matricules' ? 'active' : ''; ?>">Matricules</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/extensions/academy/forum.php"
                   class="nav-sub-item <?php echo $currentPage === 'forum' ? 'active' : ''; ?>">Forum</a>
            </div>
        </div>

        <!-- CONFIGURATION -->
        <div class="nav-section">
            <div class="nav-section-label">Configuration</div>
            <button class="nav-item <?php echo isActiveStaff(['settings', 'config_site_mdt', 'site_customization'], 'staff'); ?>"
                    onclick="toggleSubmenu('sub-settings')" title="Paramètres">
                <span class="nav-icon"><i class="fa-solid fa-sliders"></i></span>
                <span class="nav-label">Paramètres</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </button>
            <div class="nav-submenu <?php echo isActiveStaff(['settings', 'config_site_mdt', 'site_customization'], 'staff') ? 'open' : ''; ?>" id="sub-settings">
                <a href="<?php echo SITE_URL; ?>/pages/staff/settings.php?tab=general"
                   class="nav-sub-item">Général</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/settings.php?tab=maintenance"
                   class="nav-sub-item">Maintenance</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/settings.php?tab=updates"
                   class="nav-sub-item">Mises à jour</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/config_site_mdt.php"
                   class="nav-sub-item <?php echo isActiveStaff('config_site_mdt', 'staff'); ?>">Config site MDT</a>
                <a href="<?php echo SITE_URL; ?>/pages/staff/site_customization.php"
                   class="nav-sub-item <?php echo isActiveStaff('site_customization', 'staff'); ?>">Personnalisation</a>
            </div>

            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-item" title="Retour MDT">
                <span class="nav-icon"><i class="fa-solid fa-arrow-left"></i></span>
                <span class="nav-label">Retour MDT</span>
            </a>
        </div>

    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="u-avatar">
                <?php if (!empty($_SESSION['user_avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <?php echo htmlspecialchars(mdt_session_display_initial()); ?>
                <?php endif; ?>
            </div>
            <div class="u-info">
                <div class="u-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
                <div class="u-role"><?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?></div>
            </div>
            <a href="<?php echo SITE_URL; ?>/logout.php"
               class="btn-danger sidebar-logout"
               title="Déconnexion">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </div>
</aside>

<div class="main-wrapper" id="mainWrapper">
    <header class="topbar">
        <div class="topbar-left">
            <button class="topbar-toggle" id="sidebarToggle">
                <i class="fa-solid fa-bars" style="font-size:14px;"></i>
            </button>
            <nav class="breadcrumb">
                <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php">
                    <i class="fa-solid fa-house" style="font-size:12px;"></i>
                </a>
                <?php if (!empty($breadcrumb)): foreach ($breadcrumb as $b): ?>
                    <span class="sep">/</span>
                    <?php if (isset($b['url'])): ?>
                        <a href="<?php echo $b['url']; ?>"><?php echo htmlspecialchars($b['label']); ?></a>
                    <?php else: ?>
                        <span class="current"><?php echo htmlspecialchars($b['label']); ?></span>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
            </nav>
        </div>
        <div class="topbar-right">
            <button type="button" id="themeToggle" class="topbar-btn theme-toggle" title="Basculer thème clair / sombre" aria-label="Basculer le thème">
                <i class="fa-solid fa-sun ti-sun" style="font-size:13px;"></i>
                <i class="fa-solid fa-moon ti-moon" style="font-size:13px;"></i>
            </button>
            <a href="<?php echo SITE_URL; ?>/pages/staff/logs.php" class="topbar-btn" title="Logs">
                <i class="fa-solid fa-bell" style="font-size:13px;"></i>
                <span class="notif-dot"></span>
            </a>
            <a href="<?php echo SITE_URL; ?>/pages/staff/settings.php" class="topbar-btn" title="Paramètres">
                <i class="fa-solid fa-gear" style="font-size:13px;"></i>
            </a>
        </div>
    </header>

    <main class="main-content">