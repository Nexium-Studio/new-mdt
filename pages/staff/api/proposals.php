<?php
/**
 * API — Propositions d'ajout MDT (dashboard staff).
 * Actions : submit (créer + webhook), list (historique perso), set_webhook (admin).
 */

require_once __DIR__ . '/../../../config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit;
}

$staffRoles = ['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'];
if (!hasRole($staffRoles)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']);
    exit;
}

require_once ROOT_PATH . '/includes/mdt_proposals.php';

$db     = Database::getInstance()->getConnection();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);

/** Formate une proposition pour le client. */
function mdt_prop_format(array $r): array
{
    $meta = mdt_proposals_status_meta((string) ($r['status'] ?? 'pending'));
    return [
        'id'         => (int) $r['id'],
        'content'    => (string) $r['content'],
        'images'     => $r['images_arr'] ?? [],
        'status'     => (string) ($r['status'] ?? 'pending'),
        'statusLabel' => $meta['label'],
        'statusColor' => $meta['color'],
        'statusIcon' => $meta['icon'],
        'adminNote'  => (string) ($r['admin_note'] ?? ''),
        'createdAt'  => (string) ($r['created_at'] ?? ''),
    ];
}

// ── Soumettre une proposition ────────────────────────────────────────────────
if ($action === 'submit') {
    $content = (string) ($_POST['content'] ?? '');
    if (trim($content) === '') {
        echo json_encode(['ok' => false, 'error' => 'Merci de détailler votre proposition.']);
        exit;
    }

    // URL de la page d'origine (envoyée par le client), validée.
    $pageUrl = trim((string) ($_POST['page_url'] ?? ''));
    if ($pageUrl === '' || !filter_var($pageUrl, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        $pageUrl = SITE_URL . '/pages/staff/dashboard.php';
    }

    // Images (input multiple name="images[]").
    $imageUrls = [];
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
        $files = $_FILES['images'];
        $count = count($files['name']);
        for ($i = 0; $i < $count && count($imageUrls) < MDT_PROPOSAL_MAX_IMAGES; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $one = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            $res = mdt_proposals_store_image($one);
            if ($res['success']) $imageUrls[] = $res['url'];
        }
    }

    // Pseudo du compte MDT.
    $username = '';
    try {
        $st = $db->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $username = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    if ($username === '') $username = mdt_session_display_name();

    $res = mdt_proposals_create($db, $userId, $username, $content, $imageUrls, $pageUrl);
    if (!$res['success']) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Erreur']);
        exit;
    }

    // Notification : bot NexioZ (liaison) prioritaire, sinon webhook Discord legacy.
    mdt_proposals_notify($db, [
        'id'       => $res['id'],
        'username' => $username,
        'content'  => $content,
        'page_url' => $pageUrl,
        'images'   => $imageUrls,
    ]);

    if (function_exists('mdt_audit_log')) {
        try {
            mdt_audit_log($userId, 'STAFF', 'Proposition MDT #' . $res['id'], mb_substr($content, 0, 240), 'system');
        } catch (Throwable $e) {}
    }

    // Renvoyer l'historique à jour.
    $rows = array_map('mdt_prop_format', mdt_proposals_list_for_user($db, $userId));
    echo json_encode(['ok' => true, 'id' => $res['id'], 'proposals' => $rows]);
    exit;
}

// ── Historique perso ─────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = array_map('mdt_prop_format', mdt_proposals_list_for_user($db, $userId));
    echo json_encode(['ok' => true, 'proposals' => $rows]);
    exit;
}

// ── Configurer la liaison NexioZ (admin) ─────────────────────────────────────
if ($action === 'set_liaison') {
    if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Réservé aux administrateurs']);
        exit;
    }
    $token = trim((string) ($_POST['token'] ?? ''));
    if ($token !== '' && mdt_proposals_parse_token($token) === null) {
        echo json_encode(['ok' => false, 'error' => 'Clé de liaison invalide']);
        exit;
    }
    try {
        mdt_admin_settings_upsert($db, 'nexioz_liaison_token', $token);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => "Impossible d'enregistrer la clé"]);
    }
    exit;
}

// ── Diagnostic de la liaison NexioZ (admin) ──────────────────────────────────
if ($action === 'nexioz_test') {
    if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Réservé aux administrateurs']);
        exit;
    }
    $diag = mdt_proposals_diagnose_nexioz($db);
    echo json_encode(['ok' => $diag['ok'], 'steps' => $diag['steps']]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
