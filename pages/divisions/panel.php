<?php
/**
 * Panel de division — routeur unique
 *
 * URL : /pages/divisions/panel.php?key=DIV_LSPD_SWAT[&view=dashboard]
 *
 * Vues disponibles :
 *   dashboard | effectifs | grades | annonces | logs | documents
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/division_access.php';
requireLogin();

$panelKey = trim((string)($_GET['key'] ?? ''));
$view     = trim((string)($_GET['view'] ?? 'dashboard'));

// Vues autorisées (communes + optionnelles par capabilité)
$ALLOWED_VIEWS = ['dashboard', 'effectifs', 'grades', 'annonces', 'logs', 'documents',
                  'reports', 'reports_lspd'];
if (!in_array($view, $ALLOWED_VIEWS, true)) {
    $view = 'dashboard';
}

if ($panelKey === '') {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// Charger la division
$division = div_get_by_panel_key($panelKey);
if (!$division) {
    http_response_code(404);
    require __DIR__ . '/includes/not_found.php';
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Contrôle d'accès (faction + appartenance, staff bypass)
if (!div_user_can_access($currentUserId, $division)) {
    http_response_code(403);
    require __DIR__ . '/includes/no_access.php';
    exit;
}

$canManage = div_user_can_manage($currentUserId, $division);

// Gating par capabilité : si la vue demandée n'est pas activée → dashboard
if ($view === 'reports' && !div_has_capability($division, 'create_own_reports')) {
    $view = 'dashboard';
}
if ($view === 'reports_lspd' && !div_has_capability($division, 'view_lspd_reports')) {
    $view = 'dashboard';
}

// Actions POST communes (ajout/suppression membre, grade, annonce, document…)
require __DIR__ . '/includes/actions.php';

// Titre
$pageTitle = $division['name'] . ' — ' . $division['f_name'];

// Inclure header → view → footer
require __DIR__ . '/includes/header.php';
require __DIR__ . '/views/' . $view . '.php';
require __DIR__ . '/includes/footer.php';
