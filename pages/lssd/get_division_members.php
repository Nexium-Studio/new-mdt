<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');
ob_clean();
$db = Database::getInstance()->getConnection();
try {
    if (isset($_GET['user_id'])) {
        // Get divisions for a user
        $stmt=$db->prepare("SELECT d.id,d.name,d.abbreviation,d.color,ldm.role,ldm.joined_date FROM lssd_division_members ldm JOIN lssd_divisions d ON d.id=ldm.division_id WHERE ldm.user_id=? ORDER BY d.name");
        $stmt->execute([intval($_GET['user_id'])]);
        echo json_encode(['success'=>true,'divisions'=>$stmt->fetchAll()]);
    } elseif (isset($_GET['id'])) {
        // Get members of a division
        $stmt=$db->prepare("SELECT ldm.*,u.username,u.faction_rank FROM lssd_division_members ldm JOIN users u ON u.id=ldm.user_id WHERE ldm.division_id=? ORDER BY ldm.joined_date DESC");
        $stmt->execute([intval($_GET['id'])]);
        $members=$stmt->fetchAll();
        foreach($members as &$m) $m['joined_date']=date('d/m/Y',strtotime($m['joined_date']));
        echo json_encode(['success'=>true,'members'=>$members]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Paramètre manquant']);
    }
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}