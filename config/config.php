<?php
/**
 * Configuration générale — MDT
 * Priorité : config.local.php (généré par install.php) > variable MDT_SITE_URL > défauts.
 */
if (is_readable(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../error_log.txt');

$mdtSiteUrl = getenv('MDT_SITE_URL');
if (!defined('SITE_URL')) {
    if ($mdtSiteUrl !== false && $mdtSiteUrl !== '') {
        define('SITE_URL', rtrim($mdtSiteUrl, '/'));
    } else {
        define('SITE_URL', 'https://mdt.nexium-studio.com');
    }
}

if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'MDT Service Public');
}
if (!defined('ADMIN_EMAIL')) {
    define('ADMIN_EMAIL', 'lallehugos2005@gmail.com');
}
if (!defined('DISCORD_CLIENT_ID')) {
    define('DISCORD_CLIENT_ID', '1468736299732046089');
}
if (!defined('DISCORD_CLIENT_SECRET')) {
    define('DISCORD_CLIENT_SECRET', 'gitvq2o2Aj2-JzMu8FS9o4yPhew4-Wx6');
}
if (!defined('BOT_API_URL')) {
    define('BOT_API_URL', 'http://87.106.8.133:3001');
}
if (!defined('BOT_API_SECRET')) {
    define('BOT_API_SECRET', 'lxiK-hQi5Lib3JbH-pLmsDLQI0r_k0r8');
}
if (!defined('BOT_API_TIMEOUT')) {
    define('BOT_API_TIMEOUT', 20);
}
if (!defined('ANTHROPIC_API_KEY')) {
    define('ANTHROPIC_API_KEY', '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');
}
if (!defined('MDT_TIMEZONE')) {
    define('MDT_TIMEZONE', 'Europe/Paris');
}
if (!defined('MDT_DISPLAY_ERRORS')) {
    define('MDT_DISPLAY_ERRORS', false);
}

/**
 * Override optionnel de l'URL du bot NexioZ (liaison MDT).
 * Normalement inutile : la clé de liaison contient déjà l'adresse du bot.
 * À définir dans config.local.php seulement si le bot est joignable à une autre
 * URL que celle encodée dans la clé (ex. réseau interne).
 */
if (!defined('NEXIOZ_API_BASE')) {
    define('NEXIOZ_API_BASE', '');
}

/** URL du site vitrine Nexium Studio (endpoints /api/mdt/…). Surchargeable dans config.local.php */
if (!defined('NEXIUM_UPDATES_API_BASE')) {
    define('NEXIUM_UPDATES_API_BASE', 'https://nexium-studio.com');
}
if (!defined('NEXIUM_UPDATES_VERIFY_SSL')) {
    define('NEXIUM_UPDATES_VERIFY_SSL', true);
}

define('DISCORD_REDIRECT_URI', SITE_URL . '/auth/discord-callback.php');

$mdtCookieHost = parse_url(SITE_URL, PHP_URL_HOST);
if (!$mdtCookieHost || $mdtCookieHost === 'localhost') {
    $mdtCookieHost = '';
}

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires' => time() + 86400,
        'path' => '/',
        'domain' => $mdtCookieHost,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
}

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => $mdtCookieHost,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None',
]);

if (!session_id()) {
    session_start();
}

date_default_timezone_set(MDT_TIMEZONE);

if (MDT_DISPLAY_ERRORS) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('CLASSES_PATH', ROOT_PATH . '/classes');
define('ASSETS_PATH', '/assets');

spl_autoload_register(function ($class) {
    $file = CLASSES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/mdt_audit_log.php';
require_once __DIR__ . '/../includes/mdt_display_name.php';
require_once __DIR__ . '/../includes/remember_auth.php';
require_once __DIR__ . '/../includes/site_settings.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

/**
 * Rôles historiques, écrits en dur dans les gardes hasRole() à travers le site.
 * Sert de repli quand la base est injoignable.
 *
 * @return array<string,int>
 */
function mdt_builtin_role_levels(): array {
    return [
        'Citoyen'        => 0,
        'Pôle 911'       => 1,
        'Pôle Ilégale'   => 1,
        'Pôle Illégale'  => 1,
        'Pôle Légal'     => 1,
        'Helper'         => 2,
        'Recruteur'      => 2,
        'Modérateur'     => 3,
        'Staff'          => 4,
        'Développeur'    => 5,
        'Administrateur' => 6,
        '*'              => 7,
    ];
}

/**
 * Rôles définis en base, chargés une seule fois par requête pour rester
 * en phase avec mdt-roles.php sans multiplier les requêtes.
 *
 * @return array<string,int> nom => hierarchy_level (vide si base injoignable)
 */
function mdt_roles_registry(): array {
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }
    $registry = [];
    try {
        // tryGetConnection() et non getInstance() : une base injoignable doit
        // faire replier sur les rôles historiques, pas couper la requête.
        $pdo = Database::tryGetConnection();
        if ($pdo === null) {
            return $registry;
        }
        foreach ($pdo->query('SELECT name, hierarchy_level FROM mdt_roles') as $row) {
            $registry[(string) $row['name']] = (int) $row['hierarchy_level'];
        }
    } catch (Throwable $e) {
        $registry = [];
    }
    return $registry;
}

function mdt_role_is_builtin(?string $role): bool {
    $role = trim((string) $role);
    foreach (array_keys(mdt_builtin_role_levels()) as $name) {
        if (strcasecmp($name, $role) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Niveau d'un rôle, mdt_roles faisant foi. Renvoie null quand le rôle n'existe
 * ni en base ni parmi les rôles historiques : certaines gardes citent des noms
 * jamais créés (« Directeur », « Responsable »), qu'il faut ignorer plutôt que
 * traiter comme un niveau 0 qui ouvrirait la page à tous.
 */
function mdt_role_known_level(?string $role): ?int {
    $role = trim((string) $role);
    if ($role === '') {
        return null;
    }
    foreach ([mdt_roles_registry(), mdt_builtin_role_levels()] as $table) {
        if (isset($table[$role])) {
            return $table[$role];
        }
        foreach ($table as $name => $level) {
            if (strcasecmp((string) $name, $role) === 0) {
                return $level;
            }
        }
    }
    return null;
}

function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }

    if (!is_array($roles)) {
        $roles = [$roles];
    }

    $userRole = trim((string) ($_SESSION['user_role'] ?? ''));
    if ($userRole !== '' && in_array($userRole, $roles, true)) {
        return true;
    }
    // Même rôle avec casse différente en base (ex. staff / Staff / STAFF)
    foreach ($roles as $allowed) {
        if (is_string($allowed) && $userRole !== '' && strcasecmp($userRole, $allowed) === 0) {
            return true;
        }
    }

    // Recruteur : même périmètre d'accès que Helper lorsque la liste autorise « Helper »
    if ($userRole === 'Recruteur') {
        foreach ($roles as $allowed) {
            if ($allowed === 'Helper') {
                return true;
            }
        }
    }

    // Rôles créés depuis mdt-roles.php : absents des listes en dur, ils sont
    // autorisés dès que leur niveau atteint celui du rôle le plus bas exigé.
    // Les rôles historiques restent sur la correspondance par nom ci-dessus,
    // afin de ne rien changer aux gardes existantes.
    if ($userRole === '' || mdt_role_is_builtin($userRole)) {
        return false;
    }
    $userLevel = mdt_role_known_level($userRole);
    if ($userLevel === null) {
        return false;
    }
    $required = null;
    foreach ($roles as $allowed) {
        if (!is_string($allowed)) {
            continue;
        }
        $level = mdt_role_known_level($allowed);
        if ($level !== null && ($required === null || $level < $required)) {
            $required = $level;
        }
    }

    return $required !== null && $userLevel >= $required;
}

/**
 * Rang hiérarchique du rôle global (plus le nombre est élevé, plus le pouvoir est fort).
 */
function mdt_role_hierarchy_rank(?string $role): int {
    return mdt_role_known_level($role) ?? 0;
}

/**
 * Peut modifier le champ « rôle global » (users.role) sur la fiche utilisateur (Staff inclus et au-dessus).
 */
function mdt_can_edit_global_user_role(?string $editorRole): bool {
    return mdt_role_hierarchy_rank($editorRole) >= mdt_role_hierarchy_rank('Staff');
}

/**
 * L'utilisateur actuel est-il en "mode démo" ?
 * = son rôle MDT a can_view_all_panels = 1.
 * Résultat mis en cache session pour éviter des requêtes répétées.
 */
function is_demo_mode(): bool {
    if (!isLoggedIn()) return false;
    if (array_key_exists('_user_demo_mode', $_SESSION)) {
        return (bool)$_SESSION['_user_demo_mode'];
    }
    try {
        $db   = Database::getInstance()->getConnection();
        $role = $_SESSION['user_role'] ?? '';
        if ($role === '') { $_SESSION['_user_demo_mode'] = false; return false; }
        $stmt = $db->prepare("SELECT can_view_all_panels FROM mdt_roles WHERE name=? LIMIT 1");
        $stmt->execute([$role]);
        $val  = $stmt->fetchColumn();
        $_SESSION['_user_demo_mode'] = ($val !== false && (bool)(int)$val);
    } catch (Throwable $e) {
        $_SESSION['_user_demo_mode'] = false;
    }
    return (bool)$_SESSION['_user_demo_mode'];
}

function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }

    $adminRoles = ['*', 'Administrateur', 'Développeur'];

    if (in_array($_SESSION['user_role'], $adminRoles)) {
        return true;
    }

    return false;
}

function redirect($url) {
    // Mode démo : bloquer les redirections d'expulsion hors des pages panel
    // (couvre LSPD, SAMS, USSS, DOJ et tous les panels non patchés individuellement)
    if (is_demo_mode()) {
        $currentPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $panelDirs   = [
            'pages/companies', 'pages/panels',
            'pages/lspd',      'pages/lssd',    'pages/sams',      'pages/doj',
            'pages/usss',      'pages/grupp6',  'pages/gouv',      'pages/avocats',
            'pages/academy-central', 'pages/academie', 'pages/darknet',
            'pages/mail',      'pages/custom',  'pages/divisions',
        ];
        foreach ($panelDirs as $dir) {
            if (str_contains($currentPath, $dir)) {
                return; // Ne pas expulser un utilisateur démo d'une page panel
            }
        }
    }
    header('Location: ' . $url);
    exit;
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function getFactionColor($faction) {
    $colors = [
        'DOJ' => '#1a1a2e',
        'USSS' => '#16213e',
        'LSPD' => '#0f4c81',
        'LSSD' => '#2d4a2e',
        'SAMS' => '#c41e3a',
        'STAFF' => '#6c63ff',
    ];

    return $colors[$faction] ?? '#333333';
}

function getFactionLogo($faction) {
    $logos = [
        'DOJ' => ASSETS_PATH . '/images/logos/doj.png',
        'USSS' => ASSETS_PATH . '/images/logos/usss.png',
        'LSPD' => ASSETS_PATH . '/images/logos/lspd.png',
        'LSSD' => ASSETS_PATH . '/images/logos/lssd.png',
        'SAMS' => ASSETS_PATH . '/images/logos/sams.png',
        'STAFF' => ASSETS_PATH . '/images/logos/staff.png',
    ];

    return $logos[$faction] ?? ASSETS_PATH . '/images/logos/default.png';
}

define('DOCUMENTS_DIR', __DIR__ . '/../public/documents');
define('DOCUMENTS_URL', SITE_URL . '/public/documents');

if (!is_dir(DOCUMENTS_DIR)) {
    if (!mkdir(DOCUMENTS_DIR, 0755, true)) {
        error_log('Impossible de créer le dossier documents');
    }
}

function isInMaintenance($panel) {
    $db = Database::getInstance()->getConnection();

    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Ilégale', 'Pôle Légal'])) {
        return false;
    }

    try {
        $stmt = $db->prepare("SELECT is_active FROM maintenance WHERE panel = 'ALL'");
        $stmt->execute();
        $allMaintenance = $stmt->fetchColumn();

        if ($allMaintenance) {
            return true;
        }

        $stmt = $db->prepare('SELECT is_active FROM maintenance WHERE panel = ?');
        $stmt->execute([$panel]);
        $result = $stmt->fetchColumn();

        return (bool) $result;
    } catch (Exception $e) {
        error_log('Erreur vérification maintenance: ' . $e->getMessage());
        return false;
    }
}

if (is_readable(__DIR__ . '/panel_access.php')) {
    require_once __DIR__ . '/panel_access.php';
}

if (is_readable(__DIR__ . '/academy_helper.php')) {
    require_once __DIR__ . '/academy_helper.php';
}

if (isLoggedIn()) {
    mdt_session_refresh_display_name();
}

// Garde licence Nexium Studio (blocage global si MDT inactif).
// Skip pour les requetes AJAX/API afin de ne pas casser les XHR pendant la mise a jour de la cle.
if (!defined('SKIP_LICENSE_GUARD')) {
    require_once __DIR__ . '/../includes/license_guard.php';
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
    if (!$isAjax) {
        mdt_license_global_guard();
    }
}
