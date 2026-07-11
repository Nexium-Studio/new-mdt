<?php
/**
 * Gate DarkNet — Page d'authentification par code hexadécimal.
 * Première connexion (ou session expirée) : l'utilisateur doit saisir son code
 * pour accéder au DarkNet.
 */
require_once dirname(__DIR__, 3) . '/config/config.php';
require_once dirname(__DIR__, 3) . '/includes/darknet_api.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userFaction = (string) ($_SESSION['user_faction'] ?? '');

// Staff bypass → skip gate
if (darknet_is_staff_bypass()) {
    redirect(SITE_URL . '/pages/panels/darknet/dashboard.php');
}

// Faction non-illégale → pas d'accès
if (!userHasDarknetAccess()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Déjà authentifié → on le renvoie direct
if (darknet_user_session($db, $userId, $userFaction) !== null) {
    redirect(SITE_URL . '/pages/panels/darknet/dashboard.php');
}

$error = '';
$csrf = darknet_ensure_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!darknet_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée. Recharge la page.';
    } else {
        $code = (string) ($_POST['access_code'] ?? '');
        // Anti-bruteforce : max 5 tentatives/5min
        $throttleKey = 'darknet_throttle_' . $userId;
        $attempts = $_SESSION[$throttleKey] ?? ['count' => 0, 'until' => 0];
        if ($attempts['count'] >= 5 && time() < $attempts['until']) {
            $error = 'Trop de tentatives. Réessaie dans ' . max(1, (int) (($attempts['until'] - time()) / 60)) . ' minute(s).';
        } else {
            $res = darknet_try_authenticate($db, $userId, $userFaction, $code);
            if ($res['ok']) {
                unset($_SESSION[$throttleKey]);
                redirect(SITE_URL . '/pages/panels/darknet/dashboard.php');
            } else {
                $attempts['count']++;
                if ($attempts['count'] >= 5) {
                    $attempts['until'] = time() + 300;
                }
                $_SESSION[$throttleKey] = $attempts;
                $error = $res['error'] ?? 'Code refusé.';
            }
        }
    }
}

// Nom de la faction (pour affichage)
$factionInfo = ['name' => $userFaction, 'color' => '#7c3aed', 'icon' => 'user-secret'];
try {
    $stmt = $db->prepare("SELECT name, color, icon FROM factions WHERE faction_key = ? LIMIT 1");
    $stmt->execute([$userFaction]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $factionInfo['name'] = $row['name'] ?: $userFaction;
        $factionInfo['color'] = $row['color'] ?: '#7c3aed';
        $factionInfo['icon'] = $row['icon'] ?: 'user-secret';
    }
} catch (Throwable $e) {}

$pageTitle = 'DarkNet — Accès';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=JetBrains+Mono:wght@400;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{
    font-family:'JetBrains Mono',monospace;
    background:#030409;
    color:#c7d2e5;
    overflow:hidden;
    position:relative;
}
/* Matrix-like background */
body::before{
    content:'';position:fixed;inset:0;
    background:
        radial-gradient(circle at 15% 20%,rgba(124,58,237,.18),transparent 45%),
        radial-gradient(circle at 85% 75%,rgba(6,182,212,.15),transparent 50%),
        radial-gradient(circle at 50% 50%,rgba(0,0,0,.4),transparent 70%),
        linear-gradient(180deg,#020309 0%,#050712 100%);
    z-index:-2;
}
body::after{
    content:'';position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(124,58,237,.06) 1px,transparent 1px),
        linear-gradient(90deg,rgba(124,58,237,.06) 1px,transparent 1px);
    background-size:42px 42px;
    z-index:-1;
    animation:gridMove 32s linear infinite;
}
@keyframes gridMove{from{background-position:0 0,0 0}to{background-position:42px 42px,42px 42px}}

.scan{
    position:fixed;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,transparent,#a78bfa,#22d3ee,transparent);
    box-shadow:0 0 20px #a78bfa;opacity:.55;
    animation:scanMove 5s ease-in-out infinite;z-index:1;
}
@keyframes scanMove{0%,100%{top:0}50%{top:100%}}

.gate{
    position:relative;
    display:flex;align-items:center;justify-content:center;
    min-height:100vh;padding:20px;z-index:2;
}
.panel{
    width:min(480px,100%);
    background:linear-gradient(160deg,rgba(15,10,35,.95),rgba(5,7,18,.95));
    border:1px solid rgba(124,58,237,.45);
    border-radius:20px;
    padding:36px 32px 28px;
    box-shadow:
        0 0 0 1px rgba(124,58,237,.15),
        0 30px 80px rgba(0,0,0,.6),
        0 0 120px rgba(124,58,237,.18);
    position:relative;overflow:hidden;
}
.panel::before{
    content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;
    background:conic-gradient(from 0deg,transparent 0%,rgba(124,58,237,.2) 25%,transparent 50%,rgba(6,182,212,.18) 75%,transparent 100%);
    animation:spin 14s linear infinite;opacity:.5;
}
.panel::after{
    content:'';position:absolute;inset:2px;background:linear-gradient(160deg,rgba(15,10,35,.96),rgba(5,7,18,.98));border-radius:18px;
}
@keyframes spin{to{transform:rotate(360deg)}}
.panel > *{position:relative;z-index:1}

.brand{text-align:center;margin-bottom:22px}
.brand .logo{
    width:68px;height:68px;margin:0 auto 14px;border-radius:20px;
    background:linear-gradient(135deg,rgba(124,58,237,.3),rgba(6,182,212,.18));
    border:1px solid rgba(167,139,250,.55);
    display:flex;align-items:center;justify-content:center;
    font-size:1.8rem;color:#fff;
    box-shadow:0 0 35px rgba(124,58,237,.4),inset 0 1px 0 rgba(255,255,255,.1);
}
.brand h1{
    font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;
    letter-spacing:6px;text-transform:uppercase;color:#fff;
    background:linear-gradient(90deg,#a78bfa,#22d3ee);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.brand .sub{
    font-family:'Share Tech Mono',monospace;
    font-size:.72rem;color:#64748b;text-transform:uppercase;
    letter-spacing:3px;margin-top:4px
}
.faction-chip{
    display:inline-flex;align-items:center;gap:8px;padding:7px 14px;
    background:rgba(124,58,237,.14);border:1px solid rgba(167,139,250,.35);
    border-radius:20px;font-size:.75rem;color:#c4b5fd;text-transform:uppercase;letter-spacing:1.5px;
    margin-top:14px;
}

.intro{color:#94a3b8;font-size:.85rem;line-height:1.6;text-align:center;margin:22px 0 24px;padding:0 8px}
.intro strong{color:#a78bfa}

.field{position:relative;margin-bottom:14px}
.field label{
    font-family:'Share Tech Mono',monospace;
    display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:2px;
    color:#94a3b8;margin-bottom:8px;
}
.field label::before{content:'> ';color:#22d3ee}
.field input{
    width:100%;padding:14px 16px;
    background:rgba(2,4,12,.8);
    border:1px solid rgba(148,163,184,.2);
    border-radius:10px;
    color:#a7f3d0;
    font-family:'JetBrains Mono',monospace;font-size:1.05rem;
    letter-spacing:4px;
    transition:all .2s;
}
.field input:focus{
    outline:none;border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(167,139,250,.15),0 0 30px rgba(167,139,250,.15);
    background:rgba(5,7,18,.95);
}
.field input::placeholder{color:rgba(148,163,184,.35);letter-spacing:2px}

.error{
    padding:10px 14px;margin-bottom:14px;
    background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);
    border-radius:10px;color:#fca5a5;font-size:.82rem;
    display:flex;align-items:center;gap:9px;
}

.btn-auth{
    width:100%;padding:14px;
    background:linear-gradient(135deg,#7c3aed,#22d3ee);
    border:none;border-radius:12px;cursor:pointer;
    color:#fff;font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem;
    letter-spacing:3px;text-transform:uppercase;
    transition:all .2s;position:relative;overflow:hidden;
}
.btn-auth:hover{transform:translateY(-2px);box-shadow:0 15px 40px rgba(124,58,237,.5)}
.btn-auth:active{transform:translateY(0)}
.btn-auth i{margin-right:8px}

.back{
    display:block;text-align:center;margin-top:18px;
    color:#64748b;font-size:.75rem;text-decoration:none;
    text-transform:uppercase;letter-spacing:2px;transition:color .15s;
}
.back:hover{color:#c4b5fd}

.footer-info{
    margin-top:22px;padding-top:18px;
    border-top:1px solid rgba(148,163,184,.08);
    text-align:center;font-family:'Share Tech Mono',monospace;
    font-size:.68rem;color:#475569;letter-spacing:1.5px;
}

.sys-lines{
    position:absolute;top:8px;left:12px;font-family:'Share Tech Mono',monospace;
    font-size:.55rem;color:#475569;line-height:1.4;
}

@media(max-width:500px){
    .panel{padding:28px 22px 22px}
    .brand h1{font-size:1.3rem;letter-spacing:4px}
    .field input{font-size:.9rem;letter-spacing:2px}
}
</style>
</head>
<body>

<div class="scan"></div>

<div class="gate">
    <form method="post" class="panel" autocomplete="off">
        <div class="sys-lines">
            [sys] darknet.core v1.0<br>
            [sys] encryption : aes-256<br>
            [sys] auth : required
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">

        <div class="brand">
            <div class="logo"><i class="fa-solid fa-network-wired"></i></div>
            <h1>DarkNet</h1>
            <div class="sub">encrypted · anonymous · unseen</div>
            <div class="faction-chip" style="background:<?= htmlspecialchars($factionInfo['color']); ?>22;border-color:<?= htmlspecialchars($factionInfo['color']); ?>55;color:<?= htmlspecialchars($factionInfo['color']); ?>">
                <i class="fa-solid fa-<?= htmlspecialchars($factionInfo['icon']); ?>"></i>
                <?= htmlspecialchars($factionInfo['name']); ?>
            </div>
        </div>

        <p class="intro">
            Authentification par <strong>code sécurisé</strong>.<br>
            Saisis le code qui t'a été communiqué par ton chef ou le staff.
        </p>

        <?php if ($error): ?>
        <div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="field">
            <label for="access_code">Access code</label>
            <input type="password" name="access_code" id="access_code"
                   placeholder="xxxxxxxxxxxxxxxx" autofocus required
                   maxlength="64" pattern="[a-fA-F0-9\s-]{8,}"
                   inputmode="latin">
        </div>

        <button type="submit" class="btn-auth"><i class="fa-solid fa-unlock-keyhole"></i> Authentifier</button>

        <a href="<?= SITE_URL; ?>/pages/dashboard.php" class="back">← Retour au MDT</a>

        <div class="footer-info">
            [ ip: <?= htmlspecialchars(substr((string) ($_SERVER['REMOTE_ADDR'] ?? '—'), 0, 15)); ?> ] · [ session: <?= substr(session_id(), 0, 8); ?>… ]
        </div>
    </form>
</div>

<script>
// Affichage du code en clair/masqué
const inp=document.getElementById('access_code');
inp.addEventListener('focus',()=>inp.type='text');
inp.addEventListener('blur',()=>{if(!inp.value)inp.type='password'});

// Anti-paste glitch : normalisation du code
inp.addEventListener('input',()=>{
    const cleaned=inp.value.toLowerCase().replace(/[^a-f0-9]/g,'');
    if (cleaned!==inp.value) inp.value=cleaned;
});
</script>

</body>
</html>
