-- ═══════════════════════════════════════════════════════════════════════════
--  MDT V2 · Dynasty — module « Zones & Tarification »
--  Migration idempotente : peut être rejouée sans effet de bord.
--
--  Équivalent SQL de l'auto-migration
--  pages/companies/dynasty/includes/zones_bootstrap.php
--  (dynasty_zones_bootstrap). Fourni pour un déploiement manuel ou pour
--  auditer le schéma ; l'application crée ces tables toute seule au premier
--  accès à zone.php, à l'API des zones ou au site public.
--
--  Coordonnées des polygones : JSON [[nx,ny],…] normalisé 0..1, exactement
--  comme mdt_map_elements — indépendant de la résolution du fond de carte.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────
--  1. Catégories de quartier
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dynasty_zone_categories` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cle`                 VARCHAR(50)  NOT NULL,
  `label`               VARCHAR(80)  NOT NULL,
  `couleur`             VARCHAR(20)  NOT NULL DEFAULT '#94a3b8',
  `prix_base_location`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `prix_base_achat`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `ordre`               INT NOT NULL DEFAULT 0,
  `actif`               TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cle` (`cle`),
  KEY `idx_actif_ordre` (`actif`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
--  2. Tracés des zones
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dynasty_zones` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`               VARCHAR(150) NOT NULL DEFAULT 'Zone',
  `categorie_id`      INT UNSIGNED NULL DEFAULT NULL,
  `points`            LONGTEXT NOT NULL COMMENT 'JSON [[nx,ny],…] normalisé 0..1',
  `background`        VARCHAR(20) NOT NULL DEFAULT 'atlas',
  `couleur_override`  VARCHAR(20) NULL DEFAULT NULL COMMENT 'sinon couleur de la catégorie',
  `meta`              LONGTEXT NULL DEFAULT NULL COMMENT 'JSON libre',
  `revision`          INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`        INT UNSIGNED NULL DEFAULT NULL,
  `updated_by`        INT UNSIGNED NULL DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categorie` (`categorie_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
--  3. Options tarifaires (Maison, Meublé, …)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dynasty_tarif_options` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cle`              VARCHAR(50) NOT NULL,
  `label`            VARCHAR(80) NOT NULL,
  `type`             ENUM('fixe','multiplicateur') NOT NULL DEFAULT 'fixe',
  `valeur_location`  DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `valeur_achat`     DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `ordre`            INT NOT NULL DEFAULT 0,
  `actif`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cle` (`cle`),
  KEY `idx_actif_ordre` (`actif`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
--  4. Paliers de capacité garage
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dynasty_tarif_garages` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `places`               INT NOT NULL,
  `supplement_location`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `supplement_achat`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `icone`                VARCHAR(80) NULL DEFAULT NULL,
  `ordre`                INT NOT NULL DEFAULT 0,
  `actif`                TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_places` (`places`),
  KEY `idx_actif_ordre` (`actif`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
--  5. Réglages globaux (clé/valeur)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dynasty_tarif_config` (
  `cle`         VARCHAR(60) NOT NULL,
  `valeur`      VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══════════════════════════════════════════════════════════════════════════
--  SEEDS — INSERT IGNORE : ne réécrit jamais une grille déjà ajustée.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO `dynasty_zone_categories`
  (`cle`, `label`, `couleur`, `prix_base_location`, `prix_base_achat`, `ordre`, `actif`) VALUES
  ('pauvre',      'Quartier Pauvre',      '#22C55E', 1000.00,  25000.00, 1, 1),
  ('modere',      'Quartier Modéré',      '#3B82F6', 2000.00,  50000.00, 2, 1),
  ('riche',       'Quartier Riche',       '#EAB308', 4000.00, 100000.00, 3, 1),
  ('ultra_riche', 'Quartier Ultra Riche', '#F97316', 8000.00, 200000.00, 4, 1);

-- Options : valeurs volontairement neutres (0). La grille réelle se règle dans
-- l'onglet Tarification de zone.php — aucun prix n'est inventé ici.
INSERT IGNORE INTO `dynasty_tarif_options`
  (`cle`, `label`, `type`, `valeur_location`, `valeur_achat`, `ordre`, `actif`) VALUES
  ('maison', 'Maison', 'fixe', 0.0000, 0.0000, 1, 1),
  ('meuble', 'Meublé', 'fixe', 0.0000, 0.0000, 2, 1);

-- Paliers de garage : valeurs confirmées par la maquette.
INSERT IGNORE INTO `dynasty_tarif_garages`
  (`places`, `supplement_location`, `supplement_achat`, `ordre`, `actif`) VALUES
  ( 2,    0.00,     0.00, 1, 1),
  ( 4,  200.00,  2000.00, 2, 1),
  ( 6,  400.00,  4000.00, 3, 1),
  (12,  800.00,  8000.00, 4, 1),
  (16, 1200.00, 12000.00, 5, 1),
  (24, 1600.00, 16000.00, 6, 1);

INSERT IGNORE INTO `dynasty_tarif_config` (`cle`, `valeur`) VALUES
  ('taxe',                 '0.30'),    -- 30 %
  ('devise',               '$'),
  ('multiplicateur_achat', '1.0000'),  -- neutre — à ajuster dans l'interface
  ('background',           'atlas'),
  ('zones_revision',       '0');       -- compteur global (contrat du moteur de carte)


-- ═══════════════════════════════════════════════════════════════════════════
--  MIGRATION DOUCE DE dynasty_biens
--
--  Ajout d'un lien facultatif vers une zone. Le champ texte `quartier` reste
--  intact et éditable : zone_id ne sert qu'aux filtres du catalogue public.
--
--  MySQL/MariaDB n'acceptent pas « ADD COLUMN IF NOT EXISTS » partout : le
--  bloc ci-dessous teste INFORMATION_SCHEMA avant d'exécuter l'ALTER, ce qui
--  rend le script rejouable.
-- ═══════════════════════════════════════════════════════════════════════════

SET @has_zone_id := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'dynasty_biens'
    AND COLUMN_NAME  = 'zone_id'
);

SET @sql := IF(@has_zone_id = 0,
  'ALTER TABLE `dynasty_biens`
     ADD COLUMN `zone_id` INT UNSIGNED NULL DEFAULT NULL AFTER `quartier`,
     ADD KEY `idx_zone` (`zone_id`)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rapprochement automatique par nom de quartier : uniquement les biens
-- encore orphelins, jamais d'écrasement d'un rattachement manuel.
UPDATE `dynasty_biens` b
  INNER JOIN `dynasty_zones` z
     ON LOWER(TRIM(z.`nom`)) = LOWER(TRIM(b.`quartier`))
SET b.`zone_id` = z.`id`
WHERE b.`zone_id` IS NULL
  AND b.`quartier` IS NOT NULL
  AND TRIM(b.`quartier`) <> '';
