<?php
/**
 * LSSD — Helpers de gestion des annonces (dashboard).
 *
 * Couvre permissions, validations, CSRF, auto-migration du schéma et
 * CRUD centralisé. Utilisé par dashboard.php et les endpoints AJAX
 * `pages/lssd/ajax/announcement_*.php`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/mdt_display_name.php';

/** Grades LSSD autorisés à gérer les annonces. */
function lssd_ann_editor_grades(): array
{
    return [
        'Sergeant', 'Sergeant',
        'Lieutenant', 'Lieutenant',
        'Captain', 'Captain', 'Area Commander',
        'Division Chief', 'Undersheriff', 'Sheriff',
    ];
}

/**
 * Peut gérer (créer / modifier / supprimer) une annonce ?
 * Staff bypass + grades LSSD listés ci-dessus.
 */
function lssd_ann_user_can_manage(): bool
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
        return true;
    }
    if (($_SESSION['user_faction'] ?? '') !== 'LSSD') {
        return false;
    }
    $rank = trim((string) ($_SESSION['user_faction_rank'] ?? ''));
    if ($rank === '') {
        // Lit depuis la DB si pas en session (cas rare).
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('SELECT faction_rank FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
            $rank = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $rank = '';
        }
    }
    return $rank !== '' && in_array($rank, lssd_ann_editor_grades(), true);
}

function lssd_ann_json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function lssd_ann_ensure_csrf(): string
{
    if (empty($_SESSION['lssd_ann_csrf']) || !is_string($_SESSION['lssd_ann_csrf'])) {
        $_SESSION['lssd_ann_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['lssd_ann_csrf'];
}

function lssd_ann_verify_csrf(?string $t): bool
{
    $s = $_SESSION['lssd_ann_csrf'] ?? '';
    return is_string($t) && $s !== '' && hash_equals($s, $t);
}

/**
 * Auto-migration idempotente : ajoute les colonnes manquantes (image_url,
 * color, is_active) pour compatibilité avec les déploiements existants.
 */
function lssd_ann_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $cols = $db->query('SHOW COLUMNS FROM lssd_announcements')->fetchAll(PDO::FETCH_COLUMN);
        $have = array_map('strtolower', $cols ?: []);

        if (!in_array('image_url', $have, true)) {
            $db->exec('ALTER TABLE `lssd_announcements` ADD COLUMN `image_url` VARCHAR(2048) NULL AFTER `content`');
        }
        if (!in_array('color', $have, true)) {
            $db->exec("ALTER TABLE `lssd_announcements` ADD COLUMN `color` VARCHAR(7) NULL AFTER `image_url`");
        }
        if (!in_array('is_active', $have, true)) {
            $db->exec('ALTER TABLE `lssd_announcements` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `color`');
        }
    } catch (Throwable $e) {
        error_log('lssd_ann_ensure_schema: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Couleur d'accent valide : hex #rgb / #rrggbb. Retourne la valeur
 * normalisée (#rrggbb en minuscules) ou null si invalide / vide.
 */
function lssd_ann_normalize_color(?string $raw): ?string
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) {
        return null;
    }
    if (strlen($v) === 4) { // #rgb → #rrggbb
        $v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
    }
    return strtolower($v);
}

/**
 * Validation d'URL d'image : http/https + extension image connue.
 * Retourne l'URL nettoyée ou null si invalide / vide.
 */
function lssd_ann_normalize_image_url(?string $raw): ?string
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '') {
        return null;
    }
    if (mb_strlen($v) > 2048) {
        return null;
    }
    if (!filter_var($v, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    // Extension image (on accepte aussi les CDN avec query string)
    $path = (string) parse_url($v, PHP_URL_PATH);
    $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $ok   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];
    if ($ext !== '' && !in_array($ext, $ok, true)) {
        // On tolère les URLs sans extension évidente (ex. imgur, cdn
        // qui redirigent), sinon on bloque les extensions non-images.
        return null;
    }
    return $v;
}

/**
 * Liste des annonces actives, limit par défaut 10, tri desc par date.
 *
 * @return array<int, array<string, mixed>>
 */
function lssd_ann_list(PDO $db, int $limit = 10): array
{
    lssd_ann_ensure_schema($db);
    $limit = max(1, min(50, $limit));
    $sql = "SELECT a.*, u.username, u.faction_rank
            FROM lssd_announcements a
            LEFT JOIN users u ON u.id = a.author_id
            WHERE COALESCE(a.is_active, 1) = 1
            ORDER BY a.created_at DESC
            LIMIT {$limit}";
    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('lssd_ann_list: ' . $e->getMessage());
        return [];
    }
}

/** Récupère une annonce par id (ou null). */
function lssd_ann_get(PDO $db, int $id): ?array
{
    lssd_ann_ensure_schema($db);
    try {
        $st = $db->prepare('SELECT * FROM lssd_announcements WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Crée une annonce. Valide title (1..255), content (1..5000), color (#hex
 * ou null), image_url (URL image valide ou null).
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function lssd_ann_create(PDO $db, int $authorId, string $authorName, ?string $authorRank, array $data): array
{
    $title = trim((string) ($data['title']   ?? ''));
    $cont  = trim((string) ($data['content'] ?? ''));
    $color = lssd_ann_normalize_color((string) ($data['color'] ?? ''));
    $img   = lssd_ann_normalize_image_url((string) ($data['image_url'] ?? ''));

    if ($title === '' || mb_strlen($title) > 255) {
        return ['ok' => false, 'error' => 'Titre requis (max 255 caractères)'];
    }
    if ($cont === '' || mb_strlen($cont) > 5000) {
        return ['ok' => false, 'error' => 'Description requise (max 5000 caractères)'];
    }
    if (!empty($data['image_url']) && $img === null) {
        return ['ok' => false, 'error' => "Lien d'image invalide (URL http(s) + extension .jpg/.png/.webp/...)"];
    }
    if (!empty($data['color']) && $color === null) {
        return ['ok' => false, 'error' => 'Couleur invalide (format #rrggbb)'];
    }

    lssd_ann_ensure_schema($db);
    try {
        $st = $db->prepare('
            INSERT INTO lssd_announcements
                (author_id, author_name, author_rank, title, content, image_url, color, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ');
        $st->execute([$authorId, $authorName, $authorRank, $title, $cont, $img, $color]);
        return ['ok' => true, 'id' => (int) $db->lastInsertId()];
    } catch (Throwable $e) {
        error_log('lssd_ann_create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur serveur'];
    }
}

/**
 * Met à jour une annonce existante. Mêmes validations que `create`.
 *
 * @return array{ok:bool, error?:string}
 */
function lssd_ann_update(PDO $db, int $id, array $data): array
{
    $title = trim((string) ($data['title']   ?? ''));
    $cont  = trim((string) ($data['content'] ?? ''));
    $color = lssd_ann_normalize_color((string) ($data['color'] ?? ''));
    $img   = lssd_ann_normalize_image_url((string) ($data['image_url'] ?? ''));

    if ($title === '' || mb_strlen($title) > 255) {
        return ['ok' => false, 'error' => 'Titre requis (max 255 caractères)'];
    }
    if (mb_strlen($cont) > 5000 || $cont === '') {
        return ['ok' => false, 'error' => 'Description requise (max 5000 caractères)'];
    }
    if (!empty($data['image_url']) && $img === null) {
        return ['ok' => false, 'error' => "Lien d'image invalide"];
    }
    if (!empty($data['color']) && $color === null) {
        return ['ok' => false, 'error' => 'Couleur invalide (format #rrggbb)'];
    }

    lssd_ann_ensure_schema($db);
    try {
        $st = $db->prepare('
            UPDATE lssd_announcements
            SET title = ?, content = ?, image_url = ?, color = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $st->execute([$title, $cont, $img, $color, $id]);
        if ($st->rowCount() === 0 && !lssd_ann_get($db, $id)) {
            return ['ok' => false, 'error' => 'Annonce introuvable'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('lssd_ann_update: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur serveur'];
    }
}

/** Suppression "douce" : is_active = 0 (conserve l'historique en base). */
function lssd_ann_delete(PDO $db, int $id): array
{
    lssd_ann_ensure_schema($db);
    try {
        $st = $db->prepare('UPDATE lssd_announcements SET is_active = 0, updated_at = NOW() WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0 && !lssd_ann_get($db, $id)) {
            return ['ok' => false, 'error' => 'Annonce introuvable'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('lssd_ann_delete: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur serveur'];
    }
}
