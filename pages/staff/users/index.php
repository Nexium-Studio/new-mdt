<?php
// pages/staff/users/index.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) redirect(SITE_URL . '/pages/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Utilisateurs';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => ''], ['label' => 'Gestion']];
$success = $error = '';

// ── Multi-personnages ──
require_once ROOT_PATH . '/includes/characters.php';
mdt_characters_ensure_schema($db);
$canEditProfils = mdt_staff_can($db, 'staff.users.edit_profils');

/** Colonnes optionnelles (schémas différents selon migrations). */
function mdt_users_schema_has_column(PDO $db, string $column): bool {
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    try {
        $st = $db->prepare('
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $st->execute(['users', $column]);
        $cache[$column] = ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

$usersHasSteamColumn = mdt_users_schema_has_column($db, 'steam_id');

/** Clés factions « Service public » (ordre d'affichage dans l'UI). */
const MDT_SERVICE_PUBLIC_FACTION_KEYS = [
    'GOUV', 'AVOCATS', 'DOJ', 'USSS', 'LSPD', 'LSSD', 'SAMS', 'GRUPP6',
    'MARSHALS', 'SUPERIOR_COURT_SA', 'LSFD', 'IRS_TREASURY',
];

/** Catégorie d'affichage staff (factions) : illégale → staff → service public → entreprises. */
function mdt_staff_faction_ui_category(string $factionKey, ?string $dbFactionType): string {
    static $illegal = null;
    if ($illegal === null) {
        $illegal = defined('DARKNET_ALLOWED_FACTION_KEYS') ? DARKNET_ALLOWED_FACTION_KEYS : [];
    }
    if (in_array($factionKey, $illegal, true)) {
        return 'illegal';
    }
    if ($factionKey === 'STAFF') {
        return 'staff';
    }
    if (in_array($factionKey, MDT_SERVICE_PUBLIC_FACTION_KEYS, true)) {
        return 'service_public';
    }
    $t = $dbFactionType ?: 'company';
    if ($t === 'staff') {
        return 'staff';
    }
    if (in_array($t, ['law_enforcement', 'medical', 'legal', 'government'], true)) {
        return 'service_public';
    }
    return 'company';
}

/**
 * Fusionne une liste de grades {name, rank, id?, is_owner?, is_ceo?} sans doublon de nom.
 *
 * @param array<string, list<array<string,mixed>>> $map
 * @param list<array<string,mixed>>                 $entries
 */
function mdt_merge_grades_for_faction(array &$map, string $factionKey, array $entries): void {
    if (!isset($map[$factionKey])) {
        $map[$factionKey] = [];
    }
    foreach ($entries as $entry) {
        $name = (string) ($entry['name'] ?? '');
        if ($name === '') {
            continue;
        }
        foreach ($map[$factionKey] as $g) {
            if (($g['name'] ?? '') === $name) {
                continue 2;
            }
        }
        $map[$factionKey][] = [
            'id'       => $entry['id'] ?? null,
            'name'     => $name,
            'rank'     => (int) ($entry['rank'] ?? 99),
            'is_owner' => (bool) ($entry['is_owner'] ?? false),
            'is_ceo'   => (bool) ($entry['is_ceo'] ?? false),
        ];
    }
}

$editorId              = (int)($_SESSION['user_id'] ?? 0);
$editorRole            = $_SESSION['user_role'] ?? 'Citoyen';
$canEditGlobalUserRole = mdt_can_edit_global_user_role($editorRole);

/**
 * Retourne le niveau hiérarchique d'un rôle en consultant d'abord mdt_roles,
 * puis la table statique de config.php comme fallback.
 * Permet aux rôles créés manuellement d'être correctement comparés.
 */
function mdt_effective_role_rank(PDO $db, string $role): int {
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];
    try {
        $s = $db->prepare("SELECT hierarchy_level FROM mdt_roles WHERE name = ? LIMIT 1");
        $s->execute([$role]);
        $lvl = $s->fetchColumn();
        if ($lvl !== false) { $cache[$role] = (int)$lvl; return (int)$lvl; }
    } catch (Throwable $e) {}
    // Fallback hardcodé pour les rôles système
    $rank = mdt_role_hierarchy_rank($role);
    $cache[$role] = $rank;
    return $rank;
}

$editorRank    = mdt_effective_role_rank($db, $editorRole);
// Super Admin (*) et Administrateur ont les pleins pouvoirs — aucune restriction
$isFullAdmin   = in_array($editorRole, ['*', 'Administrateur'], true);
$editorFaction = $_SESSION['user_faction'] ?? 'NONE';
$editorGradeMaxRank  = null;
try {
    $egStmt = $db->prepare("
        SELECT fg.rank_order
        FROM users u
        INNER JOIN factions f ON f.faction_key = u.faction
        INNER JOIN faction_grades fg ON fg.faction_id = f.id AND fg.id = u.grade_id
        WHERE u.id = ? AND u.faction IS NOT NULL AND u.faction != '' AND u.faction != 'NONE'
        LIMIT 1
    ");
    $egStmt->execute([$editorId]);
    $egr = $egStmt->fetchColumn();
    if ($egr !== false && $egr !== null) {
        $editorGradeMaxRank = (int)$egr;
    }
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════════
// FACTIONS REGISTRY — Source : table factions en DB
// Les factions hardcodées servent de fallback si la DB est vide
// ══════════════════════════════════════════════════════════════════════
$enumFactions = [
    'STAFF'          => ['name' => 'Staff',           'color' => '#a855f7', 'icon' => 'shield-check'],
    'DOJ'            => ['name' => 'DOJ',             'color' => '#ef4444', 'icon' => 'scale-balanced'],
    'USSS'           => ['name' => 'USSS',            'color' => '#334155', 'icon' => 'user-police'],
    'LSPD'           => ['name' => 'LSPD',            'color' => '#2563eb', 'icon' => 'shield-halved'],
    'LSSD'           => ['name' => 'LSSD',            'color' => '#10b981', 'icon' => 'star'],
    'SAMS'           => ['name' => 'SAMS',            'color' => '#f43f5e', 'icon' => 'kit-medical'],
    'GRUPP6'         => ['name' => 'Grupp 6',         'color' => '#64748b', 'icon' => 'truck-shield'],
    'GOUV'           => ['name' => 'Gouvernement',    'color' => '#1d4ed8', 'icon' => 'landmark'],
    'AVOCATS'        => ['name' => "Cabinet d'Avocats",'color'=> '#d97706', 'icon' => 'scale-balanced'],
    'HAUTE_LUXURY'   => ['name' => 'Haute Luxury',    'color' => '#f472b6', 'icon' => 'gem'],
    'BAHAMAS'        => ['name' => 'Bahamas',         'color' => '#06b6d4', 'icon' => 'martini-glass-citrus'],
    'BENNYS'         => ["name" => "Benny's",         'color' => '#f59e0b', 'icon' => 'screwdrivers-wrench'],
    'LS_CUSTOM'      => ['name' => 'LS Custom',       'color' => '#8b5cf6', 'icon' => 'car-wrench'],
    'LS_MOTORS'      => ['name' => 'LS Motors',       'color' => '#3b82f6', 'icon' => 'car-rear'],
    'HEAVY_IMPOUND'  => ['name' => 'Heavy Impound',   'color' => '#94a3b8', 'icon' => 'truck-ramp-box'],
    'DYNASTY'        => ['name' => 'Dynasty',         'color' => '#22c55e', 'icon' => 'city'],
    'LTD'            => ['name' => 'LTD Mirror Park', 'color' => '#0ea5e9', 'icon' => 'basket-shopping'],
    'AMMUNATION'     => ['name' => 'Ammunation',      'color' => '#ef4444', 'icon' => 'gun'],
    'LIFE_INVADER'   => ['name' => 'Life Invader',    'color' => '#6366f1', 'icon' => 'clapperboard'],
    'POSTOP'         => ['name' => 'PostOP',          'color' => '#10b981', 'icon' => 'truck-fast'],
    'TAXI'           => ['name' => 'Taxi',            'color' => '#eab308', 'icon' => 'taxi'],
    'TRIADS'         => ['name' => 'Triads Records',  'color' => '#a855f7', 'icon' => 'compact-disc'],
    'UWU_COFFEE'     => ['name' => 'UwU Coffee',      'color' => '#f472b6', 'icon' => 'mug-hot'],
    'RESTAURANT_KOI' => ['name' => 'Restaurant KOI',  'color' => '#f87171', 'icon' => 'shrimp'],
    'UP_ATOM'        => ['name' => 'UP & Atom',       'color' => '#fbbf24', 'icon' => 'burger'],
    'PEARLS_RESORT'  => ["name" => "Pearl's Resort",  'color' => '#d4a853', 'icon' => 'hotel'],
];

// ── Enrichir / écraser depuis la table `factions` en DB (source de vérité) ──
try {
    try {
        $dbFactions = $db->query('
            SELECT faction_key, name, color, icon, faction_type
            FROM factions
            ORDER BY name ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $dbFactions = $db->query('
            SELECT faction_key, name, color, icon
            FROM factions
            ORDER BY name ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($dbFactions as $row) {
        $enumFactions[$row['faction_key']] = [
            'name'           => $row['name'],
            'color'          => $row['color'],
            'icon'           => $row['icon'],
            'faction_type'   => $row['faction_type'] ?? 'company',
        ];
    }
} catch (Exception $e) {
}

$mdtFallbackFactionTypes = [
    'STAFF'           => 'staff',
    'DOJ'             => 'legal',
    'USSS'            => 'law_enforcement',
    'LSPD'            => 'law_enforcement',
    'LSSD'            => 'law_enforcement',
    'SAMS'            => 'medical',
    'GRUPP6'          => 'law_enforcement',
    'GOUV'            => 'government',
    'AVOCATS'         => 'legal',
    'HAUTE_LUXURY'    => 'company',
    'BAHAMAS'         => 'company',
    'BENNYS'          => 'company',
    'LS_CUSTOM'       => 'company',
    'LS_MOTORS'       => 'company',
    'HEAVY_IMPOUND'   => 'company',
    'DYNASTY'         => 'company',
    'LTD'             => 'company',
    'AMMUNATION'      => 'company',
    'LIFE_INVADER'    => 'company',
    'POSTOP'          => 'company',
    'TAXI'            => 'company',
    'TRIADS'          => 'company',
    'UWU_COFFEE'      => 'company',
    'RESTAURANT_KOI' => 'company',
    'UP_ATOM'         => 'company',
    'PEARLS_RESORT'   => 'company',
];

foreach ($enumFactions as $fk => &$mdtFactionRow) {
    $dbt = $mdtFactionRow['faction_type'] ?? ($mdtFallbackFactionTypes[$fk] ?? 'company');
    $mdtFactionRow['category'] = mdt_staff_faction_ui_category($fk, $dbt);
    unset($mdtFactionRow['faction_type']);
}
unset($mdtFactionRow);

$factionCategoryOrder = [
    'staff'           => 'Staff & technique',
    'service_public'  => 'Service public',
    'company'         => 'Entreprises & commerces',
    'other'           => 'Autres',
    'illegal'         => 'Illégale',
];

// ── Factions dans users non référencées ailleurs ──
try {
    $userFactions = $db->query("
        SELECT DISTINCT faction FROM users
        WHERE faction IS NOT NULL AND faction NOT IN ('NONE','classic','glass')
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userFactions as $fk) {
        if (!isset($enumFactions[$fk])) {
            $enumFactions[$fk] = ['name' => $fk, 'color' => '#64748b', 'icon' => 'shield'];
        }
    }
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════════
// GRADES MAP — Source UNIQUE : faction_grades (après migration)
// Plus besoin des maps hardcodées ni des company_ranks séparés
// ══════════════════════════════════════════════════════════════════════
$allGradesMap = [];
try {
    $gradeRows = $db->query("
        SELECT f.faction_key, fg.id AS grade_id, fg.name AS grade_name, fg.rank_order,
               fg.can_recruit, fg.can_manage_stock, fg.can_manage_finances, fg.is_owner, fg.is_ceo
        FROM faction_grades fg
        JOIN factions f ON f.id = fg.faction_id
        ORDER BY f.faction_key, fg.rank_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gradeRows as $r) {
        $fk = (string) $r['faction_key'];
        $allGradesMap[$fk][] = [
            'id'       => $r['grade_id'],
            'name'     => (string) $r['grade_name'],
            'rank'     => (int) $r['rank_order'],
            'is_owner' => (bool) $r['is_owner'],
            'is_ceo'   => (bool) $r['is_ceo'],
        ];
    }
} catch (Exception $e) {
    $allGradesMap = [];
}

// ── Compléter avec company_ranks (anciennes configs / doublons évités) ──
try {
    $crRows = $db->query('
        SELECT faction, rank_name, rank_level
        FROM company_ranks
        ORDER BY faction, rank_level ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($crRows as $r) {
        mdt_merge_grades_for_faction($allGradesMap, (string) $r['faction'], [[
            'id'       => null,
            'name'     => (string) $r['rank_name'],
            'rank'     => (int) $r['rank_level'],
            'is_owner' => false,
            'is_ceo'   => false,
        ]]);
    }
} catch (Exception $e) {
}

// ── Référentiel mdt_ref (DOJ, GOUV, entreprises…) si tables présentes ──
try {
    $refStmt = $db->query("
        SELECT
            COALESCE(NULLIF(TRIM(rf.legacy_faction_key), ''), rf.faction_key) AS fk,
            mfg.grade_name AS rank_name,
            mfg.rank_order AS rank_level
        FROM mdt_ref_faction_grade mfg
        INNER JOIN mdt_ref_faction rf ON rf.id = mfg.faction_id
        ORDER BY fk ASC, mfg.rank_order ASC
    ");
    foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (string) $row['fk'];
        if ($fk === '') {
            continue;
        }
        mdt_merge_grades_for_faction($allGradesMap, $fk, [[
            'id'       => null,
            'name'     => (string) $row['rank_name'],
            'rank'     => (int) $row['rank_level'],
            'is_owner' => false,
            'is_ceo'   => false,
        ]]);
    }
} catch (Exception $e) {
}

foreach ($allGradesMap as &$mdtGradeList) {
    usort($mdtGradeList, static function ($a, $b) {
        return ($a['rank'] ?? 99) <=> ($b['rank'] ?? 99);
    });
}
unset($mdtGradeList);

if ($allGradesMap === []) {
    try {
        $rankRows = $db->query('SELECT faction, rank_name, rank_level FROM company_ranks ORDER BY faction, rank_level ASC')->fetchAll();
        foreach ($rankRows as $r) {
            mdt_merge_grades_for_faction($allGradesMap, (string) $r['faction'], [[
                'id'       => null,
                'name'     => (string) $r['rank_name'],
                'rank'     => (int) $r['rank_level'],
                'is_owner' => false,
                'is_ceo'   => false,
            ]]);
        }
    } catch (Exception $e2) {
    }
}

// ══════════════════════════════════════════════════════════════════════
// AJAX : personnages d'un utilisateur (GET) — avant toute sortie HTML
// ══════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_user_characters') {
    header('Content-Type: application/json');
    $targetId = (int) ($_GET['user_id'] ?? 0);
    $activeCharId = 0;
    try {
        $st = $db->prepare('SELECT active_character_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$targetId]);
        $activeCharId = (int) $st->fetchColumn();
    } catch (Exception $e) {}
    echo json_encode([
        'characters'          => $targetId > 0 ? mdt_get_characters($db, $targetId) : [],
        'active_character_id' => $activeCharId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
// ACTIONS POST
// ══════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'toggle_active' && $userId) {
        $tRole = $db->prepare('SELECT role FROM users WHERE id = ?');
        $tRole->execute([$userId]);
        $tr = $tRole->fetchColumn();
        if (!$isFullAdmin && $tr !== false && mdt_effective_role_rank($db, (string)$tr) > $editorRank) {
            $error = 'Vous ne pouvez pas modifier le statut d\'un compte avec un rôle supérieur au vôtre.';
        } else {
            $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$userId]);
            mdt_audit_log((int) $editorId, 'STAFF', 'Statut compte', 'Utilisateur ID ' . $userId . ' — is_active inversé', 'user', null);
            $success = 'Statut mis à jour.';
        }
    }

    if ($action === 'update_user' && $userId) {
        // La faction, le grade et l'identité RP se gèrent désormais par personnage
        // (fiche personnage — voir update_character_admin).
        $role       = $_POST['role']       ?? '';
        $username   = trim($_POST['username']   ?? '');
        $email      = trim($_POST['email']      ?? '');
        $discord_id = trim($_POST['discord_id'] ?? '');
        $steamId    = trim($_POST['steam_id'] ?? '');

        $errors = [];
        $tStmt = $db->prepare('SELECT id, role FROM users WHERE id = ?');
        $tStmt->execute([$userId]);
        $targetUser = $tStmt->fetch(PDO::FETCH_ASSOC);

        $isSelfEdit = ($userId === $editorId); // Peut toujours modifier son propre profil
        if (!$targetUser) {
            $errors[] = 'Utilisateur introuvable.';
        } elseif (!$isFullAdmin && !$isSelfEdit && mdt_effective_role_rank($db, $targetUser['role']) > $editorRank) {
            $errors[] = 'Vous ne pouvez pas modifier un compte avec un rôle supérieur au vôtre.';
        } else {
            if (!mdt_can_edit_global_user_role($editorRole)) {
                $role = (string) $targetUser['role'];
            }
            // Full admin : peut attribuer n'importe quel rôle sauf * sans être lui-même *
            if (!$isFullAdmin && mdt_effective_role_rank($db, $role) > $editorRank) {
                $errors[] = 'Vous ne pouvez pas attribuer un rôle supérieur au vôtre.';
            }
            if ($role === '*' && $editorRole !== '*') {
                $errors[] = 'Seul un super-admin peut attribuer le rôle Super Admin.';
            }
        }

        if (!$username) {
            $errors[] = "Le nom d'utilisateur est requis.";
        }

        if ($username && empty($errors)) {
            $chk = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $chk->execute([$username, $userId]);
            if ($chk->fetchColumn()) {
                $errors[] = "Ce nom d'utilisateur est déjà pris.";
            }
        }

        if ($errors) {
            $error = implode(' ', $errors);
        } else {
            $updateUserSql = 'UPDATE users SET username = ?, email = ?, discord_id = ?, role = ?';
            $updateParams = [$username, $email, $discord_id, $role];
            if ($usersHasSteamColumn) {
                $updateUserSql .= ', steam_id = ?';
                $updateParams[] = $steamId;
            }
            $updateUserSql .= ' WHERE id = ?';
            $updateParams[] = $userId;
            $db->prepare($updateUserSql)->execute($updateParams);

            mdt_audit_log(
                (int) $editorId,
                'STAFF',
                'Modification utilisateur',
                'Utilisateur ID ' . $userId . ' mis à jour (compte/rôle)',
                'user',
                null
            );
            $success = 'Utilisateur mis à jour.';
        }
    }

    // ── Fiche personnage (identité + faction/grade si permission) ────────
    if ($action === 'update_character_admin') {
        $charId = (int) ($_POST['character_id'] ?? 0);
        $char   = $charId > 0 ? mdt_get_character($db, $charId) : null;

        $errors = [];
        $owner  = null;
        if (!$char || !empty($char['is_deleted'])) {
            $errors[] = 'Personnage introuvable.';
        } else {
            $oStmt = $db->prepare('SELECT id, role FROM users WHERE id = ?');
            $oStmt->execute([(int) $char['user_id']]);
            $owner = $oStmt->fetch(PDO::FETCH_ASSOC);
            $isSelfEdit = ((int) $char['user_id'] === $editorId);
            if (!$owner) {
                $errors[] = 'Utilisateur introuvable.';
            } elseif (!$isFullAdmin && !$isSelfEdit && mdt_effective_role_rank($db, $owner['role']) > $editorRank) {
                $errors[] = 'Vous ne pouvez pas modifier un personnage d\'un compte avec un rôle supérieur au vôtre.';
            }
        }

        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname'] ?? '');
        if (!$errors && ($firstname === '' || $lastname === '')) {
            $errors[] = 'Le nom et le prénom du personnage sont requis.';
        }

        $data = [
            'firstname' => $firstname,
            'lastname'  => $lastname,
            'height_cm' => trim($_POST['height_cm'] ?? ''),
            'age'       => trim($_POST['age'] ?? ''),
        ];

        if (!$errors && $canEditProfils) {
            $faction = $_POST['faction'] ?? 'NONE';
            $grade   = trim($_POST['grade'] ?? '');

            // Un gradé ne peut pas attribuer un grade supérieur au sien dans SA faction
            $prevGradeName = trim((string) ($char['faction_rank'] ?: $char['faction_grade'] ?: ''));
            $gradeChanging = ($grade !== $prevGradeName);
            if ($gradeChanging && $faction === $editorFaction && $editorFaction !== 'NONE' && $editorGradeMaxRank !== null && $grade !== '') {
                try {
                    $gRankStmt = $db->prepare("
                        SELECT fg.rank_order FROM faction_grades fg
                        JOIN factions f ON f.id = fg.faction_id
                        WHERE f.faction_key = ? AND fg.name = ? LIMIT 1
                    ");
                    $gRankStmt->execute([$faction, $grade]);
                    $newRank = $gRankStmt->fetchColumn();
                    if ($newRank !== false && (int) $newRank > $editorGradeMaxRank) {
                        $errors[] = 'Vous ne pouvez pas attribuer un grade de faction supérieur au vôtre pour cette organisation.';
                    }
                } catch (Exception $e) {}
            }

            if (!$errors) {
                // Résoudre grade_id depuis faction_grades
                $gradeId = null;
                if ($faction !== 'NONE' && $grade !== '') {
                    try {
                        $gStmt = $db->prepare("
                            SELECT fg.id FROM faction_grades fg
                            JOIN factions f ON f.id = fg.faction_id
                            WHERE f.faction_key = ? AND fg.name = ? LIMIT 1
                        ");
                        $gStmt->execute([$faction, $grade]);
                        $gradeId = $gStmt->fetchColumn() ?: null;
                    } catch (Exception $e) {}
                }
                $data['faction']       = $faction;
                $data['faction_rank']  = $grade;
                $data['faction_grade'] = $grade;
                $data['grade_id']      = $gradeId;
            }
        }

        if ($errors) {
            $error = implode(' ', $errors);
        } elseif (mdt_update_character($db, $charId, $data, $canEditProfils)) {
            mdt_audit_log(
                (int) $editorId,
                'STAFF',
                'Modification personnage',
                'Personnage #' . $charId . ' (utilisateur ID ' . (int) $char['user_id'] . ') mis à jour' . ($canEditProfils ? ' (identité/faction/grade)' : ' (identité)'),
                'character',
                ($canEditProfils && !empty($data['faction']) && $data['faction'] !== 'NONE') ? $data['faction'] : null
            );
            $success = 'Personnage mis à jour.';
        } else {
            $error = 'Erreur lors de la mise à jour du personnage.';
        }
    }

    // ── Limites de personnages par rôle ───────────────────────────────────
    if ($action === 'save_character_limits' && hasRole(['*', 'Administrateur', 'Développeur'])) {
        $limits = $_POST['limits'] ?? [];
        if (is_array($limits) && $limits !== []) {
            try {
                $upd = $db->prepare('UPDATE mdt_roles SET max_characters = ? WHERE name = ?');
                foreach ($limits as $roleName => $val) {
                    $upd->execute([max(0, min(50, (int) $val)), (string) $roleName]);
                }
                mdt_audit_log((int) $editorId, 'STAFF', 'Config personnages', 'Limites de personnages par rôle mises à jour', 'mdt_roles', null);
                $success = 'Limites de personnages mises à jour.';
            } catch (Exception $e) {
                $error = 'Erreur lors de la mise à jour des limites.';
            }
        }
    }

    if ($action === 'delete' && $userId) {
        $tRole = $db->prepare('SELECT role FROM users WHERE id = ?');
        $tRole->execute([$userId]);
        $tr = $tRole->fetchColumn();
        if ($tr !== false && mdt_role_hierarchy_rank((string)$tr) > $editorRank) {
            $error = 'Vous ne pouvez pas supprimer un compte avec un rôle supérieur au vôtre.';
        } else {
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    // Pas de cascade (FK checks désactivés) : purger les personnages explicitement
                    $db->prepare('DELETE FROM characters WHERE user_id = ?')->execute([$userId]);
                    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                    mdt_audit_log((int) $editorId, 'STAFF', 'Suppression utilisateur', 'Utilisateur ID ' . $userId . ' supprimé définitivement (avec ses personnages)', 'user', null);
                    $success = 'Utilisateur supprimé.';
                } finally {
                    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
                }
            } catch (Exception $e) {
                $error = 'Erreur lors de la suppression : ' . $e->getMessage();
            }
        }
    }

    // ── Purge : supprimer tous les comptes NON staff ──────────────────────
    // Réservé exclusivement aux rôles : *, Administrateur, Développeur
    if ($action === 'purge_citizens' && hasRole(['*', 'Administrateur', 'Développeur'])) {
        $staffRoles = ['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'];
        $phs        = implode(',', array_fill(0, count($staffRoles), '?'));
        try {
            // Compter avant pour le message
            $countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role NOT IN ($phs)");
            $countStmt->execute($staffRoles);
            $purgedCount = (int)$countStmt->fetchColumn();

            // Désactiver les FK le temps de la suppression,
            // puis les réactiver quoi qu'il arrive (finally)
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            try {
                // Pas de cascade (FK checks désactivés) : purger les personnages explicitement
                $db->prepare("DELETE c FROM characters c JOIN users u ON u.id = c.user_id WHERE u.role NOT IN ($phs)")->execute($staffRoles);
                $db->prepare("DELETE FROM users WHERE role NOT IN ($phs)")->execute($staffRoles);
                mdt_audit_log((int) $editorId, 'STAFF', 'Purge citoyens', $purgedCount . ' compte(s) non-staff supprimés (avec leurs personnages)', 'user', null);
                $success = "$purgedCount compte(s) non-staff supprimé(s) définitivement.";
            } finally {
                $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } catch (Exception $e) {
            $error = 'Erreur lors de la purge : ' . $e->getMessage();
        }
    }

}

// ── Rôles + limites de personnages (modale de configuration) ──────────
$mdtRolesLimits = [];
try {
    $mdtRolesLimits = $db->query("
        SELECT name, display_name, color, icon, hierarchy_level, max_characters
        FROM mdt_roles
        ORDER BY hierarchy_level DESC, display_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════════
// FILTRES & PAGINATION
// ══════════════════════════════════════════════════════════════════════
$search        = trim($_GET['q']       ?? '');
$roleFilter    = $_GET['role']         ?? '';
$factionFilter = $_GET['faction']      ?? '';
$statusFilter  = $_GET['status']       ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 25;
$offset        = ($page - 1) * $perPage;

$where  = ['u.faction NOT IN ("classic","glass")'];
$params = [];
if ($search) {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR IFNULL(p.firstname,'') LIKE ? OR IFNULL(p.lastname,'') LIKE ?)";
    $like    = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($roleFilter)    { $where[] = "u.role = ?";    $params[] = $roleFilter; }
if ($factionFilter) { $where[] = "u.faction = ?"; $params[] = $factionFilter; }
if ($statusFilter === 'active')   { $where[] = "u.is_active = 1 AND u.is_banned = 0"; }
if ($statusFilter === 'inactive') { $where[] = "u.is_active = 0"; }
if ($statusFilter === 'banned')   { $where[] = "u.is_banned = 1"; }

$whereStr = 'WHERE ' . implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.id = u.profile_id $whereStr");
$totalStmt->execute($params);
$totalCount = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

$stmt = $db->prepare("
    SELECT u.*, p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    $whereStr
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Factions groupées pour la modal / filtres (ordre d'affichage)
$factionsGroupedUi = [];
foreach (array_keys($factionCategoryOrder) as $mdtCatKey) {
    $factionsGroupedUi[$mdtCatKey] = [];
}
foreach ($enumFactions as $mdtFk => $mdtFi) {
    $mdtCat = $mdtFi['category'] ?? 'other';
    if (!isset($factionsGroupedUi[$mdtCat])) {
        $mdtCat = 'other';
    }
    $factionsGroupedUi[$mdtCat][$mdtFk] = $mdtFi;
}
$mdtServicePublicOrder = array_flip(MDT_SERVICE_PUBLIC_FACTION_KEYS);

foreach ($factionsGroupedUi as $mdtGrpKey => &$mdtBucket) {
    if ($mdtGrpKey === 'service_public') {
        uksort($mdtBucket, static function ($ka, $kb) use ($mdtServicePublicOrder) {
            $ra = $mdtServicePublicOrder[$ka] ?? 500;
            $rb = $mdtServicePublicOrder[$kb] ?? 500;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcasecmp($ka, $kb);
        });
    } else {
        uasort($mdtBucket, static function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }
}
unset($mdtBucket);

// Stats globales
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(is_active = 1 AND is_banned = 0) as active,
        SUM(is_banned = 1) as banned,
        COUNT(DISTINCT CASE WHEN faction != 'NONE' THEN faction END) as factions_count
    FROM users WHERE faction NOT IN ('classic','glass')
")->fetch();

// ── Rôles dynamiques depuis mdt_roles (fallback hardcodé si table absente) ──
$roleDefs = [];
try {
    $__roleRows = $db->query("SELECT name, display_name, color, icon, hierarchy_level FROM mdt_roles ORDER BY hierarchy_level DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($__roleRows as $__rr) {
        $roleDefs[$__rr['name']] = [
            'label' => $__rr['display_name'],
            'color' => $__rr['color'] ?: '#64748b',
            'icon'  => $__rr['icon']  ?: 'user',
            'level' => (int)$__rr['hierarchy_level'],
        ];
    }
} catch (Throwable $__e) {}

// Fallback si la table mdt_roles n'existe pas encore
if (empty($roleDefs)) {
    $roleDefs = [
        '*'            => ['label'=>'Super Admin',   'color'=>'#ef4444','icon'=>'bolt',               'level'=>99],
        'Administrateur'=> ['label'=>'Administrateur','color'=>'#ef4444','icon'=>'crown',             'level'=>6],
        'Développeur'  => ['label'=>'Développeur',   'color'=>'#6366f1','icon'=>'code',               'level'=>5],
        'Staff'        => ['label'=>'Staff',          'color'=>'#a855f7','icon'=>'star',               'level'=>4],
        'Modérateur'   => ['label'=>'Modérateur',     'color'=>'#3b82f6','icon'=>'gavel',              'level'=>3],
        'Helper'       => ['label'=>'Helper',         'color'=>'#22c55e','icon'=>'headset',            'level'=>2],
        'Recruteur'    => ['label'=>'Recruteur',      'color'=>'#10b981','icon'=>'user-plus',          'level'=>2],
        'Pôle 911'     => ['label'=>'Pôle 911',       'color'=>'#ef4444','icon'=>'phone',              'level'=>1],
        'Pôle Ilégale' => ['label'=>'Pôle Ilégale',   'color'=>'#7c3aed','icon'=>'skull',              'level'=>1],
        'Pôle Légal'   => ['label'=>'Pôle Légal',     'color'=>'#1d4ed8','icon'=>'scale-balanced',     'level'=>1],
        'Citoyen'      => ['label'=>'Citoyen',        'color'=>'#64748b','icon'=>'user',               'level'=>0],
    ];
}

$roles = array_keys($roleDefs);

include __DIR__ . '/../includes/header.php';
?>
<style>
/* ════════════════════════════════════════════════════════════════ */
/* PAGE HEADER */
/* ════════════════════════════════════════════════════════════════ */
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.page-header-left h1 {
    margin: 0 0 6px 0;
    font-size: 1.75rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 12px;
    letter-spacing: -0.02em;
}
.page-header-left p { margin: 0; color: var(--text-secondary); font-size: 0.9rem; }
.page-header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

/* ════════════════════════════════════════════════════════════════ */
/* STAT CARDS */
/* ════════════════════════════════════════════════════════════════ */
.stats-bar { display: flex; gap: 14px; margin-bottom: 22px; flex-wrap: wrap; }
.stat-card {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 140px;
}
.stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.stat-num  { font-size: 1.5rem; font-weight: 800; color: var(--text); line-height: 1; }
.stat-lbl  { font-size: 0.72rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; }

/* ════════════════════════════════════════════════════════════════ */
/* FILTER BAR */
/* ════════════════════════════════════════════════════════════════ */
.filter-bar {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 320px; }
.search-wrap i {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%); color: var(--text-secondary); font-size: 0.82rem; pointer-events: none;
}
.search-wrap input {
    width: 100%; padding: 8px 12px 8px 32px;
    border: 1px solid var(--border); border-radius: 8px;
    background: var(--bg-overlay); color: var(--text); font-size: 0.88rem; box-sizing: border-box;
}
.search-wrap input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }

.filter-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.filter-chip {
    padding: 5px 13px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: var(--bg-overlay);
    color: var(--text-secondary); transition: all 0.18s; text-decoration: none; white-space: nowrap;
}
.filter-chip:hover, .filter-chip.active {
    background: var(--blue); border-color: var(--blue); color: white;
}
.filter-chip.chip-green.active  { background: #22c55e; border-color: #22c55e; }
.filter-chip.chip-red.active    { background: #ef4444; border-color: #ef4444; }
.filter-chip.chip-slate.active  { background: #64748b; border-color: #64748b; }

/* ════════════════════════════════════════════════════════════════ */
/* TABLE */
/* ════════════════════════════════════════════════════════════════ */
.users-table-wrap {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}
.users-table-wrap table { width: 100%; border-collapse: collapse; }
.users-table-wrap thead tr { background: var(--bg-overlay); border-bottom: 1px solid var(--border); }
.users-table-wrap th {
    padding: 10px 14px; text-align: left; font-size: 0.72rem; font-weight: 700;
    color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap;
}
.users-table-wrap td { padding: 11px 14px; border-bottom: 1px solid rgba(255,255,255,.03); vertical-align: middle; }
.users-table-wrap tbody tr:last-child td { border-bottom: none; }
.users-table-wrap tbody tr:hover { background: rgba(255,255,255,.015); }

/* Avatar */
.u-ava {
    width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
    background: var(--blue); color: white; font-weight: 700; font-size: 0.85rem;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.u-ava img { width: 100%; height: 100%; object-fit: cover; }

/* Badge rôle */
.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 700;
    white-space: nowrap;
}

/* Badge faction */
.faction-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 700;
    white-space: nowrap;
}

/* Status dot */
.status-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; display: inline-block;
}

/* Row actions */
.row-actions { display: flex; gap: 5px; align-items: center; }
.action-btn {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid var(--border); background: var(--bg-overlay);
    color: var(--text-secondary); cursor: pointer; display: flex;
    align-items: center; justify-content: center; font-size: 0.78rem;
    transition: all 0.15s; text-decoration: none;
}
.action-btn:hover { background: var(--bg-elevated); color: var(--text); }
.action-btn.btn-blue:hover  { background: rgba(59,130,246,.15); border-color: #3b82f6; color: #3b82f6; }
.action-btn.btn-green:hover { background: rgba(34,197,94,.15);  border-color: #22c55e; color: #22c55e; }
.action-btn.btn-red:hover   { background: rgba(239,68,68,.15);  border-color: #ef4444; color: #ef4444; }
.action-btn.btn-orange:hover{ background: rgba(249,115,22,.15); border-color: #f97316; color: #f97316; }

/* Grade tag */
.grade-tag {
    font-size: 0.72rem; color: var(--text-secondary);
    background: var(--bg-overlay); padding: 2px 7px; border-radius: 5px;
    font-family: 'DM Mono', monospace; white-space: nowrap; max-width: 130px;
    overflow: hidden; text-overflow: ellipsis; display: inline-block;
}

/* ════════════════════════════════════════════════════════════════ */
/* PAGINATION */
/* ════════════════════════════════════════════════════════════════ */
.pagination-bar {
    padding: 14px 18px; border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    font-size: 0.83rem; flex-wrap: wrap; gap: 10px;
}
.pagination-bar .info { color: var(--text-secondary); }
.pagination-pages { display: flex; gap: 5px; }
.page-btn {
    padding: 5px 11px; border-radius: 7px; font-size: 0.8rem; font-weight: 600;
    border: 1px solid var(--border); background: var(--bg-overlay); color: var(--text-secondary);
    cursor: pointer; text-decoration: none; transition: all 0.15s;
}
.page-btn:hover { background: var(--bg-elevated); color: var(--text); }
.page-btn.active { background: var(--blue); border-color: var(--blue); color: white; }

/* ════════════════════════════════════════════════════════════════ */
/* ALERTS */
/* ════════════════════════════════════════════════════════════════ */
.alert {
    padding: 12px 16px; border-radius: 10px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 10px; font-size: 0.88rem;
    animation: slideDown .3s ease-out;
}
@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.alert-success { background: rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.25); color:#22c55e; }
.alert-danger  { background: rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.25);  color:#ef4444; }

/* ════════════════════════════════════════════════════════════════ */
/* MODAL */
/* ════════════════════════════════════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
    z-index: 999; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 16px;
    box-shadow: 0 24px 64px rgba(0,0,0,.4); width: 100%; max-width: 520px;
    max-height: 92vh; overflow-y: auto; animation: modalIn .2s ease-out;
}
.modal-lg { max-width: 640px; }
@keyframes modalIn { from { opacity:0; transform:scale(.96) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }
.modal-header {
    padding: 18px 22px; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0;
    background: var(--bg-elevated); z-index: 1;
}
.modal-header h3 { margin:0; font-size:1rem; font-weight:700; display:flex; align-items:center; gap:10px; }
.modal-close {
    background: var(--bg-overlay); border: 1px solid var(--border); border-radius:7px;
    font-size:.88rem; cursor:pointer; color:var(--text-secondary);
    width:28px; height:28px; display:flex; align-items:center; justify-content:center; transition:all .15s;
}
.modal-close:hover { color:var(--text); background:var(--border); }
.modal-body { padding: 20px 22px; }
.modal-footer {
    padding: 14px 22px; border-top: 1px solid var(--border);
    display: flex; gap: 10px; justify-content: flex-end;
    position: sticky; bottom: 0; background: var(--bg-elevated);
}

/* ════════════════════════════════════════════════════════════════ */
/* FORMS */
/* ════════════════════════════════════════════════════════════════ */
.form-group { margin-bottom: 14px; }
.form-group:last-child { margin-bottom: 0; }
.form-label { display:block; font-weight:600; font-size:.78rem; color:var(--text-secondary); margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
.form-control {
    width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px;
    background: var(--bg-overlay); color: var(--text); font-size: .9rem; box-sizing: border-box; transition: border-color .15s;
}
.form-control:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-hint { font-size:.74rem; color:var(--text-muted); margin-top:4px; }

/* User info block in modal */
.user-info-block {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px; background: var(--bg-overlay);
    border: 1px solid var(--border); border-radius: 10px; margin-bottom: 20px;
}
.user-info-block .ava-lg {
    width: 48px; height: 48px; border-radius: 10px; flex-shrink: 0;
    background: var(--blue); color: white; font-weight: 800; font-size: 1.1rem;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.user-info-block .ava-lg img { width:100%; height:100%; object-fit:cover; }
.user-info-block .uname { font-weight: 700; font-size: 1rem; }
.user-info-block .uid   { font-size: .72rem; color: var(--text-muted); font-family: monospace; margin-top: 2px; }

/* Divider */
.modal-divider { border:none; border-top: 1px solid var(--border); margin: 16px 0; }

/* BTNS */
.btn {
    padding: 8px 16px; border: none; border-radius: 8px; font-weight: 600;
    font-size: .86rem; cursor: pointer; transition: all .18s;
    display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
}
.btn-primary  { background: var(--blue); color: white; }
.btn-primary:hover  { background: #2563eb; transform: translateY(-1px); }
.btn-secondary { background: var(--bg-overlay); border: 1px solid var(--border); color: var(--text); }
.btn-secondary:hover { background: var(--bg-elevated); }
.btn-danger   { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3); color: #ef4444; }
.btn-danger:hover   { background: #ef4444; color: white; }
.btn-success  { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: #22c55e; }
.btn-success:hover  { background: #22c55e; color: white; }
.btn-sm { padding: 6px 12px; font-size: .8rem; }
</style>

<?php
// Helper badge rôle
function roleBadge(string $role): string {
    $map = [
        '*'              => ['#a855f7', 'crown'],
        'Administrateur' => ['#ef4444', 'shield-halved'],
        'Développeur'    => ['#8b5cf6', 'code'],
        'Staff'          => ['#3b82f6', 'shield'],
        'Modérateur'     => ['#06b6d4', 'eye'],
        'Helper'         => ['#22c55e', 'hand-holding-heart'],
        'Recruteur'      => ['#22c55e', 'user-plus'],
        'Pôle 911'       => ['#f43f5e', 'phone'],
        'Pôle Ilégale'   => ['#f97316', 'skull'],
        'Pôle Illégale'  => ['#f97316', 'skull'],
        'Pôle Légal'     => ['#eab308', 'gavel'],
        'Citoyen'        => ['#64748b', 'user'],
    ];
    [$col, $ico] = $map[$role] ?? ['#64748b', 'user'];
    $label = htmlspecialchars($role);
    return "<span class=\"role-badge\" style=\"background:{$col}18;color:{$col};border:1px solid {$col}30;\"><i class=\"fa-solid fa-{$ico}\" style=\"font-size:.65rem;\"></i>{$label}</span>";
}
?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- PAGE HEADER -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-users" style="color:var(--blue);font-size:1.4rem;"></i> Utilisateurs</h1>
        <p><?php echo number_format($totalCount); ?> utilisateur(s) trouvé(s) &nbsp;·&nbsp; <?php echo number_format($stats['total']); ?> au total</p>
    </div>
    <div class="page-header-right">
        <a href="roles.php"     class="btn btn-secondary btn-sm"><i class="fa-solid fa-shield"></i> Rôles & Factions</a>
        <?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
        <a href="div_grad.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Division & Grade</a>
        <?php endif; ?>
        <a href="bans.php"      class="btn btn-secondary btn-sm"><i class="fa-solid fa-ban"></i> Bans</a>
        <a href="sanctions.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-gavel"></i> Sanctions</a>
        <?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
        <button type="button" onclick="openModal('modal-char-limits')" class="btn btn-secondary btn-sm" title="Nombre maximum de personnages par rôle">
            <i class="fa-solid fa-users-gear"></i> Config. personnages
        </button>
        <?php endif; ?>
        <?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
        <button type="button"
                onclick="openPurgeModal()"
                class="btn btn-sm"
                style="background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.3);font-weight:700;"
                title="Supprimer tous les comptes non-staff">
            <i class="fa-solid fa-skull"></i> Purge citoyens
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- STATS BAR -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="stats-bar">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(59,130,246,.12);color:#3b82f6;"><i class="fa-solid fa-users"></i></div>
        <div><div class="stat-num"><?php echo number_format($stats['total']); ?></div><div class="stat-lbl">Total</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(34,197,94,.12);color:#22c55e;"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="stat-num"><?php echo number_format($stats['active']); ?></div><div class="stat-lbl">Actifs</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(239,68,68,.12);color:#ef4444;"><i class="fa-solid fa-ban"></i></div>
        <div><div class="stat-num"><?php echo number_format($stats['banned']); ?></div><div class="stat-lbl">Bannis</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(168,85,247,.12);color:#a855f7;"><i class="fa-solid fa-shield"></i></div>
        <div><div class="stat-num"><?php echo number_format($stats['factions_count']); ?></div><div class="stat-lbl">Factions actives</div></div>
    </div>
</div>

<?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
<div class="users-table-wrap" style="margin-bottom:22px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
    <div>
        <h2 style="margin:0 0 4px;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-layer-group" style="color:var(--blue);"></i> Catalogue divisions & grades (mdt_ref)
        </h2>
        <p style="margin:0;font-size:.82rem;color:var(--text-secondary);max-width:720px;">
            Référentiel des unités et grades internes (LSPD, SAMS, USSS, LSSD, Grupp 6). Les panels dashboard se gèrent sous <strong>Contenu → Divisions</strong>.
        </p>
    </div>
    <a href="div_grad.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-arrow-right"></i> Ouvrir Division & Grade</a>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- FILTER BAR -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<form method="GET" class="filter-bar" id="filterForm">
    <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher un utilisateur...">
    </div>

    <!-- Filtre rôle -->
    <select name="role" class="form-control" style="width:auto;padding:7px 12px;font-size:.82rem;" onchange="document.getElementById('filterForm').submit()">
        <option value="">Tous les rôles</option>
        <?php foreach ($roles as $r): ?>
            <option value="<?php echo $r; ?>" <?php echo $roleFilter===$r?'selected':''; ?>><?php echo $r; ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Filtre faction -->
    <select name="faction" class="form-control" style="width:auto;max-width:280px;padding:7px 12px;font-size:.82rem;" onchange="document.getElementById('filterForm').submit()">
        <option value="">Toutes les factions</option>
        <option value="NONE" <?php echo $factionFilter==='NONE'?'selected':''; ?>>— Aucune faction —</option>
        <?php foreach ($factionCategoryOrder as $mdtCatKey => $mdtCatLabel): ?>
            <?php
            $mdtBucket = $factionsGroupedUi[$mdtCatKey] ?? [];
            if ($mdtBucket === []) {
                continue;
            }
            ?>
            <optgroup label="<?php echo htmlspecialchars($mdtCatLabel); ?>">
                <?php foreach ($mdtBucket as $fk => $fi): ?>
                    <option value="<?php echo htmlspecialchars($fk); ?>" <?php echo $factionFilter === $fk ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($fi['name']); ?> (<?php echo htmlspecialchars($fk); ?>)
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>

    <!-- Chips statut -->
    <div class="filter-chips">
        <a href="?q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status="
           class="filter-chip <?php echo !$statusFilter?'active':''; ?>">Tous</a>
        <a href="?q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=active"
           class="filter-chip chip-green <?php echo $statusFilter==='active'?'active':''; ?>"><i class="fa-solid fa-circle" style="font-size:.5rem;"></i> Actifs</a>
        <a href="?q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=inactive"
           class="filter-chip chip-slate <?php echo $statusFilter==='inactive'?'active':''; ?>">Inactifs</a>
        <a href="?q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=banned"
           class="filter-chip chip-red <?php echo $statusFilter==='banned'?'active':''; ?>"><i class="fa-solid fa-ban" style="font-size:.6rem;"></i> Bannis</a>
    </div>

    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtrer</button>
    <?php if ($search || $roleFilter || $factionFilter || $statusFilter): ?>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Reset</a>
    <?php endif; ?>
</form>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- TABLE -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="users-table-wrap">
    <table>
        <thead>
            <tr>
                <th>Utilisateur</th>
                <th>Email</th>
                <th>Rôle</th>
                <th>Faction</th>
                <th>Grade</th>
                <th>Statut</th>
                <th>Inscription</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-secondary);">
                <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px;"></i>
                Aucun utilisateur trouvé
            </td></tr>
            <?php else: foreach ($users as $u):
                $factionInfo = $enumFactions[$u['faction']] ?? null;
                $grade = $u['faction_rank'] ?: $u['faction_grade'] ?: '';
                $rpDisp = mdt_rp_display_name($u['profile_firstname'] ?? null, $u['profile_lastname'] ?? null, (string) $u['username']);
                $rpIni  = mdt_rp_initials($u['profile_firstname'] ?? null, $u['profile_lastname'] ?? null, (string) $u['username']);
            ?>
            <tr>
                <!-- Utilisateur -->
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="u-ava" style="<?php echo $factionInfo ? "box-shadow:0 0 0 2px {$factionInfo['color']}40;" : ''; ?>">
                            <?php if (!empty($u['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($u['avatar']); ?>" alt="" onerror="this.parentNode.innerHTML='<?php echo htmlspecialchars($rpIni, ENT_QUOTES); ?>'">
                            <?php else: ?>
                                <?php echo htmlspecialchars($rpIni); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:.875rem;"><?php echo htmlspecialchars($rpDisp); ?></div>
                            <div style="font-size:.68rem;color:var(--text-muted);">@<?php echo htmlspecialchars($u['username']); ?> · #<?php echo $u['id']; ?></div>
                        </div>
                    </div>
                </td>

                <!-- Email -->
                <td style="font-size:.8rem;color:var(--text-secondary);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($u['email'] ?: '—'); ?>
                </td>

                <!-- Rôle -->
                <td><?php echo roleBadge($u['role'] ?? 'Citoyen'); ?></td>

                <!-- Faction -->
                <td>
                    <?php if ($factionInfo && $u['faction'] !== 'NONE'): ?>
                        <span class="faction-pill" style="background:<?php echo $factionInfo['color']; ?>18;color:<?php echo $factionInfo['color']; ?>;border:1px solid <?php echo $factionInfo['color']; ?>30;">
                            <i class="fa-solid fa-<?php echo htmlspecialchars($factionInfo['icon']); ?>" style="font-size:.65rem;"></i>
                            <?php echo htmlspecialchars($factionInfo['name']); ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.78rem;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Grade -->
                <td>
                    <?php if ($grade): ?>
                        <span class="grade-tag" title="<?php echo htmlspecialchars($grade); ?>"><?php echo htmlspecialchars($grade); ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.78rem;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Statut -->
                <td>
                    <?php if ($u['is_banned'] ?? false): ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:600;color:#ef4444;">
                            <span class="status-dot" style="background:#ef4444;"></span> Banni
                        </span>
                    <?php elseif ($u['is_active']): ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:600;color:#22c55e;">
                            <span class="status-dot" style="background:#22c55e;box-shadow:0 0 6px #22c55e80;"></span> Actif
                        </span>
                    <?php else: ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:600;color:#64748b;">
                            <span class="status-dot" style="background:#64748b;"></span> Inactif
                        </span>
                    <?php endif; ?>
                </td>

                <!-- Date -->
                <td style="font-size:.76rem;color:var(--text-secondary);font-family:'DM Mono',monospace;white-space:nowrap;">
                    <?php echo date('d/m/Y', strtotime($u['created_at'])); ?>
                </td>

                <!-- Actions -->
                <td>
                    <?php
                    $isSelf = ((int)$u['id'] === $editorId);
                    $canManageRow = $isFullAdmin || $isSelf || mdt_effective_role_rank($db, $u['role'] ?? 'Citoyen') <= $editorRank;
                    ?>
                    <div class="row-actions" style="justify-content:flex-end;">
                        <?php if ($canManageRow): ?>
                        <!-- Éditer -->
                        <button class="action-btn btn-blue" title="Modifier"
                            onclick='openEditModal(<?php echo json_encode([
                                'id'                 => $u['id'],
                                'username'           => $u['username'],
                                'email'              => $u['email'] ?? '',
                                'discord_id'         => $u['discord_id'] ?? '',
                                'avatar'             => $u['avatar'] ?? '',
                                'role'               => $u['role'] ?? 'Citoyen',
                                'rp_display'         => $rpDisp,
                                'steam_id'           => $u['steam_id'] ?? '',
                                'is_active'          => (bool) $u['is_active'],
                                'is_banned'          => (bool) $u['is_banned'],
                                'created_at'         => $u['created_at'],
                                'last_login'         => $u['last_login'] ?? '',
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG); ?>)'>
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>

                        <!-- Toggle actif -->
                        <form method="POST" style="display:inline;" id="toggle-<?php echo $u['id']; ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="action-btn <?php echo $u['is_active'] ? 'btn-orange' : 'btn-green'; ?>"
                                title="<?php echo $u['is_active'] ? 'Désactiver' : 'Activer'; ?>">
                                <i class="fa-solid fa-<?php echo $u['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                            </button>
                        </form>

                        <!-- Sanctionner -->
                        <a href="sanctions.php?user_id=<?php echo $u['id']; ?>" class="action-btn" title="Sanctionner">
                            <i class="fa-solid fa-gavel"></i>
                        </a>

                        <!-- Bannir -->
                        <a href="bans.php?ban_user=<?php echo $u['id']; ?>" class="action-btn btn-red" title="Bannir">
                            <i class="fa-solid fa-ban"></i>
                        </a>

                        <!-- Supprimer -->
                        <form method="POST" style="display:inline;"
                            onsubmit="return confirm('Supprimer définitivement <?php echo addslashes($u['username']); ?> ?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="action-btn btn-red" title="Supprimer">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <span style="font-size:.72rem;color:var(--text-muted);" title="Rôle supérieur au vôtre"><i class="fa-solid fa-lock"></i></span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
        <span class="info">Page <?php echo $page; ?> / <?php echo $totalPages; ?> &nbsp;·&nbsp; <?php echo number_format($totalCount); ?> résultat(s)</span>
        <div class="pagination-pages">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=<?php echo urlencode($statusFilter); ?>" class="page-btn">← Préc.</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                <a href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=<?php echo urlencode($statusFilter); ?>"
                   class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&role=<?php echo urlencode($roleFilter); ?>&faction=<?php echo urlencode($factionFilter); ?>&status=<?php echo urlencode($statusFilter); ?>" class="page-btn">Suiv. →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL ÉDITION UTILISATEUR -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit" onclick="if(event.target===this)closeModal('modal-edit')">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color:var(--blue);"></i> Modifier l'utilisateur</h3>
            <button class="modal-close" onclick="closeModal('modal-edit')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="editForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit-user-id">

                <!-- Avatar + statut + infos lecture seule -->
                <div class="user-info-block">
                    <div class="ava-lg" id="edit-ava">?</div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span id="edit-status-badge"></span>
                        </div>
                        <div style="display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;">
                            <div style="font-size:.72rem;color:var(--text-muted);">
                                <i class="fa-solid fa-clock" style="margin-right:4px;opacity:.6;"></i>
                                Inscription : <span id="edit-created-at" style="color:var(--text-secondary);font-family:'DM Mono',monospace;"></span>
                            </div>
                            <div style="font-size:.72rem;color:var(--text-muted);" id="edit-last-login-wrap">
                                <i class="fa-solid fa-right-to-bracket" style="margin-right:4px;opacity:.6;"></i>
                                Dernière connexion : <span id="edit-last-login" style="color:var(--text-secondary);font-family:'DM Mono',monospace;"></span>
                            </div>
                        </div>
                    </div>
                    <!-- ID non modifiable -->
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">ID</div>
                        <div id="edit-uid-badge" style="font-family:'DM Mono',monospace;font-size:.88rem;font-weight:700;color:var(--text);background:var(--bg-overlay);border:1px solid var(--border);border-radius:7px;padding:4px 10px;"></div>
                    </div>
                </div>

                <hr class="modal-divider">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">
                    <i class="fa-solid fa-user" style="margin-right:6px;color:var(--blue);"></i>Informations générales
                </div>

                <!-- Nom + Email -->
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-user" style="font-size:.7rem;margin-right:4px;color:var(--blue);"></i> Nom d'utilisateur</label>
                        <input type="text" name="username" id="edit-input-username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-envelope" style="font-size:.7rem;margin-right:4px;color:var(--blue);"></i> Email</label>
                        <input type="email" name="email" id="edit-input-email" class="form-control">
                    </div>
                </div>

                <!-- Discord + Steam -->
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label"><i class="fa-brands fa-discord" style="font-size:.7rem;margin-right:4px;color:#5865f2;"></i> Discord ID</label>
                        <input type="text" name="discord_id" id="edit-input-discord" class="form-control" placeholder="123456789012345678">
                    </div>
                    <?php if ($usersHasSteamColumn): ?>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-brands fa-steam" style="font-size:.7rem;margin-right:4px;color:#66c0f4;"></i> Steam ID</label>
                        <input type="text" name="steam_id" id="edit-input-steam" class="form-control" placeholder="Identifiant Steam (FiveM)" autocomplete="off">
                    </div>
                    <?php endif; ?>
                </div>

                <hr class="modal-divider">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">
                    <i class="fa-solid fa-shield" style="margin-right:6px;color:var(--blue);"></i>Rôle
                </div>

                <!-- ── SÉLECTEUR RÔLE ── -->
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-crown" style="font-size:.7rem;margin-right:4px;color:var(--blue);"></i> Rôle</label>
                    <input type="hidden" name="role" id="edit-role" value="Citoyen">
                    <?php if ($canEditGlobalUserRole): ?>
                    <div class="custom-picker" id="role-picker">
                        <?php foreach ($roleDefs as $rKey => $rDef):
                            $rLevel = $rDef['level'] ?? mdt_role_hierarchy_rank($rKey);
                            // Full admin voit tout sauf * (réservé au Super Admin)
                            if ($rKey === '*' && $editorRole !== '*') continue;
                            if (!$isFullAdmin && $rLevel > $editorRank) continue;
                        ?>
                        <div class="cp-item" data-type="role" data-val="<?php echo htmlspecialchars($rKey); ?>"
                             data-color="<?php echo htmlspecialchars($rDef['color']); ?>"
                             style="--cp-color:<?php echo htmlspecialchars($rDef['color']); ?>;"
                             onclick="cpSelect(this,'edit-role','role-picker')">
                            <span class="cp-icon"><i class="fa-solid fa-<?php echo htmlspecialchars($rDef['icon']); ?>"></i></span>
                            <span class="cp-label"><?php echo htmlspecialchars($rDef['label']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div id="role-readonly-wrap" style="padding:10px 12px;background:var(--bg-overlay);border:1px solid var(--border);border-radius:10px;font-size:.88rem;font-weight:600;color:var(--text);" data-placeholder="—"></div>
                    <div class="form-hint">Le rôle global (staff / citoyen / pôles…) ne peut être modifié qu'à partir du grade <strong>Staff</strong> inclus. Vous pouvez toujours ajuster la faction et le grade.</div>
                    <?php endif; ?>
                </div>

                <hr class="modal-divider">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">
                    <i class="fa-solid fa-user-group" style="margin-right:6px;color:var(--blue);"></i>Personnages
                </div>
                <div class="form-hint" style="margin-bottom:10px;">Cliquez sur un personnage pour ouvrir sa fiche complète<?php echo $canEditProfils ? ' (faction et grade modifiables)' : ''; ?>.</div>
                <div class="chars-grid-admin" id="edit-characters-grid">
                    <div class="char-mini-empty">Chargement…</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL FICHE PERSONNAGE -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-character" onclick="if(event.target===this)closeModal('modal-character')">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3><i class="fa-solid fa-id-card" style="color:var(--blue);"></i> Fiche personnage</h3>
            <button class="modal-close" onclick="closeModal('modal-character')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="characterForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_character_admin">
                <input type="hidden" name="character_id" id="char-id">

                <!-- Avatar + méta -->
                <div class="user-info-block">
                    <div class="ava-lg" id="char-ava">?</div>
                    <div style="flex:1;min-width:0;">
                        <div id="char-title" style="font-weight:700;font-size:1rem;"></div>
                        <div style="display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;">
                            <div style="font-size:.72rem;color:var(--text-muted);">
                                <i class="fa-solid fa-user" style="margin-right:4px;opacity:.6;"></i>
                                Compte : <span id="char-owner" style="color:var(--text-secondary);"></span>
                            </div>
                            <div style="font-size:.72rem;color:var(--text-muted);">
                                <i class="fa-solid fa-calendar" style="margin-right:4px;opacity:.6;"></i>
                                Créé le : <span id="char-created-at" style="color:var(--text-secondary);font-family:'DM Mono',monospace;"></span>
                            </div>
                            <div style="font-size:.72rem;" id="char-active-wrap"></div>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;">ID</div>
                        <div id="char-id-badge" style="font-family:'DM Mono',monospace;font-size:.88rem;font-weight:700;color:var(--text);background:var(--bg-overlay);border:1px solid var(--border);border-radius:7px;padding:4px 10px;"></div>
                    </div>
                </div>

                <hr class="modal-divider">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">
                    <i class="fa-solid fa-id-card" style="margin-right:6px;color:var(--blue);"></i>Identité
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nom</label>
                        <input type="text" name="lastname" id="char-input-lastname" class="form-control" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prénom</label>
                        <input type="text" name="firstname" id="char-input-firstname" class="form-control" maxlength="100" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Taille (cm)</label>
                        <input type="number" name="height_cm" id="char-input-height" class="form-control" min="100" max="250">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Âge</label>
                        <input type="number" name="age" id="char-input-age" class="form-control" min="18" max="120">
                    </div>
                </div>

                <hr class="modal-divider">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;">
                    <i class="fa-solid fa-shield" style="margin-right:6px;color:var(--blue);"></i>Faction & Grade
                </div>

                <?php if ($canEditProfils): ?>
                <!-- ── SÉLECTEUR FACTION (personnage) ── -->
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-shield" style="font-size:.7rem;margin-right:4px;color:var(--blue);"></i> Faction</label>
                    <input type="hidden" name="faction" id="char-faction" value="NONE">
                    <!-- Recherche -->
                    <div style="position:relative;margin-bottom:8px;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.72rem;pointer-events:none;"></i>
                        <input type="text" id="char-faction-search" class="form-control" placeholder="Rechercher une faction..."
                               style="padding-left:30px;font-size:.8rem;" oninput="filterCp('char-faction-picker',this.value)">
                    </div>
                    <div class="custom-picker faction-picker" id="char-faction-picker">
                        <!-- NONE -->
                        <div class="cp-item cp-none" data-type="faction" data-val="NONE" data-faction-key="NONE" data-color="#64748b"
                             data-label="aucune none sans faction"
                             style="--cp-color:#64748b;" onclick="mdtPickCharFaction(this)">
                            <span class="cp-icon"><i class="fa-solid fa-ban"></i></span>
                            <span class="cp-label">Aucune faction</span>
                        </div>
                        <?php foreach ($factionCategoryOrder as $mdtCatKey => $mdtCatLabel): ?>
                            <?php
                            $mdtBucket = $factionsGroupedUi[$mdtCatKey] ?? [];
                            if ($mdtBucket === []) {
                                continue;
                            }
                            ?>
                        <div class="cp-category" data-category="<?php echo htmlspecialchars($mdtCatKey); ?>">
                            <div class="cp-category-label"><?php echo htmlspecialchars($mdtCatLabel); ?></div>
                            <div class="cp-category-items">
                                <?php foreach ($mdtBucket as $fk => $fi): ?>
                                <div class="cp-item" data-type="faction" data-val="<?php echo htmlspecialchars($fk); ?>"
                                     data-faction-key="<?php echo htmlspecialchars($fk); ?>"
                                     data-color="<?php echo htmlspecialchars($fi['color']); ?>"
                                     data-label="<?php echo htmlspecialchars(strtolower($fi['name'] . ' ' . $fk . ' ' . $mdtCatLabel)); ?>"
                                     style="--cp-color:<?php echo htmlspecialchars($fi['color']); ?>;"
                                     onclick="mdtPickCharFaction(this)">
                                    <span class="cp-icon"><i class="fa-solid fa-<?php echo htmlspecialchars($fi['icon']); ?>"></i></span>
                                    <span class="cp-label"><?php echo htmlspecialchars($fi['name']); ?></span>
                                    <span class="cp-key"><?php echo htmlspecialchars($fk); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── SÉLECTEUR GRADE (personnage) ── -->
                <div class="form-group" id="char-grade-group">
                    <label class="form-label"><i class="fa-solid fa-medal" style="font-size:.7rem;margin-right:4px;color:var(--blue);"></i> Grade</label>
                    <input type="hidden" name="grade" id="char-grade-hidden" value="">
                    <div class="custom-picker grade-picker" id="char-grade-picker">
                        <div class="cp-item cp-none" data-type="grade" data-val="" data-color="#64748b"
                             style="--cp-color:#64748b;" onclick="cpSelect(this,'char-grade-hidden','char-grade-picker')">
                            <span class="cp-icon"><i class="fa-solid fa-minus"></i></span>
                            <span class="cp-label">— Sans grade —</span>
                        </div>
                    </div>
                    <div id="char-grade-empty" style="display:none;text-align:center;padding:16px;color:var(--text-secondary);font-size:.78rem;">
                        <i class="fa-solid fa-arrow-up" style="display:block;margin-bottom:6px;opacity:.4;"></i>
                        Sélectionnez d'abord une faction
                    </div>
                </div>
                <?php else: ?>
                <!-- Lecture seule : permission staff.users.edit_profils requise -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 14px;background:var(--bg-overlay);border:1px solid var(--border);border-radius:10px;">
                    <span id="char-ro-faction"></span>
                    <span id="char-ro-grade" style="font-size:.82rem;color:var(--text-secondary);"></span>
                </div>
                <div class="form-hint" style="margin-top:8px;">
                    <i class="fa-solid fa-lock" style="margin-right:4px;"></i>
                    La modification de la faction et du grade requiert la permission <code>staff.users.edit_profils</code> (gérée dans <a href="mdt-roles.php" style="color:var(--blue);">Rôles MDT</a>).
                </div>
                <?php endif; ?>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-character')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL CONFIG : NOMBRE MAX DE PERSONNAGES PAR RÔLE -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-char-limits" onclick="if(event.target===this)closeModal('modal-char-limits')">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-users-gear" style="color:var(--blue);"></i> Nombre maximum de personnages</h3>
            <button class="modal-close" onclick="closeModal('modal-char-limits')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="save_character_limits">
                <div class="form-hint" style="margin-bottom:14px;">
                    Nombre de personnages que chaque rôle peut créer sur la page de sélection.
                </div>
                <?php if ($mdtRolesLimits === []): ?>
                    <div style="text-align:center;padding:16px;color:var(--text-secondary);font-size:.82rem;">Aucun rôle trouvé (table <code>mdt_roles</code>).</div>
                <?php else: foreach ($mdtRolesLimits as $rl): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 4px;border-bottom:1px solid var(--border);">
                    <span style="display:inline-flex;align-items:center;gap:8px;font-size:.85rem;font-weight:600;color:<?php echo htmlspecialchars($rl['color'] ?: '#64748b'); ?>;">
                        <i class="fa-solid fa-<?php echo htmlspecialchars($rl['icon'] ?: 'shield'); ?>" style="font-size:.75rem;"></i>
                        <?php echo htmlspecialchars($rl['display_name'] ?: $rl['name']); ?>
                    </span>
                    <input type="number" name="limits[<?php echo htmlspecialchars($rl['name']); ?>]"
                           value="<?php echo (int) ($rl['max_characters'] ?? 3); ?>" min="0" max="50"
                           class="form-control" style="width:90px;text-align:center;">
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-char-limits')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Grades map as JSON for JS -->
<style>
/* ══ CUSTOM PICKERS ══ */
.custom-picker {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-height: 180px;
    overflow-y: auto;
    padding: 10px;
    background: var(--bg-elevated, rgba(255,255,255,.03));
    border: 1px solid var(--border);
    border-radius: 10px;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
}
.faction-picker, .grade-picker {
    max-height: 240px;
}
.faction-picker {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    max-height: 340px;
}
.cp-category {
    width: 100%;
}
.cp-category-label {
    font-size: 0.64rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: var(--text-muted);
    padding: 2px 2px 6px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    margin-bottom: 6px;
}
/* Liste verticale (modal — factions uniquement) */
.faction-picker .cp-category-items {
    display: flex;
    flex-direction: column;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 4px;
}
.faction-picker .cp-item {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    justify-content: flex-start;
}
.faction-picker > .cp-item.cp-none {
    width: 100%;
    box-sizing: border-box;
}
.cp-category-items {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.cp-item {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.07);
    background: rgba(255,255,255,.03);
    cursor: pointer;
    transition: all .15s;
    font-size: .78rem;
    white-space: nowrap;
    user-select: none;
}
.cp-item:hover {
    background: color-mix(in srgb, var(--cp-color) 12%, transparent);
    border-color: color-mix(in srgb, var(--cp-color) 35%, transparent);
    color: var(--cp-color);
}
.cp-item.selected {
    background: color-mix(in srgb, var(--cp-color) 18%, transparent);
    border-color: color-mix(in srgb, var(--cp-color) 50%, transparent);
    color: var(--cp-color);
    font-weight: 600;
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--cp-color) 25%, transparent);
}
.cp-item.hidden { display: none; }
.cp-icon {
    width: 22px;
    height: 22px;
    border-radius: 5px;
    display: grid;
    place-items: center;
    font-size: .65rem;
    background: color-mix(in srgb, var(--cp-color) 14%, transparent);
    color: var(--cp-color);
    flex-shrink: 0;
    transition: background .15s;
}
.cp-item.selected .cp-icon {
    background: color-mix(in srgb, var(--cp-color) 25%, transparent);
}
.cp-label { flex: 1; color: inherit; }
.cp-key {
    font-size: .62rem;
    font-family: 'DM Mono', monospace;
    opacity: .5;
    padding: 1px 5px;
    background: rgba(255,255,255,.05);
    border-radius: 4px;
}
.cp-none { opacity: .6; }
/* Grade items — avec badge de rang */
.cp-grade-rank {
    min-width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .55rem;
    font-weight: 800;
    background: color-mix(in srgb, var(--cp-color) 20%, transparent);
    color: var(--cp-color);
    flex-shrink: 0;
    padding: 0 4px;
    min-width: 22px;
}
.cp-owner-badge {
    font-size: .52rem;
    padding: 1px 5px;
    border-radius: 4px;
    background: color-mix(in srgb, var(--cp-color) 18%, transparent);
    border: 1px solid color-mix(in srgb, var(--cp-color) 35%, transparent);
    color: var(--cp-color);
    font-weight: 700;
}

/* ══ MINI-CARTES PERSONNAGES (modale utilisateur) ══ */
.chars-grid-admin {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
}
.char-mini-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--bg-overlay, rgba(255,255,255,.03));
    border: 1px solid var(--border);
    border-radius: 12px;
    cursor: pointer;
    transition: border-color .15s, background .15s, transform .15s;
}
.char-mini-card:hover {
    border-color: rgba(59,130,246,.45);
    background: rgba(59,130,246,.06);
    transform: translateY(-1px);
}
.char-mini-ava {
    width: 40px; height: 40px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    background: rgba(59,130,246,.15);
    color: #93c5fd;
    font-weight: 700;
    font-size: .85rem;
}
.char-mini-ava img { width: 100%; height: 100%; object-fit: cover; }
.char-mini-info { min-width: 0; flex: 1; }
.char-mini-name {
    font-size: .82rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 6px;
}
.char-mini-active {
    font-size: .55rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 1px 6px;
    border-radius: 8px;
    background: rgba(16,185,129,.15);
    border: 1px solid rgba(16,185,129,.35);
    color: #6ee7b7;
    flex-shrink: 0;
}
.char-mini-sub {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 3px;
    font-size: .68rem;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
}
.char-mini-faction {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 7px;
    border-radius: 8px;
    font-weight: 700;
    font-size: .62rem;
    flex-shrink: 0;
}
.char-mini-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 14px;
    color: var(--text-secondary);
    font-size: .78rem;
}
</style>

<script>
const GRADES_MAP = <?php echo json_encode($allGradesMap, JSON_UNESCAPED_UNICODE); ?>;
const EDITOR_FACTION = <?php echo json_encode($editorFaction, JSON_UNESCAPED_UNICODE); ?>;
const EDITOR_GRADE_MAX_RANK = <?php echo $editorGradeMaxRank === null ? 'null' : (int)$editorGradeMaxRank; ?>;
const CAN_EDIT_GLOBAL_USER_ROLE = <?php echo $canEditGlobalUserRole ? 'true' : 'false'; ?>;
const CAN_EDIT_PROFILS = <?php echo $canEditProfils ? 'true' : 'false'; ?>;
const FACTIONS_INFO = <?php
    $fi = [];
    foreach ($enumFactions as $k => $v) $fi[$k] = array_merge($v, ['key' => $k]);
    echo json_encode($fi, JSON_UNESCAPED_UNICODE);
?>;

/* ── Modal open/close ── */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
});

/* ══ CUSTOM PICKER ENGINE ══ */
/**
 * Sélectionne un item dans un picker.
 * @param {HTMLElement} item  - L'item cliqué
 * @param {string} hiddenId   - ID du champ hidden à mettre à jour
 * @param {string} pickerId   - ID du conteneur picker
 */
function cpSelect(item, hiddenId, pickerId) {
    const picker = document.getElementById(pickerId);
    if (picker) picker.querySelectorAll('.cp-item').forEach(i => i.classList.remove('selected'));
    item.classList.add('selected');
    const hidden = document.getElementById(hiddenId);
    if (hidden) hidden.value = item.dataset.val;
}

/**
 * Pré-sélectionne un item par valeur.
 */
function cpSetValue(pickerId, hiddenId, val) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    picker.querySelectorAll('.cp-item').forEach(i => {
        i.classList.remove('selected');
        if (i.dataset.val === val) {
            i.classList.add('selected');
            i.scrollIntoView({ block: 'nearest' });
        }
    });
    const hidden = document.getElementById(hiddenId);
    if (hidden) hidden.value = val;
}

/**
 * Filtre les items d'un picker par texte.
 */
function filterCp(pickerId, q) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const lq = q.toLowerCase().trim();
    picker.querySelectorAll('.cp-item').forEach(i => {
        const label = (i.dataset.label || i.textContent).toLowerCase();
        i.classList.toggle('hidden', lq !== '' && !label.includes(lq));
    });
    picker.querySelectorAll('.cp-category').forEach(cat => {
        const items = cat.querySelectorAll('.cp-category-items .cp-item');
        const anyVisible = [...items].some(it => !it.classList.contains('hidden'));
        cat.style.display = anyVisible ? '' : 'none';
    });
}

/** Faction (fiche personnage) : évite les problèmes de quotes dans onclick + recharge les grades. */
function mdtPickCharFaction(el) {
    const fk = el.dataset.factionKey || el.dataset.val || 'NONE';
    cpSelect(el, 'char-faction', 'char-faction-picker');
    updateGrades(fk);
}

/* ══ MISE À JOUR DES GRADES selon la faction ══
   Cible par défaut : les pickers de la fiche personnage. */
function updateGrades(factionKey, currentGrade, pickerId = 'char-grade-picker', hiddenId = 'char-grade-hidden', emptyId = 'char-grade-empty') {
    const picker  = document.getElementById(pickerId);
    const empty   = document.getElementById(emptyId);
    const hidden  = document.getElementById(hiddenId);
    if (!picker) return;

    if (!factionKey || factionKey === 'NONE') {
        if (hidden) hidden.value = '';
        picker.innerHTML = `
        <div class="cp-item cp-none" data-type="grade" data-val="" data-color="#64748b"
             style="--cp-color:#64748b;" onclick="cpSelect(this,'${hiddenId}','${pickerId}')">
            <span class="cp-icon"><i class="fa-solid fa-minus"></i></span>
            <span class="cp-label">— Sans grade —</span>
        </div>`;
        picker.style.display = 'none';
        if (empty) empty.style.display = 'block';
        return;
    }

    const grades    = GRADES_MAP[factionKey] || [];
    const fInfo     = FACTIONS_INFO[factionKey] || {};
    const color     = fInfo.color || '#64748b';

    // Reconstruire le picker
    picker.innerHTML = `
        <div class="cp-item cp-none" data-type="grade" data-val="" data-color="${color}"
             style="--cp-color:${color};" onclick="cpSelect(this,'${hiddenId}','${pickerId}')">
            <span class="cp-icon"><i class="fa-solid fa-minus"></i></span>
            <span class="cp-label">— Sans grade —</span>
        </div>`;

    if (grades.length === 0) {
        picker.style.display = 'none';
        if (empty) empty.style.display = 'block';
        return;
    }
    picker.style.display = 'flex';
    if (empty) empty.style.display = 'none';

    grades.forEach((g, idx) => {
        const name  = typeof g === 'object' ? g.name : g;
        const rank  = typeof g === 'object' ? g.rank : (idx + 1);
        if (EDITOR_GRADE_MAX_RANK !== null && factionKey === EDITOR_FACTION && Number(rank) > EDITOR_GRADE_MAX_RANK) {
            return;
        }
        const isOwner = typeof g === 'object' ? g.is_owner : false;
        const isCeo   = typeof g === 'object' ? g.is_ceo   : false;

        const div = document.createElement('div');
        div.className = 'cp-item';
        div.dataset.type  = 'grade';
        div.dataset.val   = name;
        div.dataset.color = color;
        div.dataset.label = String(name).toLowerCase();
        div.style.cssText = `--cp-color:${color};`;
        div.addEventListener('click', function () { cpSelect(this, hiddenId, pickerId); });

        const rk = document.createElement('span');
        rk.className = 'cp-grade-rank';
        rk.textContent = String(rank);
        const ic = document.createElement('span');
        ic.className = 'cp-icon';
        ic.innerHTML = '<i class="fa-solid fa-medal"></i>';
        const lb = document.createElement('span');
        lb.className = 'cp-label';
        lb.textContent = name;
        div.appendChild(rk);
        div.appendChild(ic);
        div.appendChild(lb);
        if (isOwner) {
            const b = document.createElement('span');
            b.className = 'cp-owner-badge';
            b.textContent = 'PDG';
            div.appendChild(b);
        } else if (isCeo) {
            const b = document.createElement('span');
            b.className = 'cp-owner-badge';
            b.textContent = 'Co-PDG';
            div.appendChild(b);
        }
        picker.appendChild(div);
    });

    let cur = currentGrade || (hidden ? hidden.value : '');
    if (cur && EDITOR_GRADE_MAX_RANK !== null && factionKey === EDITOR_FACTION) {
        let found = false;
        picker.querySelectorAll('.cp-item[data-val]').forEach(el => {
            if (el.dataset.val === cur) found = true;
        });
        if (!found) {
            const div = document.createElement('div');
            div.className = 'cp-item selected';
            div.dataset.type = 'grade';
            div.dataset.val = cur;
            div.dataset.color = color;
            div.dataset.label = String(cur).toLowerCase();
            div.style.cssText = `--cp-color:${color};`;
            div.addEventListener('click', function () { cpSelect(this, hiddenId, pickerId); });
            const rk = document.createElement('span');
            rk.className = 'cp-grade-rank';
            rk.title = "Grade actuel (au-dessus de vos droits d'édition)";
            rk.innerHTML = '<i class="fa-solid fa-lock" style="font-size:.55rem;"></i>';
            const ic = document.createElement('span');
            ic.className = 'cp-icon';
            ic.innerHTML = '<i class="fa-solid fa-medal"></i>';
            const lb = document.createElement('span');
            lb.className = 'cp-label';
            lb.textContent = cur;
            div.appendChild(rk);
            div.appendChild(ic);
            div.appendChild(lb);
            picker.appendChild(div);
            if (hidden) hidden.value = cur;
        }
    }

    if (cur) {
        picker.querySelectorAll('.cp-item[data-type="grade"]').forEach(el => {
            if (el.dataset.val === cur) {
                el.classList.add('selected');
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }
}

/* ══ OUVERTURE DE LA MODAL D'ÉDITION ══ */
let CURRENT_EDIT_USER = null;

function openEditModal(u) {
    CURRENT_EDIT_USER = u;
    document.getElementById('edit-user-id').value = u.id;
    document.getElementById('edit-uid-badge').textContent = '#' + u.id;

    // Avatar (initiale = nom RP affiché ou pseudo)
    const ava = document.getElementById('edit-ava');
    const _disp = String(u.rp_display || u.username || '?').trim();
    const _ini = _disp.length ? _disp.charAt(0).toUpperCase() : '?';
    if (u.avatar) {
        const fb = String(u.username || '?').charAt(0).toUpperCase();
        ava.innerHTML = `<img src="${u.avatar}" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentNode.textContent='${fb.replace(/'/g, "\\'")}'">`;
    } else {
        ava.textContent = _ini;
    }

    // Badge statut
    const statusEl = document.getElementById('edit-status-badge');
    if (u.is_banned) {
        statusEl.innerHTML = '<span style="font-size:.8rem;font-weight:700;color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);padding:3px 9px;border-radius:20px;"><i class="fa-solid fa-ban" style="margin-right:5px;"></i>Banni</span>';
    } else if (u.is_active) {
        statusEl.innerHTML = '<span style="font-size:.8rem;font-weight:700;color:#22c55e;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);padding:3px 9px;border-radius:20px;"><i class="fa-solid fa-circle" style="margin-right:5px;font-size:.5rem;"></i>Actif</span>';
    } else {
        statusEl.innerHTML = '<span style="font-size:.8rem;font-weight:700;color:#64748b;background:rgba(100,116,139,.1);border:1px solid rgba(100,116,139,.3);padding:3px 9px;border-radius:20px;"><i class="fa-solid fa-circle" style="margin-right:5px;font-size:.5rem;"></i>Inactif</span>';
    }

    // Dates
    document.getElementById('edit-created-at').textContent  = u.created_at ? u.created_at.slice(0,10).split('-').reverse().join('/') : '—';
    document.getElementById('edit-last-login').textContent  = u.last_login  ? u.last_login.slice(0,10).split('-').reverse().join('/') + ' ' + u.last_login.slice(11,16) : 'Jamais';

    // Champs texte
    document.getElementById('edit-input-username').value = u.username;
    document.getElementById('edit-input-email').value    = u.email      || '';
    document.getElementById('edit-input-discord').value  = u.discord_id || '';
    const steamInp = document.getElementById('edit-input-steam');
    if (steamInp) steamInp.value = u.steam_id || '';

    // Rôle
    document.getElementById('edit-role').value = u.role || 'Citoyen';
    if (CAN_EDIT_GLOBAL_USER_ROLE) {
        cpSetValue('role-picker', 'edit-role', u.role || 'Citoyen');
    } else {
        const ro = document.getElementById('role-readonly-wrap');
        if (ro) ro.textContent = u.role || 'Citoyen';
    }

    // Personnages du compte
    loadUserCharacters(u.id);

    openModal('modal-edit');
}

/* ══ PERSONNAGES : chargement + mini-cartes ══ */
function loadUserCharacters(userId) {
    const grid = document.getElementById('edit-characters-grid');
    grid.innerHTML = '<div class="char-mini-empty"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Chargement…</div>';
    fetch('index.php?action=get_user_characters&user_id=' + encodeURIComponent(userId))
        .then(r => r.json())
        .then(data => renderCharacterCards(data))
        .catch(() => { grid.innerHTML = '<div class="char-mini-empty">Erreur de chargement des personnages.</div>'; });
}

function renderCharacterCards(data) {
    const grid = document.getElementById('edit-characters-grid');
    grid.innerHTML = '';
    const chars = (data && data.characters) || [];
    if (!chars.length) {
        grid.innerHTML = '<div class="char-mini-empty">Aucun personnage.</div>';
        return;
    }
    const activeId = Number(data.active_character_id || 0);
    chars.forEach(c => {
        const isNone = !c.faction || c.faction === 'NONE';
        const color  = (!isNone && c.faction_color) ? c.faction_color : '#64748b';
        const fName  = isNone ? 'Civil' : (c.faction_name || c.faction);
        const grade  = c.faction_rank || c.faction_grade || '—';
        const ini    = (String(c.firstname || '?').charAt(0) + String(c.lastname || '').charAt(0)).toUpperCase();

        const card = document.createElement('div');
        card.className = 'char-mini-card';

        const avaDiv = document.createElement('div');
        avaDiv.className = 'char-mini-ava';
        if (c.avatar) {
            const img = document.createElement('img');
            img.src = c.avatar;
            img.alt = '';
            img.onerror = function () { avaDiv.textContent = ini; };
            avaDiv.appendChild(img);
        } else {
            avaDiv.textContent = ini;
        }

        const info = document.createElement('div');
        info.className = 'char-mini-info';

        const nameDiv = document.createElement('div');
        nameDiv.className = 'char-mini-name';
        nameDiv.textContent = (c.firstname || '') + ' ' + (c.lastname || '');
        if (Number(c.id) === activeId) {
            const b = document.createElement('span');
            b.className = 'char-mini-active';
            b.textContent = 'Actif';
            nameDiv.appendChild(b);
        }

        const sub = document.createElement('div');
        sub.className = 'char-mini-sub';
        const fBadge = document.createElement('span');
        fBadge.className = 'char-mini-faction';
        fBadge.style.background = color + '18';
        fBadge.style.color = color;
        fBadge.style.border = '1px solid ' + color + '30';
        fBadge.textContent = fName;
        const gSpan = document.createElement('span');
        gSpan.textContent = grade;
        sub.appendChild(fBadge);
        sub.appendChild(gSpan);

        info.appendChild(nameDiv);
        info.appendChild(sub);
        card.appendChild(avaDiv);
        card.appendChild(info);
        card.addEventListener('click', () => openCharacterModal(c, activeId));
        grid.appendChild(card);
    });
}

/* ══ FICHE PERSONNAGE ══ */
function openCharacterModal(c, activeId) {
    document.getElementById('char-id').value = c.id;
    document.getElementById('char-id-badge').textContent = '#' + c.id;
    document.getElementById('char-title').textContent = (c.firstname || '') + ' ' + (c.lastname || '');
    document.getElementById('char-owner').textContent = CURRENT_EDIT_USER ? ('@' + CURRENT_EDIT_USER.username + ' (#' + CURRENT_EDIT_USER.id + ')') : '#' + c.user_id;
    document.getElementById('char-created-at').textContent = c.created_at ? String(c.created_at).slice(0,10).split('-').reverse().join('/') : '—';
    document.getElementById('char-active-wrap').innerHTML = Number(c.id) === Number(activeId)
        ? '<span class="char-mini-active"><i class="fa-solid fa-circle-check" style="margin-right:4px;"></i>Personnage actif</span>' : '';

    // Avatar
    const ava = document.getElementById('char-ava');
    const ini = (String(c.firstname || '?').charAt(0) + String(c.lastname || '').charAt(0)).toUpperCase();
    if (c.avatar) {
        ava.innerHTML = '';
        const img = document.createElement('img');
        img.src = c.avatar;
        img.alt = '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        img.onerror = function () { ava.textContent = ini; };
        ava.appendChild(img);
    } else {
        ava.textContent = ini;
    }

    // Identité
    document.getElementById('char-input-firstname').value = c.firstname || '';
    document.getElementById('char-input-lastname').value  = c.lastname  || '';
    document.getElementById('char-input-height').value    = c.height_cm || '';
    document.getElementById('char-input-age').value       = c.age       || '';

    // Faction & grade
    const grade = c.faction_rank || c.faction_grade || '';
    if (CAN_EDIT_PROFILS) {
        cpSetValue('char-faction-picker', 'char-faction', c.faction || 'NONE');
        const fs = document.getElementById('char-faction-search');
        if (fs) { fs.value = ''; filterCp('char-faction-picker', ''); }
        updateGrades(c.faction || 'NONE', grade);
    } else {
        const isNone = !c.faction || c.faction === 'NONE';
        const color  = (!isNone && c.faction_color) ? c.faction_color : '#64748b';
        const roF = document.getElementById('char-ro-faction');
        roF.className = 'char-mini-faction';
        roF.style.background = color + '18';
        roF.style.color = color;
        roF.style.border = '1px solid ' + color + '30';
        roF.style.fontSize = '.75rem';
        roF.style.padding = '3px 10px';
        roF.textContent = isNone ? 'Civil' : (c.faction_name || c.faction);
        document.getElementById('char-ro-grade').textContent = grade || '— Sans grade —';
    }

    openModal('modal-character');
}
</script>

<?php if (hasRole(['*', 'Administrateur', 'Développeur'])): ?>
<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- MODAL PURGE CITOYENS                                                -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div id="modal-purge" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);z-index:9999;align-items:center;justify-content:center;padding:1.5rem;">
    <div style="background:linear-gradient(140deg,#120508,#0d0205);border:2px solid rgba(239,68,68,.4);border-radius:16px;max-width:440px;width:100%;overflow:hidden;box-shadow:0 0 80px rgba(239,68,68,.2);">

        <!-- Header -->
        <div style="padding:1.35rem 1.5rem 1rem;border-bottom:1px solid rgba(239,68,68,.2);display:flex;align-items:center;gap:.85rem;">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(239,68,68,.15);border:1.5px solid rgba(239,68,68,.35);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-skull" style="color:#fca5a5;font-size:1.2rem;"></i>
            </div>
            <div>
                <div style="font-size:1.05rem;font-weight:800;color:#fff;margin-bottom:.2rem;">Purge des comptes citoyens</div>
                <div style="font-size:.75rem;color:#fca5a5;opacity:.8;">Action irréversible — niveau administrateur requis</div>
            </div>
        </div>

        <!-- Body -->
        <div style="padding:1.35rem 1.5rem;">
            <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:.9rem 1.1rem;margin-bottom:1.1rem;">
                <div style="font-size:.85rem;font-weight:700;color:#fca5a5;margin-bottom:.45rem;">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-right:.4rem;"></i>Cette action va supprimer :
                </div>
                <ul style="margin:0;padding-left:1.2rem;color:#fecaca;font-size:.82rem;line-height:1.8;">
                    <li>Tous les comptes avec un rôle <strong>non-staff</strong></li>
                    <li>Les comptes Citoyen, Pôle 911, Pôle Illégale, Pôle Légal…</li>
                    <li>Cette action est <strong>définitive et irréversible</strong></li>
                </ul>
            </div>
            <div style="background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:.75rem 1rem;margin-bottom:1.1rem;font-size:.8rem;color:#6ee7b7;">
                <i class="fa-solid fa-shield-check" style="margin-right:.4rem;"></i>
                Les comptes <strong>*, Administrateur, Développeur, Staff, Modérateur, Helper, Recruteur</strong> sont <strong>protégés</strong> et ne seront pas supprimés.
            </div>

            <!-- Champ de confirmation textuelle -->
            <div style="margin-bottom:.3rem;">
                <label style="display:block;font-size:.75rem;font-weight:700;color:#fca5a5;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.45rem;">
                    Tapez <code style="background:rgba(239,68,68,.15);padding:2px 7px;border-radius:4px;font-family:monospace;color:#fca5a5;">PURGE</code> pour confirmer
                </label>
                <input type="text" id="purge-confirm-input"
                       placeholder="PURGE"
                       autocomplete="off"
                       oninput="checkPurgeInput(this)"
                       style="width:100%;padding:9px 13px;background:#1a0205;border:1.5px solid rgba(239,68,68,.3);border-radius:8px;color:#fff;font-size:.92rem;font-family:'IBM Plex Mono',monospace;box-sizing:border-box;letter-spacing:.1em;transition:border-color .15s;">
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:.9rem 1.5rem 1.35rem;display:flex;justify-content:flex-end;gap:.6rem;">
            <button type="button"
                    class="btn btn-secondary btn-sm"
                    onclick="closePurgeModal()">
                Annuler
            </button>
            <form method="POST" id="purge-form" style="margin:0;">
                <input type="hidden" name="action" value="purge_citizens">
                <button type="submit" id="purge-submit-btn"
                        disabled
                        style="padding:.45rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:not-allowed;background:rgba(239,68,68,.08);color:rgba(252,165,165,.4);border:1px solid rgba(239,68,68,.15);transition:all .2s;display:inline-flex;align-items:center;gap:.4rem;">
                    <i class="fa-solid fa-skull"></i> Purger définitivement
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function openPurgeModal() {
    const m = document.getElementById('modal-purge');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Reset le champ à chaque ouverture
    const inp = document.getElementById('purge-confirm-input');
    if (inp) { inp.value = ''; inp.style.borderColor = 'rgba(239,68,68,.3)'; }
    const btn = document.getElementById('purge-submit-btn');
    if (btn) {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.background = 'rgba(239,68,68,.08)';
        btn.style.color = 'rgba(252,165,165,.4)';
        btn.style.borderColor = 'rgba(239,68,68,.15)';
    }
    setTimeout(() => document.getElementById('purge-confirm-input')?.focus(), 150);
}

function closePurgeModal() {
    const m = document.getElementById('modal-purge');
    m.style.display = 'none';
    document.body.style.overflow = '';
}

function checkPurgeInput(inp) {
    const ok  = inp.value.trim() === 'PURGE';
    const btn = document.getElementById('purge-submit-btn');
    inp.style.borderColor = inp.value.length === 0
        ? 'rgba(239,68,68,.3)'
        : (ok ? 'rgba(239,68,68,.85)' : 'rgba(239,68,68,.3)');
    if (btn) {
        btn.disabled        = !ok;
        btn.style.cursor    = ok ? 'pointer'                    : 'not-allowed';
        btn.style.background= ok ? 'rgba(239,68,68,.18)'        : 'rgba(239,68,68,.08)';
        btn.style.color     = ok ? '#fca5a5'                    : 'rgba(252,165,165,.4)';
        btn.style.borderColor=ok ? 'rgba(239,68,68,.5)'         : 'rgba(239,68,68,.15)';
    }
}

// Fermer avec Échap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('modal-purge')?.style.display === 'flex') {
        closePurgeModal();
    }
});
// Fermer au clic extérieur
document.getElementById('modal-purge')?.addEventListener('click', function(e) {
    if (e.target === this) closePurgeModal();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>