<?php
/**
 * Endpoint public de rappel — le bot NexioZ y pousse les changements de statut
 * des propositions. Aucune session : l'authentification se fait par le SECRET
 * partagé (composant de la clé de liaison), comparé en temps constant.
 *
 * Corps JSON attendu : { secret, proposalId, status }
 */

define('SKIP_LICENSE_GUARD', true); // endpoint machine-to-machine : pas de page licence
require_once __DIR__ . '/../../../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Corps JSON (repli sur POST classique).
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$secret     = (string) ($body['secret'] ?? '');
$proposalId = (int) ($body['proposalId'] ?? 0);
$status     = (string) ($body['status'] ?? '');

require_once ROOT_PATH . '/includes/mdt_proposals.php';
$db = Database::getInstance()->getConnection();

// Authentification par secret.
$parsed = mdt_proposals_parse_token(mdt_proposals_liaison_token($db));
if ($parsed === null || $secret === '' || !hash_equals($parsed['secret'], $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if ($proposalId <= 0 || !mdt_proposals_status_valid($status)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_body']);
    exit;
}

$ok = mdt_proposals_set_status($db, $proposalId, $status);
if (!$ok) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

echo json_encode(['ok' => true]);
