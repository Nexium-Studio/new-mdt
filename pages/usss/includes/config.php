<?php
/**
 * Life Invader News — Configuration
 * Mettre ce fichier dans /includes/config.php
 */

// ── Connexion DB (adapter ces valeurs) ────────────────────────
define('LI_DB_HOST',  'localhost');
define('LI_DB_NAME',  'mdt_service_public');
define('LI_DB_USER',  'mdt_service_public_user');
define('LI_DB_PASS',  'Po3P0?Pcp5zuhnr&');
define('LI_SITE_URL', 'https://life-invader.lysoria.fr');
define('LI_SITE_NAME','Life Invader News');

// ── Mode debug : passer à true pour voir les erreurs ──────────
define('LI_DEBUG', false);

if (LI_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Connexion PDO singleton ───────────────────────────────────
function getLIDB(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host='.LI_DB_HOST.';dbname='.LI_DB_NAME.';charset=utf8mb4',
            LI_DB_USER, LI_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        if (LI_DEBUG) {
            die('<pre style="color:red">Erreur DB: '.$e->getMessage().'</pre>');
        }
        // En prod : page maintenance
        http_response_code(503);
        die('<!DOCTYPE html><html><head><title>Maintenance</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem"><h1>Site en maintenance</h1><p>Revenez dans quelques instants.</p></body></html>');
    }
    return $pdo;
}

/**
 * Exécute une requête SELECT et retourne tous les résultats.
 * Retourne [] en cas d'erreur (évite les 500).
 */
function liQuery(string $sql, array $params = []): array {
    try {
        $db  = getLIDB();
        $st  = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        if (LI_DEBUG) trigger_error('SQL Error: '.$e->getMessage().' | SQL: '.$sql, E_USER_WARNING);
        return [];
    }
}

/**
 * Exécute une requête SELECT et retourne 1 ligne.
 * Retourne null en cas d'erreur.
 */
function liQueryOne(string $sql, array $params = []): ?array {
    try {
        $db = getLIDB();
        $st = $db->prepare($sql);
        $st->execute($params);
        $r  = $st->fetch();
        return $r ?: null;
    } catch (PDOException $e) {
        if (LI_DEBUG) trigger_error('SQL Error: '.$e->getMessage(), E_USER_WARNING);
        return null;
    }
}

/**
 * Retourne une valeur scalaire (COUNT, SUM…).
 */
function liQueryVal(string $sql, array $params = [], $default = 0) {
    try {
        $db = getLIDB();
        $st = $db->prepare($sql);
        $st->execute($params);
        $v  = $st->fetchColumn();
        return $v !== false ? $v : $default;
    } catch (PDOException $e) {
        if (LI_DEBUG) trigger_error('SQL Error: '.$e->getMessage(), E_USER_WARNING);
        return $default;
    }
}

// ── Incrémenter vues (1 fois par session) ────────────────────
function liIncrVue(int $id): void {
    $k = 'li_vu_'.$id;
    if (!empty($_SESSION[$k])) return;
    $_SESSION[$k] = 1;
    try {
        getLIDB()->prepare("UPDATE li_articles SET nb_vues = nb_vues + 1 WHERE id = ?")
                 ->execute([$id]);
    } catch (PDOException $e) {}
}

// ── Helpers ──────────────────────────────────────────────────
function liTimeAgo(string $d): string {
    if (!$d) return '';
    $s = time() - strtotime($d);
    if ($s <     60) return 'À l\'instant';
    if ($s <   3600) return floor($s / 60).'min';
    if ($s <  86400) return floor($s / 3600).'h';
    if ($s < 604800) return floor($s / 86400).'j';
    return date('d/m/Y', strtotime($d));
}

function liReadTime(string $txt): int {
    return max(1, (int)ceil(str_word_count(strip_tags($txt)) / 220));
}

function liH(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function liUrl(string $page, array $p = []): string {
    return LI_SITE_URL . '/' . $page . ($p ? '?' . http_build_query($p) : '');
}