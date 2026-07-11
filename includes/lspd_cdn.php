<?php
/**
 * CDN interne LSPD — stockage et partage d'images officiers.
 * Stockage filesystem local sous public/uploads/cdn/lspd/, chemins en DB.
 *
 * Réutilisé par : pages/lspd/archive.php (galerie principale) et les champs
 * image de armes-civils.php / equipement.php / saisies.php.
 *
 * Le helper est volontairement découplé des permissions : c'est à la page
 * appelante de vérifier faction_acl (cdn.view / cdn.upload / cdn.delete)
 * AVANT d'invoquer store/delete.
 */

// ── Constantes de chemin ──────────────────────────────────────────────────
function lspd_cdn_dir(): string  { return dirname(__DIR__) . '/public/uploads/cdn/lspd/'; }
function lspd_cdn_staging_dir(): string { return lspd_cdn_dir() . '_staging/'; }
function lspd_cdn_base_url(): string
{
    $base = defined('SITE_URL') ? SITE_URL : '';
    return $base . '/public/uploads/cdn/lspd/';
}

const LSPD_CDN_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const LSPD_CDN_EXT  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const LSPD_CDN_MAX  = 8388608; // 8 Mo

// ── Schéma ────────────────────────────────────────────────────────────────
function lspd_cdn_ensure_table(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `lspd_cdn_images` (
            `id`          int(11)      NOT NULL AUTO_INCREMENT,
            `title`       varchar(200) NOT NULL DEFAULT '',
            `file_path`   varchar(255) NULL,
            `url`         varchar(1024) NULL,
            `is_local`    tinyint(1)   NOT NULL DEFAULT 1,
            `uploaded_by` int(11)      NULL,
            `created_at`  timestamp    NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_uploaded_by` (`uploaded_by`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* existe déjà */ }
    $done = true;
}

// ── Helpers internes ──────────────────────────────────────────────────────
function lspd_cdn_ext_ok(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), LSPD_CDN_EXT, true);
}

function lspd_cdn_unique_name(string $origName): string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, LSPD_CDN_EXT, true)) $ext = 'png';
    return 'cdn_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

/**
 * URL publique directe et copiable d'une entrée CDN.
 */
function lspd_cdn_public_url(array $row): string
{
    if ((int)($row['is_local'] ?? 1) === 0 && !empty($row['url'])) return $row['url'];
    if (!empty($row['file_path'])) return lspd_cdn_base_url() . $row['file_path'];
    return $row['url'] ?? '';
}

// ── Liste ─────────────────────────────────────────────────────────────────
function lspd_cdn_all(PDO $db): array
{
    lspd_cdn_ensure_table($db);
    try {
        $rows = $db->query(
            "SELECT c.*, u.username AS uploader_name,
                    p.firstname AS uploader_first, p.lastname AS uploader_last
             FROM lspd_cdn_images c
             LEFT JOIN users u ON u.id = c.uploaded_by
             LEFT JOIN profiles p ON p.id = u.profile_id
             ORDER BY c.created_at DESC, c.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    foreach ($rows as &$r) { $r['public_url'] = lspd_cdn_public_url($r); }
    return $rows;
}

function lspd_cdn_get(PDO $db, int $id): ?array
{
    lspd_cdn_ensure_table($db);
    try {
        $s = $db->prepare("SELECT * FROM lspd_cdn_images WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Création depuis URL ───────────────────────────────────────────────────
function lspd_cdn_store_url(PDO $db, string $url, string $title, int $userId): array
{
    lspd_cdn_ensure_table($db);
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'error' => 'URL invalide'];
    }
    try {
        $s = $db->prepare(
            "INSERT INTO lspd_cdn_images (title, url, is_local, uploaded_by) VALUES (?, ?, 0, ?)"
        );
        $s->execute([mb_substr($title, 0, 200), $url, $userId ?: null]);
        return ['success' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => "Erreur d'enregistrement"];
    }
}

// ── Création depuis upload fichier ────────────────────────────────────────
function lspd_cdn_store_upload(PDO $db, array $file, string $title, int $userId): array
{
    lspd_cdn_ensure_table($db);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Aucun fichier reçu'];
    }
    if (!in_array($file['type'], LSPD_CDN_MIME, true) || !lspd_cdn_ext_ok($file['name'])) {
        return ['success' => false, 'error' => 'Type de fichier invalide'];
    }
    if (($file['size'] ?? 0) > LSPD_CDN_MAX) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max 8 Mo)'];
    }
    $dir = lspd_cdn_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['success' => false, 'error' => 'Dossier de stockage indisponible'];
    }
    $fn = lspd_cdn_unique_name($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        return ['success' => false, 'error' => 'Échec du transfert'];
    }
    try {
        $s = $db->prepare(
            "INSERT INTO lspd_cdn_images (title, file_path, is_local, uploaded_by) VALUES (?, ?, 1, ?)"
        );
        $s->execute([mb_substr($title, 0, 200), $fn, $userId ?: null]);
        return ['success' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        @unlink($dir . $fn);
        return ['success' => false, 'error' => "Erreur d'enregistrement"];
    }
}

/**
 * Stocke un fichier image dans le dossier CDN SANS créer d'entrée en DB.
 * Utilisé par les champs image des pages (armes, équipement, saisies) :
 * la permission est gérée par la page appelante, pas par cdn.upload.
 * Retourne ['success'=>true,'url'=>...] (URL publique directe).
 */
function lspd_cdn_store_file_raw(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Aucun fichier reçu'];
    }
    if (!in_array($file['type'], LSPD_CDN_MIME, true) || !lspd_cdn_ext_ok($file['name'])) {
        return ['success' => false, 'error' => 'Type de fichier invalide'];
    }
    if (($file['size'] ?? 0) > LSPD_CDN_MAX) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max 8 Mo)'];
    }
    $dir = lspd_cdn_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['success' => false, 'error' => 'Dossier de stockage indisponible'];
    }
    $fn = lspd_cdn_unique_name($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        return ['success' => false, 'error' => 'Échec du transfert'];
    }
    return ['success' => true, 'url' => lspd_cdn_base_url() . $fn];
}

// ── Archive ZIP : extraction vers staging pour sélection ──────────────────
/**
 * Extrait les images d'un ZIP uploadé dans un dossier de staging temporaire.
 * Retourne ['success'=>true,'token'=>..,'images'=>[['name'=>..,'url'=>..],..]]
 * Les fichiers ne sont PAS encore enregistrés en DB : l'utilisateur sélectionne
 * ensuite ceux à conserver via lspd_cdn_commit_staged().
 */
function lspd_cdn_extract_zip(array $file): array
{
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'Extension ZIP indisponible sur le serveur'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Aucune archive reçue'];
    }
    $token   = date('Ymd') . '_' . bin2hex(random_bytes(8));
    $dest    = lspd_cdn_staging_dir() . $token . '/';
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        return ['success' => false, 'error' => 'Dossier de staging indisponible'];
    }
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        return ['success' => false, 'error' => 'Archive illisible'];
    }
    $images = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false || substr($entry, -1) === '/') continue;
        $base = basename($entry);
        if ($base === '' || $base[0] === '.') continue;
        if (!lspd_cdn_ext_ok($base)) continue;
        $safe = lspd_cdn_unique_name($base);
        $stream = $zip->getStream($entry);
        if (!$stream) continue;
        $out = @fopen($dest . $safe, 'wb');
        if ($out) {
            stream_copy_to_stream($stream, $out);
            fclose($out);
            $images[] = [
                'name' => $base,
                'file' => $safe,
                'url'  => defined('SITE_URL') ? SITE_URL . '/public/uploads/cdn/lspd/_staging/' . $token . '/' . $safe : $safe,
            ];
        }
        fclose($stream);
    }
    $zip->close();
    if (empty($images)) {
        lspd_cdn_cleanup_staging($token);
        return ['success' => false, 'error' => 'Aucune image trouvée dans l\'archive'];
    }
    return ['success' => true, 'token' => $token, 'images' => $images];
}

/**
 * Valide les images sélectionnées d'un staging : les déplace dans le stockage
 * permanent et crée les entrées DB. $selected = liste de noms de fichiers staged.
 * $titles = map ['<file>' => 'titre'] optionnel.
 */
function lspd_cdn_commit_staged(PDO $db, string $token, array $selected, array $titles, int $userId): array
{
    lspd_cdn_ensure_table($db);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $token)) {
        return ['success' => false, 'error' => 'Token invalide'];
    }
    $stageDir = lspd_cdn_staging_dir() . $token . '/';
    $destDir  = lspd_cdn_dir();
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['success' => false, 'error' => 'Dossier de stockage indisponible'];
    }
    $created = 0;
    foreach ($selected as $file) {
        $file = basename($file);
        $src  = $stageDir . $file;
        if (!is_file($src) || !lspd_cdn_ext_ok($file)) continue;
        $fn = lspd_cdn_unique_name($file);
        if (!@rename($src, $destDir . $fn)) {
            if (!@copy($src, $destDir . $fn)) continue;
            @unlink($src);
        }
        try {
            $title = mb_substr($titles[$file] ?? pathinfo($file, PATHINFO_FILENAME), 0, 200);
            $s = $db->prepare(
                "INSERT INTO lspd_cdn_images (title, file_path, is_local, uploaded_by) VALUES (?, ?, 1, ?)"
            );
            $s->execute([$title, $fn, $userId ?: null]);
            $created++;
        } catch (Throwable $e) {
            @unlink($destDir . $fn);
        }
    }
    lspd_cdn_cleanup_staging($token);
    if ($created === 0) return ['success' => false, 'error' => 'Aucune image enregistrée'];
    return ['success' => true, 'count' => $created];
}

function lspd_cdn_cleanup_staging(string $token): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $token)) return;
    $dir = lspd_cdn_staging_dir() . $token . '/';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}

// ── Suppression ───────────────────────────────────────────────────────────
function lspd_cdn_delete(PDO $db, int $id): array
{
    lspd_cdn_ensure_table($db);
    $row = lspd_cdn_get($db, $id);
    if (!$row) return ['success' => false, 'error' => 'Image introuvable'];
    try {
        $db->prepare("DELETE FROM lspd_cdn_images WHERE id = ?")->execute([$id]);
        if ((int)$row['is_local'] === 1 && !empty($row['file_path'])) {
            @unlink(lspd_cdn_dir() . $row['file_path']);
        }
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Suppression échouée'];
    }
}
