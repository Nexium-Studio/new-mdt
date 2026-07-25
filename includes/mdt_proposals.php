<?php
/**
 * Propositions d'ajout MDT — soumissions des membres staff pour de nouvelles
 * fonctionnalités / améliorations. Chaque proposition est enregistrée en base,
 * l'auteur consulte l'historique + le statut, et un webhook Discord est envoyé.
 *
 * Stockage des images : filesystem local sous public/uploads/proposals/.
 * URL du webhook : admin_settings['discord_webhook_proposals'] (configurable
 * par un admin depuis le dashboard staff).
 */

declare(strict_types=1);

const MDT_PROPOSAL_MIME       = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const MDT_PROPOSAL_EXT        = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MDT_PROPOSAL_MAX        = 8388608; // 8 Mo par image
const MDT_PROPOSAL_MAX_IMAGES = 5;
const MDT_PROPOSAL_MAX_LEN    = 5000;

// ── Chemins ────────────────────────────────────────────────────────────────
function mdt_proposals_dir(): string
{
    return dirname(__DIR__) . '/public/uploads/proposals/';
}
function mdt_proposals_base_url(): string
{
    $base = defined('SITE_URL') ? SITE_URL : '';
    return $base . '/public/uploads/proposals/';
}

// ── Statuts ──────────────────────────────────────────────────────────────────
/** @return array<string,array{label:string,color:string,icon:string}> */
function mdt_proposals_statuses(): array
{
    return [
        'pending'   => ['label' => 'En attente',    'color' => '#f59e0b', 'icon' => 'clock'],
        'reviewing' => ['label' => 'En examen',     'color' => '#3b82f6', 'icon' => 'magnifying-glass'],
        'accepted'  => ['label' => 'Acceptée',      'color' => '#10b981', 'icon' => 'thumbs-up'],
        'done'      => ['label' => 'Implémentée',   'color' => '#8b5cf6', 'icon' => 'rocket'],
        'rejected'  => ['label' => 'Refusée',       'color' => '#ef4444', 'icon' => 'xmark'],
    ];
}
function mdt_proposals_status_meta(string $status): array
{
    $all = mdt_proposals_statuses();
    return $all[$status] ?? $all['pending'];
}

// ── Schéma ───────────────────────────────────────────────────────────────────
function mdt_proposals_ensure_table(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `mdt_feature_proposals` (
            `id`         int(11)      NOT NULL AUTO_INCREMENT,
            `user_id`    int(11)      NULL,
            `username`   varchar(190) NOT NULL DEFAULT '',
            `content`    text         NOT NULL,
            `images`     text         NULL,
            `status`     varchar(20)  NOT NULL DEFAULT 'pending',
            `admin_note` text         NULL,
            `page_url`   varchar(512) NULL,
            `created_at` timestamp    NULL DEFAULT current_timestamp(),
            `updated_at` timestamp    NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user`    (`user_id`),
            KEY `idx_status`  (`status`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* existe déjà */ }
    $done = true;
}

// ── Helpers images ───────────────────────────────────────────────────────────
function mdt_proposals_ext_ok(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), MDT_PROPOSAL_EXT, true);
}
function mdt_proposals_unique_name(string $origName): string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, MDT_PROPOSAL_EXT, true)) $ext = 'png';
    return 'prop_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

/**
 * Valide et déplace une image uploadée dans le dossier des propositions.
 * @return array{success:bool,url?:string,error?:string}
 */
function mdt_proposals_store_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Aucun fichier reçu'];
    }
    $mime = (string) ($file['type'] ?? '');
    if (function_exists('finfo_open') && is_uploaded_file($file['tmp_name'] ?? '')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) finfo_file($fi, $file['tmp_name']); finfo_close($fi); }
    }
    if (!in_array($mime, MDT_PROPOSAL_MIME, true) || !mdt_proposals_ext_ok((string) $file['name'])) {
        return ['success' => false, 'error' => 'Type de fichier invalide'];
    }
    if (($file['size'] ?? 0) > MDT_PROPOSAL_MAX) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max 8 Mo)'];
    }
    $dir = mdt_proposals_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['success' => false, 'error' => 'Dossier de stockage indisponible'];
    }
    $fn = mdt_proposals_unique_name((string) $file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dir . $fn)) {
        return ['success' => false, 'error' => 'Échec du transfert'];
    }
    return ['success' => true, 'url' => mdt_proposals_base_url() . $fn];
}

// ── CRUD ─────────────────────────────────────────────────────────────────────
/**
 * @param string[] $imageUrls
 * @return array{success:bool,id?:int,error?:string}
 */
function mdt_proposals_create(PDO $db, int $userId, string $username, string $content, array $imageUrls, string $pageUrl): array
{
    mdt_proposals_ensure_table($db);
    $content = trim($content);
    if ($content === '') {
        return ['success' => false, 'error' => 'Le contenu de la proposition est vide.'];
    }
    if (mb_strlen($content) > MDT_PROPOSAL_MAX_LEN) {
        $content = mb_substr($content, 0, MDT_PROPOSAL_MAX_LEN);
    }
    try {
        $st = $db->prepare(
            "INSERT INTO mdt_feature_proposals (user_id, username, content, images, status, page_url)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        );
        $st->execute([
            $userId ?: null,
            mb_substr($username, 0, 190),
            $content,
            json_encode(array_values($imageUrls), JSON_UNESCAPED_SLASHES),
            mb_substr($pageUrl, 0, 512),
        ]);
        return ['success' => true, 'id' => (int) $db->lastInsertId()];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => "Erreur d'enregistrement."];
    }
}

/** @return array<int,array<string,mixed>> */
function mdt_proposals_list_for_user(PDO $db, int $userId): array
{
    mdt_proposals_ensure_table($db);
    try {
        $st = $db->prepare(
            "SELECT * FROM mdt_feature_proposals WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50"
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as &$r) {
        $decoded = json_decode((string) ($r['images'] ?? '[]'), true);
        $r['images_arr'] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
    unset($r);
    return $rows;
}

// ── Webhook Discord ──────────────────────────────────────────────────────────
function mdt_proposals_webhook_url(PDO $db): string
{
    try {
        $rows = mdt_admin_settings_fetch($db, ['discord_webhook_proposals']);
        $u = trim((string) ($rows['discord_webhook_proposals'] ?? ''));
        return preg_match('#^https?://#i', $u) ? $u : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Envoie la notification Discord (embed). Les images publiques sont regroupées
 * via l'astuce « même url d'embed » → galerie (jusqu'à 4 vignettes).
 * @param array{id?:int,username?:string,content?:string,page_url?:string,images?:string[]} $p
 */
function mdt_proposals_send_webhook(string $url, array $p): void
{
    if ($url === '' || !function_exists('curl_init')) return;

    $images  = array_values(array_filter((array) ($p['images'] ?? []), 'is_string'));
    $content = trim((string) ($p['content'] ?? ''));
    $desc    = mb_substr($content, 0, 3900);
    $pageUrl = trim((string) ($p['page_url'] ?? ''));
    if (!preg_match('#^https?://#i', $pageUrl)) {
        $pageUrl = defined('SITE_URL') ? SITE_URL : '';
    }

    $baseEmbed = [
        'title'       => '💡 Nouvelle proposition MDT',
        'description' => $desc !== '' ? $desc : '*(aucun détail)*',
        'color'       => 0x6C3FC5,
        'fields'      => [
            ['name' => '👤 Compte MDT', 'value' => ($p['username'] ?? '') !== '' ? (string) $p['username'] : 'Inconnu', 'inline' => true],
            ['name' => '🌐 Site',       'value' => $pageUrl !== '' ? $pageUrl : '—', 'inline' => false],
        ],
        'footer'      => ['text' => 'Proposition #' . ($p['id'] ?? '?')],
        'timestamp'   => date('c'),
    ];

    $embeds = [];
    if (!empty($images) && $pageUrl !== '') {
        // Galerie Discord : plusieurs embeds partageant la même `url`.
        $baseEmbed['url']   = $pageUrl;
        $baseEmbed['image'] = ['url' => $images[0]];
        if (count($images) > 4) {
            $extra = array_slice($images, 4);
            $baseEmbed['fields'][] = [
                'name'  => '📎 Autres images',
                'value' => implode("\n", array_map(static fn ($u) => '<' . $u . '>', $extra)),
                'inline' => false,
            ];
        }
        $embeds[] = $baseEmbed;
        foreach (array_slice($images, 1, 3) as $img) {
            $embeds[] = ['url' => $pageUrl, 'image' => ['url' => $img]];
        }
    } else {
        if (!empty($images)) {
            $baseEmbed['fields'][] = [
                'name'  => '📎 Images',
                'value' => implode("\n", array_map(static fn ($u) => '<' . $u . '>', $images)),
                'inline' => false,
            ];
        }
        $embeds[] = $baseEmbed;
    }

    $payload = json_encode(['username' => 'MDT · Propositions', 'embeds' => $embeds], JSON_UNESCAPED_SLASHES);
    if ($payload === false) return;

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) { /* silencieux */ }
}
