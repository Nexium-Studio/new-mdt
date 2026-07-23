<?php
/**
 * POST JSON — Prendre son service LSSD.
 * Payload: { csrf, vehicle, zone, notes }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/service_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_service_access_ok() || !lssd_service_can_start()) {
    lssd_service_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_service_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!lssd_service_verify_csrf($data['csrf'] ?? null)) {
    lssd_service_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    lssd_service_json_out(401, ['ok' => false, 'error' => 'Non authentifié']);
}

$vehicle = trim((string) ($data['vehicle'] ?? ''));
$zone    = trim((string) ($data['zone'] ?? ''));
$notes   = trim((string) ($data['notes'] ?? ''));

if ($vehicle === '') {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Véhicule de patrouille requis']);
}
if (mb_strlen($vehicle) > 100) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Véhicule trop long']);
}
if (!in_array($zone, lssd_service_zones(), true)) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Zone de patrouille invalide']);
}
if (mb_strlen($notes) > 1000) {
    lssd_service_json_out(400, ['ok' => false, 'error' => 'Notes trop longues (1000 max)']);
}

$db = Database::getInstance()->getConnection();
lssd_service_ensure_schema($db);

try {
    // Empêche double shift actif.
    $chk = $db->prepare("
        SELECT id FROM company_shifts
        WHERE user_id = ? AND faction = 'LSSD' AND shift_end IS NULL
        LIMIT 1
    ");
    $chk->execute([$uid]);
    if ($chk->fetch()) {
        lssd_service_json_out(409, ['ok' => false, 'error' => 'Vous êtes déjà en service']);
    }

    $ins = $db->prepare('
        INSERT INTO company_shifts
            (user_id, faction, shift_start, patrol_vehicle, patrol_zone, partner_id, notes)
        VALUES (?, ?, NOW(), ?, ?, NULL, ?)
    ');
    $ins->execute([$uid, 'LSSD', $vehicle, $zone, $notes]);

    $shiftId = (int) $db->lastInsertId();

    lssd_service_json_out(200, ['ok' => true, 'shift_id' => $shiftId]);
} catch (Throwable $e) {
    error_log('service_start: ' . $e->getMessage());
    lssd_service_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
