<?php
/**
 * discord-link.php
 * Page de liaison Discord via code — utilisée DEPUIS UN VRAI NAVIGATEUR
 * Chemin : auth/discord-link.php
 *
 * Flux FiveM :
 *  1. Joueur ouvre la tablette → voit le code 6 chiffres (généré par discord-ingame.php)
 *  2. Joueur ouvre SITE_URL/auth/discord-link.php sur son PC/téléphone
 *  3. Entre le code → clique "Lier avec Discord"
 *  4. OAuth Discord se fait dans le vrai navigateur → callback lie le compte
 *  5. La tablette détecte la liaison (polling) et redirige
 */
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->getConnection();

/* ── SQL à exécuter (une seule fois) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `discord_link_codes` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`       VARCHAR(8) NOT NULL UNIQUE,
    `user_id`    INT UNSIGNED NOT NULL,
    `discord_id` VARCHAR(32) DEFAULT NULL,
    `used`       TINYINT(1) DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
─────────────────────────────────────────────────────────────────────── */

$step    = 'enter_code'; // enter_code | discord_auth | success | error
$message = '';
/* Ne pas lire $_GET['code'] ici : Discord OAuth renvoie aussi `code` (jeton d’autorisation). */
$code = '';
if (!isset($_GET['discord_callback'])) {
    $code = sanitize($_POST['code'] ?? $_GET['link_code'] ?? '');
}

/* ── RETOUR OAUTH DISCORD ──
 * Discord envoie le paramètre GET standard `code` (pas code_oauth). */
if (isset($_GET['discord_callback']) && isset($_GET['state'])) {
    $oauthCode = $_GET['code'] ?? $_GET['code_oauth'] ?? '';
    if ($oauthCode === '') {
        $step = 'error';
        $message = 'Paramètre OAuth manquant (code).';
        goto render;
    }
    $linkCode   = sanitize($_GET['state']);

    /* Vérifier que le code de liaison est encore valide */
    $stmt = $db->prepare("SELECT * FROM discord_link_codes WHERE code = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$linkCode]);
    $linkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linkRow) { $step = 'error'; $message = 'Code de liaison expiré ou déjà utilisé.'; goto render; }

    /* Échanger le code OAuth contre un access_token */
    $tokenData = discordGetToken($oauthCode, SITE_URL . '/auth/discord-link.php?discord_callback=1&state=' . urlencode($linkCode));

    if (!isset($tokenData['access_token'])) { $step = 'error'; $message = 'Erreur lors de la connexion à Discord.'; goto render; }

    /* Récupérer les infos Discord */
    $discordUser = discordGetUser($tokenData['access_token']);

    if (!isset($discordUser['id'])) { $step = 'error'; $message = 'Impossible de récupérer les données Discord.'; goto render; }

    /* Lier le compte */
    $discordId  = $discordUser['id'];
    $avatarId   = $discordUser['avatar'] ?? null;
    $avatarUrl  = $avatarId
        ? "https://cdn.discordapp.com/avatars/{$discordId}/{$avatarId}.png"
        : ASSETS_PATH . '/images/logos/default.png';

    $db->prepare("UPDATE users SET discord_id = ?, avatar = ? WHERE id = ?")
       ->execute([$discordId, $avatarUrl, $linkRow['user_id']]);

    $db->prepare("UPDATE discord_link_codes SET used = 1, discord_id = ? WHERE code = ?")
       ->execute([$discordId, $linkCode]);

    $step    = 'success';
    $message = 'Ton compte Discord a été lié avec succès ! Tu peux fermer cette page.';
    goto render;
}

/* ── SOUMISSION DU CODE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code) {
    $stmt = $db->prepare("SELECT * FROM discord_link_codes WHERE code = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$code]);
    $linkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linkRow) {
        $message = 'Code invalide ou expiré. Génère un nouveau code depuis la tablette.';
    } else {
        /* Rediriger vers Discord OAuth avec le code comme state */
        $callbackUrl = SITE_URL . '/auth/discord-link.php?discord_callback=1&state=' . urlencode($code);
        $params = [
            'client_id'     => DISCORD_CLIENT_ID,
            'redirect_uri'  => $callbackUrl,
            'response_type' => 'code',
            'scope'         => 'identify email',
            'state'         => $code,
            'code_oauth'    => '', // remplacé par Discord
        ];
        /* Remapping du paramètre que Discord retourne */
        $oauthUrl = "https://discord.com/api/oauth2/authorize?" . http_build_query([
            'client_id'     => DISCORD_CLIENT_ID,
            'redirect_uri'  => SITE_URL . '/auth/discord-link.php?discord_callback=1&state=' . urlencode($code),
            'response_type' => 'code',
            'scope'         => 'identify email',
        ]);
        header('Location: ' . $oauthUrl);
        exit;
    }
}

render:
/* ── HELPERS ── */
function discordGetToken(string $code, string $redirectUri): array {
    $ch = curl_init("https://discord.com/api/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => DISCORD_CLIENT_ID,
            'client_secret' => DISCORD_CLIENT_SECRET,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}
function discordGetUser(string $token): array {
    $ch = curl_init("https://discord.com/api/users/@me");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lier Discord — MDT</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;
     background:linear-gradient(135deg,#07101f,#0d1b2e);font-family:'Segoe UI',sans-serif;color:#e2e8f0;}
.card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:16px;
      padding:2.5rem 2rem;max-width:420px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.discord-logo{color:#5865f2;font-size:3rem;margin-bottom:1rem;}
h1{font-size:1.4rem;font-weight:700;margin-bottom:.5rem;color:#fff;}
p{color:#94a3b8;font-size:.9rem;margin-bottom:1.5rem;line-height:1.5;}
.input-code{
    width:100%;padding:.85rem 1rem;font-size:1.5rem;letter-spacing:.4rem;text-align:center;
    font-weight:700;background:rgba(255,255,255,.07);border:2px solid rgba(255,255,255,.15);
    border-radius:10px;color:#fff;outline:none;text-transform:uppercase;
    transition:border-color .2s;
}
.input-code:focus{border-color:#5865f2;}
.btn{
    display:block;width:100%;padding:.9rem;margin-top:1rem;
    background:linear-gradient(135deg,#5865f2,#4752c4);color:#fff;
    border:none;border-radius:10px;font-size:1rem;font-weight:700;
    cursor:pointer;transition:opacity .2s;
}
.btn:hover{opacity:.9;}
.alert{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;}
.alert-error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444;}
.alert-success{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#22c55e;}
.step-icon{font-size:4rem;margin-bottom:1rem;}
.help{margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid rgba(255,255,255,.08);
      color:#64748b;font-size:.78rem;}
</style>
</head>
<body>
<div class="card">

<?php if ($step === 'success'): ?>
    <div class="step-icon">✅</div>
    <h1>Liaison réussie !</h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <div class="help">Tu peux maintenant te connecter au MDT via Discord depuis la tablette.</div>

<?php elseif ($step === 'error'): ?>
    <div class="step-icon">❌</div>
    <h1>Erreur</h1>
    <p class="alert alert-error"><?php echo htmlspecialchars($message); ?></p>
    <a href="discord-link.php" class="btn" style="text-decoration:none;">Réessayer</a>

<?php else: ?>
    <div class="discord-logo">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/>
        </svg>
    </div>
    <h1>Lier ton compte Discord</h1>
    <p>Entre le code affiché sur ta tablette dans le jeu pour associer ton compte Discord à ton MDT.</p>

    <?php if ($message): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST">
        <input class="input-code" type="text" name="code" maxlength="6"
               placeholder="000000"
               value="<?php echo htmlspecialchars($code); ?>"
               autocomplete="off" autofocus>
        <button class="btn" type="submit">
            Lier avec Discord →
        </button>
    </form>

    <div class="help">
        Ce code est valable <strong>10 minutes</strong>.<br>
        Si ton code a expiré, génère-en un nouveau depuis la tablette.
    </div>
<?php endif; ?>

</div>
</body>
</html>