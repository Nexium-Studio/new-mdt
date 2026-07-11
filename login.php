<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/remember_auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

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

            createRememberToken((int) $userData['id']);

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

$siteName = defined('SITE_NAME') ? (string) SITE_NAME : 'MDT';
$loginMessage = 'Accédez à votre espace MDT';
$faviconUrl = '';
$primaryColor = '#3B82F6';

try {
    $db = Database::getInstance()->getConnection();
    $branding = mdt_site_branding($db);
    $siteName = $branding['site_name'];
    $faviconUrl = $branding['favicon_url'];
    $primaryColor = $branding['primary_color'];

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:       #03070f;
            --bg-2:     #080f1d;
            --bg-card:  rgba(10, 17, 32, 0.85);
            --border:   rgba(255,255,255,0.07);
            --border-2: rgba(255,255,255,0.13);
            --text:     #e2e8f0;
            --muted:    #475569;
            --muted-2:  #64748b;
            --accent:   <?php echo htmlspecialchars($primaryColor); ?>;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow: hidden;
        }

        /* Orbs décoratifs */
        body::before {
            content: '';
            position: fixed;
            top: -200px; left: -200px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59,130,246,.1) 0%, transparent 65%);
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -150px; right: -150px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(99,102,241,.07) 0%, transparent 65%);
            pointer-events: none;
        }

        /* ── Layout ── */
        .auth-wrap {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
            animation: fadeInUp .5s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Back link ── */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted-2);
            text-decoration: none;
            font-size: .8rem;
            font-weight: 500;
            margin-bottom: 28px;
            transition: color .2s;
        }

        .back-link:hover { color: var(--text); }

        /* ── Card ── */
        .auth-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 36px 32px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        /* Glow top line */
        .auth-card::before {
            content: '';
            display: block;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(59,130,246,.5), transparent);
            margin: -36px -32px 32px;
            border-radius: 24px 24px 0 0;
        }

        /* ── Header ── */
        .auth-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .brand-icon {
            width: 36px; height: 36px;
            background: var(--accent);
            border-radius: 10px;
            display: grid; place-items: center;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
        }

        .brand-name {
            font-family: 'Syne', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .auth-title {
            font-family: 'Syne', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -.03em;
            margin-bottom: 6px;
        }

        .auth-subtitle {
            font-size: .875rem;
            color: var(--muted-2);
            margin-bottom: 28px;
        }

        /* ── Alert ── */
        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: .85rem;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .alert-error   { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.2);  color: #fca5a5; }
        .alert-success { background: rgba(34,197,94,.08);  border: 1px solid rgba(34,197,94,.2);  color: #86efac; }

        /* ── Form ── */
        .form-group { margin-bottom: 18px; }

        .form-label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: var(--muted-2);
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: .9rem;
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .form-input::placeholder { color: var(--muted); }

        .form-input:focus {
            border-color: rgba(59,130,246,.5);
            background: rgba(59,130,246,.04);
            box-shadow: 0 0 0 3px rgba(59,130,246,.1);
        }

        /* Input password wrapper */
        .pw-wrap { position: relative; }

        .pw-toggle {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            padding: 4px;
            display: grid; place-items: center;
            transition: color .2s;
            font-size: .9rem;
        }

        .pw-toggle:hover { color: var(--text); }

        .pw-wrap .form-input { padding-right: 42px; }

        /* Options row */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            gap: 12px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .8rem;
            color: var(--muted-2);
            cursor: pointer;
        }

        .checkbox-label input { accent-color: var(--accent); }

        .forgot-link {
            font-size: .8rem;
            color: var(--accent);
            text-decoration: none;
            white-space: nowrap;
            transition: color .2s;
        }

        .forgot-link:hover { color: #60a5fa; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 12px 20px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all .2s cubic-bezier(.4,0,.2,1);
            width: 100%;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            margin-bottom: 12px;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -4px rgba(59,130,246,.4);
        }

        .btn-discord {
            background: rgba(88,101,242,.12);
            border: 1px solid rgba(88,101,242,.3);
            color: #a5b4fc;
        }

        .btn-discord:hover {
            background: rgba(88,101,242,.2);
            border-color: rgba(88,101,242,.5);
            transform: translateY(-1px);
        }

        /* Divider */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .auth-divider span {
            font-size: .75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        /* Footer */
        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: .83rem;
            color: var(--muted-2);
        }

        .auth-footer a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color .2s;
        }

        .auth-footer a:hover { color: #60a5fa; }

        /* Responsive */
        @media (max-width: 480px) {
            .auth-card { padding: 28px 20px; }
        }
    </style>
</head>
<body>
<div class="auth-wrap">
    <a href="index.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i>
        Retour à l'accueil
    </a>

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
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)" tabindex="-1">
                        <i class="fa-regular fa-eye" id="eye-password"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="checkbox-label" style="opacity:.5;cursor:default;" title="Activé automatiquement">
                    <input type="checkbox" checked disabled>
                    <span>Session 30 jours (auto)</span>
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
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
            </svg>
            Se connecter avec Discord
        </a>
        <?php else: ?>
        <p style="text-align:center;margin:0 0 8px;font-size:.82rem;color:var(--muted);">Discord OAuth non configuré (Client ID vide dans <code>config.local.php</code>).</p>
        <?php endif; ?>

        <div class="auth-footer">
            Pas encore de compte ? <a href="register.php">S'inscrire</a>
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
</body>
</html>