<?php
// ============================================================
//  config/panel_pages.php
//  Helper de gestion des pages par panel
// ============================================================

/**
 * Lit active_pages depuis admin_panel_config.
 *
 * Format JSON : {"dashboard":true,"effectifs":false,...}
 * Sémantique **opt-out** : une clé absente ou à true → page affichée / autorisée.
 * Seules les clés explicitement à **false** désactivent la page.
 *
 * Retourne null si pas de config (= tout actif).
 */
function getPanelActivePagesMap(string $panelKey): ?array {
    try {
        $db  = Database::getInstance()->getConnection();
        $row = $db->prepare("SELECT active_pages FROM admin_panel_config WHERE panel_key = ? LIMIT 1");
        $row->execute([$panelKey]);
        $result = $row->fetch();

        if (!$result || $result['active_pages'] === null || $result['active_pages'] === '') {
            return null;
        }

        $decoded = json_decode($result['active_pages'], true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Indique si une page fichier (sans .php) est autorisée selon la map.
 */
function isPanelPageAllowed(?array $map, string $file): bool {
    if ($map === null) {
        return true;
    }
    if (!array_key_exists($file, $map)) {
        return true;
    }
    return $map[$file] === true;
}

/**
 * @deprecated Utiliser getPanelActivePagesMap — conservé pour compat éventuelle
 * Retourne la liste des clés explicitement à true (ancien comportement opt-in).
 */
function getPanelActivePages(string $panelKey): ?array {
    $map = getPanelActivePagesMap($panelKey);
    if ($map === null) {
        return null;
    }
    return array_keys(array_filter($map, static fn($v) => $v === true));
}


/**
 * Vérifie si la page courante est autorisée.
 */
function checkPageActive(string $panelKey): void {
    $currentFile = basename($_SERVER['PHP_SELF'], '.php');

    // Toujours accessibles (hors liste restrictive)
    if ($currentFile === 'dashboard' || $currentFile === 'info_vente') {
        return;
    }

    $map = getPanelActivePagesMap($panelKey);

    if ($map === null) {
        return;
    }

    if (isPanelPageAllowed($map, $currentFile)) {
        return;
    }

    header('Location: ' . SITE_URL . dirname($_SERVER['PHP_SELF']) . '/dashboard.php');
    exit;
}


/**
 * Filtre le tableau de navigation selon active_pages (opt-out).
 */
function getActiveNavItems(string $panelKey, array $navItems): array {
    $map = getPanelActivePagesMap($panelKey);

    if ($map === null) {
        return $navItems;
    }

    return array_values(array_filter($navItems, static function ($item) use ($map) {
        $file = $item['file'] ?? '';
        if ($file === 'dashboard') {
            return true;
        }
        return isPanelPageAllowed($map, $file);
    }));
}
