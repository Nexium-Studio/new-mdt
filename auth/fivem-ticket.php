<?php
/**
 * fivem-ticket.php — Endpoint PRIVE serveur-a-serveur.
 *
 * Appele UNIQUEMENT par le serveur FiveM (resource nexium_tablet, server.lua),
 * jamais par un navigateur/joueur. A partir d'un ID Discord, delivre un ticket
 * d'auto-login a usage unique (table fivem_tickets, expiration 60 s).
 *
 * Securite :
 *  - Cle secrete partagee obligatoire (header X-Fivem-Secret), comparee en temps
 *    constant (hash_equals).
 *  - Requetes preparees (anti-injection).
 *  - Aucun ticket pour un compte is_active=0, is_banned=1 ou ban_expires actif.
 *
 * Reponse JSON : { "ticket": "<hex64>" }  ou  { "ticket": null }
 */
declare(strict_types=1);

// Bootstrap minimal (constantes + DB) SANS demarrer de session ni la garde licence.
require_once __DIR__ . '/../config/config.local.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fivem_ticket_fail(int $httpCode, string $error): void {
    http_response_code($httpCode);
    echo json_encode(['ticket' => null, 'error' => $error]);
    exit;
}

// 1. Methode
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fivem_ticket_fail(405, 'method_not_allowed');
}

// 2. Cle secrete (temps constant)
$provided = (string) ($_SERVER['HTTP_X_FIVEM_SECRET'] ?? '');
if (!defined('FIVEM_AUTH_SECRET') || (string) FIVEM_AUTH_SECRET === ''
    || $provided === '' || !hash_equals((string) FIVEM_AUTH_SECRET, $provided)) {
    fivem_ticket_fail(403, 'forbidden');
}

// 3. Payload : ID Discord (snowflake)
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$discordId = is_array($body) ? trim((string) ($body['discord_id'] ?? '')) : '';

if ($discordId === '' || !preg_match('/^\d{15,25}$/', $discordId)) {
    fivem_ticket_fail(400, 'invalid_discord_id');
}

try {
    $db = Database::getInstance()->getConnection();

    // 4. Compte lie et exploitable ?
    $stmt = $db->prepare(
        'SELECT id, is_active, is_banned, ban_expires
         FROM users WHERE discord_id = ? LIMIT 1'
    );
    $stmt->execute([$discordId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Compte bloque si : inexistant, inactif, banni, ou ban temporaire encore actif.
    $blocked = false;
    if (!$user || (int) $user['is_active'] !== 1) {
        $blocked = true;
    } elseif ((int) $user['is_banned'] === 1) {
        $blocked = true;
    } elseif (!empty($user['ban_expires']) && strtotime((string) $user['ban_expires']) > time()) {
        $blocked = true;
    }

    if ($blocked) {
        // Aucun compte valide -> pas de ticket. Le jeu basculera sur le login manuel.
        echo json_encode(['ticket' => null]);
        exit;
    }

    // 5. Ticket a usage unique, valable 60 s.
    $ticket    = bin2hex(random_bytes(32)); // 64 caracteres hex
    $expiresAt = date('Y-m-d H:i:s', time() + 60);

    // Menage : purge des tickets de cet utilisateur + des tickets expires.
    $db->prepare('DELETE FROM fivem_tickets WHERE user_id = ? OR expires_at < NOW()')
       ->execute([(int) $user['id']]);

    $db->prepare(
        'INSERT INTO fivem_tickets (ticket, user_id, expires_at, used) VALUES (?, ?, ?, 0)'
    )->execute([$ticket, (int) $user['id'], $expiresAt]);

    echo json_encode(['ticket' => $ticket]);
    exit;

} catch (Throwable $e) {
    error_log('[fivem-ticket] ' . $e->getMessage());
    fivem_ticket_fail(500, 'server_error');
}
