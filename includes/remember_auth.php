<?php
/**
 * remember_auth.php
 * Auto-connexion via cookie persistant (30 jours) + mémoire de dernière page
 * Compatible FiveM CEF
 *
 * ── INSTALLATION ──
 * Ajouter dans config/config.php APRÈS le chargement de Database :
 *     require_once __DIR__ . '/../classes/Database.php'; // ← D'ABORD Database
 *     require_once __DIR__ . '/../includes/remember_auth.php'; // ← ENSUITE remember_auth
 *
 * ── SQL ──
 * CREATE TABLE IF NOT EXISTS `remember_tokens` (
 *   `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   `user_id`    INT UNSIGNED NOT NULL,
 *   `token`      VARCHAR(128) NOT NULL UNIQUE,
 *   `last_page`  VARCHAR(512) DEFAULT NULL,
 *   `expires_at` DATETIME NOT NULL,
 *   `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   INDEX `idx_token` (`token`),
 *   INDEX `idx_user`  (`user_id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

const REMEMBER_COOKIE   = 'mdt_remember';
const REMEMBER_DAYS     = 30;
const REMEMBER_DURATION = REMEMBER_DAYS * 24 * 3600;

/* Pages exclues de la mémoire de dernière page */
const LAST_PAGE_EXCLUDED = ['/login.php', '/register.php', '/logout.php', '/index.php', '/auth/', '/forgot-password.php'];

/**
 * Vérifie si Database est disponible
 */
function isDatabaseAvailable(): bool {
    return class_exists('Database');
}

/**
 * Crée un token de remember-me + pose le cookie
 */
function createRememberToken(int $userId): void {
    if (!isDatabaseAvailable()) return;
    
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()")->execute([$userId]);

        $token   = bin2hex(random_bytes(48));
        $expires = date('Y-m-d H:i:s', time() + REMEMBER_DURATION);
        $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
           ->execute([$userId, $token, $expires]);

        setcookie(REMEMBER_COOKIE, $token, [
            'expires'  => time() + REMEMBER_DURATION,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Exception $e) {
        error_log("Remember token creation error: " . $e->getMessage());
    }
}

/**
 * Supprime le token (déconnexion)
 */
function deleteRememberToken(): void {
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    
    if (isDatabaseAvailable()) {
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([$_COOKIE[REMEMBER_COOKIE]]);
        } catch (Exception $e) {
            error_log("Remember token deletion error: " . $e->getMessage());
        }
    }
    
    setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
}

/**
 * Sauvegarde la page actuelle dans le token en base (appelé par JS via AJAX)
 * Et via cookie JS côté client pour lecture PHP immédiate
 */
function saveLastPage(string $url): void {
    if (!isDatabaseAvailable()) return;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    
    foreach (LAST_PAGE_EXCLUDED as $ex) {
        if (strpos(parse_url($url, PHP_URL_PATH) ?? '', $ex) !== false) return;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE remember_tokens SET last_page = ? WHERE token = ?")
           ->execute([substr($url, 0, 512), $_COOKIE[REMEMBER_COOKIE]]);
    } catch (Exception $e) {
        error_log("Save last page error: " . $e->getMessage());
    }
}

/**
 * Retourne la dernière page sauvegardée pour ce token
 */
function getLastPage(): ?string {
    if (!isDatabaseAvailable()) return null;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return null;
    
    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT last_page FROM remember_tokens WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$_COOKIE[REMEMBER_COOKIE]]);
        $row = $stmt->fetchColumn();
        return $row ?: null;
    } catch (Exception $e) {
        error_log("Get last page error: " . $e->getMessage());
    }
    return null;
}

/**
 * Vérifie le cookie et restaure la session si valide
 */
function checkRememberToken(): void {
    // Vérifier si Database est disponible
    if (!isDatabaseAvailable()) {
        error_log("checkRememberToken: Database class not available yet");
        return;
    }
    
    if (!empty($_SESSION['user_id'])) return;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;

    try {
        $db    = Database::getInstance()->getConnection();
        $token = $_COOKIE[REMEMBER_COOKIE];

        $stmt = $db->prepare(
            "SELECT rt.user_id, u.email, u.username, u.avatar,
                    u.faction, u.faction_rank, u.faction_grade, u.role
             FROM remember_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = ? AND rt.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
            return;
        }

        /* Restaurer la session */
        $_SESSION['user_id']            = $userData['user_id'];
        $_SESSION['user_email']         = $userData['email'];
        $_SESSION['user_username']      = $userData['username'];
        $_SESSION['user_avatar']        = $userData['avatar'];
        $_SESSION['user_faction']       = $userData['faction'];
        $_SESSION['user_faction_rank']  = $userData['faction_rank'];
        $_SESSION['user_faction_grade'] = $userData['faction_grade'];
        $_SESSION['user_role']          = $userData['role'];

        /* Renouveler le token */
        $newToken   = bin2hex(random_bytes(48));
        $newExpires = date('Y-m-d H:i:s', time() + REMEMBER_DURATION);
        $db->prepare("UPDATE remember_tokens SET token = ?, expires_at = ? WHERE token = ?")
           ->execute([$newToken, $newExpires, $token]);

        setcookie(REMEMBER_COOKIE, $newToken, [
            'expires'  => time() + REMEMBER_DURATION,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

    } catch (Exception $e) {
        error_log("checkRememberToken error: " . $e->getMessage());
    }
}

/* ── AJAX : sauvegarder la page courante ── */
/* Appeler depuis page-tracker.js : POST /includes/remember_auth.php?save_page=1 */
if (isset($_GET['save_page']) && !empty($_POST['url'])) {
    header('Content-Type: application/json');
    
    if (isDatabaseAvailable()) {
        checkRememberToken();
        if (!empty($_SESSION['user_id'])) {
            saveLastPage($_POST['url']);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database not available']);
    }
    exit;
}

/* ── Exécution automatique à l'inclusion (seulement si Database est disponible) ── */
if (isDatabaseAvailable()) {
    checkRememberToken();
} else {
    error_log("remember_auth.php: Database class not loaded yet. Make sure to include Database before remember_auth.");
}