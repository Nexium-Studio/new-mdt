<?php
// pages/staff/dashboard.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Multi-personnages : un personnage actif est requis
require_once ROOT_PATH . '/includes/characters.php';
mdt_require_character();

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Dashboard Staff';
$breadcrumb = [['label' => 'Dashboard']];

/* ── Couleurs factions ── */
$factionColors = [
    'LSPD'=>'#3b82f6','LSSD'=>'#10b981','SAMS'=>'#f43f5e','DOJ'=>'#ef4444',
    'USSS'=>'#64748b','GRUPP6'=>'#94a3b8','STAFF'=>'#a855f7','NONE'=>'#334155',
];
$factionBg = [
    'LSPD'=>'#1d4ed8','LSSD'=>'#065f46','SAMS'=>'#9f1239','DOJ'=>'#991b1b',
    'USSS'=>'#334155','STAFF'=>'#6d28d9','GRUPP6'=>'#475569','NONE'=>'#1e293b',
];

/* ── Requêtes ── */
try {
    $totalUsers  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $todayLogins = $db->query("SELECT COUNT(*) FROM logs WHERE action='Connexion' AND DATE(created_at)=CURDATE()")->fetchColumn();
    $weekLogins  = $db->query("SELECT COUNT(*) FROM logs WHERE action='Connexion' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    $totalLogs   = $db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
    $logsToday   = $db->query("SELECT COUNT(*) FROM logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $logsWeek    = $db->query("SELECT COUNT(*) FROM logs WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();

    $bannedUsers = 0;
    try { $bannedUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_banned=1")->fetchColumn(); } catch(Exception $e){}

    $activePanels = 0;
    try { $activePanels = $db->query("SELECT COUNT(*) FROM admin_panels WHERE is_active=1")->fetchColumn(); } catch(Exception $e){}

    $maintenancePanels = 0;
    try {
        $maintenancePanels = $db->query("SELECT COUNT(*) FROM admin_settings WHERE setting_key LIKE 'maintenance_%' AND setting_value='1'")->fetchColumn();
    } catch(Exception $e){}

    $customPanels = 0;
    try { $customPanels = $db->query("SELECT COUNT(*) FROM custom_panels WHERE is_active=1")->fetchColumn(); } catch(Exception $e){}

    $onlineNow = 0;
    try {
        $onlineNow = $db->query("SELECT COUNT(DISTINCT user_id) FROM logs WHERE action='Connexion' AND created_at >= DATE_SUB(NOW(),INTERVAL 10 MINUTE)")->fetchColumn();
    } catch(Exception $e){}

    /* Graphique 7j */
    $loginMap = $db->query("
        SELECT DATE(created_at) as d, COUNT(*) as cnt FROM logs
        WHERE action='Connexion' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 6 DAY)
        GROUP BY DATE(created_at)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $days_fr = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
    $chartData = [];
    for ($i=6;$i>=0;$i--) {
        $day = date('Y-m-d',strtotime("-{$i} days"));
        $chartData[] = ['lbl'=>$days_fr[date('D',strtotime($day))]??date('D',strtotime($day)),'cnt'=>(int)($loginMap[$day]??0),'today'=>$i===0];
    }

    /* Logs récents */
    $recentLogs = $db->query("
        SELECT l.*, u.username, u.avatar, u.faction
        FROM logs l LEFT JOIN users u ON l.user_id=u.id
        ORDER BY l.created_at DESC LIMIT 14")->fetchAll();

    /* Nouveaux membres */
    $newUsers = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 7")->fetchAll();

    /* Stats factions */
    $factionStats = $db->query("
        SELECT faction, COUNT(*) as cnt FROM users
        WHERE faction IS NOT NULL AND faction!='NONE'
        GROUP BY faction ORDER BY cnt DESC LIMIT 8")->fetchAll();

    /* Activité par panel 7j */
    $panelActivity = $db->query("
        SELECT panel, COUNT(*) as cnt FROM logs
        WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) AND panel IS NOT NULL AND panel!=''
        GROUP BY panel ORDER BY cnt DESC LIMIT 7")->fetchAll();

    /* Top users 7j */
    $topUsers = $db->query("
        SELECT u.username, u.avatar, u.faction, COUNT(l.id) as actions
        FROM logs l JOIN users u ON l.user_id=u.id
        WHERE l.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
        GROUP BY l.user_id ORDER BY actions DESC LIMIT 5")->fetchAll();

    /* Dernières sanctions */
    $lastSanctions = [];
    try {
        $lastSanctions = $db->query("
            SELECT s.*, u.username as target_name, a.username as author_name
            FROM sanctions s
            LEFT JOIN users u ON s.user_id=u.id
            LEFT JOIN users a ON s.author_id=a.id
            ORDER BY s.created_at DESC LIMIT 5")->fetchAll();
    } catch(Exception $e){}

    /* Nouveaux bannis récents */
    $recentBans = [];
    try {
        $recentBans = $db->query("
            SELECT u.username, u.ban_reason, u.banned_at, b.username as by_name
            FROM users u LEFT JOIN users b ON u.banned_by=b.id
            WHERE u.is_banned=1 ORDER BY u.banned_at DESC LIMIT 4")->fetchAll();
    } catch(Exception $e){}

} catch (PDOException $e) {
    $totalUsers=$activeUsers=$todayLogins=$totalLogs=$bannedUsers=$weekLogins=0;
    $activePanels=$maintenancePanels=$customPanels=$logsToday=$logsWeek=$onlineNow=0;
    $recentLogs=$newUsers=$factionStats=$panelActivity=$topUsers=$chartData=[];
    $lastSanctions=$recentBans=[];
}

$maxChart   = max(array_column($chartData,   'cnt')?:[1]);
$maxFaction = max(array_column($factionStats,'cnt')?:[1]);
$maxPanel   = max(array_column($panelActivity,'cnt')?:[1]);

function ago(string $ts):string {
    $d=time()-strtotime($ts);
    if($d<60)   return "à l'inst.";
    if($d<3600) return round($d/60).'m';
    if($d<86400)return round($d/3600).'h';
    return date('d/m',strtotime($ts));
}

include __DIR__ . '/includes/header.php';
?>
<style>
/* ════════ DASHBOARD STAFF ════════ */
.db-wrap { display:flex; flex-direction:column; gap:20px; }

/* Header */
.db-top { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; }
.db-top h1 { font-size:1.55rem; font-weight:900; letter-spacing:-.5px; margin-bottom:5px; }
.db-top h1 i { color:var(--blue); margin-right:10px; }
.db-sub { font-size:.78rem; color:var(--text-secondary); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.live-chip { display:inline-flex; align-items:center; gap:5px; background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.25); border-radius:20px; padding:2px 9px; font-size:.68rem; font-weight:700; color:#22c55e; }
.ld { width:6px; height:6px; background:#22c55e; border-radius:50%; animation:ldp 2s infinite; }
@keyframes ldp{0%,100%{opacity:1}50%{opacity:.4}}
.db-btns { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start; }

/* KPI row */
.kpi-row { display:grid; grid-template-columns:repeat(8,1fr); gap:11px; }
@media(max-width:1400px){.kpi-row{grid-template-columns:repeat(4,1fr);}}
@media(max-width:700px) {.kpi-row{grid-template-columns:repeat(2,1fr);}}

.kpi { background:var(--card-bg,rgba(15,23,41,.6)); border:1px solid var(--border); border-radius:13px; padding:.9rem 1.1rem; position:relative; overflow:hidden; transition:border-color .2s,transform .2s; }
.kpi:hover { border-color:var(--kc,var(--blue)); transform:translateY(-2px); }
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:var(--kc,var(--blue)); }
.kpi::after  { content:''; position:absolute; top:-30px; right:-15px; width:80px; height:80px; border-radius:50%; background:var(--kc,var(--blue)); opacity:.05; }
.kpi-h { display:flex; justify-content:space-between; align-items:center; margin-bottom:7px; }
.kpi-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--text-secondary); }
.kpi-ico { width:27px; height:27px; border-radius:7px; background:color-mix(in srgb,var(--kc,var(--blue)) 14%,transparent); color:var(--kc,var(--blue)); display:flex; align-items:center; justify-content:center; font-size:.78rem; }
.kpi-val { font-size:1.7rem; font-weight:900; color:var(--text-primary); line-height:1; margin-bottom:5px; font-variant-numeric:tabular-nums; }
.kpi-meta { font-size:.67rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
.kpi-meta .up   { color:#22c55e; font-weight:700; }
.kpi-meta .warn { color:#f59e0b; font-weight:700; }
.kpi-meta .dn   { color:#ef4444; font-weight:700; }
.dot { display:inline-block; width:7px; height:7px; border-radius:50%; }
.dot-g { background:#22c55e; animation:ldp 2s infinite; }
.dot-y { background:#f59e0b; }
.dot-r { background:#ef4444; }

/* Grilles */
.g2   { display:grid; grid-template-columns:1fr 320px; gap:18px; }
.g3   { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
.g2b  { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.gcol { display:flex; flex-direction:column; gap:16px; }
@media(max-width:1100px){ .g2,.g3,.g2b{grid-template-columns:1fr;} }

/* Logs */
.log-row { display:grid; grid-template-columns:34px 1fr auto; gap:9px; align-items:center; padding:7px 4px; border-bottom:1px solid var(--border); transition:background .15s; }
.log-row:last-child { border-bottom:none; }
.log-row:hover { background:rgba(255,255,255,.025); border-radius:8px; margin:0 -4px; padding:7px 8px; }
.log-av { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.log-av img { width:100%; height:100%; object-fit:cover; }
.log-body { min-width:0; }
.log-top { display:flex; align-items:center; gap:6px; margin-bottom:2px; }
.log-who { font-size:.77rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px; }
.log-tag { font-size:.56rem; padding:1px 6px; border-radius:20px; background:rgba(99,102,241,.12); color:#a5b4fc; border:1px solid rgba(99,102,241,.2); font-weight:700; letter-spacing:.4px; text-transform:uppercase; flex-shrink:0; }
.log-act { font-size:.74rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.log-desc{ font-size:.66rem; color:var(--text-secondary); opacity:.6; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.log-ts  { font-size:.62rem; color:var(--text-secondary); white-space:nowrap; text-align:right; flex-shrink:0; }

/* Membres */
.m-row { display:flex; align-items:center; gap:9px; padding:6px 4px; border-radius:9px; transition:background .15s; }
.m-row:hover { background:rgba(255,255,255,.03); }
.m-av { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.m-av img { width:100%; height:100%; object-fit:cover; }
.m-info { flex:1; min-width:0; }
.m-name { font-size:.79rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.m-role { font-size:.65rem; color:var(--text-secondary); }
.m-badge{ font-size:.58rem; font-weight:700; padding:2px 7px; border-radius:20px; flex-shrink:0; }

/* Factions */
.f-row { margin-bottom:9px; }
.f-head { display:flex; justify-content:space-between; margin-bottom:4px; }
.f-name { font-size:.76rem; font-weight:700; display:flex; align-items:center; gap:6px; }
.f-dot  { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.f-cnt  { font-size:.68rem; color:var(--text-secondary); }
.prog   { height:4px; background:rgba(255,255,255,.07); border-radius:99px; overflow:hidden; }
.prog-f { height:100%; border-radius:99px; transition:width 1.1s cubic-bezier(.16,1,.3,1); width:0; }

/* Top users */
.top-row { display:flex; align-items:center; gap:8px; padding:6px 4px; border-radius:9px; transition:background .15s; }
.top-row:hover { background:rgba(255,255,255,.03); }
.top-rank { font-size:.68rem; font-weight:900; width:17px; text-align:center; flex-shrink:0; color:var(--text-secondary); }
.top-rank.gold{color:#f59e0b;} .top-rank.silver{color:#94a3b8;} .top-rank.bronze{color:#b45309;}
.t-av { width:29px; height:29px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.t-av img { width:100%; height:100%; object-fit:cover; }
.t-info { flex:1; min-width:0; }
.t-name { font-size:.77rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.t-sub  { font-size:.63rem; color:var(--text-secondary); }
.t-cnt  { font-size:.7rem; font-weight:700; color:var(--blue); white-space:nowrap; }

/* Panel activity */
.pa-row { display:flex; align-items:center; gap:9px; margin-bottom:8px; }
.pa-name{ font-size:.75rem; font-weight:700; width:75px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pa-bar { flex:1; height:5px; background:rgba(255,255,255,.06); border-radius:99px; overflow:hidden; }
.pa-fill{ height:100%; border-radius:99px; background:var(--blue); transition:width 1.1s cubic-bezier(.16,1,.3,1); width:0; }
.pa-cnt { font-size:.68rem; color:var(--text-secondary); width:25px; text-align:right; }

/* Système */
.sys-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid var(--border); font-size:.77rem; }
.sys-row:last-child { border-bottom:none; }
.sys-lbl { color:var(--text-secondary); display:flex; align-items:center; gap:8px; }
.sys-lbl i { width:13px; text-align:center; }
.sys-val { font-weight:700; display:flex; align-items:center; gap:5px; }

/* Actions rapides */
.qa-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:7px; }
.qa-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:5px; padding:11px 6px; border-radius:10px; background:rgba(255,255,255,.03); border:1px solid var(--border); color:var(--text-secondary); text-decoration:none; font-size:.68rem; font-weight:700; transition:all .2s; text-align:center; }
.qa-btn i { font-size:.95rem; color:var(--qc,var(--blue)); }
.qa-btn:hover { background:color-mix(in srgb,var(--qc,var(--blue)) 9%,transparent); border-color:var(--qc,var(--blue)); color:var(--text-primary); transform:translateY(-2px); }

/* Sanctions */
.sanc-row { display:flex; align-items:center; gap:9px; padding:7px 4px; border-bottom:1px solid var(--border); }
.sanc-row:last-child { border-bottom:none; }
.sanc-ico { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.8rem; flex-shrink:0; }
.sanc-info { flex:1; min-width:0; }
.sanc-name { font-size:.78rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sanc-detail { font-size:.66rem; color:var(--text-secondary); }
.sanc-ts { font-size:.62rem; color:var(--text-secondary); white-space:nowrap; }

/* Graphique */
.chart { display:flex; align-items:flex-end; gap:7px; height:85px; }
.chart-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end; }
.chart-val { font-size:.6rem; color:var(--text-secondary); font-weight:700; }
.chart-bar { width:100%; border-radius:4px 4px 0 0; min-height:3px; }
.chart-lbl { font-size:.6rem; font-weight:600; }

/* Bannis */
.ban-row { display:flex; align-items:center; gap:9px; padding:7px 4px; border-bottom:1px solid var(--border); }
.ban-row:last-child{border-bottom:none;}
.ban-av { width:30px; height:30px; border-radius:8px; background:#991b1b; display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; color:#fff; flex-shrink:0; }
.ban-info { flex:1; min-width:0; }
.ban-name { font-size:.77rem; font-weight:700; }
.ban-reason { font-size:.65rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ban-ts { font-size:.62rem; color:var(--text-secondary); white-space:nowrap; }

/* Alerte maintenance */
.maint-alert { display:flex; align-items:center; gap:12px; background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.25); border-radius:12px; padding:12px 16px; }
.maint-alert i { color:#f59e0b; font-size:1.1rem; flex-shrink:0; }
.maint-alert-text { font-size:.8rem; color:var(--text-primary); }
.maint-alert-text strong { color:#f59e0b; }
</style>

<?php $hasMaintenanceAlert = $maintenancePanels > 0 || $bannedUsers > 0; ?>

<div class="db-wrap">

<!-- ══ HEADER ══ -->
<div class="db-top">
    <div>
        <h1><i class="fa-solid fa-gauge-high"></i>Dashboard Staff</h1>
        <div class="db-sub">
            <div class="live-chip"><span class="ld"></span> Live</div>
            LysoriaMDT &middot; <?php echo date('d F Y'); ?>
            &middot; <?php echo $activePanels; ?> panels actifs
            <?php if ($maintenancePanels>0): ?> &middot; <span style="color:#f59e0b;font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $maintenancePanels; ?> maintenance</span><?php endif; ?>
        </div>
    </div>
    <div class="db-btns">
        <a href="users/index.php"    class="btn btn-secondary btn-sm"><i class="fa-solid fa-users"></i> Utilisateurs</a>
        <a href="logs.php"           class="btn btn-secondary btn-sm"><i class="fa-solid fa-scroll"></i> Logs</a>
        <a href="content/panels.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Panels</a>
        <a href="settings.php"       class="btn btn-primary btn-sm"><i class="fa-solid fa-gear"></i> Paramètres</a>
    </div>
</div>

<?php if ($maintenancePanels > 0 || $bannedUsers >= 5): ?>
<div class="maint-alert">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div class="maint-alert-text">
        <?php if ($maintenancePanels>0): ?><strong><?php echo $maintenancePanels; ?> panel<?php echo $maintenancePanels>1?'s':''; ?> en maintenance</strong> — certains utilisateurs ne peuvent pas y accéder.<?php endif; ?>
        <?php if ($bannedUsers>=5): ?> &nbsp; <strong><?php echo $bannedUsers; ?> comptes bannis</strong> actuellement.<?php endif; ?>
        <a href="settings.php" style="color:var(--blue);margin-left:8px;">Gérer →</a>
    </div>
</div>
<?php endif; ?>

<!-- ══ KPIs ══ -->
<div class="kpi-row">

    <div class="kpi" style="--kc:var(--blue)">
        <div class="kpi-h"><div class="kpi-lbl">Utilisateurs</div><div class="kpi-ico"><i class="fa-solid fa-users"></i></div></div>
        <div class="kpi-val" data-count="<?php echo $totalUsers; ?>">0</div>
        <div class="kpi-meta"><span class="up">↑ <?php echo $activeUsers; ?> actifs</span> &middot; <?php echo $totalUsers-$activeUsers; ?> inactifs</div>
    </div>

    <div class="kpi" style="--kc:#22c55e">
        <div class="kpi-h"><div class="kpi-lbl">Connexions / jour</div><div class="kpi-ico"><i class="fa-solid fa-right-to-bracket"></i></div></div>
        <div class="kpi-val" data-count="<?php echo $todayLogins; ?>">0</div>
        <div class="kpi-meta"><span class="up">↑ <?php echo $weekLogins; ?></span> cette semaine</div>
    </div>

    <div class="kpi" style="--kc:var(--purple)">
        <div class="kpi-h"><div class="kpi-lbl">Panels actifs</div><div class="kpi-ico"><i class="fa-solid fa-layer-group"></i></div></div>
        <div class="kpi-val" data-count="<?php echo $activePanels; ?>">0</div>
        <div class="kpi-meta"><?php if($customPanels>0):?><span class="up">+<?php echo $customPanels; ?> custom</span> &middot; <?php endif;?>sur 22</div>
    </div>

    <div class="kpi" style="--kc:var(--orange)">
        <div class="kpi-h"><div class="kpi-lbl">Actions aujourd'hui</div><div class="kpi-ico"><i class="fa-solid fa-bolt"></i></div></div>
        <div class="kpi-val" data-count="<?php echo $logsToday; ?>">0</div>
        <div class="kpi-meta"><?php echo number_format($logsWeek); ?> cette semaine</div>
    </div>

    <div class="kpi" style="--kc:var(--red)">
        <div class="kpi-h"><div class="kpi-lbl">Bannis</div><div class="kpi-ico"><i class="fa-solid fa-ban"></i></div></div>
        <div class="kpi-val" data-count="<?php echo $bannedUsers; ?>">0</div>
        <div class="kpi-meta"><a href="users/bans.php" style="color:var(--blue);font-weight:700;text-decoration:none;">Gérer →</a></div>
    </div>

    <div class="kpi" style="--kc:#06b6d4">
        <div class="kpi-h"><div class="kpi-lbl">Taux d'activité</div><div class="kpi-ico"><i class="fa-solid fa-chart-pie"></i></div></div>
        <div class="kpi-val"><?php echo $totalUsers>0?round(($activeUsers/$totalUsers)*100):0; ?>%</div>
        <div class="kpi-meta"><?php echo $activeUsers; ?> / <?php echo $totalUsers; ?> comptes</div>
    </div>

    <div class="kpi" style="--kc:#22c55e">
        <div class="kpi-h"><div class="kpi-lbl">En ligne</div><div class="kpi-ico"><i class="fa-solid fa-signal"></i></div></div>
        <div class="kpi-val"><?php echo $onlineNow; ?></div>
        <div class="kpi-meta"><span class="dot dot-g"></span> Actifs (10 min)</div>
    </div>

    <div class="kpi" style="--kc:#f59e0b">
        <div class="kpi-h"><div class="kpi-lbl">Maintenance</div><div class="kpi-ico"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
        <div class="kpi-val"><?php echo $maintenancePanels; ?></div>
        <div class="kpi-meta">
            <?php if($maintenancePanels>0):?><span class="warn"><span class="dot dot-y"></span> <?php echo $maintenancePanels; ?> panel(s)</span>
            <?php else:?><span class="up">Tout opérationnel</span><?php endif;?>
        </div>
    </div>

</div>

<!-- ══ LIGNE 1 : Logs + Sidebar ══ -->
<div class="g2">

    <!-- Logs récents -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Activité récente</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <!-- Micro sparkline -->
                <div style="display:flex;align-items:flex-end;gap:3px;height:22px;opacity:.65;">
                    <?php foreach($chartData as $i=>$d):
                        $h=$maxChart>0?max(2,round(($d['cnt']/$maxChart)*20)):2; ?>
                    <div style="width:5px;height:<?php echo $h;?>px;background:<?php echo $d['today']?'var(--blue)':'rgba(59,130,246,.35)';?>;border-radius:2px 2px 0 0;"></div>
                    <?php endforeach;?>
                </div>
                <a href="logs.php" class="btn btn-secondary btn-sm">Tout voir</a>
            </div>
        </div>
        <div class="card-body">
            <?php if(empty($recentLogs)):?>
                <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Aucune activité</p></div>
            <?php else: foreach($recentLogs as $log):
                $fac=$log['faction']??'NONE';
                $avBg=$factionBg[$fac]??'#1d4ed8';
                $init=strtoupper(substr($log['username']??'S',0,1));
            ?>
            <div class="log-row">
                <div class="log-av" style="background:<?php echo $avBg;?>">
                    <?php if(!empty($log['avatar'])):?><img src="<?php echo htmlspecialchars($log['avatar']);?>" alt="">
                    <?php else:?><?php echo $init;?><?php endif;?>
                </div>
                <div class="log-body">
                    <div class="log-top">
                        <span class="log-who"><?php echo htmlspecialchars($log['username']??'Système');?></span>
                        <?php if(!empty($log['panel'])):?><span class="log-tag"><?php echo htmlspecialchars($log['panel']);?></span><?php endif;?>
                    </div>
                    <div class="log-act"><?php echo htmlspecialchars($log['action']);?></div>
                    <?php if(!empty($log['description'])):?><div class="log-desc"><?php echo htmlspecialchars($log['description']);?></div><?php endif;?>
                </div>
                <div class="log-ts"><?php echo ago($log['created_at']);?></div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>

    <!-- Sidebar droite -->
    <div class="gcol">

        <!-- État système -->
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-server"></i> État du système</div></div>
            <div class="card-body">
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-database" style="color:#22c55e"></i> Base de données</span>
                    <span class="sys-val"><span class="dot dot-g"></span> OK</span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-layer-group" style="color:var(--blue)"></i> Panels actifs</span>
                    <span class="sys-val"><?php echo $activePanels;?> / 22</span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-puzzle-piece" style="color:var(--purple)"></i> Custom panels</span>
                    <span class="sys-val"><?php echo $customPanels;?></span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i> Maintenance</span>
                    <span class="sys-val">
                        <?php if($maintenancePanels>0):?><span class="dot dot-y"></span> <?php echo $maintenancePanels;?> panel(s)
                        <?php else:?><span class="dot dot-g"></span> Aucune<?php endif;?>
                    </span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-ban" style="color:var(--red)"></i> Bannis</span>
                    <span class="sys-val" style="color:<?php echo $bannedUsers>0?'var(--red)':'inherit';?>">
                        <?php if($bannedUsers>0):?><span class="dot dot-r"></span><?php endif;?> <?php echo $bannedUsers;?>
                    </span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-clock" style="color:#06b6d4"></i> Heure serveur</span>
                    <span class="sys-val" id="srv-clock" style="font-size:.7rem;font-variant-numeric:tabular-nums;"><?php echo date('H:i:s');?></span>
                </div>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-bolt"></i> Actions rapides</div></div>
            <div class="card-body">
                <div class="qa-grid">
                    <a href="users/index.php"       class="qa-btn" style="--qc:var(--blue)"><i class="fa-solid fa-users"></i>Utilisateurs</a>
                    <a href="content/panels.php"    class="qa-btn" style="--qc:var(--purple)"><i class="fa-solid fa-layer-group"></i>Panels</a>
                    <a href="content/mdt.php"       class="qa-btn" style="--qc:#06b6d4"><i class="fa-solid fa-sliders"></i>Config MDT</a>
                    <a href="users/bans.php"        class="qa-btn" style="--qc:var(--red)"><i class="fa-solid fa-ban"></i>Bannis</a>
                    <a href="logs.php"              class="qa-btn" style="--qc:var(--orange)"><i class="fa-solid fa-scroll"></i>Logs</a>
                    <a href="extensions/addons.php" class="qa-btn" style="--qc:var(--green)"><i class="fa-solid fa-puzzle-piece"></i>Extensions</a>
                    <a href="content/panel-builder.php" class="qa-btn" style="--qc:#a855f7"><i class="fa-solid fa-wand-magic-sparkles"></i>Builder</a>
                    <a href="settings.php"          class="qa-btn" style="--qc:#94a3b8"><i class="fa-solid fa-gear"></i>Paramètres</a>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ══ LIGNE 2 : Membres + Factions + Top users ══ -->
<div class="g3">

    <!-- Nouveaux membres -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-user-plus"></i> Nouveaux membres</div>
            <a href="users/index.php" class="btn btn-secondary btn-sm">Voir tout</a>
        </div>
        <div class="card-body">
            <?php foreach($newUsers as $u):
                $fac=$u['faction']??'NONE'; $bg=$factionBg[$fac]??'#1e293b'; $col=$factionColors[$fac]??'#64748b';
                $init=strtoupper(substr($u['username'],0,1));
            ?>
            <div class="m-row">
                <div class="m-av" style="background:<?php echo $bg;?>">
                    <?php if(!empty($u['avatar'])):?><img src="<?php echo htmlspecialchars($u['avatar']);?>" alt="">
                    <?php else:?><?php echo $init;?><?php endif;?>
                </div>
                <div class="m-info">
                    <div class="m-name"><?php echo htmlspecialchars($u['username']);?></div>
                    <div class="m-role"><?php echo htmlspecialchars($u['role']??'Joueur');?></div>
                </div>
                <span class="m-badge" style="background:<?php echo $col;?>20;color:<?php echo $col;?>;border:1px solid <?php echo $col;?>40;">
                    <?php echo htmlspecialchars($fac);?>
                </span>
            </div>
            <?php endforeach;?>
            <?php if(empty($newUsers)):?><div class="empty-state"><i class="fa-solid fa-users"></i><p>Aucun membre</p></div><?php endif;?>
        </div>
    </div>

    <!-- Répartition factions -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Répartition factions</div></div>
        <div class="card-body">
            <?php if(empty($factionStats)):?><div class="empty-state"><i class="fa-solid fa-chart-bar"></i><p>Aucune donnée</p></div>
            <?php endif; foreach($factionStats as $fs):
                $pct=$maxFaction>0?round(($fs['cnt']/$maxFaction)*100):0;
                $col=$factionColors[$fs['faction']]??'#3b82f6';
            ?>
            <div class="f-row">
                <div class="f-head">
                    <div class="f-name"><div class="f-dot" style="background:<?php echo $col;?>;box-shadow:0 0 5px <?php echo $col;?>55;"></div><?php echo htmlspecialchars($fs['faction']);?></div>
                    <div class="f-cnt"><?php echo $fs['cnt'];?> membre<?php echo $fs['cnt']>1?'s':'';?></div>
                </div>
                <div class="prog"><div class="prog-f" data-width="<?php echo $pct;?>" style="background:<?php echo $col;?>;opacity:.75;"></div></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>

    <!-- Top users + Panels actifs -->
    <div class="gcol">

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-trophy"></i> Top utilisateurs (7j)</div></div>
            <div class="card-body">
                <?php if(empty($topUsers)):?><div class="empty-state"><i class="fa-solid fa-trophy"></i><p>Aucune donnée</p></div>
                <?php else: foreach($topUsers as $i=>$u):
                    $rc=match($i){0=>'gold',1=>'silver',2=>'bronze',default=>''};
                    $bg=$factionBg[$u['faction']??'NONE']??'#1d4ed8';
                ?>
                <div class="top-row">
                    <div class="top-rank <?php echo $rc;?>">#<?php echo $i+1;?></div>
                    <div class="t-av" style="background:<?php echo $bg;?>">
                        <?php if(!empty($u['avatar'])):?><img src="<?php echo htmlspecialchars($u['avatar']);?>" alt="">
                        <?php else:?><?php echo strtoupper(substr($u['username'],0,1));?><?php endif;?>
                    </div>
                    <div class="t-info">
                        <div class="t-name"><?php echo htmlspecialchars($u['username']);?></div>
                        <div class="t-sub"><?php echo htmlspecialchars($u['faction']??'–');?></div>
                    </div>
                    <div class="t-cnt"><?php echo $u['actions'];?> <span style="color:var(--text-secondary);font-weight:400;font-size:.62rem;">actions</span></div>
                </div>
                <?php endforeach; endif;?>
            </div>
        </div>

        <?php if(!empty($panelActivity)):?>
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-fire-flame-curved"></i> Panels les + actifs (7j)</div></div>
            <div class="card-body">
                <?php foreach($panelActivity as $pa):
                    $w=$maxPanel>0?round(($pa['cnt']/$maxPanel)*100):0;?>
                <div class="pa-row">
                    <div class="pa-name"><?php echo htmlspecialchars($pa['panel']);?></div>
                    <div class="pa-bar"><div class="pa-fill" data-width="<?php echo $w;?>"></div></div>
                    <div class="pa-cnt"><?php echo $pa['cnt'];?></div>
                </div>
                <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>

    </div>
</div>

<!-- ══ LIGNE 3 : Graphique + Bannis récents + Sanctions récentes ══ -->
<div class="g3">

    <!-- Graphique connexions 7j -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-chart-line"></i> Connexions — 7 jours</div>
            <span style="font-size:.72rem;color:var(--text-secondary);"><?php echo $weekLogins;?> au total</span>
        </div>
        <div class="card-body">
            <div class="chart">
                <?php foreach($chartData as $d):
                    $h=$maxChart>0?max(4,round(($d['cnt']/$maxChart)*73)):4;?>
                <div class="chart-col">
                    <div class="chart-val"><?php echo $d['cnt'];?></div>
                    <div class="chart-bar" style="height:<?php echo $h;?>px;background:<?php echo $d['today']?'var(--blue)':'rgba(59,130,246,.28)';?>;box-shadow:<?php echo $d['today']?'0 0 12px rgba(59,130,246,.4)':'none';?>;"></div>
                    <div class="chart-lbl" style="color:<?php echo $d['today']?'var(--blue)':'var(--text-secondary)';?>;font-weight:<?php echo $d['today']?'800':'600';?>;">
                        <?php echo $d['today']?'Auj.':$d['lbl'];?>
                    </div>
                </div>
                <?php endforeach;?>
            </div>
        </div>
    </div>

    <!-- Bannis récents -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-ban"></i> Derniers bannis</div>
            <a href="users/bans.php" class="btn btn-secondary btn-sm">Voir tout</a>
        </div>
        <div class="card-body">
            <?php if(empty($recentBans)):?>
                <div class="empty-state"><i class="fa-solid fa-shield-check" style="color:#22c55e"></i><p>Aucun banni récent</p></div>
            <?php else: foreach($recentBans as $ban):?>
            <div class="ban-row">
                <div class="ban-av"><?php echo strtoupper(substr($ban['username'],0,1));?></div>
                <div class="ban-info">
                    <div class="ban-name"><?php echo htmlspecialchars($ban['username']);?></div>
                    <div class="ban-reason"><?php echo htmlspecialchars($ban['ban_reason']??'–');?></div>
                </div>
                <div class="ban-ts"><?php echo ago($ban['banned_at']??'now');?></div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>

    <!-- Sanctions récentes -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-gavel"></i> Sanctions récentes</div>
        </div>
        <div class="card-body">
            <?php if(empty($lastSanctions)):?>
                <div class="empty-state"><i class="fa-solid fa-gavel"></i><p>Aucune sanction récente</p></div>
            <?php else: foreach($lastSanctions as $s):
                $typeColor=['Avertissement'=>'#f59e0b','Renvoi'=>'#ef4444','Mise à pied'=>'#f97316','Blâme'=>'#8b5cf6'][$s['type']??'']??'#64748b';
                $typeIcon =['Avertissement'=>'triangle-exclamation','Renvoi'=>'user-slash','Mise à pied'=>'pause-circle','Blâme'=>'comment-slash'][$s['type']??'']??'gavel';
            ?>
            <div class="sanc-row">
                <div class="sanc-ico" style="background:<?php echo $typeColor;?>18;color:<?php echo $typeColor;?>;">
                    <i class="fa-solid fa-<?php echo $typeIcon;?>"></i>
                </div>
                <div class="sanc-info">
                    <div class="sanc-name"><?php echo htmlspecialchars($s['target_name']??'–');?></div>
                    <div class="sanc-detail"><?php echo htmlspecialchars($s['type']??'Sanction');?> · par <?php echo htmlspecialchars($s['author_name']??'Staff');?></div>
                </div>
                <div class="sanc-ts"><?php echo ago($s['created_at']);?></div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>

</div>

</div><!-- /db-wrap -->

<script>
/* Compteurs animés */
document.querySelectorAll('[data-count]').forEach(el => {
    const t = parseInt(el.dataset.count)||0;
    if(!t){ el.textContent='0'; return; }
    let cur=0, step=Math.max(1,Math.ceil(t/40));
    const timer=setInterval(()=>{ cur=Math.min(cur+step,t); el.textContent=cur.toLocaleString('fr-FR'); if(cur>=t)clearInterval(timer); },22);
});

/* Barres */
setTimeout(()=>{
    document.querySelectorAll('[data-width]').forEach(el=>{ el.style.width=el.dataset.width+'%'; });
},250);

/* Horloge serveur */
setInterval(()=>{
    const el=document.getElementById('srv-clock');
    if(el) el.textContent=new Date().toLocaleTimeString('fr-FR');
},1000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>