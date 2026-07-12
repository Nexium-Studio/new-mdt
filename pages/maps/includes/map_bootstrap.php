<?php
/**
 * pages/maps/includes/map_bootstrap.php
 * ─────────────────────────────────────────────────────────────────────────
 * Auto-migration du Système de Cartes MDT (tables mdt_map_*).
 *
 * Inclus par toutes les pages / l'API du système. Suit la convention du MDT
 * (cf. pages/staff/content/hack-manager.php) : CREATE TABLE IF NOT EXISTS dans
 * un try/catch, préfixe mdt_, dégradation gracieuse si la base est indisponible.
 *
 * Système 100 % indépendant du module carte LSPD (tables lspd_maps* non touchées).
 */

declare(strict_types=1);

if (!function_exists('mdt_maps_bootstrap')) {
    /**
     * Crée les tables du système de cartes + seeds (tags par défaut, singletons
     * Commune / Gang). Exécuté une seule fois par requête (garde statique).
     */
    function mdt_maps_bootstrap(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            // ── Activation des cartes de faction (apparition dans la navbar) ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_map_factions (
                faction_key VARCHAR(50) NOT NULL PRIMARY KEY,
                is_active   TINYINT(1) NOT NULL DEFAULT 0,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Droits d'accès par faction propriétaire (configurés côté staff) ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_map_permissions (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_faction VARCHAR(50) NOT NULL,
                faction       VARCHAR(50) NOT NULL,
                can_view      TINYINT(1) NOT NULL DEFAULT 0,
                can_edit      TINYINT(1) NOT NULL DEFAULT 0,
                can_create    TINYINT(1) NOT NULL DEFAULT 0,
                can_delete    TINYINT(1) NOT NULL DEFAULT 0,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_owner_grantee (owner_faction, faction)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Catégories (tags) ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_map_tags (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nom         VARCHAR(80) NOT NULL,
                color       VARCHAR(20) NOT NULL DEFAULT '#64748b',
                faction_key VARCHAR(50) NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Cartes (créées + singletons Commune/Gang) ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_maps (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope        ENUM('faction','commune','gang') NOT NULL DEFAULT 'faction',
                faction_key  VARCHAR(50) NULL,
                title        VARCHAR(150) NOT NULL DEFAULT 'Carte sans titre',
                tag_id       INT UNSIGNED NULL,
                background   ENUM('atlas','roadmap','satellite') NOT NULL DEFAULT 'atlas',
                visibility   ENUM('draft','public','private') NOT NULL DEFAULT 'draft',
                creator_id   INT UNSIGNED NULL,
                creator_name VARCHAR(120) NULL,
                revision     INT UNSIGNED NOT NULL DEFAULT 0,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_faction_vis (faction_key, visibility),
                KEY idx_scope (scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Éléments (dessins / marqueurs / zones) — un élément = une ligne ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_map_elements (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                map_id       INT UNSIGNED NOT NULL,
                element_type ENUM('stroke','marker','zone') NOT NULL,
                data         LONGTEXT NOT NULL,
                created_by   INT UNSIGNED NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_map (map_id),
                KEY idx_map_type (map_id, element_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Présence temps réel (curseurs) ──
            $db->exec("CREATE TABLE IF NOT EXISTS mdt_map_presence (
                map_id       INT UNSIGNED NOT NULL,
                user_id      INT UNSIGNED NOT NULL,
                display_name VARCHAR(120) NOT NULL DEFAULT 'Agent',
                nx           FLOAT NOT NULL DEFAULT 0.5,
                ny           FLOAT NOT NULL DEFAULT 0.5,
                updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (map_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Seed des tags par défaut (une seule fois) ──
            $tagCount = (int) $db->query("SELECT COUNT(*) FROM mdt_map_tags")->fetchColumn();
            if ($tagCount === 0) {
                $seedTags = [
                    ['Opération',    '#ef4444'],
                    ['Briefing',     '#3b82f6'],
                    ['Surveillance', '#8b5cf6'],
                    ['Patrouille',   '#10b981'],
                    ['Enquête',      '#f59e0b'],
                    ['Autre',        '#64748b'],
                ];
                $ins = $db->prepare("INSERT INTO mdt_map_tags (nom, color) VALUES (?, ?)");
                foreach ($seedTags as $t) {
                    $ins->execute([$t[0], $t[1]]);
                }
            }

            // ── Seed des singletons Commune / Gang (cartes partagées permanentes) ──
            foreach ([['commune', 'Map Commune'], ['gang', 'Map Gang']] as $sg) {
                $exists = (int) $db->query(
                    "SELECT COUNT(*) FROM mdt_maps WHERE scope = " . $db->quote($sg[0])
                )->fetchColumn();
                if ($exists === 0) {
                    $db->prepare(
                        "INSERT INTO mdt_maps (scope, faction_key, title, background, visibility, creator_name)
                         VALUES (?, NULL, ?, 'atlas', 'public', 'Système')"
                    )->execute([$sg[0], $sg[1]]);
                }
            }
        } catch (Throwable $e) {
            // Dégradation gracieuse : ne jamais casser une page si la base est indisponible.
            error_log('[MDT Maps] bootstrap: ' . $e->getMessage());
        }
    }
}
