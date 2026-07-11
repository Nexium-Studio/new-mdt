<?php
/**
 * Header DarkNet — thème cyber custom partagé par toutes les pages /pages/panels/darknet/.
 * Prérequis : $pageTitle (optionnel), $darknetSession (array retourné par darknet_require_auth()).
 */
if (!isset($darknetSession) || !is_array($darknetSession)) {
    exit('DarkNet : session requise.');
}

require_once dirname(__DIR__, 4) . '/includes/mdt_display_name.php';

$__dnFactionKey = $darknetSession['faction_key'] ?? '';
$__dnRole = $darknetSession['role'] ?? 'member';
$__dnIsLeader = ($__dnRole === 'leader') || !empty($darknetSession['staff_bypass']);
$__dnIsStaff = !empty($darknetSession['staff_bypass']);
$__dnGrade = $darknetSession['grade'] ?? '—';

// Liste des factions illégales disponibles pour le staff switcher
$__dnAllFactions = [];
if ($__dnIsStaff) {
    try {
        $__dnAllFactions = darknet_get_illegal_factions(Database::getInstance()->getConnection());
    } catch (Throwable $e) {}
}

// Infos faction pour thème
$__dnFactionName = $__dnFactionKey;
$__dnFactionColor = '#a78bfa';
$__dnFactionIcon = 'user-secret';
try {
    $__dnDb = Database::getInstance()->getConnection();
    $stmt = $__dnDb->prepare("SELECT name, color, icon FROM factions WHERE faction_key = ? LIMIT 1");
    $stmt->execute([$__dnFactionKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $__dnFactionName  = $row['name']  ?: $__dnFactionKey;
        $__dnFactionColor = $row['color'] ?: '#a78bfa';
        $__dnFactionIcon  = $row['icon']  ?: 'user-secret';
    }
} catch (Throwable $e) {}

$__dnCurrent = basename($_SERVER['PHP_SELF'], '.php');
$__dnBase = SITE_URL . '/pages/panels/darknet';
$__dnPrivateBase = SITE_URL . '/pages/panels/darknet/faction';
$__dnCommonBase = SITE_URL . '/pages/panels/darknet/common';

// Liste des sections (utilisé pour la sidebar)
$__dnNavPrivate = [
    ['file' => 'faction/effectif',  'label' => 'Effectif',  'icon' => 'users-rectangle', 'leader_only' => false],
    ['file' => 'faction/stock',     'label' => 'Stock',     'icon' => 'box-archive',     'leader_only' => false],
    ['file' => 'faction/bank',      'label' => 'Bank',      'icon' => 'sack-dollar',     'leader_only' => false],
];
$__dnNavCommon = [
    ['file' => 'common/black-market',  'label' => 'Black Market',   'icon' => 'basket-shopping'],
    ['file' => 'common/contrats',      'label' => 'Contrats',       'icon' => 'crosshairs'],
    ['file' => 'common/leaks',         'label' => 'Leaks Center',   'icon' => 'user-ninja'],
    ['file' => 'common/laboratoire',   'label' => 'Laboratoire',    'icon' => 'flask'],
    ['file' => 'common/chat',          'label' => 'Encrypted Chat', 'icon' => 'comments'],
    ['file' => 'common/wanted',        'label' => 'Wanted List',    'icon' => 'file-invoice'],
    ['file' => 'common/reseau',        'label' => 'Le Réseau',      'icon' => 'diagram-project'],
    ['file' => 'common/wiki',          'label' => 'Wiki Criminel',  'icon' => 'book-skull'],
];

$__dnDisplayName = mdt_rp_display_name(
    $_SESSION['user_firstname'] ?? null,
    $_SESSION['user_lastname'] ?? null,
    $_SESSION['user_username'] ?? 'Anonyme'
);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($pageTitle ?? 'Dashboard') . ' — DarkNet'); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=JetBrains+Mono:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --dn-bg-0:#050610;
    --dn-bg-1:#0a0c18;
    --dn-bg-2:#111428;
    --dn-border:rgba(148,163,184,.12);
    --dn-border-hot:rgba(167,139,250,.35);
    --dn-text:#e2e8f0;
    --dn-text-dim:#94a3b8;
    --dn-text-mute:#64748b;
    --dn-accent:#a78bfa;
    --dn-accent-2:#22d3ee;
    --dn-accent-rgb:167,139,250;
    --dn-faction:<?= htmlspecialchars($__dnFactionColor); ?>;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{
    font-family:'JetBrains Mono',monospace;
    background:var(--dn-bg-0);color:var(--dn-text);
    min-height:100vh;position:relative;overflow-x:hidden;
}
body::before{
    content:'';position:fixed;inset:0;pointer-events:none;z-index:-2;
    background:
        radial-gradient(circle at 15% 12%,rgba(124,58,237,.12),transparent 40%),
        radial-gradient(circle at 88% 85%,rgba(6,182,212,.1),transparent 45%),
        linear-gradient(180deg,#030409 0%,#070914 100%);
}
body::after{
    content:'';position:fixed;inset:0;pointer-events:none;z-index:-1;
    background-image:
        linear-gradient(rgba(124,58,237,.04) 1px,transparent 1px),
        linear-gradient(90deg,rgba(124,58,237,.04) 1px,transparent 1px);
    background-size:44px 44px;
}

/* ═══ Sidebar ═════════════════════════════════════════════════════════════ */
.dn-sb{
    position:fixed;left:0;top:0;width:268px;height:100vh;
    background:linear-gradient(180deg,#0a0c1a,#05070f);
    border-right:1px solid rgba(124,58,237,.22);
    overflow-y:auto;z-index:1000;
    box-shadow:0 0 45px rgba(0,0,0,.55),inset -1px 0 0 rgba(167,139,250,.08);
}
.dn-sb::before{
    content:'';position:absolute;top:0;right:0;width:1px;height:100%;
    background:linear-gradient(180deg,transparent,rgba(167,139,250,.55),transparent);opacity:.55;
}
.dn-sb::-webkit-scrollbar{width:5px}
.dn-sb::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(167,139,250,.4),rgba(6,182,212,.3));border-radius:10px}

.dn-head{padding:22px 20px 18px;border-bottom:1px solid rgba(148,163,184,.08);position:relative}
.dn-head::after{content:'';position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:55%;height:1px;
    background:linear-gradient(90deg,transparent,rgba(167,139,250,.45),transparent)}
.dn-brand{display:flex;align-items:center;gap:12px}
.dn-brand .logo{
    width:48px;height:48px;border-radius:13px;
    background:linear-gradient(135deg,rgba(124,58,237,.32),rgba(6,182,212,.18));
    border:1px solid rgba(167,139,250,.55);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:1.3rem;
    box-shadow:0 0 25px rgba(124,58,237,.4);
}
.dn-brand .t{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;letter-spacing:3px;color:#fff;
    background:linear-gradient(90deg,#a78bfa,#22d3ee);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.dn-brand .s{font-family:'Share Tech Mono',monospace;font-size:.62rem;color:#64748b;letter-spacing:2px;text-transform:uppercase;margin-top:3px}

.dn-faction-chip{
    margin:14px 20px 0;padding:9px 12px;border-radius:10px;
    background:color-mix(in srgb,var(--dn-faction) 15%,transparent);
    border:1px solid color-mix(in srgb,var(--dn-faction) 35%,transparent);
    display:flex;align-items:center;gap:10px;font-size:.78rem;
    color:var(--dn-faction);font-weight:600;letter-spacing:1px;text-transform:uppercase;
}
.dn-faction-chip i{font-size:.85rem}
.dn-faction-chip .role{
    margin-left:auto;font-size:.6rem;padding:2px 7px;border-radius:10px;
    background:rgba(0,0,0,.35);letter-spacing:1.2px;
    color:<?= $__dnIsLeader ? '#fcd34d' : '#c4b5fd'; ?>;
}

.dn-nav{padding:14px 0 24px}
.dn-sect{
    padding:14px 20px 6px;font-family:'Share Tech Mono',monospace;
    font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:3px;
    color:#475569;display:flex;align-items:center;gap:8px;
}
.dn-sect::before{content:'';width:4px;height:4px;border-radius:50%;background:var(--dn-accent);box-shadow:0 0 6px var(--dn-accent)}
.dn-sect.common::before{background:#22d3ee;box-shadow:0 0 6px #22d3ee}

.dn-link{
    display:flex;align-items:center;gap:11px;padding:9px 20px;
    color:#94a3b8;text-decoration:none;font-size:.82rem;font-weight:500;
    transition:all .18s cubic-bezier(.4,0,.2,1);position:relative;border-left:2px solid transparent;
}
.dn-link i{width:16px;text-align:center;font-size:.85rem;opacity:.75;transition:opacity .15s}
.dn-link:hover{color:#e2e8f0;background:rgba(124,58,237,.08);border-left-color:rgba(167,139,250,.35);padding-left:24px}
.dn-link:hover i{opacity:1;color:var(--dn-accent)}
.dn-link.active{
    color:#fff;background:linear-gradient(90deg,rgba(124,58,237,.2),rgba(124,58,237,.05));
    border-left-color:var(--dn-accent);padding-left:24px;
}
.dn-link.active i{color:var(--dn-accent);filter:drop-shadow(0 0 6px rgba(167,139,250,.6))}
.dn-link.active::after{
    content:'';position:absolute;right:14px;top:50%;transform:translateY(-50%);
    width:5px;height:5px;border-radius:50%;background:var(--dn-accent);
    box-shadow:0 0 8px var(--dn-accent);animation:dnPulse 1.5s ease-in-out infinite;
}
@keyframes dnPulse{0%,100%{opacity:.4}50%{opacity:1}}
.dn-link.locked{opacity:.4;pointer-events:none}
.dn-link.locked::before{content:'\f023';font-family:'Font Awesome 6 Free';font-weight:900;margin-right:6px;font-size:.65rem;color:#64748b}

.dn-divider{height:1px;margin:12px 20px;background:linear-gradient(90deg,transparent,rgba(148,163,184,.18),transparent)}

.dn-foot{
    position:sticky;bottom:0;padding:14px 18px;background:#05070f;
    border-top:1px solid rgba(148,163,184,.08);
}
.dn-user{display:flex;gap:10px;align-items:center;padding:10px 12px;background:rgba(124,58,237,.07);border:1px solid rgba(167,139,250,.2);border-radius:11px}
.dn-user .avt{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--dn-faction),#2a1050);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.85rem}
.dn-user .n{font-size:.78rem;color:#e2e8f0;font-weight:600;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dn-user .g{font-size:.62rem;color:#94a3b8;text-transform:uppercase;letter-spacing:1.3px;margin-top:2px}

/* ═══ Main content ════════════════════════════════════════════════════════ */
.dn-main{margin-left:268px;min-height:100vh;position:relative}
.dn-top{
    position:sticky;top:0;z-index:100;
    background:rgba(5,7,15,.92);backdrop-filter:blur(12px);
    border-bottom:1px solid rgba(124,58,237,.15);
    padding:18px 30px;
    display:flex;justify-content:space-between;align-items:center;gap:20px;
}
.dn-top::after{
    content:'';position:absolute;left:0;right:0;bottom:0;height:1px;
    background:linear-gradient(90deg,transparent,var(--dn-accent) 40%,var(--dn-accent-2) 60%,transparent);opacity:.4;
}
.dn-title{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#fff;display:flex;align-items:center;gap:12px}
.dn-title i{color:var(--dn-accent);font-size:1rem}
.dn-crumbs{font-family:'Share Tech Mono',monospace;font-size:.65rem;color:#64748b;letter-spacing:1.5px;margin-top:3px}

.dn-top-right{display:flex;align-items:center;gap:10px}
.dn-badge{
    padding:6px 12px;border-radius:20px;font-size:.68rem;font-weight:600;
    text-transform:uppercase;letter-spacing:1.5px;
    display:inline-flex;align-items:center;gap:5px;
}
.dn-badge.live{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac}
.dn-badge.live::before{content:'';width:6px;height:6px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e;animation:dnPulse 1.5s ease infinite}
.dn-badge.faction{background:color-mix(in srgb,var(--dn-faction) 14%,transparent);color:var(--dn-faction);border:1px solid color-mix(in srgb,var(--dn-faction) 30%,transparent)}
.dn-staff-sw{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35);color:#fde68a;padding:6px 12px;border-radius:20px;font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:600;cursor:pointer;letter-spacing:1px}
.dn-staff-sw:focus{outline:none;border-color:#fbbf24;box-shadow:0 0 0 3px rgba(251,191,36,.15)}
.dn-staff-sw option{background:#0f172a;color:#e2e8f0;letter-spacing:normal}

.dn-content{padding:24px 30px 60px;max-width:1500px}

/* ═══ Common utils ════════════════════════════════════════════════════════ */
.dn-card{
    background:linear-gradient(160deg,rgba(13,17,33,.85),rgba(7,9,22,.95));
    border:1px solid rgba(148,163,184,.1);
    border-radius:16px;padding:22px 24px;position:relative;overflow:hidden;
}
.dn-card:hover{border-color:rgba(167,139,250,.25)}
.dn-card-h{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.dn-card-h .ic{width:36px;height:36px;border-radius:10px;background:rgba(124,58,237,.15);border:1px solid rgba(167,139,250,.3);display:flex;align-items:center;justify-content:center;color:var(--dn-accent)}
.dn-card-h h3{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}

.dn-btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:9px 16px;border-radius:10px;cursor:pointer;
    background:rgba(124,58,237,.12);border:1px solid rgba(167,139,250,.28);
    color:#c4b5fd;font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:600;
    text-decoration:none;transition:all .18s;
}
.dn-btn:hover{background:rgba(124,58,237,.22);color:#fff;transform:translateY(-1px);box-shadow:0 8px 18px rgba(124,58,237,.25)}
.dn-btn.accent{background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border-color:transparent}
.dn-btn.cyan{background:rgba(6,182,212,.12);border-color:rgba(6,182,212,.28);color:#67e8f9}
.dn-btn.danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.28);color:#fca5a5}

@media(max-width:960px){
    .dn-sb{transform:translateX(-100%);transition:transform .3s}
    .dn-sb.show{transform:translateX(0)}
    .dn-main{margin-left:0}
}
</style>
</head>
<body>

<aside class="dn-sb" id="dnSidebar">
    <div class="dn-head">
        <div class="dn-brand">
            <div class="logo"><i class="fa-solid fa-network-wired"></i></div>
            <div>
                <div class="t">DarkNet</div>
                <div class="s">encrypted · anon</div>
            </div>
        </div>
    </div>

    <div class="dn-faction-chip">
        <i class="fa-solid fa-<?= htmlspecialchars($__dnFactionIcon); ?>"></i>
        <?= htmlspecialchars($__dnFactionName); ?>
        <span class="role"><?= !empty($darknetSession['staff_bypass']) ? 'Staff' : ($__dnIsLeader ? 'Leader' : 'Member'); ?></span>
    </div>

    <nav class="dn-nav">
        <a href="<?= $__dnBase; ?>/dashboard.php" class="dn-link <?= $__dnCurrent === 'dashboard' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-simple"></i> Dashboard
        </a>

        <div class="dn-sect">Privé · <?= htmlspecialchars($__dnFactionName); ?></div>
        <?php foreach ($__dnNavPrivate as $item):
            $f = basename($item['file']);
            $active = str_starts_with($_SERVER['PHP_SELF'], '/pages/panels/darknet/' . $item['file'] . '.php') ? 'active' : '';
            $locked = $item['leader_only'] && !$__dnIsLeader ? 'locked' : '';
        ?>
            <a href="<?= $__dnBase . '/' . $item['file']; ?>.php" class="dn-link <?= $active; ?> <?= $locked; ?>">
                <i class="fa-solid fa-<?= htmlspecialchars($item['icon']); ?>"></i> <?= htmlspecialchars($item['label']); ?>
            </a>
        <?php endforeach; ?>

        <div class="dn-sect common">Commun · All factions</div>
        <?php foreach ($__dnNavCommon as $item):
            $active = str_starts_with($_SERVER['PHP_SELF'], '/pages/panels/darknet/' . $item['file'] . '.php') ? 'active' : '';
        ?>
            <a href="<?= $__dnBase . '/' . $item['file']; ?>.php" class="dn-link <?= $active; ?>">
                <i class="fa-solid fa-<?= htmlspecialchars($item['icon']); ?>"></i> <?= htmlspecialchars($item['label']); ?>
            </a>
        <?php endforeach; ?>

        <div class="dn-divider"></div>

        <a href="<?= SITE_URL; ?>/pages/panels/darknet/logout.php" class="dn-link">
            <i class="fa-solid fa-right-from-bracket"></i> Se déconnecter
        </a>
        <a href="<?= SITE_URL; ?>/pages/dashboard.php" class="dn-link">
            <i class="fa-solid fa-house"></i> Accueil MDT
        </a>
    </nav>

    <div class="dn-foot">
        <div class="dn-user">
            <div class="avt"><?= htmlspecialchars(strtoupper(mb_substr($__dnDisplayName, 0, 1))); ?></div>
            <div>
                <div class="n"><?= htmlspecialchars($__dnDisplayName); ?></div>
                <div class="g"><?= htmlspecialchars($__dnGrade); ?></div>
            </div>
        </div>
    </div>
</aside>

<main class="dn-main">
    <div class="dn-top">
        <div>
            <div class="dn-title"><i class="fa-solid fa-terminal"></i> <?= htmlspecialchars($pageTitle ?? 'Dashboard'); ?></div>
            <div class="dn-crumbs">~ darknet / <?= htmlspecialchars(strtolower($__dnFactionKey)); ?> / <?= htmlspecialchars($__dnCurrent); ?></div>
        </div>
        <div class="dn-top-right">
            <?php if ($__dnIsStaff && count($__dnAllFactions) > 0): ?>
            <form method="post" action="<?= SITE_URL; ?>/pages/panels/darknet/switch_faction.php" style="display:flex;gap:8px;align-items:center">
                <span class="dn-badge" style="background:rgba(251,191,36,.14);color:#fbbf24;border:1px solid rgba(251,191,36,.35)"><i class="fa-solid fa-user-shield"></i> Staff view</span>
                <select name="faction_key" onchange="this.form.submit()" class="dn-staff-sw">
                    <?php foreach ($__dnAllFactions as $f): ?>
                        <option value="<?= htmlspecialchars($f['faction_key']); ?>" <?= $f['faction_key'] === $__dnFactionKey ? 'selected' : ''; ?>><?= htmlspecialchars($f['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php else: ?>
            <span class="dn-badge faction"><i class="fa-solid fa-<?= htmlspecialchars($__dnFactionIcon); ?>"></i> <?= htmlspecialchars($__dnFactionName); ?></span>
            <?php endif; ?>
            <span class="dn-badge live">Online</span>
        </div>
    </div>
    <div class="dn-content">
