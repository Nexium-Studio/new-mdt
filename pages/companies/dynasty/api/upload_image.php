<?php
/**
 * pages/companies/dynasty/api/upload_image.php
 * ─────────────────────────────────────────────────────────────────────────
 * Import de fichiers image pour les biens Dynasty.
 * Enveloppe {ok:true,data:{urls:[…]}} / {ok:false,error}.
 *
 * Reçoit un ou plusieurs fichiers dans $_FILES['images'] (ou 'image'),
 * valide le type réel via finfo (PNG / JPG / JPEG / GIF / WEBP), enregistre sous
 * /uploads/dynasty/ avec un nom aléatoire, et renvoie les chemins relatifs.
 *
 * Sécurité : requireLogin + garde faction DYNASTY + permission stock.add
 * (identique à biens.php) + CSRF. Le type est déterminé par le contenu réel,
 * jamais par l'extension ou par le Content-Type déclaré par le client.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Session expirée, reconnectez-vous.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once ROOT_PATH . '/includes/faction_acl.php';
require_once dirname(__DIR__) . '/includes/zones_bootstrap.php';

const DY_FACTION = 'DYNASTY';

function up_json(int $code, array $p): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}
function up_ok(array $d): void  { up_json(200, ['ok' => true, 'data' => $d]); }
function up_err(string $m, int $c = 400): void { up_json($c, ['ok' => false, 'error' => $m]); }

/*
 * Tout le traitement est encapsulé : une exception non rattrapée renverrait
 * un 500 HTML illisible côté client. Ici, toute erreur ressort en JSON propre
 * et est journalisée pour diagnostic.
 */
try {
    $db  = Database::getInstance()->getConnection();
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    /* ── Garde de faction, même règle que biens.php ── */
    if (($_SESSION['user_faction'] ?? '') !== DY_FACTION
        && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])
        && !is_demo_mode()) {
        up_err('Accès refusé.', 403);
    }

    /* ── Permission : ajouter/gérer un bien (= stock.add, comme biens.php) ── */
    if (!faction_acl_can($db, $uid, DY_FACTION, 'stock.add')) {
        up_err("Permission requise : stock.add", 403);
    }

    /* ── CSRF (jeton partagé avec le module Zones) ── */
    if (!dynasty_zones_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        up_err('Session expirée, rechargez la page.', 419);
    }

    /* ── Récupération des fichiers (champ 'images' multiple ou 'image' simple) ── */
    $field = isset($_FILES['images']) ? 'images' : (isset($_FILES['image']) ? 'image' : null);
    if ($field === null) {
        // Souvent le signe d'un POST qui dépasse post_max_size (auquel cas $_POST
        // et $_FILES arrivent vides) : on le dit clairement.
        $hint = (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES)
            ? ' Le fichier dépasse peut-être la taille autorisée par le serveur (post_max_size).'
            : '';
        up_err('Aucun fichier reçu.' . $hint, 400);
    }

    $f = $_FILES[$field];
    // Normalisation : toujours travailler avec des tableaux indexés.
    $names = is_array($f['name']) ? $f['name'] : [$f['name']];
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
    $sizes = is_array($f['size']) ? $f['size'] : [$f['size']];
    $errs  = is_array($f['error']) ? $f['error'] : [$f['error']];

    if (count($names) > 24) {
        up_err('Trop de fichiers (24 maximum par envoi).');
    }

    /* ── Dossier de destination : création robuste (uploads/ puis uploads/dynasty/) ── */
    $root   = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
    $relDir = '/uploads/dynasty';
    $absDir = $root . $relDir;
    if (!is_dir($absDir)) {
        @mkdir($root . '/uploads', 0775, true);
        @mkdir($absDir, 0775, true);
    }
    if (!is_dir($absDir)) {
        up_err("Le dossier d'upload « /uploads/dynasty » n'existe pas et n'a pas pu être créé. "
             . "Créez-le sur le serveur.", 500);
    }
    // Tentative d'auto-réparation : si le dossier appartient au même utilisateur
    // que PHP mais avec de mauvais droits, chmod suffit à le rendre inscriptible.
    if (!is_writable($absDir)) {
        @chmod($absDir, 0775);
        clearstatcache(true, $absDir);
    }
    if (!is_writable($absDir)) {
        up_err("Le dossier « /uploads/dynasty » n'est pas inscriptible par le serveur web. "
             . "Sur le serveur : donnez-lui les droits d'écriture (chmod 775), ou supprimez-le "
             . "pour que le site le recrée automatiquement au bon propriétaire.", 500);
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $maxSize = 6 * 1024 * 1024; // 6 Mo par fichier

    $urls  = [];
    $refus = [];

    foreach ($names as $i => $nom) {
        $nom = (string) $nom;
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) {
            $refus[] = $nom . ' (erreur de transfert #' . (int) $errs[$i] . ')';
            continue;
        }
        if (($sizes[$i] ?? 0) > $maxSize) {
            $refus[] = $nom . ' (trop volumineux, 6 Mo max)';
            continue;
        }
        if (!is_uploaded_file($tmps[$i])) {
            $refus[] = $nom . ' (source invalide)';
            continue;
        }

        // Type réel, déterminé par le CONTENU. getimagesize lit les octets
        // d'en-tête et ne dépend d'aucune extension PHP (contrairement à finfo,
        // pas garanti présent) : un fichier texte renommé .png est rejeté.
        $imgInfo = @getimagesize($tmps[$i]);
        $mime    = ($imgInfo && isset($imgInfo['mime'])) ? strtolower((string) $imgInfo['mime']) : '';

        // Contrôle supplémentaire via finfo quand disponible : les deux concordent.
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m2 = strtolower((string) @finfo_file($fi, $tmps[$i]));
                @finfo_close($fi);
                if ($m2 !== '' && $m2 !== $mime) {
                    $mime = '';
                }
            }
        }

        if (!isset($allowed[$mime])) {
            $refus[] = $nom . ' (format refusé : PNG, JPG, JPEG, GIF ou WEBP seulement)';
            continue;
        }
        $ext  = $allowed[$mime];
        $name = 'bien_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($tmps[$i], $absDir . '/' . $name)) {
            $refus[] = $nom . ' (échec d\'enregistrement sur le serveur)';
            continue;
        }
        @chmod($absDir . '/' . $name, 0644);
        $urls[] = $relDir . '/' . $name;
    }

    if (!$urls) {
        up_err($refus ? implode(' · ', $refus) : 'Aucune image valide.', 422);
    }

    up_ok(['urls' => $urls, 'refuses' => $refus]);
} catch (Throwable $e) {
    error_log('[Dynasty Upload] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    up_err('Erreur serveur : ' . $e->getMessage(), 500);
}
