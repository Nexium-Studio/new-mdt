<?php
require_once __DIR__ . '/config/config.php';

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription — <?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'MDT'; ?></title>
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
            --accent:   #3b82f6;
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
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: -200px; right: -100px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(99,102,241,.09) 0%, transparent 65%);
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -150px; left: -150px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(59,130,246,.07) 0%, transparent 65%);
            pointer-events: none;
        }

        .auth-wrap {
            width: 100%;
            max-width: 460px;
            position: relative;
            z-index: 1;
            animation: fadeInUp .5s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

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

        .auth-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 36px 32px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .auth-card::before {
            content: '';
            display: block;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,102,241,.5), transparent);
            margin: -36px -32px 32px;
            border-radius: 24px 24px 0 0;
        }

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

        .alert-error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: #fca5a5; }

        /* Form */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-group { margin-bottom: 16px; }

        .form-label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: var(--muted-2);
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 7px;
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

        .form-input.is-error {
            border-color: rgba(239,68,68,.4);
            box-shadow: 0 0 0 3px rgba(239,68,68,.08);
        }

        .form-hint {
            display: block;
            font-size: .75rem;
            color: var(--muted);
            margin-top: 5px;
        }

        /* Strength bar */
        .pw-strength {
            margin-top: 8px;
            display: none;
        }

        .pw-strength-bar {
            height: 3px;
            border-radius: 2px;
            background: var(--border);
            overflow: hidden;
            margin-bottom: 4px;
        }

        .pw-strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: width .3s, background .3s;
            width: 0;
        }

        .pw-strength-label {
            font-size: .7rem;
            color: var(--muted-2);
        }

        /* Password wrapper */
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
            font-size: .9rem;
            transition: color .2s;
        }

        .pw-toggle:hover { color: var(--text); }
        .pw-wrap .form-input { padding-right: 42px; }

        /* Checkbox */
        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: .83rem;
            color: var(--muted-2);
            cursor: pointer;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .checkbox-label input { accent-color: var(--accent); margin-top: 3px; flex-shrink: 0; }

        .checkbox-label a {
            color: var(--accent);
            text-decoration: none;
        }

        .checkbox-label a:hover { text-decoration: underline; }

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

        .auth-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }

        .auth-divider::before,
        .auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        .auth-divider span {
            font-size: .75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

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

        @media (max-width: 480px) {
            .auth-card { padding: 28px 20px; }
            .form-row  { grid-template-columns: 1fr; }
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
            <span class="brand-name"><?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'MDT'; ?></span>
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
                    <label for="username" class="form-label">Nom RP</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        placeholder="John Doe"
                        value="<?php echo htmlspecialchars($username); ?>"
                        minlength="3"
                        autocomplete="username"
                        required
                    >
                    <span class="form-hint">Min. 3 caractères</span>
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
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)" tabindex="-1">
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
                    <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)" tabindex="-1">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <label class="checkbox-label">
                <input type="checkbox" required>
                <span>J'accepte les <a href="#">conditions d'utilisation</a> de la plateforme</span>
            </label>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-user-plus"></i>
                Créer mon compte
            </button>
        </form>

        
    </div>
</div>

<script>
/* Toggle visibility */
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

/* Password strength */
const pwInput     = document.getElementById('password');
const strengthWrap = document.getElementById('strengthWrap');
const fill        = document.getElementById('strengthFill');
const label       = document.getElementById('strengthLabel');

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
        { w: '20%',  bg: '#ef4444', text: 'Très faible' },
        { w: '40%',  bg: '#f97316', text: 'Faible' },
        { w: '60%',  bg: '#eab308', text: 'Moyen' },
        { w: '80%',  bg: '#22c55e', text: 'Fort' },
        { w: '100%', bg: '#10b981', text: 'Très fort' },
    ];
    const lvl = levels[Math.min(score, 4)];
    fill.style.width      = lvl.w;
    fill.style.background = lvl.bg;
    label.textContent     = lvl.text;
    label.style.color     = lvl.bg;
});

/* Confirm match */
const confirmInput = document.getElementById('confirm_password');
confirmInput.addEventListener('input', function () {
    this.setCustomValidity(
        this.value && this.value !== pwInput.value ? 'Les mots de passe ne correspondent pas' : ''
    );
    this.classList.toggle('is-error', !!this.validationMessage);
});
</script>
</body>
</html>