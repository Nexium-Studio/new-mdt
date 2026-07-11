<?php
// ════════════════════════════════════════════════════════════════════
// config/faction_helper.php
// Helpers centralisés pour la gestion des factions et grades.
// À inclure dans config.php : require_once __DIR__ . '/faction_helper.php';
// ════════════════════════════════════════════════════════════════════

/**
 * Retourne les permissions d'un grade pour une faction.
 * Source unique : faction_grades (après migration).
 *
 * @param  string $factionKey  ex: 'PEARLS_RESORT', 'LSPD'
 * @param  string $gradeName   ex: 'PDG', 'Lieutenant II'
 * @return array  ['can_recruit'=>bool, 'can_manage_stock'=>bool,
 *                 'can_manage_finances'=>bool, 'is_owner'=>bool, 'is_ceo'=>bool]
 */
function getGradePermissions(string $factionKey, string $gradeName): array {
    static $cache = [];
    $key = $factionKey . '|' . $gradeName;

    if (isset($cache[$key])) return $cache[$key];

    $defaults = [
        'can_recruit'         => false,
        'can_manage_stock'    => false,
        'can_manage_finances' => false,
        'is_owner'            => false,
        'is_ceo'              => false,
    ];

    if (!$factionKey || $factionKey === 'NONE' || !$gradeName) {
        return $cache[$key] = $defaults;
    }

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT fg.can_recruit, fg.can_manage_stock, fg.can_manage_finances,
                   fg.is_owner, fg.is_ceo
            FROM faction_grades fg
            JOIN factions f ON f.id = fg.faction_id
            WHERE f.faction_key = ? AND fg.name = ?
            LIMIT 1
        ");
        $stmt->execute([$factionKey, $gradeName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $cache[$key] = [
                'can_recruit'         => (bool)$row['can_recruit'],
                'can_manage_stock'    => (bool)$row['can_manage_stock'],
                'can_manage_finances' => (bool)$row['can_manage_finances'],
                'is_owner'            => (bool)$row['is_owner'],
                'is_ceo'              => (bool)$row['is_ceo'],
            ];
            return $cache[$key];
        }
    } catch (Exception $e) {}

    return $cache[$key] = $defaults;
}

/**
 * Retourne le grade réel de l'utilisateur connecté depuis users.faction_grade.
 * Utilise la DB (fiable) avec fallback sur la session.
 *
 * @return string  Grade name, ex: 'PDG', 'DRH'…
 */
function getCurrentUserGrade(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT faction_grade FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $grade = $stmt->fetchColumn();
        $cached = ($grade !== false && $grade !== null) ? (string)$grade : '';
    } catch (Exception $e) {
        $cached = $_SESSION['user_faction_rank'] ?? '';
    }
    return $cached;
}



/**
 * Vérifie si l'utilisateur connecté peut effectuer une action sur un panel.
 * Combine les permissions du grade + le rôle staff.
 *
 * @param  string $permission  'can_recruit'|'can_manage_stock'|'can_manage_finances'|'is_owner'|'is_ceo'
 * @return bool
 */
function hasCompanyPermission(string $permission): bool {
    if (hasRole(['*', 'Administrateur', 'Développeur'])) return true;

    $factionKey = $_SESSION['user_faction'] ?? 'NONE';
    if (!$factionKey || $factionKey === 'NONE') return false;

    $perms = getGradePermissions($factionKey, getCurrentUserGrade());
    return $perms[$permission] ?? false;
}

/**
 * Retourne tous les grades d'une faction, triés par rang.
 *
 * @param  string $factionKey
 * @return array  [['id', 'name', 'rank_order', 'can_recruit', ...], ...]
 */
function getFactionGrades(string $factionKey): array {
    static $gradesCache = [];
    if (isset($gradesCache[$factionKey])) return $gradesCache[$factionKey];

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT fg.id, fg.name, fg.rank_order,
                   fg.can_recruit, fg.can_manage_stock, fg.can_manage_finances,
                   fg.is_owner, fg.is_ceo
            FROM faction_grades fg
            JOIN factions f ON f.id = fg.faction_id
            WHERE f.faction_key = ?
            ORDER BY fg.rank_order ASC, fg.name ASC
        ");
        $stmt->execute([$factionKey]);
        $gradesCache[$factionKey] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $gradesCache[$factionKey];
    } catch (Exception $e) {
        return $gradesCache[$factionKey] = [];
    }
}

/**
 * Recrute un utilisateur dans une faction et synchronise TOUTES les tables :
 * - users.faction + users.faction_rank + users.grade_id
 * - company_employees
 *
 * @param PDO    $db
 * @param int    $userId
 * @param string $factionKey
 * @param string $gradeName
 * @return bool
 */
function recruitUser(PDO $db, int $userId, string $factionKey, string $gradeName): bool {
    try {
        $db->beginTransaction();

        // Résoudre grade_id
        $stmt = $db->prepare("
            SELECT fg.id FROM faction_grades fg
            JOIN factions f ON f.id = fg.faction_id
            WHERE f.faction_key = ? AND fg.name = ? LIMIT 1
        ");
        $stmt->execute([$factionKey, $gradeName]);
        $gradeId = $stmt->fetchColumn() ?: null;

        // Mettre à jour users
        $db->prepare("
            UPDATE users
            SET faction = ?, faction_rank = ?, faction_grade = ?, grade_id = ?
            WHERE id = ?
        ")->execute([$factionKey, $gradeName, $gradeName, $gradeId, $userId]);

        // Upsert company_employees
        $db->prepare("
            INSERT INTO company_employees (user_id, faction, rank, hired_date, created_at)
            VALUES (?, ?, ?, CURDATE(), NOW())
            ON DUPLICATE KEY UPDATE rank = ?, hired_date = IF(hired_date IS NULL, CURDATE(), hired_date)
        ")->execute([$userId, $factionKey, $gradeName, $gradeName]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

/**
 * Licencie un utilisateur d'une faction et nettoie toutes les tables.
 */
function fireUser(PDO $db, int $userId, string $factionKey): bool {
    try {
        $db->beginTransaction();
        $db->prepare("
            UPDATE users SET faction='NONE', faction_rank=NULL, faction_grade=NULL, grade_id=NULL
            WHERE id=?
        ")->execute([$userId]);
        $db->prepare("DELETE FROM company_employees WHERE user_id=? AND faction=?")->execute([$userId, $factionKey]);
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

/**
 * Change le grade d'un employé et synchronise users + company_employees.
 */
function updateUserGrade(PDO $db, int $userId, string $factionKey, string $gradeName): bool {
    try {
        $stmt = $db->prepare("
            SELECT fg.id FROM faction_grades fg
            JOIN factions f ON f.id = fg.faction_id
            WHERE f.faction_key = ? AND fg.name = ? LIMIT 1
        ");
        $stmt->execute([$factionKey, $gradeName]);
        $gradeId = $stmt->fetchColumn() ?: null;

        $db->prepare("
            UPDATE users SET faction_rank=?, faction_grade=?, grade_id=? WHERE id=?
        ")->execute([$gradeName, $gradeName, $gradeId, $userId]);

        $db->prepare("
            UPDATE company_employees SET rank=? WHERE user_id=? AND faction=?
        ")->execute([$gradeName, $userId, $factionKey]);

        return true;
    } catch (Exception $e) {
        return false;
    }
}