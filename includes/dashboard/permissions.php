<?php
/**
 * Dashboard Builder — gardes de permission centralisées.
 *
 * Toute action d'écriture du builder DOIT vérifier dashboard_can_build()
 * côté serveur AVANT toute requête (ne jamais se fier au front).
 * S'appuie sur hasRole() de config/config.php.
 */

if (!function_exists('dashboard_can_build')) {
    /**
     * Peut gérer le dashboard (thème, catégories, panels, ordre).
     * Réservé Développeur / Administrateur (décision client).
     */
    function dashboard_can_build(): bool
    {
        return function_exists('hasRole')
            && hasRole(['*', 'Administrateur', 'Développeur']);
    }
}

if (!function_exists('dashboard_can_preview')) {
    /**
     * Peut utiliser « Version Joueurs » (prévisualisation).
     * Staff élargi + mode démo. Lecture seule, aucune écriture exposée.
     */
    function dashboard_can_preview(): bool
    {
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return true;
        }
        return function_exists('hasRole')
            && hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur']);
    }
}

if (!function_exists('dashboard_require_build_json')) {
    /**
     * Garde pour endpoints JSON : coupe avec 403 si non autorisé.
     */
    function dashboard_require_build_json(): void
    {
        if (!dashboard_can_build()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
    }
}
