<?php
/**
 * Contrôles d'accès aux panels MDT (factions / DarkNet).
 * DarkNet (joueurs) : uniquement les factions listées dans DARKNET_ALLOWED_FACTION_KEYS.
 * Le staff peut ouvrir le panel pour modération (comme les autres MDT).
 */

if (!defined('DARKNET_ALLOWED_FACTION_KEYS')) {
    define('DARKNET_ALLOWED_FACTION_KEYS', [
        'BALLAS',
        'VAGOS',
        'FAMILIES',
        'MARABUNTA',
        'LA_COSA_NOSTRA',
        'AZTECAS',
        'UPTOWN_RIDERS',
        'THE_LOST_MC',
        'ANGEL_OF_DEATH',
        'SOUTHSIDE_HOODS',
        'EPSILON',
    ]);
}

if (!function_exists('userHasDarknetAccess')) {
    function userHasDarknetAccess(): bool
    {
        $f = $_SESSION['user_faction'] ?? '';
        return in_array($f, DARKNET_ALLOWED_FACTION_KEYS, true);
    }

    function requireDarknetPanelAccess(): void
    {
        if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur'])) {
            return;
        }
        if (!userHasDarknetAccess()) {
            redirect(SITE_URL . '/pages/dashboard.php');
        }
    }

    /**
     * Accès panel entreprise / service : membre de la faction ou rôles staff / pôles.
     */
    function requireFactionPanelAccess(string $factionKey): void
    {
        if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Recruteur', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal'])) {
            return;
        }
        if (($_SESSION['user_faction'] ?? '') === $factionKey) {
            return;
        }
        redirect(SITE_URL . '/pages/dashboard.php');
    }
}
