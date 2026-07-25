<?php
// pages/admin/users/roles.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/mdt_faction_discord_sync.php';
require_once ROOT_PATH . '/includes/faction_logo.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) redirect(SITE_URL . '/pages/dashboard.php');

$db = Database::getInstance()->getConnection();
mdt_faction_sync_ensure_tables($db);

$pageTitle  = 'Rôles & Factions';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => 'index.php'], ['label' => 'Rôles & Factions']];
$success = $error = '';
$uid = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['roles_faction_csrf'])) $_SESSION['roles_faction_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['roles_faction_csrf'];

// ── AJAX : liste utilisateurs Discord pour resync factions ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_faction_discord_users') {
    header('Content-Type: application/json');
    try {
        $rows = $db->query("SELECT id, username, discord_id, faction FROM users WHERE discord_id IS NOT NULL AND discord_id != '' AND is_active=1 ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['users' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['users' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX : resync faction d'UN utilisateur ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync_faction_single') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $targetId  = (int)($_POST['user_id']    ?? 0);
    $discordId = trim($_POST['discord_id']  ?? '');
    if ($targetId > 0 && $discordId !== '') {
        $result = mdt_faction_sync_user($db, $targetId, $discordId, 'manual');
    } else {
        $result = ['success'=>false,'changed'=>false,'message'=>'Paramètres manquants'];
    }
    echo json_encode($result);
    exit;
}

// ── AJAX : liste des pages + catalogue d'actions d'une faction (GET) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_faction_pages') {
    header('Content-Type: application/json');
    require_once ROOT_PATH . '/includes/faction_acl.php';
    $fk        = trim($_GET['faction_key'] ?? '');
    $gradeId   = (int)($_GET['grade_id'] ?? 0);
    $gradeName = trim($_GET['grade_name'] ?? '');

    $pages      = faction_acl_scan_pages($fk);
    $pagePerms  = ($gradeId > 0) ? faction_acl_get_grade_permissions($gradeId, $db) : [];
    $actPerms   = ($gradeName !== '' && $fk !== '') ? faction_acl_get_action_permissions($fk, $gradeName, $db) : [];
    $catalog    = faction_acl_action_catalog();

    // Attacher le catalogue d'actions à chaque page
    foreach ($pages as &$page) {
        $stem = pathinfo($page['path'], PATHINFO_FILENAME);
        if (isset($catalog[$stem])) {
            $page['action_group'] = $catalog[$stem]['group'];
            $page['actions'] = [];
            foreach ($catalog[$stem]['actions'] as $key => $label) {
                $page['actions'][] = [
                    'key'     => $key,
                    'label'   => $label,
                    'enabled' => $actPerms[$key] ?? null, // null = non configuré
                ];
            }
        }
    }
    unset($page);

    echo json_encode(['pages' => $pages, 'page_permissions' => $pagePerms, 'action_permissions' => $actPerms]);
    exit;
}

// ── CRUD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Créer un grade pour une faction
    if ($action === 'create_grade') {
        $faction_key = trim($_POST['faction_key'] ?? '');
        $grade_name  = trim($_POST['grade_name']  ?? '');
        $rank_order  = (int)($_POST['rank_order'] ?? 99);

        if ($faction_key && $grade_name) {
            try {
                // 1. Chercher la faction — si elle n'existe pas encore dans la table, la créer automatiquement
                $fStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
                $fStmt->execute([$faction_key]);
                $factionId = $fStmt->fetchColumn();

                if (!$factionId) {
                    // Récupérer les infos depuis $enumFactions si disponible, sinon valeurs par défaut
                    $fName  = $enumFactions[$faction_key]['name']  ?? $faction_key;
                    $fColor = $enumFactions[$faction_key]['color'] ?? '#64748b';
                    $fIcon  = $enumFactions[$faction_key]['icon']  ?? 'shield';
                    $db->prepare("INSERT INTO factions (name, faction_key, color, icon, created_at) VALUES (?,?,?,?,NOW())")
                       ->execute([$fName, $faction_key, $fColor, $fIcon]);
                    $factionId = $db->lastInsertId();
                }

                // 2. Calculer automatiquement le rank_order si non fourni (= dernier + 1)
                if ($rank_order === 99) {
                    $maxStmt = $db->prepare("SELECT COALESCE(MAX(rank_order), 0) FROM faction_grades WHERE faction_id = ?");
                    $maxStmt->execute([$factionId]);
                    $rank_order = (int)$maxStmt->fetchColumn() + 1;
                }

                // 3. Insérer le grade (ignore si doublon)
                $existStmt = $db->prepare("SELECT id FROM faction_grades WHERE faction_id = ? AND name = ?");
                $existStmt->execute([$factionId, $grade_name]);
                if (!$existStmt->fetchColumn()) {
                    $db->prepare("INSERT INTO faction_grades (faction_id, name, rank_order, created_at) VALUES (?, ?, ?, NOW())")
                       ->execute([$factionId, $grade_name, $rank_order]);
                    $success = "Grade \"$grade_name\" créé.";
                    mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Création grade faction', "Faction $faction_key — grade \"$grade_name\"", 'faction', $faction_key);
                } else {
                    $error = "Ce grade existe déjà dans cette faction.";
                }
            } catch (Exception $e) {
                $error = 'Erreur lors de la création du grade : ' . $e->getMessage();
            }
        } else {
            $error = 'Faction et nom du grade requis.';
        }
    }

    // Mettre à jour le grade d'un utilisateur
    if ($action === 'update_user_grade') {
        $user_id = (int)$_POST['user_id'];
        $grade_name = trim($_POST['grade_name'] ?? '');
        
        try {
            $db->prepare("UPDATE users SET faction_rank = ? WHERE id = ?")->execute([$grade_name, $user_id]);
            $success = "Grade mis à jour.";
            mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Grade membre (fiche faction)', "User ID $user_id — grade faction_rank: $grade_name", 'user', null);
        } catch (Exception $e) {
            $error = 'Erreur lors de la mise à jour.';
        }
    }

    // Supprimer un grade
    if ($action === 'delete_grade') {
        $faction_key = trim($_POST['faction_key'] ?? '');
        $grade_name  = trim($_POST['grade_name']  ?? '');

        try {
            // Supprimer de faction_grades (si la faction y est enregistrée)
            $fStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
            $fStmt->execute([$faction_key]);
            $factionId = $fStmt->fetchColumn();
            if ($factionId) {
                $db->prepare("DELETE FROM faction_grades WHERE faction_id = ? AND name = ?")
                   ->execute([$factionId, $grade_name]);
            }
            // Dans tous les cas : remettre à vide faction_rank des membres portant ce grade
            $db->prepare("UPDATE users SET faction_rank = '', faction_grade = '' WHERE faction = ? AND faction_rank = ?")
               ->execute([$faction_key, $grade_name]);
            $success = "Grade \"$grade_name\" supprimé.";
            mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Suppression grade faction', "Faction $faction_key — grade \"$grade_name\"", 'faction', $faction_key);
        } catch (Exception $e) {
            $error = 'Erreur lors de la suppression : ' . $e->getMessage();
        }
    }

    // ─── Modifier un grade (nom + rank_order + permissions de pages) ─────────
    if ($action === 'update_grade') {
        $faction_key   = trim($_POST['faction_key']   ?? '');
        $old_name      = trim($_POST['old_grade_name']?? '');
        $new_name      = trim($_POST['grade_name']    ?? '');
        $rank_order    = (int)($_POST['rank_order']   ?? 99);

        if ($faction_key && $old_name && $new_name) {
            try {
                // Trouver le faction_id
                $fStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
                $fStmt->execute([$faction_key]);
                $factionId = $fStmt->fetchColumn();

                $gradeId = 0;
                if ($factionId) {
                    // Mise à jour dans faction_grades (si le grade y existe déjà)
                    $db->prepare("UPDATE faction_grades SET name = ?, rank_order = ? WHERE faction_id = ? AND name = ?")
                       ->execute([$new_name, $rank_order, $factionId, $old_name]);
                    // Récupérer l'ID du grade pour les permissions
                    $gidStmt = $db->prepare("SELECT id FROM faction_grades WHERE faction_id = ? AND name = ?");
                    $gidStmt->execute([$factionId, $new_name]);
                    $gradeId = (int)($gidStmt->fetchColumn() ?: 0);
                    // Grade "orphelin" (dans users.faction_rank mais pas dans faction_grades) :
                    // on le crée automatiquement pour pouvoir lui assigner des permissions.
                    if ($gradeId === 0 && isset($_POST['pages'])) {
                        $db->prepare("INSERT INTO faction_grades (faction_id, name, rank_order) VALUES (?,?,?)")
                           ->execute([$factionId, $new_name, $rank_order ?: 99]);
                        $gradeId = (int)$db->lastInsertId();
                    }
                }
                // Mise à jour dans company_ranks
                try {
                    $db->prepare("UPDATE company_ranks SET rank_name = ? WHERE faction = ? AND rank_name = ?")
                       ->execute([$new_name, $faction_key, $old_name]);
                } catch (Exception $e) {}

                // Mise à jour dans users (faction_rank)
                if ($new_name !== $old_name) {
                    $db->prepare("UPDATE users SET faction_rank = ? WHERE faction = ? AND faction_rank = ?")
                       ->execute([$new_name, $faction_key, $old_name]);
                }

                require_once ROOT_PATH . '/includes/faction_acl.php';

                // ── Sauvegarder les permissions de pages (view/edit) ─────────
                if (isset($_POST['pages'])) {
                    faction_acl_save_grade_permissions($gradeId, $faction_key, $new_name, (array)$_POST['pages'], $db);
                }

                // ── Sauvegarder les permissions d'actions granulaires ─────────
                if (isset($_POST['actions']) && is_array($_POST['actions'])) {
                    $actionMap = [];
                    foreach ((array)$_POST['actions'] as $key => $val) {
                        $actionMap[(string)$key] = !empty($val);
                    }
                    faction_acl_save_action_permissions($faction_key, $new_name, $actionMap, $db);
                }

                $success = "Grade \"$new_name\" mis à jour.";
                mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Modification grade faction', "Faction $faction_key — \"$old_name\" → \"$new_name\" (rang $rank_order)", 'faction', $faction_key);
            } catch (Exception $e) {
                $error = 'Erreur : ' . $e->getMessage();
            }
        } else {
            $error = 'Données manquantes.';
        }
    }

    // ─── Réordonner les grades (appelé en AJAX) ───────────────────────────────
    if ($action === 'reorder_grades') {
        header('Content-Type: application/json');
        $faction_key = trim($_POST['faction_key'] ?? '');
        $order       = json_decode($_POST['order'] ?? '[]', true); // [{name, rank_order}, ...]

        if (!$faction_key || !is_array($order)) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }
        try {
            $fStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
            $fStmt->execute([$faction_key]);
            $factionId = $fStmt->fetchColumn();

            $db->beginTransaction();
            foreach ($order as $item) {
                $name       = trim($item['name'] ?? '');
                $rankOrder  = (int)($item['rank_order'] ?? 99);
                if (!$name) continue;

                if ($factionId) {
                    $db->prepare("UPDATE faction_grades SET rank_order = ? WHERE faction_id = ? AND name = ?")
                       ->execute([$rankOrder, $factionId, $name]);
                }
                // Sync company_ranks si présent
                try {
                    $db->prepare("UPDATE company_ranks SET rank_level = ? WHERE faction = ? AND rank_name = ?")
                       ->execute([$rankOrder, $faction_key, $name]);
                } catch (Exception $e) {}
            }
            $db->commit();
            mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Réordonnancement grades', 'Faction ' . $faction_key . ' — hiérarchie des grades mise à jour', 'faction', $faction_key);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Supprimer une faction (mettre tous les users à NONE)
    if ($action === 'delete_faction') {
        $faction_key = trim($_POST['faction_key'] ?? '');
        
        try {
            $db->prepare("UPDATE users SET faction = 'NONE', faction_rank = '' WHERE faction = ?")->execute([$faction_key]);
            // Essayer de supprimer de la table factions si elle existe
            @$db->prepare("DELETE FROM factions WHERE faction_key = ?")->execute([$faction_key]);
            $success = "Faction supprimée et utilisateurs disaffiliés.";
            mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Suppression faction', "Faction $faction_key supprimée, membres passés en NONE", 'faction', $faction_key);
        } catch (Exception $e) {
            $error = 'Erreur lors de la suppression.';
        }
    }

    // Créer une nouvelle faction
    if ($action === 'create_faction') {
        $faction_key = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $_POST['faction_key'] ?? ''));
        $faction_name = trim($_POST['faction_name'] ?? '');
        $faction_color = trim($_POST['faction_color'] ?? '#3b82f6');
        $faction_icon = trim($_POST['faction_icon'] ?? 'shield');

        if ($faction_key && $faction_name) {
            try {
                // Vérifier si la faction existe déjà
                $checkStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
                $checkStmt->execute([$faction_key]);
                
                if ($checkStmt->rowCount() > 0) {
                    $error = 'Cette clé de faction existe déjà.';
                } else {
                    // Créer la table factions si elle n'existe pas
                    $db->exec("CREATE TABLE IF NOT EXISTS `factions` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `name` varchar(100) NOT NULL,
                        `faction_key` varchar(50) NOT NULL UNIQUE,
                        `color` varchar(20) NOT NULL DEFAULT '#3b82f6',
                        `icon` varchar(50) NOT NULL DEFAULT 'shield',
                        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `faction_key` (`faction_key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $db->prepare("INSERT INTO factions (name, faction_key, color, icon) VALUES (?,?,?,?)")
                        ->execute([$faction_name, $faction_key, $faction_color, $faction_icon]);
                    $success = "Faction \"$faction_name\" créée.";
                    mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Création faction', "Clé $faction_key — $faction_name", 'faction', $faction_key);
                }
            } catch (Exception $e) {
                $error = 'Erreur lors de la création de la faction.';
            }
        } else {
            $error = 'Clé et nom de faction requis.';
        }
    }

    // Modifier une faction
    if ($action === 'update_faction') {
        $faction_key = trim($_POST['faction_key'] ?? '');
        $faction_name = trim($_POST['faction_name'] ?? '');
        $faction_color = trim($_POST['faction_color'] ?? '#3b82f6');
        $faction_icon = trim($_POST['faction_icon'] ?? 'shield');

        if ($faction_key && $faction_name) {
            try {
                // Créer la table factions si elle n'existe pas
                $db->exec("CREATE TABLE IF NOT EXISTS `factions` (
                    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL,
                    `faction_key` varchar(50) NOT NULL UNIQUE,
                    `color` varchar(20) NOT NULL DEFAULT '#3b82f6',
                    `icon` varchar(50) NOT NULL DEFAULT 'shield',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `faction_key` (`faction_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Vérifier si la faction existe dans factions
                $checkStmt = $db->prepare("SELECT id FROM factions WHERE faction_key = ?");
                $checkStmt->execute([$faction_key]);
                
                if ($checkStmt->rowCount() > 0) {
                    // Mettre à jour
                    $db->prepare("UPDATE factions SET name = ?, color = ?, icon = ? WHERE faction_key = ?")
                        ->execute([$faction_name, $faction_color, $faction_icon, $faction_key]);
                } else {
                    // Insérer si n'existe pas
                    $db->prepare("INSERT INTO factions (name, faction_key, color, icon) VALUES (?,?,?,?)")
                        ->execute([$faction_name, $faction_key, $faction_color, $faction_icon]);
                }
                $success = "Faction mise à jour.";
                mdt_audit_log(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'STAFF', 'Modification faction', "Clé $faction_key — $faction_name", 'faction', $faction_key);
            } catch (Exception $e) {
                $error = 'Erreur lors de la mise à jour.';
            }
        } else {
            $error = 'Données incomplètes.';
        }
    }
}

// ── LOAD FACTIONS + GRADES + MEMBERS ──
$enumFactions = [
    'STAFF' => ['name' => 'Staff', 'color' => '#a855f7', 'icon' => 'shield-check'],
    'DOJ' => ['name' => 'DOJ', 'color' => '#ef4444', 'icon' => 'scale-balanced'],
    'USSS' => ['name' => 'USSS', 'color' => '#334155', 'icon' => 'user-police'],
    'LSPD' => ['name' => 'LSPD', 'color' => '#2563eb', 'icon' => 'shield-halved'],
    'LSSD' => ['name' => 'LSSD', 'color' => '#10b981', 'icon' => 'star'],
    'SAMS' => ['name' => 'SAMS', 'color' => '#f43f5e', 'icon' => 'kit-medical'],
    'HAUTE_LUXURY' => ['name' => 'Haute Luxury', 'color' => '#f472b6', 'icon' => 'gem'],
    'BAHAMAS' => ['name' => 'Bahamas', 'color' => '#06b6d4', 'icon' => 'martini-glass-citrus'],
    'BENNYS' => ['name' => "Benny's", 'color' => '#f59e0b', 'icon' => 'screwdrivers-wrench'],
    'LS_CUSTOM' => ['name' => 'LS Custom', 'color' => '#8b5cf6', 'icon' => 'car-wrench'],
    'LS_MOTORS' => ['name' => 'LS Motors', 'color' => '#3b82f6', 'icon' => 'car-rear'],
    'HEAVY_IMPOUND' => ['name' => 'Heavy Impound', 'color' => '#94a3b8', 'icon' => 'truck-ramp-box'],
    'DYNASTY' => ['name' => 'Dynasty', 'color' => '#22c55e', 'icon' => 'city'],
    'LTD' => ['name' => 'LTD Mirror Park', 'color' => '#0ea5e9', 'icon' => 'basket-shopping'],
    'AMMUNATION' => ['name' => 'Ammunation', 'color' => '#ef4444', 'icon' => 'gun'],
    'LIFE_INVADER' => ['name' => 'Life Invader', 'color' => '#6366f1', 'icon' => 'clapperboard'],
    'POSTOP' => ['name' => 'PostOP', 'color' => '#10b981', 'icon' => 'truck-fast'],
    'TAXI' => ['name' => 'Taxi', 'color' => '#eab308', 'icon' => 'taxi'],
    'TRIADS' => ['name' => 'Triads Records', 'color' => '#a855f7', 'icon' => 'compact-disc'],
    'UWU_COFFEE' => ['name' => 'UwU Coffee', 'color' => '#f472b6', 'icon' => 'mug-hot'],
    'RESTAURANT_KOI' => ['name' => 'Restaurant KOI', 'color' => '#f87171', 'icon' => 'shrimp'],
    'UP_ATOM' => ['name' => 'UP & Atom', 'color' => '#fbbf24', 'icon' => 'burger'],
    'GRUPP6'         => ['name' => 'Grupp 6',            'color' => '#64748b', 'icon' => 'truck-shield'],
    'GOUV'           => ['name' => 'Gouvernement',       'color' => '#1d4ed8', 'icon' => 'landmark'],
    'AVOCATS'        => ['name' => "Cabinet d'Avocats",  'color' => '#d97706', 'icon' => 'scale-balanced'],
    'PEARLS_RESORT'  => ['name' => "Pearl's Resort",     'color' => '#d4a853', 'icon' => 'hotel'],
];

// ── GRADES INSTITUTIONNELS (fallback si faction_grades vide / reset DB) ──
// Source : mdt_ref_faction_grade + clé effective = legacy_faction_key ou faction_key (aligné factions.faction_key)
$institutionalRanks = [];
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
        $fk = $row['fk'];
        if ($fk === '') {
            continue;
        }
        if (!isset($institutionalRanks[$fk])) {
            $institutionalRanks[$fk] = [];
        }
        $institutionalRanks[$fk][] = [
            'rank_name'             => $row['rank_name'],
            'rank_level'            => (int) $row['rank_level'],
            'is_owner'              => false,
            'is_ceo'                => false,
            'can_recruit'           => false,
            'can_manage_finances'   => false,
        ];
    }
} catch (Exception $e) {
    $institutionalRanks = [];
}

$customFactionsData = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `factions` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        `faction_key` varchar(50) NOT NULL UNIQUE,
        `color` varchar(20) NOT NULL DEFAULT '#3b82f6',
        `icon` varchar(50) NOT NULL DEFAULT 'shield',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `faction_key` (`faction_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $customRows = $db->query("SELECT faction_key, name, color, icon FROM factions")->fetchAll();
    foreach ($customRows as $row) {
        $customFactionsData[$row['faction_key']] = [
            'name' => $row['name'],
            'color' => $row['color'],
            'icon' => $row['icon'],
        ];
    }
} catch (Exception $e) {}

// Fusionner avec les données custom
foreach ($customFactionsData as $key => $data) {
    $enumFactions[$key] = $data;
}

$factions = [];

// ── Récupérer le comptage de membres par (faction, grade) en une seule requête ──
$memberCountsRaw = $db->query("
    SELECT faction,
           COALESCE(NULLIF(faction_rank,''), NULLIF(faction_grade,'')) AS grade_name,
           COUNT(*) AS cnt
    FROM users
    WHERE faction != 'NONE'
      AND (faction_rank IS NOT NULL AND faction_rank != ''
           OR faction_grade IS NOT NULL AND faction_grade != '')
    GROUP BY faction, grade_name
")->fetchAll(PDO::FETCH_ASSOC);

$globalMembersByGrade = []; // ['FACTION']['grade'] => count
foreach ($memberCountsRaw as $row) {
    if ($row['grade_name'] === null) continue;
    $globalMembersByGrade[$row['faction']][$row['grade_name']] = (int)$row['cnt'];
}

// ── POST : config Discord B + mapping factions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $fAction = $_POST['faction_discord_action'] ?? '';

    if ($fAction === 'save_faction_discord_config') {
        $guildId  = preg_replace('/[^0-9]/', '', trim($_POST['faction_discord_guild_id'] ?? ''));
        $botToken = trim($_POST['faction_discord_bot_token'] ?? '');
        try {
            mdt_faction_sync_save_config($db, $guildId, $botToken);
            $success = 'Configuration Discord B (factions) sauvegardée.';
        } catch (Throwable $e) { $error = 'Erreur : ' . $e->getMessage(); }
    }

    if ($fAction === 'save_faction_discord_map') {
        $mappings = (array)($_POST['faction_role_map'] ?? []);
        try {
            mdt_faction_sync_save_map($db, $mappings);
            $success = 'Mapping Discord → Factions sauvegardé.';
        } catch (Throwable $e) { $error = 'Erreur : ' . $e->getMessage(); }
    }
}

// ── Récupérer tous les grades company_ranks en une fois ──
$allCompanyRanks = $db->query("
    SELECT faction, rank_name, rank_level, is_owner, is_ceo, can_recruit, can_manage_finances
    FROM company_ranks
    ORDER BY faction, rank_level ASC
")->fetchAll(PDO::FETCH_ASSOC);

$companyRanksByFaction = [];
foreach ($allCompanyRanks as $r) {
    $companyRanksByFaction[$r['faction']][] = $r;
}

// ── Récupérer tous les grades faction_grades en une fois ──
$allFactionGrades = [];
try {
    $allFactionGrades = $db->query("
        SELECT f.faction_key, g.id AS grade_id, g.name AS rank_name, g.rank_order AS rank_level,
               COALESCE(g.is_owner, 0) AS is_owner,
               COALESCE(g.is_ceo, 0) AS is_ceo,
               COALESCE(g.can_recruit, 0) AS can_recruit,
               COALESCE(g.can_manage_finances, 0) AS can_manage_finances
        FROM faction_grades g
        JOIN factions f ON f.id = g.faction_id
        ORDER BY f.faction_key, g.rank_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $allFactionGrades = $db->query("
            SELECT f.faction_key, g.id AS grade_id, g.name AS rank_name, g.rank_order AS rank_level,
                   0 AS is_owner, 0 AS is_ceo, 0 AS can_recruit, 0 AS can_manage_finances
            FROM faction_grades g
            JOIN factions f ON f.id = g.faction_id
            ORDER BY f.faction_key, g.rank_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
    }
}
$factionGradesByKey = [];
foreach ($allFactionGrades as $r) {
    $factionGradesByKey[$r['faction_key']][] = $r;
}

foreach ($enumFactions as $factionKey => $factionInfo) {
    $f = [
        'faction_key'     => $factionKey,
        'name'            => $factionInfo['name'],
        'color'           => $factionInfo['color'],
        'icon'            => $factionInfo['icon'],
        'grades'          => [],
        'members_no_grade'=> [],
        'member_count'    => 0,
    ];

    // ── Comptes de membres pour cette faction ──
    $membersByGrade = $globalMembersByGrade[$factionKey] ?? [];

    // ── 1. Construire la liste des grades officiels ──
    // Priorité : faction_grades > company_ranks > institutionalRanks
    $mergedRanks = []; // keyed by rank_name pour dédupliquer

    // Source A — faction_grades (ajouts manuels via l'interface)
    foreach ($factionGradesByKey[$factionKey] ?? [] as $r) {
        $mergedRanks[$r['rank_name']] = $r;
    }

    // Source B — company_ranks (toutes les entreprises, ordonnées par rank_level)
    foreach ($companyRanksByFaction[$factionKey] ?? [] as $r) {
        if (!isset($mergedRanks[$r['rank_name']])) {
            $mergedRanks[$r['rank_name']] = $r;
        }
    }

    // Source C — institutionalRanks (factions publiques : LSPD, SAMS, DOJ, etc.)
    foreach ($institutionalRanks[$factionKey] ?? [] as $r) {
        if (!isset($mergedRanks[$r['rank_name']])) {
            $mergedRanks[$r['rank_name']] = $r;
        }
    }

    // Trier par rank_level ASC
    uasort($mergedRanks, fn($a, $b) => (int)$a['rank_level'] <=> (int)$b['rank_level']);
    $officialRanks   = array_values($mergedRanks);
    $officialNames   = array_column($officialRanks, 'rank_name');

    // ── 2. Ajouter les grades officiels (avec member_count = 0 si personne) ──
    foreach ($officialRanks as $r) {
        $f['grades'][] = [
            'grade_id'            => (int)($r['grade_id'] ?? 0),
            'name'                => $r['rank_name'],
            'rank_level'          => (int)$r['rank_level'],
            'is_owner'            => (bool)($r['is_owner'] ?? false),
            'is_ceo'              => (bool)($r['is_ceo']  ?? false),
            'can_recruit'         => (bool)($r['can_recruit'] ?? false),
            'can_manage_finances' => (bool)($r['can_manage_finances'] ?? false),
            'member_count'        => $membersByGrade[$r['rank_name']] ?? 0,
            'from_users'          => false,
        ];
    }

    // ── 3. Ajouter les grades "orphelins" (présents dans users mais pas dans les sources officielles) ──
    foreach ($membersByGrade as $gradeName => $cnt) {
        if (!in_array($gradeName, $officialNames, true)) {
            $f['grades'][] = [
                'name'                => $gradeName,
                'rank_level'          => 999,
                'is_owner'            => false,
                'is_ceo'              => false,
                'can_recruit'         => false,
                'can_manage_finances' => false,
                'member_count'        => $cnt,
                'from_users'          => true,
            ];
        }
    }

    // ── 4. Membres sans grade ──
    $noGradeStmt = $db->prepare("
        SELECT id, username FROM users
        WHERE faction = ?
          AND (faction_rank IS NULL OR faction_rank = '')
          AND (faction_grade IS NULL OR faction_grade = '')
        ORDER BY username ASC
    ");
    $noGradeStmt->execute([$factionKey]);
    $f['members_no_grade'] = $noGradeStmt->fetchAll();

    // ── 5. Total membres ──
    $countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE faction = ?");
    $countStmt->execute([$factionKey]);
    $f['member_count'] = (int)$countStmt->fetchColumn();

    $factions[] = $f;
}

// ── Données Discord B (factions) ─────────────────────────────────────────────
$factionDiscordCfg  = mdt_faction_sync_get_config($db);
$factionDiscordMap  = mdt_faction_sync_get_map($db);
$factionSyncLogs    = [];
try {
    $factionSyncLogs = $db->query("SELECT * FROM faction_discord_sync_log ORDER BY synced_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ════════════════════════════════════════════════════════════════ */
/* PAGE HEADER */
/* ════════════════════════════════════════════════════════════════ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 28px;
    gap: 20px;
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

.page-header-left p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* ════════════════════════════════════════════════════════════════ */
/* STATS BAR */
/* ════════════════════════════════════════════════════════════════ */
.stats-bar {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.stat-card {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 150px;
}

.stat-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.stat-card-info { flex: 1; }

.stat-card-number {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
    margin-bottom: 2px;
}

.stat-card-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* ════════════════════════════════════════════════════════════════ */
/* FILTER BAR */
/* ════════════════════════════════════════════════════════════════ */
.filter-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-wrap {
    position: relative;
    flex: 1;
    min-width: 200px;
    max-width: 340px;
}

.search-input-wrap i {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 0.82rem;
    pointer-events: none;
}

.search-input-wrap input {
    width: 100%;
    padding: 8px 12px 8px 34px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-elevated);
    color: var(--text);
    font-size: 0.88rem;
    box-sizing: border-box;
}

.search-input-wrap input:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.filter-chip {
    padding: 5px 13px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--bg-elevated);
    color: var(--text-secondary);
    transition: all 0.2s;
}

.filter-chip:hover,
.filter-chip.active {
    background: var(--blue);
    border-color: var(--blue);
    color: white;
}

/* ════════════════════════════════════════════════════════════════ */
/* ALERTS */
/* ════════════════════════════════════════════════════════════════ */
.alert {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.88rem;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.alert-success {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.25);
    color: #22c55e;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #ef4444;
}

/* ════════════════════════════════════════════════════════════════ */
/* FACTIONS GRID */
/* ════════════════════════════════════════════════════════════════ */
.factions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 14px;
    margin-bottom: 30px;
}

.faction-card {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    transition: all 0.22s ease;
    position: relative;
}

.faction-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--faction-color, var(--blue));
    opacity: 0;
    transition: opacity 0.22s;
}

.faction-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    border-color: var(--faction-color, var(--blue));
}

.faction-card:hover::before { opacity: 1; }

.faction-card-header {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.faction-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.faction-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    overflow: hidden;
}

/* Pastille de faction dans un titre : accueille indifféremment un logo
   (admin_panel_config.custom_logo_url) ou l'icône FontAwesome de repli. */
.faction-glyph {
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    vertical-align: -4px;
}

.faction-header-info { flex: 1; min-width: 0; }

.faction-name {
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--text);
    margin-bottom: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.faction-key {
    font-size: 0.72rem;
    color: var(--text-secondary);
    font-family: monospace;
    background: var(--bg-overlay);
    padding: 1px 6px;
    border-radius: 4px;
    display: inline-block;
}

.faction-header-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.faction-header-actions button {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: var(--bg-overlay);
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    transition: all 0.18s;
}

.faction-header-actions button:hover {
    background: var(--blue);
    border-color: var(--blue);
    color: white;
}

.faction-header-actions button.btn-danger:hover {
    background: #ef4444;
    border-color: #ef4444;
}

/* ════════════════════════════════════════════════════════════════ */
/* STATS ROW */
/* ════════════════════════════════════════════════════════════════ */
.faction-stats-row {
    display: flex;
    background: var(--bg-overlay);
    border-bottom: 1px solid var(--border);
}

.faction-stat-cell {
    flex: 1;
    padding: 8px 12px;
    text-align: center;
    position: relative;
}

.faction-stat-cell + .faction-stat-cell::before {
    content: '';
    position: absolute;
    left: 0; top: 15%; height: 70%;
    width: 1px;
    background: var(--border);
}

.faction-stat-cell .num {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--faction-color, var(--blue));
    line-height: 1.1;
}

.faction-stat-cell .lbl {
    font-size: 0.68rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* ════════════════════════════════════════════════════════════════ */
/* FACTION BODY */
/* ════════════════════════════════════════════════════════════════ */
.faction-card-body {
    padding: 12px 14px;
}

.grades-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.grades-title {
    font-weight: 700;
    font-size: 0.72rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.grades-list {
    max-height: 280px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-bottom: 10px;
}

.grades-list::-webkit-scrollbar { width: 4px; }
.grades-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

.grade-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 9px;
    border-radius: 7px;
    background: var(--bg-overlay);
    font-size: 0.82rem;
    border: 1px solid transparent;
    transition: border-color 0.15s;
}

.grade-item:hover { border-color: var(--border); }

.grade-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}

.grade-info { flex: 1; min-width: 0; }

.grade-name {
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.grade-count {
    font-size: 0.68rem;
    color: var(--text-muted);
}

.grade-actions button {
    width: 22px;
    height: 22px;
    border-radius: 5px;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    transition: all 0.15s;
    flex-shrink: 0;
}

.grade-actions button:hover {
    background: rgba(239, 68, 68, 0.12);
    color: #ef4444;
}

.no-grades {
    padding: 16px;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.8rem;
    background: var(--bg-overlay);
    border-radius: 8px;
    margin-bottom: 10px;
}

.no-grades i {
    display: block;
    font-size: 1.3rem;
    opacity: 0.3;
    margin-bottom: 5px;
}

.faction-actions { display: flex; gap: 8px; }

/* ════════════════════════════════════════════════════════════════ */
/* BUTTONS */
/* ════════════════════════════════════════════════════════════════ */
.btn {
    padding: 8px 15px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.86rem;
    cursor: pointer;
    transition: all 0.18s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-primary { background: var(--blue); color: white; }
.btn-primary:hover { background: #2563eb; transform: translateY(-1px); }

.btn-secondary {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    color: var(--text);
}
.btn-secondary:hover { background: var(--bg-overlay); }

.btn-sm { padding: 7px 12px; font-size: 0.82rem; }
.btn-xs { padding: 3px 8px; font-size: 0.74rem; }
.w-full { width: 100%; justify-content: center; }

/* ════════════════════════════════════════════════════════════════ */
/* MODALES */
/* ════════════════════════════════════════════════════════════════ */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(4px);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.active { display: flex; }

.modal {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalIn 0.2s ease-out;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-close {
    background: var(--bg-overlay);
    border: 1px solid var(--border);
    border-radius: 7px;
    font-size: 0.88rem;
    cursor: pointer;
    color: var(--text-secondary);
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}

.modal-close:hover { color: var(--text); background: var(--bg-elevated); }

.modal-body { padding: 20px 22px; }

.modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ════════════════════════════════════════════════════════════════ */
/* FORMS */
/* ════════════════════════════════════════════════════════════════ */
.form-group { margin-bottom: 14px; }
.form-group:last-child { margin-bottom: 0; }

.form-label {
    display: block;
    font-weight: 600;
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-overlay);
    color: var(--text);
    font-size: 0.9rem;
    box-sizing: border-box;
    transition: border-color 0.15s, box-shadow 0.15s;
}

.form-control:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.grade-level-badge {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 800;
    flex-shrink: 0;
    font-family: monospace;
}

.grade-badge-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 4px;
    font-size: 0.6rem;
    margin-left: 3px;
    vertical-align: middle;
}

.grade-occupied .grade-name {
    color: var(--text);
}

/* Tous les grades sont affichés normalement, occupés ou non */
.grade-item {
    opacity: 1;
}

/* Badge "Vacant" pour les grades sans membre */
.grade-vacant-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    border: 1.5px dashed var(--border);
    flex-shrink: 0;
}

/* ════════════════════════════════════════════════════════════════ */
/* GRADE DRAG & EDIT                                              */
/* ════════════════════════════════════════════════════════════════ */

/* Poignée de drag */
.grade-drag-handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 5px;
    cursor: grab;
    color: var(--text-muted);
    opacity: .35;
    transition: opacity .15s, color .15s, background .15s;
    flex-shrink: 0;
    font-size: .75rem;
    user-select: none;
}
.grade-drag-handle:hover,
.grade-item:hover .grade-drag-handle {
    opacity: 1;
    color: var(--text-secondary);
    background: var(--bg-overlay);
}
.grade-drag-handle:active { cursor: grabbing; }

/* Bouton modifier grade */
.btn-edit-grade {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    cursor: pointer;
    color: var(--text-muted);
    width: 24px; height: 24px;
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem;
    transition: all .15s;
    opacity: 0;
}
.grade-item:hover .btn-edit-grade {
    opacity: 1;
}
.btn-edit-grade:hover {
    background: rgba(59,130,246,.12);
    border-color: rgba(59,130,246,.25);
    color: var(--blue);
}

/* État drag en cours */
.grade-item.dragging {
    opacity: .35;
    background: var(--bg-overlay) !important;
    box-shadow: none !important;
}
.grade-item.drag-over {
    border-color: var(--blue) !important;
    background: rgba(59,130,246,.06) !important;
    box-shadow: 0 0 0 2px rgba(59,130,246,.18);
}

/* Indicateur de drop entre items */
.grade-drop-indicator {
    height: 3px;
    border-radius: 3px;
    background: var(--blue);
    margin: 2px 0;
    box-shadow: 0 0 8px rgba(59,130,246,.4);
    pointer-events: none;
    display: none;
}
.grade-drop-indicator.visible { display: block; }

/* Toast sauvegarde */
.grade-toast {
    position: fixed;
    bottom: 24px; right: 24px;
    z-index: 99999;
    padding: 10px 16px;
    border-radius: 10px;
    font-size: .82rem; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
    box-shadow: 0 8px 28px rgba(0,0,0,.3);
    animation: toastIn .25s cubic-bezier(.34,1.56,.64,1);
    transition: opacity .2s;
}
.grade-toast.success {
    background: rgba(34,197,94,.12);
    border: 1px solid rgba(34,197,94,.3);
    color: #22c55e;
}
.grade-toast.error {
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
    color: #ef4444;
}
.grade-toast.saving {
    background: rgba(59,130,246,.1);
    border: 1px solid rgba(59,130,246,.25);
    color: var(--blue);
}
@keyframes toastIn {
    from { transform: translateY(16px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.form-hint {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 4px;
}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-header-left">
        <h1>
            <i class="fa-solid fa-shield-halved" style="color: var(--blue);"></i>
            Rôles & Factions
        </h1>
        <p><?php echo count($factions); ?> factions &mdash; <?php echo array_sum(array_column($factions, 'member_count')); ?> membres au total</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-create-faction')">
        <i class="fa-solid fa-plus"></i> Nouvelle faction
    </button>
</div>

<!-- STATS BAR -->
<div class="stats-bar">
    <div class="stat-card">
        <div class="stat-card-icon" style="background: rgba(59,130,246,0.12); color: var(--blue);">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div class="stat-card-info">
            <div class="stat-card-number"><?php echo count($factions); ?></div>
            <div class="stat-card-label">Factions</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background: rgba(34,197,94,0.12); color: #22c55e;">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-card-info">
            <div class="stat-card-number"><?php echo array_sum(array_column($factions, 'member_count')); ?></div>
            <div class="stat-card-label">Membres</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background: rgba(168,85,247,0.12); color: #a855f7;">
            <i class="fa-solid fa-tags"></i>
        </div>
        <div class="stat-card-info">
            <div class="stat-card-number"><?php echo array_sum(array_map(function($f){ return count($f['grades']); }, $factions)); ?></div>
            <div class="stat-card-label">Grades</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background: rgba(245,158,11,0.12); color: #f59e0b;">
            <i class="fa-solid fa-user-slash"></i>
        </div>
        <div class="stat-card-info">
            <div class="stat-card-number"><?php echo array_sum(array_map(function($f){ return count($f['members_no_grade']); }, $factions)); ?></div>
            <div class="stat-card-label">Sans grade</div>
        </div>
    </div>
</div>

<!-- ALERTES -->
<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- FILTER BAR -->
<div class="filter-bar">
    <div class="search-input-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="search-factions" placeholder="Rechercher une faction..." oninput="filterFactions()">
    </div>
    <div class="filter-chips">
        <button class="filter-chip active" onclick="setFilter('all', this)">Toutes</button>
        <button class="filter-chip" onclick="setFilter('has-members', this)">Avec membres</button>
        <button class="filter-chip" onclick="setFilter('no-members', this)">Vides</button>
    </div>
</div>

<!-- FACTIONS GRID -->
<div class="factions-grid" id="factions-grid">
    <?php foreach ($factions as $f): ?>
    <div class="faction-card <?php echo $f['member_count'] === 0 ? 'empty-card' : ''; ?>"
         data-faction-name="<?php echo strtolower(htmlspecialchars($f['name'])); ?>"
         data-faction-key="<?php echo strtolower(htmlspecialchars($f['faction_key'])); ?>"
         data-has-members="<?php echo $f['member_count'] > 0 ? '1' : '0'; ?>"
         style="--faction-color: <?php echo htmlspecialchars($f['color']); ?>;">

        <!-- HEADER -->
        <div class="faction-card-header">
            <div class="faction-header-left">
                <div class="faction-icon" style="background: <?php echo htmlspecialchars($f['color']); ?>22; color: <?php echo htmlspecialchars($f['color']); ?>;">
                    <?php echo faction_logo_img($db, $f['faction_key'], $f['icon'], 3); ?>
                </div>
                <div class="faction-header-info">
                    <div class="faction-name"><?php echo htmlspecialchars($f['name']); ?></div>
                    <div class="faction-key"><?php echo htmlspecialchars($f['faction_key']); ?></div>
                </div>
            </div>
            <div class="faction-header-actions">
                <button onclick="editFaction('<?php echo $f['faction_key']; ?>', '<?php echo addslashes($f['name']); ?>', '<?php echo $f['color']; ?>', '<?php echo $f['icon']; ?>')" title="Modifier">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <button onclick="openModal('modal-members-<?php echo $f['faction_key']; ?>')" title="Gérer les membres">
                    <i class="fa-solid fa-users"></i>
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer la faction <?php echo addslashes($f['name']); ?> ?');">
                    <input type="hidden" name="action" value="delete_faction">
                    <input type="hidden" name="faction_key" value="<?php echo $f['faction_key']; ?>">
                    <button type="submit" title="Supprimer" class="btn-danger">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- STATS ROW -->
        <div class="faction-stats-row">
            <div class="faction-stat-cell">
                <div class="num"><?php echo $f['member_count']; ?></div>
                <div class="lbl">Membres</div>
            </div>
            <div class="faction-stat-cell">
                <div class="num"><?php echo count($f['grades']); ?></div>
                <div class="lbl">Grades</div>
            </div>
            <div class="faction-stat-cell">
                <div class="num"><?php echo count($f['members_no_grade']); ?></div>
                <div class="lbl">Sans grade</div>
            </div>
        </div>

        <!-- BODY -->
        <div class="faction-card-body">
            <!-- GRADES -->
            <div class="grades-header">
                <div class="grades-title"><i class="fa-solid fa-tags" style="margin-right:4px;opacity:.6;"></i>Grades</div>
            </div>
            <?php if (!empty($f['grades'])): ?>
            <div class="grades-list" id="grades-list-<?php echo $f['faction_key']; ?>"
                 data-faction="<?php echo htmlspecialchars($f['faction_key']); ?>">
                <?php foreach ($f['grades'] as $g): ?>
                <div class="grade-item <?php echo $g['member_count'] > 0 ? 'grade-occupied' : ''; ?>"
                     draggable="true"
                     data-grade-name="<?php echo htmlspecialchars($g['name'], ENT_QUOTES); ?>"
                     data-grade-id="<?php echo (int)($g['grade_id'] ?? 0); ?>"
                     data-rank="<?php echo $g['rank_level']; ?>"
                     data-faction="<?php echo htmlspecialchars($f['faction_key']); ?>"
                     data-color="<?php echo htmlspecialchars($f['color']); ?>">

                    <!-- Poignée de glisser -->
                    <div class="grade-drag-handle" title="Maintenir pour déplacer">
                        <i class="fa-solid fa-grip-vertical"></i>
                    </div>

                    <!-- Niveau -->
                    <div class="grade-level-badge grade-rank-badge"
                         style="background: <?php echo htmlspecialchars($f['color']); ?>22; color: <?php echo htmlspecialchars($f['color']); ?>; <?php echo $g['member_count'] === 0 ? 'opacity:.45;' : ''; ?>">
                        <?php echo ($g['rank_level'] < 99 && $g['rank_level'] < 999) ? $g['rank_level'] : '—'; ?>
                    </div>

                    <div class="grade-info">
                        <div class="grade-name" style="<?php echo $g['member_count'] === 0 ? 'color:var(--text-secondary);' : ''; ?>">
                            <?php echo htmlspecialchars($g['name']); ?>
                            <?php if (!empty($g['from_users'])): ?>
                                <span class="grade-badge-pill" style="background:rgba(148,163,184,0.15);color:#94a3b8;" title="Grade issu des données utilisateurs"><i class="fa-solid fa-user-tag"></i></span>
                            <?php endif; ?>
                            <?php if ($g['is_owner']): ?>
                                <span class="grade-badge-pill" style="background:rgba(245,158,11,0.15);color:#f59e0b;" title="Patron / PDG"><i class="fa-solid fa-crown"></i></span>
                            <?php elseif ($g['is_ceo']): ?>
                                <span class="grade-badge-pill" style="background:rgba(99,102,241,0.15);color:#6366f1;" title="Co-Patron / Co-PDG"><i class="fa-solid fa-star"></i></span>
                            <?php endif; ?>
                            <?php if ($g['can_recruit']): ?>
                                <span class="grade-badge-pill" style="background:rgba(34,197,94,0.12);color:#22c55e;" title="Peut recruter"><i class="fa-solid fa-user-plus"></i></span>
                            <?php endif; ?>
                            <?php if ($g['can_manage_finances']): ?>
                                <span class="grade-badge-pill" style="background:rgba(59,130,246,0.12);color:#3b82f6;" title="Gestion finances"><i class="fa-solid fa-coins"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="grade-count">
                            <?php if ($g['member_count'] > 0): ?>
                                <span style="color: <?php echo htmlspecialchars($f['color']); ?>; font-weight:700;"><?php echo $g['member_count']; ?></span>
                                <span> membre<?php echo $g['member_count'] > 1 ? 's' : ''; ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—&nbsp;Vacant</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grade-actions">
                        <!-- Bouton Modifier -->
                        <button title="Modifier ce grade"
                                class="btn-edit-grade"
                                onclick="openEditGrade(
                                    '<?php echo htmlspecialchars($f['faction_key'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars(addslashes($g['name'])); ?>',
                                    <?php echo (int)$g['rank_level']; ?>,
                                    '<?php echo htmlspecialchars($f['color'], ENT_QUOTES); ?>',
                                    <?php echo (int)($g['grade_id'] ?? 0); ?>
                                )">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <!-- Bouton Supprimer -->
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer le grade «<?php echo addslashes($g['name']); ?>» ?');">
                            <input type="hidden" name="action" value="delete_grade">
                            <input type="hidden" name="faction_key" value="<?php echo $f['faction_key']; ?>">
                            <input type="hidden" name="grade_name" value="<?php echo htmlspecialchars($g['name']); ?>">
                            <button type="submit" title="Supprimer">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-grades">
                <i class="fa-solid fa-inbox"></i>
                Aucun grade défini
            </div>
            <?php endif; ?>

            <!-- ACTIONS -->
            <div class="faction-actions">
                <button class="btn btn-secondary btn-sm w-full" onclick="openModal('modal-add-grade-<?php echo $f['faction_key']; ?>')">
                    <i class="fa-solid fa-plus"></i> Ajouter un grade
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- MODALES: CRÉER FACTION -->
<div class="modal-overlay" id="modal-create-faction">
    <div class="modal">
        <div class="modal-header">
            <h3>
                <i class="fa-solid fa-shield" style="color: var(--blue);"></i>
                Créer une faction
            </h3>
            <button class="modal-close" onclick="closeModal('modal-create-faction')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_faction">
                
                <div class="form-group">
                    <label class="form-label">Clé de la faction</label>
                    <input type="text" name="faction_key" class="form-control" placeholder="Ex: LSPD" style="font-family: monospace; text-transform: uppercase;" required>
                    <div class="form-hint">Majuscules et underscores uniquement (HAUTE_LUXURY)</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nom de la faction</label>
                    <input type="text" name="faction_name" class="form-control" placeholder="Ex: LSPD" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Couleur</label>
                    <input type="color" name="faction_color" class="form-control" value="#3b82f6" style="height: 42px; padding: 4px;">
                </div>

                <div class="form-group">
                    <label class="form-label">Icône FontAwesome</label>
                    <input type="text" name="faction_icon" class="form-control" placeholder="shield-halved" value="shield">
                    <div class="form-hint">Nom sans "fa-"</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-faction')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Créer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODALES: MODIFIER FACTION -->
<div class="modal-overlay" id="modal-edit-faction">
    <div class="modal">
        <div class="modal-header">
            <h3>
                <i class="fa-solid fa-pen" style="color: var(--blue);"></i>
                Modifier la faction
            </h3>
            <button class="modal-close" onclick="closeModal('modal-edit-faction')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_faction">
                <input type="hidden" name="faction_key" id="edit-faction-key" value="">
                
                <div class="form-group">
                    <label class="form-label">Nom de la faction</label>
                    <input type="text" name="faction_name" class="form-control" id="edit-faction-name" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Couleur</label>
                    <input type="color" name="faction_color" class="form-control" id="edit-faction-color" style="height: 42px; padding: 4px;">
                </div>

                <div class="form-group">
                    <label class="form-label">Icône FontAwesome</label>
                    <input type="text" name="faction_icon" class="form-control" id="edit-faction-icon">
                    <div class="form-hint">Nom sans "fa-"</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-faction')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-save"></i> Sauvegarder
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODALES: AJOUTER GRADE -->
<?php foreach ($factions as $f): ?>
<div class="modal-overlay" id="modal-add-grade-<?php echo $f['faction_key']; ?>">
    <div class="modal">
        <div class="modal-header">
            <h3>
                <span class="faction-glyph" style="color: <?php echo htmlspecialchars($f['color']); ?>;"><?php echo faction_logo_img($db, $f['faction_key'], $f['icon'], 0); ?></span>
                Ajouter un grade
            </h3>
            <button class="modal-close" onclick="closeModal('modal-add-grade-<?php echo $f['faction_key']; ?>')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_grade">
                <input type="hidden" name="faction_key" value="<?php echo $f['faction_key']; ?>">
                
                <div class="form-group">
                    <label class="form-label">Nom du grade</label>
                    <input type="text" name="grade_name" class="form-control" placeholder="Ex: Officer I" required>
                    <div class="form-hint">Le grade sera créé dans la faction</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-grade-<?php echo $f['faction_key']; ?>')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Créer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODALES: GÉRER MEMBRES -->
<div class="modal-overlay" id="modal-members-<?php echo $f['faction_key']; ?>">
    <div class="modal">
        <div class="modal-header">
            <h3>
                <span class="faction-glyph" style="color: <?php echo htmlspecialchars($f['color']); ?>;"><?php echo faction_logo_img($db, $f['faction_key'], $f['icon'], 0); ?></span>
                Gérer les membres
            </h3>
            <button class="modal-close" onclick="closeModal('modal-members-<?php echo $f['faction_key']; ?>')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <div style="max-height: 400px; overflow-y: auto;">
                <?php
                // Tous les utilisateurs de cette faction
                $allUsersStmt = $db->prepare("SELECT id, username, faction_rank FROM users WHERE faction = ? ORDER BY faction_rank DESC, username ASC");
                $allUsersStmt->execute([$f['faction_key']]);
                $allUsers = $allUsersStmt->fetchAll();
                
                if (!empty($allUsers)):
                    foreach ($allUsers as $u):
                ?>
                <form method="POST" style="display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <input type="hidden" name="action" value="update_user_grade">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    
                    <div style="width: 32px; height: 32px; border-radius: 6px; background: var(--bg-overlay); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0; color: <?php echo htmlspecialchars($f['color']); ?>;">
                        <?php echo strtoupper(substr($u['username'], 0, 1)); ?>
                    </div>
                    
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--text);">
                            <?php echo htmlspecialchars($u['username']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                            <?php echo htmlspecialchars($u['faction_rank'] ?: 'Sans grade'); ?>
                        </div>
                    </div>
                    
                    <select name="grade_name" class="form-control" style="flex: 0 1 140px; padding: 6px 10px; font-size: 0.85rem;">
                        <option value="">— Sans grade —</option>
                        <?php foreach ($f['grades'] as $g): ?>
                        <option value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo $u['faction_rank'] === $g['name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($g['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-xs" style="flex-shrink: 0;">
                        <i class="fa-solid fa-save"></i>
                    </button>
                </form>
                <?php 
                    endforeach;
                else:
                ?>
                <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">
                    <i class="fa-solid fa-inbox" style="font-size: 2rem; opacity: 0.5; display: block; margin-bottom: 8px;"></i>
                    Aucun membre
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-members-<?php echo $f['faction_key']; ?>')">
                Fermer
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ═══════════════════════════════════════════════════════════════════════
     SECTION : SYNCHRONISATION DISCORD B — FACTIONS
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
.fd-section{margin-top:28px;display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:flex-start}
@media(max-width:1100px){.fd-section{grid-template-columns:1fr}}
.fd-card{background:var(--bg-elevated);border:1px solid rgba(88,101,242,.25);border-radius:14px;overflow:hidden}
.fd-card-header{padding:14px 18px;background:linear-gradient(135deg,rgba(88,101,242,.12),rgba(88,101,242,.04));border-bottom:1px solid rgba(88,101,242,.2);display:flex;align-items:center;gap:10px;font-weight:700;font-size:.88rem}
.fd-card-header i{color:#7289da;font-size:1rem}
.fd-card-body{padding:16px 18px}
.fd-map-table{width:100%;border-collapse:collapse;font-size:.8rem}
.fd-map-table th{padding:6px 10px;text-align:left;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em}
.fd-map-table td{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.fd-map-table tr:last-child td{border-bottom:none}
.fd-role-input{width:160px;padding:5px 8px;background:var(--bg-overlay);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:.75rem;font-family:monospace}
.fd-role-input:focus{outline:none;border-color:#7289da;box-shadow:0 0 0 2px rgba(88,101,242,.15)}
.fd-status-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:4px;font-size:.65rem;font-weight:700}
.fd-status-ok{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.25)}
.fd-status-no{background:rgba(100,116,139,.08);color:#64748b;border:1px solid rgba(100,116,139,.15)}
.fd-prog-bar{height:26px;background:rgba(255,255,255,.06);border-radius:7px;overflow:hidden;margin-bottom:10px;position:relative}
.fd-prog-fill{height:100%;background:linear-gradient(90deg,#7289da,#5865f2);border-radius:7px;width:0%;transition:width .3s;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:white;min-width:28px}
.fd-log-table{font-size:.75rem;width:100%;border-collapse:collapse}
.fd-log-table td,.fd-log-table th{padding:5px 10px;border-bottom:1px solid rgba(255,255,255,.04);text-align:left}
.fd-log-table thead th{color:var(--text-secondary);font-weight:600;font-size:.68rem;text-transform:uppercase;border-bottom:1px solid var(--border)}
</style>

<!-- Titre section -->
<div style="margin-top:36px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
    <div style="width:40px;height:40px;border-radius:10px;background:rgba(88,101,242,.18);border:1px solid rgba(88,101,242,.35);display:flex;align-items:center;justify-content:center">
        <i class="fa-brands fa-discord" style="color:#7289da;font-size:1.2rem"></i>
    </div>
    <div>
        <div style="font-weight:800;font-size:1.05rem">Synchronisation Discord B — Factions</div>
        <div style="font-size:.78rem;color:var(--text-secondary)">Serveur Discord dédié aux factions RP. Indépendant du Serveur A (rôles MDT).</div>
    </div>
</div>

<div class="fd-section">

    <!-- Colonne gauche : Config + Resync -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Config Serveur B -->
        <div class="fd-card">
            <div class="fd-card-header"><i class="fa-brands fa-discord"></i>Serveur Discord B</div>
            <div class="fd-card-body">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="faction_discord_action" value="save_faction_discord_config">
                    <div class="form-group">
                        <label class="form-label">Guild ID (Serveur B)</label>
                        <input type="text" name="faction_discord_guild_id" class="form-control" value="<?php echo htmlspecialchars($factionDiscordCfg['guild_id']); ?>" placeholder="Ex: 123456789012345678">
                        <div class="form-hint">Clic droit sur le serveur → Copier l'identifiant</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bot Token Discord <span style="color:#ef4444">*</span></label>
                        <input type="password" name="faction_discord_bot_token" class="form-control" value="<?php echo htmlspecialchars($factionDiscordCfg['bot_token']); ?>" autocomplete="new-password" placeholder="MTxxxxxxxxxxxxxxxx...">
                        <div class="form-hint">
                            <a href="https://discord.com/developers/applications" target="_blank" style="color:#7289da">Discord Developer Portal</a> → Bot → Reset Token
                            <?php if ($factionDiscordCfg['bot_token'] !== ''): ?>
                            <span style="color:#22c55e;margin-left:6px">✓ Configuré</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-save"></i> Sauvegarder</button>
                </form>
            </div>
        </div>

        <!-- Resync -->
        <div class="fd-card">
            <div class="fd-card-header"><i class="fa-solid fa-rotate"></i>Resynchronisation factions</div>
            <div class="fd-card-body">
                <p style="font-size:.8rem;color:var(--text-secondary);margin-bottom:14px;line-height:1.5">
                    Attribue automatiquement la faction MDT de chaque membre selon ses rôles sur le Serveur B.<br>
                    <span style="font-size:.72rem;opacity:.7">Traitement asynchrone — pas de timeout.</span>
                </p>
                <button class="btn btn-sm" style="background:rgba(88,101,242,.15);border:1px solid rgba(88,101,242,.35);color:#7289da" onclick="startFactionResync()">
                    <i class="fa-solid fa-rotate"></i> Resync toutes les factions
                </button>
            </div>
        </div>

        <!-- Dernières syncs -->
        <?php if (!empty($factionSyncLogs)): ?>
        <div class="fd-card">
            <div class="fd-card-header"><i class="fa-solid fa-scroll"></i>Dernières synchronisations</div>
            <div style="max-height:240px;overflow-y:auto">
                <table class="fd-log-table">
                    <thead><tr><th>Utilisateur</th><th>Avant</th><th>Après</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($factionSyncLogs as $sl): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($sl['username'] ?? $sl['discord_id'] ?? '—'); ?></td>
                        <td style="color:var(--text-muted)"><?php echo htmlspecialchars($sl['old_faction'] ?? '—'); ?></td>
                        <td><strong style="color:<?php echo ($sl['new_faction'] && $sl['new_faction'] !== 'NONE') ? '#22c55e' : '#64748b'; ?>"><?php echo htmlspecialchars($sl['new_faction'] ?? '—'); ?></strong></td>
                        <td style="color:var(--text-muted)"><?php echo date('d/m H:i', strtotime($sl['synced_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /colonne gauche -->

    <!-- Colonne droite : Mapping factions → Discord Role IDs -->
    <div class="fd-card">
        <div class="fd-card-header"><i class="fa-solid fa-link"></i>Mapping Factions ↔ Discord Role IDs</div>
        <div class="fd-card-body">
            <p style="font-size:.78rem;color:var(--text-secondary);margin-bottom:14px;line-height:1.5">
                Pour chaque faction, renseigne l'ID du rôle Discord correspondant sur le <strong>Serveur B</strong>.<br>
                Laisse vide pour ne pas synchroniser cette faction.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="faction_discord_action" value="save_faction_discord_map">

                <!-- Barre de recherche -->
                <div style="position:relative;margin-bottom:12px">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.75rem;pointer-events:none"></i>
                    <input type="text" id="fdMapSearch" placeholder="Filtrer les factions…"
                           oninput="fdFilterMap(this.value)"
                           style="width:100%;padding:7px 12px 7px 32px;border:1px solid var(--border);border-radius:8px;background:var(--bg-overlay);color:var(--text);font-size:.8rem;box-sizing:border-box">
                </div>

                <div style="max-height:520px;overflow-y:auto">
                <table class="fd-map-table">
                    <thead><tr>
                        <th>Faction</th>
                        <th style="text-align:center">Statut</th>
                        <th>Discord Role ID</th>
                    </tr></thead>
                    <tbody id="fdMapBody">
                    <?php foreach ($factions as $f):
                        $fk      = $f['faction_key'];
                        $roleId  = $factionDiscordMap[$fk]['discord_role_id'] ?? '';
                    ?>
                    <tr data-fname="<?php echo strtolower(htmlspecialchars($f['name'])); ?>" data-fkey="<?php echo strtolower($fk); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="width:28px;height:28px;border-radius:7px;background:<?php echo $f['color']; ?>22;color:<?php echo $f['color']; ?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0">
                                    <?php echo faction_logo_img($db, $fk, $f['icon'], 2); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:.8rem"><?php echo htmlspecialchars($f['name']); ?></div>
                                    <div style="font-size:.65rem;color:var(--text-muted);font-family:monospace"><?php echo $fk; ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center">
                            <?php if ($roleId !== ''): ?>
                            <span class="fd-status-badge fd-status-ok"><i class="fa-brands fa-discord" style="font-size:.6rem"></i> Lié</span>
                            <?php else: ?>
                            <span class="fd-status-badge fd-status-no">Non lié</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" class="fd-role-input" name="faction_role_map[<?php echo $fk; ?>]"
                                   value="<?php echo htmlspecialchars($roleId); ?>"
                                   placeholder="ID rôle Discord…">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="margin-top:14px;display:flex;justify-content:flex-end">
                    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-save"></i> Sauvegarder le mapping</button>
                </div>
            </form>
        </div>
    </div>

</div><!-- /fd-section -->

<!-- ═══ MODAL RESYNC FACTIONS ═══ -->
<div id="fdResyncModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:300;place-items:center;padding:20px">
    <div style="background:var(--bg-elevated);border:1px solid rgba(88,101,242,.3);border-radius:16px;width:100%;max-width:520px;padding:24px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
            <i class="fa-brands fa-discord" style="color:#7289da;font-size:1.2rem"></i>
            <h3 style="font-size:.95rem;font-weight:800;margin:0">Resync Factions — Discord B</h3>
        </div>
        <div class="fd-prog-bar"><div id="fdProgFill" class="fd-prog-fill"></div></div>
        <div id="fdProgStatus" style="font-size:.82rem;color:var(--text-secondary);margin-bottom:10px">Initialisation…</div>
        <div id="fdProgLog" style="max-height:200px;overflow-y:auto;background:rgba(0,0,0,.25);border-radius:8px;padding:10px;font-family:monospace;font-size:.72rem;line-height:1.6;border:1px solid var(--border)">
            <span style="color:var(--text-muted)">En attente…</span>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:16px">
            <button id="fdClosBtn" class="btn btn-secondary" disabled onclick="document.getElementById('fdResyncModal').style.display='none';location.reload()">
                <i class="fa-solid fa-check"></i> Fermer
            </button>
        </div>
    </div>
</div>

<script>
// ── Filtre faction dans le mapping ────────────────────────────────────────────
function fdFilterMap(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#fdMapBody tr').forEach(tr => {
        const n = (tr.dataset.fname || '') + ' ' + (tr.dataset.fkey || '');
        tr.style.display = (!q || n.includes(q)) ? '' : 'none';
    });
}

// ── Resync factions AJAX (un par un) ─────────────────────────────────────────
function fdEsc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function startFactionResync() {
    const modal  = document.getElementById('fdResyncModal');
    const fill   = document.getElementById('fdProgFill');
    const status = document.getElementById('fdProgStatus');
    const log    = document.getElementById('fdProgLog');
    const btn    = document.getElementById('fdClosBtn');

    fill.style.width = '0%'; fill.textContent = '';
    log.innerHTML    = '';
    status.textContent = 'Chargement des utilisateurs…';
    btn.disabled     = true;
    modal.style.display = 'grid';

    // 1. Récupérer la liste
    let users = [];
    try {
        const r = await fetch(window.location.pathname + '?action=get_faction_discord_users');
        const d = await r.json();
        users   = d.users || [];
    } catch(e) {
        status.textContent = 'Erreur : impossible de charger les utilisateurs.';
        btn.disabled = false;
        return;
    }

    if (users.length === 0) {
        status.textContent = 'Aucun utilisateur avec un compte Discord lié.';
        btn.disabled = false;
        return;
    }

    let synced = 0, changed = 0, errors = 0;
    status.textContent = '0 / ' + users.length + ' synchronisés…';

    // 2. Traitement un par un
    for (let i = 0; i < users.length; i++) {
        const u = users[i];
        const pct = Math.round(((i + 1) / users.length) * 100);

        try {
            const fd = new FormData();
            fd.append('csrf',       '<?php echo $csrf; ?>');
            fd.append('action',     'resync_faction_single');
            fd.append('faction_discord_action', '');
            fd.append('user_id',    u.id);
            fd.append('discord_id', u.discord_id);

            const r   = await fetch(window.location.pathname, { method: 'POST', body: fd });
            const res = await r.json();
            synced++;
            if (res.changed) {
                changed++;
                log.innerHTML += `<div style="color:#22c55e;font-size:.73rem;margin-bottom:2px">✓ <strong>${fdEsc(u.username)}</strong> : ${fdEsc(res.old_faction||'NONE')} → <strong>${fdEsc(res.new_faction||'NONE')}</strong></div>`;
            } else if (res.message && res.message !== '') {
                log.innerHTML += `<div style="color:#64748b;font-size:.68rem;margin-bottom:1px">• ${fdEsc(u.username)} : ${fdEsc(res.message)}</div>`;
            }
        } catch(e) {
            errors++;
            log.innerHTML += `<div style="color:#ef4444;font-size:.73rem;margin-bottom:2px">✗ <strong>${fdEsc(u.username)}</strong> : erreur réseau</div>`;
        }

        fill.style.width   = pct + '%';
        fill.textContent   = pct + '%';
        status.textContent = (i + 1) + ' / ' + users.length + ' traités…';
        log.scrollTop = log.scrollHeight;
    }

    status.innerHTML = '<strong style="color:#22c55e">Terminé</strong> — ' + synced + ' traités, <span style="color:#22c55e">' + changed + ' mis à jour</span>' + (errors > 0 ? ', <span style="color:#f59e0b">' + errors + ' erreurs</span>' : '') + '.';
    btn.disabled = false;
}
</script>


<?php include __DIR__ . '/../includes/footer.php'; ?>
<!-- ═══════════════════════════════════════════════════════════
     MODAL GLOBAL — MODIFIER UN GRADE + PERMISSIONS PAR PAGE
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit-grade">
    <div class="modal" style="max-width:860px;">
        <div class="modal-header">
            <h3>
                <i class="fa-solid fa-shield-halved" id="edit-grade-icon" style="color:var(--blue);"></i>
                Modifier le grade &amp; ses permissions
            </h3>
            <button class="modal-close" onclick="closeModal('modal-edit-grade')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" id="form-edit-grade">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_grade">
                <input type="hidden" name="faction_key"    id="eg-faction-key">
                <input type="hidden" name="old_grade_name" id="eg-old-name">
                <input type="hidden" name="grade_id"       id="eg-grade-id" value="0">

                <!-- ── Infos grade ───────────────────────────────────── -->
                <div style="display:grid;grid-template-columns:1fr 1fr 160px;gap:14px;margin-bottom:20px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Faction</label>
                        <div id="eg-faction-display" style="padding:8px 12px;background:var(--bg-overlay);border:1px solid var(--border);border-radius:8px;font-size:.85rem;font-weight:600;color:var(--text);border-left-width:3px;border-left-style:solid;"></div>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Nouveau nom <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="grade_name" id="eg-new-name" class="form-control"
                               placeholder="Nom du grade..." required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Rang hiérarchique</label>
                        <input type="number" name="rank_order" id="eg-rank-order" class="form-control"
                               min="1" max="999" placeholder="Ex: 3">
                    </div>
                </div>

                <!-- ── Séparateur ─────────────────────────────────────── -->
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                    <div style="flex:1;height:1px;background:var(--border);"></div>
                    <div style="font-size:.8rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;">
                        <i class="fa-solid fa-key" style="margin-right:6px;opacity:.6;"></i>Permissions par page
                    </div>
                    <div style="flex:1;height:1px;background:var(--border);"></div>
                </div>

                <!-- ── Contrôles globaux ──────────────────────────────── -->
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:10px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:7px;font-size:.82rem;cursor:pointer;user-select:none;">
                        <input type="checkbox" id="eg-all-view" onchange="egToggleAll('view',this.checked)">
                        <i class="fa-solid fa-eye" style="color:#3b82f6;width:14px;text-align:center;"></i>
                        Tout voir
                    </label>
                    <label style="display:flex;align-items:center;gap:7px;font-size:.82rem;cursor:pointer;user-select:none;">
                        <input type="checkbox" id="eg-all-edit" onchange="egToggleAll('edit',this.checked)">
                        <i class="fa-solid fa-pen-to-square" style="color:#22c55e;width:14px;text-align:center;"></i>
                        Tout éditer
                    </label>
                    <div id="eg-perm-search-wrap" style="margin-left:auto;position:relative;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.75rem;pointer-events:none;"></i>
                        <input type="text" id="eg-perm-search" placeholder="Rechercher une page…"
                               oninput="egFilterPages(this.value)"
                               style="padding:6px 10px 6px 28px;border:1px solid var(--border);border-radius:7px;background:var(--bg-overlay);color:var(--text);font-size:.8rem;width:200px;">
                    </div>
                </div>

                <!-- ── Tableau de permissions ─────────────────────────── -->
                <div id="eg-perm-container" style="max-height:380px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.02);">
                    <!-- Chargé en AJAX via egLoadPermissions() -->
                    <div id="eg-perm-loading" style="display:flex;align-items:center;justify-content:center;gap:10px;padding:40px;color:var(--text-muted);font-size:.85rem;">
                        <i class="fa-solid fa-circle-notch fa-spin"></i> Chargement des pages…
                    </div>
                </div>
                <div id="eg-no-panel" style="display:none;padding:30px;text-align:center;color:var(--text-muted);font-size:.85rem;">
                    <i class="fa-solid fa-folder-open" style="font-size:1.8rem;opacity:.4;display:block;margin-bottom:8px;"></i>
                    Aucune page trouvée pour ce panel.<br>
                    <span style="font-size:.75rem;">Vérifiez que le dossier du panel existe sur le serveur.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-grade')">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Tableau de permissions ── */
#eg-perm-container table { width:100%; border-collapse:collapse; font-size:.82rem; }
#eg-perm-container thead th {
    position:sticky; top:0; z-index:2;
    background:var(--bg-elevated); border-bottom:1px solid var(--border);
    padding:8px 10px; text-align:left; font-weight:700; font-size:.75rem;
    color:var(--text-secondary); text-transform:uppercase; letter-spacing:.05em;
}
#eg-perm-container thead th:not(:first-child) { text-align:center; width:72px; }
#eg-perm-container tbody tr { border-bottom:1px solid rgba(255,255,255,.04); }
#eg-perm-container tbody tr:last-child { border-bottom:none; }
#eg-perm-container tbody tr.eg-cat-header td {
    padding:6px 10px 4px;
    font-size:.7rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
    color:var(--text-muted); background:rgba(255,255,255,.025); border-bottom:1px solid var(--border);
}
#eg-perm-container tbody td { padding:7px 10px; vertical-align:middle; }
#eg-perm-container tbody td:not(:first-child) { text-align:center; }
#eg-perm-container tbody tr:hover td { background:rgba(255,255,255,.025); }
.eg-perm-cb { width:16px; height:16px; cursor:pointer; accent-color:var(--blue); }
.eg-perm-cb.edit-cb { accent-color:#22c55e; }
.eg-page-label { font-weight:500; color:var(--text); }
.eg-page-path { font-size:.7rem; color:var(--text-muted); font-family:monospace; margin-top:1px; }
tr[data-hidden="1"] { display:none; }
</style>

<script>
// ════════════════════════════════════════════════════════════════
// FILTER / SEARCH
// ════════════════════════════════════════════════════════════════
let currentFilter = 'all';

function filterFactions() {
    const q = document.getElementById('search-factions').value.toLowerCase().trim();
    document.querySelectorAll('#factions-grid .faction-card').forEach(card => {
        const name = card.dataset.factionName || '';
        const key  = card.dataset.factionKey  || '';
        const hasMembers = card.dataset.hasMembers === '1';

        const matchesSearch = !q || name.includes(q) || key.includes(q);
        const matchesFilter =
            currentFilter === 'all' ||
            (currentFilter === 'has-members' && hasMembers) ||
            (currentFilter === 'no-members'  && !hasMembers);

        card.style.display = matchesSearch && matchesFilter ? '' : 'none';
    });
}

function setFilter(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    filterFactions();
}

// ════════════════════════════════════════════════════════════════
// MODAL HELPERS
// ════════════════════════════════════════════════════════════════
function openModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) { el.classList.add('active'); el.style.display = ''; }
}
function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) { el.classList.remove('active'); }
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    }
});

function editFaction(factionKey, factionName, factionColor, factionIcon) {
    document.getElementById('edit-faction-key').value   = factionKey;
    document.getElementById('edit-faction-name').value  = factionName;
    document.getElementById('edit-faction-color').value = factionColor;
    document.getElementById('edit-faction-icon').value  = factionIcon;
    openModal('modal-edit-faction');
}

// ════════════════════════════════════════════════════════════════
// MODIFIER UN GRADE — ouverture du modal + chargement permissions
// ════════════════════════════════════════════════════════════════
function openEditGrade(factionKey, gradeName, rankOrder, color, gradeId) {
    gradeId = gradeId || 0;

    document.getElementById('eg-faction-key').value = factionKey;
    document.getElementById('eg-old-name').value    = gradeName;
    document.getElementById('eg-grade-id').value    = gradeId;
    document.getElementById('eg-new-name').value    = gradeName;
    document.getElementById('eg-rank-order').value  = (rankOrder < 999 && rankOrder > 0) ? rankOrder : '';

    // Affichage faction coloré
    const dispEl = document.getElementById('eg-faction-display');
    dispEl.textContent = factionKey;
    dispEl.style.borderLeftColor = color;

    // Icône dans le header
    const ico = document.getElementById('edit-grade-icon');
    if (ico) ico.style.color = color;

    // Réinitialiser les checkboxes globales
    document.getElementById('eg-all-view').checked = false;
    document.getElementById('eg-all-edit').checked = false;
    document.getElementById('eg-perm-search').value = '';

    openModal('modal-edit-grade');
    setTimeout(() => document.getElementById('eg-new-name').focus(), 120);

    // Charger les pages du panel via AJAX (+ gradeName pour les permissions d'actions)
    egLoadPermissions(factionKey, gradeId, gradeName);
}

/**
 * Charge (via AJAX GET) les pages d'une faction et les permissions d'un grade,
 * puis rend le tableau dans #eg-perm-container.
 */
function egLoadPermissions(factionKey, gradeId, gradeName) {
    gradeName = gradeName || '';
    const container = document.getElementById('eg-perm-container');
    const noPanel   = document.getElementById('eg-no-panel');
    const loading   = document.getElementById('eg-perm-loading');

    container.style.display = '';
    noPanel.style.display   = 'none';
    loading.style.display   = 'flex';

    // Vider l'ancien contenu
    container.querySelectorAll('table, .eg-action-section').forEach(el => el.remove());

    if (!factionKey) {
        loading.style.display = 'none';
        noPanel.style.display = 'block';
        return;
    }

    const url = window.location.pathname
        + '?action=get_faction_pages'
        + '&faction_key=' + encodeURIComponent(factionKey)
        + '&grade_id='    + encodeURIComponent(gradeId)
        + '&grade_name='  + encodeURIComponent(gradeName);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            const pages      = data.pages || [];
            const pagePerms  = data.page_permissions || {};
            const actPerms   = data.action_permissions || {};

            if (pages.length === 0) {
                noPanel.style.display = 'block';
                return;
            }

            egRenderPermTable(pages, pagePerms, actPerms, container);
        })
        .catch(() => {
            loading.style.display = 'none';
            container.innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:.85rem;"><i class="fa-solid fa-triangle-exclamation"></i> Erreur lors du chargement des pages.</div>';
        });
}

/**
 * Rend le tableau de permissions dans le container.
 * - Pages avec catalogue d'actions → liste de checkboxes par action
 * - Pages sans catalogue → colonnes view/edit classiques
 */
function egRenderPermTable(pages, pagePerms, actPerms, container) {
    // Grouper par catégorie
    const groups = {};
    pages.forEach(p => {
        const cat = p.category || 'Autres';
        if (!groups[cat]) groups[cat] = [];
        groups[cat].push(p);
    });

    let html = '<table><thead><tr>'
        + '<th style="width:42%">Page / Action</th>'
        + '<th style="width:58%;text-align:left;padding-left:10px">Permissions</th>'
        + '</tr></thead><tbody>';

    Object.keys(groups).sort().forEach(cat => {
        html += `<tr class="eg-cat-header"><td colspan="2"><i class="fa-solid fa-folder-open" style="margin-right:5px;opacity:.5;"></i>${egEsc(cat)}</td></tr>`;

        groups[cat].forEach(p => {
            const hasActions = p.actions && p.actions.length > 0;

            if (hasActions) {
                // ── Page AVEC catalogue d'actions ────────────────────────────
                html += `<tr data-hidden="0" data-page-path="${egEsc(p.path)}" data-page-label="${egEsc(p.label).toLowerCase()} ${(p.actions||[]).map(a=>a.label.toLowerCase()).join(' ')}">
                    <td>
                        <div class="eg-page-label">${egEsc(p.label)}</div>
                        <div class="eg-page-path">${egEsc(p.path)}</div>
                    </td>
                    <td>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">`;

                p.actions.forEach(a => {
                    // null = non configuré → case indéterminée, false = refusé, true = accordé
                    const enabled = (a.enabled === true || actPerms[a.key] === true);
                    const isViewAction = a.key.endsWith('.view') || a.key.endsWith('.view_employees') || a.key.endsWith('.stats');
                    const chipColor = isViewAction ? '#3b82f6' : '#22c55e';
                    html += `<label style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border:1px solid rgba(255,255,255,.1);border-radius:6px;cursor:pointer;font-size:.72rem;user-select:none;background:rgba(255,255,255,.03);transition:.15s" title="${egEsc(a.key)}" onmouseenter="this.style.borderColor='${chipColor}40'" onmouseleave="this.style.borderColor='rgba(255,255,255,.1)'">
                        <input type="checkbox" class="eg-perm-cb action-cb" style="accent-color:${chipColor};width:13px;height:13px"
                               name="actions[${egEsc(a.key)}]" value="1"
                               data-page="${egEsc(p.path)}" data-action-key="${egEsc(a.key)}"
                               ${enabled ? 'checked' : ''}>
                        <span style="color:#e2e8f0">${egEsc(a.label)}</span>
                    </label>`;
                });

                html += `       </div>
                    </td>
                </tr>`;

            } else {
                // ── Page SANS catalogue → view/edit classique ─────────────────
                const pv = (pagePerms[p.path] && pagePerms[p.path].view) ? true : false;
                const pe = (pagePerms[p.path] && pagePerms[p.path].edit) ? true : false;
                html += `<tr data-hidden="0" data-page-path="${egEsc(p.path)}" data-page-label="${egEsc(p.label).toLowerCase()}">
                    <td>
                        <div class="eg-page-label">${egEsc(p.label)}</div>
                        <div class="eg-page-path">${egEsc(p.path)}</div>
                    </td>
                    <td>
                        <div style="display:flex;gap:12px;align-items:center;">
                        <label style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;cursor:pointer">
                            <input type="checkbox" class="eg-perm-cb view-cb" style="accent-color:#3b82f6"
                                   name="pages[${egEsc(p.path)}][view]" value="1"
                                   data-path="${egEsc(p.path)}" data-flag="view"
                                   ${pv ? 'checked' : ''} onchange="egOnViewChange(this)">
                            <span style="color:#3b82f6;font-weight:600"><i class="fa-solid fa-eye" style="font-size:.65rem"></i> Voir</span>
                        </label>
                        <label style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;cursor:pointer">
                            <input type="checkbox" class="eg-perm-cb edit-cb" style="accent-color:#22c55e"
                                   name="pages[${egEsc(p.path)}][edit]" value="1"
                                   data-path="${egEsc(p.path)}" data-flag="edit"
                                   ${pe ? 'checked' : ''} onchange="egOnEditChange(this)">
                            <span style="color:#22c55e;font-weight:600"><i class="fa-solid fa-pen-to-square" style="font-size:.65rem"></i> Éditer</span>
                        </label>
                        </div>
                    </td>
                </tr>`;
            }
        });
    });

    html += '</tbody></table>';
    container.insertAdjacentHTML('beforeend', html);
}

function egEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/** Tout cocher / décocher — couvre à la fois les actions granulaires ET les view/edit classiques */
function egToggleAll(flag, checked) {
    if (flag === 'view') {
        // Cocher/décocher toutes les cases "view" classiques
        document.querySelectorAll('#eg-perm-container .view-cb').forEach(cb => {
            cb.checked = checked;
            if (!checked) {
                const editCb = cb.closest('tr')?.querySelector('.edit-cb');
                if (editCb) editCb.checked = false;
            }
        });
        // Pour les actions : cocher/décocher uniquement les actions de consultation
        document.querySelectorAll('#eg-perm-container .action-cb').forEach(cb => {
            const key = cb.dataset.actionKey || '';
            if (key.endsWith('.view') || key.endsWith('.view_employees') || key.endsWith('.stats')) {
                cb.checked = checked;
            }
        });
        if (!checked) document.getElementById('eg-all-edit').checked = false;
    } else {
        // Cocher/décocher toutes les cases "edit" classiques
        document.querySelectorAll('#eg-perm-container .edit-cb').forEach(cb => {
            cb.checked = checked;
            if (checked) {
                const viewCb = cb.closest('tr')?.querySelector('.view-cb');
                if (viewCb) viewCb.checked = true;
            }
        });
        // Pour les actions : cocher/décocher toutes les actions non-consultation
        document.querySelectorAll('#eg-perm-container .action-cb').forEach(cb => {
            const key = cb.dataset.actionKey || '';
            const isView = key.endsWith('.view') || key.endsWith('.view_employees') || key.endsWith('.stats');
            if (!isView) cb.checked = checked;
            if (checked) cb.checked = true; // "tout éditer" = tout cocher
        });
        if (checked) document.getElementById('eg-all-view').checked = true;
    }
}

/** Héritage : Éditer coche automatiquement Voir */
function egOnEditChange(cb) {
    if (cb.checked) {
        const viewCb = cb.closest('tr').querySelector('.view-cb');
        if (viewCb) viewCb.checked = true;
    }
}

/** Héritage : Décocher Voir décoche aussi Éditer */
function egOnViewChange(cb) {
    if (!cb.checked) {
        const editCb = cb.closest('tr').querySelector('.edit-cb');
        if (editCb) editCb.checked = false;
    }
}

/** Filtrage en temps réel des pages par nom */
function egFilterPages(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#eg-perm-container tbody tr:not(.eg-cat-header)').forEach(tr => {
        const label = (tr.dataset.pageLabel || '');
        const path  = (tr.dataset.pagePath  || '').toLowerCase();
        tr.dataset.hidden = (q === '' || label.includes(q) || path.includes(q)) ? '0' : '1';
    });
    // Masquer les catégories si toutes leurs lignes sont cachées
    document.querySelectorAll('#eg-perm-container tbody tr.eg-cat-header').forEach(catTr => {
        let next = catTr.nextElementSibling;
        let hasVisible = false;
        while (next && !next.classList.contains('eg-cat-header')) {
            if (next.dataset.hidden !== '1') { hasVisible = true; break; }
            next = next.nextElementSibling;
        }
        catTr.style.display = hasVisible ? '' : 'none';
    });
}

// ════════════════════════════════════════════════════════════════
// TOAST HELPER
// ════════════════════════════════════════════════════════════════
let _toastTimer = null;
function showGradeToast(msg, type = 'success') {
    let t = document.getElementById('grade-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'grade-toast';
        t.className = 'grade-toast';
        document.body.appendChild(t);
    }
    const icons = { success: 'check-circle', error: 'circle-xmark', saving: 'circle-notch fa-spin' };
    t.className = `grade-toast ${type}`;
    t.innerHTML = `<i class="fa-solid fa-${icons[type]||'info'}"></i>${msg}`;
    t.style.opacity = '1';

    if (_toastTimer) clearTimeout(_toastTimer);
    if (type !== 'saving') {
        _toastTimer = setTimeout(() => { t.style.opacity = '0'; }, 2500);
    }
}

// ════════════════════════════════════════════════════════════════
// DRAG & DROP — réorganisation hiérarchique des grades
// ════════════════════════════════════════════════════════════════
let _dragSrc    = null;  // l'item en cours de drag
let _dragFaction = null; // faction du drag en cours
let _saveTimer  = null;

/**
 * Sauvegarder le nouvel ordre via AJAX après un drop
 */
function saveGradeOrder(listEl) {
    const faction = listEl.dataset.faction;
    const items   = listEl.querySelectorAll('.grade-item[data-grade-name]');
    const order   = [];

    items.forEach((item, idx) => {
        const name = item.dataset.gradeName;
        const newRank = idx + 1;
        order.push({ name, rank_order: newRank });

        // Mettre à jour visuellement le badge de rang
        const badge = item.querySelector('.grade-rank-badge');
        if (badge) badge.textContent = newRank;
        item.dataset.rank = newRank;
    });

    showGradeToast('Sauvegarde en cours…', 'saving');

    const fd = new FormData();
    fd.append('action',      'reorder_grades');
    fd.append('faction_key', faction);
    fd.append('order',       JSON.stringify(order));

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) showGradeToast('Hiérarchie mise à jour ✓');
            else showGradeToast('Erreur : ' + (data.message || '?'), 'error');
        })
        .catch(() => showGradeToast('Erreur réseau', 'error'));
}

/**
 * Initialise le Drag & Drop HTML5 sur une liste de grades
 */
function initGradeDragDrop(listEl) {
    if (!listEl) return;

    // Glisser depuis la poignée uniquement
    listEl.querySelectorAll('.grade-item').forEach(item => {
        const handle = item.querySelector('.grade-drag-handle');

        // Activer draggable seulement via la poignée
        if (handle) {
            handle.addEventListener('mousedown', () => { item.setAttribute('draggable', 'true'); });
            handle.addEventListener('touchstart', () => { item.setAttribute('draggable', 'true'); }, { passive: true });
        }

        item.addEventListener('dragstart', function(e) {
            if (!this.getAttribute('draggable')) { e.preventDefault(); return; }
            _dragSrc     = this;
            _dragFaction = listEl.dataset.faction;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.gradeName);
            // Délai pour voir l'état dragging
            requestAnimationFrame(() => this.classList.add('dragging'));
        });

        item.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            this.setAttribute('draggable', 'false');
            listEl.querySelectorAll('.grade-item').forEach(i => i.classList.remove('drag-over'));
            listEl.querySelectorAll('.grade-drop-indicator').forEach(d => d.classList.remove('visible'));
            _dragSrc = null;
        });

        item.addEventListener('dragover', function(e) {
            if (!_dragSrc || _dragSrc === this) return;
            if (this.closest('.grades-list').dataset.faction !== _dragFaction) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            // Enlever drag-over des autres
            listEl.querySelectorAll('.grade-item').forEach(i => i.classList.remove('drag-over'));
            this.classList.add('drag-over');
        });

        item.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        item.addEventListener('drop', function(e) {
            e.preventDefault();
            if (!_dragSrc || _dragSrc === this) return;
            if (this.closest('.grades-list').dataset.faction !== _dragFaction) return;

            this.classList.remove('drag-over');

            // Déterminer si on insère avant ou après selon la position du curseur
            const rect = this.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            const insertBefore = e.clientY < midY;

            if (insertBefore) {
                listEl.insertBefore(_dragSrc, this);
            } else {
                const next = this.nextElementSibling;
                if (next) listEl.insertBefore(_dragSrc, next);
                else listEl.appendChild(_dragSrc);
            }

            // Debounce save
            if (_saveTimer) clearTimeout(_saveTimer);
            _saveTimer = setTimeout(() => saveGradeOrder(listEl), 600);
        });
    });

    // Drop sur la liste elle-même (en bas)
    listEl.addEventListener('dragover', function(e) {
        if (!_dragSrc) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });
    listEl.addEventListener('drop', function(e) {
        if (!_dragSrc) return;
        // Si on drop en dehors d'un item, on append à la fin
        if (e.target === this) {
            e.preventDefault();
            this.appendChild(_dragSrc);
            if (_saveTimer) clearTimeout(_saveTimer);
            _saveTimer = setTimeout(() => saveGradeOrder(this), 600);
        }
    });
}

// ── Initialiser drag & drop sur toutes les listes ──
document.querySelectorAll('.grades-list[id^="grades-list-"]').forEach(initGradeDragDrop);
</script>