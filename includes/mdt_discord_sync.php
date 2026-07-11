<?php
/**
 * includes/mdt_discord_sync.php
 *
 * Synchronisation Discord ↔ MDT : attribution automatique des rôles MDT
 * en fonction des rôles Discord d'un utilisateur.
 */

if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/../config/config.php';
}

// ---------------------------------------------------------------------------
// Catalogue des permissions staff (source de vérité pour l'UI)
// ---------------------------------------------------------------------------

function mdt_staff_permissions_catalog(): array {
    return [
        'staff_dashboard' => [
            'group' => 'Dashboard Staff',
            'icon'  => 'gauge',
            'perms' => [
                'staff.dashboard.view'  => 'Voir le dashboard staff',
                'staff.dashboard.stats' => 'Voir les statistiques globales',
            ],
        ],
        'staff_users' => [
            'group' => 'Gestion Utilisateurs',
            'icon'  => 'users',
            'perms' => [
                'staff.users.view'    => 'Voir les utilisateurs',
                'staff.users.edit'    => 'Modifier les profils',
                'staff.users.ban'     => 'Bannir un utilisateur',
                'staff.users.delete'  => 'Supprimer un compte',
                'staff.users.roles'   => 'Gérer les rôles globaux',
                'staff.users.grades'  => 'Gérer les grades factions',
            ],
        ],
        'staff_logs' => [
            'group' => 'Logs & Audit',
            'icon'  => 'scroll',
            'perms' => [
                'staff.logs.view'   => 'Consulter les logs',
                'staff.logs.delete' => 'Supprimer des logs',
                'staff.logs.export' => 'Exporter les logs',
            ],
        ],
        'staff_settings' => [
            'group' => 'Configuration',
            'icon'  => 'gear',
            'perms' => [
                'staff.settings.view'  => 'Voir les paramètres',
                'staff.settings.edit'  => 'Modifier les paramètres',
                'staff.settings.reset' => 'Réinitialiser la base de données',
            ],
        ],
        'staff_extensions' => [
            'group' => 'Extensions & Panels',
            'icon'  => 'puzzle-piece',
            'perms' => [
                'staff.extensions.view'    => 'Voir les extensions',
                'staff.extensions.manage'  => 'Gérer les extensions',
                'staff.panels.manage'      => 'Gérer les panels personnalisés',
                'staff.divisions.manage'   => 'Gérer les divisions',
            ],
        ],
        'staff_content' => [
            'group' => 'Contenu',
            'icon'  => 'newspaper',
            'perms' => [
                'staff.content.mdt'      => 'Gérer le contenu MDT',
                'staff.content.dispatch' => 'Configurer le dispatch',
                'staff.content.mail'     => 'Gérer le mail MDT',
            ],
        ],
        'staff_academy' => [
            'group' => 'Académie',
            'icon'  => 'graduation-cap',
            'perms' => [
                'staff.academy.view'   => 'Voir l\'académie',
                'staff.academy.manage' => 'Gérer l\'académie',
                'staff.academy.grades' => 'Gérer les grades académie',
            ],
        ],
        'staff_sanctions' => [
            'group' => 'Sanctions & Bannissements',
            'icon'  => 'gavel',
            'perms' => [
                'staff.sanctions.view'   => 'Voir les sanctions',
                'staff.sanctions.create' => 'Créer une sanction',
                'staff.sanctions.delete' => 'Supprimer une sanction',
                'staff.bans.manage'      => 'Gérer les bannissements',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Auto-création des tables
// ---------------------------------------------------------------------------

function mdt_discord_sync_ensure_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `mdt_roles` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` varchar(50) NOT NULL,
              `display_name` varchar(100) NOT NULL,
              `description` text DEFAULT NULL,
              `color` varchar(20) NOT NULL DEFAULT '#64748b',
              `icon` varchar(50) NOT NULL DEFAULT 'shield',
              `hierarchy_level` smallint(6) NOT NULL DEFAULT 0,
              `discord_role_id` varchar(30) DEFAULT NULL,
              `is_default` tinyint(1) NOT NULL DEFAULT 0,
              `is_staff_panel` tinyint(1) NOT NULL DEFAULT 0,
              `discord_sync_enabled` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `mdt_role_panel_access` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `role_name` varchar(50) NOT NULL,
              `panel_key` varchar(100) NOT NULL,
              `can_access` tinyint(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_role_panel` (`role_name`, `panel_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `mdt_role_staff_permissions` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `role_name` varchar(50) NOT NULL,
              `permission_key` varchar(100) NOT NULL,
              `enabled` tinyint(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_role_perm` (`role_name`, `permission_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `mdt_discord_sync_log` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` int(11) DEFAULT NULL,
              `discord_id` varchar(30) DEFAULT NULL,
              `old_role` varchar(50) DEFAULT NULL,
              `new_role` varchar(50) DEFAULT NULL,
              `source` varchar(20) NOT NULL DEFAULT 'login',
              `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // ── Migration douce : ajouter can_view_all_panels si absent ─────────
        $hasCol = (bool)$db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'mdt_roles'
              AND COLUMN_NAME  = 'can_view_all_panels'
        ")->fetchColumn();
        if (!$hasCol) {
            $db->exec("ALTER TABLE `mdt_roles` ADD COLUMN `can_view_all_panels` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_staff_panel`");
        }

        $done = true;
    } catch (Throwable $e) {
        error_log('[mdt_discord_sync] ensure_tables: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Récupération des rôles Discord via l'API Discord officielle (Bot Token)
// ---------------------------------------------------------------------------

/**
 * Récupère les IDs de rôles Discord d'un membre via l'API Discord officielle.
 *
 * Nécessite : admin_settings.discord_bot_token = "MTxxxxxxx..."
 * Endpoint  : GET https://discord.com/api/v10/guilds/{guild}/members/{user}
 * Doc       : https://discord.com/developers/docs/resources/guild#get-guild-member
 *
 * Retourne null  → erreur (bot token manquant, API injoignable)
 * Retourne []    → membre absent de la guilde
 * Retourne [ids] → liste des IDs de rôles Discord du membre
 */
function mdt_get_discord_member_roles(string $discordUserId, string $guildId): ?array {
    if ($discordUserId === '' || $guildId === '') return null;

    // Lire le Bot Token depuis la base de données
    $botToken = '';
    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'discord_bot_token' LIMIT 1");
        $stmt->execute();
        $botToken = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    if ($botToken === '') {
        // Bot Token non configuré → sync impossible
        error_log('[mdt_discord_sync] discord_bot_token manquant dans admin_settings');
        return null;
    }

    // Appel API Discord officielle
    try {
        $url = "https://discord.com/api/v10/guilds/{$guildId}/members/{$discordUserId}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bot ' . $botToken,
                'Content-Type: application/json',
                'User-Agent: MDT-Nexium/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 404) return [];  // Membre absent de la guilde
        if ($code === 401) {
            error_log('[mdt_discord_sync] Bot Token invalide (401 Unauthorized)');
            return null;
        }
        if ($code !== 200 || !$resp) {
            error_log("[mdt_discord_sync] Discord API HTTP $code");
            return null;
        }

        $data = json_decode($resp, true);
        return $data['roles'] ?? [];
    } catch (Throwable $e) {
        error_log('[mdt_discord_sync] get_member_roles: ' . $e->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------------------
// Synchronisation principale
// ---------------------------------------------------------------------------

/**
 * Synchronise le rôle MDT d'un utilisateur selon ses rôles Discord.
 *
 * Logique :
 *  1. Récupère les rôles Discord du membre via le bot API
 *  2. Cherche le rôle MDT lié (discord_role_id) le plus élevé dans la hiérarchie
 *  3. Met à jour users.role si nécessaire
 *  4. Logue le changement
 *
 * Retourne un tableau ['success', 'changed', 'old_role', 'new_role', 'message']
 */
function mdt_discord_sync_user(PDO $db, int $userId, string $discordId, string $source = 'login'): array {
    mdt_discord_sync_ensure_tables($db);

    if ($discordId === '') {
        return ['success' => false, 'changed' => false, 'message' => 'Discord ID manquant'];
    }

    // Récupérer le Guild ID depuis les paramètres
    $guildId = '';
    try {
        $gs = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'discord_guild_id' LIMIT 1");
        $gs->execute();
        $guildId = (string)($gs->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    if ($guildId === '') {
        return ['success' => false, 'changed' => false, 'message' => 'Guild ID Discord non configuré'];
    }

    // Récupérer le rôle actuel de l'utilisateur
    $currentRole = '';
    try {
        $cr = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $cr->execute([$userId]);
        $currentRole = (string)($cr->fetchColumn() ?: 'Citoyen');
    } catch (Throwable $e) {}

    // Récupérer les rôles Discord du membre via l'API Discord
    $discordRoleIds = mdt_get_discord_member_roles($discordId, $guildId);
    if ($discordRoleIds === null) {
        return ['success' => false, 'changed' => false, 'message' => 'Bot Token Discord non configuré ou invalide — ajoute le dans Rôles & Discord → Config Discord'];
    }

    // Charger tous les rôles MDT avec Discord ID, triés par hiérarchie DESC
    try {
        $rs = $db->query("SELECT name, hierarchy_level, discord_role_id FROM mdt_roles
                           WHERE discord_sync_enabled = 1 AND discord_role_id IS NOT NULL AND discord_role_id != ''
                           ORDER BY hierarchy_level DESC");
        $mdtRoles = $rs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['success' => false, 'changed' => false, 'message' => 'Erreur lecture rôles: ' . $e->getMessage()];
    }

    // Trouver le rôle MDT le plus élevé correspondant aux rôles Discord
    $newRole = '';
    foreach ($mdtRoles as $r) {
        if (in_array($r['discord_role_id'], $discordRoleIds, true)) {
            $newRole = $r['name'];
            break; // Premier = plus haut dans la hiérarchie
        }
    }

    // Si aucun rôle Discord ne correspond → rôle par défaut
    if ($newRole === '') {
        try {
            $def = $db->query("SELECT name FROM mdt_roles WHERE is_default = 1 ORDER BY hierarchy_level DESC LIMIT 1");
            $newRole = $def->fetchColumn() ?: 'Citoyen';
        } catch (Throwable $e) {
            $newRole = 'Citoyen';
        }
    }

    // Mettre à jour si changement
    $changed = ($newRole !== $currentRole);
    if ($changed) {
        try {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);

            // Logger le changement
            $db->prepare("INSERT INTO mdt_discord_sync_log (user_id, discord_id, old_role, new_role, source)
                          VALUES (?,?,?,?,?)")->execute([$userId, $discordId, $currentRole, $newRole, $source]);

            mdt_audit_log($userId, 'DISCORD_SYNC', 'Synchronisation rôle Discord',
                "Rôle : $currentRole → $newRole (source: $source)", 'user', null);
        } catch (Throwable $e) {
            return ['success' => false, 'changed' => false, 'message' => 'Erreur mise à jour: ' . $e->getMessage()];
        }
    }

    return [
        'success'  => true,
        'changed'  => $changed,
        'old_role' => $currentRole,
        'new_role' => $newRole,
        'message'  => $changed ? "Rôle mis à jour : $currentRole → $newRole" : "Rôle inchangé : $currentRole",
    ];
}

/**
 * Vérifie si un rôle MDT peut accéder à un panel donné.
 * Logique : si aucune règle → permissif. Si règle can_access = 0 → bloqué.
 */
function mdt_role_can_access_panel(PDO $db, string $roleName, string $panelKey): bool {
    if (in_array($roleName, ['*', 'Administrateur', 'Développeur'], true)) return true;
    try {
        $stmt = $db->prepare(
            "SELECT can_access FROM mdt_role_panel_access WHERE role_name = ? AND panel_key = ? LIMIT 1"
        );
        $stmt->execute([$roleName, $panelKey]);
        $val = $stmt->fetchColumn();
        return ($val === false) ? true : (bool)(int)$val;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Vérifie si un rôle MDT a une permission staff.
 */
function mdt_role_has_staff_permission(PDO $db, string $roleName, string $permKey): bool {
    if (in_array($roleName, ['*', 'Administrateur', 'Développeur'], true)) return true;
    try {
        $stmt = $db->prepare(
            "SELECT enabled FROM mdt_role_staff_permissions WHERE role_name = ? AND permission_key = ? LIMIT 1"
        );
        $stmt->execute([$roleName, $permKey]);
        $val = $stmt->fetchColumn();
        return ($val === false) ? false : (bool)(int)$val;
    } catch (Throwable $e) {
        return false;
    }
}
