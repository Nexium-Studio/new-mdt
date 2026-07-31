<?php
/**
 * includes/ppa.php
 * ─────────────────────────────────────────────────────────────────────────
 * Logique partagée du workflow PPA Civil (Permis de Port d'Arme).
 *
 * Colonne vertébrale : la table `lspd_ppa_demandes` et sa colonne `statut`.
 *   demande   → pré-demande déposée par le civil, en attente d'évaluation SAMS
 *   en_cours  → aptitude validée par le SAMS, en attente de décision LSPD
 *   valide    → PPA accordé par le LSPD (décision finale + certificat généré)
 *   refuse    → refusé (par le SAMS ou par le LSPD)
 *
 * Réutilisé par :
 *   · pages/espace-perso.php                          (dépôt civil)
 *   · pages/sams/documents/aptitude-ppa.php           (évaluation)
 *   · pages/lspd/ppa.php + pages/lspd/profils.php      (décision + fiche)
 *   · pages/companies/ammunation/recencement-ppa.php   (registre PPA valides)
 */

declare(strict_types=1);

if (!function_exists('mdt_ppa_types_arme')) {

    /** Liste unique des types d'arme proposés à la pré-demande. */
    function mdt_ppa_types_arme(): array
    {
        return ['Pistolet', 'Revolver', 'Fusil à pompe', "Fusil d'assaut", 'Carabine',
                'Pistolet mitrailleur', 'Arme de défense', 'Autre'];
    }

    /**
     * Config d'affichage des statuts (7 champs, format historique de ppa.php) :
     * [libellé, classe-badge, icône, couleur, couleur-claire, fond, bordure].
     */
    function mdt_ppa_statut_cfg(): array
    {
        return [
            'demande'  => ['Demande',  'bg-yellow', 'fas fa-clock',          '#f59e0b', '#fde68a', 'rgba(245,158,11,.08)', 'rgba(245,158,11,.25)'],
            'en_cours' => ['En cours', 'bg-blue',   'fas fa-hourglass-half', '#3b82f6', '#93c5fd', 'rgba(37,99,235,.08)',  'rgba(37,99,235,.25)'],
            'valide'   => ['Validé',   'bg-green',  'fas fa-check-circle',   '#10b981', '#6ee7b7', 'rgba(16,185,129,.08)', 'rgba(16,185,129,.25)'],
            'refuse'   => ['Refusé',   'bg-red',    'fas fa-times-circle',   '#ef4444', '#fca5a5', 'rgba(239,68,68,.08)',  'rgba(239,68,68,.25)'],
        ];
    }

    /**
     * Migration idempotente : table + colonnes du workflow unifié, puis
     * réinitialisation unique des données PPA de test (garde-fou admin_settings).
     * À appeler en tête de chaque page manipulant les demandes PPA.
     */
    function mdt_ppa_migrate(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `lspd_ppa_demandes` (
              `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `citizen_id`       INT UNSIGNED NOT NULL,
              `officer_id`       INT UNSIGNED,
              `statut`           ENUM('demande','en_cours','valide','refuse') NOT NULL DEFAULT 'demande',
              `type_arme`        VARCHAR(100),
              `motif_demande`    TEXT,
              `motif_refus`      TEXT,
              `date_demande`     DATE NOT NULL,
              `date_traitement`  DATE,
              `notes_officier`   TEXT,
              `created_by`       INT UNSIGNED,
              `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_citizen` (`citizen_id`),
              KEY `idx_statut`  (`statut`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}

        // Colonnes additionnelles du workflow unifié (MariaDB : IF NOT EXISTS).
        $addCols = [
            "source ENUM('civil','staff') NOT NULL DEFAULT 'staff'",
            "nom VARCHAR(100) NULL",
            "prenom VARCHAR(100) NULL",
            "date_naissance DATE NULL",
            "adresse VARCHAR(255) NULL",
            "piece_identite VARCHAR(500) NULL",
            "sams_cert_id INT UNSIGNED NULL",
            "sams_doc_url VARCHAR(500) NULL",
            "final_doc_url VARCHAR(500) NULL",
        ];
        foreach ($addCols as $col) {
            try { $db->exec("ALTER TABLE `lspd_ppa_demandes` ADD COLUMN IF NOT EXISTS $col"); } catch (Throwable $e) {}
        }

        // Colonnes du profil requises par l'affichage PPA. `address` n'est pas
        // auto-créée par profils.php : sur une base où elle manque, tout SELECT
        // joignant profiles avec p.address échouait silencieusement et les demandes
        // n'apparaissaient plus côté LSPD (compteur à 1 mais liste vide). Ajout
        // idempotent pour garantir la cohérence des requêtes.
        foreach (['address VARCHAR(255) NULL', 'date_of_birth DATE NULL'] as $pcol) {
            try { $db->exec("ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS $pcol"); } catch (Throwable $e) {}
        }

        // Auto-réparation du .htaccess du dossier d'upload PPA : un ancien fichier
        // contenant un `php_flag` non encadré renvoyait une erreur 500 (PHP-FPM) sur
        // les pièces d'identité. On réécrit toujours la version sûre si le dossier existe.
        $upDir = rtrim(ROOT_PATH, '/\\') . '/assets/uploads/ppa/';
        if (is_dir($upDir)) { mdt_ppa_write_upload_htaccess($upDir); }
        // NB : la réinitialisation initiale des données PPA (décision produit) a déjà
        // été effectuée. Aucune purge destructive n'est conservée ici : migrate() ne
        // doit jamais pouvoir supprimer de demandes en production.
    }

    /**
     * Upload local d'une pièce d'identité (image), calqué sur
     * mdt_character_handle_avatar_upload(). Dossier assets/uploads/ppa/,
     * neutralisé côté exécution PHP.
     *
     * @return array{ok:bool,path:?string,error:?string}
     */
    function mdt_ppa_handle_piece_upload(?array $file): array
    {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => null, 'error' => null]; // pas de fichier = pas d'erreur
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null, 'error' => "Erreur lors de l'envoi du fichier."];
        }
        if ($file['size'] > 6 * 1024 * 1024) {
            return ['ok' => false, 'path' => null, 'error' => 'Fichier trop lourd (max 6 Mo).'];
        }

        $exts  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($exts[$mime])) {
            return ['ok' => false, 'path' => null, 'error' => 'Format non supporté (PNG, JPG, WEBP uniquement).'];
        }

        $uploadDir = rtrim(ROOT_PATH, '/\\') . '/assets/uploads/ppa/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            return ['ok' => false, 'path' => null, 'error' => "Impossible de créer le dossier d'upload."];
        }
        mdt_ppa_write_upload_htaccess($uploadDir);

        $filename = bin2hex(random_bytes(8)) . '.' . $exts[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            return ['ok' => false, 'path' => null, 'error' => "Erreur lors de l'enregistrement du fichier."];
        }
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        return ['ok' => true, 'path' => $base . '/assets/uploads/ppa/' . $filename, 'error' => null];
    }

    /**
     * Écrit un .htaccess sûr dans le dossier d'upload PPA.
     *
     * IMPORTANT : le serveur tourne en PHP-FPM/CGI ; une directive `php_flag`
     * non encadrée provoque une erreur 500 sur TOUT le dossier (y compris les
     * images). On reprend donc le motif éprouvé des autres dossiers d'upload du
     * projet : blocage d'exécution via <FilesMatch>/Require, php_flag uniquement
     * sous <IfModule mod_php>. Écrit systématiquement (auto-réparation d'un
     * ancien .htaccess défectueux).
     */
    function mdt_ppa_write_upload_htaccess(string $dir): void
    {
        $dir = rtrim($dir, '/\\') . '/';
        if (!is_dir($dir)) return;
        $content = "# Uploads PPA — images uniquement (sécurité + cache).\n"
                 . "<FilesMatch \"\\.(php|phtml|phar|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\n"
                 . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
                 . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
                 . "<IfModule mod_headers.c>\n    <FilesMatch \"\\.(jpe?g|png|gif|webp)$\">\n        Header set Cache-Control \"public, max-age=2592000\"\n    </FilesMatch>\n</IfModule>\n";
        @file_put_contents($dir . '.htaccess', $content);
    }

    /** Normalise une URL d'image fournie par lien direct (http/https), sinon null. */
    function mdt_ppa_sanitize_url(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    /**
     * Résumé du casier judiciaire d'un citoyen (table criminal_records).
     *
     * @return array{count:int,active:int,items:array<int,array<string,mixed>>}
     */
    function mdt_ppa_casier(PDO $db, int $citizenId): array
    {
        $out = ['count' => 0, 'active' => 0, 'items' => []];
        if ($citizenId <= 0) return $out;
        try {
            $s = $db->prepare("SELECT offense_code, offense_title, fine_amount, jail_time, status, incident_date
                               FROM criminal_records WHERE citizen_id=?
                               ORDER BY incident_date DESC, id DESC LIMIT 100");
            $s->execute([$citizenId]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out['items']  = $rows;
            $out['count']  = count($rows);
            $out['active'] = count(array_filter($rows, static fn($r) => ($r['status'] ?? '') === 'active'));
        } catch (Throwable $e) {}
        return $out;
    }

    /** Absolutise une URL d'asset (avatar, pièce) pour un document/HTML autonome. */
    function mdt_ppa_abs_url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        if ($url[0] === '/') return (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . $url;
        return $url;
    }

    /**
     * Rend le casier judiciaire COMPLET (toutes les infractions) pour l'intérieur
     * d'un document généré (GoogleDocsGenerator, thème clair).
     */
    function mdt_ppa_casier_doc_html(array $casier): string
    {
        if (empty($casier['items'])) {
            return '<p style="color:#6b7280">Aucun antécédent judiciaire enregistré.</p>';
        }
        $html = '<table class="doc-med-table"><thead><tr><th>Infraction</th><th>Date</th>'
              . '<th>Peine</th><th style="text-align:right">Amende</th></tr></thead><tbody>';
        foreach ($casier['items'] as $r) {
            $title = trim((string) ($r['offense_title'] ?? '')) ?: (string) ($r['offense_code'] ?? '—');
            $date  = !empty($r['incident_date']) ? date('d/m/Y', strtotime((string) $r['incident_date'])) : '—';
            $fine  = (float) ($r['fine_amount'] ?? 0);
            $jail  = (int) ($r['jail_time'] ?? 0);
            $active= 'active' === ($r['status'] ?? '');
            $html .= '<tr><td><div class="doc-med-name">' . htmlspecialchars($title) . '</div>'
                   . '<div class="doc-med-sub">' . ($active ? 'En vigueur' : 'Classé') . '</div></td>'
                   . '<td>' . htmlspecialchars($date) . '</td>'
                   . '<td>' . ($jail > 0 ? $jail . ' mois' : '—') . '</td>'
                   . '<td class="doc-med-instr">' . ($fine > 0 ? '$' . number_format($fine, 0, '.', ',') : '—') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Rend le casier judiciaire COMPLET pour l'interface (thème sombre des panels).
     * Liste chaque infraction avec date, peine et amende.
     */
    function mdt_ppa_casier_list_html(array $casier): string
    {
        if (empty($casier['items'])) {
            return '<div style="font-size:.75rem;color:#94a3b8;padding:.35rem .1rem"><i class="fas fa-check" style="margin-right:.35rem;color:#4ade80"></i>Casier vierge — aucune infraction enregistrée.</div>';
        }
        $h = '<div style="display:flex;flex-direction:column;gap:.3rem">';
        foreach ($casier['items'] as $r) {
            $title = trim((string) ($r['offense_title'] ?? '')) ?: (string) ($r['offense_code'] ?? '—');
            $date  = !empty($r['incident_date']) ? date('d/m/Y', strtotime((string) $r['incident_date'])) : '—';
            $fine  = (float) ($r['fine_amount'] ?? 0);
            $jail  = (int) ($r['jail_time'] ?? 0);
            $active= 'active' === ($r['status'] ?? '');
            $h .= '<div style="display:flex;align-items:center;gap:.5rem;font-size:.72rem;padding:.35rem .55rem;border-radius:6px;background:rgba(255,255,255,.03);border:1px solid ' . ($active ? 'rgba(239,68,68,.2)' : 'rgba(255,255,255,.07)') . '">'
                . '<i class="fas fa-gavel" style="opacity:.55;font-size:.62rem;color:' . ($active ? '#fca5a5' : '#94a3b8') . '"></i>'
                . '<span style="flex:1;font-weight:600;color:#e2e8f0">' . htmlspecialchars($title) . ($active ? '' : ' <span style="font-weight:400;color:#94a3b8;font-size:.66rem">(classé)</span>') . '</span>'
                . '<span style="color:#94a3b8">' . htmlspecialchars($date) . '</span>'
                . ($jail > 0 ? '<span style="color:#fca5a5"><i class="fas fa-handcuffs" style="font-size:.6rem;margin-right:.2rem"></i>' . $jail . ' mois</span>' : '')
                . ($fine > 0 ? '<span style="color:#fcd34d">$' . number_format($fine, 0, '.', ',') . '</span>' : '')
                . '</div>';
        }
        $h .= '</div>';
        return $h;
    }

    /**
     * Générateur de document générique par faction : injecte le logo de la
     * faction (faction_logo_url) et laisse l'appelant fournir la palette via
     * $meta['palette']. Généralisation faction-agnostique de
     * sams_generate_document(). Retourne l'URL publique ou null.
     */
    function mdt_ppa_render_document(PDO $db, string $factionKey, string $title, string $content, string $type = 'document', array $meta = []): ?string
    {
        if (!class_exists('GoogleDocsGenerator')) {
            $gen = defined('ROOT_PATH') ? ROOT_PATH . '/config/GoogleDocsGenerator.php' : __DIR__ . '/../config/GoogleDocsGenerator.php';
            if (is_file($gen)) { @require_once $gen; }
        }
        if (!class_exists('GoogleDocsGenerator')) return null;
        try {
            if (!function_exists('faction_logo_url')) {
                $lp = defined('ROOT_PATH') ? ROOT_PATH . '/includes/faction_logo.php' : __DIR__ . '/faction_logo.php';
                if (is_file($lp)) { @require_once $lp; }
            }
            $logoUrl = function_exists('faction_logo_url') ? faction_logo_url($db, $factionKey) : null;
            $generator = new GoogleDocsGenerator();
            return $generator->generateDocument($title, $content, $type, $logoUrl, $meta) ?: null;
        } catch (Throwable $e) {
            error_log('mdt_ppa_render_document: ' . $e->getMessage());
            return null;
        }
    }
}
