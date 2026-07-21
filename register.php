<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/nexium_theme.php';

if (isLoggedIn()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$error    = '';
$success  = '';
$email    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email           = sanitize($_POST['email'] ?? '');
    $username        = sanitize($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($email) || empty($username) || empty($password) || empty($confirmPassword)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif (strlen($username) < 3) {
        $error = "Le nom d'utilisateur doit contenir au moins 3 caractères.";
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $user = new User();

        if ($user->emailExists($email)) {
            $error = 'Cette adresse email est déjà utilisée.';
        } elseif ($user->usernameExists($username)) {
            $error = "Ce nom d'utilisateur est déjà pris.";
        } else {
            $userId = $user->create([
                'email'    => $email,
                'username' => $username,
                'password' => $password,
                'faction'  => 'NONE',
                'role'     => 'Citoyen',
            ]);

            if ($userId) {
                mdt_audit_log((int) $userId, 'AUTH', 'Inscription', 'Compte créé : ' . $username . ' (' . $email . ')', 'auth', null);
                redirect(SITE_URL . '/login.php?registered=success');
            } else {
                $error = "Une erreur est survenue lors de l'inscription.";
            }
        }
    }
}

$siteName   = defined('SITE_NAME') ? (string) SITE_NAME : 'MDT';
$faviconUrl = '';
$themeMode  = 'dark';

try {
    $db = Database::getInstance()->getConnection();
    $branding   = mdt_site_branding($db);
    $siteName   = $branding['site_name'];
    $faviconUrl = $branding['favicon_url'];
    $themeMode  = $branding['theme_mode'];
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription — <?php echo htmlspecialchars($siteName); ?></title>
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

        .auth-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .auth-subtitle { font-size: .9rem; color: var(--muted); margin-bottom: 26px; }

        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 15px; border-radius: 9px; font-size: .85rem; font-weight: 500; margin-bottom: 18px; }
        .alert-error { background: color-mix(in srgb, #dc2626 10%, transparent); border: 1px solid color-mix(in srgb, #dc2626 28%, transparent); color: #dc2626; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: .76rem; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 7px; }

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
        .form-input.is-error { border-color: #dc2626; box-shadow: 0 0 0 3px color-mix(in srgb, #dc2626 14%, transparent); }

        .form-hint { display: block; font-size: .74rem; color: var(--muted); margin-top: 5px; }

        .pw-strength { margin-top: 8px; display: none; }
        .pw-strength-bar { height: 3px; border-radius: 2px; background: var(--border); overflow: hidden; margin-bottom: 4px; }
        .pw-strength-fill { height: 100%; border-radius: 2px; transition: width .3s, background .3s; width: 0; }
        .pw-strength-label { font-size: .7rem; color: var(--muted); }

        .pw-wrap { position: relative; }
        .pw-wrap .form-input { padding-right: 42px; }
        .pw-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted); cursor: pointer; padding: 6px; display: grid; place-items: center; font-size: .9rem; transition: color .15s; }
        .pw-toggle:hover { color: var(--text); }

        /* CGU : interrupteur ON/OFF */
        .cgu-row { display: flex; align-items: center; gap: 12px; margin: 6px 0 22px; font-size: .84rem; color: var(--text-2); line-height: 1.5; }
        .cgu-row a { color: var(--bleu); text-decoration: none; font-weight: 600; }
        .cgu-row a:hover { text-decoration: underline; }
        .switch { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
        .switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .switch-track { position: absolute; inset: 0; border-radius: 999px; background: var(--border-strong); transition: background .2s; pointer-events: none; }
        .switch-thumb { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .2s; pointer-events: none; }
        .switch input:checked ~ .switch-track { background: var(--bleu); }
        .switch input:checked ~ .switch-thumb { transform: translateX(18px); }
        .switch input:focus-visible ~ .switch-track { box-shadow: 0 0 0 3px color-mix(in srgb, var(--bleu) 22%, transparent); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 9px;
            width: 100%; padding: 12px 20px; border-radius: 8px;
            font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 600;
            text-decoration: none; cursor: pointer; border: 1.5px solid transparent;
            transition: filter .15s ease, background .15s;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn-primary { background: var(--bleu); color: #fff; }
        .btn:disabled { opacity: .5; cursor: not-allowed; filter: none; }
        .btn:disabled:hover { filter: none; }

        .auth-footer { text-align: center; margin-top: 22px; font-size: .84rem; color: var(--muted); }
        .auth-footer a { color: var(--bleu); text-decoration: none; font-weight: 600; transition: filter .15s; }
        .auth-footer a:hover { filter: brightness(1.1); text-decoration: underline; }

        @media (max-width: 480px) { .auth-card { padding: 26px 20px; } .form-row { grid-template-columns: 1fr; } }
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

        <h1 class="auth-title">Inscription</h1>
        <p class="auth-subtitle">Créez votre compte MDT</p>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="regForm" novalidate>
            <div class="form-row">
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="email@exemple.com"
                        value="<?php echo htmlspecialchars($email); ?>"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="username" class="form-label">Nom d'utilisateur</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        placeholder="Ex : johndoe"
                        value="<?php echo htmlspecialchars($username); ?>"
                        minlength="3"
                        autocomplete="username"
                        required
                    >
                    <span class="form-hint">Sert à vous connecter (min. 3 caractères)</span>
                </div>
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
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)" tabindex="-1" aria-label="Afficher / masquer le mot de passe">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
                <div class="pw-strength" id="strengthWrap">
                    <div class="pw-strength-bar">
                        <div class="pw-strength-fill" id="strengthFill"></div>
                    </div>
                    <span class="pw-strength-label" id="strengthLabel"></span>
                </div>
                <span class="form-hint">Min. 8 caractères</span>
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirmer le mot de passe</label>
                <div class="pw-wrap">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        class="form-input"
                        placeholder="••••••••"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)" tabindex="-1" aria-label="Afficher / masquer le mot de passe">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="cgu-row">
                <span class="switch">
                    <input type="checkbox" id="cgu" name="cgu" value="1" required>
                    <span class="switch-track"></span>
                    <span class="switch-thumb"></span>
                </span>
                <label for="cgu">J'accepte les <a href="https://nexium-studio.com/mentions-legales.php" target="_blank" rel="noopener">conditions d'utilisation</a> de la plateforme</label>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                <i class="fa-solid fa-user-plus"></i>
                Créer mon compte
            </button>
        </form>

        <div class="auth-footer">
            Déjà un compte ? <a href="login.php">Se connecter</a>
        </div>
    </div>
</div>

<script>
/* Afficher / masquer */
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

/* Robustesse du mot de passe */
const pwInput      = document.getElementById('password');
const strengthWrap = document.getElementById('strengthWrap');
const fill         = document.getElementById('strengthFill');
const label        = document.getElementById('strengthLabel');

pwInput.addEventListener('input', function () {
    const v = this.value;
    strengthWrap.style.display = v.length ? 'block' : 'none';
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const levels = [
        { w: '20%',  bg: '#dc2626', text: 'Très faible' },
        { w: '40%',  bg: '#ea580c', text: 'Faible' },
        { w: '60%',  bg: '#ca8a04', text: 'Moyen' },
        { w: '80%',  bg: '#16a34a', text: 'Fort' },
        { w: '100%', bg: '#059669', text: 'Très fort' },
    ];
    const lvl = levels[Math.min(score, 4)];
    fill.style.width      = lvl.w;
    fill.style.background  = lvl.bg;
    label.textContent      = lvl.text;
    label.style.color      = lvl.bg;
});

/* Correspondance de confirmation */
const confirmInput = document.getElementById('confirm_password');
confirmInput.addEventListener('input', function () {
    this.setCustomValidity(
        this.value && this.value !== pwInput.value ? 'Les mots de passe ne correspondent pas' : ''
    );
    this.classList.toggle('is-error', !!this.validationMessage);
});

/* Le bouton d'inscription reste inactif tant que les CGU ne sont pas acceptées */
const cguCheck  = document.getElementById('cgu');
const submitBtn = document.getElementById('submitBtn');
function syncSubmitState() { submitBtn.disabled = !cguCheck.checked; }
cguCheck.addEventListener('change', syncSubmitState);
syncSubmitState();
</script>
<?php nexium_theme_script(); ?>
</body>
</html>
