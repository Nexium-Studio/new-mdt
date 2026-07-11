<?php
/**
 * POST JSON — Prendre son service LSPD.
 * Payload: { csrf, vehicle, zone, notes }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/service_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lspd_service_access_ok() || !lspd_service_can_start()) {
    lspd_service_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lspd_service_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!lspd_service_verify_csrf($data['csrf'] ?? null)) {
    lspd_service_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lspd_service_json_out(401, ['ok' => false, 'error' => 'Non authentifié']);
}

$vehicle = trim((string) ($data['vehicle'] ?? ''));
$zone    = trim((string) ($data['zone'] ?? ''));
$notes   = trim((string) ($data['notes'] ?? ''));

if ($vehicle === '') {
    lspd_service_json_out(400, ['ok' => false, 'error' => 'Véhicule de patrouille requis']);
}
if (mb_strlen($vehicle) > 100) {
    lspd_service_json_out(400, ['ok' => false, 'error' => 'Véhicule trop long']);
}
if (!in_array($zone, lspd_service_zones(), true)) {
    lspd_service_json_out(400, ['ok' => false, 'error' => 'Zone de patrouille invalide']);
}
if (mb_strlen($notes) > 1000) {
    lspd_service_json_out(400, ['ok' => false, 'error' => 'Notes trop longues (1000 max)']);
}

$db = Database::getInstance()->getConnection();
lspd_service_ensure_schema($db);

try {
    // Empêche double shift actif.
    $chk = $db->prepare("
        SELECT id FROM company_shifts
        WHERE user_id = ? AND faction = 'LSPD' AND shift_end IS NULL
        LIMIT 1
    ");
    $chk->execute([$uid]);
    if ($chk->fetch()) {
        lspd_service_json_out(409, ['ok' => false, 'error' => 'Vous êtes déjà en service']);
    }

    $ins = $db->prepare('
        INSERT INTO company_shifts
            (user_id, faction, shift_start, patrol_vehicle, patrol_zone, partner_id, notes)
        VALUES (?, ?, NOW(), ?, ?, NULL, ?)
    ');
    $ins->execute([$uid, 'LSPD', $vehicle, $zone, $notes]);

    $shiftId = (int) $db->lastInsertId();

    lspd_service_json_out(200, ['ok' => true, 'shift_id' => $shiftId]);
} catch (Throwable $e) {
    error_log('service_start: ' . $e->getMessage());
    lspd_service_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
