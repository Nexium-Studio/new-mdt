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

/* ── Propositions d'ajout MDT (section dédiée) ── */
require_once ROOT_PATH . '/includes/mdt_proposals.php';
$myProposals   = mdt_proposals_list_for_user($db, (int)($_SESSION['user_id'] ?? 0));
$propStatuses  = mdt_proposals_statuses();
$isProposalAdmin = hasRole(['*', 'Administrateur', 'Développeur']);
$proposalsLiaisonSet = $isProposalAdmin ? (mdt_proposals_liaison_token($db) !== '') : false;
$proposalsInitial = array_map(static function (array $r) use ($propStatuses) {
    $st   = (string)($r['status'] ?? 'pending');
    $meta = $propStatuses[$st] ?? $propStatuses['pending'];
    return [
        'id'          => (int)$r['id'],
        'content'     => (string)$r['content'],
        'images'      => $r['images_arr'] ?? [],
        'status'      => $st,
        'statusLabel' => $meta['label'],
        'statusColor' => $meta['color'],
        'statusIcon'  => $meta['icon'],
        'adminNote'   => (string)($r['admin_note'] ?? ''),
        'createdAt'   => (string)($r['created_at'] ?? ''),
    ];
}, $myProposals);

include __DIR__ . '/includes/header.php';
?>
<style>
/* ════════════════════ DASHBOARD STAFF — refonte Nexium ════════════════════ */
/* Le layout staff (.staff-layout) est un flex-row et .main-wrapper ne grandit
   pas par défaut : la zone de contenu se réduit alors à la largeur de son
   contenu, laissant un grand vide à droite. On la force à remplir toute la
   largeur/hauteur disponible, à toutes les résolutions. */
.main-wrapper { flex:1 1 auto; min-width:0; }
.main-content { width:100%; min-width:0; }

.sd { display:flex; flex-direction:column; gap:22px; width:100%; }

/* ── Hero ── */
.sd-hero {
    display:flex; justify-content:space-between; align-items:flex-end; gap:18px; flex-wrap:wrap;
    padding:22px 26px; border-radius:20px; position:relative; overflow:hidden;
    background:var(--bg-card); border:1px solid var(--border);
}
.sd-hero::before { content:''; position:absolute; inset:0; opacity:.10; pointer-events:none;
    background:radial-gradient(120% 140% at 0% 0%, var(--bleu) 0%, transparent 55%),
              radial-gradient(120% 140% at 100% 100%, var(--violet) 0%, transparent 55%); }
.sd-hero-l { position:relative; z-index:1; }
.sd-eyebrow { display:inline-flex; align-items:center; gap:7px; font-size:.66rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
    color:#fff; background:var(--grad); padding:4px 10px; border-radius:20px; margin-bottom:12px; }
.sd-hero h1 { font-size:1.7rem; letter-spacing:-.5px; margin-bottom:8px; }
.sd-hero h1 span { background:var(--grad); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.sd-sub { font-size:.8rem; color:var(--text-secondary); display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
.sd-sub .sep { opacity:.4; }
.live-chip { display:inline-flex; align-items:center; gap:6px; background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.28); border-radius:20px; padding:3px 10px; font-size:.68rem; font-weight:700; color:var(--green); }
.ld { width:6px; height:6px; background:var(--green); border-radius:50%; animation:ldp 2s infinite; }
@keyframes ldp { 0%,100%{opacity:1} 50%{opacity:.35} }
.sd-hero-r { display:flex; gap:8px; flex-wrap:wrap; position:relative; z-index:1; }

/* ── Alerte maintenance ── */
.sd-alert { display:flex; align-items:center; gap:12px; background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.28); border-radius:14px; padding:13px 18px; }
.sd-alert > i { color:var(--orange); font-size:1.15rem; flex-shrink:0; }
.sd-alert-txt { font-size:.82rem; color:var(--text-primary); }
.sd-alert-txt strong { color:var(--orange); }

/* ── KPIs ── */
.sd-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
.sd-kpi { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:18px 20px; position:relative; overflow:hidden; transition:transform .2s, border-color .2s, box-shadow .2s; }
.sd-kpi:hover { transform:translateY(-3px); border-color:color-mix(in srgb,var(--kc,var(--blue)) 55%,transparent); box-shadow:0 16px 34px -18px var(--kc,var(--blue)); }
.sd-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kc,var(--blue)); }
.sd-kpi-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.sd-kpi-lbl { font-size:.64rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--text-secondary); }
.sd-kpi-ico { width:36px; height:36px; border-radius:11px; display:grid; place-items:center; font-size:.9rem; color:var(--kc,var(--blue));
    background:color-mix(in srgb,var(--kc,var(--blue)) 15%,transparent); border:1px solid color-mix(in srgb,var(--kc,var(--blue)) 26%,transparent);
    transition:transform .28s cubic-bezier(.34,1.4,.5,1), background .2s, border-color .2s; }
.sd-kpi:hover .sd-kpi-ico { transform:scale(1.1) rotate(-4deg); border-color:color-mix(in srgb,var(--kc,var(--blue)) 45%,transparent); }
@media (prefers-reduced-motion: reduce) { .sd-kpi-ico { transition:none; } .sd-kpi:hover .sd-kpi-ico { transform:none; } }
.sd-kpi-val { font-family:'Montserrat',sans-serif; font-size:1.9rem; font-weight:800; line-height:1; margin-bottom:7px; font-variant-numeric:tabular-nums; color:var(--text-primary); }
.sd-kpi-meta { font-size:.72rem; color:var(--text-secondary); display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.sd-kpi-meta a { color:var(--blue); font-weight:700; text-decoration:none; }
.up{color:var(--green);font-weight:700;} .warn{color:var(--orange);font-weight:700;} .dn{color:var(--red);font-weight:700;}
.dot { display:inline-block; width:7px; height:7px; border-radius:50%; }
.dot-g{background:var(--green);animation:ldp 2s infinite;} .dot-y{background:var(--orange);} .dot-r{background:var(--red);}

/* ── Grilles ── */
.sd-two   { display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:20px; }
.sd-three { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:20px; }
.sd-col   { display:flex; flex-direction:column; gap:20px; min-width:0; }

/* ── Graphique connexions (pleine largeur) ── */
.sd-chart { display:flex; align-items:flex-end; gap:1.4%; padding:8px 2px 0; }
.sd-chart-col { flex:1 1 0; min-width:0; display:flex; flex-direction:column; align-items:center; gap:9px; }
.sd-chart-bar { width:100%; max-width:78px; border-radius:10px 10px 4px 4px; background:var(--grad); transform-origin:bottom;
    animation:sdGrow 1s cubic-bezier(.16,1,.3,1) both; box-shadow:0 -4px 26px -10px var(--blue); }
.sd-chart-bar.is-today { background:linear-gradient(180deg,var(--cyan),var(--violet)); box-shadow:0 -4px 30px -8px var(--violet); }
.sd-chart-col:hover .sd-chart-bar { filter:brightness(1.12); }
.sd-chart-val { font-size:.76rem; font-weight:800; color:var(--text-primary); font-variant-numeric:tabular-nums; }
.sd-chart-lbl { font-size:.72rem; font-weight:600; color:var(--text-secondary); }
.sd-chart-col.is-today .sd-chart-lbl { color:var(--violet); font-weight:800; }
@keyframes sdGrow { from{transform:scaleY(0);} to{transform:scaleY(1);} }
.sd-spark { display:flex; align-items:flex-end; gap:3px; height:22px; opacity:.7; }
.sd-spark span { width:5px; border-radius:2px 2px 0 0; background:color-mix(in srgb,var(--blue) 45%,transparent); }
.sd-spark span.on { background:var(--blue); }

/* ── Logs ── */
.log-row { display:grid; grid-template-columns:36px 1fr auto; gap:10px; align-items:center; padding:9px 6px; border-radius:9px; transition:background .15s; }
.log-row + .log-row { border-top:1px solid var(--border); }
.log-row:hover { background:var(--bg-hover); }
.log-av { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.log-av img { width:100%; height:100%; object-fit:cover; }
.log-body { min-width:0; }
.log-top { display:flex; align-items:center; gap:7px; margin-bottom:2px; }
.log-who { font-size:.78rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:130px; }
.log-tag { font-size:.56rem; padding:1px 7px; border-radius:20px; background:color-mix(in srgb,var(--purple) 16%,transparent); color:var(--purple); border:1px solid color-mix(in srgb,var(--purple) 26%,transparent); font-weight:700; letter-spacing:.4px; text-transform:uppercase; flex-shrink:0; }
.log-act { font-size:.75rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.log-desc{ font-size:.66rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.log-ts  { font-size:.64rem; color:var(--text-muted); white-space:nowrap; text-align:right; flex-shrink:0; font-family:'DM Mono',monospace; }

/* ── Membres ── */
.m-row { display:flex; align-items:center; gap:10px; padding:8px 6px; border-radius:10px; transition:background .15s; }
.m-row:hover { background:var(--bg-hover); }
.m-av { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.m-av img { width:100%; height:100%; object-fit:cover; }
.m-info { flex:1; min-width:0; }
.m-name { font-size:.8rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.m-role { font-size:.66rem; color:var(--text-secondary); }
.m-badge{ font-size:.58rem; font-weight:700; padding:2px 8px; border-radius:20px; flex-shrink:0; }

/* ── Factions ── */
.f-row { margin-bottom:11px; }
.f-row:last-child { margin-bottom:0; }
.f-head { display:flex; justify-content:space-between; margin-bottom:5px; }
.f-name { font-size:.77rem; font-weight:700; display:flex; align-items:center; gap:7px; }
.f-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.f-cnt  { font-size:.68rem; color:var(--text-secondary); }
.prog   { height:6px; background:var(--border); border-radius:99px; overflow:hidden; }
.prog-f { height:100%; border-radius:99px; transition:width 1.1s cubic-bezier(.16,1,.3,1); width:0; }

/* ── Top users ── */
.top-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:10px; transition:background .15s; }
.top-row:hover { background:var(--bg-hover); }
.top-rank { font-size:.7rem; font-weight:900; width:20px; text-align:center; flex-shrink:0; color:var(--text-secondary); }
.top-rank.gold{color:#f59e0b;} .top-rank.silver{color:#94a3b8;} .top-rank.bronze{color:#b45309;}
.t-av { width:31px; height:31px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.t-av img { width:100%; height:100%; object-fit:cover; }
.t-info { flex:1; min-width:0; }
.t-name { font-size:.78rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.t-sub  { font-size:.64rem; color:var(--text-secondary); }
.t-cnt  { font-size:.72rem; font-weight:800; color:var(--blue); white-space:nowrap; }

/* ── Panel activity ── */
.pa-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.pa-row:last-child { margin-bottom:0; }
.pa-name{ font-size:.75rem; font-weight:700; width:82px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pa-bar { flex:1; height:6px; background:var(--border); border-radius:99px; overflow:hidden; }
.pa-fill{ height:100%; border-radius:99px; background:var(--grad); transition:width 1.1s cubic-bezier(.16,1,.3,1); width:0; }
.pa-cnt { font-size:.68rem; color:var(--text-secondary); width:28px; text-align:right; font-variant-numeric:tabular-nums; }

/* ── Système ── */
.sys-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--border); font-size:.78rem; }
.sys-row:last-child { border-bottom:none; }
.sys-lbl { color:var(--text-secondary); display:flex; align-items:center; gap:9px; }
.sys-lbl i { width:14px; text-align:center; }
.sys-val { font-weight:700; display:flex; align-items:center; gap:6px; }

/* ── Actions rapides ── */
.qa-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
.qa-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:7px; padding:14px 8px; border-radius:12px; background:var(--bg-hover); border:1px solid var(--border); color:var(--text-secondary); text-decoration:none; font-size:.7rem; font-weight:700; transition:all .2s; text-align:center; }
.qa-btn i { font-size:1.05rem; color:var(--qc,var(--blue)); }
.qa-btn:hover { background:color-mix(in srgb,var(--qc,var(--blue)) 10%,transparent); border-color:color-mix(in srgb,var(--qc,var(--blue)) 45%,transparent); color:var(--text-primary); transform:translateY(-2px); }

/* ── Sanctions ── */
.sanc-row { display:flex; align-items:center; gap:10px; padding:9px 6px; border-radius:9px; }
.sanc-row + .sanc-row { border-top:1px solid var(--border); }
.sanc-ico { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.82rem; flex-shrink:0; }
.sanc-info { flex:1; min-width:0; }
.sanc-name { font-size:.78rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sanc-detail { font-size:.66rem; color:var(--text-secondary); }
.sanc-ts { font-size:.64rem; color:var(--text-muted); white-space:nowrap; font-family:'DM Mono',monospace; }

/* ── Bannis ── */
.ban-row { display:flex; align-items:center; gap:10px; padding:9px 6px; border-radius:9px; }
.ban-row + .ban-row { border-top:1px solid var(--border); }
.ban-av { width:32px; height:32px; border-radius:9px; background:#991b1b; display:flex; align-items:center; justify-content:center; font-size:.74rem; font-weight:800; color:#fff; flex-shrink:0; }
.ban-info { flex:1; min-width:0; }
.ban-name { font-size:.78rem; font-weight:700; }
.ban-reason { font-size:.66rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ban-ts { font-size:.64rem; color:var(--text-muted); white-space:nowrap; font-family:'DM Mono',monospace; }

/* ══ PROPOSITIONS D'AJOUT ══ */
.sd-propose { position:relative; overflow:hidden; }
.sd-propose::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--grad); z-index:1; }
.sd-propose-head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:22px 24px 4px; flex-wrap:wrap; }
.sd-propose-head-l { display:flex; align-items:center; gap:14px; min-width:0; }
.sd-propose-ico { width:46px; height:46px; border-radius:13px; flex-shrink:0; display:grid; place-items:center; font-size:1.2rem; color:#fff; background:var(--grad); box-shadow:0 8px 22px -8px var(--violet); }
.sd-propose-title { font-family:'Montserrat',sans-serif; font-weight:800; font-size:1.05rem; }
.sd-propose-sub { font-size:.76rem; color:var(--text-secondary); margin-top:2px; }
.sd-propose-grid { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(0,1fr); gap:24px; padding:18px 24px 24px; }
@media(max-width:900px){ .sd-propose-grid{ grid-template-columns:1fr; } }

.sd-propose-form textarea.form-control { resize:vertical; min-height:130px; line-height:1.55; }
.sd-count { text-align:right; font-size:.68rem; color:var(--text-muted); margin-top:4px; font-variant-numeric:tabular-nums; }
.sd-dropzone { border:1.5px dashed var(--border-hover); border-radius:13px; padding:22px 16px; text-align:center; cursor:pointer; transition:border-color .18s, background .18s; color:var(--text-secondary); }
.sd-dropzone i { font-size:1.5rem; color:var(--blue); margin-bottom:8px; display:block; }
.sd-dropzone div:not(.sd-dropzone-hint) { font-size:.82rem; }
.sd-dropzone span { color:var(--blue); font-weight:700; text-decoration:underline; }
.sd-dropzone-hint { font-size:.68rem; color:var(--text-muted); margin-top:5px; }
.sd-dropzone.drag { border-color:var(--blue); background:color-mix(in srgb,var(--blue) 8%,transparent); }
.sd-thumbs { display:flex; flex-wrap:wrap; gap:9px; margin-top:12px; }
.sd-thumb { position:relative; width:66px; height:66px; border-radius:10px; overflow:hidden; border:1px solid var(--border); }
.sd-thumb img { width:100%; height:100%; object-fit:cover; }
.sd-thumb-x { position:absolute; top:3px; right:3px; width:19px; height:19px; border-radius:6px; border:none; cursor:pointer; background:rgba(0,0,0,.65); color:#fff; font-size:.6rem; display:grid; place-items:center; }
.sd-thumb-x:hover { background:var(--red); }
.sd-submit { margin-top:16px; width:100%; justify-content:center; }
.sd-submit:disabled { opacity:.6; cursor:not-allowed; }

.sd-propose-hist { border-left:1px solid var(--border); padding-left:24px; min-width:0; }
@media(max-width:900px){ .sd-propose-hist{ border-left:none; border-top:1px solid var(--border); padding-left:0; padding-top:18px; } }
.sd-hist-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.sd-hist-count { font-size:.66rem; font-weight:800; color:var(--text-secondary); background:var(--bg-hover); border:1px solid var(--border); border-radius:20px; padding:2px 9px; }
.sd-hist-list { display:flex; flex-direction:column; gap:11px; max-height:440px; overflow-y:auto; padding-right:4px; }
.sd-hist-list::-webkit-scrollbar { width:6px; }
.sd-hist-list::-webkit-scrollbar-thumb { background:var(--border-hover); border-radius:3px; }
.sd-prop { background:var(--bg-hover); border:1px solid var(--border); border-radius:12px; padding:13px 14px; transition:border-color .16s; }
.sd-prop:hover { border-color:var(--border-hover); }
.sd-prop-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:7px; }
.sd-prop-status { display:inline-flex; align-items:center; gap:5px; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; padding:3px 9px; border-radius:20px; white-space:nowrap; }
.sd-prop-date { font-size:.64rem; color:var(--text-muted); white-space:nowrap; font-family:'DM Mono',monospace; }
.sd-prop-text { font-size:.79rem; color:var(--text-primary); line-height:1.5; white-space:pre-wrap; word-break:break-word; }
.sd-prop-imgs { display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
.sd-prop-imgs a { width:46px; height:46px; border-radius:8px; overflow:hidden; border:1px solid var(--border); }
.sd-prop-imgs img { width:100%; height:100%; object-fit:cover; display:block; }
.sd-prop-note { margin-top:8px; font-size:.7rem; color:var(--text-secondary); background:color-mix(in srgb,var(--blue) 9%,transparent); border-left:2px solid var(--blue); border-radius:0 7px 7px 0; padding:6px 9px; }
.sd-hist-empty { text-align:center; padding:34px 16px; color:var(--text-secondary); }
.sd-hist-empty i { font-size:2rem; opacity:.3; margin-bottom:10px; display:block; }
.sd-hist-empty p { font-size:.82rem; }

.sd-dot-ok { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--green); margin-left:2px; }
.sd-dot-off { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--text-muted); margin-left:2px; }

/* ── Responsive ── */
@media(max-width:1400px){ .sd-three{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:1100px){ .sd-two{ grid-template-columns:1fr; } }
@media(max-width:960px) { .sd-kpis{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:680px) { .sd-three{ grid-template-columns:1fr; } }
@media(max-width:520px) { .sd-hero{ padding:18px; } .sd-hero h1{ font-size:1.4rem; } }
@media(max-width:440px) { .sd-kpis{ grid-template-columns:1fr; } }
</style>

<div class="sd">

<!-- ══ HERO ══ -->
<div class="sd-hero">
    <div class="sd-hero-l">
        <span class="sd-eyebrow"><i class="fa-solid fa-gauge-high"></i> Panel Staff</span>
        <h1>Dashboard <span>Staff</span></h1>
        <div class="sd-sub">
            <div class="live-chip"><span class="ld"></span> Live</div>
            <span><?php echo htmlspecialchars($siteName ?? 'LysoriaMDT'); ?></span>
            <span class="sep">•</span> <?php echo date('d F Y'); ?>
            <span class="sep">•</span> <?php echo (int)$activePanels; ?> panels actifs
            <?php if ($maintenancePanels>0): ?><span class="sep">•</span> <span class="warn"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo (int)$maintenancePanels; ?> maintenance</span><?php endif; ?>
        </div>
    </div>
    <div class="sd-hero-r">
        <a href="users/index.php"    class="btn btn-secondary btn-sm"><i class="fa-solid fa-users"></i> Utilisateurs</a>
        <a href="logs.php"           class="btn btn-secondary btn-sm"><i class="fa-solid fa-scroll"></i> Logs</a>
        <a href="content/panels.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Panels</a>
        <a href="settings.php"       class="btn btn-primary btn-sm"><i class="fa-solid fa-gear"></i> Paramètres</a>
    </div>
</div>

<?php if ($maintenancePanels > 0 || $bannedUsers >= 5): ?>
<div class="sd-alert">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div class="sd-alert-txt">
        <?php if ($maintenancePanels>0): ?><strong><?php echo (int)$maintenancePanels; ?> panel<?php echo $maintenancePanels>1?'s':''; ?> en maintenance</strong> — certains utilisateurs ne peuvent pas y accéder.<?php endif; ?>
        <?php if ($bannedUsers>=5): ?> &nbsp; <strong><?php echo (int)$bannedUsers; ?> comptes bannis</strong> actuellement.<?php endif; ?>
        <a href="settings.php" style="color:var(--blue);margin-left:8px;font-weight:700;text-decoration:none;">Gérer →</a>
    </div>
</div>
<?php endif; ?>

<!-- ══ KPIs ══ -->
<div class="sd-kpis">
    <div class="sd-kpi" style="--kc:var(--blue)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Utilisateurs</div><div class="sd-kpi-ico"><i class="fa-solid fa-users"></i></div></div>
        <div class="sd-kpi-val" data-count="<?php echo (int)$totalUsers; ?>">0</div>
        <div class="sd-kpi-meta"><span class="up">↑ <?php echo (int)$activeUsers; ?> actifs</span> • <?php echo (int)$totalUsers-(int)$activeUsers; ?> inactifs</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--green)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Connexions / jour</div><div class="sd-kpi-ico"><i class="fa-solid fa-right-to-bracket"></i></div></div>
        <div class="sd-kpi-val" data-count="<?php echo (int)$todayLogins; ?>">0</div>
        <div class="sd-kpi-meta"><span class="up">↑ <?php echo (int)$weekLogins; ?></span> cette semaine</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--purple)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Panels actifs</div><div class="sd-kpi-ico"><i class="fa-solid fa-layer-group"></i></div></div>
        <div class="sd-kpi-val" data-count="<?php echo (int)$activePanels; ?>">0</div>
        <div class="sd-kpi-meta"><?php if($customPanels>0):?><span class="up">+<?php echo (int)$customPanels; ?> custom</span> • <?php endif;?>sur 22</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--orange)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Actions aujourd'hui</div><div class="sd-kpi-ico"><i class="fa-solid fa-bolt"></i></div></div>
        <div class="sd-kpi-val" data-count="<?php echo (int)$logsToday; ?>">0</div>
        <div class="sd-kpi-meta"><?php echo number_format((int)$logsWeek,0,',',' '); ?> cette semaine</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--red)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Bannis</div><div class="sd-kpi-ico"><i class="fa-solid fa-ban"></i></div></div>
        <div class="sd-kpi-val" data-count="<?php echo (int)$bannedUsers; ?>">0</div>
        <div class="sd-kpi-meta"><a href="users/bans.php">Gérer →</a></div>
    </div>
    <div class="sd-kpi" style="--kc:var(--cyan)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Taux d'activité</div><div class="sd-kpi-ico"><i class="fa-solid fa-chart-pie"></i></div></div>
        <div class="sd-kpi-val"><?php echo $totalUsers>0?round(($activeUsers/$totalUsers)*100):0; ?>%</div>
        <div class="sd-kpi-meta"><?php echo (int)$activeUsers; ?> / <?php echo (int)$totalUsers; ?> comptes</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--green)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">En ligne</div><div class="sd-kpi-ico"><i class="fa-solid fa-signal"></i></div></div>
        <div class="sd-kpi-val"><?php echo (int)$onlineNow; ?></div>
        <div class="sd-kpi-meta"><span class="dot dot-g"></span> Actifs (10 min)</div>
    </div>
    <div class="sd-kpi" style="--kc:var(--orange)">
        <div class="sd-kpi-top"><div class="sd-kpi-lbl">Maintenance</div><div class="sd-kpi-ico"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
        <div class="sd-kpi-val"><?php echo (int)$maintenancePanels; ?></div>
        <div class="sd-kpi-meta">
            <?php if($maintenancePanels>0):?><span class="warn"><span class="dot dot-y"></span> <?php echo (int)$maintenancePanels; ?> panel(s)</span>
            <?php else:?><span class="up">Tout opérationnel</span><?php endif;?>
        </div>
    </div>
</div>

<!-- ══ GRAPHIQUE CONNEXIONS — PLEINE LARGEUR ══ -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-column"></i> Connexions — 7 derniers jours</div>
        <span style="font-size:.74rem;color:var(--text-secondary);font-weight:600;"><?php echo (int)$weekLogins; ?> au total</span>
    </div>
    <div class="card-body">
        <?php if(empty($chartData)): ?>
            <div class="empty-state"><i class="fa-solid fa-chart-column"></i><p>Aucune donnée</p></div>
        <?php else: ?>
        <div class="sd-chart">
            <?php foreach($chartData as $idx=>$d):
                $px = $maxChart>0 ? max(6, (int)round(($d['cnt']/$maxChart)*190)) : 6; ?>
            <div class="sd-chart-col <?php echo $d['today']?'is-today':''; ?>">
                <div class="sd-chart-val"><?php echo (int)$d['cnt']; ?></div>
                <div class="sd-chart-bar <?php echo $d['today']?'is-today':''; ?>" style="height:<?php echo $px; ?>px;animation-delay:<?php echo $idx*70; ?>ms"></div>
                <div class="sd-chart-lbl"><?php echo $d['today']?"Auj.":htmlspecialchars($d['lbl']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ LIGNE : Logs + Sidebar ══ -->
<div class="sd-two">

    <!-- Activité récente -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Activité récente</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="sd-spark">
                    <?php foreach($chartData as $d):
                        $h=$maxChart>0?max(2,round(($d['cnt']/$maxChart)*20)):2; ?>
                    <span class="<?php echo $d['today']?'on':''; ?>" style="height:<?php echo $h; ?>px;"></span>
                    <?php endforeach;?>
                </div>
                <a href="logs.php" class="btn btn-secondary btn-sm">Tout voir</a>
            </div>
        </div>
        <div class="card-body">
            <?php if(empty($recentLogs)):?>
                <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Aucune activité</p></div>
            <?php else: foreach($recentLogs as $log):
                $fac=$log['faction']??'NONE'; $avBg=$factionBg[$fac]??'#1d4ed8';
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

    <!-- Sidebar -->
    <div class="sd-col">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-server"></i> État du système</div></div>
            <div class="card-body">
                <div class="sys-row"><span class="sys-lbl"><i class="fa-solid fa-database" style="color:var(--green)"></i> Base de données</span><span class="sys-val"><span class="dot dot-g"></span> OK</span></div>
                <div class="sys-row"><span class="sys-lbl"><i class="fa-solid fa-layer-group" style="color:var(--blue)"></i> Panels actifs</span><span class="sys-val"><?php echo (int)$activePanels;?> / 22</span></div>
                <div class="sys-row"><span class="sys-lbl"><i class="fa-solid fa-puzzle-piece" style="color:var(--purple)"></i> Custom panels</span><span class="sys-val"><?php echo (int)$customPanels;?></span></div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-triangle-exclamation" style="color:var(--orange)"></i> Maintenance</span>
                    <span class="sys-val"><?php if($maintenancePanels>0):?><span class="dot dot-y"></span> <?php echo (int)$maintenancePanels;?> panel(s)<?php else:?><span class="dot dot-g"></span> Aucune<?php endif;?></span>
                </div>
                <div class="sys-row">
                    <span class="sys-lbl"><i class="fa-solid fa-ban" style="color:var(--red)"></i> Bannis</span>
                    <span class="sys-val" style="color:<?php echo $bannedUsers>0?'var(--red)':'inherit';?>"><?php if($bannedUsers>0):?><span class="dot dot-r"></span><?php endif;?> <?php echo (int)$bannedUsers;?></span>
                </div>
                <div class="sys-row"><span class="sys-lbl"><i class="fa-solid fa-clock" style="color:var(--cyan)"></i> Heure serveur</span><span class="sys-val" id="srv-clock" style="font-family:'DM Mono',monospace;font-size:.74rem;"><?php echo date('H:i:s');?></span></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-bolt"></i> Actions rapides</div></div>
            <div class="card-body">
                <div class="qa-grid">
                    <a href="users/index.php"           class="qa-btn" style="--qc:var(--blue)"><i class="fa-solid fa-users"></i>Utilisateurs</a>
                    <a href="content/panels.php"        class="qa-btn" style="--qc:var(--purple)"><i class="fa-solid fa-layer-group"></i>Panels</a>
                    <a href="content/mdt.php"           class="qa-btn" style="--qc:var(--cyan)"><i class="fa-solid fa-sliders"></i>Config MDT</a>
                    <a href="users/bans.php"            class="qa-btn" style="--qc:var(--red)"><i class="fa-solid fa-ban"></i>Bannis</a>
                    <a href="logs.php"                  class="qa-btn" style="--qc:var(--orange)"><i class="fa-solid fa-scroll"></i>Logs</a>
                    <a href="extensions/addons.php"     class="qa-btn" style="--qc:var(--green)"><i class="fa-solid fa-puzzle-piece"></i>Extensions</a>
                    <a href="content/panel-builder.php" class="qa-btn" style="--qc:var(--violet)"><i class="fa-solid fa-wand-magic-sparkles"></i>Builder</a>
                    <a href="settings.php"              class="qa-btn" style="--qc:#94a3b8"><i class="fa-solid fa-gear"></i>Paramètres</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ LIGNE : Membres + Factions + Top users ══ -->
<div class="sd-three">
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-user-plus"></i> Nouveaux membres</div>
            <a href="users/index.php" class="btn btn-secondary btn-sm">Voir tout</a>
        </div>
        <div class="card-body">
            <?php if(empty($newUsers)):?><div class="empty-state"><i class="fa-solid fa-users"></i><p>Aucun membre</p></div>
            <?php else: foreach($newUsers as $u):
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
                <span class="m-badge" style="background:<?php echo $col;?>20;color:<?php echo $col;?>;border:1px solid <?php echo $col;?>40;"><?php echo htmlspecialchars($fac);?></span>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>

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
                    <div class="f-name"><div class="f-dot" style="background:<?php echo $col;?>;box-shadow:0 0 6px <?php echo $col;?>66;"></div><?php echo htmlspecialchars($fs['faction']);?></div>
                    <div class="f-cnt"><?php echo (int)$fs['cnt'];?> membre<?php echo $fs['cnt']>1?'s':'';?></div>
                </div>
                <div class="prog"><div class="prog-f" data-width="<?php echo $pct;?>" style="background:<?php echo $col;?>;"></div></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>

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
                <div class="t-cnt"><?php echo (int)$u['actions'];?> <span style="color:var(--text-secondary);font-weight:400;font-size:.62rem;">actions</span></div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>
</div>

<!-- ══ LIGNE : Panels actifs + Bannis + Sanctions ══ -->
<div class="sd-three">
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-fire-flame-curved"></i> Panels les + actifs (7j)</div></div>
        <div class="card-body">
            <?php if(empty($panelActivity)):?><div class="empty-state"><i class="fa-solid fa-fire-flame-curved"></i><p>Aucune donnée</p></div>
            <?php else: foreach($panelActivity as $pa):
                $w=$maxPanel>0?round(($pa['cnt']/$maxPanel)*100):0;?>
            <div class="pa-row">
                <div class="pa-name"><?php echo htmlspecialchars($pa['panel']);?></div>
                <div class="pa-bar"><div class="pa-fill" data-width="<?php echo $w;?>"></div></div>
                <div class="pa-cnt"><?php echo (int)$pa['cnt'];?></div>
            </div>
            <?php endforeach; endif;?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-ban"></i> Derniers bannis</div>
            <a href="users/bans.php" class="btn btn-secondary btn-sm">Voir tout</a>
        </div>
        <div class="card-body">
            <?php if(empty($recentBans)):?>
                <div class="empty-state"><i class="fa-solid fa-shield-halved" style="color:var(--green)"></i><p>Aucun banni récent</p></div>
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

    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-gavel"></i> Sanctions récentes</div></div>
        <div class="card-body">
            <?php if(empty($lastSanctions)):?>
                <div class="empty-state"><i class="fa-solid fa-gavel"></i><p>Aucune sanction récente</p></div>
            <?php else: foreach($lastSanctions as $s):
                $typeColor=['Avertissement'=>'#f59e0b','Renvoi'=>'#ef4444','Mise à pied'=>'#f97316','Blâme'=>'#8b5cf6'][$s['type']??'']??'#64748b';
                $typeIcon =['Avertissement'=>'triangle-exclamation','Renvoi'=>'user-slash','Mise à pied'=>'pause','Blâme'=>'comment-slash'][$s['type']??'']??'gavel';
            ?>
            <div class="sanc-row">
                <div class="sanc-ico" style="background:<?php echo $typeColor;?>18;color:<?php echo $typeColor;?>;"><i class="fa-solid fa-<?php echo $typeIcon;?>"></i></div>
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

<!-- ══ PROPOSITION D'AJOUT SUR LE MDT ══ -->
<div class="card sd-propose" id="proposeCard">
    <div class="sd-propose-head">
        <div class="sd-propose-head-l">
            <div class="sd-propose-ico"><i class="fa-solid fa-lightbulb"></i></div>
            <div>
                <div class="sd-propose-title">Proposition d'ajout sur le MDT</div>
                <div class="sd-propose-sub">Suggérez une fonctionnalité ou une amélioration — l'équipe de développement est notifiée automatiquement.</div>
            </div>
        </div>
        <?php if ($isProposalAdmin): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('whModal')" title="Configurer la liaison NexioZ">
            <i class="fa-brands fa-discord" style="color:#7289da"></i> Liaison NexioZ
            <?php echo $proposalsLiaisonSet ? '<span class="sd-dot-ok"></span>' : '<span class="sd-dot-off"></span>'; ?>
        </button>
        <?php endif; ?>
    </div>

    <div class="sd-propose-grid">
        <!-- Formulaire -->
        <form id="proposeForm" class="sd-propose-form" autocomplete="off">
            <label class="form-label" for="propContent">Votre proposition</label>
            <textarea id="propContent" class="form-control" rows="6" maxlength="5000" required
                      placeholder="Décrivez la fonctionnalité ou l'amélioration souhaitée, le contexte, l'objectif…"></textarea>
            <div class="sd-count"><span id="propCount">0</span> / 5000</div>

            <label class="form-label" style="margin-top:14px;">Pièces jointes (images)</label>
            <div class="sd-dropzone" id="propDrop">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <div>Glissez vos images ici ou <span>parcourez</span></div>
                <div class="sd-dropzone-hint">PNG, JPG, GIF, WEBP — 5 images max, 8 Mo chacune</div>
                <input type="file" id="propFiles" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
            </div>
            <div class="sd-thumbs" id="propThumbs"></div>

            <button type="submit" class="btn btn-primary sd-submit" id="propSubmit">
                <i class="fa-solid fa-paper-plane"></i> Envoyer la proposition
            </button>
        </form>

        <!-- Historique -->
        <div class="sd-propose-hist">
            <div class="sd-hist-head">
                <div class="card-title" style="font-size:.9rem;"><i class="fa-solid fa-clock-rotate-left"></i> Mes propositions</div>
                <span class="sd-hist-count" id="propHistCount"><?php echo count($proposalsInitial); ?></span>
            </div>
            <div class="sd-hist-list" id="propHist"></div>
        </div>
    </div>
</div>

</div><!-- /.sd -->

<?php if ($isProposalAdmin): ?>
<div class="modal-overlay" id="whModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-brands fa-discord" style="color:#7289da;margin-right:8px;"></i>Liaison NexioZ</h3>
            <button class="modal-close" onclick="closeModal('whModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="form-group">
            <label class="form-label" for="whUrl">Clé unique de liaison NexioZ</label>
            <textarea id="whUrl" class="form-control" rows="3" style="font-family:'DM Mono',monospace;font-size:.78rem;resize:vertical;" placeholder="nxz_…"></textarea>
            <div class="form-hint">
                Générez cette clé dans NexioZ (module <strong>Liaison MDT → Propositions</strong>) puis collez-la ici.
                Chaque proposition sera alors publiée sur votre serveur Discord, et les changements de statut reviendront ici automatiquement.<br>
                Statut actuel : <strong style="color:<?php echo $proposalsLiaisonSet?'var(--green)':'var(--text-muted)';?>"><?php echo $proposalsLiaisonSet?'lié':'non lié';?></strong>.
                Laisser vide pour désactiver.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('whModal')">Annuler</button>
            <button class="btn btn-primary" id="whSave"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Horloge serveur ── */
setInterval(() => {
    const el = document.getElementById('srv-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('fr-FR');
}, 1000);

/* ── Barres de progression (factions + panels) ── */
setTimeout(() => {
    document.querySelectorAll('.prog-f[data-width], .pa-fill[data-width]').forEach(el => {
        el.style.width = el.dataset.width + '%';
    });
}, 150);

/* ══ PROPOSITIONS ══ */
(function () {
    const API      = '<?php echo SITE_URL; ?>/pages/staff/api/proposals.php';
    const MAX_IMG  = 5;
    const MAX_SIZE = 8 * 1024 * 1024;
    let proposals  = <?php echo json_encode($proposalsInitial, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
    let files      = [];

    const form   = document.getElementById('proposeForm');
    const ta     = document.getElementById('propContent');
    const counter= document.getElementById('propCount');
    const drop   = document.getElementById('propDrop');
    const input  = document.getElementById('propFiles');
    const thumbs = document.getElementById('propThumbs');
    const submit = document.getElementById('propSubmit');
    const histEl = document.getElementById('propHist');
    const histCt = document.getElementById('propHistCount');

    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmtDate(s){
        if(!s) return '';
        const d = new Date(s.replace(' ','T'));
        if(isNaN(d)) return esc(s);
        return d.toLocaleDateString('fr-FR',{day:'2-digit',month:'short'}) + ' ' + d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
    }

    /* ── Compteur ── */
    ta && ta.addEventListener('input', () => { counter.textContent = ta.value.length; });

    /* ── Sélection / drag&drop ── */
    drop.addEventListener('click', () => input.click());
    input.addEventListener('change', () => { addFiles(input.files); input.value=''; });
    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => { if(e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });

    function addFiles(list){
        for (const f of list) {
            if (files.length >= MAX_IMG) { toast('5 images maximum', 'warning'); break; }
            if (!/^image\/(png|jpe?g|gif|webp)$/i.test(f.type)) { toast('Format non supporté : '+f.name, 'error'); continue; }
            if (f.size > MAX_SIZE) { toast('Image trop lourde (max 8 Mo) : '+f.name, 'error'); continue; }
            files.push(f);
        }
        renderThumbs();
    }
    function renderThumbs(){
        thumbs.innerHTML = files.map((f,i) => {
            const url = URL.createObjectURL(f);
            return `<div class="sd-thumb"><img src="${url}" alt=""><button type="button" class="sd-thumb-x" data-i="${i}" title="Retirer"><i class="fa-solid fa-xmark"></i></button></div>`;
        }).join('');
        thumbs.querySelectorAll('.sd-thumb-x').forEach(b => b.addEventListener('click', () => { files.splice(+b.dataset.i,1); renderThumbs(); }));
    }

    /* ── Rendu historique ── */
    function renderHist(){
        histCt.textContent = proposals.length;
        if (!proposals.length) {
            histEl.innerHTML = `<div class="sd-hist-empty"><i class="fa-regular fa-lightbulb"></i><p>Aucune proposition envoyée pour le moment.</p></div>`;
            return;
        }
        histEl.innerHTML = proposals.map(p => {
            const imgs = (p.images||[]).map(u => `<a href="${esc(u)}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="" loading="lazy"></a>`).join('');
            const note = p.adminNote ? `<div class="sd-prop-note"><i class="fa-solid fa-reply"></i> ${esc(p.adminNote)}</div>` : '';
            return `<div class="sd-prop">
                <div class="sd-prop-top">
                    <span class="sd-prop-status" style="background:${esc(p.statusColor)}1f;color:${esc(p.statusColor)};border:1px solid ${esc(p.statusColor)}44;">
                        <i class="fa-solid fa-${esc(p.statusIcon)}"></i> ${esc(p.statusLabel)}
                    </span>
                    <span class="sd-prop-date">${fmtDate(p.createdAt)}</span>
                </div>
                <div class="sd-prop-text">${esc(p.content)}</div>
                ${imgs ? `<div class="sd-prop-imgs">${imgs}</div>` : ''}
                ${note}
            </div>`;
        }).join('');
    }
    renderHist();

    /* ── Rafraîchit l'historique (statuts mis à jour depuis Discord) ── */
    async function refreshHist() {
        try {
            const r = await fetch(API + '?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await r.json();
            if (data.ok && Array.isArray(data.proposals)) { proposals = data.proposals; renderHist(); }
        } catch (e) {}
    }
    setInterval(refreshHist, 45000);

    /* ── Envoi ── */
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const content = ta.value.trim();
        if (!content) { toast('Merci de détailler votre proposition.', 'warning'); ta.focus(); return; }

        submit.disabled = true;
        submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Envoi…';

        const fd = new FormData();
        fd.append('action', 'submit');
        fd.append('content', content);
        fd.append('page_url', window.location.href);
        files.forEach(f => fd.append('images[]', f));

        try {
            const r = await fetch(API, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Erreur');
            proposals = data.proposals || proposals;
            renderHist();
            ta.value = ''; counter.textContent = '0';
            files = []; renderThumbs();
            toast('Proposition envoyée à l\'équipe de développement !', 'success');
        } catch (err) {
            toast(err.message || 'Échec de l\'envoi', 'error');
        } finally {
            submit.disabled = false;
            submit.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Envoyer la proposition';
        }
    });

    /* ── Liaison NexioZ (admin) ── */
    const whSave = document.getElementById('whSave');
    if (whSave) {
        whSave.addEventListener('click', async () => {
            const token = (document.getElementById('whUrl').value || '').trim();
            whSave.disabled = true;
            const fd = new FormData();
            fd.append('action', 'set_liaison');
            fd.append('token', token);
            try {
                const r = await fetch(API, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd });
                const data = await r.json();
                if (!data.ok) throw new Error(data.error || 'Erreur');
                toast(token ? 'Liaison NexioZ enregistrée' : 'Liaison désactivée', 'success');
                closeModal('whModal');
            } catch (err) {
                toast(err.message || 'Échec', 'error');
            } finally {
                whSave.disabled = false;
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
