<?php
/**
 * Journal d’audit global (table `logs`) — insertion centralisée.
 *
 * Catégories conseillées : auth, user, faction, division, security, billing,
 * vehicle, panel, system, error, other
 */

declare(strict_types=1);

if (!function_exists('mdt_client_ip')) {
    function mdt_client_ip(): ?string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            if (($parts[0] ?? '') !== '') {
                return substr($parts[0], 0, 45);
            }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}

if (!function_exists('mdt_audit_log_schema')) {
    /**
     * @return array{category:bool,target_faction:bool}
     */
    function mdt_audit_log_schema(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = ['category' => false, 'target_faction' => false];
        try {
            $st = $db->prepare("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs'
                  AND COLUMN_NAME IN ('category','target_faction')
            ");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
                if ($col === 'category') {
                    $cache['category'] = true;
                }
                if ($col === 'target_faction') {
                    $cache['target_faction'] = true;
                }
            }
        } catch (Throwable $e) {
            // schéma inconnu : insert minimal
        }
        return $cache;
    }
}

if (!function_exists('mdt_audit_log')) {
    /**
     * @param int|null    $userId        Auteur de l’action (NULL si anonyme / échec login)
     * @param string      $panel         Zone : SYSTEM, STAFF, AUTH, PROFILE, ADMIN, LSPD, …
     * @param string      $action        Libellé court (ex. Connexion, Modification utilisateur)
     * @param string|null $description   Détail lisible humain
     * @param string|null $category      Filtre métier (auth, user, faction, …) si colonne présente
     * @param string|null $targetFaction Clé faction concernée si colonne présente
     */
    function mdt_audit_log(
        ?int $userId,
        string $panel,
        string $action,
        ?string $description = null,
        ?string $category = null,
        ?string $targetFaction = null
    ): void {
        try {
            $db = Database::getInstance()->getConnection();
            $panel = substr(trim($panel), 0, 64);
            if ($panel === '') {
                $panel = 'SYSTEM';
            }
            $action = substr(trim($action), 0, 255);
            if ($action === '') {
                $action = 'Action';
            }
            $desc = $description !== null ? mb_substr($description, 0, 65000) : null;
            $cat = $category !== null ? substr(trim($category), 0, 32) : null;
            $tf = $targetFaction !== null ? substr(trim($targetFaction), 0, 64) : null;
            $ip = mdt_client_ip();

            $sch = mdt_audit_log_schema($db);
            $fields = ['user_id', 'panel'];
            $values = [$userId, $panel];
            if ($sch['category']) {
                $fields[] = 'category';
                $values[] = $cat;
            }
            if ($sch['target_faction']) {
                $fields[] = 'target_faction';
                $values[] = $tf;
            }
            $fields[] = 'action';
            $fields[] = 'description';
            $fields[] = 'ip_address';
            $values[] = $action;
            $values[] = $desc;
            $values[] = $ip;

            $quoted = array_map(static fn ($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
            $sql = 'INSERT INTO logs (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
            $db->prepare($sql)->execute($values);
        } catch (Throwable $e) {
            error_log('mdt_audit_log: ' . $e->getMessage());
        }
    }
}
