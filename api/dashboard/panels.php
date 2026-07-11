<?php
/**
 * API Dashboard Builder — gestion des panels.
 *
 *   GET  ?action=list    → tous les panels (catalogue fusionné) + état/overrides
 *   GET  ?action=meta    → catégories + factions (pour les sélecteurs custom)
 *   POST ?action=create  → crée un panel custom (custom_panels + pages entreprise)
 *   POST ?action=update  → édite name/color/logo/description/category (+ custom: icon/faction)
 *   POST ?action=delete  → supprime un panel custom
 *   POST ?action=toggle  → active/désactive (admin_panels)
 *   POST ?action=reorder → réordonne les panels d'une catégorie (phase 5)
 *
 * Écritures réservées Dev/Admin (dashboard_require_build_json()).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/dashboard/permissions.php';
require_once __DIR__ . '/../../includes/dashboard/overlay.php';
require_once __DIR__ . '/../../config/mdt_panel_catalog.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'list';

function dbp_body(): array
{
    $in = json_decode((string) file_get_contents('php://input'), true);
    return is_array($in) ? $in : $_POST;
}

/** Valeurs autorisées par l'ENUM custom_panels.category (placement réel via overlay). */
function dbp_enum_category(): string { return 'Commerces'; }

/** Pages du template "entreprise" (mirroir de panel-builder.php). */
function dbp_entreprise_pages(): array
{
    return [
        'dashboard'      => ['label' => 'Dashboard',        'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
        'effectifs'      => ['label' => 'Effectifs',        'icon' => 'fas fa-users',         'can_disable' => true,  'is_admin' => false],
        'prises-service' => ['label' => 'Prise de service', 'icon' => 'fas fa-clock',         'can_disable' => true,  'is_admin' => false],
        'sanctions'      => ['label' => 'Sanctions',        'icon' => 'fas fa-gavel',         'can_disable' => true,  'is_admin' => false],
        'stock'          => ['label' => 'Stock',            'icon' => 'fas fa-boxes',         'can_disable' => true,  'is_admin' => false],
        'vente'          => ['label' => 'Ventes',           'icon' => 'fas fa-cash-register', 'can_disable' => true,  'is_admin' => false],
        'facturation'    => ['label' => 'Facturation',      'icon' => 'fas fa-receipt',       'can_disable' => true,  'is_admin' => false],
        'depenses'       => ['label' => 'Dépenses',         'icon' => 'fas fa-money-bill',    'can_disable' => true,  'is_admin' => false],
    ];
}

// =====================================================================
if ($action === 'meta') {
    $cats = [];
    try {
        $rows = $db->query("SELECT category_key, label FROM dashboard_categories WHERE is_hidden = 0 ORDER BY sort_order ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cats[] = ['value' => $r['category_key'], 'label' => $r['label']];
        }
    } catch (Exception $e) {
    }
    $factions = [];
    try {
        $rows = $db->query("SELECT faction_key, name, color, icon FROM factions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $factions[] = ['value' => $r['faction_key'], 'label' => $r['name'], 'color' => $r['color'] ?: '#64748b', 'icon' => $r['icon'] ?: 'shield'];
        }
    } catch (Exception $e) {
    }
    echo json_encode(['success' => true, 'categories' => $cats, 'factions' => $factions]);
    exit;
}

if ($action === 'list') {
    // Overrides + état d'activation.
    $cfg = [];
    try {
        foreach ($db->query("SELECT panel_key, custom_name, custom_color, custom_logo_url FROM admin_panel_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cfg[$r['panel_key']] = $r;
        }
    } catch (Exception $e) {
    }
    $active = [];
    $hasActiveTable = false;
    try {
        foreach ($db->query("SELECT panel_key, is_active FROM admin_panels")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $active[$r['panel_key']] = (bool) (int) $r['is_active'];
        }
        $hasActiveTable = true;
    } catch (Exception $e) {
    }
    $customKeys = [];
    try {
        foreach ($db->query("SELECT panel_key FROM custom_panels")->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $customKeys[$k] = true;
        }
    } catch (Exception $e) {
    }

    $merged = mdt_panel_catalog_categories($db, true);
    $out = [];
    foreach ($merged as $label => $panels) {
        if (!is_array($panels)) {
            continue;
        }
        foreach ($panels as $p) {
            $key = (string) ($p['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $isActive = $hasActiveTable
                ? (array_key_exists($key, $active) ? $active[$key] : true)
                : true;
            if (!empty($p['always_active'])) {
                $isActive = true;
            }
            $out[] = [
                'key'          => $key,
                'name'         => (string) ($cfg[$key]['custom_name'] ?? $p['name'] ?? $key),
                'description'  => (string) ($p['description'] ?? ''),
                'icon'         => (string) ($p['icon'] ?? 'cube'),
                'color'        => (string) ($cfg[$key]['custom_color'] ?? $p['color'] ?? '#64748b'),
                'logo'         => (string) ($cfg[$key]['custom_logo_url'] ?? ''),
                'category'     => (string) $label,
                'faction'      => (string) ($p['faction'] ?? ''),
                'is_active'    => $isActive,
                'is_custom'    => isset($customKeys[$key]),
                'is_division'  => !empty($p['division_panel']),
                'always_active'=> !empty($p['always_active']),
            ];
        }
    }
    echo json_encode(['success' => true, 'panels' => $out]);
    exit;
}

// ── Écritures ──
dashboard_require_build_json();
$in = dbp_body();

if ($action === 'toggle') {
    $key = trim((string) ($in['panel_key'] ?? ''));
    $val = (int) (!empty($in['is_active']));
    if ($key === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'key_required']); exit; }
    try {
        $db->prepare("INSERT INTO admin_panels (panel_key, is_active, updated_at) VALUES (?,?,NOW())
                      ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = NOW()")->execute([$key, $val]);
        // Sync custom_panels / faction_divisions si présents (comme l'ancienne page).
        $db->prepare("UPDATE custom_panels SET is_active = ? WHERE panel_key = ?")->execute([$val, $key]);
        $db->prepare("UPDATE faction_divisions SET is_active = ? WHERE panel_key = ?")->execute([$val, $key]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'db_error']); exit;
    }
    echo json_encode(['success' => true, 'is_active' => (bool) $val]);
    exit;
}

if ($action === 'create') {
    $name    = trim((string) ($in['name'] ?? ''));
    if ($name === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'name_required']); exit; }
    $desc    = trim((string) ($in['description'] ?? ''));
    $catKey  = trim((string) ($in['category'] ?? ''));
    $faction = trim((string) ($in['faction'] ?? '')) ?: null;
    $color   = mdt_validate_hex_color((string) ($in['color'] ?? ''), '#3B82F6');
    $iconMode = ($in['icon_mode'] ?? 'fa') === 'url' ? 'url' : 'fa';
    $iconVal  = trim((string) ($in['icon'] ?? ''));
    $faIcon   = ($iconMode === 'fa' && $iconVal !== '') ? preg_replace('/[^a-z0-9\-]/', '', strtolower($iconVal)) : 'store';
    $logoUrl  = ($iconMode === 'url' && preg_match('#^https?://#i', $iconVal)) ? $iconVal : null;

    // Génère une clé unique (style panel-builder).
    $base = strtoupper(preg_replace('/[^A-Z0-9]/', '_', strtoupper($name)));
    $base = preg_replace('/_+/', '_', trim((string) $base, '_'));
    $panelKey = substr((string) $base, 0, 30) . '_' . substr(md5(uniqid('', true)), 0, 4);

    try {
        $db->beginTransaction();

        $db->prepare("INSERT INTO custom_panels
            (panel_key, name, subtitle, category, faction_key, color, style, icon, base_template, is_active, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,1,?)")
           ->execute([$panelKey, $name, $desc, dbp_enum_category(), $faction, $color, 'neon', $faIcon, 'entreprise', $_SESSION['user_id'] ?? null]);

        // Pages du template entreprise.
        $sort = 0;
        $pages = dbp_entreprise_pages();
        $stPage = $db->prepare("INSERT INTO custom_panel_pages
            (panel_key, page_key, label, icon, source_template, section, can_disable, is_admin, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($pages as $pageKey => $pg) {
            $stPage->execute([$panelKey, $pageKey, $pg['label'], $pg['icon'], 'entreprise', $pg['section'] ?? null,
                (int) ($pg['can_disable'] ?? 1), (int) ($pg['is_admin'] ?? 0), $sort++]);
        }

        $db->prepare("INSERT INTO admin_panels (panel_key, is_active) VALUES (?,1)
                      ON DUPLICATE KEY UPDATE is_active = 1")->execute([$panelKey]);

        $pagesJson = json_encode(array_fill_keys(array_keys($pages), true));
        $db->prepare("INSERT INTO admin_panel_config (panel_key, custom_name, custom_subtitle, custom_color, custom_style, active_pages, custom_logo_url)
                      VALUES (?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE custom_name = VALUES(custom_name), custom_color = VALUES(custom_color), active_pages = VALUES(active_pages), custom_logo_url = VALUES(custom_logo_url)")
           ->execute([$panelKey, $name, $desc, $color, 'neon', $pagesJson, $logoUrl]);

        // Placement réel + description via overlay.
        dashboard_overlay_set_panel($db, $panelKey, ($catKey !== '' ? $catKey : null), $desc, 9998);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]); exit;
    }

    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Panel créé', $name . ' (' . $panelKey . ')', 'settings', null);
    }
    echo json_encode(['success' => true, 'panel_key' => $panelKey]);
    exit;
}

if ($action === 'update') {
    $key = trim((string) ($in['panel_key'] ?? ''));
    if ($key === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'key_required']); exit; }
    $isCustom = false;
    try {
        $st = $db->prepare("SELECT 1 FROM custom_panels WHERE panel_key = ?");
        $st->execute([$key]);
        $isCustom = (bool) $st->fetchColumn();
    } catch (Exception $e) {
    }

    $name  = isset($in['name']) ? trim((string) $in['name']) : null;
    $desc  = isset($in['description']) ? trim((string) $in['description']) : null;
    $color = isset($in['color']) ? mdt_validate_hex_color((string) $in['color'], '#64748B') : null;
    $catKey = isset($in['category']) ? trim((string) $in['category']) : null;
    $iconMode = ($in['icon_mode'] ?? '') === 'url' ? 'url' : (($in['icon_mode'] ?? '') === 'fa' ? 'fa' : '');
    $iconVal  = isset($in['icon']) ? trim((string) $in['icon']) : null;
    $faction  = isset($in['faction']) ? (trim((string) $in['faction']) ?: null) : false; // false = ne pas toucher

    try {
        $db->beginTransaction();

        // 1) admin_panel_config : name / color / logo (s'appliquent à TOUS les panels)
        $logoUrl = null; $setLogo = false;
        if ($iconMode === 'url' && $iconVal !== null && preg_match('#^https?://#i', $iconVal)) { $logoUrl = $iconVal; $setLogo = true; }
        elseif ($iconMode === 'url' && $iconVal === '') { $logoUrl = null; $setLogo = true; }

        if ($name !== null || $color !== null || $setLogo) {
            // Upsert ciblé colonne par colonne pour ne pas écraser l'existant.
            $db->prepare("INSERT IGNORE INTO admin_panel_config (panel_key) VALUES (?)")->execute([$key]);
            if ($name !== null)  { $db->prepare("UPDATE admin_panel_config SET custom_name = ? WHERE panel_key = ?")->execute([$name, $key]); }
            if ($color !== null) { $db->prepare("UPDATE admin_panel_config SET custom_color = ? WHERE panel_key = ?")->execute([$color, $key]); }
            if ($setLogo)        { $db->prepare("UPDATE admin_panel_config SET custom_logo_url = ? WHERE panel_key = ?")->execute([$logoUrl, $key]); }
        }

        // 2) custom_panels (uniquement panels custom) : name / color / faction / icon FA
        if ($isCustom) {
            if ($name !== null)   { $db->prepare("UPDATE custom_panels SET name = ? WHERE panel_key = ?")->execute([$name, $key]); }
            if ($color !== null)  { $db->prepare("UPDATE custom_panels SET color = ? WHERE panel_key = ?")->execute([$color, $key]); }
            if ($faction !== false) { $db->prepare("UPDATE custom_panels SET faction_key = ? WHERE panel_key = ?")->execute([$faction, $key]); }
            if ($iconMode === 'fa' && $iconVal !== null && $iconVal !== '') {
                $fa = preg_replace('/[^a-z0-9\-]/', '', strtolower($iconVal));
                $db->prepare("UPDATE custom_panels SET icon = ? WHERE panel_key = ?")->execute([$fa, $key]);
            }
        }

        // 3) overlay : description + catégorie (null = non modifié)
        if ($desc !== null || $catKey !== null) {
            dashboard_overlay_set_panel($db, $key, $catKey, $desc, null);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'db_error']); exit;
    }

    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Panel modifié', $key, 'settings', null);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete') {
    $key = trim((string) ($in['panel_key'] ?? ''));
    if ($key === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'key_required']); exit; }
    // Sécurité : on ne supprime QUE des panels custom (les panels catalogue ne se désactivent que via toggle).
    try {
        $st = $db->prepare("SELECT 1 FROM custom_panels WHERE panel_key = ?");
        $st->execute([$key]);
        if (!$st->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'not_custom']);
            exit;
        }
        $db->beginTransaction();
        $db->prepare("DELETE FROM custom_panels WHERE panel_key = ?")->execute([$key]);
        try { $db->prepare("DELETE FROM custom_panel_pages WHERE panel_key = ?")->execute([$key]); } catch (Exception $e) {}
        $db->prepare("DELETE FROM admin_panels WHERE panel_key = ?")->execute([$key]);
        $db->prepare("DELETE FROM admin_panel_config WHERE panel_key = ?")->execute([$key]);
        $db->prepare("DELETE FROM dashboard_panel_overlay WHERE panel_key = ?")->execute([$key]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'db_error']); exit;
    }
    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Panel supprimé', $key, 'settings', null);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'reorder') {
    $catKey = trim((string) ($in['category_key'] ?? ''));
    $order  = is_array($in['order'] ?? null) ? $in['order'] : [];
    try {
        dashboard_overlay_reorder_panels($db, $catKey, array_map('strval', $order));
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'db_error']); exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
