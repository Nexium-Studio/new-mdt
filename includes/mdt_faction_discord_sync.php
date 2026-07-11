<?php
/**
 * includes/mdt_faction_discord_sync.php
 *
 * Synchronisation Discord B (serveur factions/RP) ↔ factions MDT.
 * Indépendant de mdt_discord_sync.php qui gère les rôles globaux (Serveur A).
 */

if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/../config/config.php';
}

// ---------------------------------------------------------------------------
// Gestion des tables
// ---------------------------------------------------------------------------

function mdt_faction_sync_ensure_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `faction_discord_sync_config` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `setting_key` varchar(50) NOT NULL,
              `setting_value` text DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `faction_discord_role_map` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `faction_key` varchar(50) NOT NULL,
              `discord_role_id` varchar(30) NOT NULL,
              `faction_name` varchar(100) DEFAULT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_faction` (`faction_key`),
              KEY `idx_discord_role` (`discord_role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `faction_discord_sync_log` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` int(11) DEFAULT NULL,
              `discord_id` varchar(30) DEFAULT NULL,
              `username` varchar(100) DEFAULT NULL,
              `old_faction` varchar(50) DEFAULT NULL,
              `new_faction` varchar(50) DEFAULT NULL,
              `discord_role_id` varchar(30) DEFAULT NULL,
              `source` varchar(20) NOT NULL DEFAULT 'login',
              `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
    } catch (Throwable $e) {
        error_log('[faction_discord_sync] ensure_tables: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

function mdt_faction_sync_get_config(PDO $db): array {
    mdt_faction_sync_ensure_tables($db);
    $cfg = ['guild_id' => '', 'bot_token' => ''];
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM faction_discord_sync_config")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ($r['setting_key'] === 'faction_discord_guild_id')  $cfg['guild_id']  = $r['setting_value'];
            if ($r['setting_key'] === 'faction_discord_bot_token') $cfg['bot_token'] = $r['setting_value'];
        }
    } catch (Throwable) {}
    return $cfg;
}

function mdt_faction_sync_save_config(PDO $db, string $guildId, string $botToken): void {
    mdt_faction_sync_ensure_tables($db);
    $upsert = $db->prepare("INSERT INTO faction_discord_sync_config (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
    if ($guildId !== '') $upsert->execute(['faction_discord_guild_id',  $guildId,  $guildId]);
    if ($botToken !== '') $upsert->execute(['faction_discord_bot_token', $botToken, $botToken]);
}

// ---------------------------------------------------------------------------
// Mapping faction_key ↔ Discord Role ID
// ---------------------------------------------------------------------------

function mdt_faction_sync_get_map(PDO $db): array {
    mdt_faction_sync_ensure_tables($db);
    $map = [];
    try {
        $rows = $db->query("SELECT faction_key, discord_role_id, faction_name FROM faction_discord_role_map WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $map[$r['faction_key']] = ['discord_role_id' => $r['discord_role_id'], 'faction_name' => $r['faction_name']];
        }
    } catch (Throwable) {}
    return $map;
}

function mdt_faction_sync_save_map(PDO $db, array $mappings): void {
    mdt_faction_sync_ensure_tables($db);
    $upsert = $db->prepare("
        INSERT INTO faction_discord_role_map (faction_key, discord_role_id, faction_name, is_active)
        VALUES (?,?,?,1)
        ON DUPLICATE KEY UPDATE discord_role_id=VALUES(discord_role_id), faction_name=VALUES(faction_name), is_active=1
    ");
    $clear = $db->prepare("UPDATE faction_discord_role_map SET is_active=0 WHERE faction_key=?");

    foreach ($mappings as $factionKey => $discordRoleId) {
        $factionKey    = strtoupper(preg_replace('/[^A-Z0-9_a-z]/', '', $factionKey));
        $discordRoleId = preg_replace('/[^0-9]/', '', trim($discordRoleId));
        if ($factionKey === '') continue;
        if ($discordRoleId === '') {
            $clear->execute([$factionKey]);
        } else {
            $upsert->execute([$factionKey, $discordRoleId, $factionKey]);
        }
    }
}

// ---------------------------------------------------------------------------
// API Discord — récupérer rôles d'un membre (Serveur B)
// ---------------------------------------------------------------------------

function mdt_faction_sync_get_member_roles(string $discordUserId, string $guildId, string $botToken): ?array {
    if ($discordUserId === '' || $guildId === '' || $botToken === '') return null;
    try {
        $url = "https://discord.com/api/v10/guilds/{$guildId}/members/{$discordUserId}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ["Authorization: Bot {$botToken}", 'User-Agent: MDT-Nexium/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($code === 404) return [];
        if ($code !== 200 || !$resp) return null;
        $data = json_decode($resp, true);
        return $data['roles'] ?? [];
    } catch (Throwable $e) {
        error_log('[faction_discord_sync] get_member_roles: ' . $e->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------------------
// Synchronisation principale : faction pour un utilisateur
// ---------------------------------------------------------------------------

/**
 * Synchronise la faction MDT d'un utilisateur via ses rôles Discord (Serveur B).
 *
 * Logique :
 *  - Si le bot ne répond pas → on ne touche rien (pas d'erreur)
 *  - Si aucun rôle faction trouvé sur Discord B → faction inchangée (gestion manuelle possible)
 *  - Si un rôle faction trouvé → met à jour users.faction
 *  - Si rôle faction retiré (utilisateur a une faction mais plus le rôle) → passe à NONE
 *
 * Retourne ['success', 'changed', 'old_faction', 'new_faction', 'message']
 */
function mdt_faction_sync_user(PDO $db, int $userId, string $discordId, string $source = 'login'): array {
    mdt_faction_sync_ensure_tables($db);

    if ($discordId === '') {
        return ['success' => false, 'changed' => false, 'message' => 'Discord ID manquant'];
    }

    $cfg = mdt_faction_sync_get_config($db);
    if ($cfg['guild_id'] === '' || $cfg['bot_token'] === '') {
        return ['success' => false, 'changed' => false, 'message' => 'Serveur Discord B non configuré'];
    }

    // Faction actuelle de l'utilisateur
    $currentFaction = 'NONE';
    $username       = '';
    try {
        $stmt = $db->prepare("SELECT faction, username FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentFaction = $row['faction'] ?? 'NONE';
        $username       = $row['username'] ?? '';
    } catch (Throwable) {}

    // Récupérer les rôles Discord du membre sur Serveur B
    $discordRoles = mdt_faction_sync_get_member_roles($discordId, $cfg['guild_id'], $cfg['bot_token']);
    if ($discordRoles === null) {
        // Bot indisponible ou membre absent → on ne change rien
        return ['success' => true, 'changed' => false, 'message' => 'Bot Discord B indisponible — faction inchangée'];
    }

    // Charger le mapping Discord Role ID → faction_key
    $map = mdt_faction_sync_get_map($db);
    // Inverser : discord_role_id → faction_key
    $roleToFaction = [];
    foreach ($map as $fk => $m) {
        if ($m['discord_role_id'] !== '') {
            $roleToFaction[$m['discord_role_id']] = $fk;
        }
    }

    // Trouver la faction correspondante (premier match = priorité d'insertion dans le mapping)
    $newFaction      = '';
    $matchedRoleId   = '';
    foreach ($discordRoles as $roleId) {
        if (isset($roleToFaction[$roleId])) {
            $newFaction    = $roleToFaction[$roleId];
            $matchedRoleId = $roleId;
            break;
        }
    }

    // Si l'utilisateur est sur le Serveur B mais n'a aucun rôle faction connu
    // et qu'il a déjà une faction MDT liée au mapping → on retire sa faction
    if ($newFaction === '' && $currentFaction !== 'NONE' && isset($roleToFaction)) {
        // Vérifier si la faction actuelle est dans le mapping → si oui, l'utilisateur a perdu son rôle
        $hasMapping = false;
        foreach ($map as $fk => $m) {
            if ($fk === $currentFaction && $m['discord_role_id'] !== '') { $hasMapping = true; break; }
        }
        if ($hasMapping) {
            $newFaction = 'NONE';
        }
    }

    // Si membre absent du serveur B ($discordRoles = []) et pas de faction actuellement → rien à faire
    if ($newFaction === '' && $currentFaction === 'NONE') {
        return ['success' => true, 'changed' => false, 'message' => 'Aucune faction Discord B — inchangé'];
    }

    // Si pas de changement détecté
    $resolvedNew = $newFaction !== '' ? $newFaction : $currentFaction;
    if ($resolvedNew === $currentFaction && $newFaction !== 'NONE') {
        return ['success' => true, 'changed' => false, 'message' => "Faction inchangée : $currentFaction"];
    }
    if ($newFaction === '') {
        return ['success' => true, 'changed' => false, 'message' => 'Aucun rôle faction trouvé sur Discord B — faction conservée'];
    }

    // Appliquer le changement
    try {
        if ($newFaction === 'NONE') {
            $db->prepare("UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL WHERE id=?")->execute([$userId]);
        } else {
            $db->prepare("UPDATE users SET faction=? WHERE id=?")->execute([$newFaction, $userId]);
        }

        // Logger
        $db->prepare("INSERT INTO faction_discord_sync_log (user_id, discord_id, username, old_faction, new_faction, discord_role_id, source)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$userId, $discordId, $username, $currentFaction, $newFaction, $matchedRoleId, $source]);

        mdt_audit_log($userId, 'DISCORD_SYNC', 'Sync faction Discord',
            "Faction : $currentFaction → $newFaction (Discord role: $matchedRoleId, source: $source)", 'user', null);

        return [
            'success'    => true,
            'changed'    => true,
            'old_faction'=> $currentFaction,
            'new_faction'=> $newFaction,
            'message'    => "Faction : $currentFaction → $newFaction",
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'changed' => false, 'message' => 'Erreur BDD: ' . $e->getMessage()];
    }
}
