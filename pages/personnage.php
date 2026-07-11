<?php
// pages/personnage.php — Sélection & création de personnage
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_once ROOT_PATH . '/includes/characters.php';

$db = Database::getInstance()->getConnection();
mdt_characters_ensure_schema($db);

$uid = (int) $_SESSION['user_id'];

if (empty($_SESSION['characters_csrf'])) {
    $_SESSION['characters_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['characters_csrf'];

$error   = '';
$success = '';
$openModal = false;

// Flash après création (PRG)
if (!empty($_SESSION['characters_flash'])) {
    $success = (string) $_SESSION['characters_flash'];
    unset($_SESSION['characters_flash']);
}

// ── ACTIONS POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'select_character') {
            $charId = (int) ($_POST['character_id'] ?? 0);
            if ($charId > 0 && mdt_select_character($db, $uid, $charId)) {
                if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
                    redirect(SITE_URL . '/pages/staff/dashboard.php');
                } else {
                    redirect(SITE_URL . '/pages/dashboard.php');
                }
            }
            $error = 'Impossible de sélectionner ce personnage.';
        }

        if ($action === 'create_character') {
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname  = trim($_POST['lastname'] ?? '');
            $heightRaw = trim($_POST['height_cm'] ?? '');
            $ageRaw    = trim($_POST['age'] ?? '');

            $errors = [];
            if ($firstname === '' || $lastname === '') {
                $errors[] = 'Le nom et le prénom sont requis.';
            }
            if (mb_strlen($firstname) > 100 || mb_strlen($lastname) > 100) {
                $errors[] = 'Nom ou prénom trop long (100 caractères max).';
            }
            if ($heightRaw !== '' && ((int) $heightRaw < 100 || (int) $heightRaw > 250)) {
                $errors[] = 'La taille doit être comprise entre 100 et 250 cm.';
            }
            if ($ageRaw !== '' && ((int) $ageRaw < 18 || (int) $ageRaw > 120)) {
                $errors[] = 'L\'âge doit être compris entre 18 et 120 ans.';
            }

            $limitNow = mdt_character_limit($db, (string) ($_SESSION['user_role'] ?? 'Citoyen'));
            if (mdt_count_characters($db, $uid) >= $limitNow) {
                $errors[] = 'Vous avez atteint la limite de ' . $limitNow . ' personnage(s).';
            }

            $avatarPath = null;
            if (!$errors) {
                $up = mdt_character_handle_avatar_upload($_FILES['avatar'] ?? null);
                if (!$up['ok']) {
                    $errors[] = $up['error'];
                } else {
                    $avatarPath = $up['path'];
                }
            }

            if ($errors) {
                $error = implode(' ', $errors);
                $openModal = true;
            } else {
                $newId = mdt_create_character($db, $uid, [
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'height_cm' => $heightRaw,
                    'age'       => $ageRaw,
                    'avatar'    => $avatarPath,
                ]);
                if ($newId > 0) {
                    $_SESSION['characters_flash'] = 'Personnage créé avec succès.';
                    redirect(SITE_URL . '/pages/personnage.php');
                }
                $error = 'Erreur lors de la création du personnage.';
                $openModal = true;
            }
        }
    }
}

// ── DONNÉES ──────────────────────────────────────────────────────────────
$characters = mdt_get_characters($db, $uid);
$count      = count($characters);
$limit      = mdt_character_limit($db, (string) ($_SESSION['user_role'] ?? 'Citoyen'));
$remaining  = max(0, $limit - $count);
$activeId   = (int) ($_SESSION['character_id'] ?? 0);

$displayName = function_exists('mdt_session_display_name')
    ? mdt_session_display_name()
    : (string) ($_SESSION['user_username'] ?? '');

$siteName = defined('SITE_NAME') ? (string) SITE_NAME : 'MDT';
$faviconUrl = '';
try {
    if (function_exists('mdt_site_branding')) {
        $branding   = mdt_site_branding($db);
        $siteName   = $branding['site_name'] ?: $siteName;
        $faviconUrl = (string) ($branding['favicon_url'] ?? '');
    }
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sélection du personnage — <?php echo htmlspecialchars($siteName); ?></title>
    <?php if ($faviconUrl !== ''): ?><link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:       #03070f;
            --bg-2:     #080f1d;
            --bg-card:  rgba(13, 21, 38, .85);
            --bg-elev:  #0d1526;
            --border:   rgba(255,255,255,.07);
            --border-2: rgba(255,255,255,.14);
            --text:     #e2e8f0;
            --muted:    #475569;
            --muted-2:  #64748b;
            --blue:     #3b82f6;
            --purple:   #8b5cf6;
            --green:    #10b981;
            --red:      #ef4444;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 32px 24px 64px;
            position: relative;
            overflow-x: hidden;
        }

        /* Orbes décoratifs (diffus, sans néon) */
        body::before {
            content: '';
            position: fixed;
            top: -220px; left: -220px;
            width: 640px; height: 640px;
            background: radial-gradient(circle, rgba(59,130,246,.09) 0%, transparent 65%);
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -180px; right: -180px;
            width: 560px; height: 560px;
            background: radial-gradient(circle, rgba(139,92,246,.06) 0%, transparent 65%);
            pointer-events: none;
        }

        .page { position: relative; z-index: 1; max-width: 1200px; margin: 0 auto; }

        /* ── Barre supérieure ── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 48px;
        }
        .topbar .brand {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: .95rem;
            color: var(--muted-2);
            letter-spacing: .04em;
        }
        .topbar .links { display: flex; gap: 8px; }
        .top-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted-2);
            text-decoration: none;
            font-size: .8rem;
            font-weight: 500;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: color .2s, border-color .2s, background .2s;
        }
        .top-link:hover { color: var(--text); border-color: var(--border-2); background: rgba(255,255,255,.03); }

        /* ── En-tête ── */
        .page-head { text-align: center; margin-bottom: 44px; animation: fadeInUp .5s ease both; }
        .page-head .eyebrow {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .28em;
            color: var(--muted-2);
            margin-bottom: 14px;
        }
        .page-head h1 {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: clamp(1.6rem, 3.4vw, 2.4rem);
            margin-bottom: 12px;
        }
        .page-head p {
            color: var(--muted-2);
            font-size: .92rem;
            max-width: 560px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ── Alertes ── */
        .alert {
            max-width: 720px;
            margin: 0 auto 28px;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error   { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.25);  color: #fca5a5; }
        .alert-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.25); color: #6ee7b7; }

        /* ── Grille ── */
        .chars-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 24px;
            justify-content: center;
        }

        /* ── Carte personnage ── */
        .char-card {
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            transition: transform .28s ease, border-color .28s ease, box-shadow .28s ease;
            animation: fadeInUp .5s ease both;
        }
        .char-card:hover {
            transform: translateY(-6px);
            border-color: color-mix(in srgb, var(--blue) 45%, transparent);
            box-shadow: 0 24px 48px -18px rgba(59,130,246,.35);
        }

        .char-photo {
            position: relative;
            aspect-ratio: 3 / 4;
            overflow: hidden;
            background: linear-gradient(160deg, rgba(59,130,246,.18), rgba(139,92,246,.16));
        }
        .char-photo img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .4s ease;
        }
        .char-card:hover .char-photo img { transform: scale(1.05); }
        .char-photo::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 45%, rgba(3,7,15,.55) 78%, rgba(3,7,15,.94) 100%);
            pointer-events: none;
        }
        .char-initials {
            width: 100%; height: 100%;
            display: grid;
            place-items: center;
            font-family: 'Syne', sans-serif;
            font-size: 3.4rem;
            font-weight: 800;
            color: rgba(226,232,240,.8);
        }

        .char-active-badge {
            position: absolute;
            top: 12px; right: 12px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .66rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            background: rgba(16,185,129,.18);
            border: 1px solid rgba(16,185,129,.35);
            color: #6ee7b7;
            backdrop-filter: blur(6px);
        }

        .char-name {
            position: absolute;
            left: 16px; right: 16px; bottom: 12px;
            z-index: 2;
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 1.12rem;
            line-height: 1.25;
            text-shadow: 0 2px 12px rgba(3,7,15,.8);
        }

        .char-body {
            padding: 14px 16px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }
        .char-meta { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .faction-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            align-self: flex-start;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            background: color-mix(in srgb, var(--fc, #64748b) 16%, transparent);
            border: 1px solid color-mix(in srgb, var(--fc, #64748b) 38%, transparent);
            color: color-mix(in srgb, var(--fc, #64748b) 70%, #ffffff);
        }
        .char-grade {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted-2);
            font-size: .8rem;
        }
        .char-grade i { font-size: .7rem; opacity: .7; }

        .btn-select {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border: none;
            border-radius: 12px;
            background: var(--blue);
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: .86rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, transform .2s;
        }
        .btn-select:hover { background: #2f6fe0; }

        /* ── Carte "Nouveau personnage" ── */
        .char-card-new {
            position: relative;
            border: 1px dashed var(--border-2);
            border-radius: 20px;
            background: rgba(255,255,255,.015);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 14px;
            padding: 40px 24px;
            min-height: 380px;
            transition: transform .28s ease, border-color .28s ease, background .28s ease;
            animation: fadeInUp .5s ease both;
        }
        .char-card-new:hover {
            transform: translateY(-6px);
            border-color: color-mix(in srgb, var(--purple) 45%, transparent);
            background: rgba(139,92,246,.04);
        }
        .new-plus {
            width: 64px; height: 64px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 1.5rem;
            color: #fff;
            background: linear-gradient(135deg, var(--blue), var(--purple));
        }
        .char-card-new h3 { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 1.05rem; }
        .char-card-new p { color: var(--muted-2); font-size: .82rem; line-height: 1.6; max-width: 240px; }

        /* ── Encart d'information ── */
        .slots-info {
            max-width: 720px;
            margin: 40px auto 0;
            padding: 16px 22px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: rgba(255,255,255,.02);
            color: var(--muted-2);
            font-size: .85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-align: center;
        }
        .slots-info i { color: var(--blue); }
        .slots-info strong { color: var(--text); font-weight: 600; }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            color: var(--muted-2);
            font-size: .9rem;
            padding: 20px 0 0;
        }

        /* ── Modale de création ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: none;
            place-items: center;
            padding: 20px;
            background: rgba(0,0,0,.7);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: grid; }
        .modal {
            width: 100%;
            max-width: 560px;
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: var(--bg-elev);
            border: 1px solid var(--border);
            border-radius: 20px;
            animation: modalIn .22s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        .modal-header h3 {
            font-family: 'Syne', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-header h3 i { color: var(--blue); }
        .modal-close {
            width: 34px; height: 34px;
            display: grid;
            place-items: center;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--muted-2);
            font-size: .9rem;
            cursor: pointer;
            transition: color .2s, border-color .2s;
        }
        .modal-close:hover { color: var(--text); border-color: var(--border-2); }
        .modal-body { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
        .modal-footer {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 18px 24px;
            border-top: 1px solid var(--border);
        }

        /* Upload photo */
        .avatar-upload {
            position: relative;
            border: 1px dashed var(--border-2);
            border-radius: 16px;
            min-height: 170px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: var(--muted-2);
            font-size: .82rem;
            cursor: pointer;
            overflow: hidden;
            transition: border-color .2s, background .2s;
        }
        .avatar-upload:hover { border-color: color-mix(in srgb, var(--blue) 45%, transparent); background: rgba(59,130,246,.04); }
        .avatar-upload i { font-size: 1.6rem; color: var(--blue); }
        .avatar-upload img {
            position: absolute;
            inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            display: none;
        }
        .avatar-upload.has-image img { display: block; }
        .avatar-upload.has-image .upload-hint { display: none; }
        .avatar-upload .upload-hint { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 0 16px; }
        .avatar-upload input[type="file"] { display: none; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 7px; }
        .form-group label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--muted-2);
        }
        .form-control {
            width: 100%;
            padding: 11px 14px;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border);
            border-radius: 11px;
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: .88rem;
            transition: border-color .2s, background .2s;
        }
        .form-control:focus {
            outline: none;
            border-color: color-mix(in srgb, var(--blue) 55%, transparent);
            background: rgba(59,130,246,.05);
        }
        .form-control::placeholder { color: var(--muted); }

        .modal-slots {
            font-size: .78rem;
            color: var(--muted-2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-slots i { color: var(--blue); }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 11px;
            border: none;
            font-family: 'DM Sans', sans-serif;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, color .2s, border-color .2s;
        }
        .btn-secondary {
            background: rgba(255,255,255,.04);
            color: var(--muted-2);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover { color: var(--text); border-color: var(--border-2); }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: #2f6fe0; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 640px) {
            body { padding: 20px 14px 48px; }
            .topbar { margin-bottom: 32px; }
            .chars-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
            .char-card-new { min-height: 280px; padding: 28px 16px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="brand"><?php echo htmlspecialchars($siteName); ?></div>
        <div class="links">
            <?php if ($activeId > 0): ?>
                <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="top-link"><i class="fa-solid fa-arrow-left"></i> Retour au MDT</a>
            <?php endif; ?>
            <a href="<?php echo SITE_URL; ?>/logout.php" class="top-link"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
        </div>
    </div>

    <div class="page-head">
        <div class="eyebrow">Sélection de personnage</div>
        <h1>Sélectionnez votre personnage</h1>
        <p>Choisissez le personnage avec lequel vous souhaitez accéder au MDT. Chaque personnage possède ses propres informations, accès et progression.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="chars-grid">
        <?php foreach ($characters as $i => $c):
            $fc        = ($c['faction'] !== 'NONE' && !empty($c['faction_color'])) ? $c['faction_color'] : '#64748b';
            $fIcon     = ($c['faction'] !== 'NONE' && !empty($c['faction_icon'])) ? $c['faction_icon'] : 'user';
            $fName     = $c['faction'] !== 'NONE' ? ($c['faction_name'] ?: $c['faction']) : 'Civil';
            $grade     = trim((string) ($c['faction_rank'] ?: $c['faction_grade'] ?: ''));
            $initials  = mb_strtoupper(mb_substr((string) $c['firstname'], 0, 1) . mb_substr((string) $c['lastname'], 0, 1));
        ?>
        <form method="POST" class="char-card" style="--fc: <?php echo htmlspecialchars($fc); ?>; animation-delay: <?php echo min($i * 70, 490); ?>ms"
              onclick="if (!event.target.closest('button')) this.submit();">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="select_character">
            <input type="hidden" name="character_id" value="<?php echo (int) $c['id']; ?>">

            <div class="char-photo">
                <?php if ((int) $c['id'] === $activeId): ?>
                    <div class="char-active-badge"><i class="fa-solid fa-circle-check"></i> Actif</div>
                <?php endif; ?>
                <?php if (!empty($c['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($c['avatar']); ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="char-initials"><?php echo htmlspecialchars($initials); ?></div>
                <?php endif; ?>
                <div class="char-name"><?php echo htmlspecialchars($c['firstname'] . ' ' . $c['lastname']); ?></div>
            </div>

            <div class="char-body">
                <div class="char-meta">
                    <span class="faction-badge"><i class="fa-solid fa-<?php echo htmlspecialchars($fIcon); ?>"></i> <?php echo htmlspecialchars($fName); ?></span>
                    <span class="char-grade"><i class="fa-solid fa-ranking-star"></i> <?php echo $grade !== '' ? htmlspecialchars($grade) : '—'; ?></span>
                </div>
                <button type="submit" class="btn-select"><i class="fa-solid fa-play"></i> Sélectionner</button>
            </div>
        </form>
        <?php endforeach; ?>

        <?php if ($remaining > 0): ?>
        <div class="char-card-new" style="animation-delay: <?php echo min($count * 70, 560); ?>ms" onclick="openCreateModal()">
            <div class="new-plus"><i class="fa-solid fa-plus"></i></div>
            <h3>Nouveau personnage</h3>
            <p>Créez un nouveau personnage pour rejoindre une faction ou vivre une nouvelle aventure.</p>
        </div>
        <?php endif; ?>

        <?php if ($count === 0 && $remaining === 0): ?>
            <div class="empty-state">Aucun personnage disponible. Contactez le staff.</div>
        <?php endif; ?>
    </div>

    <div class="slots-info">
        <i class="fa-solid fa-circle-info"></i>
        <span>
            Vous pouvez créer jusqu'à <strong><?php echo $limit; ?></strong> personnage<?php echo $limit > 1 ? 's' : ''; ?>.
            <?php if ($remaining > 0): ?>
                Il vous reste <strong><?php echo $remaining; ?></strong> emplacement<?php echo $remaining > 1 ? 's' : ''; ?> disponible<?php echo $remaining > 1 ? 's' : ''; ?>.
            <?php else: ?>
                Vous avez atteint la limite d'emplacements disponibles.
            <?php endif; ?>
        </span>
    </div>

</div>

<!-- ── Modale : création de personnage ── -->
<div class="modal-overlay" id="modal-create" onclick="if (event.target === this) closeCreateModal()">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-plus"></i> Nouveau personnage</h3>
            <button type="button" class="modal-close" onclick="closeCreateModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="createForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="create_character">
            <div class="modal-body">

                <label class="avatar-upload" id="avatarUpload">
                    <img id="avatarPreview" alt="">
                    <div class="upload-hint">
                        <i class="fa-solid fa-image"></i>
                        <span>Cliquez pour ajouter une image.<br>Formats acceptés : PNG, JPG, JPEG (max 4 Mo)</span>
                    </div>
                    <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg">
                </label>

                <div class="form-row">
                    <div class="form-group">
                        <label for="f-lastname">Nom</label>
                        <input type="text" name="lastname" id="f-lastname" class="form-control" placeholder="Ex : Smith" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label for="f-firstname">Prénom</label>
                        <input type="text" name="firstname" id="f-firstname" class="form-control" placeholder="Ex : John" maxlength="100" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="f-height">Taille (cm)</label>
                        <input type="number" name="height_cm" id="f-height" class="form-control" placeholder="Ex : 182" min="100" max="250">
                    </div>
                    <div class="form-group">
                        <label for="f-age">Âge</label>
                        <input type="number" name="age" id="f-age" class="form-control" placeholder="Ex : 28" min="18" max="120">
                    </div>
                </div>

                <div class="modal-slots">
                    <i class="fa-solid fa-circle-info"></i>
                    Vous pouvez créer jusqu'à <?php echo $limit; ?> personnage<?php echo $limit > 1 ? 's' : ''; ?>.
                    Il vous reste <?php echo $remaining; ?> emplacement<?php echo $remaining > 1 ? 's' : ''; ?>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Créer le personnage</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal()  { document.getElementById('modal-create').classList.add('active'); }
function closeCreateModal() { document.getElementById('modal-create').classList.remove('active'); }

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCreateModal();
});

// Aperçu de la photo
document.getElementById('avatarInput').addEventListener('change', function () {
    const file = this.files && this.files[0];
    const zone = document.getElementById('avatarUpload');
    const img  = document.getElementById('avatarPreview');
    if (file) {
        img.src = URL.createObjectURL(file);
        zone.classList.add('has-image');
    } else {
        img.removeAttribute('src');
        zone.classList.remove('has-image');
    }
});

<?php if ($openModal): ?>
openCreateModal();
<?php endif; ?>
</script>
</body>
</html>
