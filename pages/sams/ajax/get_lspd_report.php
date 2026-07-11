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
    // Récupérer le rapport
    $stmt = $db->prepare("
        SELECT lr.*,
               u.username as officer_name
        FROM lspd_reports lr
        LEFT JOIN users u ON lr.officer_in_charge = u.id
        WHERE lr.id = ?
    ");
    $stmt->execute([intval($_GET['id'])]);
    $report = $stmt->fetch();
    
    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Rapport introuvable']);
        exit;
    }
    
    // Formater la date
    $report['incident_date'] = date('d/m/Y à H:i', strtotime($report['incident_date']));
    
    // Récupérer les suspects
    $stmt = $db->prepare("
        SELECT CONCAT(p.firstname, ' ', p.lastname) as name
        FROM lspd_report_suspects lrs
        JOIN profiles p ON lrs.profile_id = p.id
        WHERE lrs.report_id = ?
    ");
    $stmt->execute([$report['id']]);
    $report['suspects'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Récupérer les victimes
    $stmt = $db->prepare("
        SELECT CONCAT(p.firstname, ' ', p.lastname) as name
        FROM lspd_report_victims lrv
        JOIN profiles p ON lrv.profile_id = p.id
        WHERE lrv.report_id = ?
    ");
    $stmt->execute([$report['id']]);
    $report['victims'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Récupérer les témoins
    $stmt = $db->prepare("
        SELECT CONCAT(p.firstname, ' ', p.lastname) as name
        FROM lspd_report_witnesses lrw
        JOIN profiles p ON lrw.profile_id = p.id
        WHERE lrw.report_id = ?
    ");
    $stmt->execute([$report['id']]);
    $report['witnesses'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Récupérer les infractions
    $stmt = $db->prepare("
        SELECT lo.title, lo.category, lo.fine_max, lo.jail_time_max
        FROM lspd_report_offenses lro
        JOIN lspd_offenses lo ON lro.offense_id = lo.id
        WHERE lro.report_id = ?
    ");
    $stmt->execute([$report['id']]);
    $report['offenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'report' => $report]);
    
} catch (Exception $e) {
    error_log("Erreur get_lspd_report: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}