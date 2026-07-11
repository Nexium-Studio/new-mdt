<?php
/**
 * discord-ingame.php
 * Affiché DANS la tablette FiveM — remplace le bouton Discord direct
 * Chemin : auth/discord-ingame.php
 *
 * Ce fichier :
 *  1. Génère un code de liaison 6 chiffres pour l'utilisateur connecté
 *  2. Affiche le code + l'URL où aller sur le vrai navigateur
 *  3. Poll toutes les 3s pour détecter quand la liaison est faite
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/remember_auth.php';

if (!isLoggedIn()) {
    redirect(SITE_URL . '/login.php');
}

$db     = Database::getInstance()->getConnection();
$userId = (int) $_SESSION['user_id'];

/* Vérifier si déjà lié à Discord */
$stmt = $db->prepare("SELECT discord_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($user['discord_id'])) {
    /* Déjà lié — retour au dashboard */
    redirect(SITE_URL . '/pages/dashboard.php?discord=already_linked');
}

/* ── AJAX : vérifier si la liaison a été faite ── */
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    $stmt = $db->prepare("SELECT discord_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['linked' => !empty($u['discord_id'])]);
    exit;
}

/* ── Générer ou récupérer un code actif ── */
$db->prepare("DELETE FROM discord_link_codes WHERE user_id = ? OR expires_at < NOW()")->execute([$userId]);

$code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes
$db->prepare("INSERT INTO discord_link_codes (code, user_id, expires_at) VALUES (?, ?, ?)")
   ->execute([$code, $userId, $expires]);

$linkUrl = SITE_URL . '/auth/discord-link.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Liaison Discord — MDT</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;
     background:linear-gradient(135deg,#07101f,#0d1b2e);font-family:'Segoe UI',sans-serif;color:#e2e8f0;}
.card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:16px;
      padding:2.5rem 2rem;max-width:420px;width:100%;text-align:center;}
h1{font-size:1.3rem;font-weight:700;margin-bottom:.5rem;color:#fff;}
.sub{color:#94a3b8;font-size:.85rem;margin-bottom:2rem;line-height:1.5;}
.code-box{
    background:rgba(88,101,242,.12);border:2px solid rgba(88,101,242,.4);
    border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;
}
.code{font-size:2.8rem;font-weight:900;letter-spacing:.5rem;color:#7c8df8;
      font-family:'Courier New',monospace;}
.code-hint{font-size:.72rem;color:#64748b;margin-top:.5rem;}
.url-box{
    background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);
    border-radius:10px;padding:1rem;margin-bottom:1.5rem;
    display:flex;align-items:center;gap:.75rem;
}
.url-text{flex:1;color:#00e5ff;font-size:.85rem;font-family:'Courier New',monospace;
           word-break:break-all;text-align:left;}
.copy-btn{
    flex-shrink:0;background:rgba(0,229,255,.1);border:1px solid rgba(0,229,255,.3);
    color:#00e5ff;padding:.4rem .75rem;border-radius:6px;font-size:.75rem;cursor:pointer;
    transition:background .15s;
}
.copy-btn:hover{background:rgba(0,229,255,.2);}
.status{
    display:flex;align-items:center;justify-content:center;gap:.5rem;
    color:#94a3b8;font-size:.8rem;margin-top:1rem;
}
.dot{width:8px;height:8px;border-radius:50%;background:#22c55e;animation:pulse 1.5s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.timer{color:#f59e0b;font-size:.75rem;margin-top:.75rem;}
.steps{text-align:left;margin-bottom:1.5rem;}
.step{display:flex;align-items:flex-start;gap:.6rem;margin-bottom:.6rem;font-size:.82rem;color:#94a3b8;}
.step-num{flex-shrink:0;width:20px;height:20px;border-radius:50%;background:rgba(88,101,242,.3);
           color:#7c8df8;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.success-screen{display:none;text-align:center;}
.success-icon{font-size:4rem;margin-bottom:1rem;}
.back-btn{
    display:inline-block;margin-top:1.5rem;padding:.75rem 2rem;
    background:linear-gradient(135deg,rgba(0,229,255,.18),rgba(0,100,200,.18));
    border:1px solid rgba(0,229,255,.35);color:#00e5ff;border-radius:10px;
    font-weight:700;font-size:.9rem;cursor:pointer;text-decoration:none;
    transition:background .15s;
}
.back-btn:hover{background:linear-gradient(135deg,rgba(0,229,255,.28),rgba(0,100,200,.28));}
</style>
</head>
<body>
<div class="card">

    <!-- Écran principal -->
    <div id="main-screen">
        <h1>🔗 Lier ton compte Discord</h1>
        <p class="sub">Le navigateur intégré au jeu ne peut pas accéder à Discord.<br>
        Suis les étapes ci-dessous depuis ton <strong>PC ou téléphone</strong>.</p>

        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <span>Ouvre <strong>ton navigateur web</strong> en dehors du jeu</span>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <span>Va sur l'adresse ci-dessous et entre le code</span>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <span>Connecte-toi à Discord — ton compte sera lié automatiquement</span>
            </div>
        </div>

        <div class="url-box">
            <span class="url-text" id="linkUrl"><?php echo htmlspecialchars($linkUrl); ?></span>
            <button class="copy-btn" onclick="copyUrl()">Copier</button>
        </div>

        <div class="code-box">
            <div class="code" id="linkCode"><?php echo $code; ?></div>
            <div class="code-hint">Entre ce code sur la page ci-dessus</div>
        </div>

        <div class="timer" id="timer">⏳ Code valable 10:00</div>

        <div class="status">
            <div class="dot"></div>
            <span>En attente de liaison...</span>
        </div>
    </div>

    <!-- Écran succès -->
    <div class="success-screen" id="success-screen">
        <div class="success-icon">✅</div>
        <h1>Compte Discord lié !</h1>
        <p class="sub">Tu peux maintenant te connecter au MDT avec Discord.</p>
        <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="back-btn">Retour au MDT</a>
    </div>

</div>

<script>
const CHECK_URL = '<?php echo SITE_URL; ?>/auth/discord-ingame.php?check=1';
let timeLeft = 600; // secondes

/* Polling — vérifie la liaison toutes les 3 secondes */
const pollInterval = setInterval(async () => {
    try {
        const r = await fetch(CHECK_URL);
        const d = await r.json();
        if (d.linked) {
            clearInterval(pollInterval);
            clearInterval(timerInterval);
            document.getElementById('main-screen').style.display = 'none';
            document.getElementById('success-screen').style.display = 'block';
        }
    } catch(e) {}
}, 3000);

/* Compte à rebours */
const timerInterval = setInterval(() => {
    timeLeft--;
    const m = Math.floor(timeLeft / 60).toString().padStart(2, '0');
    const s = (timeLeft % 60).toString().padStart(2, '0');
    document.getElementById('timer').textContent = `⏳ Code valable ${m}:${s}`;
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        document.getElementById('timer').textContent = '❌ Code expiré — recharge la page pour en générer un nouveau.';
        document.getElementById('timer').style.color = '#ef4444';
    }
}, 1000);

/* Copier l'URL */
function copyUrl() {
    const url = document.getElementById('linkUrl').textContent;
    navigator.clipboard?.writeText(url).then(() => {
        const btn = document.querySelector('.copy-btn');
        btn.textContent = '✓ Copié';
        setTimeout(() => btn.textContent = 'Copier', 2000);
    }).catch(() => {
        /* Fallback pour FiveM CEF */
        const ta = document.createElement('textarea');
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        const btn = document.querySelector('.copy-btn');
        btn.textContent = '✓ Copié';
        setTimeout(() => btn.textContent = 'Copier', 2000);
    });
}
</script>
</body>
</html>