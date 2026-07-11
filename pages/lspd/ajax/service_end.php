<?php
/**
 * POST JSON — Terminer son service LSPD.
 * Payload: { csrf, notes }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/service_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lspd_service_access_ok()) {
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

$notesAppend = trim((string) ($data['notes'] ?? ''));
if (mb_strlen($notesAppend) > 1000) {
    lspd_service_json_out(400, ['ok' => false, 'error' => 'Notes trop longues (1000 max)']);
}

$db = Database::getInstance()->getConnection();
lspd_service_ensure_schema($db);

try {
    $cs = $db->prepare("
        SELECT id, shift_start, notes FROM company_shifts
        WHERE user_id = ? AND faction = 'LSPD' AND shift_end IS NULL
        ORDER BY shift_start DESC
        LIMIT 1
    ");
    $cs->execute([$uid]);
    $shift = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        lspd_service_json_out(404, ['ok' => false, 'error' => "Vous n'êtes pas en service"]);
    }

    $shiftId = (int) $shift['id'];

    // ⚠️ Durée calculée ENTIÈREMENT côté MySQL pour éviter tout décalage
    // de fuseau horaire entre PHP et le serveur SQL (TIMESTAMPDIFF utilise
    // les deux valeurs dans le même référentiel, donc zéro drift possible).
    $durStmt = $db->prepare('
        SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, shift_start, NOW())) AS elapsed_sec
        FROM company_shifts
        WHERE id = ?
    ');
    $durStmt->execute([$shiftId]);
    $elapsedSec = (int) ($durStmt->fetchColumn() ?: 0);
    $dur = (int) round($elapsedSec / 60);

    $mergedNotes = (string) ($shift['notes'] ?? '');
    if ($notesAppend !== '') {
        $sep = $mergedNotes !== '' ? "\n\n— Fin de service —\n" : '';
        $mergedNotes = trim($mergedNotes . $sep . $notesAppend);
    }

    $db->beginTransaction();

    $upd = $db->prepare('
        UPDATE company_shifts
        SET shift_end = NOW(), duration_minutes = ?, notes = ?
        WHERE id = ?
    ');
    $upd->execute([$dur, $mergedNotes, $shiftId]);

    // Ferme tous les binômes actifs de ce shift.
    $db->prepare('
        UPDATE lspd_service_partners
        SET removed_at = NOW()
        WHERE shift_id = ? AND removed_at IS NULL
    ')->execute([$shiftId]);

    // Ferme également les liens actifs où CET utilisateur était binôme
    // (car il n'est plus disponible en patrouille).
    $db->prepare('
        UPDATE lspd_service_partners
        SET removed_at = NOW()
        WHERE partner_user_id = ? AND removed_at IS NULL
    ')->execute([$uid]);

    $db->commit();

    lspd_service_json_out(200, [
        'ok' => true,
        'shift_id' => $shiftId,
        'duration_minutes' => $dur,
        'duration_seconds' => $elapsedSec,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('service_end: ' . $e->getMessage());
    lspd_service_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}
