<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/remember_auth.php';
require_once __DIR__ . '/includes/nexium_theme.php';

if (isLoggedIn()) {
    if (empty($_SESSION['character_id'])) {
        redirect(SITE_URL . '/pages/personnage.php');
    } elseif (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
        redirect(SITE_URL . '/pages/staff/dashboard.php');
    } else {
        redirect(SITE_URL . '/pages/dashboard.php');
    }
}

$error   = '';
$success = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $user     = new User();
        $userData = $user->authenticate($email, $password);

        if ($userData) {
            $_SESSION['user_id']            = $userData['id'];
            $_SESSION['user_email']         = $userData['email'];
            $_SESSION['user_username']      = $userData['username'];
            $_SESSION['user_avatar']        = $userData['avatar'];
            $_SESSION['user_faction']       = $userData['faction'];
            $_SESSION['user_faction_rank']  = $userData['faction_rank'];
            $_SESSION['user_faction_grade'] = $userData['faction_grade'];
            $_SESSION['user_role']          = $userData['role'];

            mdt_session_refresh_display_name();

            // « Se souvenir de moi » : cookie 30 jours uniquement si activé.
            if ($remember) {
                createRememberToken((int) $userData['id']);
            }

            // Multi-personnages : sélection obligatoire avant d'accéder au MDT
            unset($_SESSION['character_id']);
            redirect(SITE_URL . '/pages/personnage.php');
        } else {
            $error = 'Email ou mot de passe incorrect.';
            mdt_audit_log(null, 'AUTH', 'Échec connexion', 'Tentative email/mot de passe : ' . $email, 'auth', null);
        }
    }
}

if (isset($_GET['registered']) && $_GET['registered'] === 'success') {
    $success = 'Inscription réussie ! Vous pouvez maintenant vous connecter.';
}

$siteName     = defined('SITE_NAME') ? (string) SITE_NAME : 'MDT';
$loginMessage = 'Accédez à votre espace MDT';
$faviconUrl   = '';
$themeMode    = 'dark';

try {
    $db = Database::getInstance()->getConnection();
    $branding   = mdt_site_branding($db);
    $siteName   = $branding['site_name'];
    $faviconUrl = $branding['favicon_url'];
    $themeMode  = $branding['theme_mode'];

    $rows = mdt_admin_settings_fetch($db, ['login_message']);
    if (trim((string) ($rows['login_message'] ?? '')) !== '') {
        $loginMessage = (string) $rows['login_message'];
    }
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — <?php echo htmlspecialchars($siteName); ?></title>
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

        h1, .brand-name { font-family: 'Montserrat', sans-serif; color: var(--text); }

        .auth-wrap { width: 100%; max-width: 430px; animation: fadeInUp .5s ease both; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }

        <?php echo nexium_theme_toggle_css(); ?>

        .auth-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 22px; }

        .back-link {
            display: inline-flex; align-items: center; gap: 7px;
            color: var(--muted); text-decoration: none;
            font-size: .82rem; font-weight: 500;
            transition: color .15s;
        }
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
        /* Filet dégradé signature en tête de carte */
        .auth-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--grad); }

        .auth-brand { display: flex; align-items: center; gap: 11px; margin-bottom: 26px; }
        .brand-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: var(--grad);
            display: grid; place-items: center; font-size: 15px; color: #fff; flex-shrink: 0;
        }
        .brand-name { font-size: 1.05rem; font-weight: 700; letter-spacing: -.01em; }

        .auth-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .auth-subtitle { font-size: .9rem; color: var(--muted); margin-bottom: 26px; }

        .alert {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 15px; border-radius: 9px;
            font-size: .85rem; font-weight: 500; margin-bottom: 18px;
        }
        .alert-error   { background: color-mix(in srgb, #dc2626 10%, transparent); border: 1px solid color-mix(in srgb, #dc2626 28%, transparent); color: #dc2626; }
        .alert-success { background: color-mix(in srgb, #16a34a 10%, transparent); border: 1px solid color-mix(in srgb, #16a34a 28%, transparent); color: #16a34a; }

        .form-group { margin-bottom: 17px; }
        .form-label {
            display: block; font-size: .78rem; font-weight: 600;
            color: var(--text-2); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px;
        }
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

        .pw-wrap { position: relative; }
        .pw-wrap .form-input { padding-right: 42px; }
        .pw-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--muted); cursor: pointer;
            padding: 6px; display: grid; place-items: center; font-size: .9rem; transition: color .15s;
        }
        .pw-toggle:hover { color: var(--text); }

        /* Ligne options : toggle + lien */
        .form-options { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 0 22px; }

        /* Interrupteur ON/OFF */
        .switch-label { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: .84rem; color: var(--text-2); user-select: none; }
        .switch { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
        .switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .switch-track { position: absolute; inset: 0; border-radius: 999px; background: var(--border-strong); transition: background .2s; pointer-events: none; }
        .switch-thumb { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .2s; pointer-events: none; }
        .switch input:checked ~ .switch-track { background: var(--bleu); }
        .switch input:checked ~ .switch-thumb { transform: translateX(18px); }
        .switch input:focus-visible ~ .switch-track { box-shadow: 0 0 0 3px color-mix(in srgb, var(--bleu) 22%, transparent); }

        .forgot-link { font-size: .82rem; color: var(--bleu); text-decoration: none; white-space: nowrap; font-weight: 500; transition: filter .15s; }
        .forgot-link:hover { filter: brightness(1.1); text-decoration: underline; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 9px;
            width: 100%; padding: 12px 20px; border-radius: 8px;
            font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 600;
            text-decoration: none; cursor: pointer; border: 1.5px solid transparent;
            transition: filter .15s ease, background .15s, border-color .15s, color .15s;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn-primary { background: var(--bleu); color: #fff; margin-bottom: 12px; }
        .btn-discord { background: var(--discord); color: #fff; }

        .auth-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; }
        .auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .auth-divider span { font-size: .74rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }

        .auth-note { text-align: center; margin: 0 0 8px; font-size: .82rem; color: var(--muted); }
        .auth-note code { background: color-mix(in srgb, var(--text) 8%, transparent); padding: 2px 6px; border-radius: 4px; }

        .auth-footer { text-align: center; margin-top: 22px; font-size: .84rem; color: var(--muted); }
        .auth-footer a { color: var(--bleu); text-decoration: none; font-weight: 600; transition: filter .15s; }
        .auth-footer a:hover { filter: brightness(1.1); text-decoration: underline; }
        .auth-legal { margin-top: 12px; }
        .auth-legal a { color: var(--muted); font-weight: 500; font-size: .78rem; }
        .auth-legal a:hover { color: var(--text-2); }

        @media (max-width: 480px) { .auth-card { padding: 26px 20px; } }
    </style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-top">
        <a href="index.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i>
            Retour à l'accueil
        </a>
        <?php nexium_theme_toggle(); ?>
    </div>

    <div class="auth-card">
        <div class="auth-brand">
            <div class="brand-icon"><i class="fa-solid fa-terminal"></i></div>
            <span class="brand-name"><?php echo htmlspecialchars($siteName); ?></span>
        </div>

        <h1 class="auth-title">Connexion</h1>
        <p class="auth-subtitle"><?php echo htmlspecialchars($loginMessage); ?></p>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="votre.email@exemple.com"
                    value="<?php echo htmlspecialchars($email ?? ''); ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Mot de passe</label>
                <div class="pw-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)" tabindex="-1" aria-label="Afficher / masquer le mot de passe">
                        <i class="fa-regular fa-eye" id="eye-password"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="switch-label">
                    <span class="switch">
                        <input type="checkbox" name="remember" value="1" checked>
                        <span class="switch-track"></span>
                        <span class="switch-thumb"></span>
                    </span>
                    Se souvenir de moi
                </label>
                <a href="forgot-password.php" class="forgot-link">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                Se connecter
            </button>
        </form>

        <div class="auth-divider"><span>ou</span></div>

        <?php if (defined('DISCORD_CLIENT_ID') && DISCORD_CLIENT_ID !== ''): ?>
        <a href="auth/discord-login.php" class="btn btn-discord">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
            </svg>
            Se connecter avec Discord
        </a>
        <?php else: ?>
        <p class="auth-note">Discord OAuth non configuré (Client ID vide dans <code>config.local.php</code>).</p>
        <?php endif; ?>

        <div class="auth-footer">
            Pas encore de compte ? <a href="register.php">S'inscrire</a>
            <div class="auth-legal">
                <a href="https://nexium-studio.com/mentions-legales.php" target="_blank" rel="noopener">Mentions légales &amp; conditions d'utilisation</a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}
</script>
<?php nexium_theme_script(); ?>
</body>
</html>
