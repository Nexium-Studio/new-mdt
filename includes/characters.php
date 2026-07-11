<?php
// includes/characters.php
// ═══════════════════════════════════════════════════════════════════════
// SYSTÈME MULTI-PERSONNAGES
// Un utilisateur possède plusieurs personnages ; le personnage actif est
// stocké en session ($_SESSION['character_id']) et « mirroré » sur la
// ligne users (faction, grade, avatar, profile_id) afin que tout le MDT
// existant fonctionne avec le personnage actif sans modification.
// ═══════════════════════════════════════════════════════════════════════

if (!defined('MDT_CHARACTERS_SCHEMA_VERSION')) {
    define('MDT_CHARACTERS_SCHEMA_VERSION', '1');
}

if (!function_exists('mdt_characters_table_has_column')) {
    /** Détecte la présence d'une colonne (schémas différents selon migrations). */
    function mdt_characters_table_has_column(PDO $db, string $table, string $column): bool {
        try {
            $st = $db->prepare('
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ');
            $st->execute([$table, $column]);
            return ((int) $st->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('mdt_characters_ensure_schema')) {
    /** Auto-migration : crée la table characters + colonnes annexes + backfill. */
    function mdt_characters_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;

        // Version déjà appliquée ? (1 SELECT, pas de re-scan à chaque hit)
        try {
            $st = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'characters_schema_version' LIMIT 1");
            $st->execute();
            if ((string) $st->fetchColumn() === MDT_CHARACTERS_SCHEMA_VERSION) return;
        } catch (Exception $e) {}

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `characters` (
                    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id`       INT NOT NULL COMMENT 'FK → users.id',
                    `firstname`     VARCHAR(100) NOT NULL,
                    `lastname`      VARCHAR(100) NOT NULL,
                    `avatar`        VARCHAR(500) DEFAULT NULL,
                    `height_cm`     SMALLINT UNSIGNED DEFAULT NULL,
                    `age`           TINYINT UNSIGNED DEFAULT NULL,
                    `faction`       VARCHAR(50) NOT NULL DEFAULT 'NONE' COMMENT 'clé factions.faction_key',
                    `faction_rank`  VARCHAR(100) DEFAULT NULL,
                    `faction_grade` VARCHAR(100) DEFAULT NULL,
                    `grade_id`      INT UNSIGNED DEFAULT NULL COMMENT 'FK → faction_grades.id',
                    `profile_id`    INT DEFAULT NULL COMMENT 'FK → profiles.id',
                    `is_deleted`    TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_characters_user` (`user_id`),
                    INDEX `idx_characters_profile` (`profile_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {}

        if (!mdt_characters_table_has_column($db, 'users', 'active_character_id')) {
            try { $db->exec("ALTER TABLE `users` ADD COLUMN `active_character_id` INT UNSIGNED DEFAULT NULL COMMENT 'FK → characters.id (personnage actif)'"); } catch (Exception $e) {}
        }
        if (!mdt_characters_table_has_column($db, 'mdt_roles', 'max_characters')) {
            try {
                $db->exec("ALTER TABLE `mdt_roles` ADD COLUMN `max_characters` INT NOT NULL DEFAULT 3");
                $db->exec("UPDATE `mdt_roles` SET `max_characters` = 10 WHERE `name` IN ('*','Administrateur','Développeur')");
                $db->exec("UPDATE `mdt_roles` SET `max_characters` = 5 WHERE `name` IN ('Staff','Modérateur')");
            } catch (Exception $e) {}
        }

        // Permission EDIT_PROFILS pour les rôles élevés (pas d'UNIQUE → INSERT conditionnel)
        try {
            $db->exec("
                INSERT INTO `mdt_role_staff_permissions` (`role_name`, `permission_key`, `enabled`)
                SELECT r.`name`, 'staff.users.edit_profils', 1
                FROM `mdt_roles` r
                WHERE r.`name` IN ('*','Administrateur','Développeur')
                  AND NOT EXISTS (
                    SELECT 1 FROM `mdt_role_staff_permissions` sp
                    WHERE sp.`role_name` = r.`name` AND sp.`permission_key` = 'staff.users.edit_profils'
                  )
            ");
        } catch (Exception $e) {}

        // Backfill : personnage n°1 pour chaque compte existant
        try {
            $db->exec("
                INSERT INTO `characters` (`user_id`,`firstname`,`lastname`,`avatar`,`age`,`faction`,`faction_rank`,`faction_grade`,`grade_id`,`profile_id`,`created_at`)
                SELECT u.`id`,
                       COALESCE(NULLIF(TRIM(p.`firstname`),''), SUBSTRING_INDEX(u.`username`,' ',1)),
                       COALESCE(NULLIF(TRIM(p.`lastname`),''), NULLIF(TRIM(SUBSTRING(u.`username`, LENGTH(SUBSTRING_INDEX(u.`username`,' ',1))+1)),''), '—'),
                       u.`avatar`, p.`age`, COALESCE(NULLIF(u.`faction`,''),'NONE'),
                       u.`faction_rank`, u.`faction_grade`, u.`grade_id`, u.`profile_id`, u.`created_at`
                FROM `users` u
                LEFT JOIN `profiles` p ON p.`id` = u.`profile_id`
                WHERE NOT EXISTS (SELECT 1 FROM `characters` c WHERE c.`user_id` = u.`id`)
            ");
        } catch (Exception $e) {}

        try {
            $db->prepare("
                INSERT INTO admin_settings (setting_key, setting_value) VALUES ('characters_schema_version', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([MDT_CHARACTERS_SCHEMA_VERSION]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('mdt_get_characters')) {
    /** Tous les personnages (non supprimés) d'un utilisateur, avec infos faction. */
    function mdt_get_characters(PDO $db, int $userId): array {
        try {
            $st = $db->prepare("
                SELECT c.*, f.name AS faction_name, f.color AS faction_color, f.icon AS faction_icon
                FROM characters c
                LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = c.faction
                WHERE c.user_id = ? AND c.is_deleted = 0
                ORDER BY c.created_at ASC, c.id ASC
            ");
            $st->execute([$userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('mdt_get_character')) {
    /** Un personnage par id (avec infos faction), ou null. */
    function mdt_get_character(PDO $db, int $charId): ?array {
        try {
            $st = $db->prepare("
                SELECT c.*, f.name AS faction_name, f.color AS faction_color, f.icon AS faction_icon
                FROM characters c
                LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = c.faction
                WHERE c.id = ? LIMIT 1
            ");
            $st->execute([$charId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('mdt_count_characters')) {
    function mdt_count_characters(PDO $db, int $userId): int {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM characters WHERE user_id = ? AND is_deleted = 0");
            $st->execute([$userId]);
            return (int) $st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('mdt_get_active_character')) {
    /** Le personnage actif de la session courante, ou null. */
    function mdt_get_active_character(PDO $db): ?array {
        $charId = (int) ($_SESSION['character_id'] ?? 0);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($charId <= 0 || $userId <= 0) return null;
        $char = mdt_get_character($db, $charId);
        if (!$char || (int) $char['user_id'] !== $userId || !empty($char['is_deleted'])) return null;
        return $char;
    }
}

if (!function_exists('mdt_character_limit')) {
    /** Nombre max de personnages autorisés pour un rôle (défaut 3). */
    function mdt_character_limit(PDO $db, string $role): int {
        try {
            $st = $db->prepare("SELECT max_characters FROM mdt_roles WHERE name = ? LIMIT 1");
            $st->execute([$role]);
            $max = $st->fetchColumn();
            if ($max !== false && $max !== null) return max(0, (int) $max);
        } catch (Exception $e) {}
        return 3;
    }
}

if (!function_exists('mdt_character_mirror_to_user')) {
    /**
     * Copie les données d'un personnage sur la ligne users (miroir) :
     * tout le MDT existant (session, jointures SQL) suit alors ce personnage.
     */
    function mdt_character_mirror_to_user(PDO $db, array $char): void {
        $userId = (int) $char['user_id'];
        $db->prepare("
            UPDATE users
            SET faction = ?, faction_rank = ?, faction_grade = ?, grade_id = ?,
                avatar = ?, profile_id = ?, active_character_id = ?
            WHERE id = ?
        ")->execute([
            $char['faction'] ?: 'NONE',
            $char['faction_rank'],
            $char['faction_grade'],
            $char['grade_id'] ?: null,
            $char['avatar'],
            $char['profile_id'] ?: null,
            (int) $char['id'],
            $userId,
        ]);

        // users.faction_id (colonne récente) : best-effort
        try {
            $db->prepare("
                UPDATE users SET faction_id = (
                    SELECT id FROM factions WHERE faction_key COLLATE utf8mb4_unicode_ci = ? LIMIT 1
                ) WHERE id = ?
            ")->execute([$char['faction'] ?: 'NONE', $userId]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('mdt_character_hydrate_session')) {
    /** Recharge les variables de session avec le personnage actif. */
    function mdt_character_hydrate_session(array $char): void {
        $_SESSION['character_id']       = (int) $char['id'];
        $_SESSION['user_avatar']        = $char['avatar'];
        $_SESSION['user_faction']       = $char['faction'] ?: 'NONE';
        $_SESSION['user_faction_rank']  = $char['faction_rank'];
        $_SESSION['user_faction_grade'] = $char['faction_grade'];
        if (function_exists('mdt_session_refresh_display_name')) {
            mdt_session_refresh_display_name();
        }
    }
}

if (!function_exists('mdt_select_character')) {
    /** Sélectionne un personnage : vérifie la propriété, mirrore sur users, hydrate la session. */
    function mdt_select_character(PDO $db, int $userId, int $charId): bool {
        $char = mdt_get_character($db, $charId);
        if (!$char || (int) $char['user_id'] !== $userId || !empty($char['is_deleted'])) return false;

        try {
            mdt_character_mirror_to_user($db, $char);
        } catch (Exception $e) {
            return false;
        }
        mdt_character_hydrate_session($char);

        if (function_exists('mdt_audit_log')) {
            mdt_audit_log($userId, 'AUTH', 'Sélection personnage',
                'Personnage #' . (int) $char['id'] . ' (' . $char['firstname'] . ' ' . $char['lastname'] . ')',
                'character', ($char['faction'] && $char['faction'] !== 'NONE') ? $char['faction'] : null);
        }
        return true;
    }
}

if (!function_exists('mdt_create_character')) {
    /**
     * Crée un personnage (+ profil citoyen léger lié, pour que le nom RP et les
     * données keyées profile_id suivent le personnage). Retourne l'id ou 0.
     * $data = ['firstname','lastname','height_cm','age','avatar']
     */
    function mdt_create_character(PDO $db, int $userId, array $data): int {
        $role  = (string) ($_SESSION['user_role'] ?? 'Citoyen');
        $limit = mdt_character_limit($db, $role);
        if (mdt_count_characters($db, $userId) >= $limit) return 0;

        $firstname = trim((string) ($data['firstname'] ?? ''));
        $lastname  = trim((string) ($data['lastname'] ?? ''));
        if ($firstname === '' || $lastname === '') return 0;
        $heightCm = isset($data['height_cm']) && $data['height_cm'] !== '' ? (int) $data['height_cm'] : null;
        $age      = isset($data['age']) && $data['age'] !== '' ? (int) $data['age'] : null;
        $avatar   = isset($data['avatar']) && $data['avatar'] !== '' ? (string) $data['avatar'] : null;

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO profiles (firstname, lastname, age, avatar, created_by) VALUES (?, ?, ?, ?, ?)")
               ->execute([$firstname, $lastname, $age, $avatar, $userId]);
            $profileId = (int) $db->lastInsertId();

            $db->prepare("
                INSERT INTO characters (user_id, firstname, lastname, avatar, height_cm, age, profile_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$userId, $firstname, $lastname, $avatar, $heightCm, $age, $profileId ?: null]);
            $charId = (int) $db->lastInsertId();

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            return 0;
        }

        if (function_exists('mdt_audit_log')) {
            mdt_audit_log($userId, 'PROFILE', 'Création personnage',
                'Personnage #' . $charId . ' (' . $firstname . ' ' . $lastname . ')', 'character', null);
        }
        return $charId;
    }
}

if (!function_exists('mdt_update_character')) {
    /**
     * Met à jour un personnage.
     * Champs joueur : firstname, lastname, height_cm, age, avatar.
     * Champs admin ($allowFactionEdit) : faction, faction_rank, faction_grade, grade_id.
     * Propage vers le profil lié uniquement s'il a été auto-créé par le joueur
     * (jamais une fiche du recensement police), puis re-mirrore sur users si le
     * personnage est celui actuellement actif.
     */
    function mdt_update_character(PDO $db, int $charId, array $data, bool $allowFactionEdit = false): bool {
        $char = mdt_get_character($db, $charId);
        if (!$char) return false;

        $fields = [];
        $params = [];
        foreach (['firstname', 'lastname'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $char[$f] = trim((string) $data[$f]);
            }
        }
        foreach (['height_cm', 'age'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = ($data[$f] === '' || $data[$f] === null) ? null : (int) $data[$f];
                $char[$f] = $params[count($params) - 1];
            }
        }
        if (isset($data['avatar']) && $data['avatar'] !== '') {
            $fields[] = 'avatar = ?';
            $params[] = (string) $data['avatar'];
            $char['avatar'] = (string) $data['avatar'];
        }
        if ($allowFactionEdit) {
            if (array_key_exists('faction', $data)) {
                $fields[] = 'faction = ?';
                $params[] = (string) ($data['faction'] ?: 'NONE');
                $char['faction'] = (string) ($data['faction'] ?: 'NONE');
            }
            foreach (['faction_rank', 'faction_grade'] as $f) {
                if (array_key_exists($f, $data)) {
                    $fields[] = "$f = ?";
                    $params[] = ($data[$f] === '' || $data[$f] === null) ? null : (string) $data[$f];
                    $char[$f] = $params[count($params) - 1];
                }
            }
            if (array_key_exists('grade_id', $data)) {
                $fields[] = 'grade_id = ?';
                $params[] = $data['grade_id'] ? (int) $data['grade_id'] : null;
                $char['grade_id'] = $params[count($params) - 1];
            }
        }
        if (!$fields) return true;

        $params[] = $charId;
        try {
            $db->prepare('UPDATE characters SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        } catch (Exception $e) {
            return false;
        }

        // Propagation vers le profil citoyen auto-créé (identité + photo)
        try {
            $pid = (int) ($char['profile_id'] ?? 0);
            if ($pid > 0) {
                $st = $db->prepare('SELECT created_by FROM profiles WHERE id = ? LIMIT 1');
                $st->execute([$pid]);
                $createdBy = $st->fetchColumn();
                if ($createdBy !== false && (int) $createdBy === (int) $char['user_id']) {
                    $db->prepare('UPDATE profiles SET firstname = ?, lastname = ?, age = ?, avatar = ?, updated_at = NOW() WHERE id = ?')
                       ->execute([$char['firstname'], $char['lastname'], $char['age'], $char['avatar'], $pid]);
                }
            }
        } catch (Exception $e) {}

        // Re-miroir si c'est le personnage actif de son propriétaire
        try {
            $st = $db->prepare('SELECT active_character_id FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int) $char['user_id']]);
            if ((int) $st->fetchColumn() === $charId) {
                mdt_character_mirror_to_user($db, $char);

                // Effectifs entreprise : suivre le grade du personnage actif
                if ($allowFactionEdit && !empty($char['faction']) && $char['faction'] !== 'NONE') {
                    try {
                        $db->prepare('UPDATE company_employees SET rank = ? WHERE user_id = ? AND faction = ?')
                           ->execute([(string) ($char['faction_rank'] ?? ''), (int) $char['user_id'], $char['faction']]);
                    } catch (Exception $e) {}
                }

                // Si c'est l'utilisateur de la session courante, ré-hydrater
                if ((int) ($_SESSION['user_id'] ?? 0) === (int) $char['user_id']
                    && (int) ($_SESSION['character_id'] ?? 0) === $charId) {
                    mdt_character_hydrate_session($char);
                }
            }
        } catch (Exception $e) {}

        return true;
    }
}

if (!function_exists('mdt_require_character')) {
    /**
     * Garde : exige un personnage actif valide, sinon redirige vers la sélection.
     * Filet de sécurité contre les connexions qui contournent login.php
     * (remember-me, OAuth Discord).
     */
    function mdt_require_character(): void {
        if (empty($_SESSION['user_id'])) return; // requireLogin() s'en charge

        static $checked = false;
        if ($checked) return;

        $charId = (int) ($_SESSION['character_id'] ?? 0);
        if ($charId > 0) {
            try {
                $db = Database::getInstance()->getConnection();
                mdt_characters_ensure_schema($db);
                $st = $db->prepare('SELECT user_id, is_deleted FROM characters WHERE id = ? LIMIT 1');
                $st->execute([$charId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && (int) $row['user_id'] === (int) $_SESSION['user_id'] && empty($row['is_deleted'])) {
                    $checked = true;
                    return;
                }
            } catch (Exception $e) {
                // BDD indisponible : ne pas boucler en redirections
                $checked = true;
                return;
            }
            unset($_SESSION['character_id']);
        }
        redirect(SITE_URL . '/pages/personnage.php');
    }
}

if (!function_exists('mdt_staff_can')) {
    /** Permission staff granulaire (table mdt_role_staff_permissions). */
    function mdt_staff_can(PDO $db, string $permKey): bool {
        $role = (string) ($_SESSION['user_role'] ?? '');
        if ($role === '') return false;
        if ($role === '*') return true;
        try {
            $st = $db->prepare('SELECT enabled FROM mdt_role_staff_permissions WHERE role_name = ? AND permission_key = ? LIMIT 1');
            $st->execute([$role, $permKey]);
            $enabled = $st->fetchColumn();
            if ($enabled !== false) return (bool) (int) $enabled;
        } catch (Exception $e) {
            return false;
        }
        // Permission jamais configurée : permissif pour les rôles élevés uniquement
        return in_array($role, ['Administrateur', 'Développeur'], true);
    }
}

if (!function_exists('mdt_characters_merge_staff_catalog')) {
    /**
     * Injecte la permission personnages dans le catalogue des permissions staff
     * (mdt_staff_permissions_catalog() est définie dans le config, non modifiable ici).
     * Forme des sections : ['group' => label, 'icon' => icon, 'perms' => [clé => label]].
     */
    function mdt_characters_merge_staff_catalog(array $catalog): array {
        $key   = 'staff.users.edit_profils';
        $label = 'Éditer les fiches personnages (faction / grade)';
        foreach ($catalog as $i => $section) {
            $perms = $section['perms'] ?? [];
            if (isset($perms[$key])) return $catalog;
            foreach (array_keys($perms) as $k) {
                if (strpos((string) $k, 'staff.users.') === 0) {
                    $catalog[$i]['perms'][$key] = $label;
                    return $catalog;
                }
            }
        }
        $catalog[] = ['group' => 'Personnages', 'icon' => 'user-group', 'perms' => [$key => $label]];
        return $catalog;
    }
}

if (!function_exists('mdt_character_handle_avatar_upload')) {
    /**
     * Upload de la photo d'un personnage (PNG / JPG / JPEG, max 4 Mo).
     * Retourne ['ok' => bool, 'path' => ?string (chemin web relatif), 'error' => ?string].
     */
    function mdt_character_handle_avatar_upload(?array $file): array {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => null, 'error' => null]; // pas de fichier = pas d'erreur
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null, 'error' => 'Erreur lors de l\'envoi du fichier.'];
        }
        if ($file['size'] > 4 * 1024 * 1024) {
            return ['ok' => false, 'path' => null, 'error' => 'Fichier trop lourd (max 4 Mo).'];
        }

        $exts  = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($exts[$mime])) {
            return ['ok' => false, 'path' => null, 'error' => 'Format non supporté (PNG, JPG, JPEG uniquement).'];
        }

        $uploadDir = rtrim(ROOT_PATH, '/\\') . '/assets/uploads/characters/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return ['ok' => false, 'path' => null, 'error' => 'Impossible de créer le dossier d\'upload.'];
            }
            // Neutraliser toute exécution de script dans le dossier d'upload
            @file_put_contents($uploadDir . '.htaccess',
                "php_flag engine off\nOptions -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\n");
        }

        $filename = bin2hex(random_bytes(8)) . '.' . $exts[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            return ['ok' => false, 'path' => null, 'error' => 'Erreur lors de l\'enregistrement du fichier.'];
        }
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        return ['ok' => true, 'path' => $base . '/assets/uploads/characters/' . $filename, 'error' => null];
    }
}
