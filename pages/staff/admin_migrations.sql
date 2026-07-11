-- ================================================
-- LYSORIA MDT — ADMIN PANEL MIGRATIONS
-- À exécuter sur votre base de données
-- ================================================

-- Table des paramètres admin (clé-valeur)
CREATE TABLE IF NOT EXISTS `admin_settings` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valeurs par défaut
INSERT IGNORE INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
('site_name',           'LysoriaMDT'),
('site_description',    'Plateforme MDT pour serveur FiveM'),
('timezone',            'Europe/Paris'),
('addon_dispatch',      '0'),
('addon_life_invader',  '0');

-- Table des panels (activation/désactivation)
CREATE TABLE IF NOT EXISTS `admin_panels` (
    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `panel_key` VARCHAR(50) NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuration personnalisée des panels (couleur, nom, pages actives)
CREATE TABLE IF NOT EXISTS `admin_panel_config` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `panel_key`    VARCHAR(50) NOT NULL UNIQUE,
    `custom_name`  VARCHAR(100),
    `custom_color` VARCHAR(20),
    `active_pages` JSON,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Factions personnalisées
CREATE TABLE IF NOT EXISTS `factions` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL,
    `faction_key` VARCHAR(50)  NOT NULL UNIQUE,
    `color`       VARCHAR(20)  NOT NULL DEFAULT '#3b82f6',
    `icon`        VARCHAR(50)  NOT NULL DEFAULT 'shield',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grades par faction
CREATE TABLE IF NOT EXISTS `faction_grades` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `faction_id`  INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL,
    `rank_order`  SMALLINT    NOT NULL DEFAULT 99,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`faction_id`) REFERENCES `factions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Colonne grade_id sur users (si elle n'existe pas)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `grade_id`     INT UNSIGNED DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_banned`    TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `ban_reason`   TEXT;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `ban_expires`  DATETIME     DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `banned_by`    INT UNSIGNED DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `banned_at`    DATETIME     DEFAULT NULL;

-- Sanctions
CREATE TABLE IF NOT EXISTS `sanctions` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `type`       ENUM('Avertissement','Bannissement Temp','Bannissement Def','Révocation','Suspension') NOT NULL,
    `reason`     TEXT NOT NULL,
    `notes`      TEXT,
    `duration`   VARCHAR(100),
    `issued_by`  INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`),
    INDEX (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Colonne ip_address sur logs (si elle n'existe pas)
ALTER TABLE `logs` ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) DEFAULT NULL;
