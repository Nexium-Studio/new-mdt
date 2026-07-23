-- ============================================================
-- Migration LSSD — tables dédiées (générées depuis le schéma LSPD)
-- Panels indépendants : lssd_* (opérationnel) + lssd_dispatch_* (dispatch)
-- Tables PARTAGÉES volontairement NON recréées : penal_code, profiles,
-- users, vehicles, criminal_records, reports, factions, faction_grades,
-- faction_divisions, panel_page_permissions, admin_panel_config...
-- À exécuter sur la base distante (MariaDB/MySQL).
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

-- ---- `lssd_dispatch_card_global_checks` ----
DROP TABLE IF EXISTS `lssd_dispatch_card_global_checks`;
CREATE TABLE `lssd_dispatch_card_global_checks` (
  `card_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 1,
  `done_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_card_global_checks`
  ADD PRIMARY KEY (`card_id`,`item_id`),
  ADD KEY `idx_card` (`card_id`),
  ADD KEY `fk_dcgc_item` (`item_id`);

-- ---- `lssd_dispatch_card_labels` ----
DROP TABLE IF EXISTS `lssd_dispatch_card_labels`;
CREATE TABLE `lssd_dispatch_card_labels` (
  `card_id` int(11) NOT NULL,
  `label_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_card_labels`
  ADD PRIMARY KEY (`card_id`,`label_id`),
  ADD KEY `label_id` (`label_id`);

-- ---- `lssd_dispatch_card_logs` ----
DROP TABLE IF EXISTS `lssd_dispatch_card_logs`;
CREATE TABLE `lssd_dispatch_card_logs` (
  `id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_dispatch_card_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`),
  ADD KEY `idx_created` (`created_at`);
ALTER TABLE `lssd_dispatch_card_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_cards` ----
DROP TABLE IF EXISTS `lssd_dispatch_cards`;
CREATE TABLE `lssd_dispatch_cards` (
  `id` int(11) NOT NULL,
  `list_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('critique','haute','normale','faible') DEFAULT 'normale',
  `due_date` datetime DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `grade_tags` text DEFAULT NULL,
  `patrol_vehicle` varchar(100) DEFAULT NULL,
  `vehicle_plate` varchar(16) DEFAULT NULL,
  `code_ten_tags` text DEFAULT NULL,
  `other_tags` text DEFAULT NULL,
  `card_color` varchar(20) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `use_global_checklists` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_agents` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `list_id` (`list_id`),
  ADD KEY `idx_vehicle_plate` (`vehicle_plate`);
ALTER TABLE `lssd_dispatch_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_cards_archive` ----
DROP TABLE IF EXISTS `lssd_dispatch_cards_archive`;
CREATE TABLE `lssd_dispatch_cards_archive` (
  `id` int(11) NOT NULL,
  `list_name` varchar(128) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `priority` varchar(32) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `assigned_to` text DEFAULT NULL,
  `grade_tags` text DEFAULT NULL,
  `patrol_vehicle` varchar(128) DEFAULT NULL,
  `vehicle_plate` varchar(16) DEFAULT NULL,
  `code_ten_tags` text DEFAULT NULL,
  `other_tags` text DEFAULT NULL,
  `card_color` varchar(20) DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT current_timestamp(),
  `deleted_by` int(11) DEFAULT NULL,
  `snapshot` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_dispatch_cards_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted` (`deleted_at`);

-- ---- `lssd_dispatch_checklist_items` ----
DROP TABLE IF EXISTS `lssd_dispatch_checklist_items`;
CREATE TABLE `lssd_dispatch_checklist_items` (
  `id` int(11) NOT NULL,
  `checklist_id` int(11) NOT NULL,
  `text` varchar(255) NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `checklist_id` (`checklist_id`);
ALTER TABLE `lssd_dispatch_checklist_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_checklists` ----
DROP TABLE IF EXISTS `lssd_dispatch_checklists`;
CREATE TABLE `lssd_dispatch_checklists` (
  `id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL DEFAULT 'Checklist',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_checklists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `card_id` (`card_id`);
ALTER TABLE `lssd_dispatch_checklists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_comments` ----
DROP TABLE IF EXISTS `lssd_dispatch_comments`;
CREATE TABLE `lssd_dispatch_comments` (
  `id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `author` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `card_id` (`card_id`);
ALTER TABLE `lssd_dispatch_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_config` ----
DROP TABLE IF EXISTS `lssd_dispatch_config`;
CREATE TABLE `lssd_dispatch_config` (
  `config_key` varchar(64) NOT NULL,
  `config_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
ALTER TABLE `lssd_dispatch_config`
  ADD PRIMARY KEY (`config_key`);

-- ---- `lssd_dispatch_global_checklist_items` ----
DROP TABLE IF EXISTS `lssd_dispatch_global_checklist_items`;
CREATE TABLE `lssd_dispatch_global_checklist_items` (
  `id` int(11) NOT NULL,
  `checklist_id` int(11) NOT NULL,
  `text` varchar(255) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_global_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_checklist` (`checklist_id`);
ALTER TABLE `lssd_dispatch_global_checklist_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_global_checklists` ----
DROP TABLE IF EXISTS `lssd_dispatch_global_checklists`;
CREATE TABLE `lssd_dispatch_global_checklists` (
  `id` int(11) NOT NULL,
  `title` varchar(128) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_global_checklists`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_dispatch_global_checklists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_grades` ----
DROP TABLE IF EXISTS `lssd_dispatch_grades`;
CREATE TABLE `lssd_dispatch_grades` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_grades`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_dispatch_grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_labels` ----
DROP TABLE IF EXISTS `lssd_dispatch_labels`;
CREATE TABLE `lssd_dispatch_labels` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#00e5ff'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_labels`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_dispatch_labels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_lists` ----
DROP TABLE IF EXISTS `lssd_dispatch_lists`;
CREATE TABLE `lssd_dispatch_lists` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `color` varchar(20) DEFAULT '#00e5ff',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_lists`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_dispatch_lists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_patrol_vehicles` ----
DROP TABLE IF EXISTS `lssd_dispatch_patrol_vehicles`;
CREATE TABLE `lssd_dispatch_patrol_vehicles` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_patrol_vehicles`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_dispatch_patrol_vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_dispatch_vehicle_plates` ----
DROP TABLE IF EXISTS `lssd_dispatch_vehicle_plates`;
CREATE TABLE `lssd_dispatch_vehicle_plates` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `plate` varchar(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_dispatch_vehicle_plates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_vehicle_plate` (`vehicle_id`,`plate`),
  ADD KEY `idx_plate` (`plate`);
ALTER TABLE `lssd_dispatch_vehicle_plates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_absences` ----
DROP TABLE IF EXISTS `lssd_absences`;
CREATE TABLE `lssd_absences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `character_id` int(11) DEFAULT NULL,
  `profile_id` int(11) DEFAULT NULL,
  `agent_name` varchar(160) NOT NULL DEFAULT '',
  `agent_avatar` varchar(500) DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `motif` text DEFAULT NULL,
  `statut` enum('en_attente','validee','refusee') NOT NULL DEFAULT 'en_attente',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `notif_seen` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_absences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_dates` (`date_debut`,`date_fin`);
ALTER TABLE `lssd_absences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_activity_logs` ----
DROP TABLE IF EXISTS `lssd_activity_logs`;
CREATE TABLE `lssd_activity_logs` (
  `id` int(11) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL DEFAULT '',
  `faction_rank` varchar(100) NOT NULL DEFAULT '',
  `action` varchar(80) NOT NULL COMMENT 'ex: recruit, promote, fire, add_report...',
  `page` varchar(60) NOT NULL COMMENT 'ex: effectifs, reports, casiers...',
  `target_id` int(11) DEFAULT NULL COMMENT 'id de la ressource affectée',
  `target_label` varchar(200) DEFAULT NULL COMMENT 'ex: nom de l officier, n rapport...',
  `details` text DEFAULT NULL COMMENT 'JSON ou texte libre',
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_page` (`page`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`);
ALTER TABLE `lssd_activity_logs`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_announcements` ----
DROP TABLE IF EXISTS `lssd_announcements`;
CREATE TABLE `lssd_announcements` (
  `id` int(10) UNSIGNED NOT NULL,
  `author_id` int(10) UNSIGNED NOT NULL,
  `author_name` varchar(100) NOT NULL,
  `author_rank` varchar(100) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `tag` enum('Information','Urgent','Communiquer') NOT NULL DEFAULT 'Information',
  `content` text NOT NULL,
  `image_url` varchar(2048) DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`);
ALTER TABLE `lssd_announcements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_armes_civils` ----
DROP TABLE IF EXISTS `lssd_armes_civils`;
CREATE TABLE `lssd_armes_civils` (
  `id` int(10) UNSIGNED NOT NULL,
  `citizen_id` int(10) UNSIGNED NOT NULL,
  `type_arme` varchar(100) NOT NULL,
  `modele` varchar(200) DEFAULT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `calibre` varchar(50) DEFAULT NULL,
  `date_enregistrement` date NOT NULL,
  `date_expiration` date DEFAULT NULL,
  `statut` enum('enregistree','suspendue','revoquee') NOT NULL DEFAULT 'enregistree',
  `officer_id` int(10) UNSIGNED DEFAULT NULL,
  `ppa_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `images` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_armes_civils`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_citizen` (`citizen_id`),
  ADD KEY `idx_statut` (`statut`);
ALTER TABLE `lssd_armes_civils`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_bracelets` ----
DROP TABLE IF EXISTS `lssd_bracelets`;
CREATE TABLE `lssd_bracelets` (
  `id` int(11) NOT NULL,
  `numero_bracelet` varchar(40) NOT NULL,
  `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
  `rapport_id` int(11) NOT NULL COMMENT 'FK → reports.id',
  `agent_id` int(11) DEFAULT NULL,
  `date_pose` date NOT NULL,
  `date_fin_prevue` date NOT NULL,
  `date_retrait` date DEFAULT NULL,
  `zone_autorisee` varchar(400) DEFAULT NULL COMMENT 'Zones autorisées',
  `horaires_autorisees` varchar(300) DEFAULT NULL,
  `conditions_speciales` text DEFAULT NULL,
  `statut` enum('actif','retire','violation','expire','suspendu') DEFAULT 'actif',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_bracelets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_bracelet` (`numero_bracelet`),
  ADD KEY `citoyen_id` (`citoyen_id`),
  ADD KEY `rapport_id` (`rapport_id`),
  ADD KEY `statut` (`statut`);
ALTER TABLE `lssd_bracelets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_cdn_images` ----
DROP TABLE IF EXISTS `lssd_cdn_images`;
CREATE TABLE `lssd_cdn_images` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `file_path` varchar(255) DEFAULT NULL,
  `url` varchar(1024) DEFAULT NULL,
  `is_local` tinyint(1) NOT NULL DEFAULT 1,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_cdn_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_created_at` (`created_at`);
ALTER TABLE `lssd_cdn_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_citizen_notes` ----
DROP TABLE IF EXISTS `lssd_citizen_notes`;
CREATE TABLE `lssd_citizen_notes` (
  `id` int(11) NOT NULL,
  `citizen_id` int(11) NOT NULL,
  `author_id` int(11) DEFAULT NULL,
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_citizen_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_citizen` (`citizen_id`);
ALTER TABLE `lssd_citizen_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_commendations` ----
DROP TABLE IF EXISTS `lssd_commendations`;
CREATE TABLE `lssd_commendations` (
  `id` int(11) NOT NULL,
  `officer_id` int(11) NOT NULL,
  `type` enum('medal_bravery','medal_service','citation','commendation_letter','officer_month','other') DEFAULT 'commendation_letter',
  `titre` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `awarded_by` int(11) NOT NULL,
  `award_date` date NOT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_commendations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_officer` (`officer_id`);
ALTER TABLE `lssd_commendations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_convocations` ----
DROP TABLE IF EXISTS `lssd_convocations`;
CREATE TABLE `lssd_convocations` (
  `id` int(11) NOT NULL,
  `numero` varchar(30) NOT NULL,
  `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id — Doit exister',
  `motif` enum('audition','garde_a_vue','comparution','entretien','notification','autre') NOT NULL DEFAULT 'audition',
  `objet` varchar(300) NOT NULL,
  `date_convocation` datetime NOT NULL,
  `lieu` varchar(200) DEFAULT 'Commissariat LSPD — Mission Row',
  `agent_convoquant` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `statut` enum('envoyee','presentee','absente','annulee','reportee') DEFAULT 'envoyee',
  `date_presentation` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_convocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero` (`numero`);
ALTER TABLE `lssd_convocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_depositions` ----
DROP TABLE IF EXISTS `lssd_depositions`;
CREATE TABLE `lssd_depositions` (
  `id` int(11) NOT NULL,
  `numero` varchar(30) NOT NULL,
  `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
  `type_deposition` enum('entretien','arrestation','audition','temoignage','confrontation','autre') NOT NULL DEFAULT 'entretien',
  `contexte` varchar(300) NOT NULL COMMENT 'Contexte / motif',
  `contenu_rich` text DEFAULT NULL COMMENT 'Contenu HTML',
  `rapport_id` int(11) DEFAULT NULL COMMENT 'FK → reports.id',
  `convocation_id` int(11) DEFAULT NULL COMMENT 'FK → lspd_convocations.id',
  `agent_id` int(11) DEFAULT NULL COMMENT 'FK → users.id — Agent réceptionnant',
  `temoin_id` int(11) DEFAULT NULL COMMENT 'FK → users.id — Agent témoin',
  `lieu` varchar(200) DEFAULT NULL,
  `date_deposition` datetime DEFAULT NULL,
  `miranda_lu` tinyint(1) DEFAULT 0,
  `heure_miranda` time DEFAULT NULL,
  `avocat_present` tinyint(1) DEFAULT 0,
  `signe_par_citoyen` tinyint(1) DEFAULT 0,
  `statut` enum('brouillon','finalisee','transmise','archivee') DEFAULT 'finalisee',
  `notes_internes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_depositions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero` (`numero`),
  ADD KEY `citoyen_id` (`citoyen_id`),
  ADD KEY `rapport_id` (`rapport_id`);
ALTER TABLE `lssd_depositions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_division_members` ----
DROP TABLE IF EXISTS `lssd_division_members`;
CREATE TABLE `lssd_division_members` (
  `id` int(11) NOT NULL,
  `division_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_date` date NOT NULL,
  `role` varchar(100) DEFAULT NULL COMMENT 'Rôle dans la division',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_division_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_division_user` (`division_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);
ALTER TABLE `lssd_division_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_divisions` ----
DROP TABLE IF EXISTS `lssd_divisions`;
CREATE TABLE `lssd_divisions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Nom de la division',
  `abbreviation` varchar(10) NOT NULL COMMENT 'Abréviation (ex: SWAT, K9)',
  `description` text DEFAULT NULL COMMENT 'Description de la division',
  `min_rank` varchar(100) DEFAULT NULL COMMENT 'Grade minimum requis',
  `color` varchar(7) DEFAULT '#3b82f6' COMMENT 'Couleur hex',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_divisions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_name` (`name`),
  ADD UNIQUE KEY `uk_abbreviation` (`abbreviation`);
ALTER TABLE `lssd_divisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_equipment` ----
DROP TABLE IF EXISTS `lssd_equipment`;
CREATE TABLE `lssd_equipment` (
  `id` int(11) NOT NULL,
  `officer_id` int(11) NOT NULL,
  `equipment_type` enum('glock','taser','baton','flashlight','radio','bodycam','vest','badge','handcuffs','pepper_spray') NOT NULL,
  `serial_number` varchar(50) DEFAULT NULL COMMENT 'Numéro de série',
  `date_assigned` date NOT NULL,
  `date_returned` date DEFAULT NULL COMMENT 'Date de retour',
  `condition_status` enum('excellent','good','fair','poor','damaged') DEFAULT 'excellent',
  `notes` text DEFAULT NULL,
  `status` enum('assigned','returned','lost','damaged') DEFAULT 'assigned',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `images` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_officer` (`officer_id`),
  ADD KEY `idx_status` (`status`);
ALTER TABLE `lssd_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ia_dossiers` ----
DROP TABLE IF EXISTS `lssd_ia_dossiers`;
CREATE TABLE `lssd_ia_dossiers` (
  `id` int(11) NOT NULL,
  `numero` varchar(30) DEFAULT NULL,
  `officer_id` int(11) NOT NULL,
  `type` enum('misconduct','excessive_force','corruption','insubordination','negligence','other') NOT NULL DEFAULT 'misconduct',
  `statut` enum('ouvert','enquete','audience','clos_exonere','clos_sanctionne','clos_archive') NOT NULL DEFAULT 'ouvert',
  `priorite` enum('faible','normale','haute','critique') DEFAULT 'normale',
  `titre` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `plaignant_nom` varchar(200) DEFAULT NULL,
  `plaignant_contact` varchar(200) DEFAULT NULL,
  `opened_by` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `evidence_urls` longtext DEFAULT NULL,
  `conclusion` text DEFAULT NULL,
  `date_ouverture` date NOT NULL,
  `date_cloture` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ia_dossiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_officer` (`officer_id`),
  ADD KEY `idx_statut` (`statut`);
ALTER TABLE `lssd_ia_dossiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ia_events` ----
DROP TABLE IF EXISTS `lssd_ia_events`;
CREATE TABLE `lssd_ia_events` (
  `id` int(11) NOT NULL,
  `dossier_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `type` enum('note','convocation','audition','preuve','decision','cloture') DEFAULT 'note',
  `content` text NOT NULL,
  `is_private` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ia_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dossier` (`dossier_id`);
ALTER TABLE `lssd_ia_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ia_notes` ----
DROP TABLE IF EXISTS `lssd_ia_notes`;
CREATE TABLE `lssd_ia_notes` (
  `id` int(11) NOT NULL,
  `officer_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `type` enum('commendation','note','warning','investigation') NOT NULL DEFAULT 'note',
  `content` text NOT NULL,
  `is_private` tinyint(1) DEFAULT 0 COMMENT 'Visible que superviseurs+',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ia_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_officer` (`officer_id`);
ALTER TABLE `lssd_ia_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_map_presence` ----
DROP TABLE IF EXISTS `lssd_map_presence`;
CREATE TABLE `lssd_map_presence` (
  `map_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `display_name` varchar(191) NOT NULL,
  `lat` double NOT NULL,
  `lng` double NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_map_presence`
  ADD PRIMARY KEY (`map_id`,`user_id`);

-- ---- `lssd_maps` ----
DROP TABLE IF EXISTS `lssd_maps`;
CREATE TABLE `lssd_maps` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `nature` varchar(64) NOT NULL COMMENT 'operation, briefing, surveillance, autre',
  `map_layer` enum('atlas','satellite','road') NOT NULL DEFAULT 'atlas',
  `strokes_json` longtext NOT NULL COMMENT 'JSON: tableau de tracés {id,tool,color,width,latlngs}',
  `viewport_json` varchar(1024) DEFAULT NULL COMMENT 'JSON: {center:[lat,lng], zoom}',
  `author_id` int(10) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Incrémenté à chaque mise à jour des tracés',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_maps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lspd_maps_created` (`created_at`),
  ADD KEY `idx_lspd_maps_author` (`author_id`);
ALTER TABLE `lssd_maps`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_objectives` ----
DROP TABLE IF EXISTS `lssd_objectives`;
CREATE TABLE `lssd_objectives` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('patrol','investigation','operation','training') DEFAULT 'patrol',
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `priority` enum('low','normal','high','critical') DEFAULT 'normal',
  `assigned_to` longtext DEFAULT NULL COMMENT 'JSON array of user IDs',
  `due_date` date DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_objectives`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_objectives`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_payments` ----
DROP TABLE IF EXISTS `lssd_payments`;
CREATE TABLE `lssd_payments` (
  `id` int(11) NOT NULL,
  `officer_id` int(11) NOT NULL,
  `payer_id` int(11) DEFAULT NULL,
  `minutes` int(11) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_officer` (`officer_id`);
ALTER TABLE `lssd_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_plaintes` ----
DROP TABLE IF EXISTS `lssd_plaintes`;
CREATE TABLE `lssd_plaintes` (
  `id` int(11) NOT NULL,
  `numero` varchar(30) NOT NULL,
  `plaignant_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
  `mis_en_cause_id` int(11) DEFAULT NULL COMMENT 'FK → profiles.id',
  `categorie` enum('agression','vol','harcelement','escroquerie','degradation','menace','discrimination','autre') NOT NULL DEFAULT 'autre',
  `objet` varchar(300) NOT NULL,
  `description_rich` text DEFAULT NULL,
  `lieu_faits` varchar(200) DEFAULT NULL,
  `date_faits` datetime DEFAULT NULL,
  `statut` enum('deposee','en_enquete','classee_sans_suite','transmise_doj','resolue') DEFAULT 'deposee',
  `priorite` enum('faible','normale','haute','urgente') DEFAULT 'normale',
  `agent_referent` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `notes_internes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_plaintes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero` (`numero`),
  ADD KEY `plaignant_id` (`plaignant_id`),
  ADD KEY `statut` (`statut`);
ALTER TABLE `lssd_plaintes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ppa_armes` ----
DROP TABLE IF EXISTS `lssd_ppa_armes`;
CREATE TABLE `lssd_ppa_armes` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(150) NOT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `etat` varchar(100) DEFAULT 'Bon état',
  `stock_munitions` int(11) DEFAULT 0,
  `statut` enum('libre','en_attente','en_service') NOT NULL DEFAULT 'libre',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ppa_armes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `statut` (`statut`);
ALTER TABLE `lssd_ppa_armes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ppa_categories` ----
DROP TABLE IF EXISTS `lssd_ppa_categories`;
CREATE TABLE `lssd_ppa_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `nom` varchar(100) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ppa_categories`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_ppa_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ppa_demandes` ----
DROP TABLE IF EXISTS `lssd_ppa_demandes`;
CREATE TABLE `lssd_ppa_demandes` (
  `id` int(10) UNSIGNED NOT NULL,
  `citizen_id` int(10) UNSIGNED NOT NULL,
  `officer_id` int(10) UNSIGNED DEFAULT NULL,
  `statut` enum('demande','en_cours','valide','refuse') NOT NULL DEFAULT 'demande',
  `type_arme` varchar(100) DEFAULT NULL,
  `motif_demande` text DEFAULT NULL,
  `motif_refus` text DEFAULT NULL,
  `date_demande` date NOT NULL,
  `date_traitement` date DEFAULT NULL,
  `notes_officier` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_ppa_demandes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_citizen` (`citizen_id`),
  ADD KEY `idx_statut` (`statut`);
ALTER TABLE `lssd_ppa_demandes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ppa_retours` ----
DROP TABLE IF EXISTS `lssd_ppa_retours`;
CREATE TABLE `lssd_ppa_retours` (
  `id` int(10) UNSIGNED NOT NULL,
  `sortie_id` int(10) UNSIGNED NOT NULL,
  `arme_id` int(10) UNSIGNED NOT NULL,
  `etat_retour` varchar(100) DEFAULT 'Bon état',
  `munitions_retour` int(11) DEFAULT 0,
  `lieu` varchar(200) DEFAULT NULL,
  `recupere_par` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ppa_retours`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_ppa_retours`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ppa_sorties` ----
DROP TABLE IF EXISTS `lssd_ppa_sorties`;
CREATE TABLE `lssd_ppa_sorties` (
  `id` int(10) UNSIGNED NOT NULL,
  `arme_id` int(10) UNSIGNED NOT NULL,
  `agent_id` int(11) NOT NULL,
  `periode_debut` datetime NOT NULL,
  `periode_fin` datetime DEFAULT NULL,
  `raison` text DEFAULT NULL,
  `statut` enum('en_attente','valide','refuse','rendu') NOT NULL DEFAULT 'en_attente',
  `valide_par` int(11) DEFAULT NULL,
  `valide_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ppa_sorties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `arme_id` (`arme_id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `statut` (`statut`);
ALTER TABLE `lssd_ppa_sorties`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- `lssd_ranks` ----
DROP TABLE IF EXISTS `lssd_ranks`;
CREATE TABLE `lssd_ranks` (
  `id` int(11) NOT NULL,
  `rank_name` varchar(100) NOT NULL,
  `hierarchy_level` int(11) NOT NULL COMMENT 'Niveau hiérarchique (1=plus haut)',
  `base_salary` decimal(10,2) DEFAULT 0.00 COMMENT 'Salaire de base',
  `can_recruit` tinyint(1) DEFAULT 0 COMMENT 'Peut recruter',
  `can_manage_divisions` tinyint(1) DEFAULT 0,
  `can_give_sanctions` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `salary_hourly` decimal(8,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_ranks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rank_name` (`rank_name`);
ALTER TABLE `lssd_ranks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_rapports_rookie` ----
DROP TABLE IF EXISTS `lssd_rapports_rookie`;
CREATE TABLE `lssd_rapports_rookie` (
  `id` int(11) NOT NULL,
  `numero` varchar(30) NOT NULL,
  `agent_referent_id` int(11) NOT NULL COMMENT 'FK → users.id — Agent qui évalue',
  `referent_patrouille_id` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `rookie_id` int(11) NOT NULL COMMENT 'FK → users.id — Le rookie évalué',
  `date_patrouille` date NOT NULL,
  `gestion_radio` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
  `connaissance_procedure` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
  `gestion_conduite` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
  `communication_civils` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
  `observation` text NOT NULL COMMENT 'Min 50 caractères',
  `conclusion` text DEFAULT NULL,
  `recommandation` enum('continuer','attente','retirer','promouvoir') DEFAULT 'continuer',
  `note_globale` tinyint(1) DEFAULT NULL COMMENT '1-5',
  `statut` enum('brouillon','soumis','valide','archive') DEFAULT 'soumis',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custom_answers` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_rapports_rookie`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_rapports_rookie`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_report_offenses` ----
DROP TABLE IF EXISTS `lssd_report_offenses`;
CREATE TABLE `lssd_report_offenses` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `penal_code_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_report_offenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_report_penal` (`report_id`,`penal_code_id`),
  ADD KEY `idx_report` (`report_id`),
  ADD KEY `idx_penal` (`penal_code_id`);
ALTER TABLE `lssd_report_offenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_report_suspects` ----
DROP TABLE IF EXISTS `lssd_report_suspects`;
CREATE TABLE `lssd_report_suspects` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_report_suspects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_report_suspect` (`report_id`,`profile_id`),
  ADD KEY `profile_id` (`profile_id`);
ALTER TABLE `lssd_report_suspects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_report_victims` ----
DROP TABLE IF EXISTS `lssd_report_victims`;
CREATE TABLE `lssd_report_victims` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_report_victims`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_report_victim` (`report_id`,`profile_id`),
  ADD KEY `profile_id` (`profile_id`);
ALTER TABLE `lssd_report_victims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_report_witnesses` ----
DROP TABLE IF EXISTS `lssd_report_witnesses`;
CREATE TABLE `lssd_report_witnesses` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_report_witnesses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_report_witness` (`report_id`,`profile_id`),
  ADD KEY `profile_id` (`profile_id`);
ALTER TABLE `lssd_report_witnesses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_reports` ----
DROP TABLE IF EXISTS `lssd_reports`;
CREATE TABLE `lssd_reports` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `incident_type` varchar(100) NOT NULL,
  `location` varchar(255) NOT NULL,
  `incident_date` datetime NOT NULL,
  `narrative` text NOT NULL,
  `evidence` text DEFAULT NULL,
  `officer_in_charge` int(11) NOT NULL,
  `status` enum('draft','pending','approved','closed') DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `priority` enum('low','normal','high','critical') DEFAULT 'normal',
  `tags` longtext DEFAULT NULL,
  `suspects` longtext DEFAULT NULL,
  `victims` longtext DEFAULT NULL,
  `witnesses` longtext DEFAULT NULL,
  `officers` longtext DEFAULT NULL,
  `vehicles_involved` longtext DEFAULT NULL,
  `evidence_urls` longtext DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date` (`incident_date`),
  ADD KEY `idx_officer` (`officer_in_charge`);
ALTER TABLE `lssd_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_rookie_form_questions` ----
DROP TABLE IF EXISTS `lssd_rookie_form_questions`;
CREATE TABLE `lssd_rookie_form_questions` (
  `id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `qtype` enum('texte','boutons') NOT NULL DEFAULT 'texte',
  `options` text DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_rookie_form_questions`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_rookie_form_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_saisies` ----
DROP TABLE IF EXISTS `lssd_saisies`;
CREATE TABLE `lssd_saisies` (
  `id` int(11) NOT NULL,
  `criminal_record_id` int(11) DEFAULT NULL COMMENT 'FK → criminal_records.id',
  `report_id` int(11) DEFAULT NULL COMMENT 'FK → reports.id',
  `citizen_id` int(11) DEFAULT NULL,
  `categorie` enum('arme','drogue','vehicule','argent','bijou','electronique','document','autre') NOT NULL DEFAULT 'autre',
  `description` varchar(300) NOT NULL,
  `quantite` varchar(100) DEFAULT NULL,
  `valeur_estimee` decimal(10,2) DEFAULT NULL,
  `lieu_saisie` varchar(200) DEFAULT NULL,
  `numero_evidence` varchar(50) DEFAULT NULL,
  `statut` enum('saisie','detruit','restitue','en_evidence') DEFAULT 'saisie',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `images` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_saisies`
  ADD PRIMARY KEY (`id`);
ALTER TABLE `lssd_saisies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_sanctions` ----
DROP TABLE IF EXISTS `lssd_sanctions`;
CREATE TABLE `lssd_sanctions` (
  `id` int(11) NOT NULL,
  `officer_id` int(11) NOT NULL COMMENT 'Agent sanctionné',
  `issued_by` int(11) NOT NULL COMMENT 'Émetteur de la sanction',
  `sanction_type` enum('verbal_warning','written_warning','suspension','demotion','termination') NOT NULL,
  `reason` text NOT NULL,
  `details` text DEFAULT NULL COMMENT 'Détails supplémentaires',
  `duration_days` int(11) DEFAULT NULL COMMENT 'Durée en jours (pour suspension)',
  `date_issued` date NOT NULL,
  `date_expires` date DEFAULT NULL COMMENT 'Date d''expiration',
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_sanctions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issued_by` (`issued_by`),
  ADD KEY `idx_officer` (`officer_id`),
  ADD KEY `idx_status` (`status`);
ALTER TABLE `lssd_sanctions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_service_partners` ----
DROP TABLE IF EXISTS `lssd_service_partners`;
CREATE TABLE `lssd_service_partners` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  `removed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_service_partners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_active` (`shift_id`,`removed_at`),
  ADD KEY `idx_partner_active` (`partner_user_id`,`removed_at`);
ALTER TABLE `lssd_service_partners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ---- `lssd_service_totals` ----
DROP TABLE IF EXISTS `lssd_service_totals`;
CREATE TABLE `lssd_service_totals` (
  `user_id` int(11) NOT NULL,
  `season_start` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `lssd_service_totals`
  ADD PRIMARY KEY (`user_id`);

-- ---- `lssd_report_tags` ----
DROP TABLE IF EXISTS `lssd_report_tags`;
CREATE TABLE `lssd_report_tags` (
  `id` int(10) UNSIGNED NOT NULL,
  `label` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#94a3b8',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `lssd_report_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_label` (`label`);
ALTER TABLE `lssd_report_tags`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ---- admin_panel_config : colonnes de thème LSSD ----
ALTER TABLE `admin_panel_config`
  ADD COLUMN IF NOT EXISTS `lssd_theme` varchar(20) DEFAULT 'vert',
  ADD COLUMN IF NOT EXISTS `lssd_theme_custom` text DEFAULT NULL;

INSERT INTO `admin_panel_config` (`panel_key`,`custom_name`,`custom_subtitle`,`custom_color`,`lssd_theme`)
  VALUES ('LSSD','LSSD','Los Santos Sheriff\'s Department','#10b981','vert')
  ON DUPLICATE KEY UPDATE `lssd_theme`=IFNULL(`lssd_theme`,'vert');

-- ---- Tags de rapport LSSD par défaut ----
INSERT INTO `lssd_report_tags` (`label`,`color`,`sort_order`) VALUES
  ('LSSD','#10b981',1),('Urgent','#ef4444',2),('Remis DOJ','#8b5cf6',3),
  ('Attente DOJ','#f97316',4),('Validé','#22c55e',5),('Payé','#10b981',6),
  ('Confidentiel','#a855f7',7),('Code Rouge','#dc2626',8),('Gang','#7c3aed',9);


-- ============================================================
-- Clés étrangères (ajoutées en dernier : toutes les tables existent)
-- Noms préfixés fk_lssd_* pour ne pas entrer en collision avec le LSPD.
-- ============================================================
ALTER TABLE `lssd_dispatch_card_global_checks`
  ADD CONSTRAINT `fk_lssd_dcgc_card` FOREIGN KEY (`card_id`) REFERENCES `lssd_dispatch_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lssd_dcgc_item` FOREIGN KEY (`item_id`) REFERENCES `lssd_dispatch_global_checklist_items` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_card_labels`
  ADD CONSTRAINT `lssd_dispatch_card_labels_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `lssd_dispatch_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lssd_dispatch_card_labels_ibfk_2` FOREIGN KEY (`label_id`) REFERENCES `lssd_dispatch_labels` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_cards`
  ADD CONSTRAINT `lssd_dispatch_cards_ibfk_1` FOREIGN KEY (`list_id`) REFERENCES `lssd_dispatch_lists` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_checklist_items`
  ADD CONSTRAINT `lssd_dispatch_checklist_items_ibfk_1` FOREIGN KEY (`checklist_id`) REFERENCES `lssd_dispatch_checklists` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_checklists`
  ADD CONSTRAINT `lssd_dispatch_checklists_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `lssd_dispatch_cards` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_comments`
  ADD CONSTRAINT `lssd_dispatch_comments_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `lssd_dispatch_cards` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_global_checklist_items`
  ADD CONSTRAINT `fk_lssd_dgci_cl` FOREIGN KEY (`checklist_id`) REFERENCES `lssd_dispatch_global_checklists` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_dispatch_vehicle_plates`
  ADD CONSTRAINT `fk_lssd_dvp_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `lssd_dispatch_patrol_vehicles` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_map_presence`
  ADD CONSTRAINT `fk_lssd_map_presence_map` FOREIGN KEY (`map_id`) REFERENCES `lssd_maps` (`id`) ON DELETE CASCADE;
ALTER TABLE `lssd_service_partners`
  ADD CONSTRAINT `fk_lssd_sp_shift` FOREIGN KEY (`shift_id`) REFERENCES `company_shifts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lssd_sp_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
