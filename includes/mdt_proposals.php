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

function mdt_proposals_status_valid(string $status): bool
{
    return array_key_exists($status, mdt_proposals_statuses());
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

/** Met à jour le statut d'une proposition (appelé par le rappel du bot NexioZ). */
function mdt_proposals_set_status(PDO $db, int $id, string $status): bool
{
    mdt_proposals_ensure_table($db);
    if ($id <= 0 || !mdt_proposals_status_valid($status)) {
        return false;
    }
    try {
        $chk = $db->prepare("SELECT 1 FROM mdt_feature_proposals WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) {
            return false;
        }
        $st = $db->prepare("UPDATE mdt_feature_proposals SET status = ? WHERE id = ?");
        $st->execute([$status, $id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 *  LIAISON NexioZ (bot Discord multi-serveur)
 *  La « clé de liaison » est un token auto-suffisant : nxz_base64url({u,k})
 *  où u = URL du bot et k = secret partagé.
 * ──────────────────────────────────────────────────────────────────────────── */

/** Token de liaison configuré (admin_settings), ou chaîne vide. */
function mdt_proposals_liaison_token(PDO $db): string
{
    try {
        $rows = mdt_admin_settings_fetch($db, ['nexioz_liaison_token']);
        return trim((string) ($rows['nexioz_liaison_token'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Décode une clé de liaison.
 * @return array{url:string,secret:string}|null
 */
function mdt_proposals_parse_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    if (strncmp($token, 'nxz_', 4) === 0) {
        $token = substr($token, 4);
    }
    $b64 = strtr($token, '-_', '+/');
    $mod = strlen($b64) % 4;
    if ($mod) {
        $b64 .= str_repeat('=', 4 - $mod);
    }
    $json = base64_decode($b64, true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    $u = rtrim((string) ($data['u'] ?? ''), '/');
    $k = (string) ($data['k'] ?? '');
    if ($u === '' || $k === '' || !preg_match('#^https?://#i', $u)) {
        return null;
    }
    return ['url' => $u, 'secret' => $k];
}

/**
 * Pousse une proposition vers le bot NexioZ (auth par secret de la clé).
 * @param array{id?:int|string,username?:string,content?:string,images?:string[]} $p
 */
function mdt_proposals_log(string $message): void
{
    error_log('[NexioZ] ' . $message);
}

function mdt_proposals_send_to_nexioz(PDO $db, array $p): bool
{
    if (!function_exists('curl_init')) {
        mdt_proposals_log('cURL indisponible sur ce serveur : envoi impossible.');
        return false;
    }

    $token = mdt_proposals_liaison_token($db);
    if ($token === '') {
        mdt_proposals_log('Aucune clé de liaison enregistrée (admin_settings.nexioz_liaison_token).');
        return false;
    }

    $parsed = mdt_proposals_parse_token($token);
    if ($parsed === null) {
        mdt_proposals_log('Clé de liaison illisible : format attendu nxz_<base64url>. Regénérez-la depuis le dashboard NexioZ.');
        return false;
    }

    $base = (defined('NEXIOZ_API_BASE') && NEXIOZ_API_BASE !== '')
        ? rtrim((string) NEXIOZ_API_BASE, '/')
        : $parsed['url'];

    // Seules les images en URL absolue sont exploitables par Discord.
    $images = array_values(array_filter(
        (array) ($p['images'] ?? []),
        static fn ($u) => is_string($u) && preg_match('#^https?://#i', $u) === 1
    ));

    $payload = json_encode([
        'secret'     => $parsed['secret'],
        'proposalId' => (string) ($p['id'] ?? ''),
        'author'     => (string) ($p['username'] ?? ''),
        'content'    => (string) ($p['content'] ?? ''),
        'images'     => $images,
        'site'       => defined('SITE_URL') ? SITE_URL : '',
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        mdt_proposals_log('Encodage JSON de la proposition impossible.');
        return false;
    }

    $endpoint = $base . '/api/mdt/proposals';

    try {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            mdt_proposals_log("Connexion au bot impossible ({$endpoint}) : {$err}");
            return false;
        }
        if ($code < 200 || $code >= 300) {
            mdt_proposals_log(
                "Bot joignable mais requête refusée ({$endpoint}) — HTTP {$code} : "
                . mb_substr((string) $body, 0, 500)
            );
            return false;
        }
        return true;
    } catch (Throwable $e) {
        mdt_proposals_log('Exception lors de l\'envoi : ' . $e->getMessage());
        return false;
    }
}

/**
 * Diagnostic de la liaison NexioZ : vérifie la clé, l'URL cible et la
 * joignabilité du bot. Utilisé par l'action `nexioz_test` de l'API.
 * @return array{ok:bool,steps:array<int,array{label:string,ok:bool,detail:string}>}
 */
function mdt_proposals_diagnose_nexioz(PDO $db): array
{
    $steps = [];
    $add = static function (array &$steps, string $label, bool $ok, string $detail): void {
        $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    };

    $add($steps, 'Extension cURL', function_exists('curl_init'), function_exists('curl_init') ? 'Disponible' : 'Absente');

    $token = mdt_proposals_liaison_token($db);
    $add($steps, 'Clé de liaison enregistrée', $token !== '', $token !== '' ? 'Présente' : 'Aucune clé enregistrée');
    if ($token === '') {
        return ['ok' => false, 'steps' => $steps];
    }

    $parsed = mdt_proposals_parse_token($token);
    $add($steps, 'Clé de liaison lisible', $parsed !== null, $parsed !== null ? 'Format valide' : 'Format invalide (attendu nxz_…)');
    if ($parsed === null) {
        return ['ok' => false, 'steps' => $steps];
    }

    $base = (defined('NEXIOZ_API_BASE') && NEXIOZ_API_BASE !== '')
        ? rtrim((string) NEXIOZ_API_BASE, '/')
        : $parsed['url'];
    $endpoint = $base . '/api/mdt/proposals';
    $add($steps, 'URL du bot', true, $endpoint);

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'steps' => $steps];
    }

    // Ping d'authentification : payload minimal, sans créer de proposition.
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'secret'     => $parsed['secret'],
            'proposalId' => 'diagnostic',
            'author'     => 'Diagnostic',
            'content'    => '',
            'images'     => [],
            'site'       => defined('SITE_URL') ? SITE_URL : '',
            'dryRun'     => true,
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        $add($steps, 'Bot joignable', false, $err);
        return ['ok' => false, 'steps' => $steps];
    }
    $add($steps, 'Bot joignable', true, 'HTTP ' . $code);

    $authOk = $code >= 200 && $code < 300;
    $add(
        $steps,
        'Authentification (clé acceptée)',
        $authOk,
        $authOk ? 'Clé reconnue par le bot' : 'Refusée — ' . mb_substr($body, 0, 300)
    );

    return ['ok' => $authOk, 'steps' => $steps];
}

/**
 * Notifie l'équipe (NexioZ prioritaire, sinon webhook Discord legacy).
 * @param array{id?:int,username?:string,content?:string,page_url?:string,images?:string[]} $p
 */
function mdt_proposals_notify(PDO $db, array $p): void
{
    $id = (string) ($p['id'] ?? '?');
    mdt_proposals_log("notify() appele pour la proposition #{$id}");

    if (mdt_proposals_liaison_token($db) !== '') {
        $ok = mdt_proposals_send_to_nexioz($db, $p);
        mdt_proposals_log("notify() #{$id} -> NexioZ : " . ($ok ? 'ENVOYE' : 'ECHEC'));
        return;
    }

    mdt_proposals_log("notify() #{$id} : aucune cle de liaison, repli sur le webhook legacy");
    $wh = mdt_proposals_webhook_url($db);
    if ($wh !== '') {
        mdt_proposals_send_webhook($wh, $p);
    }
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
