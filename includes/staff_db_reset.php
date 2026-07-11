<?php
/**
 * Réinitialisation des données (hors code pénal + comptes admin conservés).
 * Appelé uniquement depuis le staff avec contrôle de rôle.
 */
declare(strict_types=1);

/**
 * Rôles utilisateur conservés (accès complet type administrateur).
 */
function mdt_db_reset_preserved_roles(): array {
    return ['*', 'Administrateur', 'Développeur'];
}

/**
 * Tables dont on ne fait jamais TRUNCATE (données métier à garder).
 */
function mdt_db_reset_preserved_tables(): array {
    return ['penal_code'];
}

/**
 * Vide toutes les tables sauf penal_code, et supprime les utilisateurs dont le rôle n’est pas préservé.
 *
 * @return array{0:bool,1:string}
 */
function mdt_perform_database_data_reset(PDO $db): array {
    $preserveRoles = mdt_db_reset_preserved_roles();
    $skipTables    = mdt_db_reset_preserved_tables();

    $dbName = $db->query('SELECT DATABASE()')->fetchColumn();
    if (!$dbName || !is_string($dbName)) {
        return [false, 'Base de données introuvable.'];
    }

    $st = $db->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?'
    );
    $st->execute([$dbName, 'BASE TABLE']);
    $tables = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) {
        return [false, 'Aucune table listée.'];
    }

    sort($tables);

    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) {
            if (!is_string($table)) {
                continue;
            }
            if (in_array($table, $skipTables, true)) {
                continue;
            }
            if ($table === 'users') {
                continue;
            }
            $safe = '`' . str_replace('`', '``', $table) . '`';
            $db->exec('TRUNCATE TABLE ' . $safe);
        }

        $ph = implode(',', array_fill(0, count($preserveRoles), '?'));
        $del = $db->prepare("DELETE FROM users WHERE role NOT IN ($ph)");
        $del->execute($preserveRoles);
    } catch (Throwable $e) {
        return [false, 'Erreur : ' . $e->getMessage()];
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return [true, 'Données effacées. La table penal_code est conservée. Seuls les comptes avec rôle : ' . implode(', ', $preserveRoles) . ' sont conservés.'];
}
