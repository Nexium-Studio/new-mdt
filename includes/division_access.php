<?php
/**
 * includes/division_access.php
 *
 * Helpers partagés pour le système de panels de division.
 *
 * Règles d'accès (décidées dans la spec) :
 *   • Être membre de la faction (users.faction) ET membre de la division
 *   • Les Staff/Administrateur/Développeur passent partout (bypass)
 *   • La permission "manage" dépend du grade de division (can_manage=1)
 *     ou du grade de faction (can_manage_divisions=1 dans lspd_ranks
 *     ou faction_grades.is_owner / is_ceo)
 */

if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/../config/config.php';
}

/* ──────────────────────────────────────────────────────────────────────────
 * Helpers bas niveau
 * ──────────────────────────────────────────────────────────────────────── */

/** Récupère la connexion PDO (raccourci). */
function div_db(): PDO {
    return Database::getInstance()->getConnection();
}

/** Un utilisateur est-il "staff MDT" (bypass global) ? */
function div_is_staff_bypass(): bool {
    return hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper']);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Divisions & lecture
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Charge une division par son panel_key (ex. "DIV_LSPD_SWAT").
 * Retourne un tableau associatif enrichi : faction (name, key, color, icon).
 */
function div_get_by_panel_key(string $panelKey): ?array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT fd.*,
                   f.faction_key   AS f_key,
                   f.name          AS f_name,
                   f.color         AS f_color,
                   f.icon          AS f_icon
              FROM faction_divisions fd
              JOIN factions f ON f.id = fd.faction_id
             WHERE fd.panel_key = ?
             LIMIT 1
        ");
        $stmt->execute([$panelKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('div_get_by_panel_key: ' . $e->getMessage());
        return null;
    }
}

/** Charge une division par id. */
function div_get_by_id(int $id): ?array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT fd.*, f.faction_key AS f_key, f.name AS f_name,
                   f.color AS f_color, f.icon AS f_icon
              FROM faction_divisions fd
              JOIN factions f ON f.id = fd.faction_id
             WHERE fd.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Toutes les divisions d'une faction (par clé LSPD/LSSD/…).
 */
function div_get_all_for_faction(string $factionKey): array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT fd.*, f.faction_key AS f_key, f.color AS f_color
              FROM faction_divisions fd
              JOIN factions f ON f.id = fd.faction_id
             WHERE f.faction_key = ?
               AND fd.is_active = 1
             ORDER BY fd.sort_order ASC, fd.name ASC
        ");
        $stmt->execute([$factionKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/* ──────────────────────────────────────────────────────────────────────────
 * Membres
 * ──────────────────────────────────────────────────────────────────────── */

/** Lignes d'appartenance de l'utilisateur (toutes divisions confondues). */
function div_get_user_memberships(int $userId): array {
    if ($userId <= 0) return [];
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT m.id            AS membership_id,
                   m.division_id,
                   m.division_grade_id,
                   m.role,
                   m.joined_date,
                   fd.panel_key,
                   fd.name         AS division_name,
                   fd.abbreviation AS division_abbr,
                   fd.color        AS division_color,
                   fd.icon         AS division_icon,
                   fd.target_url,
                   fd.is_active    AS division_active,
                   fd.show_on_dashboard,
                   f.faction_key,
                   f.name          AS faction_name,
                   fdg.name        AS grade_name,
                   fdg.can_manage  AS grade_can_manage
              FROM faction_division_members m
              JOIN faction_divisions fd ON fd.id = m.division_id
              JOIN factions f           ON f.id = fd.faction_id
         LEFT JOIN faction_division_grades fdg ON fdg.id = m.division_grade_id
             WHERE m.user_id = ?
             ORDER BY f.name, fd.name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('div_get_user_memberships: ' . $e->getMessage());
        return [];
    }
}

/** Membres d'une division (avec grade + user). */
function div_get_members(int $divisionId): array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT m.*,
                   u.username,
                   u.avatar,
                   u.faction_rank,
                   u.faction,
                   u.is_active       AS user_active,
                   fdg.name          AS grade_name,
                   fdg.rank_order    AS grade_order,
                   fdg.can_manage    AS grade_can_manage
              FROM faction_division_members m
              JOIN users u ON u.id = m.user_id
         LEFT JOIN faction_division_grades fdg ON fdg.id = m.division_grade_id
             WHERE m.division_id = ?
             ORDER BY COALESCE(fdg.rank_order, 999) ASC, u.username ASC
        ");
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Grades d'une division (hiérarchie). */
function div_get_grades(int $divisionId): array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT id, division_id, name, rank_order, track_code, can_manage
              FROM faction_division_grades
             WHERE division_id = ?
             ORDER BY rank_order ASC, name ASC
        ");
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/* ──────────────────────────────────────────────────────────────────────────
 * Permissions
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Le user appartient-il à la faction de la division ?
 * (Vérifie users.faction == faction_key)
 */
function div_user_is_in_faction(int $userId, string $factionKey): bool {
    if (div_is_staff_bypass()) return true;
    $db = div_db();
    try {
        $stmt = $db->prepare("SELECT faction FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $f = (string)($stmt->fetchColumn() ?: '');
        return strcasecmp($f, $factionKey) === 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Le user a-t-il le droit d'accéder au panel de la division ?
 * - Staff MDT → oui
 * - Sinon : faction correcte + appartenance à la division
 */
function div_user_can_access(int $userId, array $division): bool {
    if (div_is_staff_bypass()) return true;
    // Mode démo : accès lecture seule à toutes les divisions
    if (function_exists('is_demo_mode') && is_demo_mode()) return true;
    if ($userId <= 0) return false;

    $factionKey = $division['f_key'] ?? '';
    if ($factionKey === '' || !div_user_is_in_faction($userId, $factionKey)) {
        return false;
    }
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT 1 FROM faction_division_members
             WHERE division_id = ? AND user_id = ? LIMIT 1
        ");
        $stmt->execute([(int)$division['id'], $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Le user peut-il gérer la division (CRUD membres, grades, annonces) ?
 *  • Staff → oui
 *  • Faction owner/ceo → oui
 *  • Grade de faction avec can_manage_divisions (LSPD lspd_ranks) → oui
 *  • Grade de division avec can_manage=1 → oui
 */
function div_user_can_manage(int $userId, array $division): bool {
    if (div_is_staff_bypass()) return true;
    if ($userId <= 0) return false;

    $factionKey = $division['f_key'] ?? '';
    if (!div_user_is_in_faction($userId, $factionKey)) return false;

    $db = div_db();

    // (A) grade de faction — is_owner / is_ceo / can_manage_finances (= chefs)
    try {
        $stmt = $db->prepare("
            SELECT fg.is_owner, fg.is_ceo, fg.can_manage_finances
              FROM users u
              JOIN factions f        ON f.faction_key = u.faction
              JOIN faction_grades fg ON fg.faction_id = f.id AND fg.name = u.faction_rank
             WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && ((int)$row['is_owner'] === 1 || (int)$row['is_ceo'] === 1)) {
            return true;
        }
    } catch (Throwable $e) {}

    // (B) Spécifique LSPD : lspd_ranks.can_manage_divisions
    if (strcasecmp($factionKey, 'LSPD') === 0) {
        try {
            $stmt = $db->prepare("
                SELECT lr.can_manage_divisions
                  FROM users u
                  JOIN lspd_ranks lr ON lr.rank_name = u.faction_rank
                 WHERE u.id = ? LIMIT 1
            ");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() === 1) return true;
        } catch (Throwable $e) {}
    }

    // (C) Grade de division avec can_manage = 1
    try {
        $stmt = $db->prepare("
            SELECT fdg.can_manage
              FROM faction_division_members m
         LEFT JOIN faction_division_grades fdg ON fdg.id = m.division_grade_id
             WHERE m.division_id = ? AND m.user_id = ?
             LIMIT 1
        ");
        $stmt->execute([(int)$division['id'], $userId]);
        if ((int)$stmt->fetchColumn() === 1) return true;
    } catch (Throwable $e) {}

    return false;
}

/* ──────────────────────────────────────────────────────────────────────────
 * Écriture / actions
 * ──────────────────────────────────────────────────────────────────────── */

function div_log(int $divisionId, ?int $userId, string $action, ?string $target = null, ?string $meta = null): void {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            INSERT INTO faction_division_logs (division_id, user_id, action, target, meta)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$divisionId, $userId ?: null, $action, $target, $meta]);
    } catch (Throwable $e) {
        error_log('div_log: ' . $e->getMessage());
    }
}

/**
 * Liste des officiers de la même faction que la division (pour l'ajout d'un
 * membre) qui ne sont PAS encore dans la division.
 */
function div_get_candidate_users(int $divisionId): array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.avatar, u.faction_rank
              FROM users u
              JOIN factions f            ON f.faction_key = u.faction
              JOIN faction_divisions fd  ON fd.faction_id = f.id
             WHERE fd.id = ?
               AND u.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM faction_division_members m
                    WHERE m.division_id = fd.id AND m.user_id = u.id
               )
             ORDER BY u.username ASC
        ");
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Retourne l'URL d'accès au panel de la division.
 */
function div_panel_url(array $division): string {
    $key = $division['panel_key'] ?? '';
    if ($key === '') return SITE_URL . '/pages/dashboard.php';
    return SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($key);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Capabilités par division
 *
 *  Déclarent quelles fonctionnalités optionnelles sont activées sur chaque
 *  division. La matrice est indexée par l'abréviation de la division
 *  (ex. "IAD", "SWAT") + faction. Ajouter une nouvelle division = ajouter
 *  son abréviation ici.
 *
 *  Capabilités supportées :
 *    • view_lspd_reports    — consultation en lecture seule des rapports LSPD
 *    • create_own_reports   — création de rapports propres à la division
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * @return array<string, array{factions: array<string>, divisions: array<string>}>
 */
function div_capability_map(): array {
    return [
        'view_lspd_reports' => [
            'factions'  => ['LSPD'],
            'divisions' => ['IAD', 'DB'],
        ],
        'create_own_reports' => [
            'factions'  => ['LSPD'],
            'divisions' => ['IAD','DB','MRD','K9','ASD','MCD','GND','SWAT','WU','MD','CNT'],
        ],
    ];
}

/**
 * La division a-t-elle la capacité $cap ?
 */
function div_has_capability(array $division, string $cap): bool {
    $map = div_capability_map();
    if (!isset($map[$cap])) return false;

    $factionKey = strtoupper((string)($division['f_key'] ?? ''));
    $abbr       = strtoupper((string)($division['abbreviation'] ?? ''));

    $entry = $map[$cap];
    if (!in_array($factionKey, array_map('strtoupper', $entry['factions']), true)) return false;
    if (!in_array($abbr,       array_map('strtoupper', $entry['divisions']), true)) return false;
    return true;
}

/* ──────────────────────────────────────────────────────────────────────────
 * Rapports de division
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Types de rapports supportés par les panels de division.
 * @return array<string, array{0:string, 1:string}>  [label, css_badge_class]
 */
function div_report_types(): array {
    return [
        'incident'  => ["Rapport d'incident",    'bg-blue'],
        'mission'   => ["Rapport de mission",    'bg-purple'],
        'enquete'   => ["Rapport d'enquête",     'bg-red'],
        'activite'  => ["Rapport d'activité",    'bg-green'],
        'formation' => ['Rapport de formation',  'bg-yellow'],
        'note'      => ['Note interne',          'bg-gray'],
        'autre'     => ['Autre',                 'bg-gray'],
    ];
}

/**
 * Statuts des rapports de division.
 * @return array<string, array{0:string, 1:string}>
 */
function div_report_statuses(): array {
    return [
        'draft'     => ['Brouillon', 'bg-gray'],
        'submitted' => ['Soumis',    'bg-blue'],
        'approved'  => ['Validé',    'bg-green'],
        'rejected'  => ['Rejeté',    'bg-red'],
        'closed'    => ['Clôturé',   'bg-gray'],
    ];
}

/**
 * Priorités des rapports de division.
 * @return array<string, array{0:string, 1:string}>
 */
function div_report_priorities(): array {
    return [
        'low'      => ['Basse',    'bg-green'],
        'normal'   => ['Normale',  'bg-blue'],
        'high'     => ['Haute',    'bg-yellow'],
        'critical' => ['Critique', 'bg-red'],
    ];
}

/**
 * Tags disponibles (globaux + spécifiques à la division).
 * @return array<int, array{label:string,color:string}>
 */
function div_report_tags(int $divisionId): array {
    $db = div_db();
    try {
        $stmt = $db->prepare("
            SELECT label, color
              FROM division_report_tags
             WHERE division_id IS NULL OR division_id = ?
             ORDER BY sort_order, id
        ");
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [
            ['label'=>'Urgent','color'=>'#ef4444'],
            ['label'=>'Confidentiel','color'=>'#a855f7'],
            ['label'=>'Validé','color'=>'#22c55e'],
        ];
    }
}
