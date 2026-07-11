<?php
/**
 * academy_helper.php
 * Centralise les factions, permissions, grades, matricules et QCM
 * pour le système d'Académie (panels Public + Centralized).
 */

if (!defined('ACADEMY_HELPER_LOADED')) {
define('ACADEMY_HELPER_LOADED', true);

/**
 * Métadonnées des 9 factions concernées par l'Académie.
 * slug : utilisé dans les noms de fichiers et URLs (lowercase).
 */
function academy_factions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'LSPD'              => ['slug' => 'lspd',              'name' => 'LSPD',                    'color' => '#2563eb', 'icon' => 'shield-halved',      'subtitle' => 'Los Santos Police Department'],
        'LSSD'              => ['slug' => 'lssd',              'name' => 'LSSD',                    'color' => '#10b981', 'icon' => 'star',               'subtitle' => 'Los Santos Sheriffs Department'],
        'SAMS'              => ['slug' => 'sams',              'name' => 'SAMS',                    'color' => '#f43f5e', 'icon' => 'kit-medical',        'subtitle' => 'Service Ambulancier & Médical'],
        'GRUPP6'            => ['slug' => 'grupp6',            'name' => 'Grupp 6',                 'color' => '#64748b', 'icon' => 'warehouse',          'subtitle' => 'Société de sécurité'],
        'MARSHALS'          => ['slug' => 'marshals',          'name' => 'US Marshals',             'color' => '#475569', 'icon' => 'shield-halved',      'subtitle' => 'US Marshals Service'],
        'SUPERIOR_COURT_SA' => ['slug' => 'superior_court_sa', 'name' => 'Superior Court of S.A',   'color' => '#b45309', 'icon' => 'gavel',              'subtitle' => 'Cour supérieure de San Andreas'],
        'LSFD'              => ['slug' => 'lsfd',              'name' => 'LSFD',                    'color' => '#dc2626', 'icon' => 'fire-flame-curved',  'subtitle' => 'Los Santos Fire Department'],
        'IRS_TREASURY'      => ['slug' => 'irs_treasury',      'name' => 'IRS Treasury',            'color' => '#0f766e', 'icon' => 'landmark',           'subtitle' => 'Department of the Treasury'],
        'DOJ'               => ['slug' => 'doj',               'name' => 'DOJ',                     'color' => '#ef4444', 'icon' => 'scale-balanced',     'subtitle' => 'Department of Justice'],
    ];
    return $cache;
}

function academy_faction_meta(string $fk): array {
    $all = academy_factions();
    return $all[$fk] ?? ['slug' => strtolower($fk), 'name' => $fk, 'color' => '#3b82f6', 'icon' => 'building', 'subtitle' => ''];
}

function academy_faction_slug(string $fk): string {
    return academy_faction_meta($fk)['slug'];
}

function academy_faction_from_slug(string $slug): ?string {
    foreach (academy_factions() as $key => $meta) {
        if ($meta['slug'] === $slug) return $key;
    }
    return null;
}

function academy_faction_exists(string $fk): bool {
    return array_key_exists($fk, academy_factions());
}

/**
 * Types autorisés pour les champs dynamiques d'une candidature.
 */
function academy_candidature_field_types(): array {
    return [
        'texte_court' => 'Texte court',
        'texte_long'  => 'Texte long',
        'nombre'      => 'Nombre',
        'date'        => 'Date',
    ];
}

/**
 * Statuts possibles d'une candidature avec leurs métadonnées d'affichage.
 */
function academy_candidature_statuses(): array {
    return [
        'en_attente' => ['label' => 'En attente', 'color' => '#fcd34d', 'icon' => 'clock',           'bg' => 'rgba(245,158,11,.12)'],
        'en_cours'   => ['label' => 'En cours',   'color' => '#93c5fd', 'icon' => 'hourglass-half',  'bg' => 'rgba(59,130,246,.12)'],
        'en_examen'  => ['label' => 'En examen',  'color' => '#c4b5fd', 'icon' => 'clipboard-check', 'bg' => 'rgba(139,92,246,.12)'],
        'valide'     => ['label' => 'Validé',     'color' => '#6ee7b7', 'icon' => 'circle-check',    'bg' => 'rgba(16,185,129,.12)'],
        'refuse'     => ['label' => 'Refusé',     'color' => '#fca5a5', 'icon' => 'circle-xmark',    'bg' => 'rgba(239,68,68,.12)'],
    ];
}

function academy_formation_statuses(): array {
    return [
        'non_commence' => ['label' => 'Non commencé', 'color' => '#94a3b8', 'icon' => 'circle',         'bg' => 'rgba(100,116,139,.12)'],
        'en_cours'     => ['label' => 'En cours',     'color' => '#93c5fd', 'icon' => 'hourglass-half', 'bg' => 'rgba(59,130,246,.12)'],
        'valide'       => ['label' => 'Validé',       'color' => '#6ee7b7', 'icon' => 'circle-check',   'bg' => 'rgba(16,185,129,.12)'],
        'refuse'       => ['label' => 'Refusé',       'color' => '#fca5a5', 'icon' => 'circle-xmark',   'bg' => 'rgba(239,68,68,.12)'],
    ];
}

// ─── PERMISSIONS ────────────────────────────────────────────────────────────

/**
 * Indique si un user a un accès staff qui bypass tous les gates académie.
 */
function academy_is_staff_bypass(): bool {
    return hasRole(['*', 'Administrateur', 'Développeur', 'Staff']);
}

/**
 * Retourne tous les grades académie d'un user.
 * @return array [['grade_id','grade_key','name','color','icon','faction_key','permissions' (array)]]
 */
function academy_user_grades(int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    if ($uid <= 0) return $cache[$uid] = [];
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'SELECT g.id AS grade_id, g.grade_key, g.name, g.color, g.icon, g.faction_key, g.permissions
             FROM academy_user_grades aug
             JOIN academy_grades g ON g.id = aug.grade_id
             WHERE aug.user_id = ? AND g.is_active = 1'
        );
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['permissions'] = $r['permissions'] ? (json_decode($r['permissions'], true) ?: []) : [];
        }
        unset($r);
        return $cache[$uid] = $rows;
    } catch (Exception $e) {
        return $cache[$uid] = [];
    }
}

/**
 * Indique si le user connecté a accès (vue) à la page_key donnée.
 */
function academy_user_can(int $uid, string $pageKey, string $action = 'view'): bool {
    if (academy_is_staff_bypass()) return true;
    if ($uid <= 0) return false;
    $grades = academy_user_grades($uid);
    if (empty($grades)) return false;
    try {
        $db = Database::getInstance()->getConnection();
        $gids = array_column($grades, 'grade_id');
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $col  = $action === 'edit' ? 'can_edit' : 'can_view';
        $stmt = $db->prepare(
            "SELECT 1 FROM academy_grade_page_access
             WHERE grade_id IN ($ph) AND page_key = ? AND $col = 1
             LIMIT 1"
        );
        $params = array_merge($gids, [$pageKey]);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Indique si le user a au moins un grade académie (pour afficher le panel Centralized).
 */
function academy_user_has_any_grade(int $uid): bool {
    if (academy_is_staff_bypass()) return true;
    return !empty(academy_user_grades($uid));
}

/**
 * Gate d'accès pour les pages Centralized. Redirige sinon.
 */
function academy_require_access(string $pageKey, string $action = 'view'): void {
    requireLogin();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!academy_user_can($uid, $pageKey, $action)) {
        if (academy_user_has_any_grade($uid)) {
            header('Location: ' . SITE_URL . '/pages/academy-central/dashboard.php');
        } else {
            header('Location: ' . SITE_URL . '/pages/dashboard.php');
        }
        exit;
    }
}

/**
 * Gate simple pour le panel Public (tout user connecté).
 */
function academy_require_public(): void {
    requireLogin();
}

/**
 * Gate d'entrée au panel Centralized (au moins un grade ou staff).
 */
function academy_require_central_entry(): void {
    requireLogin();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!academy_user_has_any_grade($uid)) {
        header('Location: ' . SITE_URL . '/pages/dashboard.php');
        exit;
    }
}

/**
 * Liste des factions pour lesquelles le user a au moins un accès Centralized.
 * Staff voit toutes les factions. Retourne la liste des faction_keys.
 */
function academy_user_accessible_factions(int $uid): array {
    if (academy_is_staff_bypass()) return array_keys(academy_factions());
    $grades = academy_user_grades($uid);
    if (empty($grades)) return [];
    try {
        $db = Database::getInstance()->getConnection();
        $gids = array_column($grades, 'grade_id');
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT page_key FROM academy_grade_page_access
             WHERE grade_id IN ($ph) AND can_view = 1"
        );
        $stmt->execute($gids);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $factionSlugs = [];
        foreach ($keys as $k) {
            if (preg_match('/_([a-z_]+)$/', $k, $m)) {
                $slug = $m[1];
                $fk = academy_faction_from_slug($slug);
                if ($fk) $factionSlugs[$fk] = true;
            }
        }
        return array_keys($factionSlugs);
    } catch (Exception $e) {
        return [];
    }
}

// ─── MATRICULES ─────────────────────────────────────────────────────────────

function academy_matricule_ranges(string $fk): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM academy_matricule_ranges WHERE faction_key = ? ORDER BY range_min');
        $stmt->execute([$fk]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function academy_matricule_pad(int $n, int $padding = 3): string {
    return str_pad((string)$n, max($padding, strlen((string)$n)), '0', STR_PAD_LEFT);
}

/**
 * Synchronise la table academy_matricules : crée les slots manquants
 * pour toutes les plages configurées d'une faction.
 */
function academy_matricule_sync_slots(string $fk): void {
    try {
        $db = Database::getInstance()->getConnection();
        $ranges = academy_matricule_ranges($fk);
        if (empty($ranges)) return;
        $existing = $db->prepare('SELECT number FROM academy_matricules WHERE faction_key = ?');
        $existing->execute([$fk]);
        $existingNums = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $existingSet = array_flip($existingNums);
        $toInsert = [];
        foreach ($ranges as $r) {
            for ($i = (int)$r['range_min']; $i <= (int)$r['range_max']; $i++) {
                if (!isset($existingSet[$i])) $toInsert[] = $i;
            }
        }
        if (empty($toInsert)) return;
        $ins = $db->prepare('INSERT IGNORE INTO academy_matricules (faction_key, number) VALUES (?, ?)');
        foreach ($toInsert as $n) $ins->execute([$fk, $n]);
    } catch (Exception $e) {}
}

// ─── QCM ────────────────────────────────────────────────────────────────────

function academy_qcm_config(string $fk): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM academy_qcm_config WHERE faction_key = ? LIMIT 1');
        $stmt->execute([$fk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Exception $e) {}
    return [
        'faction_key' => $fk, 'num_questions' => 10, 'passing_score' => 60,
        'duration_minutes' => 30, 'randomize' => 1, 'is_active' => 1,
    ];
}

function academy_qcm_pick_questions(string $fk, int $n, bool $randomize = true): array {
    try {
        $db = Database::getInstance()->getConnection();
        $order = $randomize ? 'RAND()' : 'sort_order, id';
        $stmt  = $db->prepare(
            "SELECT id, description, option_a, option_b, option_c, option_d, correct, sort_order
             FROM academy_qcm_questions
             WHERE faction_key = ? AND is_active = 1
             ORDER BY $order
             LIMIT " . (int)$n
        );
        $stmt->execute([$fk]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ─── CANDIDATURES ───────────────────────────────────────────────────────────

/**
 * @deprecated Conservé pour compatibilité. Utilisez academy_user_can_take_qcm()
 * pour le gate QCM (qui vérifie 'en_examen' depuis l'évolution v2).
 */
function academy_user_has_cand_in_progress(int $uid, string $fk): bool {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM academy_candidatures
             WHERE user_id = ? AND faction_key = ? AND status = 'en_cours' LIMIT 1"
        );
        $stmt->execute([$uid, $fk]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Indique si le user peut passer le QCM d'une faction.
 * Le QCM est désormais débloqué uniquement quand la candidature est en statut « en_examen ».
 */
function academy_user_can_take_qcm(int $uid, string $fk): bool {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM academy_candidatures
             WHERE user_id = ? AND faction_key = ? AND status = 'en_examen' LIMIT 1"
        );
        $stmt->execute([$uid, $fk]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Récupère la dernière candidature d'un user pour une faction (ou null).
 */
function academy_user_latest_candidature(int $uid, string $fk): ?array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM academy_candidatures
             WHERE user_id = ? AND faction_key = ?
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$uid, $fk]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function academy_candidature_config(string $fk): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM academy_candidature_config WHERE faction_key = ? LIMIT 1');
        $stmt->execute([$fk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['fields'] = $row['fields'] ? (json_decode($row['fields'], true) ?: []) : [];
            return $row;
        }
    } catch (Exception $e) {}
    return ['faction_key' => $fk, 'name' => 'Candidature ' . $fk, 'description' => '', 'is_active' => 1, 'fields' => []];
}

// ─── DIVERS ─────────────────────────────────────────────────────────────────

/**
 * CSRF helper commun pour les endpoints Academy.
 */
function academy_csrf_token(): string {
    if (empty($_SESSION['academy_csrf'])) {
        $_SESSION['academy_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['academy_csrf'];
}

function academy_csrf_check(?string $token): bool {
    return $token !== null && !empty($_SESSION['academy_csrf']) && hash_equals($_SESSION['academy_csrf'], $token);
}

/**
 * Renvoie une réponse JSON et termine l'exécution.
 */
function academy_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Sanitize HTML pour le rich editor (documents forum posts) : retire les scripts.
 */
function academy_sanitize_html(string $html): string {
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html);
    return $html;
}

// ─── HOMEPAGE FACTION (Académie immersive v2) ───────────────────────────────

/**
 * Configuration de la page d'accueil immersive d'une faction.
 * Renvoie un tableau associatif avec valeurs par défaut si rien en BDD.
 */
function academy_homepage_config(string $fk): array {
    $defaults = [
        'faction_key'        => $fk,
        'hero_title'         => academy_faction_meta($fk)['name'],
        'hero_subtitle'      => academy_faction_meta($fk)['subtitle'] ?? '',
        'presentation_text'  => '',
        'cta_primary_label'  => 'Déposer une candidature',
        'cta_secondary_label'=> 'Passer le QCM',
        'accent_color'       => academy_faction_meta($fk)['color'],
        'motto'              => '',
        'is_published'       => 1,
    ];
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM academy_homepage_config WHERE faction_key = ? LIMIT 1');
        $stmt->execute([$fk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return array_merge($defaults, $row);
    } catch (Exception $e) {}
    return $defaults;
}

/**
 * Liste des images du slider d'une faction (par défaut : actives uniquement).
 */
function academy_homepage_images(string $fk, bool $onlyActive = true): array {
    try {
        $db = Database::getInstance()->getConnection();
        $sql = 'SELECT * FROM academy_homepage_images WHERE faction_key = ?';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $db->prepare($sql);
        $stmt->execute([$fk]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Liste des questions FAQ d'une faction.
 */
function academy_homepage_faq(string $fk, bool $onlyActive = true): array {
    try {
        $db = Database::getInstance()->getConnection();
        $sql = 'SELECT * FROM academy_homepage_faq WHERE faction_key = ?';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $db->prepare($sql);
        $stmt->execute([$fk]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Liste des annonces actives d'une faction (filtre expires_at).
 */
function academy_homepage_announcements(string $fk, bool $onlyActive = true): array {
    try {
        $db = Database::getInstance()->getConnection();
        $sql = 'SELECT * FROM academy_homepage_announcements WHERE faction_key = ?';
        if ($onlyActive) $sql .= " AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
        $sql .= ' ORDER BY sort_order, id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute([$fk]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Métadonnées des types d'annonces (label, couleur, icône).
 */
function academy_announcement_kinds(): array {
    return [
        'info'        => ['label' => 'Information',  'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,.12)',  'icon' => 'circle-info'],
        'event'       => ['label' => 'Événement',    'color' => '#a855f7', 'bg' => 'rgba(168,85,247,.12)',  'icon' => 'calendar-days'],
        'recruitment' => ['label' => 'Recrutement',  'color' => '#10b981', 'bg' => 'rgba(16,185,129,.12)',  'icon' => 'user-plus'],
        'warning'     => ['label' => 'Important',    'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)',  'icon' => 'triangle-exclamation'],
        'success'     => ['label' => 'Bonne nouvelle','color' => '#22c55e','bg' => 'rgba(34,197,94,.12)',   'icon' => 'circle-check'],
    ];
}

/**
 * Résout le chemin d'affichage d'une image académie.
 * Si le chemin est déjà une URL absolue (http://…), retourne tel quel.
 * Sinon préfixe avec SITE_URL.
 */
function academy_image_src(string $path): string {
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return SITE_URL . $path;
}

/**
 * Chemin physique du dossier d'upload pour une faction.
 * Crée le dossier s'il n'existe pas (chmod 0755).
 */
function academy_upload_dir(string $fk): array {
    $slug = academy_faction_slug($fk);
    $rel  = '/uploads/academy/' . $slug;
    $abs  = realpath(__DIR__ . '/..');
    if ($abs === false) $abs = dirname(__DIR__);
    $abs .= $rel;
    if (!is_dir($abs)) @mkdir($abs, 0755, true);
    return ['abs' => $abs, 'rel' => $rel];
}

// ─── FORUM v2 ────────────────────────────────────────────────────────────

/**
 * Gate d'accès pour le forum académique : autorise un user qui
 *  - est staff (bypass), OU
 *  - a au moins un grade académie, OU
 *  - appartient à une faction publique (= membre régulier d'un service public).
 * Sinon redirige vers le dashboard.
 */
function academy_require_forum_access(): void {
    if (function_exists('requireLogin')) requireLogin();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (academy_is_staff_bypass()) return;
    if ($uid > 0 && academy_user_has_any_grade($uid)) return;
    // Membre d'une faction publique ?
    $userFac = $_SESSION['user_faction'] ?? '';
    if ($userFac !== '' && academy_faction_exists($userFac)) return;
    header('Location: ' . SITE_URL . '/pages/dashboard.php');
    exit;
}


/**
 * Catégories de forum visibles pour un user (faction donnée ou globales).
 * @param string|null $fk  faction_key, ou null pour les catégories globales.
 */
function academy_forum_categories(int $uid, ?string $fk = null): array {
    try {
        $db = Database::getInstance()->getConnection();
        if ($fk === null) {
            $stmt = $db->prepare(
                'SELECT c.*,
                        (SELECT COUNT(*) FROM academy_forum_threads WHERE category_id = c.id) AS nb_threads,
                        (SELECT COUNT(p.id) FROM academy_forum_posts p JOIN academy_forum_threads t ON t.id = p.thread_id WHERE t.category_id = c.id AND p.deleted_at IS NULL) AS nb_posts,
                        (SELECT MAX(t2.last_post_at) FROM academy_forum_threads t2 WHERE t2.category_id = c.id) AS last_activity
                 FROM academy_forum_categories c
                 WHERE c.faction_key IS NULL AND c.is_active = 1
                 ORDER BY c.sort_order, c.id'
            );
            $stmt->execute();
        } else {
            $stmt = $db->prepare(
                'SELECT c.*,
                        (SELECT COUNT(*) FROM academy_forum_threads WHERE category_id = c.id) AS nb_threads,
                        (SELECT COUNT(p.id) FROM academy_forum_posts p JOIN academy_forum_threads t ON t.id = p.thread_id WHERE t.category_id = c.id AND p.deleted_at IS NULL) AS nb_posts,
                        (SELECT MAX(t2.last_post_at) FROM academy_forum_threads t2 WHERE t2.category_id = c.id) AS last_activity
                 FROM academy_forum_categories c
                 WHERE c.faction_key = ? AND c.is_active = 1
                 ORDER BY c.sort_order, c.id'
            );
            $stmt->execute([$fk]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Filtre selon required_grade_id
        if (academy_is_staff_bypass()) return $rows;
        $userGrades = array_column(academy_user_grades($uid), 'grade_id');
        return array_values(array_filter($rows, function ($c) use ($userGrades) {
            $req = (int)($c['required_grade_id'] ?? 0);
            return $req === 0 || in_array($req, $userGrades, true);
        }));
    } catch (Exception $e) { return []; }
}

/**
 * Vérifie qu'un user peut voir une catégorie précise.
 */
function academy_forum_can_view_category(int $uid, array $category): bool {
    if (academy_is_staff_bypass()) return true;
    $req = (int)($category['required_grade_id'] ?? 0);
    if ($req === 0) return true;
    foreach (academy_user_grades($uid) as $g) if ((int)$g['grade_id'] === $req) return true;
    return false;
}

/**
 * Liste des tags disponibles (faction + globaux).
 */
function academy_forum_tags(?string $fk = null): array {
    try {
        $db = Database::getInstance()->getConnection();
        if ($fk === null) {
            $stmt = $db->query('SELECT * FROM academy_forum_tags WHERE faction_key IS NULL ORDER BY sort_order, label');
        } else {
            $stmt = $db->prepare('SELECT * FROM academy_forum_tags WHERE faction_key = ? OR faction_key IS NULL ORDER BY faction_key IS NULL, sort_order, label');
            $stmt->execute([$fk]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/**
 * Tags attribués à un thread.
 */
function academy_forum_thread_tags(int $threadId): array {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'SELECT t.* FROM academy_forum_thread_tags tt
             JOIN academy_forum_tags t ON t.id = tt.tag_id
             WHERE tt.thread_id = ? ORDER BY t.sort_order, t.label'
        );
        $stmt->execute([$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/**
 * Récupère les tags pour un ensemble de thread_ids (1 query) → indexé par thread_id.
 */
function academy_forum_threads_tags(array $threadIds): array {
    if (empty($threadIds)) return [];
    try {
        $db = Database::getInstance()->getConnection();
        $ph = implode(',', array_fill(0, count($threadIds), '?'));
        $stmt = $db->prepare(
            "SELECT tt.thread_id, t.* FROM academy_forum_thread_tags tt
             JOIN academy_forum_tags t ON t.id = tt.tag_id
             WHERE tt.thread_id IN ($ph) ORDER BY t.sort_order, t.label"
        );
        $stmt->execute($threadIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['thread_id']][] = $r;
        }
        return $out;
    } catch (Exception $e) { return []; }
}

/**
 * Compte les notifications non lues d'un user pour le forum.
 */
function academy_forum_unread_count(int $uid): int {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM academy_forum_notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$uid]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/**
 * Crée une notification forum (réponse, mention, like, …).
 * Évite la self-notification.
 */
function academy_forum_notify(int $userId, string $kind, ?int $threadId, ?int $postId, ?int $fromUserId, ?string $payload = null): void {
    if ($userId <= 0) return;
    if ($fromUserId !== null && $fromUserId === $userId) return; // pas de self-notif
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare(
            'INSERT INTO academy_forum_notifications (user_id, kind, thread_id, post_id, from_user_id, payload) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $kind, $threadId, $postId, $fromUserId, $payload]);
    } catch (Exception $e) {}
}

/**
 * Détecte les @mentions dans un contenu HTML/texte et crée les notifications.
 * Renvoie la liste des user_ids mentionnés.
 */
function academy_forum_process_mentions(string $content, int $postId, int $threadId, int $fromUid): array {
    $plain = trim(strip_tags($content));
    if ($plain === '') return [];
    if (!preg_match_all('/(?:^|[^a-zA-Z0-9_])@([a-zA-Z0-9_\-]{2,32})/', $plain, $m)) return [];
    $usernames = array_unique($m[1]);
    if (empty($usernames)) return [];
    try {
        $db = Database::getInstance()->getConnection();
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        $stmt = $db->prepare("SELECT id, username FROM users WHERE username IN ($ph) LIMIT 50");
        $stmt->execute($usernames);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            $db->prepare('INSERT INTO academy_forum_mentions (post_id, mentioned_user, mentioned_by) VALUES (?,?,?)')
               ->execute([$postId, $uid, $fromUid]);
            academy_forum_notify($uid, 'mention', $threadId, $postId, $fromUid, mb_substr($plain, 0, 200));
        }
        return array_column($rows, 'id');
    } catch (Exception $e) { return []; }
}

/**
 * Incrémente le compteur de vues d'un thread (1× par jour par user).
 */
function academy_forum_register_view(int $threadId, int $uid): void {
    if ($threadId <= 0 || $uid <= 0) return;
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT last_view FROM academy_forum_thread_views WHERE thread_id = ? AND user_id = ?');
        $stmt->execute([$threadId, $uid]);
        $last = $stmt->fetchColumn();
        if ($last && (time() - strtotime((string)$last)) < 86400) return; // déjà compté aujourd'hui
        $db->prepare('INSERT INTO academy_forum_thread_views (thread_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_view = NOW()')
           ->execute([$threadId, $uid]);
        $db->prepare('UPDATE academy_forum_threads SET view_count = view_count + 1 WHERE id = ?')->execute([$threadId]);
    } catch (Exception $e) {}
}

/**
 * Marque toutes les notifications forum d'un user comme lues
 * (ou seulement celles d'un thread donné).
 */
function academy_forum_mark_read(int $uid, ?int $threadId = null): void {
    try {
        $db = Database::getInstance()->getConnection();
        if ($threadId === null) {
            $db->prepare('UPDATE academy_forum_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
        } else {
            $db->prepare('UPDATE academy_forum_notifications SET is_read = 1 WHERE user_id = ? AND thread_id = ? AND is_read = 0')
               ->execute([$uid, $threadId]);
        }
    } catch (Exception $e) {}
}

/**
 * Renvoie HTML pour un contenu post : sanitize + transforme @user en lien.
 */
function academy_forum_render_content(string $html): string {
    $html = academy_sanitize_html($html);
    // Transforme @username (pas dans une balise HTML) en mention stylée
    $html = preg_replace_callback(
        '/(^|>|\s)@([a-zA-Z0-9_\-]{2,32})\b/',
        fn($m) => $m[1] . '<span class="fmention">@' . htmlspecialchars($m[2]) . '</span>',
        $html
    );
    return $html;
}

/**
 * Vérifie si un user peut modérer le forum d'une faction.
 * Modération = pin/lock/delete d'un thread, suppression de post.
 */
function academy_forum_can_moderate(int $uid, ?string $fk = null): bool {
    if (academy_is_staff_bypass()) return true;
    $grades = academy_user_grades($uid);
    foreach ($grades as $g) {
        $perms = is_array($g['permissions']) ? $g['permissions'] : [];
        if (!empty($perms['forum_moderate'])) {
            // Si la perm est globale OU si le grade matche la faction du forum
            if ($fk === null || $g['faction_key'] === null || $g['faction_key'] === $fk) return true;
        }
    }
    return false;
}

} // fin ACADEMY_HELPER_LOADED
