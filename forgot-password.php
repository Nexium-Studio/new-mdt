<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/nexium_theme.php';

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$error = '';
$success = '';
$step = 1; // Étape 1: entrer Discord ID

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $discord_id = sanitize($_POST['discord_id'] ?? '');

    if (empty($discord_id)) {
        $error = 'Veuillez entrer votre ID Discord';
    } elseif (!preg_match('/^\d{17,20}$/', $discord_id)) {
        $error = 'ID Discord invalide. L\'ID doit être composé uniquement de chiffres (17-20 caractères)';
    } else {
        // Vérifier si l'ID Discord existe dans la base de données
        $db = Database::getInstance();
        $stmt = $db->getConnection()->prepare("SELECT id, email, username, discord_id FROM users WHERE discord_id = ? AND is_active = 1");
        $stmt->execute([$discord_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Aucun compte n\'est associé à cet ID Discord';
        } else {
            // Générer un nouveau mot de passe temporaire
            $tempPassword = generateSecurePassword(12);
            $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            // Mettre à jour le mot de passe dans la base de données
            $updateStmt = $db->getConnection()->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateResult = $updateStmt->execute([$hashedPassword, $user['id']]);

            if ($updateResult) {
                if (trim((string) BOT_API_URL) === '') {
                    $error = 'Réinitialisation par bot non configurée sur ce serveur (BOT_API_URL). Contactez un administrateur.';
                } else {
                // Envoyer la demande au bot Discord
                $botApiUrl = BOT_API_URL;
                $timeout = BOT_API_TIMEOUT;

                $postData = [
                    'discord_id' => $discord_id,
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'temp_password' => $tempPassword,
                    'secret_key' => BOT_API_SECRET
                ];

                $ch = curl_init($botApiUrl . '/api/password-reset');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                // Log détaillé
                error_log("[BOT_API] URL: $botApiUrl/api/password-reset | HTTP: $httpCode | Timeout: {$timeout}s | Error: $curlError ($curlErrno) | Response: $response");

                if ($httpCode === 200 && $response) {
                    $success = 'Un message privé contenant vos nouveaux identifiants a été envoyé sur Discord. Vérifiez vos MPs !';
                    $step = 2;
                } else {
                    // Déterminer le type d'erreur
                    if ($curlErrno) {
                        $error = "Erreur de connexion au serveur Discord. Veuillez réessayer dans quelques instants. (Code: $curlErrno)";
                    } elseif ($httpCode === 0) {
                        $error = "Impossible de contacter le serveur Discord. Veuillez réessayer plus tard.";
                    } else {
                        $error = "Erreur serveur Discord (Code: $httpCode). Veuillez réessayer.";
                    }

                    error_log("Password reset failed for Discord ID: $discord_id - HTTP: $httpCode - Curl Error: $curlError - Response: $response");
                }
                }
            } else {
                $error = 'Une erreur est survenue lors de la mise à jour du mot de passe.';
            }
        }
    }
}

/**
 * Génère un mot de passe sécurisé
 */
function generateSecurePassword($length = 12) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%&*';

    $password = '';

    // Au moins un de chaque type
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];

    // Compléter le reste
    $allChars = $uppercase . $lowercase . $numbers . $special;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }

    // Mélanger le mot de passe
    return str_shuffle($password);
}

$siteName   = defined('SITE_NAME') ? (string) SITE_NAME : 'MDT';
$faviconUrl = '';
$themeMode  = 'dark';
$discordUrl = ''; // Lien du serveur Discord, configuré dans les paramètres du site
try {
    $conn       = Database::getInstance()->getConnection();
    $branding   = mdt_site_branding($conn);
    $siteName   = $branding['site_name'];
    $faviconUrl = $branding['favicon_url'];
    $themeMode  = $branding['theme_mode'];

    $rows = mdt_admin_settings_fetch($conn, ['discord_server_url']);
    $u = trim((string) ($rows['discord_server_url'] ?? ''));
    if ($u !== '' && stripos($u, 'https://') === 0) {
        $discordUrl = $u;
    }
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié — <?php echo htmlspecialchars($siteName); ?></title>
    <?php if ($faviconUrl !== ''): ?><link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>"><?php endif; ?>
    <?php nexium_theme_head($themeMode); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Charte Nexium Studio — Bleu #1E6FE5 → Violet #6C3FC5 · Montserrat / Inter */
        :root {
            --bleu:   #1E6FE5;
            --violet: #6C3FC5;
            --cyan:   #2EA8E6;
            --grad: linear-gradient(120deg, var(--bleu), var(--violet));
            --discord: #5865F2;
            --vert: #16a34a;
            --ambre: #d97706;
        }
        :root, :root[data-theme="dark"] {
            --bg:            #0C1023;
            --surface:       #141a30;
            --input-bg:      rgba(255,255,255,.04);
            --border:        rgba(255,255,255,.08);
            --border-strong: rgba(255,255,255,.16);
            --text:          #F4F6FB;
            --text-2:        #C9CDDA;
            --muted:         #8891a8;
            --card-shadow:   0 24px 60px -30px rgba(0,0,0,.7);
        }
        :root[data-theme="light"] {
            --bg:            #F5F6FA;
            --surface:       #FFFFFF;
            --input-bg:      #FFFFFF;
            --border:        #E4E7EF;
            --border-strong: #D5DAE6;
            --text:          #1A1F30;
            --text-2:        #3B4258;
            --muted:         #6B7386;
            --card-shadow:   0 2px 14px rgba(20,25,45,.07);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
            transition: background .25s ease, color .25s ease;
        }

        h1, h2, .brand-name { font-family: 'Montserrat', sans-serif; color: var(--text); }

        .auth-wrap { width: 100%; max-width: 460px; animation: fadeInUp .5s ease both; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }

        <?php echo nexium_theme_toggle_css(); ?>

        .auth-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 22px; }
        .back-link { display: inline-flex; align-items: center; gap: 7px; color: var(--muted); text-decoration: none; font-size: .82rem; font-weight: 500; transition: color .15s; }
        .back-link:hover { color: var(--text); }

        .auth-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 34px 32px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        .auth-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--grad); }

        .auth-brand { display: flex; align-items: center; gap: 11px; margin-bottom: 26px; }
        .brand-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--grad); display: grid; place-items: center; font-size: 15px; color: #fff; flex-shrink: 0; }
        .brand-name { font-size: 1.05rem; font-weight: 700; letter-spacing: -.01em; }

        .auth-title { font-size: 1.4rem; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .auth-subtitle { font-size: .9rem; color: var(--muted); margin-bottom: 24px; }

        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 15px; border-radius: 9px; font-size: .85rem; font-weight: 500; margin-bottom: 18px; }
        .alert-error { background: color-mix(in srgb, #dc2626 10%, transparent); border: 1px solid color-mix(in srgb, #dc2626 28%, transparent); color: #dc2626; }

        /* Aide Discord */
        .discord-help {
            background: color-mix(in srgb, var(--discord) 8%, transparent);
            border: 1px solid color-mix(in srgb, var(--discord) 28%, transparent);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 22px;
        }
        .discord-help h3 { font-family: 'Montserrat', sans-serif; color: var(--discord); font-size: .9rem; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .discord-help ol { font-size: .82rem; color: var(--text-2); padding-left: 18px; margin: 0 0 10px; }
        .discord-help ol li { margin-bottom: 6px; }
        .discord-help .note { font-size: .78rem; color: var(--muted); }
        .discord-help code { background: color-mix(in srgb, var(--discord) 16%, transparent); padding: 2px 6px; border-radius: 4px; font-family: monospace; color: var(--discord); }
        .discord-help strong { color: var(--text); }

        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: .78rem; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
        .form-input {
            width: 100%; padding: 11px 14px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-family: 'Inter', sans-serif; font-size: .92rem;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-input::placeholder { color: var(--muted); }
        .form-input:focus { border-color: var(--bleu); box-shadow: 0 0 0 3px color-mix(in srgb, var(--bleu) 16%, transparent); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 9px;
            width: 100%; padding: 12px 20px; border-radius: 8px;
            font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 600;
            text-decoration: none; cursor: pointer; border: 1.5px solid transparent;
            transition: filter .15s ease, background .15s;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn-primary { background: var(--bleu); color: #fff; }
        .btn-discord { background: var(--discord); color: #fff; }

        /* Écran de succès */
        .success-message { text-align: center; }
        .success-icon { width: 76px; height: 76px; border-radius: 50%; background: color-mix(in srgb, var(--vert) 16%, transparent); border: 1px solid color-mix(in srgb, var(--vert) 32%, transparent); display: grid; place-items: center; margin: 4px auto 18px; }
        .success-icon i { font-size: 2rem; color: var(--vert); }
        .success-message h2 { font-size: 1.3rem; font-weight: 700; margin-bottom: 10px; }
        .success-message p { color: var(--text-2); font-size: .9rem; margin-bottom: 18px; line-height: 1.6; }
        .warning-box {
            background: color-mix(in srgb, var(--ambre) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ambre) 30%, transparent);
            border-radius: 9px; padding: 12px 14px; margin-top: 6px;
            font-size: .82rem; color: var(--ambre); text-align: left;
            display: flex; align-items: flex-start; gap: 9px; line-height: 1.5;
        }
        .warning-box i { margin-top: 2px; }
        .warning-box strong { color: var(--text); }

        .auth-footer { text-align: center; margin-top: 20px; font-size: .84rem; color: var(--muted); }
        .auth-footer a { color: var(--bleu); text-decoration: none; font-weight: 600; }
        .auth-footer a:hover { text-decoration: underline; }

        @media (max-width: 480px) { .auth-card { padding: 26px 20px; } }
    </style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-top">
        <a href="login.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i>
            Retour à la connexion
        </a>
        <?php nexium_theme_toggle(); ?>
    </div>

    <div class="auth-card">
        <div class="auth-brand">
            <div class="brand-icon"><i class="fa-solid fa-terminal"></i></div>
            <span class="brand-name"><?php echo htmlspecialchars($siteName); ?></span>
        </div>

        <?php if ($step === 2 && $success): ?>
            <!-- Étape 2: Succès -->
            <div class="success-message">
                <div class="success-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2>Message envoyé !</h2>
                <p>Vos nouveaux identifiants ont été envoyés sur Discord.<br>Vérifiez vos messages privés.</p>

                <div class="warning-box">
                    <i class="fa-solid fa-circle-info"></i>
                    <span><strong>Important :</strong> pensez à modifier votre mot de passe temporaire dès votre connexion !</span>
                </div>

                <a href="login.php" class="btn btn-primary" style="margin-top: 20px;">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    Retour à la connexion
                </a>
            </div>
        <?php else: ?>
            <!-- Étape 1: Formulaire -->
            <h1 class="auth-title">Mot de passe oublié</h1>
            <p class="auth-subtitle">Récupérez vos identifiants via Discord</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="discord-help">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                    </svg>
                    Comment trouver mon ID Discord ?
                </h3>
                <ol>
                    <li>Ouvrez Discord et allez dans <strong>Paramètres utilisateur</strong></li>
                    <li>Allez dans <strong>Avancés</strong> et activez le <strong>Mode développeur</strong></li>
                    <li>Cliquez droit sur votre avatar puis <strong>« Copier l'identifiant »</strong></li>
                    <li>Collez l'ID ci-dessous (exemple&nbsp;: <code>389168772243783690</code>)</li>
                </ol>
                <div class="note">Attention&nbsp;: si vous n'avez pas lié votre compte Discord au MDT, cette méthode ne fonctionnera pas.</div>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="discord_id" class="form-label">ID Discord</label>
                    <input
                        type="text"
                        id="discord_id"
                        name="discord_id"
                        class="form-input"
                        placeholder="123456789012345678"
                        value="<?php echo htmlspecialchars($_POST['discord_id'] ?? ''); ?>"
                        pattern="\d{17,20}"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-discord">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                    </svg>
                    Envoyer mes identifiants sur Discord
                </button>
            </form>

            <div class="auth-footer">
                <?php if ($discordUrl !== ''): ?>
                    Besoin d'aide ? <a href="<?php echo htmlspecialchars($discordUrl); ?>" target="_blank" rel="noopener">Contacter le support</a>
                <?php else: ?>
                    Vous n'avez pas de compte Discord lié ? <a href="mailto:support@lysoria.fr">Contacter le support</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php nexium_theme_script(); ?>
</body>
</html>
