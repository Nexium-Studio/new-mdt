<?php
/**
 * Centralized stock category/settings system.
 * Shared by all company/panel stock.php and vente.php pages.
 */

function stock_ensure_tables(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `stock_categories` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `company_key`   varchar(100) NOT NULL,
            `name`          varchar(200) NOT NULL,
            `icon`          varchar(100) NOT NULL DEFAULT 'fas fa-box',
            `color`         varchar(20)  NOT NULL DEFAULT '#64748b',
            `display_order` int(11)      NOT NULL DEFAULT 0,
            `is_active`     tinyint(1)   NOT NULL DEFAULT 1,
            `created_at`    timestamp    NULL DEFAULT current_timestamp(),
            `updated_at`    timestamp    NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_company_name` (`company_key`,`name`),
            KEY `idx_company_key` (`company_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `stock_settings` (
            `company_key` varchar(100) NOT NULL,
            `max_items`   int(11)      NOT NULL DEFAULT 0,
            `created_at`  timestamp    NULL DEFAULT current_timestamp(),
            `updated_at`  timestamp    NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`company_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* tables may already exist */ }
    $done = true;
}

/**
 * Returns categories for a given company key as:
 *   ['Category Name' => ['id' => int, 'icon' => string, 'color' => string]]
 */
function stock_get_categories(PDO $db, string $companyKey): array
{
    static $cache = [];
    if (isset($cache[$companyKey])) return $cache[$companyKey];

    stock_ensure_tables($db);
    try {
        $stmt = $db->prepare(
            "SELECT * FROM stock_categories WHERE company_key = ? AND is_active = 1 ORDER BY display_order ASC, name ASC"
        );
        $stmt->execute([$companyKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$companyKey] = [];
        return [];
    }

    $cats = [];
    foreach ($rows as $r) {
        $cats[$r['name']] = ['id' => (int)$r['id'], 'icon' => $r['icon'], 'color' => $r['color']];
    }
    $cache[$companyKey] = $cats;
    return $cats;
}

/**
 * Returns all categories (including inactive) for staff management.
 * Returns full row arrays indexed by id.
 */
function stock_get_all_categories(PDO $db, string $companyKey): array
{
    stock_ensure_tables($db);
    try {
        $stmt = $db->prepare(
            "SELECT * FROM stock_categories WHERE company_key = ? ORDER BY display_order ASC, name ASC"
        );
        $stmt->execute([$companyKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Returns settings for a given company key.
 * ['company_key' => string, 'max_items' => int]
 */
function stock_get_settings(PDO $db, string $companyKey): array
{
    static $cache = [];
    if (isset($cache[$companyKey])) return $cache[$companyKey];

    stock_ensure_tables($db);
    try {
        $stmt = $db->prepare("SELECT * FROM stock_settings WHERE company_key = ?");
        $stmt->execute([$companyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = false;
    }
    $result = $row ?: ['company_key' => $companyKey, 'max_items' => 0];
    $cache[$companyKey] = $result;
    return $result;
}

/** Fallback category when none configured. */
function stock_fallback_category(): array
{
    return ['Autre' => ['id' => 0, 'icon' => 'fas fa-box', 'color' => '#64748b']];
}

/**
 * Scans pages/companies/ and pages/panels/ for directories containing stock.php.
 * Extracts $companyFaction and $companyName from each file via regex.
 * Returns array of ['key','name','type' (company|panel),'slug'].
 */
function stock_scan_entities(string $rootPath): array
{
    $entities = [];

    $groups = [
        'company' => $rootPath . '/pages/companies/*/stock.php',
        'panel'   => $rootPath . '/pages/panels/*/stock.php',
    ];

    foreach ($groups as $type => $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            $slug    = basename(dirname($file));
            $content = @file_get_contents($file);
            if (!$content) continue;

            $key  = null;
            $name = ucwords(str_replace(['-', '_'], ' ', $slug));

            if (preg_match('/\$companyFaction\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $key = $m[1];
            }
            if (preg_match('/\$(companyName|panelName)\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $name = $m[2];
            }

            if (!$key) continue;

            $entities[] = [
                'key'  => $key,
                'name' => $name,
                'type' => $type,
                'slug' => $slug,
            ];
        }
    }

    return $entities;
}
