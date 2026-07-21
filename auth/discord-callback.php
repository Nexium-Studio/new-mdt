<?php
/**
 * Traitement du retour Discord OAuth - MDT Service Public
 * Gestion des erreurs de doublons et synchronisation de compte
 */
require_once __DIR__ . '/../config/config.php';

// 1. Protection : Si déjà connecté, on dégage vers le dashboard
if (isLoggedIn()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// 2. Vérification du code d'autorisation
if (!isset($_GET['code'])) {
    redirect(SITE_URL . '/login.php?error=access_denied');
}

$code = $_GET['code'];

// 3. Échange du code contre le jeton d'accès (Access Token)
$token_url = "https://discord.com/api/oauth2/token";
$data = [
    'client_id'     => DISCORD_CLIENT_ID,
    'client_secret' => DISCORD_CLIENT_SECRET,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => DISCORD_REDIRECT_URI,
    'scope'         => 'identify email'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
$response = curl_exec($ch);
$token_data = json_decode($response, true);

if (!isset($token_data['access_token'])) {
    redirect(SITE_URL . '/login.php?error=discord_auth_failed');
}

$access_token = $token_data['access_token'];

// 4. Récupération des infos utilisateur Discord
curl_setopt($ch, CURLOPT_URL, "https://discord.com/api/users/@me");
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
$user_response = curl_exec($ch);
$discord_user = json_decode($user_response, true);
curl_close($ch);

if (!isset($discord_user['id'])) {
    redirect(SITE_URL . '/login.php?error=discord_data_error');
}

// 5. Traitement en Base de Données
try {
    $db = Database::getInstance()->getConnection();
    
    $discord_id = $discord_user['id'];
    $email      = sanitize($discord_user['email'] ?? '');
    $username   = sanitize($discord_user['username']);
    $avatar_id  = $discord_user['avatar'];
    
    // URL de l'avatar Discord
    $avatar_url = $avatar_id 
        ? "https://cdn.discordapp.com/avatars/$discord_id/$avatar_id.png" 
        : ASSETS_PATH . "/images/logos/default.png";

    // ÉTAPE A : On cherche si l'utilisateur existe déjà (ID Discord OU Email)
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ? OR email = ? LIMIT 1");
    $stmt->execute([$discord_id, $email]);
    $userData = $stmt->fetch();
    $discordOAuthNewAccount = false;

    if ($userData) {
        // MISE À JOUR : le compte existe, on synchronise l'ID Discord.
        // L'avatar Discord n'est qu'une valeur PAR DÉFAUT : on ne l'applique que
        // si l'utilisateur n'a pas encore d'avatar. Un avatar déjà défini
        // (personnalisé depuis le profil, ou hérité d'une précédente connexion)
        // est conservé et n'est JAMAIS écrasé lors des connexions suivantes.
        $currentAvatar = trim((string) ($userData['avatar'] ?? ''));
        if ($currentAvatar === '') {
            $update = $db->prepare("UPDATE users SET discord_id = ?, avatar = ? WHERE id = ?");
            $update->execute([$discord_id, $avatar_url, $userData['id']]);
            $userData['avatar'] = $avatar_url;
        } else {
            $update = $db->prepare("UPDATE users SET discord_id = ? WHERE id = ?");
            $update->execute([$discord_id, $userData['id']]);
        }
        $userData['discord_id'] = $discord_id;
    } else {
        $discordOAuthNewAccount = true;
        // INSCRIPTION : Nouveau compte
        
        // ÉTAPE B : Sécurité "Duplicate entry" pour le username
        // Si le pseudo Discord est déjà pris sur le MDT, on le rend unique
        $checkUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $checkUser->execute([$username]);
        
        if ($checkUser->fetchColumn() > 0) {
            // On ajoute les 4 derniers chiffres de l'ID Discord pour différencier
            $username = $username . '_' . substr($discord_id, -4);
        }

        $insert = $db->prepare("
            INSERT INTO users (username, email, discord_id, avatar, role, faction, created_at, is_active) 
            VALUES (?, ?, ?, ?, 'Citoyen', 'NONE', NOW(), 1)
        ");
        $insert->execute([$username, $email, $discord_id, $avatar_url]);
        
        // On récupère les données créées
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$db->lastInsertId()]);
        $userData = $stmt->fetch();
    }

    // 6. Initialisation de la Session (Identique à ton système actuel)
    $_SESSION['user_id']            = $userData['id'];
    $_SESSION['user_email']         = $userData['email'];
    $_SESSION['user_username']      = $userData['username'];
    $_SESSION['user_avatar']        = $userData['avatar'];
    $_SESSION['user_faction']       = $userData['faction'];
    $_SESSION['user_faction_grade'] = $userData['faction_grade'] ?? 'Aucun';
    $_SESSION['user_role']          = $userData['role'];

    mdt_session_refresh_display_name();

    // ── Synchronisation A : Rôles MDT (Serveur Discord A) ───────────────────
    try {
        require_once __DIR__ . '/../includes/mdt_discord_sync.php';
        $syncResult = mdt_discord_sync_user($db, (int)$userData['id'], (string)$discord_id, 'login');
        if ($syncResult['changed']) {
            $_SESSION['user_role'] = $syncResult['new_role'];
        }
    } catch (Throwable $syncErr) {
        error_log('[discord-callback] Role sync error: ' . $syncErr->getMessage());
    }

    // ── Synchronisation B : Factions MDT (Serveur Discord B) ────────────────
    try {
        require_once __DIR__ . '/../includes/mdt_faction_discord_sync.php';
        mdt_faction_sync_user($db, (int)$userData['id'], (string)$discord_id, 'login');
        // Recharger la faction en session si changée
        $freshFaction = $db->prepare("SELECT faction FROM users WHERE id=? LIMIT 1");
        $freshFaction->execute([(int)$userData['id']]);
        $_SESSION['user_faction'] = (string)($freshFaction->fetchColumn() ?: 'NONE');
    } catch (Throwable $syncErr) {
        error_log('[discord-callback] Faction sync error: ' . $syncErr->getMessage());
    }

    mdt_audit_log(
        (int) $userData['id'],
        'AUTH',
        'Connexion',
        $discordOAuthNewAccount
            ? 'OAuth Discord — nouveau compte créé (@' . ($userData['username'] ?? '') . ')'
            : 'OAuth Discord — connexion (@' . ($userData['username'] ?? '') . ')',
        'auth',
        null
    );

    // 7. Redirection finale
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
        redirect(SITE_URL . '/pages/staff/dashboard.php');
    } else {
        redirect(SITE_URL . '/pages/dashboard.php');
    }

} catch (PDOException $e) {
    // En cas d'erreur fatale, on affiche le message pour débugger une dernière fois
    die("Erreur critique Base de données : " . $e->getMessage());
}