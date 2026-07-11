<?php
/**
 * AJAX endpoints pour la page de configuration des pages d'accueil Académie.
 * Toutes les actions transitent par ce fichier (POST + CSRF + faction_key).
 *
 * Actions supportées :
 *   config_save  | image_upload | image_add_url | image_update | image_delete | image_reorder
 *   faq_save     | faq_delete   | faq_toggle
 *   ann_save     | ann_delete   | ann_toggle
 */
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../../config/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']); exit;
}
if (empty($_SESSION['staff_academy_csrf']) || !hash_equals($_SESSION['staff_academy_csrf'], $_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF invalide']); exit;
}

$action = (string)($_POST['action'] ?? '');
$fk     = (string)($_POST['faction_key'] ?? '');
if (!academy_faction_exists($fk)) {
    echo json_encode(['success' => false, 'error' => 'Faction invalide']); exit;
}

$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id'] ?? 0);

try {
    switch ($action) {

        // ── PRÉSENTATION ────────────────────────────────────────────────────
        case 'config_save': {
            $stmt = $db->prepare("INSERT INTO academy_homepage_config
                (faction_key, hero_title, hero_subtitle, presentation_text, cta_primary_label, cta_secondary_label, accent_color, motto, is_published, updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  hero_title          = VALUES(hero_title),
                  hero_subtitle       = VALUES(hero_subtitle),
                  presentation_text   = VALUES(presentation_text),
                  cta_primary_label   = VALUES(cta_primary_label),
                  cta_secondary_label = VALUES(cta_secondary_label),
                  accent_color        = VALUES(accent_color),
                  motto               = VALUES(motto),
                  is_published        = VALUES(is_published),
                  updated_by          = VALUES(updated_by)");
            $accent = trim((string)($_POST['accent_color'] ?? ''));
            if ($accent !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) $accent = '';
            $stmt->execute([
                $fk,
                mb_substr(trim((string)($_POST['hero_title']    ?? '')), 0, 255),
                mb_substr(trim((string)($_POST['hero_subtitle'] ?? '')), 0, 255),
                academy_sanitize_html((string)($_POST['presentation_text'] ?? '')),
                mb_substr(trim((string)($_POST['cta_primary_label']   ?? 'Déposer une candidature')), 0, 64),
                mb_substr(trim((string)($_POST['cta_secondary_label'] ?? 'Passer le QCM')),           0, 64),
                $accent !== '' ? $accent : null,
                mb_substr(trim((string)($_POST['motto'] ?? '')), 0, 255),
                !empty($_POST['is_published']) ? 1 : 0,
                $uid,
            ]);
            echo json_encode(['success' => true]); exit;
        }

        // ── IMAGES SLIDER ───────────────────────────────────────────────────
        case 'image_upload': {
            if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
                $errs = [
                    UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (limite serveur upload_max_filesize)',
                    UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite formulaire)',
                    UPLOAD_ERR_PARTIAL    => 'Upload incomplet, réessayez',
                    UPLOAD_ERR_NO_FILE    => 'Aucun fichier fourni',
                    UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant côté serveur',
                    UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier temporaire',
                    UPLOAD_ERR_EXTENSION  => 'Upload bloqué par une extension PHP',
                ];
                throw new Exception($errs[$code] ?? 'Erreur d\'upload (code ' . $code . ')');
            }
            $f = $_FILES['image'];
            if ($f['size'] > 4 * 1024 * 1024) throw new Exception('Fichier trop volumineux (4 Mo max)');
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime  = $finfo ? finfo_file($finfo, $f['tmp_name']) : ($f['type'] ?? '');
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($allowed[$mime])) throw new Exception('Format non supporté (JPG, PNG, WEBP, GIF uniquement)');
            $ext = $allowed[$mime];
            $dir = academy_upload_dir($fk);
            if (!is_dir($dir['abs']) || !is_writable($dir['abs'])) throw new Exception('Dossier de destination non inscriptible : ' . $dir['abs']);
            $name = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!@move_uploaded_file($f['tmp_name'], $dir['abs'] . '/' . $name)) throw new Exception('Impossible d\'enregistrer le fichier');
            $rel = $dir['rel'] . '/' . $name;
            $next = (int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM academy_homepage_images WHERE faction_key = ' . $db->quote($fk))->fetchColumn();
            $stmt = $db->prepare('INSERT INTO academy_homepage_images (faction_key, image_path, sort_order, is_active, uploaded_by) VALUES (?,?,?,1,?)');
            $stmt->execute([$fk, $rel, $next, $uid]);
            $id = (int)$db->lastInsertId();
            echo json_encode([
                'success' => true,
                'image'   => [
                    'id'         => $id,
                    'image_path' => $rel,
                    'src'        => academy_image_src($rel),
                    'caption'    => '',
                    'sort_order' => $next,
                    'is_active'  => 1,
                ],
            ]); exit;
        }

        case 'image_add_url': {
            $url = trim((string)($_POST['url'] ?? ''));
            if ($url === '') throw new Exception('URL manquante');
            if (!preg_match('#^https?://#i', $url)) throw new Exception('URL invalide (http:// ou https:// requis)');
            if (mb_strlen($url) > 512) throw new Exception('URL trop longue (512 caractères max)');
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new Exception('URL invalide');
            // Vérification optionnelle de l'extension dans l'URL
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $okExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'];
            if ($ext !== '' && !in_array($ext, $okExt, true)) {
                // On laisse passer si pas d'extension dans l'URL (CDN dynamique), sinon on bloque
                throw new Exception('Extension non supportée (utilisez JPG, PNG, WEBP, GIF, AVIF ou SVG)');
            }
            $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 255);
            $next = (int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM academy_homepage_images WHERE faction_key = ' . $db->quote($fk))->fetchColumn();
            $stmt = $db->prepare('INSERT INTO academy_homepage_images (faction_key, image_path, caption, sort_order, is_active, uploaded_by) VALUES (?,?,?,?,1,?)');
            $stmt->execute([$fk, $url, $caption, $next, $uid]);
            $id = (int)$db->lastInsertId();
            echo json_encode([
                'success' => true,
                'image'   => [
                    'id'         => $id,
                    'image_path' => $url,
                    'src'        => $url,
                    'caption'    => $caption,
                    'sort_order' => $next,
                    'is_active'  => 1,
                ],
            ]); exit;
        }

        case 'image_update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID manquant');
            $sets = [];
            $vals = [];
            if (isset($_POST['caption'])) {
                $sets[] = 'caption = ?';
                $vals[] = mb_substr(trim((string)$_POST['caption']), 0, 255);
            }
            if (isset($_POST['is_active'])) {
                $sets[] = 'is_active = ?';
                $vals[] = (int)!!(int)$_POST['is_active'];
            }
            if (empty($sets)) throw new Exception('Rien à mettre à jour');
            $vals[] = $id; $vals[] = $fk;
            $db->prepare('UPDATE academy_homepage_images SET ' . implode(',', $sets) . ' WHERE id = ? AND faction_key = ?')->execute($vals);
            echo json_encode(['success' => true]); exit;
        }

        case 'image_delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID manquant');
            $row = $db->prepare('SELECT image_path FROM academy_homepage_images WHERE id = ? AND faction_key = ? LIMIT 1');
            $row->execute([$id, $fk]);
            $img = $row->fetch(PDO::FETCH_ASSOC);
            // On ne supprime physiquement que les fichiers locaux (pas les URL externes)
            if ($img && !preg_match('#^https?://#i', $img['image_path'])) {
                $base = realpath(__DIR__ . '/../../../../..');
                if ($base !== false) {
                    $abs = $base . $img['image_path'];
                    if (is_file($abs)) @unlink($abs);
                }
            }
            $db->prepare('DELETE FROM academy_homepage_images WHERE id = ? AND faction_key = ?')->execute([$id, $fk]);
            echo json_encode(['success' => true]); exit;
        }

        case 'image_reorder': {
            $order = json_decode((string)($_POST['order'] ?? '[]'), true);
            if (!is_array($order)) throw new Exception('Ordre invalide');
            $stmt = $db->prepare('UPDATE academy_homepage_images SET sort_order = ? WHERE id = ? AND faction_key = ?');
            $i = 1;
            foreach ($order as $id) {
                $id = (int)$id;
                if ($id > 0) $stmt->execute([$i++, $id, $fk]);
            }
            echo json_encode(['success' => true]); exit;
        }

        // ── FAQ ─────────────────────────────────────────────────────────────
        case 'faq_save': {
            $id   = (int)($_POST['id'] ?? 0);
            $q    = mb_substr(trim((string)($_POST['question'] ?? '')), 0, 255);
            $a    = academy_sanitize_html((string)($_POST['answer'] ?? ''));
            $ord  = (int)($_POST['sort_order'] ?? 0);
            $act  = (int)!!(int)($_POST['is_active'] ?? 1);
            if ($q === '' || trim(strip_tags($a)) === '') throw new Exception('Question et réponse requises');
            if ($id > 0) {
                $db->prepare('UPDATE academy_homepage_faq SET question=?, answer=?, sort_order=?, is_active=? WHERE id=? AND faction_key=?')
                   ->execute([$q, $a, $ord, $act, $id, $fk]);
            } else {
                $db->prepare('INSERT INTO academy_homepage_faq (faction_key, question, answer, sort_order, is_active) VALUES (?,?,?,?,?)')
                   ->execute([$fk, $q, $a, $ord, $act]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]); exit;
        }

        case 'faq_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_homepage_faq WHERE id=? AND faction_key=?')->execute([$id, $fk]);
            echo json_encode(['success' => true]); exit;
        }

        case 'faq_toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE academy_homepage_faq SET is_active = 1 - is_active WHERE id=? AND faction_key=?')->execute([$id, $fk]);
            echo json_encode(['success' => true]); exit;
        }

        // ── ANNONCES ────────────────────────────────────────────────────────
        case 'ann_save': {
            $kinds = array_keys(academy_announcement_kinds());
            $id    = (int)($_POST['id'] ?? 0);
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
            $desc  = trim((string)($_POST['description'] ?? ''));
            $kind  = (string)($_POST['kind'] ?? 'info');
            if (!in_array($kind, $kinds, true)) $kind = 'info';
            $icon  = mb_substr(trim((string)($_POST['icon'] ?? '')), 0, 64);
            if ($icon === '') $icon = academy_announcement_kinds()[$kind]['icon'];
            $ord   = (int)($_POST['sort_order'] ?? 0);
            $link  = mb_substr(trim((string)($_POST['link_url'] ?? '')), 0, 512);
            $lab   = mb_substr(trim((string)($_POST['link_label'] ?? '')), 0, 64);
            $act   = (int)!!(int)($_POST['is_active'] ?? 1);
            $exp   = trim((string)($_POST['expires_at'] ?? ''));
            $expSql = null;
            if ($exp !== '') {
                $ts = strtotime(str_replace('T', ' ', $exp));
                if ($ts) $expSql = date('Y-m-d H:i:s', $ts);
            }
            if ($title === '') throw new Exception('Titre requis');
            if ($id > 0) {
                $db->prepare('UPDATE academy_homepage_announcements
                              SET title=?, description=?, kind=?, icon=?, sort_order=?, link_url=?, link_label=?, expires_at=?, is_active=?
                              WHERE id=? AND faction_key=?')
                   ->execute([$title, $desc, $kind, $icon, $ord, $link, $lab, $expSql, $act, $id, $fk]);
            } else {
                $db->prepare('INSERT INTO academy_homepage_announcements
                              (faction_key, title, description, kind, icon, sort_order, link_url, link_label, expires_at, is_active, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$fk, $title, $desc, $kind, $icon, $ord, $link, $lab, $expSql, $act, $uid]);
                $id = (int)$db->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]); exit;
        }

        case 'ann_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_homepage_announcements WHERE id=? AND faction_key=?')->execute([$id, $fk]);
            echo json_encode(['success' => true]); exit;
        }

        case 'ann_toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE academy_homepage_announcements SET is_active = 1 - is_active WHERE id=? AND faction_key=?')->execute([$id, $fk]);
            echo json_encode(['success' => true]); exit;
        }

        default:
            throw new Exception('Action inconnue : ' . $action);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
