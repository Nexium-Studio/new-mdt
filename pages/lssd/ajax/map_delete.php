<?php
/**
 * map_delete.php — Suppression d'un plan tactique.
 * Supprime la map et tous ses tracés/présences (cascade FK).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../includes/map_api.php';

header('Content-Type: application/json; charset=utf-8');

if (!lssd_map_panel_access_ok()) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Accès refusé']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lssd_map_json_out(405, ['ok' => false, 'error' => 'Méthode non autorisée']);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'JSON invalide']);
}

if (!lssd_map_verify_csrf($data['csrf'] ?? null)) {
    lssd_map_json_out(403, ['ok' => false, 'error' => 'Session expirée, rechargez la page']);
}

$mapId = (int)($data['map_id'] ?? 0);
if ($mapId <= 0) {
    lssd_map_json_out(400, ['ok' => false, 'error' => 'Identifiant de plan invalide']);
}

$db = Database::getInstance()->getConnection();

try {
    /* Vérifier existence */
    $chk = $db->prepare('SELECT id, title FROM lssd_maps WHERE id = ? LIMIT 1');
    $chk->execute([$mapId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        lssd_map_json_out(404, ['ok' => false, 'error' => 'Plan introuvable']);
    }

    /*
     * Supprimer le plan.
     * La table lssd_map_presence a une FK ON DELETE CASCADE sur map_id,
     * donc les présences associées sont supprimées automatiquement.
     */
    $del = $db->prepare('DELETE FROM lssd_maps WHERE id = ?');
    $del->execute([$mapId]);

    /*
     * Nettoyage explicite au cas où la contrainte FK ne serait pas active
     * (si la migration a été appliquée partiellement).
     */
    try {
        $db->prepare('DELETE FROM lssd_map_presence WHERE map_id = ?')->execute([$mapId]);
    } catch (Throwable $e) {
        /* table peut être absente, on ignore */
    }

    lssd_map_json_out(200, [
        'ok'      => true,
        'deleted' => $mapId,
        'title'   => (string)$row['title'],
    ]);

} catch (Throwable $e) {
    error_log('map_delete: ' . $e->getMessage());
    lssd_map_json_out(500, ['ok' => false, 'error' => 'Erreur serveur']);
}