<?php
require_once __DIR__ . '/config/config.php';

// Enregistrer la déconnexion dans les logs
if (isLoggedIn()) {
    mdt_audit_log(
        (int) $_SESSION['user_id'],
        'SYSTEM',
        'Déconnexion',
        'Déconnexion utilisateur',
        'auth',
        null
    );
}

// Détruire la session
session_unset();
session_destroy();

// Rediriger vers la page de connexion
redirect(SITE_URL . '/login.php');
