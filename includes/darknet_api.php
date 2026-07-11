<?php
/**
 * DarkNet API — helpers centralisés pour le système DarkNet / factions illégales.
 *
 * Couvre :
 *  - Permissions (staff bypass, accès par faction, rôles leader/member)
 *  - CRUD des comptes illégaux (staff)
 *  - CRUD des sous-codes (chef)
 *  - Génération de codes hexadécimaux sécurisés
 *  - Authentification par code (côté gate)
 *  - CSRF dédié
 *  - Auto-migration légère (sécurité : les tables doivent exister via migration_darknet_system.sql)
 *
 * Dépendances : config/config.php, config/panel_access.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/panel_access.php';

// =============================================================================
// Constantes & utilitaires
// =============================================================================

if (!defined('DARKNET_CODE_LENGTH')) {
    /** Longueur du code hexadécimal généré (16 chars = 8 bytes random). */
    define('DARKNET_CODE_LENGTH', 16);
}

if (!defined('DARKNET_STAFF_BYPASS_ROLES')) {
    define('DARKNET_STAFF_BYPASS_ROLES', ['*', 'Administrateur', 'Développeur', 'Staff']);
}

if (!defined('DARKNET_LEADER_GRADES')) {
    /** Grades donnant accès aux fonctions "leader" (gestion effectif/stock/bank). */
    define('DARKNET_LEADER_GRADES', ['Chef de gang', 'Bras droit', 'Chef', 'Sous-chef', 'Leader', 'Boss']);
}

// =============================================================================
// Auto-migration du schéma (idempotent)
// =============================================================================

if (!function_exists('darknet_ensure_schema')) {
    /**
     * Crée les tables DarkNet si elles n'existent pas. Appelé au début de chaque
     * page DarkNet pour éviter les 500 lors du premier déploiement.
     */
    function darknet_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_illegal_accounts` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `faction_key`       VARCHAR(50)  NOT NULL,
                `grade`             VARCHAR(80)  NOT NULL,
                `label`             VARCHAR(120) DEFAULT NULL,
                `access_code_hash`  VARCHAR(255) NOT NULL,
                `code_last4`        VARCHAR(4)   NOT NULL,
                `assigned_user_id`  INT UNSIGNED DEFAULT NULL,
                `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
                `created_by`        INT UNSIGNED DEFAULT NULL,
                `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `last_auth_at`      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_dia_faction` (`faction_key`),
                KEY `idx_dia_user`    (`assigned_user_id`),
                KEY `idx_dia_active`  (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_subcodes` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `account_id`        INT UNSIGNED NOT NULL,
                `faction_key`       VARCHAR(50)  NOT NULL,
                `code_hash`         VARCHAR(255) NOT NULL,
                `code_last4`        VARCHAR(4)   NOT NULL,
                `member_label`      VARCHAR(120) DEFAULT NULL,
                `grade`             VARCHAR(80)  DEFAULT 'Soldat',
                `assigned_user_id`  INT UNSIGNED DEFAULT NULL,
                `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_auth_at`      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_dsc_account` (`account_id`),
                KEY `idx_dsc_faction` (`faction_key`),
                KEY `idx_dsc_user`    (`assigned_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_sessions` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`          INT UNSIGNED NOT NULL,
                `faction_key`      VARCHAR(50)  NOT NULL,
                `role`             ENUM('leader','member','staff') NOT NULL DEFAULT 'member',
                `account_id`       INT UNSIGNED DEFAULT NULL,
                `subcode_id`       INT UNSIGNED DEFAULT NULL,
                `grade`            VARCHAR(80)  DEFAULT NULL,
                `first_auth_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_auth_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip_address`       VARCHAR(45)  DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ds_user_faction` (`user_id`, `faction_key`),
                KEY `idx_ds_faction` (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_auth_log` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`       INT UNSIGNED DEFAULT NULL,
                `faction_key`   VARCHAR(50)  DEFAULT NULL,
                `success`       TINYINT(1)   NOT NULL DEFAULT 0,
                `code_last4`    VARCHAR(4)   DEFAULT NULL,
                `ip_address`    VARCHAR(45)  DEFAULT NULL,
                `user_agent`    VARCHAR(255) DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dal_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ── Phase 2 : Stock / Bank ─────────────────────────────────────
            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_stock_items` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `faction_key`   VARCHAR(50)  NOT NULL,
                `name`          VARCHAR(120) NOT NULL,
                `category`      VARCHAR(60)  DEFAULT NULL,
                `quantity`      INT          NOT NULL DEFAULT 0,
                `unit`          VARCHAR(20)  DEFAULT 'unité',
                `notes`         TEXT         DEFAULT NULL,
                `added_by`      INT UNSIGNED DEFAULT NULL,
                `added_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dsi_faction` (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_stock_tx` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `item_id`       INT UNSIGNED NOT NULL,
                `faction_key`   VARCHAR(50)  NOT NULL,
                `user_id`       INT UNSIGNED NOT NULL,
                `action`        ENUM('add','take','adjust','delete') NOT NULL,
                `qty_delta`     INT          NOT NULL,
                `notes`         VARCHAR(255) DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dst_item` (`item_id`),
                KEY `idx_dst_faction` (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_bank` (
                `faction_key`    VARCHAR(50) NOT NULL,
                `clean_balance`  BIGINT      NOT NULL DEFAULT 0,
                `dirty_balance`  BIGINT      NOT NULL DEFAULT 0,
                `updated_at`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_bank_tx` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `faction_key`   VARCHAR(50)  NOT NULL,
                `user_id`       INT UNSIGNED NOT NULL,
                `currency`      ENUM('clean','dirty') NOT NULL,
                `type`          ENUM('deposit','withdraw','launder','transfer','adjust') NOT NULL,
                `amount`        BIGINT       NOT NULL,
                `notes`         VARCHAR(255) DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dbt_faction` (`faction_key`),
                KEY `idx_dbt_user`    (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ── Phase 3 : Marché, Contrats, Leaks, Labo, Chat ──────────────
            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_market_items` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(150) NOT NULL,
                `description`   TEXT         DEFAULT NULL,
                `category`      VARCHAR(60)  DEFAULT NULL,
                `price`         BIGINT       NOT NULL DEFAULT 0,
                `currency`      ENUM('clean','dirty') NOT NULL DEFAULT 'dirty',
                `stock`         INT          NOT NULL DEFAULT 1,
                `image_url`     VARCHAR(500) DEFAULT NULL,
                `is_flash`      TINYINT(1)   NOT NULL DEFAULT 0,
                `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
                `created_by`    INT UNSIGNED DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dmi_flash` (`is_flash`),
                KEY `idx_dmi_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_market_purchases` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `item_id`       INT UNSIGNED NOT NULL,
                `buyer_faction` VARCHAR(50)  NOT NULL,
                `buyer_user`    INT UNSIGNED NOT NULL,
                `amount_paid`   BIGINT       NOT NULL,
                `currency`      ENUM('clean','dirty') NOT NULL,
                `qty`           INT          NOT NULL DEFAULT 1,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dmp_item` (`item_id`),
                KEY `idx_dmp_fac`  (`buyer_faction`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_contracts` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`            VARCHAR(150) NOT NULL,
                `target_name`      VARCHAR(120) NOT NULL,
                `description`      TEXT         DEFAULT NULL,
                `reward`           BIGINT       NOT NULL DEFAULT 0,
                `currency`         ENUM('clean','dirty') NOT NULL DEFAULT 'dirty',
                `status`           ENUM('open','accepted','proof','completed','canceled') NOT NULL DEFAULT 'open',
                `posted_by_faction` VARCHAR(50) NOT NULL,
                `posted_by_user`   INT UNSIGNED NOT NULL,
                `accepted_by_user` INT UNSIGNED DEFAULT NULL,
                `accepted_at`      DATETIME     DEFAULT NULL,
                `proof_url`        VARCHAR(500) DEFAULT NULL,
                `proof_note`       TEXT         DEFAULT NULL,
                `completed_at`     DATETIME     DEFAULT NULL,
                `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dc_status` (`status`),
                KEY `idx_dc_fac`    (`posted_by_faction`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_leaks` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type`          ENUM('leak','indic','frequency') NOT NULL DEFAULT 'leak',
                `title`         VARCHAR(150) NOT NULL,
                `content`       TEXT         DEFAULT NULL,
                `category`      VARCHAR(60)  DEFAULT NULL,
                `extra_data`    TEXT         DEFAULT NULL,
                `author_faction` VARCHAR(50) DEFAULT NULL,
                `author_user`   INT UNSIGNED NOT NULL,
                `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dl_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_lab_logs` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type`          ENUM('police_raid','arrest','patrol','research','note') NOT NULL DEFAULT 'note',
                `title`         VARCHAR(150) NOT NULL,
                `details`       TEXT         DEFAULT NULL,
                `faction_key`   VARCHAR(50)  DEFAULT NULL,
                `author_user`   INT UNSIGNED NOT NULL,
                `logged_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dll_type` (`type`),
                KEY `idx_dll_logged` (`logged_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_chat_rooms` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(120) NOT NULL,
                `scope`         ENUM('faction','private','public') NOT NULL DEFAULT 'public',
                `faction_key`   VARCHAR(50)  DEFAULT NULL,
                `created_by`    INT UNSIGNED DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dcr_scope` (`scope`),
                KEY `idx_dcr_faction` (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_chat_members` (
                `room_id`       INT UNSIGNED NOT NULL,
                `user_id`       INT UNSIGNED NOT NULL,
                `added_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`room_id`, `user_id`),
                KEY `idx_dcm_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_chat_messages` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `room_id`       INT UNSIGNED NOT NULL,
                `user_id`       INT UNSIGNED NOT NULL,
                `content`       TEXT         NOT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_dcm2_room` (`room_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS `darknet_wiki_pages` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`         VARCHAR(150) NOT NULL,
                `slug`          VARCHAR(160) NOT NULL,
                `content`       MEDIUMTEXT   NOT NULL,
                `category`      VARCHAR(60)  DEFAULT NULL,
                `author_user`   INT UNSIGNED NOT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_dwp_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Aligne la collation des tables DarkNet sur `utf8mb4_general_ci`
            // (la table `factions` est en general_ci → sans ça, les JOIN sur
            // faction_key lèvent "Illegal mix of collations").
            // On ne lance l'ALTER que si la collation ne matche pas déjà,
            // via information_schema (rapide, pas de rewrite superflu).
            $darknetTables = [
                'darknet_illegal_accounts','darknet_subcodes','darknet_sessions','darknet_auth_log',
                'darknet_stock_items','darknet_stock_tx','darknet_bank','darknet_bank_tx',
                'darknet_market_items','darknet_market_purchases','darknet_contracts','darknet_leaks',
                'darknet_lab_logs','darknet_chat_rooms','darknet_chat_members','darknet_chat_messages',
                'darknet_wiki_pages',
            ];
            try {
                $placeholders = implode(',', array_fill(0, count($darknetTables), '?'));
                $stmt = $db->prepare(
                    "SELECT TABLE_NAME, TABLE_COLLATION
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ($placeholders)"
                );
                $stmt->execute($darknetTables);
                $collations = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $collations[$r['TABLE_NAME']] = $r['TABLE_COLLATION'];
                }
                foreach ($darknetTables as $t) {
                    $cur = $collations[$t] ?? null;
                    if ($cur === null) { continue; } // table absente, ignore
                    if ($cur === 'utf8mb4_general_ci') { continue; } // déjà OK
                    try {
                        $db->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                    } catch (Throwable $e) {
                        error_log('[darknet] collation alter failed on ' . $t . ': ' . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                error_log('[darknet] collation check failed: ' . $e->getMessage());
            }

            $done = true;
        } catch (Throwable $e) {
            error_log('[darknet] schema migration failed: ' . $e->getMessage());
        }
    }
}

// =============================================================================
// Staff : faction actuellement vue (switcher)
// =============================================================================

if (!function_exists('darknet_staff_viewing_faction')) {
    /**
     * Retourne la faction actuellement "visualisée" par un staff.
     * Par défaut : la première faction illégale disponible en DB.
     */
    function darknet_staff_viewing_faction(PDO $db): string
    {
        $current = (string) ($_SESSION['darknet_staff_faction'] ?? '');
        if ($current !== '' && in_array($current, DARKNET_ALLOWED_FACTION_KEYS, true)) {
            return $current;
        }
        // Fallback : première faction illégale en DB
        try {
            $keys = DARKNET_ALLOWED_FACTION_KEYS;
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare("SELECT faction_key FROM factions WHERE faction_key IN ($placeholders) ORDER BY name ASC LIMIT 1");
            $stmt->execute($keys);
            $first = $stmt->fetchColumn();
            if ($first) {
                $_SESSION['darknet_staff_faction'] = (string) $first;
                return (string) $first;
            }
        } catch (Throwable $e) {}
        return DARKNET_ALLOWED_FACTION_KEYS[0] ?? 'BALLAS';
    }
}

if (!function_exists('darknet_staff_set_viewing_faction')) {
    function darknet_staff_set_viewing_faction(string $factionKey): bool
    {
        $factionKey = strtoupper(trim($factionKey));
        if (!in_array($factionKey, DARKNET_ALLOWED_FACTION_KEYS, true)) {
            return false;
        }
        $_SESSION['darknet_staff_faction'] = $factionKey;
        return true;
    }
}

// =============================================================================
// Permissions
// =============================================================================

if (!function_exists('darknet_staff_can_manage')) {
    /**
     * Retourne true si l'utilisateur courant peut gérer les comptes illégaux
     * (accès au panel /pages/staff/content/config_illegale.php).
     */
    function darknet_staff_can_manage(): bool
    {
        return hasRole(DARKNET_STAFF_BYPASS_ROLES);
    }
}

if (!function_exists('darknet_is_staff_bypass')) {
    function darknet_is_staff_bypass(): bool
    {
        return hasRole(DARKNET_STAFF_BYPASS_ROLES);
    }
}

// =============================================================================
// JSON output & CSRF (pour endpoints AJAX)
// =============================================================================

if (!function_exists('darknet_json_out')) {
    function darknet_json_out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('darknet_ensure_csrf')) {
    function darknet_ensure_csrf(): string
    {
        if (empty($_SESSION['darknet_csrf'])) {
            $_SESSION['darknet_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['darknet_csrf'];
    }
}

if (!function_exists('darknet_verify_csrf')) {
    function darknet_verify_csrf(?string $token): bool
    {
        $expected = (string) ($_SESSION['darknet_csrf'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}

// =============================================================================
// Génération de codes hex
// =============================================================================

if (!function_exists('darknet_generate_code')) {
    /**
     * Génère un code hex cryptographiquement sûr (16 chars par défaut).
     * Format : [a-f0-9]+ — facile à taper / dicter.
     */
    function darknet_generate_code(int $length = DARKNET_CODE_LENGTH): string
    {
        $length = max(8, min(64, $length));
        $bytes = (int) ceil($length / 2);
        $raw = bin2hex(random_bytes($bytes));
        return substr($raw, 0, $length);
    }
}

if (!function_exists('darknet_hash_code')) {
    function darknet_hash_code(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }
}

if (!function_exists('darknet_verify_code')) {
    function darknet_verify_code(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}

if (!function_exists('darknet_code_last4')) {
    function darknet_code_last4(string $code): string
    {
        $c = preg_replace('/[^a-f0-9]/i', '', $code) ?? '';
        return strtolower(substr($c, -4));
    }
}

if (!function_exists('darknet_normalize_code')) {
    /** Retire espaces, tirets, uppercase → code hex lowercase compact. */
    function darknet_normalize_code(string $input): string
    {
        $c = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $input) ?? '');
        return $c;
    }
}

// =============================================================================
// Référentiel factions illégales
// =============================================================================

if (!function_exists('darknet_get_illegal_factions')) {
    /**
     * Retourne la liste des factions illégales connues depuis la DB,
     * filtrées via DARKNET_ALLOWED_FACTION_KEYS.
     *
     * @return array<int, array{faction_key:string,name:string,color:?string,icon:?string}>
     */
    function darknet_get_illegal_factions(PDO $db): array
    {
        $keys = DARKNET_ALLOWED_FACTION_KEYS;
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare(
            "SELECT faction_key, name, color, icon
             FROM factions
             WHERE faction_key IN ($placeholders)
             ORDER BY name ASC"
        );
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('darknet_faction_grades')) {
    /** Retourne la liste des grades disponibles pour une faction illégale. */
    function darknet_faction_grades(PDO $db, string $factionKey): array
    {
        $stmt = $db->prepare(
            "SELECT fg.name, fg.rank_order
             FROM faction_grades fg
             JOIN factions f ON f.id = fg.faction_id
             WHERE f.faction_key = ?
             ORDER BY fg.rank_order ASC"
        );
        $stmt->execute([$factionKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

// =============================================================================
// Users (pour assignation)
// =============================================================================

if (!function_exists('darknet_search_users')) {
    /**
     * Recherche d'utilisateurs assignables à un compte (par username/nom/prénom).
     * Si $factionKey est fourni, filtre les users appartenant à cette faction.
     *
     * @return array<int, array{id:int,username:string,firstname:?string,lastname:?string,faction:?string}>
     */
    function darknet_search_users(PDO $db, string $query, ?string $factionKey = null, int $limit = 20): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        $params = [];
        $where = [];
        if ($query !== '') {
            $where[] = '(u.username LIKE ? OR p.firstname LIKE ? OR p.lastname LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($factionKey !== null && $factionKey !== '') {
            $where[] = 'u.faction = ?';
            $params[] = $factionKey;
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
        $sql = "SELECT u.id, u.username, p.firstname, p.lastname, u.faction
                FROM users u
                LEFT JOIN profiles p ON p.id = u.profile_id
                $whereSql
                ORDER BY u.username ASC
                LIMIT $limit";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }
}

// =============================================================================
// CRUD Comptes illégaux (darknet_illegal_accounts)
// =============================================================================

if (!function_exists('darknet_list_accounts')) {
    /**
     * Liste tous les comptes illégaux (pour le staff).
     * Inclut le nom de la faction et le username de l'utilisateur assigné.
     */
    function darknet_list_accounts(PDO $db, ?string $factionFilter = null): array
    {
        $params = [];
        $where = [];
        if ($factionFilter !== null && $factionFilter !== '') {
            $where[] = 'a.faction_key = ?';
            $params[] = $factionFilter;
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
        $sql = "SELECT a.*, f.name AS faction_name, f.color AS faction_color, f.icon AS faction_icon,
                       u.username AS assigned_username, p.firstname AS assigned_firstname, p.lastname AS assigned_lastname,
                       (SELECT COUNT(*) FROM darknet_subcodes s WHERE s.account_id = a.id AND s.is_active = 1) AS active_subcodes
                FROM darknet_illegal_accounts a
                LEFT JOIN factions f ON f.faction_key = a.faction_key
                LEFT JOIN users u ON u.id = a.assigned_user_id
                LEFT JOIN profiles p ON p.id = u.profile_id
                $whereSql
                ORDER BY f.name ASC, a.grade ASC, a.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('darknet_get_account')) {
    function darknet_get_account(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare(
            "SELECT a.*, f.name AS faction_name, f.color AS faction_color, f.icon AS faction_icon,
                    u.username AS assigned_username, p.firstname AS assigned_firstname, p.lastname AS assigned_lastname
             FROM darknet_illegal_accounts a
             LEFT JOIN factions f ON f.faction_key = a.faction_key
             LEFT JOIN users u ON u.id = a.assigned_user_id
             LEFT JOIN profiles p ON p.id = u.profile_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('darknet_create_account')) {
    /**
     * Crée un compte illégal. Retourne ['id' => int, 'code' => string (plain, à montrer UNE FOIS au staff)].
     * @throws InvalidArgumentException Si validation échoue.
     */
    function darknet_create_account(
        PDO $db,
        string $factionKey,
        string $grade,
        ?int $assignedUserId,
        ?string $label,
        ?int $createdByUserId
    ): array {
        $factionKey = strtoupper(trim($factionKey));
        $grade = trim($grade);
        $label = $label !== null ? trim($label) : null;

        if (!in_array($factionKey, DARKNET_ALLOWED_FACTION_KEYS, true)) {
            throw new InvalidArgumentException("Faction illégale inconnue : $factionKey");
        }
        if ($grade === '' || mb_strlen($grade) > 80) {
            throw new InvalidArgumentException('Grade invalide.');
        }
        if ($label !== null && mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Libellé trop long (120 max).');
        }
        if ($assignedUserId !== null && $assignedUserId <= 0) {
            $assignedUserId = null;
        }

        $plain = darknet_generate_code();
        $hash = darknet_hash_code($plain);
        $last4 = darknet_code_last4($plain);

        $stmt = $db->prepare(
            "INSERT INTO darknet_illegal_accounts
                (faction_key, grade, label, access_code_hash, code_last4, assigned_user_id, is_active, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $factionKey,
            $grade,
            $label,
            $hash,
            $last4,
            $assignedUserId,
            $createdByUserId,
        ]);
        $id = (int) $db->lastInsertId();

        return ['id' => $id, 'code' => $plain];
    }
}

if (!function_exists('darknet_update_account')) {
    /**
     * Met à jour un compte (grade, label, user assigné, actif/inactif).
     * Ne touche PAS au code (utiliser darknet_regenerate_code()).
     */
    function darknet_update_account(
        PDO $db,
        int $id,
        string $grade,
        ?int $assignedUserId,
        ?string $label,
        bool $isActive
    ): bool {
        $grade = trim($grade);
        $label = $label !== null ? trim($label) : null;
        if ($grade === '' || mb_strlen($grade) > 80) {
            throw new InvalidArgumentException('Grade invalide.');
        }
        if ($label !== null && mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Libellé trop long.');
        }
        if ($assignedUserId !== null && $assignedUserId <= 0) {
            $assignedUserId = null;
        }
        $stmt = $db->prepare(
            "UPDATE darknet_illegal_accounts
             SET grade = ?, label = ?, assigned_user_id = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$grade, $label, $assignedUserId, $isActive ? 1 : 0, $id]);
    }
}

if (!function_exists('darknet_regenerate_code')) {
    /**
     * Génère un nouveau code hex pour un compte existant (ancien invalidé).
     * Retourne le code en clair (à afficher UNE FOIS).
     */
    function darknet_regenerate_code(PDO $db, int $accountId): string
    {
        $plain = darknet_generate_code();
        $hash = darknet_hash_code($plain);
        $last4 = darknet_code_last4($plain);
        $stmt = $db->prepare(
            "UPDATE darknet_illegal_accounts
             SET access_code_hash = ?, code_last4 = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$hash, $last4, $accountId]);
        // Invalide aussi les sessions existantes pour ce compte (forcera re-auth)
        $db->prepare("DELETE FROM darknet_sessions WHERE account_id = ?")->execute([$accountId]);
        return $plain;
    }
}

if (!function_exists('darknet_delete_account')) {
    function darknet_delete_account(PDO $db, int $id): bool
    {
        $stmt = $db->prepare('DELETE FROM darknet_illegal_accounts WHERE id = ?');
        return $stmt->execute([$id]);
    }
}

// =============================================================================
// CRUD Sous-codes (darknet_subcodes)
// =============================================================================

if (!function_exists('darknet_list_subcodes')) {
    /** Liste les sous-codes d'un compte (pour le chef ou le staff). */
    function darknet_list_subcodes(PDO $db, int $accountId): array
    {
        $stmt = $db->prepare(
            "SELECT s.*, u.username AS assigned_username, p.firstname AS assigned_firstname, p.lastname AS assigned_lastname
             FROM darknet_subcodes s
             LEFT JOIN users u ON u.id = s.assigned_user_id
             LEFT JOIN profiles p ON p.id = u.profile_id
             WHERE s.account_id = ?
             ORDER BY s.created_at DESC"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('darknet_create_subcode')) {
    /**
     * Crée un sous-code membre pour un compte donné.
     * Retourne ['id' => int, 'code' => string (plain)].
     */
    function darknet_create_subcode(
        PDO $db,
        int $accountId,
        ?string $memberLabel,
        ?string $grade,
        ?int $assignedUserId
    ): array {
        $acc = darknet_get_account($db, $accountId);
        if ($acc === null) {
            throw new InvalidArgumentException('Compte parent inexistant.');
        }
        $memberLabel = $memberLabel !== null ? trim($memberLabel) : null;
        if ($memberLabel !== null && mb_strlen($memberLabel) > 120) {
            throw new InvalidArgumentException('Libellé trop long.');
        }
        $grade = $grade !== null ? trim($grade) : null;
        if ($grade !== null && $grade === '') {
            $grade = null;
        }
        if ($grade !== null && mb_strlen($grade) > 80) {
            throw new InvalidArgumentException('Grade trop long.');
        }
        if ($assignedUserId !== null && $assignedUserId <= 0) {
            $assignedUserId = null;
        }

        $plain = darknet_generate_code();
        $hash = darknet_hash_code($plain);
        $last4 = darknet_code_last4($plain);

        $stmt = $db->prepare(
            "INSERT INTO darknet_subcodes
                (account_id, faction_key, code_hash, code_last4, member_label, grade, assigned_user_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())"
        );
        $stmt->execute([
            $accountId,
            $acc['faction_key'],
            $hash,
            $last4,
            $memberLabel,
            $grade,
            $assignedUserId,
        ]);
        return ['id' => (int) $db->lastInsertId(), 'code' => $plain];
    }
}

if (!function_exists('darknet_delete_subcode')) {
    function darknet_delete_subcode(PDO $db, int $id): bool
    {
        $stmt = $db->prepare('DELETE FROM darknet_subcodes WHERE id = ?');
        return $stmt->execute([$id]);
    }
}

if (!function_exists('darknet_regenerate_subcode')) {
    function darknet_regenerate_subcode(PDO $db, int $subcodeId): string
    {
        $plain = darknet_generate_code();
        $hash = darknet_hash_code($plain);
        $last4 = darknet_code_last4($plain);
        $stmt = $db->prepare(
            "UPDATE darknet_subcodes SET code_hash = ?, code_last4 = ? WHERE id = ?"
        );
        $stmt->execute([$hash, $last4, $subcodeId]);
        $db->prepare("DELETE FROM darknet_sessions WHERE subcode_id = ?")->execute([$subcodeId]);
        return $plain;
    }
}

// =============================================================================
// Authentification (gate)
// =============================================================================

if (!function_exists('darknet_user_session')) {
    /**
     * Retourne la session DarkNet courante du user (ou null si pas authentifié).
     * @return array{id:int,user_id:int,faction_key:string,role:string,account_id:?int,subcode_id:?int,grade:?string}|null
     */
    function darknet_user_session(PDO $db, int $userId, string $factionKey): ?array
    {
        $stmt = $db->prepare(
            "SELECT * FROM darknet_sessions WHERE user_id = ? AND faction_key = ? LIMIT 1"
        );
        $stmt->execute([$userId, $factionKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('darknet_log_auth_attempt')) {
    function darknet_log_auth_attempt(PDO $db, ?int $userId, ?string $factionKey, bool $success, ?string $last4 = null): void
    {
        try {
            $stmt = $db->prepare(
                "INSERT INTO darknet_auth_log (user_id, faction_key, success, code_last4, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $userId,
                $factionKey,
                $success ? 1 : 0,
                $last4,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            // log silently
        }
    }
}

if (!function_exists('darknet_try_authenticate')) {
    /**
     * Essaie d'authentifier un utilisateur avec un code DarkNet.
     * Cherche d'abord dans darknet_illegal_accounts (leader), puis darknet_subcodes (member).
     * Si succès : crée/upsert la session + log, et retourne les infos.
     *
     * @return array{ok:bool,role?:string,faction_key?:string,grade?:?string,account_id?:?int,subcode_id?:?int,error?:string}
     */
    function darknet_try_authenticate(PDO $db, int $userId, string $userFactionKey, string $rawCode): array
    {
        $userFactionKey = strtoupper(trim($userFactionKey));
        $code = darknet_normalize_code($rawCode);
        if ($code === '' || strlen($code) < 8 || strlen($code) > 64) {
            darknet_log_auth_attempt($db, $userId, $userFactionKey ?: null, false, null);
            return ['ok' => false, 'error' => 'Code invalide (format attendu : hexadécimal 8-64 caractères).'];
        }
        if (!in_array($userFactionKey, DARKNET_ALLOWED_FACTION_KEYS, true)) {
            darknet_log_auth_attempt($db, $userId, $userFactionKey ?: null, false, darknet_code_last4($code));
            return ['ok' => false, 'error' => 'Votre faction actuelle ne permet pas l\'accès au DarkNet.'];
        }

        // ── 1. Leader accounts ───────────────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT * FROM darknet_illegal_accounts
             WHERE faction_key = ? AND is_active = 1"
        );
        $stmt->execute([$userFactionKey]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($accounts as $acc) {
            if (darknet_verify_code($code, $acc['access_code_hash'])) {
                $accountId = (int) $acc['id'];
                // Lie assigned_user_id si pas encore défini
                if (empty($acc['assigned_user_id'])) {
                    $db->prepare("UPDATE darknet_illegal_accounts SET assigned_user_id = ?, last_auth_at = NOW() WHERE id = ?")
                        ->execute([$userId, $accountId]);
                } else {
                    $db->prepare("UPDATE darknet_illegal_accounts SET last_auth_at = NOW() WHERE id = ?")
                        ->execute([$accountId]);
                }
                darknet_upsert_session($db, $userId, $userFactionKey, 'leader', $accountId, null, $acc['grade']);
                darknet_log_auth_attempt($db, $userId, $userFactionKey, true, darknet_code_last4($code));
                return [
                    'ok' => true,
                    'role' => 'leader',
                    'faction_key' => $userFactionKey,
                    'grade' => $acc['grade'],
                    'account_id' => $accountId,
                    'subcode_id' => null,
                ];
            }
        }

        // ── 2. Sub-codes ─────────────────────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT * FROM darknet_subcodes
             WHERE faction_key = ? AND is_active = 1"
        );
        $stmt->execute([$userFactionKey]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($subs as $s) {
            if (darknet_verify_code($code, $s['code_hash'])) {
                $subId = (int) $s['id'];
                if (empty($s['assigned_user_id'])) {
                    $db->prepare("UPDATE darknet_subcodes SET assigned_user_id = ?, last_auth_at = NOW() WHERE id = ?")
                        ->execute([$userId, $subId]);
                } else {
                    $db->prepare("UPDATE darknet_subcodes SET last_auth_at = NOW() WHERE id = ?")
                        ->execute([$subId]);
                }
                darknet_upsert_session($db, $userId, $userFactionKey, 'member', (int) $s['account_id'], $subId, $s['grade']);
                darknet_log_auth_attempt($db, $userId, $userFactionKey, true, darknet_code_last4($code));
                return [
                    'ok' => true,
                    'role' => 'member',
                    'faction_key' => $userFactionKey,
                    'grade' => $s['grade'],
                    'account_id' => (int) $s['account_id'],
                    'subcode_id' => $subId,
                ];
            }
        }

        darknet_log_auth_attempt($db, $userId, $userFactionKey, false, darknet_code_last4($code));
        return ['ok' => false, 'error' => 'Code refusé. Accès DarkNet rejeté.'];
    }
}

if (!function_exists('darknet_upsert_session')) {
    function darknet_upsert_session(
        PDO $db,
        int $userId,
        string $factionKey,
        string $role,
        ?int $accountId,
        ?int $subcodeId,
        ?string $grade
    ): void {
        $stmt = $db->prepare(
            "INSERT INTO darknet_sessions
                (user_id, faction_key, role, account_id, subcode_id, grade, first_auth_at, last_auth_at, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                account_id = VALUES(account_id),
                subcode_id = VALUES(subcode_id),
                grade = VALUES(grade),
                last_auth_at = NOW(),
                ip_address = VALUES(ip_address)"
        );
        $stmt->execute([
            $userId,
            $factionKey,
            $role,
            $accountId,
            $subcodeId,
            $grade,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

if (!function_exists('darknet_require_auth')) {
    /**
     * Middleware : redirige vers le gate si l'utilisateur n'est pas authentifié.
     * Staff bypass pour modération.
     * Retourne la session DarkNet (ou staff virtuelle).
     */
    function darknet_require_auth(PDO $db): array
    {
        requireLogin();
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userFaction = (string) ($_SESSION['user_faction'] ?? '');

        // Staff bypass : faction "visualisée" choisie via switcher
        if (darknet_is_staff_bypass()) {
            $viewing = darknet_staff_viewing_faction($db);
            return [
                'id' => 0,
                'user_id' => $userId,
                'faction_key' => $viewing,
                'role' => 'leader', // staff peut tout faire comme un leader
                'account_id' => null,
                'subcode_id' => null,
                'grade' => 'Staff',
                'staff_bypass' => true,
            ];
        }

        if (!userHasDarknetAccess()) {
            redirect(SITE_URL . '/pages/dashboard.php');
        }

        $sess = darknet_user_session($db, $userId, $userFaction);
        if ($sess === null) {
            redirect(SITE_URL . '/pages/panels/darknet/gate.php');
        }
        $sess['staff_bypass'] = false;
        return $sess;
    }
}

if (!function_exists('darknet_has_session')) {
    /** Test non-bloquant : l'utilisateur a-t-il une session DarkNet valide ? */
    function darknet_has_session(PDO $db): bool
    {
        if (darknet_is_staff_bypass()) {
            return true;
        }
        if (!isset($_SESSION['user_id']) || !userHasDarknetAccess()) {
            return false;
        }
        $sess = darknet_user_session($db, (int) $_SESSION['user_id'], (string) ($_SESSION['user_faction'] ?? ''));
        return $sess !== null;
    }
}

if (!function_exists('darknet_logout')) {
    function darknet_logout(PDO $db, int $userId, ?string $factionKey = null): void
    {
        if ($factionKey !== null) {
            $db->prepare("DELETE FROM darknet_sessions WHERE user_id = ? AND faction_key = ?")
                ->execute([$userId, $factionKey]);
        } else {
            $db->prepare("DELETE FROM darknet_sessions WHERE user_id = ?")->execute([$userId]);
        }
    }
}

// =============================================================================
// Gestion des rôles côté client (is_leader, can_generate_subcode, etc.)
// =============================================================================

if (!function_exists('darknet_session_can_manage_faction')) {
    /** Le user peut-il gérer l'effectif/stock/bank ? (leader ou staff) */
    function darknet_session_can_manage_faction(array $session): bool
    {
        return ($session['role'] ?? '') === 'leader'
            || !empty($session['staff_bypass']);
    }
}
