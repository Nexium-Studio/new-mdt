<?php
// pages/staff/content/mdt.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'MDT Actif';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'MDT Actif']];
$success = '';

// Colonne optionnelle — logo par URL (migration_admin_panel_config_logo_url.sql)
try {
    $db->exec(
        "ALTER TABLE `admin_panel_config` ADD COLUMN `custom_logo_url` VARCHAR(512) NULL DEFAULT NULL"
    );
} catch (Exception $e) {
    /* colonne déjà présente */
}

/**
 * Normalise une icône registre (ex. "fas fa-shield-halved") pour fa-solid fa-{slug}.
 */
function mdt_normalize_fa_icon_slug(string $iconRaw): string
{
    $iconRaw = trim($iconRaw);
    if (preg_match('/\bfa-([\w-]+)\s*$/', $iconRaw, $m)) {
        return $m[1];
    }
    return preg_replace('/^fa-/', '', $iconRaw);
}

/**
 * Libellé FR lisible pour une clé de page panel générique (basename .php).
 */
function mdt_panel_page_label(string $pageKey): string
{
    static $map = [
        'dashboard'      => 'Dashboard',
        'info_vente'     => 'Classement ventes',
        'prises-service' => 'Prise de service',
        'depenses'       => 'Dépenses',
        'facturation'    => 'Facturation',
        'effectifs'      => 'Effectifs',
        'sanctions'      => 'Sanctions',
        'stock'          => 'Stock',
        'vente'          => 'Vente',
        'custom'         => 'Personnalisé',
    ];
    if (isset($map[$pageKey])) {
        return $map[$pageKey];
    }
    $s = str_replace(['_', '-'], ' ', $pageKey);
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords($s);
}

/**
 * Fichiers *.php à la racine de /pages/panels/{slug}/ (hors config.php) → catalogue MDT.
 */
function mdt_collect_panel_php_pages(string $panelDir): array
{
    if ($panelDir === '' || !is_dir($panelDir)) {
        return [];
    }
    $glob = glob($panelDir . DIRECTORY_SEPARATOR . '*.php');
    if ($glob === false) {
        return [];
    }
    $pages = [];
    foreach ($glob as $file) {
        $base = basename($file, '.php');
        if ($base === 'config') {
            continue;
        }
        $pages[$base] = [
            'label'  => mdt_panel_page_label($base),
            'locked' => ($base === 'dashboard'),
        ];
    }
    uksort($pages, static function ($a, $b) {
        if ($a === 'dashboard') {
            return -1;
        }
        if ($b === 'dashboard') {
            return 1;
        }
        return strnatcasecmp($a, $b);
    });
    return $pages;
}

// ══════════════════════════════════════════════════════════════
// DÉFINITION DES PANELS
// ══════════════════════════════════════════════════════════════
$panelPages = [

    'DOJ' => ['name' => 'DOJ', 'color' => '#ef4444', 'icon' => 'scale-balanced', 'pages' => [
        'dashboard'  => ['label' => 'Dashboard',         'locked' => true],
        'citoyens'   => ['label' => 'Citoyens',          'locked' => false],
        'casier'     => ['label' => 'Casier Judiciaire', 'locked' => false],
        'mandats'    => ['label' => 'Mandats',           'locked' => false],
        'proces'     => ['label' => 'Procès',            'locked' => false],
        'sentences'  => ['label' => 'Sentences',         'locked' => false],
        'code-penal' => ['label' => 'Code Pénal',        'locked' => false],
        'rapports' => ['label' => 'Rapports',        'locked' => false],
        'dispatch-view' => ['label' => 'Dispatch View',        'locked' => false],
    ]],

    'USSS' => ['name' => 'USSS', 'color' => '#334155', 'icon' => 'id-card-clip', 'pages' => [
        'dashboard'    => ['label' => 'Dashboard',          'locked' => true],
        'dashboard_debug' => ['label' => 'Dashboard (debug)', 'locked' => false],
        'agents'       => ['label' => 'Agents',             'locked' => false],
        'divisions'    => ['label' => 'Divisions',          'locked' => false],
        'dossiers'     => ['label' => 'Dossiers',           'locked' => false],
        'mandats'      => ['label' => 'Mandats',            'locked' => false],
        'operations'   => ['label' => 'Opérations',         'locked' => false],
        'intelligence' => ['label' => 'Intelligence',       'locked' => false],
        'financier'    => ['label' => 'Crimes Financiers',  'locked' => false],
        'academie'     => ['label' => 'Académie',           'locked' => false],
        'materiel'     => ['label' => 'Matériel',           'locked' => false],
        'statistiques' => ['label' => 'Statistiques',       'locked' => false],
        'messages'     => ['label' => 'Messages',           'locked' => false],
    ]],

    'LSPD' => ['name' => 'LSPD', 'color' => '#2563eb', 'icon' => 'shield-halved', 'pages' => [
        'dashboard'               => ['label' => 'Dashboard',               'locked' => true],
        // ── Opérations ──────────────────────────────────────────────
        'profils'                 => ['label' => 'Profils civils',          'locked' => false],
        'vehicles'                => ['label' => 'Véhicules',               'locked' => false],
        'casiers'                 => ['label' => 'Casiers judiciaires',     'locked' => false],
        'recherches'              => ['label' => 'Avis de recherche',       'locked' => false],
        'wanted'                  => ['label' => 'Wanted',                  'locked' => false],
        'reports'                 => ['label' => 'Rapports',                'locked' => false],
        'report-create'           => ['label' => 'Création rapport',        'locked' => false],
        'offenses'                => ['label' => 'Code pénal',              'locked' => false],
        'dispatch'                => ['label' => 'Dispatch',                'locked' => false],
        'service'                 => ['label' => 'Prises de service',       'locked' => false],
        // ── Supervision ─────────────────────────────────────────────
        'effectifs'               => ['label' => 'Effectifs',               'locked' => false],
        'employees'               => ['label' => 'Employés',                'locked' => false],
        'rapport-rookie'          => ['label' => 'Rapports rookie',         'locked' => false],
        'equipement'              => ['label' => 'Équipement',              'locked' => false],
        'sanctions'               => ['label' => 'Sanctions',               'locked' => false],
        'divisions'               => ['label' => 'Divisions',               'locked' => false],
        'grades-divisions'        => ['label' => 'Grades & divisions',      'locked' => false],
        'ia'                      => ['label' => 'Affaires internes',       'locked' => false],
        'statistiques'            => ['label' => 'Statistiques',            'locked' => false],
        'logs'                    => ['label' => 'Logs',                    'locked' => false],
        'lspd_log'                => ['label' => 'Journal LSPD',            'locked' => false],
        // ── Judiciaire & armes ───────────────────────────────────────
        'saisies'                 => ['label' => 'Saisies',                 'locked' => false],
        'plainte'                 => ['label' => 'Plaintes',                'locked' => false],
        'convocation'             => ['label' => 'Convocations',            'locked' => false],
        'deposition'              => ['label' => 'Dépositions',             'locked' => false],
        'bracelets-electronique'  => ['label' => 'Bracelets électroniques', 'locked' => false],
        'armes-civils'            => ['label' => 'Armes civils',            'locked' => false],
        'ppa'                     => ['label' => 'PPA',                     'locked' => false],
        'ppa-agent'               => ['label' => 'PPA agent',               'locked' => false],
        'mainteance'              => ['label' => 'Maintenance',             'locked' => false],
        'get_division_members'    => ['label' => 'Membres division',      'locked' => false],
    ]],

    'LSSD' => ['name' => 'LSSD', 'color' => '#10b981', 'icon' => 'star', 'pages' => [
        'dashboard' => ['label' => 'Dashboard', 'locked' => true],
    ]],

    'SAMS' => ['name' => 'SAMS', 'color' => '#f43f5e', 'icon' => 'kit-medical', 'pages' => [
        'dashboard'           => ['label' => 'Dashboard',               'locked' => true],
        'citoyens'            => ['label' => 'Citoyens',                'locked' => false],
        'patients'            => ['label' => 'Patients',                'locked' => false],
        'triage'              => ['label' => 'Triage',                  'locked' => false],
        'interventions'       => ['label' => 'Interventions',           'locked' => false],
        'pharmacie'           => ['label' => 'Pharmacie',               'locked' => false],
        'planning'            => ['label' => 'Planning',                'locked' => false],
        'rapports'            => ['label' => 'Rapports',                'locked' => false],
        'statistiques'        => ['label' => 'Statistiques',            'locked' => false],
        'facturation'         => ['label' => 'Facturation',             'locked' => false],
        'effectifs'           => ['label' => 'Effectifs',               'locked' => false],
        'logs'                => ['label' => 'Logs',                    'locked' => false],
        'get_report'          => ['label' => 'Consultation rapport',    'locked' => false],
        'save-document'       => ['label' => 'Sauvegarde documents',    'locked' => false],
        // Documents médicaux (fichiers dans documents/*.php — clé = basename)
        'arret-travail'       => ['label' => 'Arrêts de travail',       'locked' => false],
        'certificat-aptitude' => ['label' => "Certificats d'aptitude",  'locked' => false],
        'ordonnance'          => ['label' => 'Ordonnances',             'locked' => false],
        'traumatisme'         => ['label' => 'Traumatismes',            'locked' => false],
        'naissance'           => ['label' => 'Naissances',              'locked' => false],
        'suivi-psy'           => ['label' => 'Suivi psy',               'locked' => false],
        'evaluation-psy'      => ['label' => 'Évaluation psy',          'locked' => false],
        'grossesse'           => ['label' => 'Suivi grossesse',         'locked' => false],
        'aptitude-ppa'        => ['label' => 'Aptitude PPA',            'locked' => false],
        'certificat-deces'    => ['label' => 'Certificats décès',       'locked' => false],
    ]],

    'GRUPP6' => ['name' => 'Grupp 6', 'color' => '#64748b', 'icon' => 'truck-shield', 'pages' => [
        'dashboard' => ['label' => 'Dashboard', 'locked' => true],
    ]],

    'HAUTE_LUXURY' => ['name' => 'Haute Luxury', 'color' => '#f472b6', 'icon' => 'gem', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'BAHAMAS' => ['name' => 'Bahamas', 'color' => '#06b6d4', 'icon' => 'martini-glass-citrus', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'BENNYS' => ['name' => "Benny's", 'color' => '#f59e0b', 'icon' => 'phone', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'custom'         => ['label' => 'Personnalisé',     'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'fourriere'      => ['label' => 'Fourrière',        'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'LS_CUSTOM' => ['name' => 'LS Custom', 'color' => '#8b5cf6', 'icon' => 'toolbox', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'custom'         => ['label' => 'Personnalisé',     'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'LS_MOTORS' => ['name' => 'LS Motors', 'color' => '#3b82f6', 'icon' => 'car-rear', 'pages' => [
        'dashboard'       => ['label' => 'Dashboard',            'locked' => true],
        // ── Gestion véhicules & vente (pages/companies/ls-motors/) ──
        'stock'           => ['label' => 'Stock',                'locked' => false],
        'vente'           => ['label' => 'Ventes (gestion)',     'locked' => false],
        'ventes'          => ['label' => 'Liste des ventes',     'locked' => false],
        'plaques'         => ['label' => 'Plaques',              'locked' => false],
        'essais'          => ['label' => 'Essais',               'locked' => false],
        'reprises'        => ['label' => 'Reprises',             'locked' => false],
        'custom'          => ['label' => 'Personnalisation',     'locked' => false],
        'maintenance'     => ['label' => 'Maintenance',          'locked' => false],
        'statistiques'    => ['label' => 'Statistiques',         'locked' => false],
        // ── Clients ─────────────────────────────────────────────────
        'clients'         => ['label' => 'Clients',              'locked' => false],
        'commissions'     => ['label' => 'Commissions',          'locked' => false],
        // ── RH & discipline ───────────────────────────────────────
        'effectifs'       => ['label' => 'Effectifs',            'locked' => false],
        'prises-service'  => ['label' => 'Prises de service',    'locked' => false],
        'sanctions'       => ['label' => 'Sanctions',            'locked' => false],
        'info_vente'      => ['label' => 'Classement ventes',    'locked' => false],
        // ── Finance ─────────────────────────────────────────────────
        'facturation'     => ['label' => 'Facturation',          'locked' => false],
        'depenses'        => ['label' => 'Dépenses',             'locked' => false],
    ]],

    'PREMIUM_DELUXE_MOTORS' => [
        'name'           => 'Premium Deluxe Motorsport',
        'color'          => '#6366f1',
        'icon'           => 'car-side',
        'panel_base_url' => '/pages/panels/premium-deluxe-motors/',
        'pages'          => [
            'dashboard'       => ['label' => 'Dashboard',            'locked' => true],
            'stock'           => ['label' => 'Stock',                'locked' => false],
            'vente'           => ['label' => 'Ventes (gestion)',     'locked' => false],
            'ventes'          => ['label' => 'Liste des ventes',     'locked' => false],
            'plaques'         => ['label' => 'Plaques',              'locked' => false],
            'essais'          => ['label' => 'Essais',               'locked' => false],
            'reprises'        => ['label' => 'Reprises',             'locked' => false],
            'custom'          => ['label' => 'Personnalisation',     'locked' => false],
            'maintenance'     => ['label' => 'Maintenance',          'locked' => false],
            'statistiques'    => ['label' => 'Statistiques',         'locked' => false],
            'clients'         => ['label' => 'Clients',              'locked' => false],
            'commissions'     => ['label' => 'Commissions',          'locked' => false],
            'effectifs'       => ['label' => 'Effectifs',            'locked' => false],
            'prises-service'  => ['label' => 'Prises de service',    'locked' => false],
            'sanctions'       => ['label' => 'Sanctions',            'locked' => false],
            'info_vente'      => ['label' => 'Classement ventes',    'locked' => false],
            'facturation'     => ['label' => 'Facturation',          'locked' => false],
            'depenses'        => ['label' => 'Dépenses',             'locked' => false],
        ],
    ],

    'LUXURY_AUTO' => [
        'name'           => 'Luxury Auto',
        'color'          => '#a855f7',
        'icon'           => 'car-rear',
        'panel_base_url' => '/pages/panels/luxury-auto/',
        'pages'          => [
            'dashboard'       => ['label' => 'Dashboard',            'locked' => true],
            'stock'           => ['label' => 'Stock',                'locked' => false],
            'vente'           => ['label' => 'Ventes (gestion)',     'locked' => false],
            'ventes'          => ['label' => 'Liste des ventes',     'locked' => false],
            'plaques'         => ['label' => 'Plaques',              'locked' => false],
            'essais'          => ['label' => 'Essais',               'locked' => false],
            'reprises'        => ['label' => 'Reprises',             'locked' => false],
            'custom'          => ['label' => 'Personnalisation',     'locked' => false],
            'maintenance'     => ['label' => 'Maintenance',          'locked' => false],
            'statistiques'    => ['label' => 'Statistiques',         'locked' => false],
            'clients'         => ['label' => 'Clients',              'locked' => false],
            'commissions'     => ['label' => 'Commissions',          'locked' => false],
            'effectifs'       => ['label' => 'Effectifs',            'locked' => false],
            'prises-service'  => ['label' => 'Prises de service',    'locked' => false],
            'sanctions'       => ['label' => 'Sanctions',            'locked' => false],
            'info_vente'      => ['label' => 'Classement ventes',    'locked' => false],
            'facturation'     => ['label' => 'Facturation',          'locked' => false],
            'depenses'        => ['label' => 'Dépenses',             'locked' => false],
        ],
    ],

    'HEAVY_IMPOUND' => ['name' => 'Heavy Impound', 'color' => '#94a3b8', 'icon' => 'truck-ramp-box', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'custom'         => ['label' => 'Personnalisé',     'locked' => false],
        'fourriere'      => ['label' => 'Fourrière',        'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'DYNASTY' => ['name' => 'Dynasty', 'color' => '#22c55e', 'icon' => 'city', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',             'locked' => true],
        'biens'          => ['label' => 'Catalogue des Biens',   'locked' => false],
        'locations'      => ['label' => 'Locations',             'locked' => false],
        'ventes'         => ['label' => 'Ventes',                'locked' => false],
        'stock'          => ['label' => 'Stock',                 'locked' => false],
        'vente'          => ['label' => 'Vente',                 'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes',     'locked' => false],
        'contrats'       => ['label' => 'Contrats BV/BL',        'locked' => false],
        'effectifs'      => ['label' => 'Agents',                'locked' => false],
        'prises-service' => ['label' => 'Prise de service',      'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',             'locked' => false],
        'facturation'    => ['label' => 'Facturation',           'locked' => false],
        'depenses'       => ['label' => 'Dépenses',              'locked' => false],
        'FIX_TRANSACTIONS' => ['label' => 'Outil transactions', 'locked' => false],
    ]],

    'LTD' => ['name' => 'LTD Mirror Park', 'color' => '#0ea5e9', 'icon' => 'basket-shopping', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'ROSA_TATTOO' => ['name' => 'Rosa Tattoo', 'color' => '#e11d48', 'icon' => 'wand-magic-sparkles', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'VANGELICO' => ['name' => 'Vangelico', 'color' => '#eab308', 'icon' => 'gem', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'DIAMOND_CASINO' => ['name' => 'Diamond Casino', 'color' => '#a78bfa', 'icon' => 'dice', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'AMMUNATION' => ['name' => 'Ammunation', 'color' => '#ef4444', 'icon' => 'gun', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'LIFE_INVADER' => ['name' => 'Life Invader', 'color' => '#6366f1', 'icon' => 'clapperboard', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'articles'       => ['label' => 'Articles',         'locked' => false],
        'communiques'    => ['label' => 'Communiqués',      'locked' => false],
        'redaction'      => ['label' => 'Rédaction',        'locked' => false],
        'effectifs'      => ['label' => 'Journalistes',     'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'POSTOP' => ['name' => 'PostOP', 'color' => '#10b981', 'icon' => 'truck-fast', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'articles'       => ['label' => 'Articles',         'locked' => false],
        'commandes'      => ['label' => 'Commandes',        'locked' => false],
        'historique'     => ['label' => 'Historique',       'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'TAXI' => ['name' => 'Taxi', 'color' => '#eab308', 'icon' => 'taxi', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',          'locked' => true],
        'dispatch'       => ['label' => 'Dispatch',           'locked' => false],
        'courses'        => ['label' => 'Courses',            'locked' => false],
        'chauffeurs'     => ['label' => 'Chauffeurs',         'locked' => false],
        'prises-service' => ['label' => 'Prise de service',   'locked' => false],
        'effectifs'      => ['label' => 'Effectifs',          'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',          'locked' => false],
        'clients'        => ['label' => 'Clients',            'locked' => false],
        'tarifs'         => ['label' => 'Tarifs & Zones',     'locked' => false],
        'stock'          => ['label' => 'Stock',              'locked' => false],
        'vente'          => ['label' => 'Vente',              'locked' => false],
        'facturation'    => ['label' => 'Facturation',        'locked' => false],
        'depenses'       => ['label' => 'Dépenses',           'locked' => false],
        'stats'          => ['label' => 'Statistiques',       'locked' => false],
        'incidents'      => ['label' => 'Incidents',          'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes',   'locked' => false],
    ]],

    'TRIADS' => ['name' => 'Triads Records', 'color' => '#a855f7', 'icon' => 'compact-disc', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',          'locked' => true],
        'artistes'       => ['label' => 'Artistes',           'locked' => false],
        'productions'    => ['label' => 'Productions',        'locked' => false],
        'studios'        => ['label' => 'Studios',            'locked' => false],
        'contrats'       => ['label' => 'Contrats',           'locked' => false],
        'evenements'     => ['label' => 'Événements',         'locked' => false],
        'effectifs'      => ['label' => 'Effectifs',          'locked' => false],
        'prises-service' => ['label' => 'Prise de Service',   'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',          'locked' => false],
        'stock'          => ['label' => 'Stock',              'locked' => false],
        'vente'          => ['label' => 'Ventes',             'locked' => false],
        'facturation'    => ['label' => 'Facturation',        'locked' => false],
        'depenses'       => ['label' => 'Dépenses',           'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes',   'locked' => false],
    ]],

    'UWU_COFFEE' => ['name' => 'UwU Coffee', 'color' => '#f472b6', 'icon' => 'mug-hot', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'BOAT_HOUSE' => ['name' => 'Boat House', 'color' => '#0891b2', 'icon' => 'sailboat', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'BURGER_SHOT' => ['name' => 'Burger Shot', 'color' => '#f97316', 'icon' => 'burger', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'HORNYS' => ['name' => "Horny's", 'color' => '#dc2626', 'icon' => 'utensils', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'RESTAURANT_KOI' => ['name' => 'Restaurant KOI', 'color' => '#f87171', 'icon' => 'shrimp', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'UP_ATOM' => ['name' => 'UP & Atom', 'color' => '#fbbf24', 'icon' => 'burger', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Vente',            'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'PEARLS_RESORT' => ['name' => "Pearl's Resort", 'color' => '#d4a853', 'icon' => 'hotel', 'pages' => [
        'dashboard'      => ['label' => 'Dashboard',        'locked' => true],
        'chambres'       => ['label' => 'Chambres',         'locked' => false],
        'reservations'   => ['label' => 'Réservations',     'locked' => false],
        'effectifs'      => ['label' => 'Effectifs',        'locked' => false],
        'prises-service' => ['label' => 'Prise de service', 'locked' => false],
        'sanctions'      => ['label' => 'Sanctions',        'locked' => false],
        'stock'          => ['label' => 'Stock',            'locked' => false],
        'vente'          => ['label' => 'Ventes',           'locked' => false],
        'facturation'    => ['label' => 'Facturation',      'locked' => false],
        'depenses'       => ['label' => 'Dépenses',         'locked' => false],
        'info_vente'     => ['label' => 'Classement ventes', 'locked' => false],
    ]],

    'AVOCATS' => ['name' => "Cabinet d'Avocats", 'color' => '#d97706', 'icon' => 'scale-balanced', 'pages' => [
        'dashboard'   => ['label' => 'Tableau de bord',  'locked' => true],
        'clients'     => ['label' => 'Clients',          'locked' => false],
        'dossiers'    => ['label' => 'Dossiers',         'locked' => false],
        'proces'      => ['label' => 'Procès',           'locked' => false],
        'documents'   => ['label' => 'Documents',        'locked' => false],
        'lois'        => ['label' => 'Base de Lois',     'locked' => false],
        'facturation' => ['label' => 'Facturation',      'locked' => false],
        'cabinet'     => ['label' => 'Gestion Cabinet',  'locked' => false],
        'personnel'   => ['label' => 'Personnel',        'locked' => false],
        'generateur'  => ['label' => 'Générateur IA',    'locked' => false],
        'api_claude'  => ['label' => 'API Claude (tech)', 'locked' => false],
    ]],

    'GOUV' => ['name' => 'Gouvernement', 'color' => '#1d4ed8', 'icon' => 'landmark', 'pages' => [
        'dashboard'          => ['label' => "Vue d'ensemble",           'locked' => true],
        'finances'           => ['label' => 'Finances',                 'locked' => false],
        'fiscalite'          => ['label' => 'Fiscalité',                'locked' => false],
        'facturation'        => ['label' => 'Facturation',              'locked' => false],
        'budget'             => ['label' => "Budget de l'État",         'locked' => false],
        'subventions'        => ['label' => 'Subventions',              'locked' => false],
        'decrets'            => ['label' => 'Décrets & arrêtés',        'locked' => false],
        'licences'           => ['label' => 'Licences',                 'locked' => false],
        'registre'           => ['label' => 'Registre civil',           'locked' => false],
        'amendes'            => ['label' => 'Amendes',                  'locked' => false],
        'alertes'            => ['label' => 'Alertes publiques',        'locked' => false],
        'lois'               => ['label' => 'Lois',                     'locked' => false],
        'sec_rapports_fin'   => ['label' => 'Sec. rapports financiers', 'locked' => false],
        'sec_emprunts'       => ['label' => 'Sec. emprunts',            'locked' => false],
        'sec_tresorerie'     => ['label' => 'Sec. trésorerie',          'locked' => false],
        'sec_rehabilitation' => ['label' => 'Sec. réhabilitation',      'locked' => false],
        'sec_services'       => ['label' => 'Sec. services',            'locked' => false],
        'sec_recours'        => ['label' => 'Sec. recours',             'locked' => false],
        'sec_justice'        => ['label' => 'Sec. justice',             'locked' => false],
        'sec_partenariats'   => ['label' => 'Sec. partenariats',        'locked' => false],
        'sec_audits'         => ['label' => 'Sec. audits',              'locked' => false],
        'sec_entreprises'    => ['label' => 'Sec. entreprises',         'locked' => false],
        'sec_agenda'         => ['label' => 'Sec. agenda',              'locked' => false],
        'sec_permis_evt'     => ['label' => 'Sec. permis & événements', 'locked' => false],
        'sec_evenementiel'   => ['label' => 'Sec. événementiel',        'locked' => false],
    ]],
];

// Panels génériques /pages/panels/{slug}/ — catalogue = tous les *.php racine (hors config.php), aligné sur le disque
if (is_readable(__DIR__ . '/../../../config/panel_registry.php')) {
    $__mdtReg = require __DIR__ . '/../../../config/panel_registry.php';
    $__panelsRoot = __DIR__ . '/../../../pages/panels';
    foreach ($__mdtReg['panels'] as $__mdtP) {
        $__pk = $__mdtP['key'];
        if (isset($panelPages[$__pk])) {
            continue;
        }
        $__slug = $__mdtP['slug'];
        $__pagesFromFs = mdt_collect_panel_php_pages($__panelsRoot . DIRECTORY_SEPARATOR . $__slug);
        if ($__pagesFromFs === []) {
            $__pagesFromFs = [
                'dashboard' => ['label' => 'Dashboard', 'locked' => true],
            ];
        }
        $panelPages[$__pk] = [
            'name'           => $__mdtP['name'],
            'color'          => $__mdtP['color'],
            'icon'           => mdt_normalize_fa_icon_slug($__mdtP['icon'] ?? 'circle'),
            'pages'          => $__pagesFromFs,
            'panel_base_url' => '/pages/panels/' . $__slug . '/',
        ];
    }
}

// ── LOAD CONFIGS FROM DB ──────────────────────────────────────
$panelConfigs = [];
try {
    $rows = $db->query("SELECT * FROM admin_panel_config")->fetchAll();
    foreach ($rows as $r) {
        $panelConfigs[$r['panel_key']] = [
            'custom_name'      => $r['custom_name'],
            'custom_subtitle'  => $r['custom_subtitle'] ?? '',
            'custom_color'     => $r['custom_color'],
            'custom_style'     => $r['custom_style'] ?? 'neon',
            'custom_logo_url'  => $r['custom_logo_url'] ?? '',
            'pages'            => json_decode($r['active_pages'] ?? '{}', true) ?: [],
        ];
    }
} catch (Exception $e) {}

// Sous-clés division (DIV_LSPD_*, etc.) : activation dashboard uniquement — pas de config MDT ici
$activePanelKeys = [];
try {
    $rows = $db->query("
        SELECT panel_key FROM admin_panels
        WHERE is_active = 1
          AND IFNULL(LEFT(panel_key, 4), '') <> 'DIV_'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $activePanelKeys = $rows ?: [];
} catch (Exception $e) {}

// ── CUSTOM PANELS ─────────────────────────────────────────────
$customPanelDefs = [];
try {
    $cRows = $db->query("SELECT cp.*, cpp.page_key, cpp.label AS page_label, cpp.icon AS page_icon,
                                cpp.can_disable, cpp.is_admin, cpp.sort_order
                         FROM custom_panels cp
                         LEFT JOIN custom_panel_pages cpp ON cp.panel_key = cpp.panel_key
                         ORDER BY cp.created_at ASC, cpp.sort_order ASC")->fetchAll();
    foreach ($cRows as $r) {
        $pk = $r['panel_key'];
        if (!isset($customPanelDefs[$pk])) {
            $customPanelDefs[$pk] = [
                'name'     => $r['name'],
                'color'    => $r['color'],
                'icon'     => $r['icon'],
                'subtitle' => $r['subtitle'] ?? '',
                'category' => $r['category'] ?? 'Commerces',
                'faction'  => $r['faction_key'] ?? '',
                'custom'   => true,
                'pages'    => [],
            ];
        }
        if ($r['page_key']) {
            $customPanelDefs[$pk]['pages'][$r['page_key']] = [
                'label'  => $r['page_label'],
                'locked' => !(bool)$r['can_disable'],
            ];
        }
    }
} catch (Exception $e) {}

foreach ($customPanelDefs as $pk => $def) {
    $panelPages[$pk] = $def;
}

// Ne garder que les clés présentes dans le catalogue (évite entrées orphelines en base)
$activePanelKeys = array_values(array_filter($activePanelKeys, static function ($k) use ($panelPages) {
    return isset($panelPages[$k]);
}));

// ══════════════════════════════════════════════════════════════
// CHARGER LES GRADES DEPUIS LA DB (3 SOURCES — exclusivement depuis la DB)
// Source A : faction_grades  (créés/modifiés via roles.php — source principale)
// Source B : company_ranks  (rangs entreprises)
// Source D : users — grades orphelins réellement attribués
// ══════════════════════════════════════════════════════════════
// NOTE : La Source C (grades hardcodés) a été supprimée.
// Tous les grades viennent exclusivement de la base de données.
// Gérez les grades via : Panel Staff → Rôles & Factions (roles.php)
// ══════════════════════════════════════════════════════════════

// 0. Initialiser depuis la table maître
$factionGradesMap = [];
try {
    $allFactions = $db->query("SELECT faction_key FROM factions ORDER BY faction_key ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allFactions as $fk) $factionGradesMap[$fk] = [];
} catch (Exception $e) {}

// Source A : faction_grades (DB — créés/modifiés via roles.php)
try {
    $stmtA = $db->query(
        "SELECT f.faction_key, fg.name
         FROM faction_grades fg
         JOIN factions f ON fg.faction_id = f.id
         ORDER BY f.faction_key ASC, fg.rank_order ASC"
    );
    foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = $row['faction_key'];
        if (!in_array($row['name'], $factionGradesMap[$fk] ?? [], true)) {
            $factionGradesMap[$fk][] = $row['name'];
        }
    }
} catch (Exception $e) {}

// Source B : company_ranks
try {
    $stmtB = $db->query(
        "SELECT faction AS faction_key, rank_name AS name FROM company_ranks ORDER BY faction ASC, rank_level ASC"
    );
    foreach ($stmtB->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = $row['faction_key'];
        if (!in_array($row['name'], $factionGradesMap[$fk] ?? [], true)) {
            $factionGradesMap[$fk][] = $row['name'];
        }
    }
} catch (Exception $e) {}

// Source C supprimée — grades exclusivement depuis la DB

// Source D : orphelins réels (users)
try {
    $stmtD = $db->query(
        "SELECT DISTINCT faction AS faction_key, faction_rank AS name FROM users
          WHERE faction IS NOT NULL AND faction != 'NONE' AND faction_rank IS NOT NULL AND faction_rank != ''
         UNION
         SELECT DISTINCT faction AS faction_key, faction_grade AS name FROM users
          WHERE faction IS NOT NULL AND faction != 'NONE' AND faction_grade IS NOT NULL AND faction_grade != ''
         ORDER BY faction_key ASC, name ASC"
    );
    foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = $row['faction_key'];
        if (isset($factionGradesMap[$fk]) && !in_array($row['name'], $factionGradesMap[$fk], true)) {
            $factionGradesMap[$fk][] = $row['name'];
        }
    }
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════
// AJAX — Retourne tous les grades de toutes les factions (pour mdt.php)
// ══════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'grades') {
    header('Content-Type: application/json');
    $out = [];
    foreach ($factionGradesMap as $fk => $grades) {
        if (empty($grades)) continue;
        // Chercher le nom et la couleur de la faction
        $fName  = $fk;
        $fColor = '#64748b';
        try {
            $fs = $db->prepare("SELECT name, color FROM factions WHERE faction_key=? LIMIT 1");
            $fs->execute([$fk]);
            $fr = $fs->fetch();
            if ($fr) { $fName=$fr['name']??$fk; $fColor=$fr['color']??'#64748b'; }
        } catch(Exception $e){}
        $out[] = ['key'=>$fk,'name'=>$fName,'color'=>$fColor,'grades'=>$grades];
    }
    echo json_encode($out);
    exit;
}

// ══════════════════════════════════════════════════════════════
// TOUS LES GRADES (toutes factions) — pour le picker UI
// ══════════════════════════════════════════════════════════════
$allGradesGrouped = []; // [{key, name, color, grades:[]}]
foreach ($factionGradesMap as $fk => $grades) {
    if (empty($grades)) continue;
    $fName  = $fk; $fColor = '#64748b';
    try {
        $fs = $db->prepare("SELECT name,color FROM factions WHERE faction_key=? LIMIT 1");
        $fs->execute([$fk]); $fr=$fs->fetch();
        if($fr){ $fName=$fr['name']??$fk; $fColor=$fr['color']??'#64748b'; }
    } catch(Exception $e){}
    $allGradesGrouped[] = ['key'=>$fk,'name'=>$fName,'color'=>$fColor,'grades'=>$grades];
}

// ══════════════════════════════════════════════════════════════
// PERMISSIONS DE PAGES PAR GRADE
// Table : panel_page_permissions (panel_key, page_key, allowed_grades JSON)
// ══════════════════════════════════════════════════════════════
$pagePermsMap = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `panel_page_permissions` (
        `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `panel_key`      VARCHAR(50)  NOT NULL,
        `page_key`       VARCHAR(100) NOT NULL,
        `allowed_grades` TEXT         NOT NULL DEFAULT '[\"everyone\"]',
        `updated_at`     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_panel_page` (`panel_key`, `page_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $permsStmt = $db->query("SELECT panel_key, page_key, allowed_grades FROM panel_page_permissions");
    foreach ($permsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pagePermsMap[$row['panel_key']][$row['page_key']] = json_decode($row['allowed_grades'], true) ?? ['everyone'];
    }
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════
// TRAITEMENT POST — Sauvegarde config + permissions
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $panelKey = $_POST['panel_key'] ?? '';

    if ($action === 'save_config' && isset($panelPages[$panelKey])) {
        $customName     = trim($_POST['custom_name']     ?? '') ?: $panelPages[$panelKey]['name'];
        $customSubtitle = trim($_POST['custom_subtitle'] ?? '');
        $customColor    = $_POST['custom_color'] ?? $panelPages[$panelKey]['color'];
        $customStyle    = in_array($_POST['custom_style'] ?? '', ['neon','classic','glass','carbon'])
                          ? $_POST['custom_style'] : 'neon';

        // Pages actives
        $activePages = [];
        foreach ($panelPages[$panelKey]['pages'] as $fileKey => $pageDef) {
            if ($pageDef['locked'] ?? false) { $activePages[$fileKey] = true; continue; }
            $activePages[$fileKey] = isset($_POST['page_' . $fileKey]);
        }
        $pagesJson = json_encode($activePages);

        $logoMode      = ($_POST['logo_mode'] ?? 'icon') === 'logo';
        $customLogoUrl = trim($_POST['custom_logo_url'] ?? '');
        if (!$logoMode) {
            $customLogoUrl = '';
        } elseif ($customLogoUrl !== '') {
            if (!filter_var($customLogoUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $customLogoUrl)) {
                $customLogoUrl = '';
            }
        }

        $saveOk   = false;
        $styleErr = false;
        $logoErr  = false;
        try {
            $db->prepare("INSERT INTO admin_panel_config
                            (panel_key, custom_name, custom_subtitle, custom_color, custom_style, custom_logo_url, active_pages)
                          VALUES (?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE
                            custom_name=?, custom_subtitle=?, custom_color=?, custom_style=?, custom_logo_url=?, active_pages=?")
               ->execute([
                   $panelKey, $customName, $customSubtitle, $customColor, $customStyle, $customLogoUrl ?: null, $pagesJson,
                   $customName, $customSubtitle, $customColor, $customStyle, $customLogoUrl ?: null, $pagesJson,
               ]);
            $saveOk = true;
        } catch (Exception $e) {
            try {
                $db->prepare("INSERT INTO admin_panel_config
                                (panel_key, custom_name, custom_subtitle, custom_color, custom_style, active_pages)
                              VALUES (?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE
                                custom_name=?, custom_subtitle=?, custom_color=?, custom_style=?, active_pages=?")
                   ->execute([$panelKey, $customName, $customSubtitle, $customColor, $customStyle, $pagesJson,
                                         $customName, $customSubtitle, $customColor, $customStyle, $pagesJson]);
                $saveOk   = true;
                $logoErr  = true;
            } catch (Exception $e2) {
                try {
                    $db->prepare("INSERT INTO admin_panel_config (panel_key, custom_name, custom_color, active_pages)
                                  VALUES (?,?,?,?)
                                  ON DUPLICATE KEY UPDATE custom_name=?, custom_color=?, active_pages=?")
                       ->execute([$panelKey, $customName, $customColor, $pagesJson,
                                             $customName, $customColor, $pagesJson]);
                    $saveOk   = true;
                    $styleErr = true;
                    $logoErr  = true;
                } catch (Exception $e3) {
                }
            }
        }

        // Permissions de pages par grade
        if ($saveOk) {
            foreach ($panelPages[$panelKey]['pages'] as $fileKey => $pageDef) {
                if ($pageDef['locked'] ?? false) continue;

                $permMode = $_POST['perm_mode_' . $panelKey . '_' . $fileKey] ?? 'everyone';

                if ($permMode === 'everyone') {
                    $allowedGrades = ['everyone'];
                } else {
                    $allowedGrades = array_values(array_filter(
                        $_POST['perm_grades_' . $panelKey . '_' . $fileKey] ?? []
                    ));
                    if (empty($allowedGrades)) $allowedGrades = ['everyone'];
                }

                try {
                    $db->prepare("INSERT INTO panel_page_permissions (panel_key, page_key, allowed_grades)
                                  VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE allowed_grades = VALUES(allowed_grades)")
                       ->execute([$panelKey, $fileKey, json_encode($allowedGrades)]);
                    $pagePermsMap[$panelKey][$fileKey] = $allowedGrades;
                } catch (Exception $e) {}
            }

            // Sync custom panels
            if (isset($customPanelDefs[$panelKey])) {
                try {
                    $db->prepare("UPDATE custom_panels SET name=?, subtitle=?, color=? WHERE panel_key=?")
                       ->execute([$customName, $customSubtitle, $customColor, $panelKey]);
                    $customPanelDefs[$panelKey]['name']     = $customName;
                    $customPanelDefs[$panelKey]['color']    = $customColor;
                    $customPanelDefs[$panelKey]['subtitle'] = $customSubtitle;
                    $panelPages[$panelKey]['name']  = $customName;
                    $panelPages[$panelKey]['color'] = $customColor;
                } catch (Exception $e) {}
            }

            // Sync table factions pour refléter le renommage partout (profile.php, dashboard.php…)
            try {
                $db->prepare("UPDATE factions SET name=?, color=? WHERE faction_key=?")
                   ->execute([$customName, $customColor, $panelKey]);
            } catch (Exception $e) {}

            $panelConfigs[$panelKey] = [
                'custom_name'      => $customName,
                'custom_subtitle'  => $customSubtitle,
                'custom_color'     => $customColor,
                'custom_style'     => $customStyle,
                'custom_logo_url'  => $customLogoUrl,
                'pages'            => $activePages,
            ];
        }

        $success = 'Configuration de ' . htmlspecialchars($panelPages[$panelKey]['name']) . ' sauvegardée.';
        if (!empty($styleErr)) {
            $success .= ' ⚠️ Le style n\'a pas pu être sauvegardé (exécutez migration_theme.sql).';
        }
        if (!empty($logoErr)) {
            $success .= ' ⚠️ L’URL du logo n’a pas été enregistrée (exécutez migration_admin_panel_config_logo_url.sql).';
        }
    }
}

$openPanel = $_GET['panel'] ?? null;

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>MDT Actif</h1>
        <p>Personnalisez les panels : couleur, nom, pages disponibles et <strong>permissions par grade</strong></p>
    </div>
    <div class="page-header-right">
        <a href="roles.php" class="btn btn-secondary" style="margin-right:8px;">
            <i class="fa-solid fa-users-gear"></i> Gérer les grades
        </a>
        <a href="panels.php" class="btn btn-secondary">
            <i class="fa-solid fa-toggle-on"></i> Activer des panels
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success; ?></div>
<?php endif; ?>

<?php if (empty($activePanelKeys)): ?>
<div class="card">
    <div class="empty-state">
        <i class="fa-solid fa-layer-group"></i>
        <p>Aucun panel actif. <a href="panels.php" style="color:var(--blue);">Activer des panels →</a></p>
    </div>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;">
<?php foreach ($activePanelKeys as $key):
    if (!isset($panelPages[$key])) continue;

    $def      = $panelPages[$key];
    $cfg      = $panelConfigs[$key] ?? [];
    $name     = $cfg['custom_name']     ?? $def['name'];
    $subtitle = $cfg['custom_subtitle'] ?? '';
    $color    = $cfg['custom_color']    ?? $def['color'];
    $style    = $cfg['custom_style']    ?? 'neon';
    $logoUrl  = trim((string) ($cfg['custom_logo_url'] ?? ''));
    $hasLogo  = $logoUrl !== '';
    $isCustom = !empty($def['custom']);

    $configuredPages = $cfg['pages'] ?? [];
    $activePageCount = 0;
    foreach ($def['pages'] as $fileKey => $pageDef) {
        if ($pageDef['locked'] ?? false) { $activePageCount++; continue; }
        if ($configuredPages[$fileKey] ?? true) $activePageCount++;
    }

    // Tous les grades disponibles (toutes factions — roles.php)
    $factionGrades = $factionGradesMap[$key] ?? []; // grades propres au panel (compat. legacy)
    $hasGrades     = !empty($allGradesGrouped);     // vrai si au moins 1 faction a des grades
?>
<!-- ── Carte résumé ── -->
<div class="card" style="border-color:<?php echo $color; ?>33;cursor:pointer;position:relative;"
     onclick="openModal('modal-panel-<?php echo $key; ?>')">

    <?php if ($isCustom): ?>
    <div style="position:absolute;top:10px;right:10px;font-size:.6rem;font-weight:700;padding:2px 7px;
                background:rgba(168,85,247,.15);border:1px solid rgba(168,85,247,.3);border-radius:4px;
                color:#a855f7;text-transform:uppercase;letter-spacing:.04em;z-index:1;">Custom</div>
    <?php endif; ?>

    <div class="card-body">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="width:46px;height:46px;border-radius:12px;background:<?php echo $color; ?>20;
                        display:grid;place-items:center;color:<?php echo $color; ?>;font-size:1.2rem;flex-shrink:0;overflow:hidden;">
                <?php if ($hasLogo): ?>
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt=""
                     style="width:100%;height:100%;object-fit:contain;padding:4px;"
                     referrerpolicy="no-referrer" loading="lazy">
                <?php else: ?>
                <i class="fa-solid fa-<?php echo htmlspecialchars($def['icon']); ?>"></i>
                <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?php echo htmlspecialchars($name); ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-secondary);"><?php echo htmlspecialchars($key); ?></div>
            </div>
            <div style="width:14px;height:14px;border-radius:50%;background:<?php echo $color; ?>;flex-shrink:0;"></div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;
                    font-size:.78rem;color:var(--text-secondary);margin-bottom:8px;">
            <span><?php echo $activePageCount; ?> / <?php echo count($def['pages']); ?> pages actives</span>
            <?php if ($hasGrades): ?>
            <span style="color:<?php echo $color; ?>;font-weight:600;font-size:.72rem;">
                <i class="fa-solid fa-shield-halved"></i> <?php echo count($factionGrades); ?> grade(s)
            </span>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:.7rem;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> Pas de grades configurés
            </span>
            <?php endif; ?>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach ($def['pages'] as $fileKey => $pageDef):
                $isActive    = ($pageDef['locked'] ?? false) ? true : ($configuredPages[$fileKey] ?? true);
                $pagePerms   = $pagePermsMap[$key][$fileKey] ?? ['everyone'];
                $isRestricted = !in_array('everyone', $pagePerms, true) && count($pagePerms) > 0;
            ?>
            <span style="font-size:.62rem;padding:2px 6px;border-radius:4px;
                background:<?php echo $isActive ? $color.'22' : 'rgba(255,255,255,.04)'; ?>;
                color:<?php echo $isActive ? $color : 'var(--text-muted)'; ?>;
                border:1px solid <?php echo $isActive ? $color.'44' : 'var(--border)'; ?>;"
                title="<?php echo $isRestricted ? implode(', ', array_map('htmlspecialchars', $pagePerms)) : 'Tous les membres'; ?>">
                <?php if ($isRestricted): ?>
                <i class="fa-solid fa-lock" style="font-size:.55rem;opacity:.7;margin-right:2px;"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($pageDef['label']); ?>
            </span>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($def['panel_base_url'])): ?>
        <div style="margin-top:8px;font-size:.62rem;color:var(--text-muted);word-break:break-all;">
            <i class="fa-solid fa-link" style="opacity:.6;"></i>
            <?php echo htmlspecialchars(rtrim(SITE_URL, '/') . $def['panel_base_url'] . 'dashboard.php'); ?>
        </div>
        <?php endif; ?>

        <div style="text-align:right;margin-top:10px;font-size:.72rem;color:var(--blue);">
            Configurer →
        </div>
    </div>
</div>

<!-- ══ MODAL CONFIG PANEL ══ -->
<div class="modal-overlay" id="modal-panel-<?php echo $key; ?>"
     <?php echo $openPanel === $key ? 'style="display:grid;"' : ''; ?>>
    <div class="modal" style="max-width:820px;width:96%;" onclick="event.stopPropagation()">

        <!-- Header -->
        <div class="modal-header">
            <h3 style="display:flex;align-items:center;gap:8px;">
                <span style="width:32px;height:32px;border-radius:8px;background:<?php echo $color; ?>20;
                             display:grid;place-items:center;color:<?php echo $color; ?>;font-size:.9rem;flex-shrink:0;overflow:hidden;">
                    <?php if ($hasLogo): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="" style="width:100%;height:100%;object-fit:contain;padding:3px;" referrerpolicy="no-referrer">
                    <?php else: ?>
                    <i class="fa-solid fa-<?php echo htmlspecialchars($def['icon']); ?>"></i>
                    <?php endif; ?>
                </span>
                <?php echo htmlspecialchars($name); ?>
                <span style="font-size:.62rem;font-weight:600;padding:2px 8px;background:<?php echo $color; ?>15;
                             border:1px solid <?php echo $color; ?>33;border-radius:20px;color:<?php echo $color; ?>;">
                    <?php echo htmlspecialchars($key); ?>
                </span>
            </h3>
            <button class="modal-close" onclick="closeModal('modal-panel-<?php echo $key; ?>')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" style="display:flex;flex-direction:column;overflow:hidden;max-height:90vh;">
            <input type="hidden" name="action"    value="save_config">
            <input type="hidden" name="panel_key" value="<?php echo $key; ?>">

            <!-- Zone scrollable -->
            <div style="overflow-y:auto;flex:1;padding:1.5rem;display:flex;flex-direction:column;gap:1.5rem;">

                <!-- ── SECTION 1 : Identité du panel ── -->
                <div>
                    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                                color:var(--text-secondary);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-pen-to-square" style="color:<?php echo $color; ?>;"></i>
                        Identité
                    </div>
                    <div class="grid-2" style="gap:12px;margin-bottom:12px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Nom affiché</label>
                            <input type="text" name="custom_name" class="form-control"
                                   value="<?php echo htmlspecialchars($name); ?>"
                                   placeholder="<?php echo htmlspecialchars($def['name']); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Sous-titre sidebar</label>
                            <input type="text" name="custom_subtitle" class="form-control"
                                   value="<?php echo htmlspecialchars($subtitle); ?>"
                                   placeholder="Ex : Los Santos Police Department">
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Couleur</label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="color" name="custom_color" class="form-control"
                                       value="<?php echo htmlspecialchars($color); ?>"
                                       style="height:38px;padding:3px;width:50px;flex-shrink:0;"
                                       oninput="document.getElementById('colorText-<?php echo $key; ?>').value=this.value;updateModalColor('<?php echo $key; ?>',this.value)">
                                <input type="text" id="colorText-<?php echo $key; ?>" class="form-control"
                                       value="<?php echo htmlspecialchars($color); ?>" placeholder="#000000"
                                       style="width:110px;"
                                       oninput="this.closest('form').querySelector('[name=custom_color]').value=this.value;updateModalColor('<?php echo $key; ?>',this.value)">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;flex:1;">
                            <label class="form-label">Style du thème</label>
                            <div style="display:flex;gap:6px;" class="theme-style-grid">
                                <?php
                                $styles = [
                                    'neon'    => ['label'=>'Neon',    'icon'=>'fa-bolt'],
                                    'classic' => ['label'=>'Classic', 'icon'=>'fa-briefcase'],
                                    'glass'   => ['label'=>'Glass',   'icon'=>'fa-droplet'],
                                    'carbon'  => ['label'=>'Carbon',  'icon'=>'fa-cube'],
                                ];
                                foreach ($styles as $sKey => $sDef):
                                    $isSelected = ($style === $sKey);
                                ?>
                                <label style="display:flex;align-items:center;gap:5px;padding:7px 10px;
                                              background:<?php echo $isSelected ? $color.'18' : 'rgba(255,255,255,.03)'; ?>;
                                              border:1px solid <?php echo $isSelected ? $color.'55' : 'var(--border)'; ?>;
                                              border-radius:8px;cursor:pointer;transition:all .18s;font-size:.78rem;font-weight:600;
                                              color:<?php echo $isSelected ? $color : 'var(--text-secondary)'; ?>;"
                                       onclick="selectThemeStyle(this,'<?php echo $key; ?>','<?php echo $sKey; ?>')">
                                    <input type="radio" name="custom_style" value="<?php echo $sKey; ?>"
                                           <?php echo $isSelected ? 'checked' : ''; ?> style="display:none;">
                                    <i class="fa-solid <?php echo $sDef['icon']; ?>"></i>
                                    <?php echo $sDef['label']; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Icône Font Awesome (catalogue) ou logo image (URL) ── -->
                <div>
                    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                                color:var(--text-secondary);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-image" style="color:<?php echo $color; ?>;"></i>
                        Icône ou logo
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem;">
                            <input type="radio" name="logo_mode" value="icon" <?php echo !$hasLogo ? 'checked' : ''; ?>
                                   onchange="document.getElementById('logo-url-wrap-<?php echo $key; ?>').style.display='none'">
                            Icône (catalogue)
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem;">
                            <input type="radio" name="logo_mode" value="logo" <?php echo $hasLogo ? 'checked' : ''; ?>
                                   onchange="document.getElementById('logo-url-wrap-<?php echo $key; ?>').style.display='block'">
                            Logo image (URL directe)
                        </label>
                    </div>
                    <p style="font-size:.72rem;color:var(--text-muted);margin:-4px 0 8px 0;">
                        Icône par défaut : <code style="font-size:.68rem;">fa-solid fa-<?php echo htmlspecialchars($def['icon']); ?></code>
                    </p>
                    <div id="logo-url-wrap-<?php echo $key; ?>"
                         style="display:<?php echo $hasLogo ? 'block' : 'none'; ?>;">
                        <label class="form-label">URL de l’image (https)</label>
                        <input type="url" name="custom_logo_url" class="form-control"
                               value="<?php echo htmlspecialchars($logoUrl); ?>"
                               placeholder="https://exemple.com/logo.png"
                               autocomplete="off"
                               style="font-size:.85rem;">
                        <p style="font-size:.65rem;color:var(--text-muted);margin-top:6px;">
                            JPG, PNG, WebP, SVG. L’image remplace l’icône dans cette page et peut être réutilisée ailleurs si la colonne DB est lue.
                        </p>
                    </div>
                </div>

                <!-- ── SECTION 2 : Pages & Permissions ── -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                                    color:var(--text-secondary);display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-shield-halved" style="color:<?php echo $color; ?>;"></i>
                            Pages &amp; Permissions
                            <span style="font-size:.65rem;padding:1px 7px;background:<?php echo $color; ?>15;
                                         border:1px solid <?php echo $color; ?>30;border-radius:20px;color:<?php echo $color; ?>;
                                         font-weight:700;text-transform:none;letter-spacing:0;">
                                <?php echo count($def['pages']); ?> pages
                            </span>
                        </div>
                        <?php if (!$hasGrades): ?>
                        <a href="<?php echo SITE_URL; ?>/pages/staff/users/roles.php" target="_blank"
                           style="display:inline-flex;align-items:center;gap:5px;font-size:.7rem;color:#f59e0b;
                                  text-decoration:none;padding:4px 12px;border:1px solid #f59e0b44;
                                  border-radius:20px;background:#f59e0b0d;transition:all .2s;"
                           onmouseover="this.style.background='#f59e0b18'"
                           onmouseout="this.style.background='#f59e0b0d'">
                            <i class="fa-solid fa-user-plus"></i> Créer des grades
                        </a>
                        <?php else: ?>
                        <span style="font-size:.7rem;color:var(--text-muted);">
                            <i class="fa-solid fa-users" style="color:<?php echo $color; ?>;opacity:.6;"></i>
                            <?php echo count($factionGrades); ?> grade(s) — staff bypass toujours
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$hasGrades): ?>
                    <div style="padding:10px 14px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);
                                border-radius:8px;font-size:.78rem;color:#fde68a;display:flex;align-items:center;gap:8px;
                                margin-bottom:10px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;flex-shrink:0;"></i>
                        Aucun grade configuré — toutes les pages sont accessibles à toute la faction.
                        <a href="<?php echo SITE_URL; ?>/pages/staff/users/roles.php" target="_blank"
                           style="color:#f59e0b;font-weight:700;white-space:nowrap;margin-left:auto;">
                            Configurer →
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- ── TABLEAU PAGES ── -->
                    <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;">

                        <!-- En-tête du tableau -->
                        <div style="display:grid;grid-template-columns:36px 1fr 90px <?php echo $hasGrades ? 'auto' : ''; ?>;
                                    align-items:center;gap:12px;padding:8px 14px;
                                    background:rgba(255,255,255,.03);border-bottom:1px solid var(--border);">
                            <div></div>
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:.07em;color:var(--text-muted);">Page</div>
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:.07em;color:var(--text-muted);text-align:center;">Statut</div>
                            <?php if ($hasGrades): ?>
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:.07em;color:var(--text-muted);">Accès par grade</div>
                            <?php endif; ?>
                        </div>

                        <!-- Lignes de pages -->
                        <?php foreach ($def['pages'] as $fileKey => $pageDef):
                            $isLocked     = $pageDef['locked'] ?? false;
                            $isPageActive = $isLocked ? true : ($configuredPages[$fileKey] ?? true);
                            $jsId         = $key . '__' . str_replace(['-','.'], '_', $fileKey);
                            $pagePerms    = $pagePermsMap[$key][$fileKey] ?? ['everyone'];
                            $isEveryone   = empty($pagePerms) || in_array('everyone', $pagePerms, true);
                        ?>
                        <div id="row-<?php echo $jsId; ?>"
                             style="border-bottom:1px solid var(--border);
                                    <?php echo array_key_last($def['pages']) === $fileKey ? 'border-bottom:none;' : ''; ?>
                                    transition:background .15s;
                                    <?php echo !$isPageActive ? 'opacity:.45;' : ''; ?>">

                            <!-- Ligne principale -->
                            <div style="display:grid;grid-template-columns:36px 1fr 90px <?php echo $hasGrades ? 'auto' : ''; ?>;
                                        align-items:center;gap:12px;padding:10px 14px;">

                                <!-- Toggle -->
                                <div style="display:flex;align-items:center;justify-content:center;">
                                    <?php if ($isLocked): ?>
                                    <span style="width:28px;height:28px;border-radius:7px;
                                                 background:rgba(255,255,255,.04);
                                                 display:grid;place-items:center;
                                                 color:var(--text-muted);font-size:.75rem;"
                                          title="Page requise, toujours active">
                                        <i class="fa-solid fa-lock-keyhole"></i>
                                    </span>
                                    <input type="checkbox" name="page_<?php echo $fileKey; ?>"
                                           id="chk-<?php echo $jsId; ?>" checked disabled style="display:none;">
                                    <?php else: ?>
                                    <!-- Switch toggle -->
                                    <div id="toggle-<?php echo $jsId; ?>"
                                         onclick="togglePageRow('<?php echo $jsId; ?>','<?php echo $color; ?>')"
                                         style="width:36px;height:20px;border-radius:10px;cursor:pointer;position:relative;
                                                transition:background .2s;flex-shrink:0;
                                                background:<?php echo $isPageActive ? $color : 'rgba(255,255,255,.1)'; ?>;">
                                        <div id="toggle-knob-<?php echo $jsId; ?>"
                                             style="position:absolute;top:3px;
                                                    left:<?php echo $isPageActive ? '19px' : '3px'; ?>;
                                                    width:14px;height:14px;border-radius:50%;
                                                    background:#fff;transition:left .2s;
                                                    box-shadow:0 1px 3px rgba(0,0,0,.3);"></div>
                                    </div>
                                    <input type="checkbox" name="page_<?php echo $fileKey; ?>"
                                           id="chk-<?php echo $jsId; ?>"
                                           <?php echo $isPageActive ? 'checked' : ''; ?>
                                           style="display:none;">
                                    <?php endif; ?>
                                </div>

                                <!-- Nom de la page -->
                                <div>
                                    <div style="font-size:.85rem;font-weight:600;
                                                color:<?php echo $isPageActive ? 'var(--text-primary)' : 'var(--text-muted)'; ?>;"
                                         id="row-label-<?php echo $jsId; ?>">
                                        <?php echo htmlspecialchars($pageDef['label']); ?>
                                    </div>
                                    <div style="font-size:.65rem;color:var(--text-muted);margin-top:1px;">
                                        <?php if (!empty($def['panel_base_url'])): ?>
                                            <?php echo htmlspecialchars(ltrim($def['panel_base_url'], '/') . $fileKey . '.php'); ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($fileKey); ?>.php
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Statut badge -->
                                <div style="text-align:center;">
                                    <?php if ($isLocked): ?>
                                    <span style="font-size:.65rem;font-weight:700;padding:3px 8px;
                                                 background:rgba(255,255,255,.06);border:1px solid var(--border);
                                                 border-radius:20px;color:var(--text-muted);">
                                        requis
                                    </span>
                                    <?php else: ?>
                                    <span id="status-badge-<?php echo $jsId; ?>"
                                          style="font-size:.65rem;font-weight:700;padding:3px 8px;border-radius:20px;
                                                 background:<?php echo $isPageActive ? $color.'18' : 'rgba(255,255,255,.04)'; ?>;
                                                 border:1px solid <?php echo $isPageActive ? $color.'44' : 'var(--border)'; ?>;
                                                 color:<?php echo $isPageActive ? $color : 'var(--text-muted)'; ?>;">
                                        <?php echo $isPageActive ? 'Actif' : 'Désactivé'; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Zone permissions (colonne droite) — picker multi-factions -->
                                <?php if ($hasGrades): ?>
                                <div id="perm-zone-<?php echo $jsId; ?>"
                                     style="<?php echo !$isPageActive && !$isLocked ? 'opacity:.3;pointer-events:none;' : ''; ?>">
                                    <?php if ($isLocked): ?>
                                    <span style="font-size:.7rem;color:var(--text-muted);font-style:italic;">accessible à tous</span>
                                    <?php else: ?>
                                    <!-- Radio cachés -->
                                    <input type="radio" name="perm_mode_<?php echo $key; ?>_<?php echo $fileKey; ?>"
                                           id="radio-everyone-<?php echo $jsId; ?>"
                                           value="everyone" <?php echo $isEveryone ? 'checked' : ''; ?> style="display:none;">
                                    <input type="radio" name="perm_mode_<?php echo $key; ?>_<?php echo $fileKey; ?>"
                                           id="radio-specific-<?php echo $jsId; ?>"
                                           value="specific" <?php echo !$isEveryone ? 'checked' : ''; ?> style="display:none;">

                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                        <!-- Bouton Tous -->
                                        <button type="button"
                                                id="btn-everyone-<?php echo $jsId; ?>"
                                                onclick="setPermMode('<?php echo $jsId; ?>','everyone','<?php echo $color; ?>')"
                                                style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
                                                       border-radius:20px;cursor:pointer;transition:all .15s;font-size:.7rem;
                                                       font-weight:700;border:1px solid;
                                                       background:<?php echo $isEveryone ? $color.'18' : 'rgba(255,255,255,.04)'; ?>;
                                                       border-color:<?php echo $isEveryone ? $color.'55' : 'var(--border)'; ?>;
                                                       color:<?php echo $isEveryone ? $color : 'var(--text-secondary)'; ?>;">
                                            <i class="fa-solid fa-users" style="font-size:.58rem;"></i> Tous
                                        </button>
                                        <!-- Bouton ouvrir picker -->
                                        <button type="button"
                                                onclick="toggleGradePicker('<?php echo $jsId; ?>','<?php echo $color; ?>')"
                                                id="btn-picker-<?php echo $jsId; ?>"
                                                style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
                                                       border-radius:20px;cursor:pointer;transition:all .15s;font-size:.7rem;
                                                       font-weight:700;border:1px solid;
                                                       background:<?php echo !$isEveryone ? $color.'18' : 'rgba(255,255,255,.04)'; ?>;
                                                       border-color:<?php echo !$isEveryone ? $color.'55' : 'var(--border)'; ?>;
                                                       color:<?php echo !$isEveryone ? $color : 'var(--text-secondary)'; ?>;">
                                            <i class="fa-solid fa-shield-halved" style="font-size:.58rem;"></i>
                                            Grades spécifiques
                                            <span id="picker-count-<?php echo $jsId; ?>"
                                                  style="font-family:monospace;font-size:.65rem;opacity:.8;">
                                                <?php echo !$isEveryone && count($pagePerms) > 0 ? '('.count($pagePerms).')' : ''; ?>
                                            </span>
                                        </button>
                                        <!-- Résumé grades sélectionnés (mini chips) -->
                                        <div id="perm-summary-<?php echo $jsId; ?>"
                                             style="display:flex;flex-wrap:wrap;gap:3px;align-items:center;">
                                            <?php if(!$isEveryone): foreach($pagePerms as $pg): if($pg==='everyone') continue;
                                                $pgHash=md5($pg); ?>
                                            <span style="font-size:.62rem;padding:2px 7px;border-radius:10px;
                                                         background:<?php echo $color;?>15;border:1px solid <?php echo $color;?>33;
                                                         color:<?php echo $color;?>;"><?php echo htmlspecialchars($pg);?></span>
                                            <?php endforeach; endif; ?>
                                        </div>
                                    </div>

                                    <!-- ── Picker expansible (toutes factions) ── -->
                                    <div id="grade-picker-<?php echo $jsId; ?>"
                                         style="display:<?php echo !$isEveryone ? 'block' : 'none'; ?>;
                                                margin-top:8px;background:rgba(0,0,0,.25);
                                                border:1px solid var(--border);border-radius:10px;
                                                padding:10px;max-height:280px;overflow-y:auto;">

                                        <!-- Search -->
                                        <div style="position:relative;margin-bottom:8px;">
                                            <i class="fa-solid fa-search" style="position:absolute;left:8px;top:50%;
                                               transform:translateY(-50%);color:var(--text-muted);font-size:.65rem;pointer-events:none;"></i>
                                            <input type="text"
                                                   placeholder="Rechercher un grade…"
                                                   oninput="filterGradePicker('<?php echo $jsId; ?>',this.value)"
                                                   style="width:100%;padding:5px 8px 5px 26px;background:rgba(255,255,255,.04);
                                                          border:1px solid var(--border);border-radius:7px;color:var(--text-primary);
                                                          font-size:.76rem;font-family:inherit;">
                                        </div>

                                        <!-- Factions & grades -->
                                        <?php foreach($allGradesGrouped as $fGroup):
                                            $fk2   = $fGroup['key'];
                                            $fname2= $fGroup['name'];
                                            $fcol2 = $fGroup['color'];
                                        ?>
                                        <div class="grade-faction-grp" data-faction="<?php echo htmlspecialchars($fk2);?>"
                                             style="margin-bottom:6px;">
                                            <!-- Faction header toggle -->
                                            <div onclick="toggleFactionGrp(this)"
                                                 style="display:flex;align-items:center;gap:5px;cursor:pointer;
                                                        padding:4px 6px;border-radius:6px;margin-bottom:3px;
                                                        background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.05);
                                                        transition:background .12s;"
                                                 onmouseover="this.style.background='rgba(255,255,255,.06)'"
                                                 onmouseout="this.style.background='rgba(255,255,255,.03)'">
                                                <div style="width:8px;height:8px;border-radius:50%;
                                                            background:<?php echo $fcol2;?>;flex-shrink:0;"></div>
                                                <span style="font-size:.65rem;font-weight:700;text-transform:uppercase;
                                                             letter-spacing:.07em;color:<?php echo $fcol2;?>;">
                                                    <?php echo htmlspecialchars($fname2);?>
                                                </span>
                                                <span style="font-size:.6rem;color:var(--text-muted);margin-left:auto;">
                                                    <?php echo count($fGroup['grades']);?> grade(s)
                                                </span>
                                                <i class="fa-solid fa-chevron-down faction-chev"
                                                   style="font-size:.55rem;color:var(--text-muted);margin-left:3px;
                                                          transition:transform .18s;"></i>
                                            </div>
                                            <!-- Grade chips for this faction -->
                                            <div class="faction-grades-list"
                                                 style="display:flex;flex-wrap:wrap;gap:4px;padding:0 4px 2px;">
                                                <?php foreach($fGroup['grades'] as $gradeName):
                                                    $isChecked  = in_array($gradeName, $pagePerms, true);
                                                    $gHash      = md5($fk2.'_'.$gradeName);
                                                ?>
                                                <label class="grade-chip-label"
                                                       data-grade="<?php echo strtolower($gradeName);?>"
                                                       data-faction="<?php echo strtolower($fk2);?>"
                                                       id="chip-<?php echo $jsId;?>-<?php echo $gHash;?>"
                                                       style="display:inline-flex;align-items:center;gap:3px;
                                                              padding:3px 9px;border-radius:20px;cursor:pointer;
                                                              transition:all .13s;font-size:.7rem;font-weight:600;
                                                              background:<?php echo $isChecked&&!$isEveryone ? $color.'20' : 'rgba(255,255,255,.04)'; ?>;
                                                              border:1px solid <?php echo $isChecked&&!$isEveryone ? $color.'55' : 'var(--border)'; ?>;
                                                              color:<?php echo $isChecked&&!$isEveryone ? $color : 'var(--text-muted)'; ?>;"
                                                       onchange="onGradeChipChange('<?php echo $jsId;?>','<?php echo $color;?>')">
                                                    <input type="checkbox"
                                                           name="perm_grades_<?php echo $key;?>_<?php echo $fileKey;?>[]"
                                                           id="gradechk-<?php echo $jsId;?>-<?php echo $gHash;?>"
                                                           value="<?php echo htmlspecialchars($gradeName);?>"
                                                           <?php echo $isChecked ? 'checked' : '';?>
                                                           style="display:none;">
                                                    <i class="fa-solid fa-<?php echo $isChecked&&!$isEveryone ? 'check' : 'plus'; ?>"
                                                       style="font-size:.55rem;"
                                                       id="chip-ico-<?php echo $jsId;?>-<?php echo $gHash;?>"></i>
                                                    <?php echo htmlspecialchars($gradeName);?>
                                                </label>
                                                <?php endforeach;?>
                                            </div>
                                        </div>
                                        <?php endforeach;?>
                                    </div>

                                    <!-- Tous les grades hidden (pour submit form) -->
                                    <div id="grade-chips-<?php echo $jsId; ?>" style="display:none;">
                                        <!-- Backward compat — les vrais inputs sont dans le picker -->
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                            </div>
                            <!-- /ligne principale -->
                        </div>
                        <!-- /row -->
                        <?php endforeach; ?>

                    </div>
                    <!-- /tableau -->

                    <div style="margin-top:8px;font-size:.65rem;color:var(--text-muted);
                                display:flex;align-items:center;gap:5px;">
                        <i class="fa-solid fa-circle-info" style="color:<?php echo $color; ?>;opacity:.5;"></i>
                        <strong>Tous</strong> = accès à toute la faction. <strong>Grades spécifiques</strong> = sélection cross-factions.
                        Grades depuis
                        <a href="<?php echo SITE_URL; ?>/pages/staff/users/roles.php" target="_blank"
                           style="color:<?php echo $color; ?>;font-weight:600;">roles.php</a>.
                    </div>
                </div>

            </div>
            <!-- /scroll zone -->

            <div class="modal-footer">
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-right:auto;">
                <?php if (!empty($def['panel_base_url'])): ?>
                <a href="<?php echo htmlspecialchars(rtrim(SITE_URL, '/') . $def['panel_base_url'] . 'dashboard.php'); ?>"
                   target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Ouvrir le panel
                </a>
                <?php endif; ?>
                <?php if ($isCustom): ?>
                <a href="<?php echo SITE_URL; ?>/pages/custom/?panel=<?php echo urlencode($key); ?>"
                   target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Ouvrir (custom)
                </a>
                <?php endif; ?>
                </div>
                <button type="button" class="btn btn-secondary"
                        onclick="closeModal('modal-panel-<?php echo $key; ?>')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Sauvegarder
                </button>
            </div>
        </form>
    </div>
</div>

<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// ══ Sélecteur de style thème ══════════════════════════════════
function selectThemeStyle(labelEl, panelKey, styleKey) {
    const grid = labelEl.closest('.theme-style-grid');
    if (!grid) return;
    grid.querySelectorAll('label').forEach(l => {
        const radio = l.querySelector('input[type=radio]');
        const icon  = l.querySelector('i');
        const span  = l.querySelector('span') || l;
        if (radio) radio.checked = false;
        l.style.background  = 'rgba(255,255,255,.03)';
        l.style.borderColor = 'var(--border)';
        l.style.color       = 'var(--text-secondary)';
        if (icon) icon.style.color = 'var(--text-secondary)';
    });
    const radio = labelEl.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
    const form  = labelEl.closest('form');
    const color = form ? (form.querySelector('[name=custom_color]')?.value || '#3b82f6') : '#3b82f6';
    labelEl.style.background  = color + '18';
    labelEl.style.borderColor = color + '55';
    labelEl.style.color       = color;
    const icon = labelEl.querySelector('i');
    if (icon) icon.style.color = color;
}

// ══ Mise à jour couleur du modal au changement de couleur ══════
function updateModalColor(key, color) {
    // On pourrait dynamiquement mettre à jour les éléments colorés
    // mais le plus simple est de laisser la sauvegarde le faire
}

// ══ Toggle switch de page (activer / désactiver) ══════════════
function togglePageRow(jsId, color) {
    const chk        = document.getElementById('chk-' + jsId);
    const toggle     = document.getElementById('toggle-' + jsId);
    const knob       = document.getElementById('toggle-knob-' + jsId);
    const row        = document.getElementById('row-' + jsId);
    const label      = document.getElementById('row-label-' + jsId);
    const badge      = document.getElementById('status-badge-' + jsId);
    const permZone   = document.getElementById('perm-zone-' + jsId);
    if (!chk || !toggle) return;

    const isNowActive = !chk.checked;
    chk.checked = isNowActive;

    // Toggle visuel
    toggle.style.background = isNowActive ? color : 'rgba(255,255,255,.1)';
    if (knob) knob.style.left = isNowActive ? '19px' : '3px';

    // Row opacity
    if (row) row.style.opacity = isNowActive ? '1' : '0.45';

    // Label couleur
    if (label) label.style.color = isNowActive ? 'var(--text-primary)' : 'var(--text-muted)';

    // Badge statut
    if (badge) {
        badge.textContent     = isNowActive ? 'Actif' : 'Désactivé';
        badge.style.background  = isNowActive ? color + '18' : 'rgba(255,255,255,.04)';
        badge.style.borderColor = isNowActive ? color + '44' : 'var(--border)';
        badge.style.color       = isNowActive ? color : 'var(--text-muted)';
    }

    // Zone permissions
    if (permZone) {
        permZone.style.opacity       = isNowActive ? '1' : '0.3';
        permZone.style.pointerEvents = isNowActive ? '' : 'none';
    }
}

// ══ Mode Tous ↔ Grades spécifiques ════════════════════════════
function setPermMode(jsId, mode, color) {
    const isEv  = (mode === 'everyone');
    const rEv   = document.getElementById('radio-everyone-' + jsId);
    const rSp   = document.getElementById('radio-specific-' + jsId);
    const btnEv = document.getElementById('btn-everyone-' + jsId);
    const btnPk = document.getElementById('btn-picker-' + jsId);
    const picker= document.getElementById('grade-picker-' + jsId);
    if (rEv) rEv.checked = isEv;
    if (rSp) rSp.checked = !isEv;
    // Style bouton Tous
    if (btnEv) {
        btnEv.style.background  = isEv ? color+'18' : 'rgba(255,255,255,.04)';
        btnEv.style.borderColor = isEv ? color+'55' : 'var(--border)';
        btnEv.style.color       = isEv ? color : 'var(--text-secondary)';
    }
    // Style bouton Picker
    if (btnPk) {
        btnPk.style.background  = !isEv ? color+'18' : 'rgba(255,255,255,.04)';
        btnPk.style.borderColor = !isEv ? color+'55' : 'var(--border)';
        btnPk.style.color       = !isEv ? color : 'var(--text-secondary)';
    }
    // Désactiver les inputs si mode "Tous" (mais laisser le picker ouvert)
    if (picker) {
        picker.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.disabled = isEv;
        });
        picker.style.opacity = isEv ? '0.4' : '1';
        picker.style.pointerEvents = isEv ? 'none' : '';
    }
}

// ══ Toggle picker (ouvrir/fermer) ════════════════════════════
function toggleGradePicker(jsId, color) {
    const picker = document.getElementById('grade-picker-' + jsId);
    const btnPk  = document.getElementById('btn-picker-' + jsId);
    const rSp    = document.getElementById('radio-specific-' + jsId);
    if (!picker) return;
    const isOpen = picker.style.display !== 'none';
    picker.style.display = isOpen ? 'none' : 'block';
    if (!isOpen && rSp) {
        rSp.checked = true;
        setPermMode(jsId, 'specific', color);
    }
}

// ══ Clic sur un chip de grade ════════════════════════════════
function onGradeChipChange(jsId, color) {
    const picker = document.getElementById('grade-picker-' + jsId);
    if (!picker) return;
    const anyChecked = picker.querySelectorAll('input[type=checkbox]:checked').length > 0;
    if (anyChecked) {
        document.getElementById('radio-specific-'+jsId).checked = true;
        setPermMode(jsId, 'specific', color);
    }
    // Mettre à jour visuellement chaque chip
    picker.querySelectorAll('.grade-chip-label').forEach(chip => {
        const chk = chip.querySelector('input[type=checkbox]');
        const ico = chip.querySelector('i');
        const ok  = chk && chk.checked;
        chip.style.background  = ok ? color+'20' : 'rgba(255,255,255,.04)';
        chip.style.borderColor = ok ? color+'55' : 'var(--border)';
        chip.style.color       = ok ? color : 'var(--text-muted)';
        if (ico) { ico.className = ok ? 'fa-solid fa-check' : 'fa-solid fa-plus'; ico.style.fontSize='.55rem'; }
    });
    // Mettre à jour le compteur et le résumé
    updatePermSummary(jsId, color);
}

// ══ Mettre à jour le résumé grades sélectionnés ══════════════
function updatePermSummary(jsId, color) {
    const picker  = document.getElementById('grade-picker-' + jsId);
    const summary = document.getElementById('perm-summary-' + jsId);
    const counter = document.getElementById('picker-count-' + jsId);
    if (!picker) return;
    const checked = Array.from(picker.querySelectorAll('input[type=checkbox]:checked'));
    const names   = checked.map(cb => cb.value);
    if (counter) counter.textContent = names.length > 0 ? '('+names.length+')' : '';
    if (summary) {
        summary.innerHTML = names.map(n =>
            `<span style="font-size:.62rem;padding:2px 7px;border-radius:10px;
                          background:${color}15;border:1px solid ${color}33;color:${color};">${n}</span>`
        ).join('');
    }
}

// ══ Toggle accordion faction ═════════════════════════════════
function toggleFactionGrp(headerEl) {
    const grp  = headerEl.closest('.grade-faction-grp');
    const list = grp?.querySelector('.faction-grades-list');
    const chev = headerEl.querySelector('.faction-chev');
    if (!list) return;
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'flex';
    if (chev) chev.style.transform = isOpen ? 'rotate(-90deg)' : '';
}

// ══ Filtrer les grades dans le picker ════════════════════════
function filterGradePicker(jsId, query) {
    const picker = document.getElementById('grade-picker-' + jsId);
    if (!picker) return;
    const q = query.toLowerCase().trim();
    picker.querySelectorAll('.grade-faction-grp').forEach(grp => {
        const list  = grp.querySelector('.faction-grades-list');
        const chips = grp.querySelectorAll('.grade-chip-label');
        let anyVisible = false;
        chips.forEach(chip => {
            const match = !q ||
                chip.dataset.grade?.includes(q) ||
                chip.dataset.faction?.includes(q);
            chip.style.display = match ? '' : 'none';
            if (match) anyVisible = true;
        });
        // Montrer/cacher la faction entière
        grp.style.display = anyVisible ? '' : 'none';
        if (anyVisible && list && q) list.style.display = 'flex'; // ouvre auto si filtre
    });
}
</script>