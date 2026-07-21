<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/nexium_theme.php';
requireLogin();

$db      = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// ── Récupérer les infos utilisateur ──────────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*, cr.rank_name as company_rank_name, cr.rank_level,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    LEFT JOIN company_ranks cr ON u.faction = cr.faction AND u.faction_rank = cr.rank_name
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) redirect(SITE_URL . '/login.php');

// ── Traitement des actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_avatar') {
        $avatar = sanitize($_POST['avatar']);
        if (empty($avatar) || filter_var($avatar, FILTER_VALIDATE_URL)) {
            $stmt = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            if ($stmt->execute([$avatar, $_SESSION['user_id']])) {
                $_SESSION['user_avatar'] = $avatar;
                $user['avatar'] = $avatar;
                $message = 'Avatar mis à jour avec succès'; $messageType = 'success';
                mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Modification avatar', 'Avatar mis à jour', 'user', $user['faction'] ?? null);
            }
        } else { $message = "URL d'avatar invalide"; $messageType = 'error'; }
    }

    if ($action === 'update_email') {
        $newEmail = sanitize($_POST['email']); $password = $_POST['current_password'];
        if (!password_verify($password, $user['password'])) {
            $message = 'Mot de passe actuel incorrect'; $messageType = 'error';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Email invalide'; $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$newEmail, $_SESSION['user_id']]);
            if ($stmt->fetch()) { $message = 'Cet email est déjà utilisé'; $messageType = 'error'; }
            else {
                $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                if ($stmt->execute([$newEmail, $_SESSION['user_id']])) {
                    $_SESSION['user_email'] = $newEmail; $user['email'] = $newEmail;
                    $message = 'Email mis à jour avec succès'; $messageType = 'success';
                    mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Modification email', "Changement email: $newEmail", 'user', $user['faction'] ?? null);
                }
            }
        }
    }

    if ($action === 'update_username') {
        $newUsername = sanitize($_POST['username']); $password = $_POST['current_password'];
        if (!password_verify($password, $user['password'])) {
            $message = 'Mot de passe actuel incorrect'; $messageType = 'error';
        } elseif (strlen($newUsername) < 3) {
            $message = "Le nom d'utilisateur doit contenir au moins 3 caractères"; $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $_SESSION['user_id']]);
            if ($stmt->fetch()) { $message = "Ce nom d'utilisateur est déjà utilisé"; $messageType = 'error'; }
            else {
                $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
                if ($stmt->execute([$newUsername, $_SESSION['user_id']])) {
                    $_SESSION['user_username'] = $newUsername; $user['username'] = $newUsername;
                    mdt_session_refresh_display_name();
                    $message = "Nom d'utilisateur mis à jour"; $messageType = 'success';
                    mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Modification username', "Changement username: $newUsername", 'user', $user['faction'] ?? null);
                }
            }
        }
    }

    if ($action === 'update_password') {
        $currentPassword = $_POST['current_password'];
        $newPassword     = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        if (!password_verify($currentPassword, $user['password'])) {
            $message = 'Mot de passe actuel incorrect'; $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Le nouveau mot de passe doit contenir au moins 8 caractères'; $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Les mots de passe ne correspondent pas'; $messageType = 'error';
        } else {
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION['user_id']])) {
                $message = 'Mot de passe mis à jour'; $messageType = 'success';
                mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Modification mot de passe', 'Changement de mot de passe', 'user', $user['faction'] ?? null);
            }
        }
    }

    if ($action === 'link_discord') {
        $discordId = sanitize($_POST['discord_id']);
        if (empty($discordId) || !preg_match('/^[0-9]{17,19}$/', $discordId)) {
            $message = 'ID Discord invalide (17 à 19 chiffres)'; $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE discord_id = ? AND id != ?");
            $stmt->execute([$discordId, $_SESSION['user_id']]);
            if ($stmt->fetch()) { $message = 'Cet ID Discord est déjà lié à un autre compte'; $messageType = 'error'; }
            else {
                $stmt = $db->prepare("UPDATE users SET discord_id = ? WHERE id = ?");
                if ($stmt->execute([$discordId, $_SESSION['user_id']])) {
                    $user['discord_id'] = $discordId;
                    $message = 'Compte Discord lié avec succès'; $messageType = 'success';
                    mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Lier Discord', "Liaison Discord ID: $discordId", 'user', $user['faction'] ?? null);
                }
            }
        }
    }

    if ($action === 'unlink_discord') {
        $stmt = $db->prepare("UPDATE users SET discord_id = NULL WHERE id = ?");
        if ($stmt->execute([$_SESSION['user_id']])) {
            $user['discord_id'] = null;
            $message = 'Compte Discord délié'; $messageType = 'success';
            mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Délier Discord', 'Déliaison Discord', 'user', $user['faction'] ?? null);
        }
    }

    if ($action === 'update_rp') {
        $profileFirst = trim($_POST['rp_firstname'] ?? '');
        $profileLast  = trim($_POST['rp_lastname'] ?? '');
        $fn = $profileFirst !== '' ? $profileFirst : '—';
        $ln = $profileLast !== '' ? $profileLast : '—';
        try {
            $pid = (int) ($user['profile_id'] ?? 0);
            if ($pid > 0) {
                $db->prepare('UPDATE profiles SET firstname = ?, lastname = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$fn, $ln, $pid]);
            } elseif ($profileFirst !== '' || $profileLast !== '') {
                $db->prepare('INSERT INTO profiles (firstname, lastname) VALUES (?, ?)')->execute([$fn, $ln]);
                $newPid = (int) $db->lastInsertId();
                if ($newPid > 0) {
                    $db->prepare('UPDATE users SET profile_id = ? WHERE id = ?')->execute([$newPid, $_SESSION['user_id']]);
                }
            }
            mdt_session_refresh_display_name();
            $message = 'Nom RP mis à jour'; $messageType = 'success';
            mdt_audit_log((int) $_SESSION['user_id'], 'PROFILE', 'Modification nom RP', 'Prénom / nom personnage mis à jour', 'user', $user['faction'] ?? null);
        } catch (Throwable $e) {
            $message = 'Impossible d\'enregistrer le nom RP'; $messageType = 'error';
        }
    }
}

$stmt = $db->prepare("
    SELECT u.*, cr.rank_name as company_rank_name, cr.rank_level,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    LEFT JOIN company_ranks cr ON u.faction = cr.faction AND u.faction_rank = cr.rank_name
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    redirect(SITE_URL . '/login.php');
}

// ── Données affichage ─────────────────────────────────────────────────────────
// ── Nom + couleur de faction, priorité :
//    1. admin_panel_config.custom_name / custom_color  (mis à jour par mdt.php)
//    2. factions.name / factions.color                 (mis à jour par roles.php)
//    3. $user['faction'] brut                          (fallback ultime)
$factionLabel = 'Aucune faction';
$factionColor = '#3b82f6';

if (!empty($user['faction']) && $user['faction'] !== 'NONE') {

    // ── Priorité 1 : admin_panel_config ──────────────────────────────────────
    $nameFromConfig  = null;
    $colorFromConfig = null;
    try {
        $stmtCfg = $db->prepare(
            "SELECT custom_name, custom_color FROM admin_panel_config WHERE panel_key = ? LIMIT 1"
        );
        $stmtCfg->execute([$user['faction']]);
        $rowCfg = $stmtCfg->fetch();
        if ($rowCfg) {
            if (!empty($rowCfg['custom_name']))  $nameFromConfig  = $rowCfg['custom_name'];
            if (!empty($rowCfg['custom_color']) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $rowCfg['custom_color'])) {
                $colorFromConfig = $rowCfg['custom_color'];
            }
        }
    } catch (Exception $e) {}

    // ── Priorité 2 : table factions ──────────────────────────────────────────
    $nameFromFactions  = null;
    $colorFromFactions = null;
    try {
        $stmtF = $db->prepare("SELECT name, color FROM factions WHERE faction_key = ? LIMIT 1");
        $stmtF->execute([$user['faction']]);
        $rowF = $stmtF->fetch();
        if ($rowF) {
            if (!empty($rowF['name']))  $nameFromFactions  = $rowF['name'];
            if (!empty($rowF['color']) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $rowF['color'])) {
                $colorFromFactions = $rowF['color'];
            }
        }
    } catch (Exception $e) {}

    // ── Fusion : custom_name > factions.name > clé brute ────────────────────
    $factionLabel = $nameFromConfig ?? $nameFromFactions ?? $user['faction'];
    $factionColor = $colorFromConfig ?? $colorFromFactions ?? '#3b82f6';
}
$rpDisplay     = mdt_rp_display_name($user['profile_firstname'] ?? null, $user['profile_lastname'] ?? null, (string) $user['username']);
$initial       = mdt_rp_initials($user['profile_firstname'] ?? null, $user['profile_lastname'] ?? null, (string) $user['username']);
$memberSince   = date('d/m/Y', strtotime($user['created_at']));
$lastLogin     = $user['last_login'] ? date('d/m/Y à H:i', strtotime($user['last_login'])) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil — <?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'MDT'; ?></title>
    <?php nexium_theme_head('dark'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Charte Nexium — bleu #1E6FE5 → violet #6C3FC5 · Montserrat / Inter */
        :root, :root[data-theme="dark"] {
            --bg:       #0C1023;
            --bg-2:     #171C31;
            --bg-card:  #141a2e;
            --border:   rgba(255,255,255,0.08);
            --border-2: rgba(255,255,255,0.16);
            --text:     #F4F6FB;
            --muted:    #565E78;
            --muted-2:  #9aa3bc;
            --accent:   #1E6FE5;
            --grad: linear-gradient(120deg, #1E6FE5, #6C3FC5);
            --faction:  <?php echo $factionColor; ?>;
            /* alias pour le bouton de thème partagé */
            --surface: var(--bg-card);
            --text-2: var(--muted-2);
            --border-strong: var(--border-2);
        }
        :root[data-theme="light"] {
            --bg:       #F5F6FA;
            --bg-2:     #eef0f6;
            --bg-card:  #FFFFFF;
            --border:   #E4E7EF;
            --border-2: #D5DAE6;
            --text:     #1A1F30;
            --muted:    #98a0b4;
            --muted-2:  #565E78;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Halo de fond retiré — charte Nexium sobre. */

        .container { max-width: 1100px; margin: 0 auto; padding: 0 2rem; width: 100%; }

        /* ── Topbar ── */
        .topbar {
            height: 60px;
            background: color-mix(in srgb, var(--bg) 85%, transparent);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            position: sticky; top: 0; z-index: 100;
        }

        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .topbar-left { display: flex; align-items: center; gap: 14px; }

        .topbar-back {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted-2);
            text-decoration: none;
            font-size: .82rem;
            font-weight: 500;
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255,255,255,.03);
            transition: all .2s;
        }

        .topbar-back:hover { color: var(--text); background: rgba(255,255,255,.06); border-color: var(--border-2); }

        .topbar-title {
            font-family: 'Montserrat', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            color: var(--text);
        }

        .topbar-logout {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted-2);
            text-decoration: none;
            font-size: .82rem;
            font-weight: 500;
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255,255,255,.03);
            transition: all .2s;
        }

        .topbar-logout:hover { color: #fca5a5; border-color: rgba(239,68,68,.3); background: rgba(239,68,68,.06); }

        /* ── Layout ── */
        .profile-layout {
            padding: 36px 0 60px;
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── Sidebar ── */
        .sidebar {
            position: sticky;
            top: 84px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* Avatar card */
        .avatar-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        /* Colored band at top based on faction */
        .avatar-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--faction), transparent);
        }

        .avatar-img,
        .avatar-initials {
            width: 80px; height: 80px;
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-img {
            object-fit: cover;
            border: 2px solid var(--border-2);
        }

        .avatar-initials {
            background: color-mix(in srgb, var(--faction) 20%, transparent);
            border: 2px solid color-mix(in srgb, var(--faction) 35%, transparent);
            font-family: 'Montserrat', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--faction);
        }

        .avatar-name {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .avatar-email {
            font-size: .75rem;
            color: var(--muted-2);
            margin-bottom: 16px;
            word-break: break-all;
        }

        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.05);
            border: 1px solid var(--border);
            border-radius: 99px;
            padding: 4px 12px;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--muted-2);
            margin-bottom: 16px;
        }

        .faction-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            background: color-mix(in srgb, var(--faction) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--faction) 25%, transparent);
            border-radius: 10px;
            padding: 10px 14px;
            text-align: left;
        }

        .faction-chip-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            background: color-mix(in srgb, var(--faction) 20%, transparent);
            display: grid; place-items: center;
            font-size: .9rem;
            color: var(--faction);
            flex-shrink: 0;
        }

        .faction-chip-name {
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
        }

        .faction-chip-rank {
            font-size: .7rem;
            color: var(--muted-2);
        }

        /* Meta list */
        .meta-list {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            font-size: .8rem;
        }

        .meta-item:last-child { border-bottom: none; }

        .meta-item i {
            width: 16px;
            text-align: center;
            color: var(--muted-2);
            font-size: .85rem;
            flex-shrink: 0;
        }

        .meta-item span { color: var(--muted-2); }

        /* ── Main content ── */
        .main-content { display: flex; flex-direction: column; gap: 16px; }

        /* Flash message */
        .flash {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: .875rem;
            font-weight: 500;
            animation: slideIn .3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .flash-success { background: rgba(34,197,94,.08);  border: 1px solid rgba(34,197,94,.2);  color: #86efac; }
        .flash-error   { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.2);  color: #fca5a5; }

        /* Section card */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .section-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            gap: 12px;
        }

        .section-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Montserrat', sans-serif;
            font-size: .9rem;
            font-weight: 700;
        }

        .section-card-title i {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: grid; place-items: center;
            font-size: .85rem;
            background: rgba(59,130,246,.1);
            color: var(--accent);
        }

        .section-card-body { padding: 22px; }

        /* Form styles */
        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }

        .form-label {
            display: block;
            font-size: .75rem;
            font-weight: 600;
            color: var(--muted-2);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: 'Inter', sans-serif;
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

        .form-hint {
            display: block;
            font-size: .75rem;
            color: var(--muted);
            margin-top: 5px;
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

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
            font-size: .85rem;
            transition: color .2s;
        }

        .pw-toggle:hover { color: var(--text); }
        .pw-wrap .form-input { padding-right: 42px; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all .2s cubic-bezier(.4,0,.2,1);
        }

        .btn-sm { padding: 7px 14px; font-size: .8rem; }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { filter: brightness(1.08); }

        .btn-ghost { background: rgba(255,255,255,.05); color: var(--muted-2); border: 1px solid var(--border); }
        .btn-ghost:hover { background: rgba(255,255,255,.09); color: var(--text); border-color: var(--border-2); }

        .btn-danger { background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
        .btn-danger:hover { background: rgba(239,68,68,.18); }

        .btn-discord-link { background: rgba(88,101,242,.1); color: #a5b4fc; border: 1px solid rgba(88,101,242,.25); }
        .btn-discord-link:hover { background: rgba(88,101,242,.2); }

        .btn-block { width: 100%; }

        /* Discord section */
        .discord-status {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.02);
            margin-bottom: 16px;
        }

        .discord-status.linked { border-color: rgba(88,101,242,.3); background: rgba(88,101,242,.06); }

        .discord-status-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: grid; place-items: center;
            font-size: .9rem;
            flex-shrink: 0;
        }

        .discord-status.linked .discord-status-icon { background: rgba(88,101,242,.2); color: #a5b4fc; }
        .discord-status:not(.linked) .discord-status-icon { background: rgba(255,255,255,.06); color: var(--muted-2); }

        .discord-status-label { font-size: .72rem; color: var(--muted-2); margin-bottom: 2px; }
        .discord-status-value { font-size: .85rem; font-weight: 600; }

        /* Info box */
        .info-box {
            background: rgba(59,130,246,.05);
            border: 1px solid rgba(59,130,246,.15);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: .82rem;
            color: var(--muted-2);
            margin-bottom: 16px;
            line-height: 1.6;
        }

        .info-box ol { margin: 6px 0 0 18px; }
        .info-box strong { color: var(--text); }

        /* ── Modals ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            backdrop-filter: blur(6px);
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
        }

        .modal-overlay.open {
            opacity: 1;
            pointer-events: all;
        }

        .modal {
            background: var(--bg-2);
            border: 1px solid var(--border-2);
            border-radius: 20px;
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            transform: scale(.95) translateY(8px);
            transition: transform .25s cubic-bezier(.4,0,.2,1);
        }

        .modal-overlay.open .modal { transform: scale(1) translateY(0); }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            font-family: 'Montserrat', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i { color: var(--accent); }

        .modal-close {
            width: 30px; height: 30px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.04);
            color: var(--muted-2);
            display: grid; place-items: center;
            cursor: pointer;
            font-size: 1rem;
            transition: all .2s;
        }

        .modal-close:hover { background: rgba(255,255,255,.09); color: var(--text); }

        .modal-body { padding: 22px; }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid var(--border);
        }

        .modal-footer .btn { width: auto; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .profile-layout { grid-template-columns: 1fr; }
            .sidebar { position: static; }
        }

        @media (max-width: 640px) {
            .container { padding: 0 1rem; }
            .form-row { grid-template-columns: 1fr; }
            .section-card-body { padding: 16px; }
        }
        /* Champs plus lisibles en thème clair */
        :root[data-theme="light"] .form-input { background: #F5F6FA; }
        :root[data-theme="light"] .form-input:focus { background: #fff; }
        :root[data-theme="light"] .btn-ghost { background: #F5F6FA; }
        <?php echo nexium_theme_toggle_css(); ?>
    </style>
</head>
<body>

<!-- ── Topbar ── -->
<div class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-left">
            <a href="pages/dashboard.php" class="topbar-back">
                <i class="fa-solid fa-arrow-left"></i>
                Dashboard
            </a>
            <span class="topbar-title">Mon Profil</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php nexium_theme_toggle(); ?>
            <a href="logout.php" class="topbar-logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                Déconnexion
            </a>
        </div>
    </div>
</div>

<!-- ── Page ── -->
<div class="container">
    <div class="profile-layout">

        <!-- ── SIDEBAR ── -->
        <aside class="sidebar">
            <!-- Avatar card -->
            <div class="avatar-card">
                <?php if ($user['avatar']): ?>
                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="Avatar" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-initials"><?php echo $initial; ?></div>
                <?php endif; ?>

                <div class="avatar-name"><?php echo htmlspecialchars($rpDisplay); ?></div>
                <div class="avatar-email" style="font-size:.72rem;color:var(--muted-2);">@<?php echo htmlspecialchars($user['username']); ?></div>
                <div class="avatar-email"><?php echo htmlspecialchars($user['email']); ?></div>

                <div class="role-chip">
                    <i class="fa-solid fa-circle-dot" style="color:var(--accent);font-size:.5rem;"></i>
                    <?php echo htmlspecialchars($user['role']); ?>
                </div>

                <?php if ($user['faction'] && $user['faction'] !== 'NONE'): ?>
                <div class="faction-chip">
                    <div class="faction-chip-icon"><i class="fa-solid fa-building"></i></div>
                    <div>
                        <div class="faction-chip-name"><?php echo htmlspecialchars($factionLabel); ?></div>
                        <?php if ($user['faction_rank']): ?>
                        <div class="faction-chip-rank"><?php echo htmlspecialchars($user['faction_rank']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Meta -->
            <div class="meta-list">
                <div class="meta-item">
                    <i class="fa-solid fa-calendar"></i>
                    <span>Membre depuis le <?php echo $memberSince; ?></span>
                </div>
                <?php if ($lastLogin): ?>
                <div class="meta-item">
                    <i class="fa-solid fa-clock"></i>
                    <span>Dernière connexion le <?php echo $lastLogin; ?></span>
                </div>
                <?php endif; ?>
                <?php if ($user['discord_id']): ?>
                <div class="meta-item">
                    <i class="fa-brands fa-discord" style="color:#a5b4fc;"></i>
                    <span style="color:#a5b4fc;font-weight:600;">Discord lié</span>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <i class="fa-solid fa-hashtag"></i>
                    <span>ID #<?php echo htmlspecialchars($user['id']); ?></span>
                </div>
            </div>
        </aside>

        <!-- ── MAIN CONTENT ── -->
        <div class="main-content">

            <?php if ($message): ?>
            <div class="flash flash-<?php echo $messageType; ?>">
                <i class="fa-solid fa-<?php echo $messageType === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Nom RP -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-id-card"></i>
                        Nom RP
                    </div>
                </div>
                <div class="section-card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_rp">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Prénom personnage</label>
                                <input type="text" name="rp_firstname" class="form-input" maxlength="120" autocomplete="off"
                                    placeholder="Prénom RP"
                                    value="<?php
                                    $vfn = trim((string) ($user['profile_firstname'] ?? ''));
                                    echo htmlspecialchars($vfn !== '' && $vfn !== '—' ? $vfn : '');
                                    ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nom personnage</label>
                                <input type="text" name="rp_lastname" class="form-input" maxlength="120" autocomplete="off"
                                    placeholder="Nom RP"
                                    value="<?php
                                    $vln = trim((string) ($user['profile_lastname'] ?? ''));
                                    echo htmlspecialchars($vln !== '' && $vln !== '—' ? $vln : '');
                                    ?>">
                            </div>
                        </div>
                        <span class="form-hint">Affiché à la place du pseudo sur le site. Laisser vide pour revenir au pseudo.</span>
                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px;">
                            <i class="fa-solid fa-floppy-disk"></i> Enregistrer le nom RP
                        </button>
                    </form>
                </div>
            </div>

            <!-- Avatar -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-image"></i>
                        Avatar
                    </div>
                </div>
                <div class="section-card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_avatar">
                        <div class="form-group">
                            <label class="form-label">URL de l'avatar</label>
                            <input type="url" name="avatar" class="form-input"
                                placeholder="https://cdn.discordapp.com/avatars/..."
                                value="<?php echo htmlspecialchars($user['avatar'] ?? ''); ?>">
                            <span class="form-hint">Laisser vide pour utiliser l'initiale du nom</span>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                        </button>
                    </form>
                </div>
            </div>

            <!-- Discord -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-brands fa-discord" style="color:#a5b4fc;background:rgba(88,101,242,.15);"></i>
                        Compte Discord
                    </div>
                </div>
                <div class="section-card-body">
                    <?php if ($user['discord_id']): ?>
                    <div class="discord-status linked">
                        <div class="discord-status-icon"><i class="fa-solid fa-check"></i></div>
                        <div>
                            <div class="discord-status-label">Compte lié</div>
                            <div class="discord-status-value"><?php echo htmlspecialchars($user['discord_id']); ?></div>
                        </div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Confirmer la déconnexion Discord ?');">
                        <input type="hidden" name="action" value="unlink_discord">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fa-solid fa-link-slash"></i> Délier le compte
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="discord-status">
                        <div class="discord-status-icon"><i class="fa-brands fa-discord"></i></div>
                        <div>
                            <div class="discord-status-label">Aucun compte lié</div>
                            <div class="discord-status-value" style="color:var(--muted-2);">Non associé</div>
                        </div>
                    </div>
                    <button onclick="openModal('discordModal')" class="btn btn-discord-link btn-sm">
                        <i class="fa-solid fa-link"></i> Lier mon compte Discord
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Paramètres du compte -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-gear"></i>
                        Paramètres du compte
                    </div>
                </div>
                <div class="section-card-body">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button onclick="openModal('emailModal')" class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-envelope"></i> Modifier l'email
                        </button>
                        <button onclick="openModal('usernameModal')" class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-user"></i> Modifier le nom
                        </button>
                        <button onclick="openModal('passwordModal')" class="btn btn-ghost btn-sm">
                            <i class="fa-solid fa-lock"></i> Changer le mot de passe
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /main-content -->
    </div><!-- /profile-layout -->
</div><!-- /container -->


<!-- ══════════ MODALS ══════════ -->

<!-- Discord -->
<div id="discordModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-brands fa-discord"></i> Lier mon compte Discord</h2>
            <button class="modal-close" onclick="closeModal('discordModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="link_discord">
                <div class="info-box">
                    <strong>Comment trouver mon ID Discord ?</strong>
                    <ol>
                        <li>Activez le Mode Développeur (Paramètres → Avancés)</li>
                        <li>Cliquez-droit sur votre profil</li>
                        <li>Sélectionnez "Copier l'identifiant"</li>
                    </ol>
                </div>
                <div class="form-group">
                    <label class="form-label">Votre ID Discord</label>
                    <input type="text" name="discord_id" class="form-input" placeholder="123456789012345678" pattern="[0-9]{17,19}" required>
                    <span class="form-hint">17 à 19 chiffres</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('discordModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-link"></i> Lier</button>
            </div>
        </form>
    </div>
</div>

<!-- Email -->
<div id="emailModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-envelope"></i> Modifier l'email</h2>
            <button class="modal-close" onclick="closeModal('emailModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_email">
                <div class="form-group">
                    <label class="form-label">Nouvel email</label>
                    <input type="email" name="email" class="form-input" placeholder="nouveau@email.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Mot de passe actuel</label>
                    <div class="pw-wrap">
                        <input type="password" name="current_password" class="form-input" placeholder="••••••••" required>
                        <button type="button" class="pw-toggle" onclick="togglePw(this)" tabindex="-1">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('emailModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Username -->
<div id="usernameModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-user"></i> Modifier le nom d'utilisateur</h2>
            <button class="modal-close" onclick="closeModal('usernameModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_username">
                <div class="form-group">
                    <label class="form-label">Nouveau nom d'utilisateur</label>
                    <input type="text" name="username" class="form-input" minlength="3" placeholder="Prénom Nom" required>
                    <span class="form-hint">Min. 3 caractères</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Mot de passe actuel</label>
                    <div class="pw-wrap">
                        <input type="password" name="current_password" class="form-input" placeholder="••••••••" required>
                        <button type="button" class="pw-toggle" onclick="togglePw(this)" tabindex="-1">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('usernameModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Password -->
<div id="passwordModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-lock"></i> Changer le mot de passe</h2>
            <button class="modal-close" onclick="closeModal('passwordModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_password">
                <div class="form-group">
                    <label class="form-label">Mot de passe actuel</label>
                    <div class="pw-wrap">
                        <input type="password" name="current_password" class="form-input" placeholder="••••••••" required>
                        <button type="button" class="pw-toggle" onclick="togglePw(this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nouveau mot de passe</label>
                    <div class="pw-wrap">
                        <input type="password" name="new_password" class="form-input" minlength="8" placeholder="••••••••" required>
                        <button type="button" class="pw-toggle" onclick="togglePw(this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                    </div>
                    <span class="form-hint">Min. 8 caractères</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirmer le nouveau mot de passe</label>
                    <div class="pw-wrap">
                        <input type="password" name="confirm_password" class="form-input" minlength="8" placeholder="••••••••" required>
                        <button type="button" class="pw-toggle" onclick="togglePw(this)" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('passwordModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>


<script>
/* Modal helpers */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

/* Close on overlay click */
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function (e) {
        if (e.target === this) closeModal(this.id);
    });
});

/* Close on Escape */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    }
});

/* Password toggle */
function togglePw(btn) {
    const input = btn.previousElementSibling;
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

/* Auto-ouvrir le modal si erreur POST */
<?php if ($message && $messageType === 'error'): ?>
// Ré-ouvrir le dernier modal actif après rechargement
const lastModal = sessionStorage.getItem('lastModal');
if (lastModal) { openModal(lastModal); sessionStorage.removeItem('lastModal'); }
<?php endif; ?>

document.querySelectorAll('.modal-overlay form').forEach(form => {
    form.addEventListener('submit', function () {
        sessionStorage.setItem('lastModal', this.closest('.modal-overlay').id);
    });
});
</script>
<?php nexium_theme_script(); ?>

</body>
</html>