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
        SELECT u.*, 
               lr.base_salary,
               GROUP_CONCAT(DISTINCT ld.abbreviation SEPARATOR ', ') as divisions,
               COUNT(DISTINCT ls.id) as sanctions_count,
               COUNT(DISTINCT le.id) as equipment_count
        FROM users u
        LEFT JOIN lspd_ranks lr ON u.faction_rank = lr.rank_name
        LEFT JOIN lspd_division_members ldm ON u.id = ldm.user_id
        LEFT JOIN lspd_divisions ld ON ldm.division_id = ld.id
        LEFT JOIN lspd_sanctions ls ON u.id = ls.officer_id AND ls.status = 'active'
        LEFT JOIN lspd_equipment le ON u.id = le.officer_id AND le.status = 'assigned'
        WHERE u.id = ? AND u.faction = 'LSPD'
        GROUP BY u.id
    ");
    $stmt->execute([intval($_GET['id'])]);
    $employee = $stmt->fetch();
    
    if ($employee) {
        $employee['created_at'] = date('d/m/Y', strtotime($employee['created_at']));
        echo json_encode(['success' => true, 'employee' => $employee]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Employé introuvable']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}