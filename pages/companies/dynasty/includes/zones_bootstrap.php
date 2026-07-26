<?php
/**
 * pages/companies/dynasty/includes/zones_bootstrap.php
 * ─────────────────────────────────────────────────────────────────────────
 * Auto-migration du module « Zones & Tarification » du panel Dynasty.
 *
 * Inclus par zone.php, l'API des zones et le site public. Suit la convention
 * du MDT (cf. pages/maps/includes/map_bootstrap.php) : CREATE TABLE IF NOT
 * EXISTS dans un try/catch, garde statique, dégradation gracieuse — une base
 * indisponible ne doit jamais casser une page.
 *
 * Tables créées :
 *   dynasty_zone_categories  — catégories de quartier + prix de base
 *   dynasty_zones            — tracés (polygones normalisés 0..1)
 *   dynasty_tarif_options    — options (Maison, Meublé, …)
 *   dynasty_tarif_garages    — paliers de capacité garage
 *   dynasty_tarif_config     — réglages globaux (clé/valeur)
 *
 * Migration douce : ajout de dynasty_biens.zone_id (nullable) + rapprochement
 * automatique par nom de quartier. La colonne texte `quartier` reste intacte.
 */

declare(strict_types=1);

if (!function_exists('dynasty_zones_bootstrap')) {

    /**
     * Crée les tables du module Zones & Tarification + seeds initiaux.
     * Exécuté une seule fois par requête (garde statique).
     */
    function dynasty_zones_bootstrap(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            // ── Catégories de quartier (configurables depuis zone.php) ──
            $db->exec("CREATE TABLE IF NOT EXISTS dynasty_zone_categories (
                id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cle                 VARCHAR(50) NOT NULL,
                label               VARCHAR(80) NOT NULL,
                couleur             VARCHAR(20) NOT NULL DEFAULT '#94a3b8',
                prix_base_location  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                prix_base_achat     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                ordre               INT NOT NULL DEFAULT 0,
                actif               TINYINT(1) NOT NULL DEFAULT 1,
                created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cle (cle),
                KEY idx_actif_ordre (actif, ordre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Tracés des zones (points normalisés 0..1, indépendants de la résolution) ──
            $db->exec("CREATE TABLE IF NOT EXISTS dynasty_zones (
                id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nom                VARCHAR(150) NOT NULL DEFAULT 'Zone',
                categorie_id       INT UNSIGNED NULL,
                points             LONGTEXT NOT NULL,
                background         VARCHAR(20) NOT NULL DEFAULT 'atlas',
                couleur_override   VARCHAR(20) NULL DEFAULT NULL,
                meta               LONGTEXT NULL DEFAULT NULL,
                revision           INT UNSIGNED NOT NULL DEFAULT 0,
                created_by         INT UNSIGNED NULL DEFAULT NULL,
                updated_by         INT UNSIGNED NULL DEFAULT NULL,
                created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_categorie (categorie_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Options tarifaires (Maison, Meublé, et les suivantes) ──
            $db->exec("CREATE TABLE IF NOT EXISTS dynasty_tarif_options (
                id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cle              VARCHAR(50) NOT NULL,
                label            VARCHAR(80) NOT NULL,
                type             ENUM('fixe','multiplicateur') NOT NULL DEFAULT 'fixe',
                valeur_location  DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                valeur_achat     DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                ordre            INT NOT NULL DEFAULT 0,
                actif            TINYINT(1) NOT NULL DEFAULT 1,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cle (cle),
                KEY idx_actif_ordre (actif, ordre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Paliers de capacité garage ──
            $db->exec("CREATE TABLE IF NOT EXISTS dynasty_tarif_garages (
                id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                places                INT NOT NULL,
                supplement_location   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                supplement_achat      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                icone                 VARCHAR(80) NULL DEFAULT NULL,
                ordre                 INT NOT NULL DEFAULT 0,
                actif                 TINYINT(1) NOT NULL DEFAULT 1,
                created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_places (places),
                KEY idx_actif_ordre (actif, ordre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Réglages globaux (clé/valeur) ──
            $db->exec("CREATE TABLE IF NOT EXISTS dynasty_tarif_config (
                cle         VARCHAR(60) NOT NULL PRIMARY KEY,
                valeur      VARCHAR(255) NOT NULL DEFAULT '',
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            dynasty_zones_seed($db);
            dynasty_zones_migrate_biens($db);
            dynasty_biens_migrate_images($db);
            dynasty_biens_migrate_categories($db);
        } catch (Throwable $e) {
            // Dégradation gracieuse : ne jamais casser une page si la base est indisponible.
            error_log('[Dynasty Zones] bootstrap: ' . $e->getMessage());
        }
    }

    /**
     * Seeds initiaux — chaque table n'est semée que si elle est vide, afin de
     * ne jamais écraser une grille tarifaire déjà ajustée par la faction.
     */
    function dynasty_zones_seed(PDO $db): void
    {
        // ── Catégories de quartier ──
        if ((int) $db->query("SELECT COUNT(*) FROM dynasty_zone_categories")->fetchColumn() === 0) {
            $seed = [
                // cle,          label,               couleur,   location, achat,    ordre
                ['pauvre',      'Quartier Pauvre',     '#22C55E',  1000.00,   25000.00, 1],
                ['modere',      'Quartier Modéré',     '#3B82F6',  2000.00,   50000.00, 2],
                ['riche',       'Quartier Riche',      '#EAB308',  4000.00,  100000.00, 3],
                ['ultra_riche', 'Quartier Ultra Riche','#F97316',  8000.00,  200000.00, 4],
            ];
            $ins = $db->prepare("INSERT INTO dynasty_zone_categories
                (cle, label, couleur, prix_base_location, prix_base_achat, ordre, actif)
                VALUES (?, ?, ?, ?, ?, ?, 1)");
            foreach ($seed as $c) {
                $ins->execute($c);
            }
        }

        // ── Options tarifaires ──
        // Valeurs volontairement neutres (0) : la grille réelle se règle dans
        // l'onglet Tarification, aucun prix n'est inventé ici.
        if ((int) $db->query("SELECT COUNT(*) FROM dynasty_tarif_options")->fetchColumn() === 0) {
            $seed = [
                ['maison', 'Maison', 'fixe', 0.0000, 0.0000, 1],
                ['meuble', 'Meublé', 'fixe', 0.0000, 0.0000, 2],
            ];
            $ins = $db->prepare("INSERT INTO dynasty_tarif_options
                (cle, label, type, valeur_location, valeur_achat, ordre, actif)
                VALUES (?, ?, ?, ?, ?, ?, 1)");
            foreach ($seed as $o) {
                $ins->execute($o);
            }
        }

        // ── Paliers de garage (valeurs de la maquette : 0 / 200 / 400 / 800 / 1200 / 1600) ──
        if ((int) $db->query("SELECT COUNT(*) FROM dynasty_tarif_garages")->fetchColumn() === 0) {
            $seed = [
                [2,     0.00,     0.00, 1],
                [4,   200.00,  2000.00, 2],
                [6,   400.00,  4000.00, 3],
                [12,  800.00,  8000.00, 4],
                [16, 1200.00, 12000.00, 5],
                [24, 1600.00, 16000.00, 6],
            ];
            $ins = $db->prepare("INSERT INTO dynasty_tarif_garages
                (places, supplement_location, supplement_achat, ordre, actif)
                VALUES (?, ?, ?, ?, 1)");
            foreach ($seed as $g) {
                $ins->execute($g);
            }
        }

        // ── Réglages globaux (INSERT IGNORE : n'écrase jamais une valeur existante) ──
        $ins = $db->prepare("INSERT IGNORE INTO dynasty_tarif_config (cle, valeur) VALUES (?, ?)");
        foreach ([
            'taxe'                 => '0.30',    // 30 %
            'devise'               => '$',
            'multiplicateur_achat' => '1.0000',  // neutre — à ajuster dans l'interface
            'background'           => 'atlas',
            'zones_revision'       => '0',       // compteur global (contrat du moteur de carte)
        ] as $k => $v) {
            $ins->execute([$k, $v]);
        }
    }

    /* ══════════════════════════ CSRF ══════════════════════════
       Même motif que mdt_map_csrf() / mdt_map_verify_csrf()
       (pages/maps/includes/map_access.php). */

    function dynasty_zones_csrf(): string
    {
        if (empty($_SESSION['dynasty_zones_csrf']) || !is_string($_SESSION['dynasty_zones_csrf'])) {
            $_SESSION['dynasty_zones_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['dynasty_zones_csrf'];
    }

    function dynasty_zones_verify_csrf(?string $token): bool
    {
        $s = $_SESSION['dynasty_zones_csrf'] ?? '';
        return is_string($token) && $s !== '' && hash_equals($s, $token);
    }

    /* ══════════════════════════ Lecture des zones ══════════════════════════ */

    /**
     * Charge toutes les zones au format attendu par le moteur de carte :
     * {id, type:'zone', points, title, color, meta:{categorie_id}}.
     *
     * La couleur affichée est celle de la catégorie, sauf si la zone porte
     * une couleur_override explicite.
     *
     * @param array $categories Retour de dynasty_pricing_load()['categories']
     */
    function dynasty_zones_load(PDO $db, array $categories = []): array
    {
        $out = [];
        try {
            $rows = $db->query("SELECT id, nom, categorie_id, points, background, couleur_override, revision
                                  FROM dynasty_zones ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $pts = json_decode((string) $r['points'], true);
                if (!is_array($pts) || count($pts) < 3) {
                    continue; // tracé corrompu : ignoré plutôt que de casser le rendu
                }
                $catId = $r['categorie_id'] !== null ? (int) $r['categorie_id'] : 0;
                $cat   = $categories[$catId] ?? null;
                $col   = $r['couleur_override'] ?: ($cat['couleur'] ?? '#94a3b8');
                $out[] = [
                    'id'     => (int) $r['id'],
                    'type'   => 'zone',
                    'points' => $pts,
                    'title'  => (string) $r['nom'],
                    'color'  => $col,
                    'meta'   => [
                        'categorie_id'    => $catId,
                        'categorie_label' => $cat['label'] ?? null,
                        'categorie_cle'   => $cat['cle'] ?? null,
                    ],
                ];
            }
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] load: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Compteur de révision global, stocké dans dynasty_tarif_config.
     * Sert le contrat du moteur de carte, qui attend un entier croissant
     * dans {ok:true, data:{revision}} après chaque écriture.
     */
    function dynasty_zones_bump_revision(PDO $db): int
    {
        try {
            $db->prepare("INSERT INTO dynasty_tarif_config (cle, valeur) VALUES ('zones_revision', '1')
                          ON DUPLICATE KEY UPDATE valeur = CAST(CAST(valeur AS UNSIGNED) + 1 AS CHAR)")
               ->execute();
            $st = $db->query("SELECT valeur FROM dynasty_tarif_config WHERE cle = 'zones_revision'");
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] bump_revision: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Migration douce de dynasty_biens : ajout d'une colonne zone_id optionnelle.
     *
     * La colonne texte `quartier` n'est ni supprimée ni modifiée : elle reste la
     * source de vérité éditoriale, zone_id n'est qu'un lien facultatif utilisé
     * par les filtres du catalogue public.
     */
    function dynasty_zones_migrate_biens(PDO $db): void
    {
        try {
            $has = dynasty_zones_biens_has_zone_id($db);
            if ($has) {
                return;
            }

            // La table dynasty_biens peut ne pas exister (panel jamais utilisé) :
            // l'ALTER échoue alors silencieusement, ce qui est le comportement voulu.
            $db->exec("ALTER TABLE dynasty_biens
                         ADD COLUMN zone_id INT UNSIGNED NULL DEFAULT NULL AFTER quartier,
                         ADD KEY idx_zone (zone_id)");

            // La colonne vient d'apparaître : rafraîchir le cache, sinon le
            // rapprochement ci-dessous le verrait encore absent (faux négatif).
            dynasty_zones_biens_has_zone_id($db, true);

            // Premier rapprochement, une seule fois à la création de la colonne.
            dynasty_zones_match_biens($db);
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] migrate_biens: ' . $e->getMessage());
        }
    }

    /**
     * La colonne dynasty_biens.zone_id existe-t-elle ? (cache par requête)
     *
     * @param bool $refresh Force une nouvelle interrogation — indispensable
     *                      juste après l'ALTER qui crée la colonne, sinon le
     *                      cache renverrait l'ancienne valeur (faux négatif).
     */
    function dynasty_zones_biens_has_zone_id(PDO $db, bool $refresh = false): bool
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        try {
            $cache = (bool) $db->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'dynasty_biens'
                  AND COLUMN_NAME  = 'zone_id'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }

    /**
     * Ajout de dynasty_biens.images (JSON, liste d'URL ou de chemins locaux).
     * Colonne facultative : en son absence, les biens n'affichent qu'une icône.
     */
    function dynasty_biens_migrate_images(PDO $db): void
    {
        try {
            if (dynasty_biens_has_images($db)) {
                return;
            }
            $db->exec("ALTER TABLE dynasty_biens
                         ADD COLUMN images LONGTEXT NULL DEFAULT NULL AFTER equipements");
            dynasty_biens_has_images($db, true); // rafraîchit le cache après l'ALTER
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] migrate_images: ' . $e->getMessage());
        }
    }

    /**
     * Ajout de dynasty_biens.categorie_ids (JSON, liste d'ID de catégories/quartiers).
     * Permet d'associer un bien à un ou plusieurs quartiers, affichés en tags
     * sur le catalogue public. Rétro-rempli depuis le zone_id existant.
     */
    function dynasty_biens_migrate_categories(PDO $db): void
    {
        try {
            if (dynasty_biens_has_categories($db)) {
                return;
            }
            $db->exec("ALTER TABLE dynasty_biens
                         ADD COLUMN categorie_ids LONGTEXT NULL DEFAULT NULL AFTER zone_id");
            dynasty_biens_has_categories($db, true);

            // Backfill : la catégorie de la zone rattachée devient le 1er quartier.
            if (dynasty_zones_biens_has_zone_id($db)) {
                $db->exec("UPDATE dynasty_biens b
                             INNER JOIN dynasty_zones z ON z.id = b.zone_id
                           SET b.categorie_ids = CONCAT('[', z.categorie_id, ']')
                           WHERE b.zone_id IS NOT NULL
                             AND z.categorie_id IS NOT NULL
                             AND (b.categorie_ids IS NULL OR b.categorie_ids = '')");
            }
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] migrate_categories: ' . $e->getMessage());
        }
    }

    /** La colonne dynasty_biens.categorie_ids existe-t-elle ? (cache par requête) */
    function dynasty_biens_has_categories(PDO $db, bool $refresh = false): bool
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        try {
            $cache = (bool) $db->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'dynasty_biens'
                  AND COLUMN_NAME  = 'categorie_ids'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }

    /**
     * Normalise une liste d'ID de catégories (POST ou base) : entiers positifs
     * uniques, filtrés sur les catégories réellement existantes.
     *
     * @param mixed $raw       Tableau, chaîne JSON, ou null.
     * @param int[] $validIds  Liste des ID de catégories valides.
     * @return int[]
     */
    function dynasty_biens_clean_category_ids($raw, array $validIds): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $valid = array_flip(array_map('intval', $validIds));
        $out = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0 && isset($valid[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /** La colonne dynasty_biens.images existe-t-elle ? (cache par requête) */
    function dynasty_biens_has_images(PDO $db, bool $refresh = false): bool
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        try {
            $cache = (bool) $db->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'dynasty_biens'
                  AND COLUMN_NAME  = 'images'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }

    /**
     * Normalise et borne une liste d'images (venue d'un POST ou de la base).
     * N'accepte que des URL http(s) ou des chemins internes /uploads/dynasty/…,
     * pour qu'aucune valeur arbitraire ne finisse dans un attribut src.
     *
     * @param mixed $raw Tableau, chaîne JSON, ou null.
     * @return string[] Liste nettoyée (max 24), sans doublon, ordre préservé.
     */
    function dynasty_biens_clean_images($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $u) {
            if (!is_string($u)) {
                continue;
            }
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            // http(s) externe, ou chemin d'upload interne (jamais de ../, jamais de scheme exotique).
            $ok = preg_match('#^https?://#i', $u)
               || (strpos($u, '/uploads/dynasty/') === 0 && strpos($u, '..') === false);
            if ($ok && !in_array($u, $out, true)) {
                $out[] = mb_substr($u, 0, 800);
            }
            if (count($out) >= 24) {
                break;
            }
        }
        return $out;
    }

    /**
     * Résout une image stockée en URL affichable : les chemins internes sont
     * préfixés par SITE_URL, les URL externes renvoyées telles quelles.
     */
    function dynasty_biens_image_url(string $img): string
    {
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        return rtrim(SITE_URL, '/') . '/' . ltrim($img, '/');
    }

    /**
     * Rattache les biens encore orphelins à une zone dont le nom correspond
     * exactement au champ texte `quartier` (comparaison insensible à la casse).
     *
     * Appelé ponctuellement — à la création de la colonne, puis par l'API après
     * la création ou le renommage d'une zone — et jamais à chaque affichage de
     * page, pour ne pas payer la jointure sur les pages publiques.
     *
     * @return int Nombre de biens rattachés.
     */
    function dynasty_zones_match_biens(PDO $db): int
    {
        if (!dynasty_zones_biens_has_zone_id($db)) {
            return 0;
        }
        try {
            $st = $db->prepare("UPDATE dynasty_biens b
                                  INNER JOIN dynasty_zones z
                                     ON LOWER(TRIM(z.nom)) = LOWER(TRIM(b.quartier))
                                SET b.zone_id = z.id
                                WHERE b.zone_id IS NULL
                                  AND b.quartier IS NOT NULL
                                  AND TRIM(b.quartier) <> ''");
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('[Dynasty Zones] match_biens: ' . $e->getMessage());
            return 0;
        }
    }
}
