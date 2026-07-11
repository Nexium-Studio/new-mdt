<?php
/**
 * API Dashboard Builder — « Version Joueurs » (lecture seule).
 * Calcule ce qu'un JOUEUR (citoyen) d'une faction donnée verrait sur le
 * dashboard, SANS jamais modifier $_SESSION. Réplique la logique d'accès
 * de pages/dashboard.php (hasAccess) pour une faction simulée.
 *
 *   GET ?action=factions          → liste des factions (sélecteur)
 *   GET ?action=render&faction=KEY → catégories/panels visibles (citoyen+faction)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/dashboard/permissions.php';
require_once __DIR__ . '/../../config/mdt_panel_catalog.php';
require_once __DIR__ . '/../../config/panel_access.php'; // DARKNET_ALLOWED_FACTION_KEYS

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if (!dashboard_can_preview()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'render';

if ($action === 'factions') {
    $factions = [];
    try {
        foreach ($db->query("SELECT faction_key, name, color, icon FROM factions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['faction_key'] ?? '') === 'STAFF') {
                continue;
            }
            $factions[] = [
                'value' => $r['faction_key'],
                'label' => $r['name'] ?: $r['faction_key'],
                'color' => $r['color'] ?: '#64748b',
                'icon'  => $r['icon'] ?: 'shield',
            ];
        }
    } catch (Exception $e) {
    }
    echo json_encode(['success' => true, 'factions' => $factions]);
    exit;
}

// ── action=render ──
$faction = trim((string) ($_GET['faction'] ?? ''));

// Overrides d'affichage + activation.
$cfg = [];
try {
    foreach ($db->query("SELECT panel_key, custom_name, custom_color, custom_logo_url FROM admin_panel_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cfg[$r['panel_key']] = $r;
    }
} catch (Exception $e) {
}
$active = [];
$hasActive = false;
try {
    foreach ($db->query("SELECT panel_key, is_active FROM admin_panels")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $active[$r['panel_key']] = (bool) (int) $r['is_active'];
    }
    $hasActive = true;
} catch (Exception $e) {
}

$categories = mdt_panel_catalog_categories($db, false); // les joueurs ne voient pas les catégories masquées

// Factions « services publics » (gate Académie Centralisée). Tolère le renommage
// de la catégorie : on retrouve son label via la category_key stable.
$publicServices = [];
try {
    $st = $db->prepare("SELECT label FROM dashboard_categories WHERE category_key = 'Services Publics' LIMIT 1");
    $st->execute();
    $spLabel = (string) ($st->fetchColumn() ?: 'Services Publics');
} catch (Exception $e) {
    $spLabel = 'Services Publics';
}
if (isset($categories[$spLabel]) && is_array($categories[$spLabel])) {
    foreach ($categories[$spLabel] as $sp) {
        $fk = $sp['faction'] ?? '';
        if ($fk !== '' && $fk !== 'STAFF') {
            $publicServices[] = $fk;
        }
    }
}

function preview_is_active(string $key, bool $alwaysActive, array $active, bool $hasActive): bool
{
    if ($alwaysActive) {
        return true;
    }
    if (!$hasActive) {
        return true;
    }
    return array_key_exists($key, $active) ? $active[$key] : true;
}

function preview_can_see(array $panel, string $faction, array $publicServices): bool
{
    $key = (string) ($panel['key'] ?? '');
    if ($key === 'DARKNET') {
        return in_array($faction, DARKNET_ALLOWED_FACTION_KEYS, true);
    }
    if (!empty($panel['mail_panel'])) {
        return true;
    }
    if (!empty($panel['academy_public'])) {
        return true; // tout citoyen connecté
    }
    if (!empty($panel['academy_central'])) {
        return $faction !== '' && in_array($faction, $publicServices, true);
    }
    if (!empty($panel['faction']) && (string) $panel['faction'] === $faction && $faction !== '') {
        return true;
    }
    // Les "roles" sont des rôles staff : un citoyen ne les possède pas → non visible.
    return false;
}

$out = [];
foreach ($categories as $label => $panels) {
    if (!is_array($panels)) {
        continue;
    }
    $visible = [];
    foreach ($panels as $p) {
        $key = (string) ($p['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $isActive = preview_is_active($key, !empty($p['always_active']), $active, $hasActive);
        if (!$isActive) {
            continue;
        }
        if (!preview_can_see($p, $faction, $publicServices)) {
            continue;
        }
        $logo = (string) ($cfg[$key]['custom_logo_url'] ?? '');
        if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
            $logo = '';
        }
        $visible[] = [
            'key'         => $key,
            'name'        => (string) ($cfg[$key]['custom_name'] ?? $p['name'] ?? $key),
            'icon'        => (string) ($p['icon'] ?? 'cube'),
            'color'       => (string) ($cfg[$key]['custom_color'] ?? $p['color'] ?? '#64748b'),
            'logo'        => $logo,
            'faction'     => (string) ($p['faction'] ?? ''),
            'description' => (string) ($p['description'] ?? ''),
        ];
    }
    if (!empty($visible)) {
        $out[] = ['label' => (string) $label, 'panels' => $visible];
    }
}

echo json_encode(['success' => true, 'faction' => $faction, 'categories' => $out]);
