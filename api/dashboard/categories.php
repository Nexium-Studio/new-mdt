<?php
/**
 * API Dashboard Builder — gestion des catégories (overlay non-destructif).
 *
 *   GET  ?action=list                         → liste + nb de panels par catégorie
 *   POST ?action=create   {label,description,color,after}
 *   POST ?action=update   {category_key,label,description,color,after}
 *   POST ?action=delete   {category_key}      → custom = supprimée ; built-in = masquée
 *   POST ?action=toggle_hidden {category_key}
 *   POST ?action=reorder  {order:[category_key,...]}
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

function db_json_body(): array
{
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    return is_array($in) ? $in : $_POST;
}

function db_slugify(string $label): string
{
    $s = strtolower(trim($label));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    if ($s === '') {
        $s = 'cat';
    }
    return 'cat-' . substr($s, 0, 60);
}

function db_unique_slug(PDO $db, string $base): string
{
    $slug = $base;
    $i = 2;
    $st = $db->prepare("SELECT 1 FROM dashboard_categories WHERE category_key = ?");
    while (true) {
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

/** Réinsère $movingKey après $afterKey (vide = début) puis renumérote tout. */
function db_reposition(PDO $db, string $movingKey, string $afterKey): void
{
    $keys = $db->query("SELECT category_key FROM dashboard_categories ORDER BY sort_order ASC, label ASC")
               ->fetchAll(PDO::FETCH_COLUMN);
    $keys = array_values(array_filter($keys, static function ($k) use ($movingKey) {
        return $k !== $movingKey;
    }));
    $pos = 0;
    if ($afterKey !== '') {
        $idx = array_search($afterKey, $keys, true);
        $pos = ($idx === false) ? count($keys) : $idx + 1;
    }
    array_splice($keys, $pos, 0, [$movingKey]);
    dashboard_overlay_reorder_categories($db, $keys);
}

// ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $cats = [];
    try {
        $cats = $db->query(
            "SELECT category_key, label, description, color, icon, sort_order, is_custom, is_hidden
             FROM dashboard_categories ORDER BY sort_order ASC, label ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cats = [];
    }

    // Compter les panels par catégorie depuis le catalogue fusionné (inclut masquées).
    $counts = [];
    try {
        $merged = mdt_panel_catalog_categories($db, true);
        foreach ($merged as $label => $panels) {
            if (is_array($panels)) {
                $counts[(string) $label] = count($panels);
            }
        }
    } catch (Exception $e) {
    }

    foreach ($cats as &$c) {
        $c['is_custom']   = (int) $c['is_custom'];
        $c['is_hidden']   = (int) $c['is_hidden'];
        $c['sort_order']  = (int) $c['sort_order'];
        // Catalogue fusionné indexé par label → on compte par label.
        $c['panel_count'] = (int) ($counts[$c['label']] ?? 0);
    }
    unset($c);

    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

// ── Toutes les autres actions = écriture ──
dashboard_require_build_json();
$in = db_json_body();

if ($action === 'create') {
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'label_required']);
        exit;
    }
    $slug  = db_unique_slug($db, db_slugify($label));
    $desc  = trim((string) ($in['description'] ?? ''));
    $color = mdt_validate_hex_color((string) ($in['color'] ?? ''), '#64748B');
    $after = trim((string) ($in['after'] ?? ''));

    try {
        $st = $db->prepare(
            "INSERT INTO dashboard_categories (category_key, label, description, color, is_custom, sort_order)
             VALUES (?, ?, ?, ?, 1, 9998)"
        );
        $st->execute([$slug, $label, $desc, $color]);
        db_reposition($db, $slug, $after);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Catégorie créée', $label, 'settings', null);
    }
    echo json_encode(['success' => true, 'category_key' => $slug]);
    exit;
}

if ($action === 'update') {
    $key = trim((string) ($in['category_key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'key_required']);
        exit;
    }
    $fields = [];
    if (isset($in['label']))       { $fields['label'] = trim((string) $in['label']); }
    if (isset($in['description'])) { $fields['description'] = trim((string) $in['description']); }
    if (isset($in['color']))       { $fields['color'] = mdt_validate_hex_color((string) $in['color'], '#64748B'); }
    if (isset($in['icon']))        { $fields['icon'] = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $in['icon'])); }

    try {
        // UPDATE direct (la catégorie existe) — évite l'écueil NOT NULL d'un upsert.
        if (!empty($fields)) {
            $set = [];
            $vals = [];
            foreach ($fields as $col => $val) {
                $set[]  = "`$col` = ?";
                $vals[] = $val;
            }
            $vals[] = $key;
            $db->prepare("UPDATE dashboard_categories SET " . implode(', ', $set) . " WHERE category_key = ?")->execute($vals);
        }
        if (array_key_exists('after', $in)) {
            db_reposition($db, $key, trim((string) $in['after']));
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Catégorie modifiée', $key, 'settings', null);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete') {
    $key = trim((string) ($in['category_key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'key_required']);
        exit;
    }
    try {
        $st = $db->prepare("SELECT is_custom FROM dashboard_categories WHERE category_key = ?");
        $st->execute([$key]);
        $isCustom = (int) $st->fetchColumn();

        if ($isCustom === 1) {
            // Custom : suppression réelle + on libère les panels qui pointaient dessus.
            $db->prepare("UPDATE dashboard_panel_overlay SET category_key = NULL WHERE category_key = ?")->execute([$key]);
            $db->prepare("DELETE FROM dashboard_categories WHERE category_key = ?")->execute([$key]);
            $mode = 'deleted';
        } else {
            // Built-in : ne peut être supprimée (re-seed) → on la masque.
            $db->prepare("UPDATE dashboard_categories SET is_hidden = 1 WHERE category_key = ?")->execute([$key]);
            $mode = 'hidden';
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    if (function_exists('mdt_audit_log')) {
        mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'DASHBOARD', 'Catégorie ' . ($mode === 'deleted' ? 'supprimée' : 'masquée'), $key, 'settings', null);
    }
    echo json_encode(['success' => true, 'mode' => $mode]);
    exit;
}

if ($action === 'toggle_hidden') {
    $key = trim((string) ($in['category_key'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'key_required']);
        exit;
    }
    try {
        $db->prepare("UPDATE dashboard_categories SET is_hidden = 1 - is_hidden WHERE category_key = ?")->execute([$key]);
        $st = $db->prepare("SELECT is_hidden FROM dashboard_categories WHERE category_key = ?");
        $st->execute([$key]);
        $hidden = (int) $st->fetchColumn();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    echo json_encode(['success' => true, 'is_hidden' => $hidden]);
    exit;
}

if ($action === 'reorder') {
    $order = $in['order'] ?? [];
    if (!is_array($order)) {
        $order = [];
    }
    try {
        dashboard_overlay_reorder_categories($db, array_map('strval', $order));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
