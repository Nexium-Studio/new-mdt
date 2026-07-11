<?php
// pages/mail/api.php — API AJAX du système de messagerie
require_once dirname(__DIR__, 2) . '/config/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function jsonOk(mixed $data = []): void  { echo json_encode(['ok'=>true,  'data'=>$data]); exit; }
function jsonErr(string $msg): void       { echo json_encode(['ok'=>false, 'error'=>$msg]); exit; }

// ── Helpers ──────────────────────────────────────────────────
function getAccountOrFail(PDO $db, int $userId, int $accountId): array {
    $st = $db->prepare("
        SELECT a.* FROM mail_accounts a
        JOIN mail_account_links l ON l.account_id = a.id
        WHERE l.user_id = ? AND a.id = ? AND a.is_active = 1
    ");
    $st->execute([$userId, $accountId]);
    $acc = $st->fetch();
    if (!$acc) jsonErr('Compte introuvable ou accès refusé.');
    return $acc;
}

function resolveAddress(PDO $db, string $addr): ?int {
    $st = $db->prepare("SELECT id FROM mail_accounts WHERE address = ? AND is_active = 1");
    $st->execute([$addr]);
    return $st->fetchColumn() ?: null;
}

function unreadCount(PDO $db, int $accountId): int {
    return (int)$db->prepare("
        SELECT COUNT(*) FROM mail_boxes
        WHERE account_id = ? AND folder = 'inbox' AND is_read = 0 AND deleted_at IS NULL
    ")->execute([$accountId]) ? $db->prepare("
        SELECT COUNT(*) FROM mail_boxes
        WHERE account_id = ? AND folder = 'inbox' AND is_read = 0 AND deleted_at IS NULL
    ")->execute([$accountId]) : 0;
}

switch ($action) {

    // ── Mes comptes ─────────────────────────────────────────
    case 'get_accounts': {
        $st = $db->prepare("
            SELECT a.*, (
                SELECT COUNT(*) FROM mail_boxes mb
                WHERE mb.account_id = a.id AND mb.folder='inbox' AND mb.is_read=0 AND mb.deleted_at IS NULL
            ) as unread
            FROM mail_accounts a
            JOIN mail_account_links l ON l.account_id = a.id
            WHERE l.user_id = ? AND a.is_active = 1
            ORDER BY a.created_at ASC
        ");
        $st->execute([$userId]);
        jsonOk($st->fetchAll());
    }

    // ── Compteurs dossiers ───────────────────────────────────
    case 'get_folder_counts': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);
        $st = $db->prepare("
            SELECT folder, COUNT(*) as total,
                   SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) as unread
            FROM mail_boxes
            WHERE account_id = ? AND deleted_at IS NULL
            GROUP BY folder
        ");
        $st->execute([$accountId]);
        $rows = $st->fetchAll();
        $counts = [];
        foreach ($rows as $r) $counts[$r['folder']] = ['total'=>(int)$r['total'],'unread'=>(int)$r['unread']];
        jsonOk($counts);
    }

    // ── Liste messages ───────────────────────────────────────
    case 'get_messages': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $folder    = $_GET['folder'] ?? 'inbox';
        $search    = trim($_GET['search'] ?? '');
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $perPage   = 25;
        $offset    = ($page - 1) * $perPage;

        getAccountOrFail($db, $userId, $accountId);

        $where = "mb.account_id = :aid AND mb.folder = :folder AND mb.deleted_at IS NULL";
        $params = ['aid'=>$accountId, 'folder'=>$folder];
        if ($search) {
            $where .= " AND (m.subject LIKE :s OR m.from_address LIKE :s2 OR m.body LIKE :s3)";
            $params['s'] = $params['s2'] = $params['s3'] = "%{$search}%";
        }

        $total = $db->prepare("SELECT COUNT(*) FROM mail_boxes mb JOIN mail_messages m ON m.id=mb.message_id WHERE $where");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $st = $db->prepare("
            SELECT m.id, m.subject, m.from_address, m.from_name, m.sent_at, m.created_at, m.type,
                   mb.is_read, mb.is_starred, mb.folder,
                   LEFT(REGEXP_REPLACE(m.body,'<[^>]+>',''),120) as preview,
                   sender.logo as from_logo
            FROM mail_boxes mb
            JOIN mail_messages m ON m.id = mb.message_id
            LEFT JOIN mail_accounts sender ON sender.address = m.from_address
            WHERE $where
            ORDER BY COALESCE(m.sent_at, m.created_at) DESC
            LIMIT :lim OFFSET :off
        ");
        $params['lim'] = $perPage;
        $params['off'] = $offset;
        $st->execute($params);

        jsonOk(['messages'=>$st->fetchAll(),'total'=>$totalCount,'page'=>$page,'per_page'=>$perPage]);
    }

    // ── Lire un message ──────────────────────────────────────
    case 'get_message': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $messageId = (int)($_GET['message_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);

        // Marquer lu
        $db->prepare("UPDATE mail_boxes SET is_read=1 WHERE message_id=? AND account_id=?")
           ->execute([$messageId, $accountId]);

        $st = $db->prepare("
            SELECT m.*, mb.is_read, mb.is_starred, mb.folder,
                   sender.logo as from_logo
            FROM mail_messages m
            JOIN mail_boxes mb ON mb.message_id=m.id
            LEFT JOIN mail_accounts sender ON sender.address = m.from_address
            WHERE m.id = ? AND mb.account_id = ?
        ");
        $st->execute([$messageId, $accountId]);
        $msg = $st->fetch();
        if (!$msg) jsonErr('Message introuvable.');

        // Fil de réponses
        $thread = [];
        if ($msg['thread_id']) {
            $st2 = $db->prepare("
                SELECT m.*, mb.is_read, sender.logo as from_logo FROM mail_messages m
                LEFT JOIN mail_boxes mb ON mb.message_id=m.id AND mb.account_id=?
                LEFT JOIN mail_accounts sender ON sender.address = m.from_address
                WHERE m.thread_id = ? ORDER BY COALESCE(m.sent_at,m.created_at) ASC
            ");
            $st2->execute([$accountId, $msg['thread_id']]);
            $thread = $st2->fetchAll();
        }

        jsonOk(['message'=>$msg,'thread'=>$thread]);
    }

    // ── Envoyer / sauver brouillon ───────────────────────────
    case 'send':
    case 'save_draft': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $acc       = getAccountOrFail($db, $userId, $accountId);

        $to      = json_decode($_POST['to']      ?? '[]', true) ?: [];
        $cc      = json_decode($_POST['cc']      ?? '[]', true) ?: [];
        $bcc     = json_decode($_POST['bcc']     ?? '[]', true) ?: [];
        $subject = trim($_POST['subject'] ?? '') ?: '(sans objet)';
        $body    = $_POST['body'] ?? '';
        $replyId = (int)($_POST['reply_to_id'] ?? 0) ?: null;
        $type    = in_array($_POST['type']??'', ['reply','forward']) ? $_POST['type'] : 'normal';
        $isDraft = ($action === 'save_draft');

        if (!$isDraft && empty($to)) jsonErr('Destinataire requis.');

        // Trouver ou créer le thread
        $threadId = null;
        if ($replyId) {
            $pt = $db->prepare("SELECT thread_id FROM mail_messages WHERE id=?");
            $pt->execute([$replyId]);
            $threadId = $pt->fetchColumn() ?: null;
        }
        if (!$threadId && !$isDraft) {
            $db->prepare("INSERT INTO mail_threads (subject) VALUES (?)")->execute([$subject]);
            $threadId = $db->lastInsertId();
        }

        // Insérer le message
        $sentAt = $isDraft ? null : date('Y-m-d H:i:s');
        $db->prepare("
            INSERT INTO mail_messages
            (thread_id, from_account_id, from_address, from_name, to_addresses, cc_addresses, bcc_addresses, subject, body, body_type, reply_to_id, type, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,'html',?,?,?)
        ")->execute([
            $threadId, $accountId, $acc['address'], $acc['display_name'] ?: $acc['address'],
            json_encode($to), json_encode($cc), json_encode($bcc),
            $subject, $body, $replyId, $type, $sentAt
        ]);
        $msgId = $db->lastInsertId();

        if ($isDraft) {
            // Boîte expéditeur — drafts
            $db->prepare("INSERT INTO mail_boxes (message_id,account_id,folder,is_read) VALUES (?,?,'drafts',1)")
               ->execute([$msgId, $accountId]);
            jsonOk(['message_id'=>$msgId]);
        }

        // Boîte expéditeur — sent
        $db->prepare("INSERT INTO mail_boxes (message_id,account_id,folder,is_read) VALUES (?,?,'sent',1)")
           ->execute([$msgId, $accountId]);

        // Livrer aux destinataires
        $allTo = array_merge($to, $cc, $bcc);
        foreach ($allTo as $addr) {
            $recvId = resolveAddress($db, trim($addr));
            if ($recvId && $recvId !== $accountId) {
                $db->prepare("INSERT IGNORE INTO mail_boxes (message_id,account_id,folder) VALUES (?,?,'inbox')")
                   ->execute([$msgId, $recvId]);
                // Notification
                $recvUser = $db->prepare("SELECT user_id FROM mail_accounts WHERE id=?");
                $recvUser->execute([$recvId]);
                $rUid = $recvUser->fetchColumn();
                if ($rUid) {
                    $db->prepare("INSERT INTO mail_notifications (user_id,account_id,message_id) VALUES (?,?,?)")
                       ->execute([$rUid, $recvId, $msgId]);
                }
                // Update thread
                if ($threadId) {
                    $db->prepare("UPDATE mail_threads SET last_message_at=NOW() WHERE id=?")
                       ->execute([$threadId]);
                }
            }
        }

        jsonOk(['message_id'=>$msgId]);
    }

    // ── Déplacer vers dossier ────────────────────────────────
    case 'move': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $messageId = (int)($_POST['message_id'] ?? 0);
        $folder    = $_POST['folder'] ?? 'inbox';
        getAccountOrFail($db, $userId, $accountId);
        $valid = ['inbox','sent','drafts','trash','spam','starred'];
        if (!in_array($folder, $valid)) jsonErr('Dossier invalide.');
        $db->prepare("UPDATE mail_boxes SET folder=? WHERE message_id=? AND account_id=?")
           ->execute([$folder, $messageId, $accountId]);
        jsonOk();
    }

    // ── Corbeille ────────────────────────────────────────────
    case 'trash': {
        $accountId  = (int)($_POST['account_id'] ?? 0);
        $messageIds = json_decode($_POST['message_ids'] ?? '[]', true) ?: [];
        getAccountOrFail($db, $userId, $accountId);
        foreach ($messageIds as $mid) {
            $db->prepare("UPDATE mail_boxes SET folder='trash', deleted_at=NOW() WHERE message_id=? AND account_id=?")
               ->execute([(int)$mid, $accountId]);
        }
        jsonOk();
    }

    // ── Restaurer ────────────────────────────────────────────
    case 'restore': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $messageId = (int)($_POST['message_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);
        $db->prepare("UPDATE mail_boxes SET folder='inbox', deleted_at=NULL WHERE message_id=? AND account_id=?")
           ->execute([$messageId, $accountId]);
        jsonOk();
    }

    // ── Supprimer définitivement ──────────────────────────────
    case 'delete': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $messageId = (int)($_POST['message_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);
        $db->prepare("DELETE FROM mail_boxes WHERE message_id=? AND account_id=?")
           ->execute([$messageId, $accountId]);
        jsonOk();
    }

    // ── Marquer lu/non-lu ────────────────────────────────────
    case 'mark_read': {
        $accountId  = (int)($_POST['account_id'] ?? 0);
        $messageIds = json_decode($_POST['message_ids'] ?? '[]', true) ?: [];
        $read       = (int)($_POST['read'] ?? 1);
        getAccountOrFail($db, $userId, $accountId);
        foreach ($messageIds as $mid) {
            $db->prepare("UPDATE mail_boxes SET is_read=? WHERE message_id=? AND account_id=?")
               ->execute([$read, (int)$mid, $accountId]);
        }
        jsonOk();
    }

    // ── Étoile ───────────────────────────────────────────────
    case 'toggle_star': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $messageId = (int)($_POST['message_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);
        $db->prepare("UPDATE mail_boxes SET is_starred = 1-is_starred WHERE message_id=? AND account_id=?")
           ->execute([$messageId, $accountId]);
        jsonOk();
    }

    // ── Vérifier une adresse avant connexion (pour UI dynamique) ──
    case 'check_address_for_link': {
        $address = strtolower(trim($_POST['address'] ?? ''));
        if (!$address) jsonErr('Adresse requise.');
        $st = $db->prepare("SELECT id, user_id, mail_password FROM mail_accounts WHERE address=? AND is_active=1");
        $st->execute([$address]);
        $acc = $st->fetch();
        if (!$acc) jsonErr('Adresse introuvable.');
        jsonOk([
            'is_owner'     => ((int)$acc['user_id'] === $userId),
            'has_password' => !empty($acc['mail_password']),
        ]);
    }

    // ── Lier un compte (multi-boîte) ──────────────────────────
    case 'link_account': {
        $address  = strtolower(trim($_POST['address'] ?? ''));
        $password = trim($_POST['password'] ?? '');
        if (!$address) jsonErr('Adresse requise.');

        $st = $db->prepare("SELECT id, user_id, mail_password FROM mail_accounts WHERE address=? AND is_active=1");
        $st->execute([$address]);
        $acc = $st->fetch();
        if (!$acc) jsonErr('Adresse introuvable ou désactivée.');

        $isOwner  = ((int)$acc['user_id'] === $userId);
        $hasDbPwd = !empty($acc['mail_password']);

        if ($isOwner) {
            // Propriétaire -> connexion directe sans mot de passe
        } elseif ($hasDbPwd && $password !== '') {
            if (!password_verify($password, $acc['mail_password'])) jsonErr('Mot de passe incorrect.');
        } elseif ($hasDbPwd && $password === '') {
            jsonErr('Ce compte est protégé. Entrez le mot de passe.');
        } else {
            jsonErr("Cette adresse ne vous appartient pas et n'a pas de mot de passe défini.");
        }

        $db->prepare("INSERT IGNORE INTO mail_account_links (user_id,account_id) VALUES (?,?)")
           ->execute([$userId, $acc['id']]);
        jsonOk(['account_id' => $acc['id'], 'address' => $address]);
    }

    // ── Déconnecter un compte ────────────────────────────────
    case 'unlink_account': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        // Ne pas supprimer le compte principal (créé par cet user)
        $isOwner = $db->prepare("SELECT id FROM mail_accounts WHERE id=? AND user_id=?");
        $isOwner->execute([$accountId, $userId]);
        if ($isOwner->fetchColumn()) jsonErr('Impossible de déconnecter votre compte principal depuis ici.');
        $db->prepare("DELETE FROM mail_account_links WHERE user_id=? AND account_id=?")
           ->execute([$userId, $accountId]);
        jsonOk();
    }

    // ── Créer mon compte mail perso ───────────────────────────
    case 'create_account': {
        // Vérif quota
        $cnt = $db->prepare("SELECT COUNT(*) FROM mail_accounts WHERE user_id=? AND is_active=1");
        $cnt->execute([$userId]);
        $serverMax = (int)($db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_max_per_user'")->fetchColumn() ?: 3);
        if ((int)$cnt->fetchColumn() >= $serverMax) jsonErr("Maximum {$serverMax} comptes perso atteint.");

        $serverName = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_server_name'")->fetchColumn() ?: 'lysoria';
        $tld        = in_array($_POST['tld']??'fr',['fr','com','net','store']) ? $_POST['tld'] : 'fr';
        $localPart  = preg_replace('/[^a-z0-9._\-]/', '', strtolower($_POST['local'] ?? ''));
        if (strlen($localPart) < 3) jsonErr('Nom trop court (min 3 caractères).');
        if (strlen($localPart) > 40) jsonErr('Nom trop long (max 40 caractères).');

        $address = "{$localPart}@{$serverName}.{$tld}";
        $check = $db->prepare("SELECT id FROM mail_accounts WHERE address=?");
        $check->execute([$address]);
        if ($check->fetchColumn()) jsonErr('Cette adresse est déjà utilisée.');

        $user = $db->prepare("SELECT username FROM users WHERE id=?");
        $user->execute([$userId]);
        $uname = $user->fetchColumn();

        $db->prepare("
            INSERT INTO mail_accounts (user_id, address, display_name, domain, tld, type)
            VALUES (?,?,?,?,?,'personal')
        ")->execute([$userId, $address, $uname, "{$serverName}.{$tld}", $tld]);
        $newId = $db->lastInsertId();

        $db->prepare("INSERT IGNORE INTO mail_account_links (user_id,account_id) VALUES (?,?)")
           ->execute([$userId, $newId]);

        jsonOk(['account_id'=>$newId,'address'=>$address]);
    }

    // ── Sauver thème ────────────────────────────────────────
    // ── Upload logo compte ───────────────────────────────────
    case 'upload_logo': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);

        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonErr('Aucun fichier reçu.');
        }

        $file    = $_FILES['logo'];
        $maxSize = 2 * 1024 * 1024; // 2 Mo
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $exts    = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

        // Vérifications
        if ($file['size'] > $maxSize) jsonErr('Fichier trop lourd (max 2 Mo).');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) jsonErr('Format non supporté (JPG, PNG, GIF, WEBP uniquement).');

        // Dossier upload
        $uploadDir = dirname(__DIR__, 2) . '/assets/mail_logos/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) jsonErr('Impossible de créer le dossier d\'upload.');
        }

        // Supprimer l'ancien logo si existant
        $old = $db->prepare("SELECT logo FROM mail_accounts WHERE id=?")->execute([$accountId]);
        $oldLogo = $db->query("SELECT logo FROM mail_accounts WHERE id=$accountId")->fetchColumn();
        if ($oldLogo) {
            $oldPath = dirname(__DIR__, 2) . '/assets/' . $oldLogo;
            if (file_exists($oldPath)) unlink($oldPath);
        }

        // Nom de fichier unique
        $ext      = $exts[$mime];
        $filename = $accountId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) jsonErr('Erreur lors de l\'enregistrement.');

        // Redimensionner si possible (GD)
        if (function_exists('imagecreatefromstring')) {
            $src = imagecreatefromstring(file_get_contents($destPath));
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $maxDim = 256;
                if ($w > $maxDim || $h > $maxDim) {
                    $ratio = min($maxDim/$w, $maxDim/$h);
                    $nw = (int)round($w * $ratio);
                    $nh = (int)round($h * $ratio);
                    $dst = imagecreatetruecolor($nw, $nh);
                    // Transparence pour PNG
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh,$w,$h);
                    if ($mime === 'image/png') imagepng($dst, $destPath);
                    elseif ($mime === 'image/webp') imagewebp($dst, $destPath);
                    else imagejpeg($dst, $destPath, 90);
                    imagedestroy($src);
                    imagedestroy($dst);
                }
            }
        }

        $relativePath = 'mail_logos/' . $filename;
        $db->prepare("UPDATE mail_accounts SET logo=? WHERE id=?")->execute([$relativePath, $accountId]);

        jsonOk(['logo' => SITE_URL . '/assets/' . $relativePath]);
    }

    // ── Supprimer logo compte ────────────────────────────────
    case 'delete_logo': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        getAccountOrFail($db, $userId, $accountId);

        $currentLogo = $db->query("SELECT logo FROM mail_accounts WHERE id=$accountId")->fetchColumn();
        if ($currentLogo) {
            $path = dirname(__DIR__, 2) . '/assets/' . $currentLogo;
            if (file_exists($path)) unlink($path);
        }
        $db->prepare("UPDATE mail_accounts SET logo=NULL WHERE id=?")->execute([$accountId]);
        jsonOk();
    }

    case 'save_theme': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $theme     = $_POST['theme'] ?? 'dark';
        $valid     = ['dark','light','midnight','ocean','sunset'];
        if (!in_array($theme, $valid)) jsonErr('Thème invalide.');
        getAccountOrFail($db, $userId, $accountId);
        $db->prepare("UPDATE mail_accounts SET theme=? WHERE id=?")
           ->execute([$theme, $accountId]);
        jsonOk();
    }

    // ── Sauver signature ─────────────────────────────────────
    case 'save_signature': {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $signature = $_POST['signature'] ?? '';
        getAccountOrFail($db, $userId, $accountId);
        $db->prepare("UPDATE mail_accounts SET signature=? WHERE id=?")
           ->execute([strip_tags($signature,'<b><i><u><br><p><a>'), $accountId]);
        jsonOk();
    }

    // ── Notifications non-vues ───────────────────────────────
    case 'get_notifications': {
        $st = $db->prepare("
            SELECT n.*, m.subject, m.from_address, a.address as to_address,
                   sender.logo as from_logo
            FROM mail_notifications n
            JOIN mail_messages m ON m.id = n.message_id
            JOIN mail_accounts a ON a.id = n.account_id
            LEFT JOIN mail_accounts sender ON sender.address = m.from_address
            WHERE n.user_id = ? AND n.is_seen = 0
            ORDER BY n.created_at DESC LIMIT 20
        ");
        $st->execute([$userId]);
        jsonOk($st->fetchAll());
    }

    // ── Marquer notifs vues ───────────────────────────────────
    case 'mark_notifications_seen': {
        $db->prepare("UPDATE mail_notifications SET is_seen=1 WHERE user_id=?")
           ->execute([$userId]);
        jsonOk();
    }

    // ── Chercher une adresse (autocomplétion) ─────────────────
    case 'search_address': {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) jsonOk([]);
        $st = $db->prepare("
            SELECT a.address, a.display_name FROM mail_accounts a
            WHERE (a.address LIKE ? OR a.display_name LIKE ?) AND a.is_active=1
            LIMIT 10
        ");
        $st->execute(["%$q%", "%$q%"]);
        jsonOk($st->fetchAll());
    }

    default:
        jsonErr('Action inconnue.');
}