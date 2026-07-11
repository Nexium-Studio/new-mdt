<?php
/**
 * Configuration générale du site
 * MDT Service Public
 */

// Démarrer la session
// Force les cookies de session à être acceptés même dans une iframe/jeu
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => 'mdt.lysoria.fr', // Remplace par ton domaine exact
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None', // TRÈS IMPORTANT pour FiveM
]);
session_start();
// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Constantes du site
define('SITE_NAME', 'MDT Service Public');
define('SITE_URL', 'https://mdt.lysoria.fr'); // À modifier selon votre domaine
define('ADMIN_EMAIL', 'admin@mdt-servicepublic.com');

// Chemins
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('CLASSES_PATH', ROOT_PATH . '/classes');
define('ASSETS_PATH', '/assets');

// Configuration Discord OAuth (optionnel)
define('DISCORD_CLIENT_ID', '1468736299732046089'); // À modifier si vous utilisez Discord OAuth
define('DISCORD_CLIENT_SECRET', 'gitvq2o2Aj2-JzMu8FS9o4yPhew4-Wx6'); // À modifier si vous utilisez Discord OAuth
define('DISCORD_REDIRECT_URI', SITE_URL . '/auth/discord-callback.php');

// Activer l'affichage des erreurs (à désactiver en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Autoloader pour les classes
spl_autoload_register(function ($class) {
    $file = CLASSES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Inclure la connexion à la base de données
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../includes/mdt_audit_log.php';

// Fonctions utilitaires
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
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
    foreach ($roles as $allowed) {
        if (is_string($allowed) && $userRole !== '' && strcasecmp($userRole, $allowed) === 0) {
            return true;
        }
    }

    if ($userRole === 'Recruteur') {
        foreach ($roles as $allowed) {
            if ($allowed === 'Helper') {
                return true;
            }
        }
    }

    return false;
}

function mdt_role_hierarchy_rank(?string $role): int {
    static $order = [
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
    return $order[$role] ?? 0;
}

function mdt_can_edit_global_user_role(?string $editorRole): bool {
    return mdt_role_hierarchy_rank($editorRole) >= mdt_role_hierarchy_rank('Staff');
}

function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Les rôles avec permission administrateur (*)
    $adminRoles = ['*', 'Administrateur', 'Développeur'];
    
    if (in_array($_SESSION['user_role'], $adminRoles)) {
        return true;
    }
    
    // Vérifier les permissions spécifiques
    // À développer selon vos besoins
    return false;
}

function redirect($url) {
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
        'DOJ' => '#1a1a2e',      // Bleu marine foncé
        'USSS' => '#16213e',     // Bleu nuit
        'LSPD' => '#0f4c81',     // Bleu police
        'LSSD' => '#2d4a2e',     // Vert sheriff
        'SAMS' => '#c41e3a',     // Rouge médical
        'STAFF' => '#6c63ff'     // Violet
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
        'STAFF' => ASSETS_PATH . '/images/logos/staff.png'
    ];
    
    return $logos[$faction] ?? ASSETS_PATH . '/images/logos/default.png';
}
