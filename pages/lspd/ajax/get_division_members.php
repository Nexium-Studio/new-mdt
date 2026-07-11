<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare("
        SELECT ldm.*, u.username, u.faction_rank
        FROM lspd_division_members ldm
        JOIN users u ON ldm.user_id = u.id
        WHERE ldm.division_id = ?
        ORDER BY ldm.joined_date DESC
    ");
    $stmt->execute([intval($_GET['id'])]);
    $members = $stmt->fetchAll();
    
    foreach ($members as &$member) {
        $member['joined_date'] = date('d/m/Y', strtotime($member['joined_date']));
    }
    
    echo json_encode(['success' => true, 'members' => $members]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}