<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'Harmony Repair';
$companyColor = '#64748b';
$companyFaction = 'HARMONY_REPAIR';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Vente';
$db = Database::getInstance()->getConnection();
require_once __DIR__ . '/../../../includes/garage_vente_lib.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sell_workshop') {
    $r = garage_vente_process_workshop_sale($db, [
        'faction'          => $companyFaction,
        'employee_id'      => (int) ($_SESSION['user_id'] ?? 0),
        'use_bennys_sales' => false,
        'post'             => $_POST,
    ]);
    $message = $r['message'];
    $messageType = $r['type'];
}

$gvModsGrouped = garage_vente_load_mods_grouped($db);
$gvCategories = garage_vente_vehicle_categories();
$gvAccent = $companyColor;
$gvTitle = 'Configuration véhicule & personnalisation';

$stmt = $db->prepare('SELECT * FROM company_stock WHERE faction = ? AND stock_quantity > 0 ORDER BY item_category, item_name');
$stmt->execute([$companyFaction]);
$stockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = [];
foreach ($stockItems as $item) {
    $cat = $item['item_category'] ?: 'Autre';
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $categories[$cat][] = $item;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/../../../includes/garage_vente_unified_markup.php';
include __DIR__ . '/includes/footer.php';
