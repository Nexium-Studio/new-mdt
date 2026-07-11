<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID manquant']);
    exit;
}

try {
    // Profil
    $stmt = $db->prepare("SELECT id, firstname, lastname, phone, date_of_birth FROM profiles WHERE id = ?");
    $stmt->execute([$id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$profile) {
        echo json_encode(['success' => false, 'error' => 'Citoyen introuvable']);
        exit;
    }
    
    // Casiers
    $stmt = $db->prepare("
        SELECT cr.case_number, cr.record_type, cr.offense_code, cr.offense_title, cr.severity, 
               cr.fine_amount, cr.jail_time, cr.points, cr.incident_date, cr.status
        FROM criminal_records cr
        WHERE cr.citizen_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mandats actifs
    $stmt = $db->prepare("
        SELECT aw.warrant_type, aw.offense_code, aw.offense_title, aw.severity, 
               aw.bail_amount, aw.issued_date, aw.status
        FROM arrest_warrants aw
        WHERE aw.citizen_id = ? AND aw.status = 'active'
        ORDER BY aw.issued_date DESC
    ");
    $stmt->execute([$id]);
    $warrants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procès
    $stmt = $db->prepare("
        SELECT cc.case_number, cc.case_title, cc.case_type, cc.verdict, cc.status, cc.hearing_date
        FROM court_cases cc
        WHERE cc.defendant_id = ?
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([$id]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'profile' => $profile,
        'records' => $records,
        'warrants' => $warrants,
        'cases' => $cases
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erreur base de données']);
}