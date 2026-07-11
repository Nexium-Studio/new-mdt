<?php
require_once __DIR__ . '/../../../../config/config.php';
header('Content-Type: application/json');

requireLogin();

$vehicle_id = intval($_GET['vehicle_id'] ?? 0);

if ($vehicle_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID véhicule invalide']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Récupérer les mods du véhicule
$stmt = $db->prepare("
    SELECT mod_id 
    FROM garage_vehicle_customizations 
    WHERE vehicle_id = ?
");
$stmt->execute([$vehicle_id]);
$mods = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'mods' => $mods
]);