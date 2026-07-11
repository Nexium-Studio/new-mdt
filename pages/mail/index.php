<?php
// pages/mail/index.php — Interface messagerie LysoriaMDT
require_once dirname(__DIR__, 2) . '/config/config.php';
requireLogin();
mdt_require_product('addon_mail', 'Gestionnaire Mail');

$db     = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];

// Charger les comptes de l'utilisateur
$stAccounts = $db->prepare("
    SELECT a.*, (
        SELECT COUNT(*) FROM mail_boxes mb
        WHERE mb.account_id=a.id AND mb.folder='inbox' AND mb.is_read=0 AND mb.deleted_at IS NULL
    ) as unread
    FROM mail_accounts a
    JOIN mail_account_links l ON l.account_id=a.id
    WHERE l.user_id=? AND a.is_active=1
    ORDER BY a.created_at ASC
");
$stAccounts->execute([$userId]);
$accounts = $stAccounts->fetchAll();

// Pas de compte ? proposer la création
$hasAccount = !empty($accounts);
$activeAccount = $accounts[0] ?? null;

// Infos serveur
$serverName = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_server_name'")->fetchColumn() ?: 'lysoria';
$maxAccounts= (int)($db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_max_per_user'")->fetchColumn() ?: 3);
// Récupérer le TLD par défaut défini dans le panel staff
$settings = [];
try {
    $stSet = $db->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'mail_%'");
    foreach ($stSet->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (Exception $e) {}
$defaultTld = $settings['mail_default_tld'] ?? 'fr';

$pageTitle = 'Service Mail — ' . $serverName;
?>
<!DOCTYPE html>
<html lang="fr" data-mail-theme="<?php echo htmlspecialchars($activeAccount['theme'] ?? 'dark'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════
   LYSORIA MAIL — Thèmes & variables
   ════════════════════════════════════════════════════════ */
:root {
    --font-head:  'Syne',    sans-serif;
    --font-body:  'DM Sans', sans-serif;
    --font-mono:  'DM Mono', monospace;
    --radius:     12px;
    --transition: .18s ease;
}

/* ── Thème dark (défaut) ── */
[data-mail-theme="dark"] {
    --bg:           #080f1e;
    --bg2:          #0d1526;
    --bg3:          #111e35;
    --bg4:          #162040;
    --sidebar-bg:   #090f1f;
    --compose-bg:   #0d1526;
    --border:       rgba(59,130,246,.12);
    --border2:      rgba(59,130,246,.22);
    --text:         #e8f0fe;
    --text2:        #8ba4d4;
    --text3:        #4a6a9e;
    --accent:       #3b82f6;
    --accent-h:     #2563eb;
    --accent-dim:   rgba(59,130,246,.15);
    --read-bg:      #090f1f;
    --unread-bg:    #0e1830;
    --unread-border:#1e3a6e;
    --hover:        rgba(59,130,246,.07);
    --selected:     rgba(59,130,246,.14);
    --danger:       #ef4444;
    --success:      #22c55e;
    --warning:      #f59e0b;
    --star:         #f59e0b;
    --shadow:       0 4px 24px rgba(0,0,0,.5);
    --input-bg:     rgba(255,255,255,.04);
}

/* ── Thème light ── */
[data-mail-theme="light"] {
    --bg:           #f1f5f9;
    --bg2:          #ffffff;
    --bg3:          #f8fafc;
    --bg4:          #e2e8f0;
    --sidebar-bg:   #f8fafc;
    --compose-bg:   #ffffff;
    --border:       #e2e8f0;
    --border2:      #cbd5e1;
    --text:         #0f172a;
    --text2:        #475569;
    --text3:        #94a3b8;
    --accent:       #2563eb;
    --accent-h:     #1d4ed8;
    --accent-dim:   rgba(37,99,235,.1);
    --read-bg:      #ffffff;
    --unread-bg:    #eff6ff;
    --unread-border:#bfdbfe;
    --hover:        #f1f5f9;
    --selected:     #dbeafe;
    --danger:       #dc2626;
    --success:      #16a34a;
    --warning:      #d97706;
    --star:         #d97706;
    --shadow:       0 2px 12px rgba(0,0,0,.1);
    --input-bg:     #f8fafc;
}

/* ── Thème midnight ── */
[data-mail-theme="midnight"] {
    --bg:           #020408;
    --bg2:          #05080f;
    --bg3:          #080d18;
    --bg4:          #0c1220;
    --sidebar-bg:   #03060e;
    --compose-bg:   #05080f;
    --border:       rgba(139,92,246,.12);
    --border2:      rgba(139,92,246,.25);
    --text:         #e8e0ff;
    --text2:        #9d8dcc;
    --text3:        #5b4f8a;
    --accent:       #8b5cf6;
    --accent-h:     #7c3aed;
    --accent-dim:   rgba(139,92,246,.15);
    --read-bg:      #03060e;
    --unread-bg:    #0c0a1f;
    --unread-border:#2d1f6e;
    --hover:        rgba(139,92,246,.07);
    --selected:     rgba(139,92,246,.14);
    --danger:       #ef4444;
    --success:      #22c55e;
    --warning:      #f59e0b;
    --star:         #f59e0b;
    --shadow:       0 4px 24px rgba(0,0,0,.7);
    --input-bg:     rgba(255,255,255,.04);
}

/* ── Thème ocean ── */
[data-mail-theme="ocean"] {
    --bg:           #011520;
    --bg2:          #031e2e;
    --bg3:          #052638;
    --bg4:          #083048;
    --sidebar-bg:   #021929;
    --compose-bg:   #031e2e;
    --border:       rgba(6,182,212,.14);
    --border2:      rgba(6,182,212,.28);
    --text:         #cffafe;
    --text2:        #67c8d4;
    --text3:        #2e7d86;
    --accent:       #06b6d4;
    --accent-h:     #0891b2;
    --accent-dim:   rgba(6,182,212,.15);
    --read-bg:      #021929;
    --unread-bg:    #042538;
    --unread-border:#0e5068;
    --hover:        rgba(6,182,212,.08);
    --selected:     rgba(6,182,212,.16);
    --danger:       #f43f5e;
    --success:      #22c55e;
    --warning:      #f59e0b;
    --star:         #f59e0b;
    --shadow:       0 4px 24px rgba(0,0,0,.6);
    --input-bg:     rgba(255,255,255,.04);
}

/* ── Thème sunset ── */
[data-mail-theme="sunset"] {
    --bg:           #150806;
    --bg2:          #1e0c08;
    --bg3:          #271208;
    --bg4:          #32180b;
    --sidebar-bg:   #180904;
    --compose-bg:   #1e0c08;
    --border:       rgba(249,115,22,.14);
    --border2:      rgba(249,115,22,.28);
    --text:         #fff1e6;
    --text2:        #d9956a;
    --text3:        #85502e;
    --accent:       #f97316;
    --accent-h:     #ea6b00;
    --accent-dim:   rgba(249,115,22,.15);
    --read-bg:      #180904;
    --unread-bg:    #221008;
    --unread-border:#6b2a0c;
    --hover:        rgba(249,115,22,.08);
    --selected:     rgba(249,115,22,.15);
    --danger:       #ef4444;
    --success:      #22c55e;
    --warning:      #f59e0b;
    --star:         #f59e0b;
    --shadow:       0 4px 24px rgba(0,0,0,.6);
    --input-bg:     rgba(255,255,255,.04);
}

/* ════ BASE ════ */
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; overflow:hidden; }
body {
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
}
a { color: var(--accent); text-decoration:none; }
input, textarea, select, button { font-family: var(--font-body); }
::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border2); border-radius:99px; }

/* ════ LAYOUT ════ */
.mail-app {
    display: grid;
    grid-template-columns: 220px 1fr;
    grid-template-rows: 56px 1fr;
    height: 100vh;
    overflow: hidden;
}

/* ─ Topbar ─ */
.mail-topbar {
    grid-column: 1 / -1;
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px;
    padding: 0 16px 0 0;
    height: 56px;
    z-index: 100;
}
.mail-logo {
    width: 220px; flex-shrink: 0;
    display: flex; align-items: center; gap: 10px;
    padding: 0 16px;
    border-right: 1px solid var(--border);
    height: 100%;
    font-family: var(--font-head);
    font-weight: 800; font-size: 1.05rem;
    color: var(--text);
}
.mail-logo i { color: var(--accent); font-size:1.15rem; }
.mail-logo span.dot { color: var(--accent); }
.mail-search-wrap { flex: 1; padding: 0 8px; max-width: 480px; position: relative; }
.mail-search {
    width: 100%; padding: 8px 16px 8px 38px;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 99px; color: var(--text); font-size:.85rem;
    transition: border-color var(--transition), background var(--transition);
    outline: none;
}
.mail-search:focus { border-color: var(--accent); background: var(--bg3); }
.mail-search-wrap i { position:absolute; left:22px; top:50%; transform:translateY(-50%); color:var(--text3); font-size:.8rem; }
.topbar-right { display:flex; align-items:center; gap:8px; margin-left:auto; }
.icon-btn {
    width:34px; height:34px; border-radius:9px; border:1px solid var(--border);
    background:var(--input-bg); color:var(--text2); display:flex; align-items:center;
    justify-content:center; cursor:pointer; transition:all var(--transition); font-size:.85rem;
    position:relative;
}
.icon-btn:hover { background:var(--hover); border-color:var(--border2); color:var(--text); }
.icon-btn .badge {
    position:absolute; top:-4px; right:-4px; background:var(--danger);
    color:#fff; font-size:.55rem; font-weight:700; border-radius:99px;
    min-width:16px; height:16px; padding:0 3px;
    display:flex; align-items:center; justify-content:center;
}
.account-switcher {
    display:flex; align-items:center; gap:8px;
    padding:4px 10px; border-radius:99px;
    border:1px solid var(--border); background:var(--input-bg);
    cursor:pointer; font-size:.8rem; color:var(--text2);
    transition:all var(--transition); max-width:180px;
}
.account-switcher:hover { border-color:var(--border2); color:var(--text); }
.account-dot {
    width:8px; height:8px; border-radius:50%; background:var(--accent);
    flex-shrink:0; animation:apulse 3s infinite;
}
@keyframes apulse{0%,100%{opacity:1}50%{opacity:.5}}
.account-addr { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.topbar-link { font-size:.78rem; color:var(--text2); padding:6px 10px; border-radius:8px; transition:all var(--transition); white-space:nowrap; }
.topbar-link:hover { background:var(--hover); color:var(--text); }
.topbar-link i { margin-right:5px; }

/* ─ Sidebar ─ */
.mail-sidebar {
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border);
    overflow-y: auto; overflow-x: hidden;
    padding: 12px 0 20px;
    display: flex; flex-direction:column; gap:4px;
}
.compose-btn {
    display:flex; align-items:center; gap:9px;
    margin: 0 10px 12px;
    padding: 10px 16px;
    background: var(--accent);
    color: #fff; border:none; border-radius: 12px;
    font-size:.85rem; font-weight:700; cursor:pointer;
    transition: background var(--transition), box-shadow var(--transition), transform var(--transition);
    box-shadow: 0 4px 14px color-mix(in srgb, var(--accent) 40%, transparent);
}
.compose-btn:hover { background:var(--accent-h); transform:translateY(-1px); box-shadow:0 6px 18px color-mix(in srgb,var(--accent) 50%,transparent); }
.compose-btn i { font-size:.9rem; }

.sidebar-section { font-size:.62rem; font-weight:700; letter-spacing:1.8px; text-transform:uppercase; color:var(--text3); padding:10px 18px 4px; }

.nav-item {
    display:flex; align-items:center; gap:10px;
    padding:8px 18px; font-size:.82rem; color:var(--text2);
    cursor:pointer; border-radius:0; transition:all var(--transition);
    border-left:2px solid transparent; position:relative;
}
.nav-item:hover { background:var(--hover); color:var(--text); }
.nav-item.active { background:var(--accent-dim); color:var(--accent); border-left-color:var(--accent); font-weight:600; }
.nav-item i { width:16px; text-align:center; flex-shrink:0; font-size:.82rem; }
.nav-item .count {
    margin-left:auto; font-size:.63rem; font-weight:700;
    background:var(--accent); color:#fff;
    padding:1px 6px; border-radius:99px; min-width:18px; text-align:center;
}
.nav-item .count.muted { background:var(--bg4); color:var(--text3); }

.account-list { margin-top:8px; }
.account-item {
    display:flex; align-items:center; gap:9px;
    padding:7px 18px; cursor:pointer; font-size:.78rem;
    color:var(--text3); transition:all var(--transition); border-radius:0;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.account-item:hover { background:var(--hover); color:var(--text); }
.account-item.active { color:var(--accent); }
.account-item .a-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.account-item .a-unread { margin-left:auto; font-size:.6rem; font-weight:700; color:var(--accent); }

/* ─ Zone principale ─ */
.mail-main {
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: var(--bg);
}

/* ─ Toolbar messages ─ */
.msg-toolbar {
    display: flex; align-items: center; gap:8px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--bg2);
    flex-shrink: 0;
}
.msg-toolbar .folder-title { font-weight:700; font-size:.9rem; margin-right:4px; }
.toolbar-btn {
    display:flex; align-items:center; gap:6px;
    padding:5px 10px; border-radius:8px; border:1px solid var(--border);
    background:var(--input-bg); color:var(--text2); font-size:.75rem; cursor:pointer;
    transition:all var(--transition);
}
.toolbar-btn:hover { background:var(--hover); color:var(--text); }
.toolbar-sep { width:1px; height:20px; background:var(--border); }
.check-all { width:16px; height:16px; border:1.5px solid var(--border2); border-radius:4px; cursor:pointer; accent-color:var(--accent); }
.pagination { margin-left:auto; display:flex; align-items:center; gap:6px; font-size:.75rem; color:var(--text3); }
.page-btn { padding:4px 8px; border-radius:6px; border:1px solid var(--border); background:transparent; color:var(--text2); cursor:pointer; transition:all var(--transition); font-size:.72rem; }
.page-btn:hover { background:var(--hover); }
.page-btn:disabled { opacity:.35; cursor:default; }

/* ─ Liste messages ─ */
.msg-list { overflow-y:auto; flex:1; }
.msg-row {
    display:grid;
    grid-template-columns: 16px 16px 26px 150px 1fr auto;
    gap:0 8px; align-items:center;
    padding:8px 14px 8px 18px;
    border-bottom:1px solid var(--border);
    cursor:pointer; transition:background var(--transition);
    background: var(--read-bg);
    position: relative;
    min-height: 42px;
}
.msg-row.unread { background:var(--unread-bg); border-left:2px solid var(--unread-border); }
.msg-row:hover  { background:var(--hover); }
.msg-row.selected { background:var(--selected); }
.msg-row.unread .msg-from,
.msg-row.unread .msg-subject { font-weight:700; color:var(--text); }

.msg-check { width:14px; height:14px; accent-color:var(--accent); cursor:pointer; }
.msg-star { background:none; border:none; color:var(--text3); cursor:pointer; font-size:.78rem; padding:0; line-height:1; transition:color var(--transition); }
.msg-star.starred { color:var(--star); }
.msg-star:hover { color:var(--star); }
.msg-from { font-size:.8rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--text2); }
.msg-sender-ava {
    width:26px; height:26px; border-radius:7px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:.65rem; color:#fff;
    background:var(--accent); overflow:hidden; line-height:1;
}
.msg-sender-ava img { width:100%; height:100%; object-fit:cover; display:block; }
.notif-ava { width:34px; height:34px; border-radius:9px; background:var(--accent); flex-shrink:0; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; font-size:.8rem; overflow:hidden; }
.notif-ava img { width:100%; height:100%; object-fit:cover; display:block; }
.msg-subject { font-size:.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--text2); }
.msg-subject .preview { color:var(--text3); font-weight:400; }
.msg-date { font-size:.72rem; color:var(--text3); white-space:nowrap; font-family:var(--font-mono); }
.unread-dot {
    position:absolute; left:4px; top:50%; transform:translateY(-50%);
    width:6px; height:6px; border-radius:50%; background:var(--accent);
    display:none;
}
.msg-row.unread .unread-dot { display:block; }

/* ─ Vue message ─ */
.msg-view {
    overflow-y:auto; flex:1;
    padding:0 24px 32px;
    display:none;
}
.msg-view.active { display:block; }
.msg-view-header { padding:20px 0 16px; border-bottom:1px solid var(--border); margin-bottom:20px; }
.msg-view-subject { font-family:var(--font-head); font-size:1.3rem; font-weight:800; margin-bottom:12px; }
.msg-view-meta { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; }
.msg-view-avatar {
    width:40px; height:40px; border-radius:11px; background:var(--accent); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.95rem; color:#fff;
    overflow:hidden;
}
.msg-view-avatar img { width:100%; height:100%; object-fit:cover; display:block; border-radius:11px; }
.msg-view-from { flex:1; min-width:0; }
.msg-view-from .from-name { font-weight:700; font-size:.9rem; }
.msg-view-from .from-addr { font-size:.75rem; color:var(--text3); font-family:var(--font-mono); }
.msg-view-from .to-line   { font-size:.75rem; color:var(--text2); margin-top:3px; }
.msg-view-actions { display:flex; gap:8px; flex-wrap:wrap; }
.action-btn {
    display:flex; align-items:center; gap:6px;
    padding:6px 12px; border-radius:8px; border:1px solid var(--border);
    background:var(--input-bg); color:var(--text2); font-size:.75rem;
    cursor:pointer; transition:all var(--transition); font-family:var(--font-body);
}
.action-btn:hover { background:var(--hover); color:var(--text); border-color:var(--border2); }
.action-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.action-btn.primary:hover { background:var(--accent-h); }
.action-btn.danger { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.3); color:var(--danger); }
.action-btn.danger:hover { background:rgba(239,68,68,.2); }
.msg-view-date { font-size:.72rem; color:var(--text3); font-family:var(--font-mono); white-space:nowrap; }

.msg-body {
    background: var(--bg2); border:1px solid var(--border);
    border-radius:var(--radius); padding:24px;
    font-size:.9rem; line-height:1.8; color:var(--text2);
}
.msg-body a { color:var(--accent); }

.thread-section { margin-top:16px; }
.thread-toggle {
    display:flex; align-items:center; gap:8px; padding:8px 0;
    font-size:.78rem; color:var(--text3); cursor:pointer;
}
.thread-msg {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:var(--radius); padding:16px; margin-bottom:8px;
}

/* ─ Modale composer ─ */
.compose-overlay {
    position:fixed; inset:0; z-index:999;
    display:none; pointer-events:none;
}
.compose-overlay.open { display:block; }
.compose-modal {
    position:fixed;
    width:580px; min-width:320px; min-height:300px;
    background:var(--compose-bg);
    border:1px solid var(--border2); border-radius:16px;
    box-shadow: 0 16px 64px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.3);
    display:flex; flex-direction:column;
    overflow:hidden;
    pointer-events:all;
    resize:both;
    /* Taille max = viewport */
    max-width:calc(100vw - 24px);
    max-height:calc(100vh - 24px);
    /* Position initiale: bas-droite */
    bottom:16px; right:16px;
    top:auto; left:auto;
    animation: composeIn .2s ease;
    transition: box-shadow .15s;
}
.compose-modal.dragging {
    box-shadow: 0 24px 80px rgba(0,0,0,.65), 0 4px 16px rgba(0,0,0,.4);
    transition: none;
    user-select:none;
}
.compose-modal.is-maximized {
    width:calc(100vw - 24px) !important;
    height:calc(100vh - 24px) !important;
    top:12px !important; left:12px !important;
    bottom:auto; right:auto;
    border-radius:16px;
    resize:none;
}
@keyframes composeIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
.compose-titlebar {
    display:flex; align-items:center; gap:8px;
    padding:10px 14px; background:var(--bg4);
    border-bottom:1px solid var(--border);
    font-weight:700; font-size:.85rem;
    cursor:grab; flex-shrink:0;
    user-select:none;
}
.compose-titlebar:active { cursor:grabbing; }
.compose-titlebar i { color:var(--accent); pointer-events:none; }
.compose-titlebar .title-actions { margin-left:auto; display:flex; gap:4px; }
.compose-titlebar .close-btn {
    width:24px; height:24px; border-radius:6px;
    background:none; border:none; color:var(--text3); cursor:pointer;
    display:flex; align-items:center; justify-content:center; font-size:.78rem;
    transition:all var(--transition); flex-shrink:0;
}
.compose-titlebar .close-btn:hover { background:var(--hover); color:var(--text); }
.compose-titlebar .close-btn.close-x:hover { background:rgba(239,68,68,.15); color:#ef4444; }
/* Scrollable inner content */
.compose-fields {
    display:flex; flex-direction:column;
    flex-shrink:0;
}
.compose-body-wrap {
    display:flex; flex-direction:column;
    flex-shrink:0;
    min-height:180px;
}
.compose-editor {
    padding:12px 16px; outline:none; color:var(--text2);
    font-size:.88rem; line-height:1.7;
    min-height:180px;
    /* Pas de overflow ici — c'est composeContent qui scroll */
}
/* Resize handle hint */
.compose-modal::after {
    content:''; position:absolute; bottom:3px; right:3px;
    width:10px; height:10px;
    border-right:2px solid var(--border2); border-bottom:2px solid var(--border2);
    border-radius:0 0 4px 0; pointer-events:none; opacity:.5;
}
.compose-field {
    display:flex; align-items:flex-start; gap:8px;
    padding:10px 16px; border-bottom:1px solid var(--border);
}
.compose-field label { font-size:.75rem; color:var(--text3); width:28px; flex-shrink:0; margin-top:1px; }
.compose-field input, .compose-subject input {
    flex:1; background:none; border:none; outline:none;
    color:var(--text); font-size:.85rem; font-family:var(--font-body);
}
.compose-field input::placeholder { color:var(--text3); }
.compose-cc-row { display:flex; gap:6px; align-items:center; flex:1; flex-wrap:wrap; }
.tag-input {
    display:flex; flex-wrap:wrap; gap:5px; flex:1;
    min-height:28px; align-items:center;
}
.tag { display:flex; align-items:center; gap:5px; background:var(--accent-dim); color:var(--accent); border:1px solid color-mix(in srgb,var(--accent) 30%,transparent); border-radius:6px; padding:2px 8px; font-size:.72rem; }
.tag .rm { cursor:pointer; opacity:.6; } .tag .rm:hover { opacity:1; }
.tag-text-input { background:none; border:none; outline:none; color:var(--text); font-size:.82rem; min-width:80px; }
.compose-subject { padding:10px 16px; border-bottom:1px solid var(--border); }
.compose-subject input { width:100%; background:none; border:none; outline:none; color:var(--text); font-size:.9rem; font-weight:600; }
.compose-toolbar-fmt {
    display:flex; gap:2px; padding:8px 12px; border-bottom:1px solid var(--border);
    background:var(--bg3);
}
.fmt-btn {
    width:28px; height:28px; border-radius:6px; background:none; border:none;
    color:var(--text3); cursor:pointer; font-size:.78rem; transition:all var(--transition);
    display:flex; align-items:center; justify-content:center;
}
.fmt-btn:hover { background:var(--hover); color:var(--text); }
.fmt-sep { width:1px; height:18px; background:var(--border); margin:0 3px; flex-shrink:0; }

/* ─ Image insert popover ─ */
.img-popover {
    display:none; position:fixed;
    background:var(--bg3); border:1px solid var(--border2);
    border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,.5);
    padding:14px; z-index:1100; width:300px;
    animation: popIn .15s ease;
}
@keyframes popIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
.img-popover.open { display:block; }
.img-popover-title { font-size:.72rem; font-weight:700; color:var(--text3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
.img-popover-row { display:flex; gap:6px; }
.img-popover input {
    flex:1; padding:7px 10px; background:var(--input-bg);
    border:1px solid var(--border); border-radius:8px;
    color:var(--text); font-size:.8rem; outline:none;
    transition:border-color .15s;
}
.img-popover input:focus { border-color:var(--accent); }
.img-popover-confirm {
    padding:7px 12px; background:var(--accent); color:#fff;
    border:none; border-radius:8px; font-size:.78rem; font-weight:700;
    cursor:pointer; white-space:nowrap; transition:background .15s;
}
.img-popover-confirm:hover { background:var(--accent-h); }
.img-popover-divider { text-align:center; font-size:.7rem; color:var(--text3); margin:10px 0; position:relative; }
.img-popover-divider::before {
    content:''; position:absolute; left:0; top:50%;
    width:42%; height:1px; background:var(--border);
}
.img-popover-divider::after {
    content:''; position:absolute; right:0; top:50%;
    width:42%; height:1px; background:var(--border);
}
.img-upload-btn {
    width:100%; padding:8px; border:1px dashed var(--border2); border-radius:8px;
    background:var(--input-bg); color:var(--text2); font-size:.8rem;
    cursor:pointer; transition:all .15s; display:flex; align-items:center; justify-content:center; gap:7px;
}
.img-upload-btn:hover { border-color:var(--accent); color:var(--accent); background:color-mix(in srgb,var(--accent) 6%,transparent); }

/* ─ Image dans le compose body ─ */
.compose-editor img {
    max-width:100%; height:auto; border-radius:8px;
    display:block; margin:6px 0; cursor:pointer;
    outline:2px solid transparent; transition:outline .15s;
}
.compose-editor img:hover { outline:2px solid var(--accent); }
.compose-editor img.selected-img { outline:2px solid var(--accent); }
.compose-editor img.img-inline { display:inline; margin:0 3px; border-radius:4px; max-height:1.4em; width:auto; vertical-align:middle; }
.img-resize-bar {
    display:none; gap:6px; align-items:center; padding:6px 12px;
    background:var(--bg3); border-top:1px solid var(--border);
    font-size:.72rem; color:var(--text3);
}
.img-resize-bar.show { display:flex; }
.img-size-btn {
    padding:3px 8px; border-radius:5px; background:var(--hover);
    border:1px solid var(--border); color:var(--text2); font-size:.7rem;
    cursor:pointer; transition:all .15s;
}
.img-size-btn:hover { border-color:var(--accent); color:var(--accent); }
.img-remove-btn { margin-left:auto; color:#ef4444; cursor:pointer; font-size:.72rem; background:none; border:none; padding:3px 6px; border-radius:5px; }
.img-remove-btn:hover { background:rgba(239,68,68,.1); }
.compose-editor {
    flex:1; padding:12px 16px; outline:none; color:var(--text2);
    font-size:.88rem; line-height:1.7; overflow-y:auto;
    min-height:160px;
}
.compose-editor[data-placeholder]:empty::before { content:attr(data-placeholder); color:var(--text3); }
.compose-footer {
    display:flex; align-items:center; gap:8px; padding:10px 16px;
    border-top:1px solid var(--border); background:var(--bg3);
}
.send-btn {
    display:flex; align-items:center; gap:7px; padding:8px 18px;
    background:var(--accent); color:#fff; border:none; border-radius:9px;
    font-size:.83rem; font-weight:700; cursor:pointer;
    transition:all var(--transition);
    box-shadow: 0 2px 10px color-mix(in srgb,var(--accent) 35%,transparent);
}
.send-btn:hover { background:var(--accent-h); transform:translateY(-1px); }
.draft-btn { background:none; border:1px solid var(--border); color:var(--text3); padding:7px 14px; border-radius:8px; font-size:.78rem; cursor:pointer; transition:all var(--transition); }
.draft-btn:hover { background:var(--hover); color:var(--text); }
.compose-footer-right { margin-left:auto; display:flex; gap:6px; }
.compose-from-sel { background:var(--input-bg); border:1px solid var(--border); color:var(--text2); border-radius:7px; padding:4px 8px; font-size:.75rem; outline:none; cursor:pointer; }

/* ─ Autocomplete ─ */
.autocomplete-list {
    position:absolute; background:var(--bg3); border:1px solid var(--border2);
    border-radius:10px; box-shadow:var(--shadow); z-index:1000; min-width:240px;
    overflow:hidden; top:100%; left:0; margin-top:4px;
    display:none;
}
.autocomplete-list.open { display:block; }
.ac-item { padding:9px 14px; cursor:pointer; font-size:.82rem; transition:background var(--transition); }
.ac-item:hover { background:var(--hover); }
.ac-addr { color:var(--text3); font-family:var(--font-mono); font-size:.72rem; }

/* ─ Panneaux latéraux modaux ─ */
.side-panel {
    position:fixed; inset:0; z-index:998; display:none;
}
.side-panel.open { display:block; }
.side-panel-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.5); }
.side-panel-content {
    position:absolute; right:0; top:0; bottom:0; width:min(400px,100%);
    background:var(--compose-bg); border-left:1px solid var(--border2);
    box-shadow:var(--shadow); overflow-y:auto; padding:24px;
    animation: slideIn .2s ease;
}
@keyframes slideIn { from{transform:translateX(100%)} }
.side-panel-title { font-family:var(--font-head); font-weight:800; font-size:1.1rem; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
.side-panel-title i { color:var(--accent); }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:.75rem; font-weight:600; color:var(--text2); margin-bottom:5px; }
.form-control {
    width:100%; padding:9px 12px; background:var(--input-bg); border:1px solid var(--border);
    border-radius:9px; color:var(--text); font-size:.85rem; outline:none;
    transition:border-color var(--transition);
}
.form-control:focus { border-color:var(--accent); }
.form-control option { background:var(--bg2); }
.btn-primary { padding:9px 18px; background:var(--accent); color:#fff; border:none; border-radius:9px; font-size:.85rem; font-weight:700; cursor:pointer; transition:all var(--transition); width:100%; }
.btn-primary:hover { background:var(--accent-h); }
.btn-danger  { padding:7px 14px; background:rgba(239,68,68,.1); color:var(--danger); border:1px solid rgba(239,68,68,.3); border-radius:8px; font-size:.78rem; cursor:pointer; transition:all var(--transition); }
.btn-danger:hover { background:rgba(239,68,68,.2); }
.btn-secondary { padding:7px 14px; background:var(--input-bg); color:var(--text2); border:1px solid var(--border); border-radius:8px; font-size:.78rem; cursor:pointer; transition:all var(--transition); }
.btn-secondary:hover { background:var(--hover); color:var(--text); }
.add-tab { flex:1; padding:7px 10px; border:none; background:none; color:var(--text3); border-radius:8px; cursor:pointer; font-size:.8rem; font-weight:600; transition:all var(--transition); display:flex; align-items:center; justify-content:center; gap:6px; font-family:var(--font-body); }
.add-tab:hover { color:var(--text); }
.add-tab.active { background:var(--bg4); color:var(--text); box-shadow:0 1px 4px rgba(0,0,0,.3); }

/* ─ Thèmes sélecteur ─ */
.theme-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:8px; margin-bottom:16px; }
.theme-opt {
    border-radius:10px; overflow:hidden; cursor:pointer;
    border:2px solid transparent; transition:all var(--transition);
    aspect-ratio:1;
}
.theme-opt:hover { transform:scale(1.05); }
.theme-opt.active { border-color:var(--accent); box-shadow:0 0 10px color-mix(in srgb,var(--accent) 40%,transparent); }
.theme-opt .preview-swatch { width:100%; height:100%; display:flex; flex-direction:column; }
.theme-opt .sw-top { flex:1; }
.theme-opt .sw-bot { height:8px; }
.theme-opt label { display:block; font-size:.6rem; text-align:center; margin-top:5px; color:var(--text2); cursor:pointer; }

/* ─ Toast ─ */
.toast-wrap { position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast {
    display:flex; align-items:center; gap:10px;
    padding:12px 16px; border-radius:12px;
    background:var(--bg3); border:1px solid var(--border2);
    box-shadow:var(--shadow); font-size:.82rem; color:var(--text);
    animation:toastIn .2s ease;
    min-width:240px;
}
@keyframes toastIn { from{opacity:0;transform:translateY(10px)} }
.toast.success i { color:var(--success); }
.toast.error   i { color:var(--danger); }
.toast.info    i { color:var(--accent); }

/* ─ Écran vide ─ */
.empty-screen {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:16px;
    color:var(--text3); text-align:center; padding:40px;
}
.empty-screen i { font-size:3rem; opacity:.3; }
.empty-screen h3 { font-size:1.1rem; font-weight:700; color:var(--text2); }
.empty-screen p { font-size:.82rem; max-width:300px; line-height:1.6; }

/* ─ État pas de compte ─ */
.setup-screen {
    flex:1; display:flex; align-items:center; justify-content:center; padding:40px;
}
.setup-card {
    background:var(--bg2); border:1px solid var(--border2);
    border-radius:20px; padding:40px; max-width:440px; width:100%; text-align:center;
    box-shadow:var(--shadow);
}
.setup-card .setup-icon { font-size:3rem; color:var(--accent); margin-bottom:16px; }
.setup-card h2 { font-family:var(--font-head); font-size:1.4rem; font-weight:800; margin-bottom:8px; }
.setup-card p { color:var(--text2); font-size:.88rem; margin-bottom:24px; line-height:1.6; }
.address-preview {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:10px; padding:10px 14px; font-family:var(--font-mono);
    font-size:.85rem; color:var(--accent); margin:8px 0 16px; text-align:left;
    min-height:36px;
}


/* ─ Highlight non-lu dans topbar ─ */
.notif-dropdown {
    position:absolute; top:calc(100% + 8px); right:0;
    width:320px; background:var(--bg3); border:1px solid var(--border2);
    border-radius:14px; box-shadow:var(--shadow); z-index:500;
    overflow:hidden; display:none;
}
.notif-dropdown.open { display:block; }
.notif-header { padding:12px 16px; font-weight:700; font-size:.82rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.notif-item { display:flex; gap:10px; padding:10px 14px; border-bottom:1px solid var(--border); cursor:pointer; transition:background var(--transition); font-size:.78rem; }
.notif-item:hover { background:var(--hover); }
.notif-item:last-child { border-bottom:none; }
.notif-ava { width:30px; height:30px; border-radius:8px; background:var(--accent); display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; color:#fff; flex-shrink:0; }
.notif-body { min-width:0; }
.notif-from { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.notif-sub  { color:var(--text3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
</style>
</head>
<body>
<div class="mail-app" id="mailApp">

<?php if (!$hasAccount): ?>
<!-- ════ PAS DE COMPTE — SETUP ════ -->
<div class="mail-topbar">
    <div class="mail-logo"><i class="fa-solid fa-envelope-open-text"></i> Lysoria<span class="dot">Mail</span></div>
    <div class="topbar-right">
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="topbar-link"><i class="fa-solid fa-arrow-left"></i> Retour au MDT</a>
    </div>
</div>
<div style="grid-column:1/-1; display:flex;">
<div class="setup-screen">
    <div class="setup-card">
        <div class="setup-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
        <h2>Créez votre adresse mail</h2>
        <p>Bienvenue sur LysoriaMail. Choisissez votre adresse pour commencer à envoyer et recevoir des messages sur le serveur.</p>
        <input type="text" class="form-control" id="setup-local" placeholder="votre.nom" style="margin-bottom:8px;" oninput="updatePreview()">
        <div class="address-preview" id="setup-preview">votre.nom@<?php echo $serverName; ?>.<?php echo htmlspecialchars($settings['mail_default_tld'] ?? 'fr'); ?></div>
        <button class="btn-primary" onclick="createAccount()"><i class="fa-solid fa-plus"></i> Créer mon adresse</button>
    </div>
</div>
</div>

<?php else: ?>
<!-- ════ APP PRINCIPALE ════ -->
<div class="mail-topbar">
    <div class="mail-logo"><i class="fa-solid fa-envelope-open-text"></i> Lysoria<span class="dot">Mail</span></div>

    <div class="mail-search-wrap">
        <i class="fa-solid fa-search"></i>
        <input type="text" class="mail-search" placeholder="Rechercher dans les mails…" id="searchInput" oninput="debounceSearch()">
    </div>

    <div class="topbar-right">
        <!-- Sélecteur de compte actif -->
        <div class="account-switcher" onclick="togglePanel('accountsPanel')">
            <span class="account-dot"></span>
            <span class="account-addr" id="activeAccountLabel"><?php echo htmlspecialchars($activeAccount['address']); ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size:.6rem;opacity:.5;"></i>
        </div>

        <!-- Notifications -->
        <div style="position:relative;">
            <div class="icon-btn" id="notifBtn" onclick="toggleNotif()">
                <i class="fa-solid fa-bell"></i>
                <span class="badge" id="notifBadge" style="display:none;">0</span>
            </div>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">Notifications <button onclick="markNotifSeen()" style="background:none;border:none;color:var(--accent);font-size:.72rem;cursor:pointer;">Tout marquer vu</button></div>
                <div id="notifList"></div>
            </div>
        </div>

        <!-- Paramètres -->
        <div class="icon-btn" onclick="togglePanel('settingsPanel')" title="Paramètres">
            <i class="fa-solid fa-gear"></i>
        </div>

        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="topbar-link"><i class="fa-solid fa-arrow-left"></i> MDT</a>
    </div>
</div>

<!-- ── Sidebar ── -->
<div class="mail-sidebar" id="sidebar">
    <button class="compose-btn" onclick="openCompose()">
        <i class="fa-solid fa-pen-to-square"></i> Nouveau message
    </button>

    <div class="sidebar-section">Dossiers</div>
    <div class="nav-item active" data-folder="inbox"   onclick="switchFolder('inbox')">
        <i class="fa-solid fa-inbox"></i> Boîte de réception
        <span class="count" id="cnt-inbox" style="display:none;"></span>
    </div>
    <div class="nav-item" data-folder="starred" onclick="switchFolder('starred')">
        <i class="fa-solid fa-star"></i> Suivis
        <span class="count muted" id="cnt-starred" style="display:none;"></span>
    </div>
    <div class="nav-item" data-folder="sent" onclick="switchFolder('sent')">
        <i class="fa-solid fa-paper-plane"></i> Envoyés
    </div>
    <div class="nav-item" data-folder="drafts" onclick="switchFolder('drafts')">
        <i class="fa-solid fa-file-lines"></i> Brouillons
        <span class="count muted" id="cnt-drafts" style="display:none;"></span>
    </div>
    <div class="nav-item" data-folder="spam" onclick="switchFolder('spam')">
        <i class="fa-solid fa-shield-halved"></i> Spam
    </div>
    <div class="nav-item" data-folder="trash" onclick="switchFolder('trash')">
        <i class="fa-solid fa-trash"></i> Corbeille
    </div>

    <div class="sidebar-section" style="margin-top:8px;">Comptes</div>
    <div class="account-list" id="accountList">
        <?php foreach($accounts as $acc): ?>
        <div class="account-item <?php echo $acc['id']===$activeAccount['id']?'active':''; ?>"
             onclick="switchAccount(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars($acc['address']); ?>')"
             data-id="<?php echo $acc['id']; ?>">
            <span class="a-dot" style="background:<?php echo sprintf('#%06X',crc32($acc['address'])&0xFFFFFF); ?>;"></span>
            <?php echo htmlspecialchars($acc['address']); ?>
            <?php if($acc['unread']>0): ?><span class="a-unread"><?php echo $acc['unread']; ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if(count($accounts) < $maxAccounts): ?>
    <div style="padding:8px 10px 0;">
        <div class="nav-item" onclick="openAddAccountPanel('create')" style="border:1px dashed var(--border); border-radius:9px; margin:0; font-size:.76rem;">
            <i class="fa-solid fa-plus"></i> Ajouter un compte
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Zone principale ── -->
<div class="mail-main" id="mailMain">

    <!-- Toolbar -->
    <div class="msg-toolbar" id="msgToolbar">
        <input type="checkbox" class="check-all" id="checkAll" onchange="toggleSelectAll(this)">
        <span class="folder-title" id="folderTitle">Boîte de réception</span>
        <div class="toolbar-sep"></div>
        <button class="toolbar-btn" id="markReadBtn" onclick="markSelectedRead(1)" style="display:none;"><i class="fa-solid fa-envelope-open"></i> Lu</button>
        <button class="toolbar-btn" id="markUnreadBtn" onclick="markSelectedRead(0)" style="display:none;"><i class="fa-solid fa-envelope"></i> Non-lu</button>
        <button class="toolbar-btn" id="trashBtn" onclick="trashSelected()" style="display:none;"><i class="fa-solid fa-trash"></i> Supprimer</button>
        <button class="toolbar-btn" onclick="loadMessages()"><i class="fa-solid fa-rotate"></i></button>
        <div class="pagination">
            <span id="pageInfo"></span>
            <button class="page-btn" id="prevPage" onclick="changePage(-1)" disabled><i class="fa-solid fa-chevron-left"></i></button>
            <button class="page-btn" id="nextPage" onclick="changePage(1)"  disabled><i class="fa-solid fa-chevron-right"></i></button>
        </div>
    </div>

    <!-- Liste -->
    <div class="msg-list" id="msgList"></div>

    <!-- Vue message -->
    <div class="msg-view" id="msgView">
        <div id="msgViewContent"></div>
    </div>

</div><!-- /mail-main -->

<!-- ════ MODALES / PANNEAUX ════ -->

<!-- Composer -->
<div class="compose-overlay" id="composeOverlay">
<div class="compose-modal" id="composeModal">
    <div class="compose-titlebar" id="composeTitlebar">
        <i class="fa-solid fa-pen-nib"></i>
        <span id="composeTitle">Nouveau message</span>
        <div class="title-actions">
            <button class="close-btn" onclick="toggleMinimizeCompose()" title="Réduire"><i class="fa-solid fa-minus"></i></button>
            <button class="close-btn" onclick="toggleMaximizeCompose()" id="composeMaxBtn" title="Agrandir"><i class="fa-solid fa-expand"></i></button>
            <button class="close-btn close-x" onclick="closeCompose()" title="Fermer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>
    <div id="composeContent" style="display:flex;flex-direction:column;flex:1;overflow-y:auto;overflow-x:hidden;min-height:0;scrollbar-width:thin;scrollbar-color:var(--border) transparent;">
    <div class="compose-fields">
        <div class="compose-field" style="position:relative;">
            <label>De</label>
            <select class="compose-from-sel" id="composeFrom">
                <?php foreach($accounts as $acc): ?>
                <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['address']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="compose-field" style="position:relative;">
            <label>À</label>
            <div class="tag-input" id="toTags" onclick="document.getElementById('toInput').focus()">
                <input type="text" id="toInput" class="tag-text-input" placeholder="Adresse…" onkeydown="handleTagInput(event,'to')" oninput="suggestAddress(event,'toSuggest')">
            </div>
            <div class="autocomplete-list" id="toSuggest"></div>
        </div>
        <div class="compose-field" id="ccRow" style="display:none;">
            <label>Cc</label>
            <div class="tag-input" id="ccTags" onclick="document.getElementById('ccInput').focus()">
                <input type="text" id="ccInput" class="tag-text-input" placeholder="Adresse…" onkeydown="handleTagInput(event,'cc')">
            </div>
        </div>
        <div class="compose-field" id="bccRow" style="display:none;">
            <label>Cci</label>
            <div class="tag-input" id="bccTags" onclick="document.getElementById('bccInput').focus()">
                <input type="text" id="bccInput" class="tag-text-input" placeholder="Adresse…" onkeydown="handleTagInput(event,'bcc')">
            </div>
        </div>
        <div style="display:flex;gap:8px;padding:4px 16px 0;font-size:.72rem;">
            <span style="cursor:pointer;color:var(--text3);" onclick="document.getElementById('ccRow').style.display='flex'">Cc</span>
            <span style="cursor:pointer;color:var(--text3);" onclick="document.getElementById('bccRow').style.display='flex'">Cci</span>
        </div>
        <div class="compose-subject">
            <input type="text" id="composeSubject" placeholder="Objet">
        </div>
        <div class="compose-body-wrap">
            <div class="compose-toolbar-fmt" style="position:relative;">
                <button class="fmt-btn" onclick="fmt('bold')" title="Gras"><i class="fa-solid fa-bold"></i></button>
                <button class="fmt-btn" onclick="fmt('italic')" title="Italique"><i class="fa-solid fa-italic"></i></button>
                <button class="fmt-btn" onclick="fmt('underline')" title="Souligné"><i class="fa-solid fa-underline"></i></button>
                <div class="fmt-sep"></div>
                <button class="fmt-btn" onclick="fmt('insertUnorderedList')"><i class="fa-solid fa-list-ul"></i></button>
                <button class="fmt-btn" onclick="fmt('insertOrderedList')"><i class="fa-solid fa-list-ol"></i></button>
                <div class="fmt-sep"></div>
                <button class="fmt-btn" onclick="insertLink()" title="Lien"><i class="fa-solid fa-link"></i></button>
                <div class="fmt-sep"></div>
                <!-- Bouton image -->
                <button class="fmt-btn" id="imgInsertBtn" onclick="toggleImgPopover(event)" title="Insérer une image" style="color:var(--accent);">
                    <i class="fa-solid fa-image"></i>
                </button>
                <div class="fmt-sep"></div>
                <button class="fmt-btn" onclick="fmt('removeFormat')" title="Effacer format"><i class="fa-solid fa-eraser"></i></button>

                <!-- Popover insertion d'image -->
                <div class="img-popover" id="imgPopover">
                    <div class="img-popover-title"><i class="fa-solid fa-image"></i> Insérer une image</div>

                    <!-- Via URL -->
                    <div class="img-popover-row">
                        <input type="text" id="imgUrlInput" placeholder="https://exemple.com/image.jpg"
                            onkeydown="if(event.key==='Enter'){insertImageUrl();event.preventDefault();}">
                        <button class="img-popover-confirm" onclick="insertImageUrl()">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:4px;margin-bottom:2px;">Colle l'URL d'une image web</div>

                    <div class="img-popover-divider">ou</div>

                    <!-- Via fichier local -->
                    <label class="img-upload-btn" for="imgFileInput">
                        <i class="fa-solid fa-upload"></i> Choisir un fichier local
                    </label>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:5px;">JPG, PNG, GIF, WEBP — max 5 Mo</div>
                    <input type="file" id="imgFileInput" accept="image/jpeg,image/png,image/gif,image/webp"
                        style="display:none;" onchange="insertImageFile(this)">
                </div>
            </div>

            <!-- Barre contextuelle image sélectionnée -->
            <div class="img-resize-bar" id="imgResizeBar">
                <i class="fa-solid fa-image" style="color:var(--accent);font-size:.8rem;"></i>
                <span>Taille :</span>
                <button class="img-size-btn" onclick="resizeSelectedImg('25%')">25%</button>
                <button class="img-size-btn" onclick="resizeSelectedImg('50%')">50%</button>
                <button class="img-size-btn" onclick="resizeSelectedImg('75%')">75%</button>
                <button class="img-size-btn" onclick="resizeSelectedImg('100%')">100%</button>
                <button class="img-size-btn" onclick="resizeSelectedImg('inline')">Inline</button>
                <button class="img-remove-btn" onclick="removeSelectedImg()"><i class="fa-solid fa-trash"></i> Supprimer</button>
            </div>

            <div class="compose-editor" id="composeBody" contenteditable="true" data-placeholder="Écrivez votre message…"
                onclick="handleEditorClick(event)" onkeyup="checkImgSelection()"></div>
        </div>
    </div>
    </div><!-- /composeContent -->
    <div class="compose-footer">
        <button class="send-btn" onclick="sendMail()"><i class="fa-solid fa-paper-plane"></i> Envoyer</button>
        <button class="draft-btn" onclick="saveDraft()"><i class="fa-regular fa-floppy-disk"></i> Brouillon</button>
        <div class="compose-footer-right">
            <button class="fmt-btn" onclick="closeCompose()" title="Fermer"><i class="fa-solid fa-trash"></i></button>
        </div>
    </div>
</div>
</div>

<!-- Panneau : Paramètres / Thème -->
<div class="side-panel" id="settingsPanel">
    <div class="side-panel-backdrop" onclick="closePanel('settingsPanel')"></div>
    <div class="side-panel-content">
        <div class="side-panel-title"><i class="fa-solid fa-gear"></i> Paramètres</div>

        <div class="form-group">
            <label>Thème de l'interface</label>
            <div class="theme-grid">
                <?php
                $themes = [
                    'dark'     => ['Dark',     '#080f1e','#3b82f6'],
                    'light'    => ['Light',    '#f1f5f9','#2563eb'],
                    'midnight' => ['Midnight', '#020408','#8b5cf6'],
                    'ocean'    => ['Ocean',    '#011520','#06b6d4'],
                    'sunset'   => ['Sunset',   '#150806','#f97316'],
                ];
                $currentTheme = $activeAccount['theme'] ?? 'dark';
                foreach($themes as $key=>[$label,$bg,$accent]): ?>
                <div>
                    <div class="theme-opt <?php echo $key===$currentTheme?'active':''; ?>" data-theme="<?php echo $key; ?>" onclick="setTheme('<?php echo $key; ?>')">
                        <div class="preview-swatch">
                            <div class="sw-top" style="background:<?php echo $bg; ?>;"></div>
                            <div class="sw-bot" style="background:<?php echo $accent; ?>;"></div>
                        </div>
                    </div>
                    <label><?php echo $label; ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label>Signature</label>
            <textarea class="form-control" id="signatureInput" rows="4" placeholder="Votre signature..."><?php echo htmlspecialchars($activeAccount['signature'] ?? ''); ?></textarea>
            <button class="btn-primary" onclick="saveSignature()" style="margin-top:8px;">Sauvegarder</button>
        </div>

        <div class="form-group">
            <label>Logo du compte</label>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <div id="logoPreviewWrap" style="width:52px;height:52px;border-radius:12px;overflow:hidden;background:var(--bg3);border:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                    <?php if(!empty($activeAccount['logo'])): ?>
                    <img id="logoPreview" src="<?php echo SITE_URL.'/assets/'.htmlspecialchars($activeAccount['logo']); ?>" style="width:100%;height:100%;object-fit:cover;" alt="logo">
                    <?php else: ?>
                    <i class="fa-solid fa-at" id="logoPreview" style="color:var(--text-muted);font-size:1.2rem;"></i>
                    <?php endif; ?>
                </div>
                <div style="flex:1;">
                    <div style="font-size:.78rem;color:var(--text2);margin-bottom:6px;">JPG, PNG, WEBP — max 2 Mo</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <label for="logoFileInput" style="cursor:pointer;" class="btn-secondary" style="padding:6px 12px;border-radius:8px;font-size:.8rem;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fa-solid fa-upload"></i> Choisir
                        </label>
                        <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="uploadLogo(this)">
                        <button class="btn-secondary" onclick="deleteLogo()" id="logoDeleteBtn"
                            style="display:<?php echo !empty($activeAccount['logo']) ? 'inline-flex' : 'none'; ?>;padding:6px 12px;border-radius:8px;font-size:.8rem;align-items:center;gap:5px;color:#ef4444;">
                            <i class="fa-solid fa-trash"></i> Supprimer
                        </button>
                    </div>
                    <div id="logoUploadStatus" style="font-size:.75rem;margin-top:5px;display:none;"></div>
                </div>
            </div>
        </div>

        <hr style="border-color:var(--border);margin:20px 0;">
        <div class="side-panel-title" style="font-size:.9rem;"><i class="fa-solid fa-at"></i> Mon adresse</div>
        <div style="font-family:var(--font-mono);font-size:.85rem;color:var(--accent);background:var(--bg3);padding:10px 14px;border-radius:9px;margin-bottom:12px;" id="currentAddrDisplay">
            <?php echo htmlspecialchars($activeAccount['address']); ?>
        </div>
    </div>
</div>

<!-- Panneau : Gestion des comptes -->
<div class="side-panel" id="accountsPanel">
    <div class="side-panel-backdrop" onclick="closePanel('accountsPanel')"></div>
    <div class="side-panel-content">
        <div class="side-panel-title"><i class="fa-solid fa-users-gear"></i> Mes comptes</div>
        <div id="accountsPanelList">
            <?php foreach($accounts as $acc):
                $isActive = ($acc['id'] === $activeAccount['id']);
                $isOwner  = ($acc['user_id'] == $_SESSION['user_id']);
            ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--border);">
                <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $isActive ? 'var(--accent)' : 'var(--text3)'; ?>;flex-shrink:0;<?php echo $isActive ? 'box-shadow:0 0 6px var(--accent);' : ''; ?>"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.82rem;font-weight:700;font-family:var(--font-mono);color:<?php echo $isActive ? 'var(--accent)' : 'var(--text)'; ?>;"><?php echo htmlspecialchars($acc['address']); ?></div>
                    <div style="font-size:.7rem;color:var(--text3);margin-top:2px;">
                        <?php echo $acc['type']==='personal' ? 'Personnel' : ($acc['type']==='faction' ? 'Faction' : 'Admin'); ?>
                        <?php if($isActive): ?> · <span style="color:var(--accent);">Actif</span><?php endif; ?>
                        <?php if(!$isOwner): ?> · <span style="color:var(--text3);">Lié</span><?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <?php if(!$isActive): ?>
                    <button class="btn-secondary" style="font-size:.72rem;padding:4px 10px;" onclick="switchAccount(<?php echo $acc['id']; ?>,'<?php echo htmlspecialchars($acc['address']); ?>')">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    </button>
                    <?php endif; ?>
                    <?php if(!$isOwner): ?>
                    <button class="btn-secondary" style="font-size:.72rem;padding:4px 10px;color:var(--danger);border-color:rgba(239,68,68,.3);" onclick="unlinkAccount(<?php echo $acc['id']; ?>,'<?php echo htmlspecialchars($acc['address']); ?>')" title="Déconnecter ce compte">
                        <i class="fa-solid fa-link-slash"></i>
                    </button>
                    <?php elseif(!$isActive && $isOwner): ?>
                    <span style="font-size:.65rem;color:var(--text3);" title="Compte principal, non supprimable"><i class="fa-solid fa-house"></i></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
            <button class="btn-primary" onclick="closePanel('accountsPanel');openAddAccountPanel('create')">
                <i class="fa-solid fa-plus"></i> Créer une nouvelle adresse
            </button>
            <button class="btn-secondary" onclick="closePanel('accountsPanel');openAddAccountPanel('connect')">
                <i class="fa-solid fa-link"></i> Connecter une adresse existante
            </button>
        </div>
    </div>
</div>

<!-- Panneau : Ajouter / Connecter compte -->
<div class="side-panel" id="addAccountPanel">
    <div class="side-panel-backdrop" onclick="closePanel('addAccountPanel')"></div>
    <div class="side-panel-content">
        <div class="side-panel-title" id="addPanelTitle"><i class="fa-solid fa-plus"></i> Nouvelle adresse</div>

        <!-- Onglets -->
        <div style="display:flex;gap:4px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:3px;margin-bottom:20px;">
            <button id="tabCreate"  class="add-tab active" onclick="switchAddTab('create')">
                <i class="fa-solid fa-pen-to-square"></i> Créer
            </button>
            <button id="tabConnect" class="add-tab" onclick="switchAddTab('connect')">
                <i class="fa-solid fa-link"></i> Connecter
            </button>
        </div>

        <!-- Onglet Créer -->
        <div id="paneCreate">
            <div class="form-group">
                <label>Nom de l'adresse</label>
                <input type="text" class="form-control" id="newLocal" placeholder="prenom.nom" oninput="updateNewPreview()">
            </div>
            <div class="address-preview" id="newAddrPreview" style="margin-bottom:16px;">prenom.nom@<?php echo $serverName; ?>.<?php echo htmlspecialchars($defaultTld); ?></div>
            <div style="font-size:.72rem;color:var(--text3);margin-bottom:16px;display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-circle-info"></i>
                L'extension <strong>.<?php echo htmlspecialchars($defaultTld); ?></strong> est définie par l'administration du serveur.
            </div>
            <button class="btn-primary" onclick="createNewAccount()"><i class="fa-solid fa-envelope-circle-check"></i> Créer l'adresse</button>
        </div>

        <!-- Onglet Connecter -->
        <div id="paneConnect" style="display:none;">
            <p style="font-size:.82rem;color:var(--text2);margin-bottom:16px;line-height:1.6;">
                Connectez une adresse mail à votre session. Si le compte vous appartient, laissez le mot de passe vide. Pour un compte faction, entrez son mot de passe.
            </p>
            <div class="form-group">
                <label>Adresse mail</label>
                <input type="text" class="form-control" id="linkAddress"
                    placeholder="ex: chief@lspd.us">
            </div>
            <div class="form-group">
                <label>Mot de passe <span style="font-size:.72rem;font-weight:400;color:var(--text3);">(optionnel — requis pour les comptes faction)</span></label>
                <div style="position:relative;">
                    <input type="password" class="form-control" id="linkPassword"
                        placeholder="Laisser vide si c'est votre compte"
                        style="padding-right:40px;">
                    <button type="button" onclick="toggleLinkPwd()"
                        id="linkPwdEye"
                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:.85rem;">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>
            <button class="btn-primary" onclick="doLinkAccount()">
                <i class="fa-solid fa-link"></i> Connecter
            </button>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /mail-app -->

<!-- Toasts -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const API    = '<?php echo SITE_URL; ?>/pages/mail/api.php';
const SNAME  = '<?php echo $serverName; ?>';
const DEFAULT_TLD = '<?php echo htmlspecialchars($defaultTld); ?>';
const SITE_BASE = '<?php echo SITE_URL; ?>';
let activeAccountId = <?php echo $hasAccount ? (int)$activeAccount['id'] : 'null'; ?>;
let currentFolder   = 'inbox';
let currentPage     = 1;
let selectedIds     = new Set();
let currentMsgId    = null;
let searchTimer     = null;

// ── Helper : avatar expéditeur (logo ou initiale) ──
function senderAvatar(fromName, fromAddress, fromLogo, size=26, radius=7) {
    if (fromLogo) {
        return `<div class="msg-sender-ava" style="width:${size}px;height:${size}px;border-radius:${radius}px;">
            <img src="${SITE_BASE}/assets/${esc(fromLogo)}" alt="" onerror="this.parentElement.innerHTML='${((fromName||fromAddress)||'?')[0].toUpperCase()}'">
        </div>`;
    }
    const initial = ((fromName || fromAddress) || '?')[0].toUpperCase();
    const colors  = ['#3b82f6','#8b5cf6','#10b981','#f97316','#ec4899','#06b6d4','#f59e0b'];
    const color   = colors[(initial.charCodeAt(0) || 0) % colors.length];
    return `<div class="msg-sender-ava" style="width:${size}px;height:${size}px;border-radius:${radius}px;background:${color};">${initial}</div>`;
}

const FOLDER_LABELS = {
    inbox:'Boîte de réception', starred:'Suivis', sent:'Envoyés',
    drafts:'Brouillons', spam:'Spam', trash:'Corbeille'
};

/* ═══ TOAST ═══ */
function toast(msg, type='info', icon='circle-info') {
    const w = document.getElementById('toastWrap');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid fa-${icon}"></i> ${msg}`;
    w.appendChild(t);
    setTimeout(()=>t.remove(), 3500);
}

/* ═══ API CALL ═══ */
async function api(params={}, method='POST') {
    const fd = new FormData();
    Object.entries(params).forEach(([k,v])=>fd.append(k, typeof v==='object'?JSON.stringify(v):v));
    const r = await fetch(API, { method, body: method==='POST'?fd:undefined });
    return r.json();
}
async function apiGet(params={}) {
    const qs = new URLSearchParams(params).toString();
    const r = await fetch(`${API}?${qs}`);
    return r.json();
}

/* ═══ INIT ═══ */
<?php if($hasAccount): ?>
document.addEventListener('DOMContentLoaded', ()=>{
    loadMessages();
    loadFolderCounts();
    loadNotifications();
    setInterval(loadNotifications, 30000);
    setInterval(loadFolderCounts,  60000);
});
<?php endif; ?>

/* ═══ COMPTES ═══ */
function switchAccount(id, addr) {
    activeAccountId = id;
    document.getElementById('activeAccountLabel').textContent = addr;
    document.querySelectorAll('.account-item').forEach(el=>{
        el.classList.toggle('active', parseInt(el.dataset.id)===id);
    });
    if(document.getElementById('currentAddrDisplay'))
        document.getElementById('currentAddrDisplay').textContent = addr;
    currentFolder = 'inbox';
    currentPage   = 1;
    loadMessages();
    loadFolderCounts();
    closePanel('accountsPanel');
    toast('Compte changé : '+addr,'info','envelope');
}

/* ═══ DOSSIERS ═══ */
function switchFolder(folder) {
    currentFolder = folder;
    currentPage   = 1;
    document.querySelectorAll('[data-folder]').forEach(el=>{
        el.classList.toggle('active', el.dataset.folder===folder);
    });
    document.getElementById('folderTitle').textContent = FOLDER_LABELS[folder]||folder;
    closeMessageView();
    loadMessages();
}

async function loadFolderCounts() {
    const r = await apiGet({action:'get_folder_counts', account_id:activeAccountId});
    if(!r.ok) return;
    const c = r.data;
    ['inbox','drafts','starred'].forEach(f=>{
        const el = document.getElementById('cnt-'+f);
        if(!el) return;
        const n = c[f]?.unread||c[f]?.total||0;
        el.textContent = n;
        el.style.display = n>0?'':'none';
    });
}

/* ═══ MESSAGES ═══ */
async function loadMessages() {
    const search = document.getElementById('searchInput')?.value||'';
    const r = await apiGet({
        action:'get_messages', account_id:activeAccountId,
        folder:currentFolder, page:currentPage, search
    });
    if(!r.ok){ toast(r.error,'error','xmark'); return; }
    renderMessageList(r.data);
}

function renderMessageList(data) {
    const list = document.getElementById('msgList');
    const msgs = data.messages||[];
    if(!msgs.length){
        list.innerHTML=`<div class="empty-screen"><i class="fa-solid fa-${currentFolder==='inbox'?'inbox':'folder-open'}"></i><h3>Aucun message</h3><p>Ce dossier est vide.</p></div>`;
        updatePagination(0,1);
        return;
    }
    list.innerHTML = msgs.map(m=>`
        <div class="msg-row ${m.is_read?'':'unread'} ${selectedIds.has(m.id)?'selected':''}" data-id="${m.id}" onclick="handleRowClick(event,${m.id})">
            <div class="unread-dot"></div>
            <input type="checkbox" class="msg-check" ${selectedIds.has(m.id)?'checked':''} onclick="event.stopPropagation();toggleSelect(${m.id},this)">
            <button class="msg-star ${m.is_starred?'starred':''}" onclick="event.stopPropagation();toggleStar(${m.id},this)">
                <i class="fa-${m.is_starred?'solid':'regular'} fa-star"></i>
            </button>
            ${senderAvatar(m.from_name, m.from_address, m.from_logo)}
            <div class="msg-from">${esc(m.from_name||m.from_address)}</div>
            <div class="msg-subject">${esc(m.subject)} <span class="preview"> — ${esc(m.preview||'')}</span></div>
            <div class="msg-date">${formatDate(m.sent_at||m.created_at)}</div>
        </div>
    `).join('');
    updatePagination(data.total, data.page);
    updateSelectionButtons();
}

function handleRowClick(e, id) {
    if(e.target.type==='checkbox') return;
    openMessage(id);
}

function updatePagination(total, page) {
    const perPage=25, pages=Math.max(1,Math.ceil(total/perPage));
    document.getElementById('pageInfo').textContent=`${Math.min((page-1)*perPage+1,total)}–${Math.min(page*perPage,total)} sur ${total}`;
    document.getElementById('prevPage').disabled = page<=1;
    document.getElementById('nextPage').disabled = page>=pages;
}

function changePage(dir) {
    currentPage = Math.max(1, currentPage+dir);
    loadMessages();
}

/* ═══ VUE MESSAGE ═══ */
async function openMessage(id) {
    currentMsgId = id;
    const r = await apiGet({action:'get_message', account_id:activeAccountId, message_id:id});
    if(!r.ok){ toast(r.error,'error','xmark'); return; }
    const {message:m, thread} = r.data;

    // Marquer lu dans la liste UI
    const row = document.querySelector(`.msg-row[data-id="${id}"]`);
    if(row) row.classList.remove('unread');

    document.getElementById('msgList').style.display='none';
    document.getElementById('msgToolbar').style.display='none';
    const view = document.getElementById('msgView');
    view.classList.add('active');

    const toArr = tryParseJSON(m.to_addresses)||[m.to_addresses];

    view.innerHTML = `
    <div class="msg-view-header">
        <div class="msg-view-subject">${esc(m.subject)}</div>
        <div class="msg-view-meta">
            <div class="msg-view-avatar">${m.from_logo
                ? `<img src="${SITE_BASE}/assets/${esc(m.from_logo)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:11px;" onerror="this.parentElement.innerHTML='${((m.from_name||m.from_address)||'?')[0].toUpperCase()}'">`
                : (m.from_name||m.from_address)[0].toUpperCase()
            }</div>
            <div class="msg-view-from">
                <div class="from-name">${esc(m.from_name||m.from_address)}</div>
                <div class="from-addr">${esc(m.from_address)}</div>
                <div class="to-line">À : ${toArr.map(a=>esc(a)).join(', ')}</div>
            </div>
            <div class="msg-view-date">${formatDateFull(m.sent_at||m.created_at)}</div>
        </div>
        <div class="msg-view-actions" style="margin-top:14px;">
            <button class="action-btn primary" onclick="openReply(${m.id},'reply')"><i class="fa-solid fa-reply"></i> Répondre</button>
            <button class="action-btn" onclick="openReply(${m.id},'reply-all')"><i class="fa-solid fa-reply-all"></i> Répondre à tous</button>
            <button class="action-btn" onclick="openReply(${m.id},'forward')"><i class="fa-solid fa-share"></i> Transférer</button>
            <button class="action-btn" onclick="toggleStarMsg(${m.id})"><i class="fa-${m.is_starred?'solid':'regular'} fa-star"></i></button>
            <button class="action-btn danger" onclick="trashMsg(${m.id})"><i class="fa-solid fa-trash"></i></button>
            <button class="action-btn" onclick="closeMessageView()"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>
    <div class="msg-body">${m.body_type==='html'?m.body:m.body.replace(/\n/g,'<br>')}</div>
    ${thread && thread.length>1 ? renderThread(thread,m.id) : ''}
    `;
}

function renderThread(thread, currentId) {
    const others = thread.filter(t=>t.id!==currentId);
    if(!others.length) return '';
    return `<div class="thread-section">
        <div class="thread-toggle" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
            <i class="fa-solid fa-code-fork"></i> ${others.length} message(s) dans cette conversation
        </div>
        <div style="display:none;">
            ${others.map(t=>`<div class="thread-msg">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    ${senderAvatar(t.from_name, t.from_address, t.from_logo, 26, 7)}
                    <div>
                        <div style="font-size:.78rem;font-weight:700;">${esc(t.from_name||t.from_address)}</div>
                        <div style="font-size:.68rem;color:var(--text3);">${formatDate(t.sent_at||t.created_at)}</div>
                    </div>
                </div>
                <div style="font-size:.82rem;color:var(--text2);">${t.body_type==='html'?t.body:t.body.replace(/\n/g,'<br>')}</div>
            </div>`).join('')}
        </div>
    </div>`;
}

function closeMessageView() {
    document.getElementById('msgView').classList.remove('active');
    document.getElementById('msgView').innerHTML='';
    document.getElementById('msgList').style.display='';
    document.getElementById('msgToolbar').style.display='';
    currentMsgId=null;
    loadMessages();
    loadFolderCounts();
}

/* ═══ COMPOSER ═══ */
const toTags={}, ccTags={}, bccTags={};
['to','cc','bcc'].forEach(f=>{toTags[f]=[];});

function openCompose(opts={}) {
    const modal   = document.getElementById('composeModal');
    const overlay = document.getElementById('composeOverlay');

    // Réinitialiser position si première ouverture ou après fermeture
    if (!modal._positioned) {
        modal.style.bottom = '16px';
        modal.style.right  = '16px';
        modal.style.top    = 'auto';
        modal.style.left   = 'auto';
        modal.style.width  = '580px';
        modal.style.height = '';
        modal._positioned  = true;
    }
    modal.classList.remove('is-maximized', 'is-minimized');
    modal.style.display = 'flex';
    overlay.classList.add('open');

    document.getElementById('composeTitle').textContent = opts.title||'Nouveau message';
    document.getElementById('composeSubject').value = opts.subject||'';
    document.getElementById('composeBody').innerHTML = opts.body||'';
    const sig = <?php echo json_encode($activeAccount['signature'] ?? ''); ?>;
    if(sig && !opts.body) document.getElementById('composeBody').innerHTML = `<br><br><hr style="opacity:.3"><div style="font-size:.8rem;color:var(--text3);">${sig}</div>`;
    ['to','cc','bcc'].forEach(f=>{toTags[f]=[]; renderTags(f);});
    if(opts.to) { toTags['to']=[opts.to]; renderTags('to'); }
    document.getElementById('composeBody')._replyToId = opts.replyId||null;
    document.getElementById('composeBody')._type = opts.type||'normal';
}

function closeCompose() {
    const modal = document.getElementById('composeModal');
    document.getElementById('composeOverlay').classList.remove('open');
    modal._positioned = false;
    modal.classList.remove('is-maximized','is-minimized');
}

/* ─ Minimize ─ */
function toggleMinimizeCompose() {
    const modal   = document.getElementById('composeModal');
    const content = document.getElementById('composeContent');
    const isMin   = modal.classList.contains('is-minimized');
    if (isMin) {
        // Restaurer
        content.style.display = '';
        modal.style.height    = modal._savedH || '';
        modal.classList.remove('is-minimized');
    } else {
        modal._savedH = modal.style.height || modal.offsetHeight + 'px';
        content.style.display = 'none';
        modal.style.height = '';
        modal.classList.add('is-minimized');
    }
}

/* ─ Maximize ─ */
function toggleMaximizeCompose() {
    const modal = document.getElementById('composeModal');
    const btn   = document.getElementById('composeMaxBtn');
    const isMax = modal.classList.contains('is-maximized');
    if (isMax) {
        modal.classList.remove('is-maximized');
        modal.style.width  = modal._savedW || '580px';
        modal.style.height = modal._savedH2 || '';
        modal.style.top    = modal._savedTop || 'auto';
        modal.style.left   = modal._savedLeft || 'auto';
        modal.style.bottom = modal._savedBottom || '16px';
        modal.style.right  = modal._savedRight  || '16px';
        btn.innerHTML = '<i class="fa-solid fa-expand"></i>';
        btn.title = 'Agrandir';
    } else {
        modal._savedW      = modal.style.width;
        modal._savedH2     = modal.style.height;
        modal._savedTop    = modal.style.top;
        modal._savedLeft   = modal.style.left;
        modal._savedBottom = modal.style.bottom;
        modal._savedRight  = modal.style.right;
        modal.classList.add('is-maximized');
        btn.innerHTML = '<i class="fa-solid fa-compress"></i>';
        btn.title = 'Restaurer';
    }
}

/* ─ Drag ─ */
(function initComposeDrag() {
    const titlebar = document.getElementById('composeTitlebar');
    const modal    = document.getElementById('composeModal');
    if (!titlebar || !modal) return;

    let startX, startY, startL, startT, isDragging = false;

    titlebar.addEventListener('mousedown', e => {
        if (e.target.closest('button')) return; // Ne pas drag via les boutons
        if (modal.classList.contains('is-maximized')) return;

        // Convertir bottom/right en top/left absolus si nécessaire
        if (modal.style.top === 'auto' || modal.style.top === '') {
            const r = modal.getBoundingClientRect();
            modal.style.top    = r.top + 'px';
            modal.style.left   = r.left + 'px';
            modal.style.bottom = 'auto';
            modal.style.right  = 'auto';
        }

        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        startL = parseInt(modal.style.left) || 0;
        startT = parseInt(modal.style.top)  || 0;
        modal.classList.add('dragging');
        e.preventDefault();
    });

    document.addEventListener('mousemove', e => {
        if (!isDragging) return;
        let nx = startL + (e.clientX - startX);
        let ny = startT + (e.clientY - startY);
        // Garder dans le viewport
        nx = Math.max(0, Math.min(nx, window.innerWidth  - modal.offsetWidth));
        ny = Math.max(0, Math.min(ny, window.innerHeight - modal.offsetHeight));
        modal.style.left = nx + 'px';
        modal.style.top  = ny + 'px';
    });

    document.addEventListener('mouseup', () => {
        if (!isDragging) return;
        isDragging = false;
        modal.classList.remove('dragging');
    });

    // Double-clic titlebar → maximize toggle
    titlebar.addEventListener('dblclick', e => {
        if (e.target.closest('button')) return;
        toggleMaximizeCompose();
    });
})();


function openReply(msgId, type) {
    // Récupérer les infos depuis la vue
    const fromAddr = document.querySelector('.from-addr')?.textContent||'';
    const subject  = document.querySelector('.msg-view-subject')?.textContent||'';
    const body     = document.querySelector('.msg-body')?.innerHTML||'';
    const isForward = type==='forward';
    openCompose({
        title: isForward?'Transférer':'Répondre',
        to: isForward?'':fromAddr,
        subject: (isForward?'Fwd: ':'Re: ')+subject.replace(/^(Re:|Fwd:)\s*/i,''),
        body: `<br><br><blockquote style="border-left:3px solid var(--accent);padding-left:12px;margin:12px 0;color:var(--text3);">${body}</blockquote>`,
        replyId: msgId,
        type: isForward?'forward':'reply',
    });
}

function handleTagInput(e, field) {
    if(e.key==='Enter'||e.key===','||e.key===' ') {
        e.preventDefault();
        const inp = document.getElementById(field+'Input');
        const val = inp.value.trim().replace(',','');
        if(val) { toTags[field].push(val); inp.value=''; renderTags(field); }
    } else if(e.key==='Backspace' && !e.target.value) {
        toTags[field].pop(); renderTags(field);
    }
}

function renderTags(field) {
    const container = document.getElementById(field+'Tags');
    const input = document.getElementById(field+'Input');
    Array.from(container.querySelectorAll('.tag')).forEach(t=>t.remove());
    toTags[field].forEach((v,i)=>{
        const t=document.createElement('div');
        t.className='tag';
        t.innerHTML=`${esc(v)}<span class="rm" onclick="removeTag('${field}',${i})">×</span>`;
        container.insertBefore(t, input);
    });
}

function removeTag(field, idx) { toTags[field].splice(idx,1); renderTags(field); }

function fmt(cmd) { document.execCommand(cmd,false,null); document.getElementById('composeBody').focus(); }
function insertLink() {
    const url=prompt('URL :','https://'); if(url) document.execCommand('createLink',false,url);
}

/* ═══ IMAGES DANS LE COMPOSE ═══ */
let _savedRange = null;

// Sauvegarder la position du curseur avant d'ouvrir le popover
function saveRange() {
    const sel = window.getSelection();
    if (sel && sel.rangeCount) _savedRange = sel.getRangeAt(0).cloneRange();
}

// Restaurer le curseur dans le compose body
function restoreRange() {
    const editor = document.getElementById('composeBody');
    editor.focus();
    if (_savedRange) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(_savedRange);
    }
}

// Insérer une image à la position du curseur
function insertImgNode(src, alt='') {
    restoreRange();
    const img = document.createElement('img');
    img.src = src;
    img.alt = alt;
    img.style.maxWidth = '100%';
    img.style.borderRadius = '8px';
    img.style.display = 'block';
    img.style.margin = '6px 0';

    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
        const range = sel.getRangeAt(0);
        range.deleteContents();
        range.insertNode(img);
        // Placer le curseur après l'image
        range.setStartAfter(img);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    } else {
        document.getElementById('composeBody').appendChild(img);
    }
    closeImgPopover();
    checkImgSelection();
}

// Ouvrir/fermer le popover
function toggleImgPopover(e) {
    e.stopPropagation();
    saveRange();
    const pop = document.getElementById('imgPopover');
    const btn = document.getElementById('imgInsertBtn');
    const isOpen = pop.classList.contains('open');

    if (isOpen) {
        closeImgPopover();
        return;
    }

    // Calculer la position en fixed par rapport au bouton
    const rect = btn.getBoundingClientRect();
    const popW = 300;
    const margin = 6;

    // Préférer au-dessus, sinon en-dessous
    let top, left;
    if (rect.top > 200) {
        // Au-dessus du bouton
        top = rect.top - margin; // sera ajusté après display
        pop.style.top = '-9999px';
        pop.style.left = '-9999px';
        pop.classList.add('open');
        const popH = pop.offsetHeight;
        pop.classList.remove('open');
        top = rect.top - popH - margin;
    } else {
        // En-dessous
        top = rect.bottom + margin;
    }

    // Aligner à gauche du bouton, mais ne pas dépasser le viewport
    left = rect.left;
    if (left + popW > window.innerWidth - 8) {
        left = window.innerWidth - popW - 8;
    }
    if (left < 8) left = 8;

    pop.style.top  = top  + 'px';
    pop.style.left = left + 'px';
    pop.classList.add('open');
    setTimeout(() => document.getElementById('imgUrlInput').focus(), 50);
}
function closeImgPopover() {
    document.getElementById('imgPopover').classList.remove('open');
    document.getElementById('imgUrlInput').value = '';
}

// Insertion via URL
function insertImageUrl() {
    const url = document.getElementById('imgUrlInput').value.trim();
    if (!url) return;
    if (!/^https?:\/\/.+\.(jpg|jpeg|png|gif|webp|svg|bmp)(\?.*)?$/i.test(url) && !url.startsWith('data:image')) {
        // Tolérant : on tente quand même l'insertion si ça ressemble à une URL image
        if (!url.startsWith('http')) { toast('URL invalide','error','xmark'); return; }
    }
    insertImgNode(url, 'image');
    toast('Image insérée', 'success', 'image');
}

// Insertion via fichier local (base64)
function insertImageFile(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 5 * 1024 * 1024) { toast('Fichier trop lourd (max 5 Mo)', 'error', 'xmark'); return; }
    const reader = new FileReader();
    reader.onload = e => {
        insertImgNode(e.target.result, file.name.replace(/\.[^.]+$/, ''));
        toast('Image insérée', 'success', 'image');
    };
    reader.readAsDataURL(file);
    input.value = '';
}

// Clic sur une image dans l'éditeur → sélectionner + afficher barre
let _selectedImg = null;
function handleEditorClick(e) {
    if (e.target.tagName === 'IMG') {
        selectImg(e.target);
    } else {
        deselectImg();
    }
}
function checkImgSelection() {
    const sel = window.getSelection();
    if (!sel || !sel.anchorNode) return;
    // Désélectionner si on navigue hors image
    if (_selectedImg && !document.getElementById('composeBody').contains(_selectedImg)) {
        deselectImg();
    }
}
function selectImg(img) {
    if (_selectedImg) _selectedImg.classList.remove('selected-img');
    _selectedImg = img;
    img.classList.add('selected-img');
    document.getElementById('imgResizeBar').classList.add('show');
}
function deselectImg() {
    if (_selectedImg) _selectedImg.classList.remove('selected-img');
    _selectedImg = null;
    document.getElementById('imgResizeBar').classList.remove('show');
}
function resizeSelectedImg(size) {
    if (!_selectedImg) return;
    if (size === 'inline') {
        _selectedImg.style.display = 'inline';
        _selectedImg.style.maxHeight = '1.4em';
        _selectedImg.style.width = 'auto';
        _selectedImg.style.maxWidth = 'none';
        _selectedImg.style.margin = '0 3px';
        _selectedImg.style.verticalAlign = 'middle';
        _selectedImg.style.borderRadius = '4px';
    } else {
        _selectedImg.style.display = 'block';
        _selectedImg.style.maxWidth = size;
        _selectedImg.style.width = size;
        _selectedImg.style.maxHeight = '';
        _selectedImg.style.height = 'auto';
        _selectedImg.style.margin = '6px 0';
        _selectedImg.style.verticalAlign = '';
    }
}
function removeSelectedImg() {
    if (!_selectedImg) return;
    _selectedImg.remove();
    deselectImg();
}

// Fermer le popover en cliquant ailleurs
document.addEventListener('click', e => {
    const pop = document.getElementById('imgPopover');
    const btn = document.getElementById('imgInsertBtn');
    if (pop && !pop.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
        closeImgPopover();
    }
});

async function sendMail() {
    const to=toTags['to'], cc=toTags['cc'], bcc=toTags['bcc'];
    const subj=document.getElementById('composeSubject').value;
    const body=document.getElementById('composeBody').innerHTML;
    const replyId=document.getElementById('composeBody')._replyToId;
    const type=document.getElementById('composeBody')._type||'normal';
    const fromId=parseInt(document.getElementById('composeFrom').value);
    if(!to.length){ toast('Destinataire requis','error','xmark'); return; }
    const r=await api({action:'send',account_id:fromId,to:JSON.stringify(to),cc:JSON.stringify(cc),bcc:JSON.stringify(bcc),subject:subj,body,reply_to_id:replyId||'',type});
    if(r.ok){ toast('Message envoyé !','success','check'); closeCompose(); loadMessages(); }
    else toast(r.error,'error','xmark');
}

async function saveDraft() {
    const to=toTags['to'], subj=document.getElementById('composeSubject').value;
    const body=document.getElementById('composeBody').innerHTML;
    const fromId=parseInt(document.getElementById('composeFrom').value);
    const r=await api({action:'save_draft',account_id:fromId,to:JSON.stringify(to),cc:'[]',bcc:'[]',subject:subj,body});
    if(r.ok){ toast('Brouillon sauvegardé','info','floppy-disk'); closeCompose(); }
    else toast(r.error,'error','xmark');
}

/* ═══ ACTIONS SUR MESSAGES ═══ */
function toggleSelect(id, cb) {
    if(cb.checked) selectedIds.add(id); else selectedIds.delete(id);
    updateSelectionButtons();
}

function toggleSelectAll(cb) {
    document.querySelectorAll('.msg-check').forEach(c=>{
        c.checked=cb.checked;
        const id=parseInt(c.closest('.msg-row').dataset.id);
        if(cb.checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    updateSelectionButtons();
}

function updateSelectionButtons() {
    const has=selectedIds.size>0;
    ['markReadBtn','markUnreadBtn','trashBtn'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.style.display=has?'':'none';
    });
}

async function markSelectedRead(read) {
    const r=await api({action:'mark_read',account_id:activeAccountId,message_ids:JSON.stringify([...selectedIds]),read});
    if(r.ok){ selectedIds.clear(); loadMessages(); loadFolderCounts(); }
}

async function trashSelected() {
    const r=await api({action:'trash',account_id:activeAccountId,message_ids:JSON.stringify([...selectedIds])});
    if(r.ok){ selectedIds.clear(); toast('Message(s) supprimé(s)','info','trash'); loadMessages(); loadFolderCounts(); }
}

async function trashMsg(id) {
    const r=await api({action:'trash',account_id:activeAccountId,message_ids:JSON.stringify([id])});
    if(r.ok){ toast('Supprimé','info','trash'); closeMessageView(); }
}

async function toggleStar(id, btn) {
    const r=await api({action:'toggle_star',account_id:activeAccountId,message_id:id});
    if(r.ok){
        btn.classList.toggle('starred');
        btn.innerHTML=`<i class="fa-${btn.classList.contains('starred')?'solid':'regular'} fa-star"></i>`;
    }
}

async function toggleStarMsg(id) {
    await api({action:'toggle_star',account_id:activeAccountId,message_id:id});
}

/* ═══ THÈME ═══ */
function setTheme(theme) {
    document.documentElement.setAttribute('data-mail-theme', theme);
    document.querySelectorAll('.theme-opt').forEach(el=>el.classList.toggle('active',el.dataset.theme===theme));
    api({action:'save_theme',account_id:activeAccountId,theme});
    toast('Thème appliqué','success','palette');
}

/* ═══ SIGNATURE ═══ */
async function saveSignature() {
    const sig=document.getElementById('signatureInput').value;
    const r=await api({action:'save_signature',account_id:activeAccountId,signature:sig});
    if(r.ok) toast('Signature sauvegardée','success','check');
    else toast(r.error,'error','xmark');
}

/* ═══ LOGO ═══ */
async function uploadLogo(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const status = document.getElementById('logoUploadStatus');
    status.style.display = 'block';
    status.style.color   = 'var(--text2)';
    status.textContent   = '⏳ Envoi en cours…';

    const fd = new FormData();
    fd.append('action', 'upload_logo');
    fd.append('account_id', activeAccountId);
    fd.append('logo', file);

    try {
        const resp = await fetch('<?php echo SITE_URL; ?>/pages/mail/api.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            // Mettre à jour la prévisualisation
            const wrap = document.getElementById('logoPreviewWrap');
            wrap.innerHTML = `<img id="logoPreview" src="${data.data.logo}?t=${Date.now()}" style="width:100%;height:100%;object-fit:cover;" alt="logo">`;
            document.getElementById('logoDeleteBtn').style.display = 'inline-flex';
            status.style.color   = 'var(--green)';
            status.textContent   = '✓ Logo mis à jour';
            toast('Logo mis à jour', 'success', 'image');
            setTimeout(() => { status.style.display = 'none'; }, 2500);
        } else {
            status.style.color = '#ef4444';
            status.textContent = '✗ ' + data.error;
        }
    } catch(e) {
        status.style.color = '#ef4444';
        status.textContent = '✗ Erreur réseau';
    }
    input.value = '';
}

async function deleteLogo() {
    const r = await api({ action: 'delete_logo', account_id: activeAccountId });
    if (r.ok) {
        const wrap = document.getElementById('logoPreviewWrap');
        wrap.innerHTML = `<i class="fa-solid fa-at" id="logoPreview" style="color:var(--text-muted);font-size:1.2rem;"></i>`;
        document.getElementById('logoDeleteBtn').style.display = 'none';
        toast('Logo supprimé', 'success', 'check');
    } else {
        toast(r.error, 'error', 'xmark');
    }
}

/* ═══ RECHERCHE ═══ */
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>{currentPage=1;loadMessages();},400);
}

/* ═══ NOTIFICATIONS ═══ */
async function loadNotifications() {
    const r=await apiGet({action:'get_notifications'});
    if(!r.ok) return;
    const notifs=r.data||[];
    const badge=document.getElementById('notifBadge');
    badge.textContent=notifs.length;
    badge.style.display=notifs.length?'flex':'none';
    const list=document.getElementById('notifList');
    if(!list) return;
    if(!notifs.length){ list.innerHTML='<div style="padding:16px;text-align:center;font-size:.78rem;color:var(--text3);">Aucune notification</div>'; return; }
    list.innerHTML=notifs.slice(0,5).map(n=>`
        <div class="notif-item" onclick="switchFolder('inbox')">
            <div class="notif-ava">${n.from_logo
                ? `<img src="${SITE_BASE}/assets/${esc(n.from_logo)}" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentElement.innerHTML='${(n.from_address||'?')[0].toUpperCase()}'">`
                : (n.from_address||'?')[0].toUpperCase()
            }</div>
            <div class="notif-body">
                <div class="notif-from">${esc(n.from_address)}</div>
                <div class="notif-sub">${esc(n.subject)}</div>
            </div>
        </div>`).join('');
}

async function markNotifSeen() {
    await api({action:'mark_notifications_seen'});
    loadNotifications();
}

function toggleNotif() {
    const d=document.getElementById('notifDropdown');
    d.classList.toggle('open');
    if(d.classList.contains('open')) markNotifSeen();
}

/* ═══ AUTOCOMPLETE ═══ */
let acTimer=null;
async function suggestAddress(e, listId) {
    clearTimeout(acTimer);
    const q=e.target.value;
    if(q.length<2){ document.getElementById(listId).classList.remove('open'); return; }
    acTimer=setTimeout(async()=>{
        const r=await apiGet({action:'search_address',q});
        const list=document.getElementById(listId);
        if(!r.ok||!r.data.length){ list.classList.remove('open'); return; }
        list.innerHTML=r.data.map(a=>`<div class="ac-item" onclick="selectAc('to','${esc(a.address)}','${listId}')">
            <div>${esc(a.display_name||a.address)}</div><div class="ac-addr">${esc(a.address)}</div>
        </div>`).join('');
        list.classList.add('open');
    },250);
}

function selectAc(field, addr, listId) {
    toTags[field].push(addr);
    renderTags(field);
    document.getElementById(field+'Input').value='';
    document.getElementById(listId).classList.remove('open');
}

/* ═══ CRÉATION / CONNEXION DE COMPTE ═══ */

// Ouvrir le panneau sur l'onglet voulu
function openAddAccountPanel(tab='create') {
    switchAddTab(tab);
    togglePanel('addAccountPanel');
}

function switchAddTab(tab) {
    const isCreate = tab === 'create';
    document.getElementById('tabCreate').classList.toggle('active', isCreate);
    document.getElementById('tabConnect').classList.toggle('active', !isCreate);
    document.getElementById('paneCreate').style.display  = isCreate  ? '' : 'none';
    document.getElementById('paneConnect').style.display = !isCreate ? '' : 'none';
    document.getElementById('addPanelTitle').innerHTML = isCreate
        ? '<i class="fa-solid fa-plus"></i> Nouvelle adresse'
        : '<i class="fa-solid fa-link"></i> Connecter un compte';
}

// Prévisualisation adresse (setup initial)
function updatePreview() {
    const local = document.getElementById('setup-local')?.value || 'votre.nom';
    const el = document.getElementById('setup-preview');
    if (el) el.textContent = `${local || 'votre.nom'}@${SNAME}.${DEFAULT_TLD}`;
}

// Prévisualisation adresse (panneau ajout)
function updateNewPreview() {
    const local = document.getElementById('newLocal')?.value || 'prenom.nom';
    const el = document.getElementById('newAddrPreview');
    if (el) el.textContent = `${local || 'prenom.nom'}@${SNAME}.${DEFAULT_TLD}`;
}

// Créer son premier compte (setup screen)
async function createAccount() {
    const local = document.getElementById('setup-local').value.trim();
    const r = await api({action:'create_account', local, tld:DEFAULT_TLD});
    if (r.ok) { toast('Adresse créée !','success','check'); setTimeout(()=>location.reload(), 800); }
    else toast(r.error,'error','xmark');
}

// Créer un nouveau compte (panneau)
async function createNewAccount() {
    const local = document.getElementById('newLocal').value.trim();
    const r = await api({action:'create_account', local, tld:DEFAULT_TLD});
    if (r.ok) { toast('Adresse créée !','success','check'); setTimeout(()=>location.reload(), 800); }
    else toast(r.error,'error','xmark');
}

// Connecter un compte existant (lier à la session)
function toggleLinkPwd() {
    const inp = document.getElementById('linkPassword');
    const btn = document.getElementById('linkPwdEye');
    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';
    btn.innerHTML = showing ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
}

async function doLinkAccount() {
    const address  = document.getElementById('linkAddress').value.trim().toLowerCase();
    const password = document.getElementById('linkPassword').value;
    if (!address) { toast('Entrez une adresse mail','error','xmark'); return; }
    const r = await api({action:'link_account', address, password});
    if (r.ok) { toast('Compte connecté !','success','link'); setTimeout(()=>location.reload(), 800); }
    else toast(r.error,'error','xmark');
}

// Déconnecter un compte lié (sans le supprimer)
async function unlinkAccount(accountId, address) {
    if (!confirm(`Déconnecter "${address}" de votre session ?\n\nLe compte et ses messages seront conservés.`)) return;
    const r = await api({action:'unlink_account', account_id:accountId});
    if (r.ok) { toast('Compte déconnecté','info','link-slash'); setTimeout(()=>location.reload(), 800); }
    else toast(r.error,'error','xmark');
}

/* ═══ PANNEAUX ═══ */
function togglePanel(id) {
    const p=document.getElementById(id);
    if(p) p.classList.toggle('open');
}
function closePanel(id) {
    const p=document.getElementById(id);
    if(p) p.classList.remove('open');
}

/* ═══ UTILITAIRES ═══ */
function esc(str) {
    if(!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function tryParseJSON(s) { try{ return JSON.parse(s); }catch(e){ return null; } }
function formatDate(dt) {
    if(!dt) return '';
    const d=new Date(dt.replace(' ','T')), now=new Date();
    if(d.toDateString()===now.toDateString()) return d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
    if(now-d < 86400*7*1000) return d.toLocaleDateString('fr-FR',{weekday:'short'});
    return d.toLocaleDateString('fr-FR',{day:'2-digit',month:'short'});
}
function formatDateFull(dt) {
    if(!dt) return '';
    return new Date(dt.replace(' ','T')).toLocaleString('fr-FR',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

// Fermer dropdowns au clic extérieur
document.addEventListener('click', e=>{
    if(!e.target.closest('#notifBtn')&&!e.target.closest('#notifDropdown'))
        document.getElementById('notifDropdown')?.classList.remove('open');
    if(!e.target.closest('#toSuggest')&&!e.target.closest('#toInput'))
        document.getElementById('toSuggest')?.classList.remove('open');
});
</script>
</body>
</html>