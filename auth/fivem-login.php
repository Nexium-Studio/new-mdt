<?php
/**
 * fivem-login.php — Page chargee DANS l'iframe de la tablette FiveM.
 *
 * Echange un ticket a usage unique (genere par fivem-ticket.php) contre une
 * session PHP MDT identique a celle d'OAuth Discord, puis redirige vers le
 * dashboard. Ticket invalide / expire / deja utilise -> login manuel.
 *
 * Securite :
 *  - Consommation atomique du ticket (anti double-usage / course).
 *  - Re-verification du statut du compte (actif / non banni) au moment du login.
 *  - Requetes preparees.
 */
require_once __DIR__ . '/../config/config.php';

$loginUrl = SITE_URL . '/login.php';

/* Deja connecte (session persistante) : on file directement au dashboard. */
if (isLoggedIn()) {
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
        redirect(SITE_URL . '/pages/staff/dashboard.php');
    }
    redirect(SITE_URL . '/pages/dashboard.php');
}

/* 1. Ticket present et bien forme ? */
$ticket = trim((string) ($_GET['ticket'] ?? ''));
if ($ticket === '' || !preg_match('/^[a-f0-9]{64}$/', $ticket)) {
    redirect($loginUrl);
}

try {
    $db = Database::getInstance()->getConnection();

    /* 2. Consommer le ticket de maniere atomique (usage unique + non expire). */
    $consume = $db->prepare(
        'UPDATE fivem_tickets SET used = 1
         WHERE ticket = ? AND used = 0 AND expires_at > NOW()'
    );
    $consume->execute([$ticket]);

    if ($consume->rowCount() !== 1) {
        // Inexistant, deja utilise ou expire.
        redirect($loginUrl);
    }

    /* 3. Utilisateur lie au ticket. */
    $stmt = $db->prepare('SELECT user_id FROM fivem_tickets WHERE ticket = ? LIMIT 1');
    $stmt->execute([$ticket]);
    $userId = (int) ($stmt->fetchColumn() ?: 0);
    if ($userId <= 0) {
        redirect($loginUrl);
    }

    /* 4. Re-verifier le compte AU MOMENT du login (actif / non banni). */
    $u = $db->prepare(
        'SELECT id, email, username, avatar, faction, faction_grade, role,
                is_active, is_banned, ban_expires
         FROM users WHERE id = ? LIMIT 1'
    );
    $u->execute([$userId]);
    $userData = $u->fetch(PDO::FETCH_ASSOC);

    $blocked = false;
    if (!$userData || (int) $userData['is_active'] !== 1) {
        $blocked = true;
    } elseif ((int) $userData['is_banned'] === 1) {
        $blocked = true;
    } elseif (!empty($userData['ban_expires']) && strtotime((string) $userData['ban_expires']) > time()) {
        $blocked = true;
    }

    if ($blocked) {
        redirect($loginUrl);
    }

    /* 5. Ouverture de la session — identique a auth/discord-callback.php. */
    session_regenerate_id(true); // anti-fixation : nouvel ID de session

    $_SESSION['user_id']            = $userData['id'];
    $_SESSION['user_email']         = $userData['email'];
    $_SESSION['user_username']      = $userData['username'];
    $_SESSION['user_avatar']        = $userData['avatar'];
    $_SESSION['user_faction']       = $userData['faction'];
    $_SESSION['user_faction_grade'] = $userData['faction_grade'] ?? 'Aucun';
    $_SESSION['user_role']          = $userData['role'];

    mdt_session_refresh_display_name();

    mdt_audit_log(
        (int) $userData['id'],
        'AUTH',
        'Connexion',
        'Auto-login FiveM (tablette) — @' . ($userData['username'] ?? ''),
        'auth',
        null
    );

    /* 6. Redirection finale (meme logique que le login classique). */
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
        redirect(SITE_URL . '/pages/staff/dashboard.php');
    }
    redirect(SITE_URL . '/pages/dashboard.php');

} catch (Throwable $e) {
    error_log('[fivem-login] ' . $e->getMessage());
    redirect($loginUrl);
}
