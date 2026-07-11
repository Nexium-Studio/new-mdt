<?php
/**
 * includes/faction_acl.php
 *
 * Système ACL (Access Control List) par page pour les grades de faction.
 *
 * Usage dans une page de faction :
 *
 *   require_once ROOT_PATH . '/includes/faction_acl.php';
 *
 *   // Vérifier accès lecture
 *   if (!faction_acl_can_view($db, (int)$_SESSION['user_id'], 'LSPD', 'effectifs.php')) {
 *       faction_acl_deny();
 *   }
 *
 *   // Conditionner les boutons d'action
 *   $canEdit = faction_acl_can_edit($db, (int)$_SESSION['user_id'], 'LSPD', 'effectifs.php');
 */

if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/../config/config.php';
}

// ---------------------------------------------------------------------------
// Constantes
// ---------------------------------------------------------------------------

/** Fichiers techniques à ignorer lors du scan (sans extension). */
const FACTION_ACL_EXCLUDED_FILES = [
    'header', 'footer', 'config', '_bootstrap', 'nav', 'sidebar',
    'index', 'init', 'bootstrap', 'functions', 'helpers', 'utils',
    'get_division_members',
];

/** Dossiers à ignorer lors du scan. */
const FACTION_ACL_EXCLUDED_DIRS = [
    'includes', 'ajax', 'api', 'assets', 'css', 'js',
];

// ---------------------------------------------------------------------------
// Infrastructure
// ---------------------------------------------------------------------------

/**
 * Crée / migre la table faction_grade_page_access.
 * Auto-migrant : compatible MySQL 5.7+, MariaDB 10.0+.
 * Utilise INFORMATION_SCHEMA pour éviter les syntaxes IF NOT EXISTS non universelles.
 */
function faction_acl_ensure_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        // ── 1. Créer la table si elle n'existe pas (nouveau schéma avec grade_name) ──
        $db->exec("
            CREATE TABLE IF NOT EXISTS `faction_grade_page_access` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `grade_id` int(10) UNSIGNED DEFAULT NULL,
              `faction_key` varchar(50) NOT NULL,
              `grade_name` varchar(100) NOT NULL DEFAULT '',
              `page_path` varchar(255) NOT NULL,
              `can_view` tinyint(1) NOT NULL DEFAULT 0,
              `can_edit` tinyint(1) NOT NULL DEFAULT 0,
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_fk_grade_page` (`faction_key`,`grade_name`,`page_path`),
              KEY `idx_grade_id` (`grade_id`),
              KEY `idx_fk` (`faction_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // ── 2. Migration douce si la table existait SANS grade_name ────────────────
        $hasGradeNameCol = (bool)$db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'faction_grade_page_access'
              AND COLUMN_NAME  = 'grade_name'
        ")->fetchColumn();

        if (!$hasGradeNameCol) {
            // Ajouter la colonne
            $db->exec("ALTER TABLE `faction_grade_page_access`
                         ADD COLUMN `grade_name` varchar(100) NOT NULL DEFAULT '' AFTER `grade_id`");

            // Peupler grade_name depuis faction_grades pour les lignes existantes
            try {
                $db->exec("UPDATE `faction_grade_page_access` fgpa
                           INNER JOIN `faction_grades` fg ON fg.id = fgpa.grade_id
                           SET fgpa.grade_name = fg.name
                           WHERE fgpa.grade_name = ''");
            } catch (Throwable $e2) {}

            // Supprimer les lignes non migrables (grade_name toujours vide → données obsolètes)
            try {
                $db->exec("DELETE FROM `faction_grade_page_access` WHERE grade_name = ''");
            } catch (Throwable $e3) {}
        }

        // ── 3. Supprimer l'ancienne clé unique si elle existe encore ───────────────
        $hasOldKey = (bool)$db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'faction_grade_page_access'
              AND INDEX_NAME   = 'uq_grade_page'
        ")->fetchColumn();

        if ($hasOldKey) {
            try {
                $db->exec("ALTER TABLE `faction_grade_page_access` DROP INDEX `uq_grade_page`");
            } catch (Throwable $e4) {}
        }

        // ── 4. Ajouter la nouvelle clé unique si elle manque ──────────────────────
        $hasNewKey = (bool)$db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'faction_grade_page_access'
              AND INDEX_NAME   = 'uq_fk_grade_page'
        ")->fetchColumn();

        if (!$hasNewKey) {
            try {
                $db->exec("ALTER TABLE `faction_grade_page_access`
                             ADD UNIQUE KEY `uq_fk_grade_page` (`faction_key`,`grade_name`,`page_path`)");
            } catch (Throwable $e5) {}
        }

        $done = true;
    } catch (Throwable $e) {
        error_log('[faction_acl] ensure_table: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Scanner de pages
// ---------------------------------------------------------------------------

/**
 * Résout le répertoire de pages d'une faction à partir du catalogue de panels.
 * Retourne le chemin absolu avec séparateur final, ou null si inconnu/introuvable.
 */
function faction_acl_panel_dir(string $factionKey): ?string {
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $db = Database::getInstance()->getConnection();
            // Charger le catalogue (déjà inclus via config, on évite le double include)
            if (!function_exists('mdt_panel_catalog_categories')) {
                require_once __DIR__ . '/../config/mdt_panel_catalog.php';
            }
            $cats = mdt_panel_catalog_categories($db);
            foreach ($cats as $panels) {
                foreach ($panels as $panel) {
                    $fk  = $panel['faction'] ?? '';
                    $url = $panel['url']     ?? '';
                    if ($fk === '' || $url === '') continue;
                    if (isset($cache[$fk])) continue;
                    // L'URL ressemble à /pages/lspd/dashboard.php → dossier = /pages/lspd
                    $dir = dirname($url);
                    if ($dir === '.' || $dir === '/') continue;
                    $abs = ROOT_PATH . $dir;
                    if (is_dir($abs)) {
                        $cache[$fk] = rtrim($abs, '/\\') . DIRECTORY_SEPARATOR;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[faction_acl] panel_dir: ' . $e->getMessage());
        }
    }

    return $cache[$factionKey] ?? null;
}

/**
 * Étiquette lisible pour un nom de fichier PHP.
 */
function faction_acl_page_label(string $filename): string {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    static $map = [
        'dashboard'              => 'Dashboard',
        'effectifs'              => 'Effectifs',
        'employees'              => 'Employés',
        'grades'                 => 'Grades',
        'grades-divisions'       => 'Grades & Divisions',
        'div_grad'               => 'Grades divisions',
        'sanctions'              => 'Sanctions',
        'reports'                => 'Rapports',
        'rapport-rookie'         => 'Rapport Rookie',
        'report-create'          => 'Créer un rapport',
        'dispatch'               => 'Dispatch',
        'wanted'                 => 'Wanted',
        'vehicles'               => 'Véhicules',
        'vehicule_lspd'          => 'Véhicules LSPD',
        'casiers'                => 'Casiers judiciaires',
        'convocation'            => 'Convocations',
        'deposition'             => 'Dépositions',
        'plainte'                => 'Plaintes',
        'offenses'               => 'Infractions',
        'armes-civils'           => 'Armes civils',
        'bracelets-electronique' => 'Bracelets électroniques',
        'ia'                     => 'Intelligence artificielle',
        'divisions'              => 'Divisions',
        'logs'                   => 'Logs',
        'lspd_log'               => 'Logs LSPD',
        'map'                    => 'Carte',
        'map-view'               => 'Vue carte',
        'add-map'                => 'Ajouter zone carte',
        'edit-map'               => 'Modifier zone carte',
        'statistiques'           => 'Statistiques',
        'profils'                => 'Profils',
        'recherches'             => 'Recherches',
        'service'                => 'Service',
        'interventions'          => 'Interventions',
        'patients'               => 'Patients',
        'citoyens'               => 'Citoyens',
        'pharmacie'              => 'Pharmacie',
        'facturation'            => 'Facturation',
        'planning'               => 'Planning',
        'rapports'               => 'Rapports médicaux',
        'documents'              => 'Documents',
        'vente'                  => 'Ventes',
        'stock'                  => 'Stock',
        'depenses'               => 'Dépenses',
        'prises-service'         => 'Prises de service',
        'custom'                 => 'Personnalisation',
        'equipement'             => 'Équipement',
        'saisies'                => 'Saisies',
        'ppa'                    => 'PPA',
        'ppa-agent'              => 'PPA Agent',
        'mainteance'             => 'Maintenance',
        'equipement'             => 'Équipement',
    ];
    return $map[$name] ?? ucwords(str_replace(['-', '_'], ' ', $name));
}

/**
 * Catégorie regroupant une page dans l'UI des permissions.
 */
function faction_acl_page_category(string $filename): string {
    $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    static $map = [
        'dashboard'              => '01_Dashboard',
        'statistiques'           => '01_Dashboard',
        'service'                => '01_Dashboard',
        'effectifs'              => '02_Personnel',
        'employees'              => '02_Personnel',
        'grades'                 => '02_Personnel',
        'grades-divisions'       => '02_Personnel',
        'div_grad'               => '02_Personnel',
        'sanctions'              => '02_Personnel',
        'prises-service'         => '02_Personnel',
        'planning'               => '02_Personnel',
        'reports'                => '03_Rapports',
        'rapport-rookie'         => '03_Rapports',
        'report-create'          => '03_Rapports',
        'rapports'               => '03_Rapports',
        'lspd_log'               => '03_Rapports',
        'logs'                   => '03_Rapports',
        'dispatch'               => '04_Opérations',
        'wanted'                 => '04_Opérations',
        'convocation'            => '04_Opérations',
        'plainte'                => '04_Opérations',
        'deposition'             => '04_Opérations',
        'offenses'               => '04_Opérations',
        'armes-civils'           => '04_Opérations',
        'bracelets-electronique' => '04_Opérations',
        'ppa'                    => '04_Opérations',
        'ppa-agent'              => '04_Opérations',
        'saisies'                => '04_Opérations',
        'recherches'             => '04_Opérations',
        'vehicles'               => '05_Véhicules',
        'vehicule_lspd'          => '05_Véhicules',
        'equipement'             => '05_Véhicules',
        'casiers'                => '06_Judiciaire',
        'profils'                => '06_Judiciaire',
        'ia'                     => '07_Outils',
        'divisions'              => '07_Outils',
        'map'                    => '07_Outils',
        'map-view'               => '07_Outils',
        'add-map'                => '07_Outils',
        'edit-map'               => '07_Outils',
        'mainteance'             => '07_Outils',
        'vente'                  => '08_Commerce',
        'stock'                  => '08_Commerce',
        'depenses'               => '08_Commerce',
        'facturation'            => '08_Commerce',
        'custom'                 => '08_Commerce',
        'interventions'          => '09_Médical',
        'patients'               => '09_Médical',
        'citoyens'               => '09_Médical',
        'pharmacie'              => '09_Médical',
        'documents'              => '10_Documents',
    ];
    $raw = $map[$name] ?? '11_Autres';
    // Retirer le préfixe de tri numérique pour l'affichage
    return preg_replace('/^\d+_/', '', $raw);
}

/** Clé de tri interne (pour usort). */
function faction_acl_page_sort_key(string $filename): string {
    $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    static $map = [
        'dashboard'              => '01_Dashboard',
        'statistiques'           => '01_Dashboard',
        'service'                => '01_Dashboard',
        'effectifs'              => '02_Personnel',
        'employees'              => '02_Personnel',
        'grades'                 => '02_Personnel',
        'grades-divisions'       => '02_Personnel',
        'div_grad'               => '02_Personnel',
        'sanctions'              => '02_Personnel',
        'prises-service'         => '02_Personnel',
        'planning'               => '02_Personnel',
        'reports'                => '03_Rapports',
        'rapport-rookie'         => '03_Rapports',
        'report-create'          => '03_Rapports',
        'rapports'               => '03_Rapports',
        'lspd_log'               => '03_Rapports',
        'logs'                   => '03_Rapports',
        'dispatch'               => '04_Opérations',
        'wanted'                 => '04_Opérations',
        'convocation'            => '04_Opérations',
        'plainte'                => '04_Opérations',
        'deposition'             => '04_Opérations',
        'offenses'               => '04_Opérations',
        'armes-civils'           => '04_Opérations',
        'bracelets-electronique' => '04_Opérations',
        'ppa'                    => '04_Opérations',
        'ppa-agent'              => '04_Opérations',
        'saisies'                => '04_Opérations',
        'recherches'             => '04_Opérations',
        'vehicles'               => '05_Véhicules',
        'vehicule_lspd'          => '05_Véhicules',
        'equipement'             => '05_Véhicules',
        'casiers'                => '06_Judiciaire',
        'profils'                => '06_Judiciaire',
        'ia'                     => '07_Outils',
        'divisions'              => '07_Outils',
        'map'                    => '07_Outils',
        'map-view'               => '07_Outils',
        'add-map'                => '07_Outils',
        'edit-map'               => '07_Outils',
        'mainteance'             => '07_Outils',
        'vente'                  => '08_Commerce',
        'stock'                  => '08_Commerce',
        'depenses'               => '08_Commerce',
        'facturation'            => '08_Commerce',
        'custom'                 => '08_Commerce',
        'interventions'          => '09_Médical',
        'patients'               => '09_Médical',
        'citoyens'               => '09_Médical',
        'pharmacie'              => '09_Médical',
        'documents'              => '10_Documents',
    ];
    return ($map[$name] ?? '11_Autres') . '_' . $name;
}

/**
 * Scanne le dossier de pages d'une faction et retourne la liste des pages.
 * Chaque entrée : ['path' => 'effectifs.php', 'label' => 'Effectifs', 'category' => 'Personnel']
 */
function faction_acl_scan_pages(string $factionKey): array {
    $dir = faction_acl_panel_dir($factionKey);
    if (!$dir || !is_dir($dir)) return [];

    $excluded = array_map('strtolower', FACTION_ACL_EXCLUDED_FILES);
    $pages    = [];

    $files = glob($dir . '*.php') ?: [];
    foreach ($files as $file) {
        $filename = basename($file);
        $stem     = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (in_array($stem, $excluded, true)) continue;
        $pages[] = [
            'path'     => $filename,
            'label'    => faction_acl_page_label($filename),
            'category' => faction_acl_page_category($filename),
            '_sort'    => faction_acl_page_sort_key($filename),
        ];
    }

    usort($pages, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));

    // Supprimer la clé interne de tri
    return array_map(function ($p) {
        unset($p['_sort']);
        return $p;
    }, $pages);
}

// ---------------------------------------------------------------------------
// Permissions CRUD
// ---------------------------------------------------------------------------

/**
 * Récupère les permissions d'un grade par son nom et sa faction.
 * Retourne ['effectifs.php' => ['view' => true, 'edit' => false], ...]
 */
function faction_acl_get_grade_permissions(int $gradeId, PDO $db): array {
    faction_acl_ensure_table($db);
    try {
        // Essai par grade_id d'abord, puis fallback sans grade_id (anciens enregistrements)
        $stmt = $db->prepare(
            "SELECT page_path, can_view, can_edit FROM faction_grade_page_access WHERE grade_id = ?"
        );
        $stmt->execute([$gradeId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['page_path']] = [
                'view' => (bool)(int)$row['can_view'],
                'edit' => (bool)(int)$row['can_edit'],
            ];
        }
        return $result;
    } catch (Throwable $e) {
        error_log('[faction_acl] get_grade_permissions: ' . $e->getMessage());
        return [];
    }
}

/**
 * Récupère les permissions d'un grade par faction_key + grade_name (lookup direct).
 */
function faction_acl_get_grade_permissions_by_name(string $factionKey, string $gradeName, PDO $db): array {
    faction_acl_ensure_table($db);
    try {
        $stmt = $db->prepare(
            "SELECT page_path, can_view, can_edit FROM faction_grade_page_access
              WHERE faction_key = ? AND grade_name = ?"
        );
        $stmt->execute([$factionKey, $gradeName]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['page_path']] = [
                'view' => (bool)(int)$row['can_view'],
                'edit' => (bool)(int)$row['can_edit'],
            ];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Enregistre les permissions d'un grade (remplacement complet).
 * Utilise grade_name pour le lookup, grade_id optionnel.
 *
 * $pages : ['effectifs.php' => ['view' => '1', 'edit' => '0'], ...]
 */
function faction_acl_save_grade_permissions(int $gradeId, string $factionKey, string $gradeName, array $pages, PDO $db): void {
    faction_acl_ensure_table($db);
    try {
        $db->beginTransaction();
        // Supprimer toutes les anciennes permissions pour ce grade (par nom)
        $db->prepare("DELETE FROM faction_grade_page_access WHERE faction_key = ? AND grade_name = ?")
           ->execute([$factionKey, $gradeName]);

        $ins = $db->prepare(
            "INSERT INTO faction_grade_page_access (grade_id, faction_key, grade_name, page_path, can_view, can_edit)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($pages as $path => $flags) {
            $path    = basename((string)$path);
            if ($path === '' || !str_ends_with($path, '.php')) continue;
            $canView = !empty($flags['view']) ? 1 : 0;
            $canEdit = !empty($flags['edit']) ? 1 : 0;
            if ($canView || $canEdit) {
                $ins->execute([$gradeId ?: null, $factionKey, $gradeName, $path, $canView, $canEdit]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[faction_acl] save_grade_permissions: ' . $e->getMessage());
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Résolution du grade d'un utilisateur
// ---------------------------------------------------------------------------

/**
 * Retourne le nom du grade (faction_rank) de l'utilisateur dans une faction.
 * Lookup DIRECT sur `users` — pas de jointure vers faction_grades.
 *
 * Retourne null  → utilisateur non membre de cette faction (ou erreur DB)
 * Retourne ''    → membre mais sans grade défini
 * Retourne 'XYZ' → grade du membre
 */
function faction_acl_user_grade_name(PDO $db, int $userId, string $factionKey): ?string {
    static $cache = [];
    $cacheKey = $userId . ':' . strtoupper($factionKey);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    try {
        $stmt = $db->prepare("SELECT faction, faction_rank FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtoupper((string)($row['faction'] ?? '')) !== strtoupper($factionKey)) {
            $cache[$cacheKey] = null;
        } else {
            $cache[$cacheKey] = (string)($row['faction_rank'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('[faction_acl] user_grade_name: ' . $e->getMessage());
        $cache[$cacheKey] = null;
    }
    return $cache[$cacheKey];
}

/** Conservé pour compatibilité (utilisé par roles.php AJAX). */
function faction_acl_user_grade_id(PDO $db, int $userId): ?int {
    try {
        $stmt = $db->prepare("
            SELECT fg.id FROM users u
            JOIN factions f ON f.faction_key = u.faction
            JOIN faction_grades fg ON fg.faction_id = f.id AND fg.name = u.faction_rank
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return ($id !== false) ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Contrôles d'accès
// ---------------------------------------------------------------------------

/**
 * Bypass staff : un staff qui EST AUSSI dans la faction utilise ses permissions
 * de faction. Un staff qui N'EST PAS dans la faction bénéficie du bypass total.
 */
function faction_acl_staff_bypass(PDO $db, int $userId, string $factionKey): bool {
    if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
        return false;
    }
    if ($factionKey === '') return true;

    // Réutilise faction_acl_user_grade_name pour savoir si l'user est dans la faction
    $gradeName = faction_acl_user_grade_name($db, $userId, $factionKey);
    // Si null → pas dans la faction → bypass
    // Si non-null (même '') → membre de la faction → pas de bypass
    return $gradeName === null;
}

/**
 * Retourne true si des permissions sont configurées pour ce grade dans cette faction.
 * Lookup par (faction_key, grade_name).
 */
function faction_acl_grade_has_any_permissions(PDO $db, string $factionKey, string $gradeName): bool {
    static $cache = [];
    $key = strtoupper($factionKey) . ':' . $gradeName;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM faction_grade_page_access WHERE faction_key = ? AND grade_name = ?"
        );
        $stmt->execute([$factionKey, $gradeName]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * L'utilisateur peut-il consulter cette page de faction ?
 *
 * Règle :
 *   - Staff non-membre de la faction → bypass total (oui)
 *   - Grade sans aucune permission configurée → permissif (oui, système non configuré)
 *   - Grade avec d'autres permissions mais pas cette page → restrictif (non)
 *   - Règle explicite can_view=1 → oui
 *   - Règle explicite can_view=0 → non
 */
function faction_acl_can_view(PDO $db, int $userId, string $factionKey, string $pagePath): bool {
    if (faction_acl_staff_bypass($db, $userId, $factionKey)) return true;
    // Mode démo : accès lecture seule à tous les panels
    if (is_demo_mode()) return true;

    faction_acl_ensure_table($db);
    $gradeName = faction_acl_user_grade_name($db, $userId, $factionKey);
    if ($gradeName === null) return false; // Pas dans cette faction
    if ($gradeName === '') return true;    // Dans la faction mais sans grade → permissif

    try {
        $stmt = $db->prepare(
            "SELECT can_view FROM faction_grade_page_access
              WHERE faction_key = ? AND grade_name = ? AND page_path = ? LIMIT 1"
        );
        $stmt->execute([$factionKey, $gradeName, basename($pagePath)]);
        $val = $stmt->fetchColumn();

        if ($val === false) {
            // Pas de règle pour cette page → restrictif si le grade a d'autres règles, sinon permissif
            return !faction_acl_grade_has_any_permissions($db, $factionKey, $gradeName);
        }
        return (bool)(int)$val;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * L'utilisateur peut-il modifier les données de cette page de faction ?
 */
function faction_acl_can_edit(PDO $db, int $userId, string $factionKey, string $pagePath): bool {
    if (faction_acl_staff_bypass($db, $userId, $factionKey)) return true;
    // Mode démo : lecture seule uniquement, jamais d'édition
    if (is_demo_mode()) return false;

    faction_acl_ensure_table($db);
    $gradeName = faction_acl_user_grade_name($db, $userId, $factionKey);
    if ($gradeName === null) return false;
    if ($gradeName === '') return true;

    try {
        $stmt = $db->prepare(
            "SELECT can_edit FROM faction_grade_page_access
              WHERE faction_key = ? AND grade_name = ? AND page_path = ? LIMIT 1"
        );
        $stmt->execute([$factionKey, $gradeName, basename($pagePath)]);
        $val = $stmt->fetchColumn();

        if ($val === false) {
            return !faction_acl_grade_has_any_permissions($db, $factionKey, $gradeName);
        }
        return (bool)(int)$val;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Bloque l'accès si l'utilisateur n'a pas la permission de consultation.
 * Affiche une page d'erreur 403 et interrompt l'exécution.
 */
function faction_acl_deny(string $message = 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.'): void {
    http_response_code(403);
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Accès refusé — MDT</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0f1117;color:#e2e8f0;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:20px;padding:24px}
  .icon{font-size:3.5rem;color:#ef4444}
  h1{font-size:1.8rem;font-weight:800}
  p{color:#94a3b8;max-width:440px;text-align:center;line-height:1.6}
  a{color:#3b82f6;text-decoration:none;padding:10px 20px;border:1px solid rgba(59,130,246,.3);border-radius:8px;font-size:.9rem;transition:.15s}
  a:hover{background:rgba(59,130,246,.1)}
</style></head>
<body>
  <i class="fa-solid fa-lock icon"></i>
  <h1>Accès refusé</h1>
  <p>{$message}</p>
  <a href="javascript:history.back()"><i class="fa-solid fa-arrow-left"></i> Retour</a>
</body>
</html>
HTML;
    exit;
}

/**
 * Raccourci : exige la permission de consultation, sinon bloque.
 */
function faction_acl_require_view(PDO $db, int $userId, string $factionKey, string $pagePath): void {
    if (!faction_acl_can_view($db, $userId, $factionKey, $pagePath)) {
        faction_acl_deny();
    }
}

/**
 * Raccourci : exige la permission d'édition (pour les actions POST/AJAX).
 * Retourne une erreur JSON si la requête est AJAX, sinon bloque.
 */
function faction_acl_require_edit(PDO $db, int $userId, string $factionKey, string $pagePath): void {
    if (!faction_acl_can_edit($db, $userId, $factionKey, $pagePath)) {
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Permission édition requise.']);
            exit;
        }
        faction_acl_deny('Vous n\'avez pas la permission d\'effectuer cette action.');
    }
}

// ============================================================================
// SYSTÈME DE PERMISSIONS GRANULAIRES PAR ACTION
// ============================================================================

/**
 * Crée la table faction_grade_action_permissions si elle n'existe pas.
 */
function faction_acl_ensure_action_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `faction_grade_action_permissions` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `faction_key` varchar(50) NOT NULL,
              `grade_name` varchar(100) NOT NULL,
              `permission_key` varchar(100) NOT NULL COMMENT 'ex: effectifs.recruit',
              `enabled` tinyint(1) NOT NULL DEFAULT 0,
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_grade_perm` (`faction_key`,`grade_name`,`permission_key`),
              KEY `idx_fk_grade` (`faction_key`,`grade_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $done = true;
    } catch (Throwable $e) {
        error_log('[faction_acl] ensure_action_table: ' . $e->getMessage());
    }
}

/**
 * Catalogue des permissions granulaires par type de page.
 * Format: [ 'page_stem' => [ 'permission.key' => 'Label lisible', ... ], ... ]
 *
 * Ces permissions sont communes à tous les panels entreprise qui partagent
 * les mêmes types de pages. Pour les panels spéciaux (LSPD, SAMS, etc.),
 * des entrées dédiées peuvent être ajoutées ici.
 */
function faction_acl_action_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) return $catalog;

    $catalog = [

        // ── Pages communes à tous les panels entreprise ──────────────────────

        'effectifs' => [
            'group'   => 'Gestion du Personnel',
            'icon'    => 'users',
            'actions' => [
                'effectifs.view'       => 'Voir les effectifs',
                'effectifs.recruit'    => 'Recruter un employé',
                'effectifs.edit_grade' => 'Modifier le grade',
                'effectifs.fire'       => 'Licencier un employé',
            ],
        ],

        'sanctions' => [
            'group'   => 'Sanctions',
            'icon'    => 'gavel',
            'actions' => [
                'sanctions.view'   => 'Voir les sanctions',
                'sanctions.create' => 'Ajouter une sanction',
                'sanctions.edit'   => 'Modifier le statut',
                'sanctions.delete' => 'Supprimer une sanction',
            ],
        ],

        'stock' => [
            'group'   => 'Gestion du Stock',
            'icon'    => 'box-open',
            'actions' => [
                'stock.view'   => 'Voir le stock',
                'stock.add'    => 'Ajouter un article',
                'stock.edit'   => 'Modifier un article',
                'stock.delete' => 'Retirer un article',
            ],
        ],

        'depenses' => [
            'group'   => 'Dépenses',
            'icon'    => 'coins',
            'actions' => [
                'depenses.view'   => 'Voir les dépenses',
                'depenses.add'    => 'Ajouter une dépense',
                'depenses.delete' => 'Supprimer une dépense',
            ],
        ],

        'facturation' => [
            'group'   => 'Facturation',
            'icon'    => 'file-invoice-dollar',
            'actions' => [
                'facturation.view'   => 'Voir les factures',
                'facturation.create' => 'Créer une facture',
                'facturation.edit'   => 'Modifier une facture',
                'facturation.delete' => 'Supprimer une facture',
                'facturation.send'   => 'Envoyer une facture',
            ],
        ],

        'vente' => [
            'group'   => 'Ventes',
            'icon'    => 'cash-register',
            'actions' => [
                'vente.view'   => 'Voir les ventes',
                'vente.create' => 'Enregistrer une vente',
                'vente.delete' => 'Annuler une vente',
            ],
        ],

        'prises-service' => [
            'group'   => 'Prises de Service',
            'icon'    => 'business-time',
            'actions' => [
                'prises-service.view'  => 'Voir les prises de service',
                'prises-service.start' => 'Prendre son service',
                'prises-service.stop'  => 'Quitter son service',
                'prises-service.manage'=> 'Gérer les services (admin)',
            ],
        ],

        'custom' => [
            'group'   => 'Catalogue Custom',
            'icon'    => 'car-side',
            'actions' => [
                'custom.view'      => 'Voir le catalogue',
                'custom.add'       => 'Ajouter un véhicule',
                'custom.edit'      => 'Modifier un véhicule',
                'custom.delete'    => 'Supprimer un véhicule',
                'custom.customize' => 'Modifier les configurations',
            ],
        ],

        'info_vente' => [
            'group'   => 'Classement Ventes',
            'icon'    => 'trophy',
            'actions' => [
                'info_vente.view'       => 'Voir le classement',
                'info_vente.edit_coeff' => 'Modifier les coefficients',
            ],
        ],

        'dashboard' => [
            'group'   => 'Dashboard',
            'icon'    => 'chart-line',
            'actions' => [
                'dashboard.view'  => 'Voir le dashboard',
                'dashboard.stats' => 'Voir les statistiques',
            ],
        ],

        'fourriere' => [
            'group'   => 'Fourrière',
            'icon'    => 'truck-ramp-box',
            'actions' => [
                'fourriere.view'        => 'Voir la fourrière',
                'fourriere.add'         => 'Ajouter un véhicule',
                'fourriere.edit'        => 'Modifier un véhicule',
                'fourriere.delete'      => 'Supprimer un véhicule',
                'fourriere.edit_tarif'  => 'Modifier les tarifs',
            ],
        ],

        // ── LSPD ──────────────────────────────────────────────────────────────

        'dispatch' => [
            'group'   => 'Dispatch',
            'icon'    => 'radio',
            'actions' => [
                'dispatch.view'   => 'Voir le dispatch',
                'dispatch.create' => 'Créer un incident',
                'dispatch.manage' => 'Gérer les incidents',
                'dispatch.close'  => 'Fermer un incident',
            ],
        ],

        'dispatch-logs' => [
            'group'   => 'Logs Dispatch',
            'icon'    => 'clock-rotate-left',
            'actions' => [
                'dispatch-logs.view'   => 'Voir les logs / cartes archivées (LOGS_DISPATCH)',
                'dispatch-logs.delete' => 'Supprimer des logs Dispatch (DELETE_LOGS_DISPATCH)',
            ],
        ],

        'wanted' => [
            'group'   => 'Wanted',
            'icon'    => 'crosshairs',
            'actions' => [
                'wanted.view'   => 'Voir les wanted',
                'wanted.add'    => 'Ajouter un wanted',
                'wanted.remove' => 'Retirer un wanted',
            ],
        ],

        'casiers' => [
            'group'   => 'Casiers Judiciaires',
            'icon'    => 'folder-open',
            'actions' => [
                'casiers.view'   => 'Consulter les casiers',
                'casiers.add'    => 'Ajouter une entrée',
                'casiers.edit'   => 'Modifier une entrée',
                'casiers.delete' => 'Supprimer une entrée',
            ],
        ],

        'rapports' => [
            'group'   => 'Rapports',
            'icon'    => 'file-lines',
            'actions' => [
                'rapports.view'   => 'Voir les rapports',
                'rapports.create' => 'Créer un rapport',
                'rapports.edit'   => 'Modifier un rapport',
                'rapports.delete' => 'Supprimer un rapport',
            ],
        ],

        'cdn' => [
            'group'   => 'Archives / CDN',
            'icon'    => 'images',
            'actions' => [
                'cdn.view'   => 'Voir les archives',
                'cdn.upload' => 'Ajouter une image',
                'cdn.delete' => 'Supprimer une image',
            ],
        ],

        'equipement' => [
            'group'   => 'Équipement',
            'icon'    => 'gun',
            'actions' => [
                'equipement.view' => "Voir l'équipement",
                'equipement.edit' => "Modifier l'équipement",
            ],
        ],

        'rapport-rookie' => [
            'group'   => 'Rapports Rookie',
            'icon'    => 'user-graduate',
            'actions' => [
                'rapport-rookie.view'      => 'Voir les rapports rookie',
                'rapport-rookie.create'    => 'Créer un rapport rookie',
                'rapport-rookie.edit_form' => 'Modifier le formulaire',
            ],
        ],

        'temps-service' => [
            'group'   => 'Temps de Service',
            'icon'    => 'business-time',
            'actions' => [
                'temps-service.view'   => 'Voir le temps de service',
                'temps-service.manage' => 'Gérer la paie et les timers',
            ],
        ],

        'absence' => [
            'group'   => 'Gestion des Absences',
            'icon'    => 'calendar-xmark',
            'actions' => [
                'absence.view'   => 'Voir les absences des agents',
                'absence.manage' => 'Valider / refuser les absences (ABSENCE_GESTION)',
            ],
        ],

        // ── SAMS ──────────────────────────────────────────────────────────────

        'interventions' => [
            'group'   => 'Interventions',
            'icon'    => 'truck-medical',
            'actions' => [
                'interventions.view'   => 'Voir les interventions',
                'interventions.create' => 'Créer une intervention',
                'interventions.close'  => 'Clôturer une intervention',
                'interventions.delete' => 'Supprimer une intervention',
            ],
        ],

        'patients' => [
            'group'   => 'Patients',
            'icon'    => 'bed-pulse',
            'actions' => [
                'patients.view'   => 'Voir les dossiers patients',
                'patients.create' => 'Créer un dossier',
                'patients.edit'   => 'Modifier un dossier',
                'patients.delete' => 'Supprimer un dossier',
            ],
        ],

        'pharmacie' => [
            'group'   => 'Pharmacie',
            'icon'    => 'pills',
            'actions' => [
                'pharmacie.view'        => 'Voir le stock pharmacie',
                'pharmacie.manage_stock'=> 'Gérer le stock',
                'pharmacie.prescribe'   => 'Établir une ordonnance',
            ],
        ],
    ];

    return $catalog;
}

/**
 * Vérifie si une permission granulaire est accordée à l'utilisateur.
 *
 * Logique (par ordre de priorité) :
 *  1. Staff non-membre de la faction → bypass total
 *  2. Lookup direct dans faction_grade_action_permissions
 *  3. Si le grade a des permissions d'actions configurées mais pas celle-ci → false
 *  4. Fallback sur le système page (can_view / can_edit) selon le suffixe
 */
function faction_acl_can(PDO $db, int $userId, string $factionKey, string $permKey): bool {
    if (faction_acl_staff_bypass($db, $userId, $factionKey)) return true;
    // Mode démo : seulement les actions de consultation, jamais de modification
    if (is_demo_mode()) {
        $suffix = explode('.', $permKey, 2)[1] ?? 'edit';
        return in_array($suffix, ['view', 'view_employees', 'stats', 'view_coeff', 'view_salary'], true);
    }

    faction_acl_ensure_action_table($db);

    $gradeName = faction_acl_user_grade_name($db, $userId, $factionKey);
    if ($gradeName === null) return false;
    if ($gradeName === '') return true;

    static $gradeActCache = [];
    $cacheKey = $factionKey . ':' . $gradeName;

    // Charger toutes les permissions d'actions du grade en une seule requête (cache par requête)
    if (!isset($gradeActCache[$cacheKey])) {
        try {
            $stmt = $db->prepare(
                "SELECT permission_key, enabled FROM faction_grade_action_permissions
                  WHERE faction_key = ? AND grade_name = ?"
            );
            $stmt->execute([$factionKey, $gradeName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $gradeActCache[$cacheKey] = [];
            foreach ($rows as $row) {
                $gradeActCache[$cacheKey][$row['permission_key']] = (bool)(int)$row['enabled'];
            }
        } catch (Throwable $e) {
            $gradeActCache[$cacheKey] = null; // erreur → fallback
        }
    }

    $actionPerms = $gradeActCache[$cacheKey];

    // Résultat direct si la clé est configurée
    if ($actionPerms !== null && array_key_exists($permKey, $actionPerms)) {
        return $actionPerms[$permKey];
    }

    // Le grade a des permissions d'actions mais pas celle-ci → restrictif
    if ($actionPerms !== null && count($actionPerms) > 0) {
        return false;
    }

    // Aucune permission d'action configurée → fallback page-level view/edit
    $parts    = explode('.', $permKey, 2);
    $pageFile = ($parts[0] ?? '') . '.php';
    // Les actions de consultation retombent sur can_view, les autres sur can_edit
    $viewActions = ['view', 'view_employees', 'view_salary', 'stats', 'view_coeff'];
    $suffix       = $parts[1] ?? 'edit';
    if (in_array($suffix, $viewActions, true)) {
        return faction_acl_can_view($db, $userId, $factionKey, $pageFile);
    }
    return faction_acl_can_edit($db, $userId, $factionKey, $pageFile);
}

/**
 * Exige une permission granulaire. Bloque avec 403 si refusé.
 * Pour les requêtes AJAX → retourne JSON {"error": "..."}.
 */
function faction_acl_require(PDO $db, int $userId, string $factionKey, string $permKey): void {
    if (!faction_acl_can($db, $userId, $factionKey, $permKey)) {
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => "Permission requise : {$permKey}"]);
            exit;
        }
        faction_acl_deny("Permission requise : <code>{$permKey}</code>");
    }
}

/**
 * Récupère les permissions d'actions d'un grade (pour l'UI du panel staff).
 * Retourne ['effectifs.recruit' => true, 'effectifs.fire' => false, ...]
 */
function faction_acl_get_action_permissions(string $factionKey, string $gradeName, PDO $db): array {
    faction_acl_ensure_action_table($db);
    try {
        $stmt = $db->prepare(
            "SELECT permission_key, enabled FROM faction_grade_action_permissions
              WHERE faction_key = ? AND grade_name = ?"
        );
        $stmt->execute([$factionKey, $gradeName]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['permission_key']] = (bool)(int)$row['enabled'];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Enregistre les permissions d'actions d'un grade (remplacement complet).
 * $actions : ['effectifs.recruit' => true, 'effectifs.fire' => false, ...]
 */
function faction_acl_save_action_permissions(string $factionKey, string $gradeName, array $actions, PDO $db): void {
    faction_acl_ensure_action_table($db);
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM faction_grade_action_permissions WHERE faction_key=? AND grade_name=?")
           ->execute([$factionKey, $gradeName]);
        $ins = $db->prepare(
            "INSERT INTO faction_grade_action_permissions (faction_key, grade_name, permission_key, enabled)
             VALUES (?,?,?,?)"
        );
        foreach ($actions as $key => $enabled) {
            $key = preg_replace('/[^a-z0-9._\-]/', '', strtolower((string)$key));
            if ($key !== '') {
                $ins->execute([$factionKey, $gradeName, $key, $enabled ? 1 : 0]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[faction_acl] save_action_permissions: ' . $e->getMessage());
        throw $e;
    }
}
