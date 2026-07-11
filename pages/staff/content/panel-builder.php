<?php
// pages/staff/content/panel-builder.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db          = Database::getInstance()->getConnection();
$pageTitle   = 'Créer un Panel';
$breadcrumb  = [['label' => 'Contenu'], ['label' => 'Panels', 'url' => 'panels.php'], ['label' => 'Créer un panel']];
$success     = '';
$errors      = [];

// ── Factions disponibles ──────────────────────────────────────
$factions = [];
try {
    $factions = $db->query("SELECT faction_key, name, color, icon FROM factions ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// ── Définition des modèles de pages disponibles ───────────────
// Structure : template_key => [ 'label', 'pages' => [ page_key => [...] ] ]
$PAGE_TEMPLATES = [

    'service_public' => [
        'label' => 'Service Public',
        'icon'  => 'shield-halved',
        'color' => '#3b82f6',
        'pages' => [
            'dashboard'  => ['label' => 'Dashboard',         'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'citoyens'   => ['label' => 'Citoyens',          'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'rapports'   => ['label' => 'Rapports',          'icon' => 'fas fa-file-alt',       'can_disable' => true,  'is_admin' => false],
            'employees'  => ['label' => 'Effectifs',         'icon' => 'fas fa-user-shield',    'can_disable' => true,  'is_admin' => true, 'section' => 'Administration'],
            'logs'       => ['label' => "Logs d'Activité",   'icon' => 'fas fa-clipboard-list', 'can_disable' => true,  'is_admin' => true, 'section' => 'Administration'],
        ],
    ],

    'doj' => [
        'label' => 'DOJ (Justice)',
        'icon'  => 'scale-balanced',
        'color' => '#ef4444',
        'pages' => [
            'dashboard'  => ['label' => 'Dashboard',         'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'citoyens'   => ['label' => 'Citoyens',          'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'casier'     => ['label' => 'Casier Judiciaire', 'icon' => 'fas fa-file-alt',       'can_disable' => true,  'is_admin' => false],
            'mandats'    => ['label' => 'Mandats',           'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'proces'     => ['label' => 'Procès',            'icon' => 'fas fa-landmark',       'can_disable' => true,  'is_admin' => false],
            'sentences'  => ['label' => 'Sentences',         'icon' => 'fas fa-handcuffs',      'can_disable' => true,  'is_admin' => false],
            'code-penal' => ['label' => 'Code Pénal',        'icon' => 'fas fa-book-open',      'can_disable' => true,  'is_admin' => false, 'section' => 'Législation'],
        ],
    ],

    'lspd' => [
        'label' => 'Police (LSPD)',
        'icon'  => 'shield-alt',
        'color' => '#2563eb',
        'pages' => [
            'dashboard'        => ['label' => 'Dashboard',          'icon' => 'fas fa-grip',               'can_disable' => false, 'is_admin' => false],
            'profiles'         => ['label' => 'Profils Civils',     'icon' => 'fas fa-user-plus',          'can_disable' => false, 'is_admin' => false],
            'dispatch'         => ['label' => 'Dispatch',           'icon' => 'fas fa-desktop',            'can_disable' => true,  'is_admin' => false],
            'vehicles'         => ['label' => 'Véhicules',          'icon' => 'fas fa-car',                'can_disable' => true,  'is_admin' => false],
            'reports'          => ['label' => 'Rapports',           'icon' => 'fas fa-file-alt',           'can_disable' => true,  'is_admin' => false],
            'wanted'           => ['label' => 'Recherche',          'icon' => 'fas fa-crosshairs',         'can_disable' => true,  'is_admin' => false],
            'employees'        => ['label' => 'Employés',           'icon' => 'fas fa-users',              'can_disable' => true,  'is_admin' => false],
            'offenses'         => ['label' => 'Infractions',        'icon' => 'fas fa-book',               'can_disable' => true,  'is_admin' => false],
            'equipement'       => ['label' => 'Équipement',         'icon' => 'fa-solid fa-gun',           'can_disable' => true,  'is_admin' => true, 'section' => 'Supervision'],
            'sanctions'        => ['label' => 'Sanctions',          'icon' => 'fas fa-clipboard-list',     'can_disable' => true,  'is_admin' => true, 'section' => 'Supervision'],
            'grades-divisions' => ['label' => 'Grades & Divisions', 'icon' => 'fa-solid fa-address-book',  'can_disable' => true,  'is_admin' => true, 'section' => 'Supervision'],
        ],
    ],

    'sams' => [
        'label' => 'Médical (SAMS)',
        'icon'  => 'kit-medical',
        'color' => '#f43f5e',
        'pages' => [
            'dashboard'          => ['label' => 'Dashboard',              'icon' => 'fas fa-chart-line',     'can_disable' => false, 'is_admin' => false],
            'citoyens'           => ['label' => 'Citoyens',               'icon' => 'fas fa-users',           'can_disable' => false, 'is_admin' => false],
            'arret-travail'      => ['label' => 'Arrêt de travail',       'icon' => 'fas fa-file-medical',    'can_disable' => true,  'is_admin' => false, 'section' => 'Documents Médicaux'],
            'certificat-aptitude'=> ['label' => "Certificat d'aptitude",  'icon' => 'fas fa-file-medical',    'can_disable' => true,  'is_admin' => false],
            'ordonnance'         => ['label' => 'Ordonnances',            'icon' => 'fas fa-prescription',    'can_disable' => true,  'is_admin' => false],
            'traumatisme'        => ['label' => 'Traumatisme',            'icon' => 'fas fa-bone',            'can_disable' => true,  'is_admin' => false],
            'naissance'          => ['label' => 'Naissance',              'icon' => 'fas fa-baby',            'can_disable' => true,  'is_admin' => false],
            'suivi-psy'          => ['label' => 'Suivi psy',              'icon' => 'fas fa-brain',           'can_disable' => true,  'is_admin' => false],
            'evaluation-psy'     => ['label' => 'Évaluation psy',         'icon' => 'fas fa-brain',           'can_disable' => true,  'is_admin' => false],
            'grossesse'          => ['label' => 'Suivi de grossesse',     'icon' => 'fas fa-heart',           'can_disable' => true,  'is_admin' => false],
            'aptitude-ppa'       => ['label' => 'Aptitude PPA',           'icon' => 'fas fa-certificate',     'can_disable' => true,  'is_admin' => false],
            'certificat-deces'   => ['label' => 'Certificat Décès',       'icon' => 'fas fa-file-medical',    'can_disable' => true,  'is_admin' => false],
            'rapports'           => ['label' => 'Rapports',               'icon' => 'fas fa-file-alt',        'can_disable' => true,  'is_admin' => false],
            'facturation'        => ['label' => 'Facturation',            'icon' => 'fas fa-receipt',         'can_disable' => true,  'is_admin' => false],
            'effectifs'          => ['label' => 'Effectifs',              'icon' => 'fas fa-user-plus',       'can_disable' => true,  'is_admin' => true, 'section' => 'Administration'],
            'logs'               => ['label' => "Logs d'Activité",        'icon' => 'fas fa-clipboard-list',  'can_disable' => true,  'is_admin' => true],
        ],
    ],

    'entreprise' => [
        'label' => 'Entreprise (Standard)',
        'icon'  => 'building',
        'color' => '#8b5cf6',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'effectifs'      => ['label' => 'Effectifs',       'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'stock'          => ['label' => 'Stock',           'icon' => 'fas fa-boxes',           'can_disable' => true,  'is_admin' => false],
            'vente'          => ['label' => 'Ventes',          'icon' => 'fas fa-cash-register',   'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',         'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',      'can_disable' => true,  'is_admin' => false],
            'commandes'      => ['label' => 'Commandes',       'icon' => 'fas fa-shopping-cart',   'can_disable' => true,  'is_admin' => false],
            'clients'        => ['label' => 'Clients',         'icon' => 'fas fa-address-book',    'can_disable' => true,  'is_admin' => false],
            'statistiques'   => ['label' => 'Statistiques',   'icon' => 'fas fa-chart-bar',       'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'mecano' => [
        'label' => 'Garage / Mécano',
        'icon'  => 'screwdriver-wrench',
        'color' => '#f59e0b',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'effectifs'      => ['label' => 'Effectifs',       'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'stock'          => ['label' => 'Stock',           'icon' => 'fas fa-boxes',           'can_disable' => true,  'is_admin' => false],
            'vente'          => ['label' => 'Ventes',          'icon' => 'fas fa-cash-register',   'can_disable' => true,  'is_admin' => false],
            'custom'         => ['label' => 'Personnalisations','icon'=> 'fas fa-car',             'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',         'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',      'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'immobilier' => [
        'label' => 'Immobilier (Dynasty)',
        'icon'  => 'city',
        'color' => '#22c55e',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',          'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'biens'          => ['label' => 'Catalogue des Biens','icon' => 'fas fa-building',       'can_disable' => true,  'is_admin' => false],
            'locations'      => ['label' => 'Locations',          'icon' => 'fas fa-key',            'can_disable' => true,  'is_admin' => false],
            'ventes'         => ['label' => 'Ventes',             'icon' => 'fas fa-handshake',      'can_disable' => true,  'is_admin' => false],
            'contrats'       => ['label' => 'Contrats BV/BL',     'icon' => 'fas fa-file-signature', 'can_disable' => true,  'is_admin' => false],
            'effectifs'      => ['label' => 'Agents',             'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service',   'icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',          'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',        'icon' => 'fas fa-receipt',        'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',           'icon' => 'fas fa-money-bill',     'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'journaliste' => [
        'label' => 'Média / Journaliste',
        'icon'  => 'newspaper',
        'color' => '#6366f1',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'articles'       => ['label' => 'Articles',        'icon' => 'fas fa-newspaper',      'can_disable' => true,  'is_admin' => false],
            'missions'       => ['label' => 'Terrains & Missions','icon'=> 'fas fa-map-marked',   'can_disable' => true,  'is_admin' => false],
            'communiques'    => ['label' => 'Communiqués',     'icon' => 'fas fa-bullhorn',        'can_disable' => true,  'is_admin' => false],
            'effectifs'      => ['label' => 'Journalistes',    'icon' => 'fas fa-id-badge',        'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',        'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',     'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'livraison' => [
        'label' => 'Service de livraison',
        'icon'  => 'truck-fast',
        'color' => '#10b981',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'articles'       => ['label' => 'Articles',        'icon' => 'fas fa-box',            'can_disable' => true,  'is_admin' => false],
            'commandes'      => ['label' => 'Commandes',       'icon' => 'fas fa-shopping-cart',  'can_disable' => true,  'is_admin' => false],
            'historique'     => ['label' => 'Historique',      'icon' => 'fas fa-history',        'can_disable' => true,  'is_admin' => false],
            'effectifs'      => ['label' => 'Effectifs',       'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'vente'          => ['label' => 'Ventes',          'icon' => 'fas fa-cash-register',  'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',        'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',     'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'taxi' => [
        'label' => 'Taxi / Transport',
        'icon'  => 'taxi',
        'color' => '#eab308',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'dispatch'       => ['label' => 'Dispatch',        'icon' => 'fas fa-desktop',        'can_disable' => true,  'is_admin' => false],
            'courses'        => ['label' => 'Courses',         'icon' => 'fas fa-route',          'can_disable' => true,  'is_admin' => false],
            'chauffeurs'     => ['label' => 'Chauffeurs',      'icon' => 'fas fa-id-badge',       'can_disable' => true,  'is_admin' => false],
            'clients'        => ['label' => 'Clients',         'icon' => 'fas fa-address-book',   'can_disable' => true,  'is_admin' => false],
            'tarifs'         => ['label' => 'Tarifs & Zones',  'icon' => 'fas fa-map',            'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'effectifs'      => ['label' => 'Effectifs',       'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'statistiques'   => ['label' => 'Statistiques',   'icon' => 'fas fa-chart-bar',      'can_disable' => true,  'is_admin' => false],
            'incidents'      => ['label' => 'Incidents',       'icon' => 'fas fa-exclamation',    'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',        'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',     'can_disable' => true,  'is_admin' => false],
        ],
    ],

    'studio' => [
        'label' => 'Studio / Musique',
        'icon'  => 'compact-disc',
        'color' => '#a855f7',
        'pages' => [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fas fa-chart-line',    'can_disable' => false, 'is_admin' => false],
            'artistes'       => ['label' => 'Artistes',        'icon' => 'fas fa-music',          'can_disable' => true,  'is_admin' => false],
            'productions'    => ['label' => 'Productions',     'icon' => 'fas fa-record-vinyl',   'can_disable' => true,  'is_admin' => false],
            'studios'        => ['label' => 'Studios',         'icon' => 'fas fa-microphone',     'can_disable' => true,  'is_admin' => false],
            'contrats'       => ['label' => 'Contrats',        'icon' => 'fas fa-file-signature', 'can_disable' => true,  'is_admin' => false],
            'evenements'     => ['label' => 'Événements',      'icon' => 'fas fa-calendar',       'can_disable' => true,  'is_admin' => false],
            'effectifs'      => ['label' => 'Effectifs',       'icon' => 'fas fa-users',          'can_disable' => true,  'is_admin' => false],
            'prises-service' => ['label' => 'Prise de service','icon' => 'fas fa-clock',          'can_disable' => true,  'is_admin' => false],
            'sanctions'      => ['label' => 'Sanctions',       'icon' => 'fas fa-gavel',          'can_disable' => true,  'is_admin' => false],
            'stock'          => ['label' => 'Stock',           'icon' => 'fas fa-boxes',           'can_disable' => true,  'is_admin' => false],
            'vente'          => ['label' => 'Ventes',          'icon' => 'fas fa-cash-register',  'can_disable' => true,  'is_admin' => false],
            'facturation'    => ['label' => 'Facturation',     'icon' => 'fas fa-receipt',        'can_disable' => true,  'is_admin' => false],
            'depenses'       => ['label' => 'Dépenses',        'icon' => 'fas fa-money-bill',     'can_disable' => true,  'is_admin' => false],
        ],
    ],
];

// ── Mapping conseiller (panel existant → template conseillé) ─
$ADVISORS = [
    'DOJ'         => 'doj',
    'USSS'        => 'service_public',
    'LSPD'        => 'lspd',
    'LSSD'        => 'service_public',
    'SAMS'        => 'sams',
    'GRUPP6'      => 'service_public',
    'HAUTE_LUXURY'=> 'entreprise',
    'BAHAMAS'     => 'entreprise',
    'BENNYS'      => 'mecano',
    'LS_CUSTOM'   => 'mecano',
    'LS_MOTORS'   => 'entreprise',
    'HEAVY_IMPOUND'=>'mecano',
    'DYNASTY'     => 'immobilier',
    'LTD'         => 'entreprise',
    'AMMUNATION'  => 'entreprise',
    'LIFE_INVADER'=> 'journaliste',
    'POSTOP'      => 'livraison',
    'TAXI'        => 'taxi',
    'TRIADS'      => 'studio',
    'UWU_COFFEE'  => 'entreprise',
    'RESTAURANT_KOI'=>'entreprise',
    'UP_ATOM'     => 'entreprise',
];

// ── Traitement POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $panelName     = trim($_POST['panel_name']     ?? '');
    $panelSubtitle = trim($_POST['panel_subtitle'] ?? '');
    $panelCategory = $_POST['panel_category'] ?? 'Commerces';
    $panelFaction  = trim($_POST['panel_faction']  ?? '') ?: null;
    $panelColor    = $_POST['panel_color']    ?? '#3b82f6';
    $panelStyle    = in_array($_POST['panel_style'] ?? '', ['neon','classic','glass','carbon']) ? $_POST['panel_style'] : 'neon';
    $panelIcon     = trim($_POST['panel_icon']     ?? '') ?: 'store';
    $baseTemplate  = $_POST['base_template']  ?? 'entreprise';
    $selectedPages = $_POST['pages'] ?? [];

    // Génère panel_key depuis le nom (ex: "Ma Boutique" → "MA_BOUTIQUE_1234")
    $panelKey = strtoupper(preg_replace('/[^A-Z0-9]/', '_', strtoupper($panelName)));
    $panelKey = preg_replace('/_+/', '_', trim($panelKey, '_'));
    $panelKey = substr($panelKey, 0, 30) . '_' . substr(md5(uniqid()), 0, 4);

    // Validations
    if (empty($panelName)) $errors[] = 'Le nom du panel est requis.';
    if (!in_array($panelCategory, ['Services Publics','Bars & Boîtes','Garages','Commerces','Restaurants'])) {
        $errors[] = 'Catégorie invalide.';
    }
    // Dédoublonne (les pages "requis" ont un hidden + checkbox désactivé = soumises 2x)
        $selectedPages = array_unique($selectedPages);
        if (empty($selectedPages)) $errors[] = 'Sélectionnez au moins une page.';

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // 1. Insérer dans custom_panels
            $db->prepare("INSERT INTO custom_panels
                (panel_key, name, subtitle, category, faction_key, color, style, icon, base_template, is_active, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,1,?)")
               ->execute([$panelKey, $panelName, $panelSubtitle, $panelCategory,
                          $panelFaction, $panelColor, $panelStyle, $panelIcon,
                          $baseTemplate, $_SESSION['user_id'] ?? null]);

            // 2. Insérer les pages sélectionnées
            $sortOrder = 0;
            $tplPages = $PAGE_TEMPLATES[$baseTemplate]['pages'] ?? [];
            foreach ($selectedPages as $pageKey) {
                $pageDef = $tplPages[$pageKey] ?? null;
                if (!$pageDef) continue;
                $db->prepare("INSERT INTO custom_panel_pages
                    (panel_key, page_key, label, icon, source_template, section, can_disable, is_admin, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $panelKey,
                       $pageKey,
                       $pageDef['label'],
                       $pageDef['icon'],
                       $baseTemplate,
                       $pageDef['section'] ?? null,
                       (int)($pageDef['can_disable'] ?? 1),
                       (int)($pageDef['is_admin']    ?? 0),
                       $sortOrder++,
                   ]);
            }

            // 3. Enregistrer dans admin_panels (actif)
            $db->prepare("INSERT INTO admin_panels (panel_key, is_active) VALUES (?,1)
                          ON DUPLICATE KEY UPDATE is_active=1")
               ->execute([$panelKey]);

            // 4. Enregistrer dans admin_panel_config
            $pagesJson = json_encode(array_fill_keys($selectedPages, true));
            $db->prepare("INSERT INTO admin_panel_config
                (panel_key, custom_name, custom_subtitle, custom_color, custom_style, active_pages)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE custom_name=?, custom_subtitle=?, custom_color=?, custom_style=?, active_pages=?")
               ->execute([
                   $panelKey, $panelName, $panelSubtitle, $panelColor, $panelStyle, $pagesJson,
                   $panelName, $panelSubtitle, $panelColor, $panelStyle, $pagesJson,
               ]);

            $db->commit();
            $success = $panelKey;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erreur base de données : ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?>
<!-- ══ SUCCÈS ══════════════════════════════════════════════════ -->
<div style="max-width:640px;margin:4rem auto;text-align:center;">
    <div style="width:80px;height:80px;background:rgba(34,197,94,.15);border:2px solid rgba(34,197,94,.4);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;color:#22c55e;">
        <i class="fa-solid fa-circle-check"></i>
    </div>
    <h2 style="font-family:'Orbitron',monospace;font-size:1.3rem;margin-bottom:.5rem;">Panel créé avec succès !</h2>
    <p style="color:var(--text-secondary);margin-bottom:.25rem;">Clé générée : <code style="background:rgba(255,255,255,.06);padding:2px 8px;border-radius:4px;color:var(--blue);"><?php echo htmlspecialchars($success); ?></code></p>
    <p style="color:var(--text-muted);font-size:.82rem;margin-bottom:2rem;">Le panel est maintenant actif et accessible.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
        <a href="<?php echo SITE_URL; ?>/pages/custom/?panel=<?php echo urlencode($success); ?>" class="btn btn-primary" target="_blank">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Ouvrir le panel
        </a>
        <a href="mdt.php?panel=<?php echo urlencode($success); ?>" class="btn btn-secondary">
            <i class="fa-solid fa-sliders"></i> Configurer les pages
        </a>
        <a href="panel-builder.php" class="btn btn-secondary">
            <i class="fa-solid fa-plus"></i> Créer un autre panel
        </a>
    </div>
</div>

<?php else: ?>
<!-- ══ FORMULAIRE ══════════════════════════════════════════════ -->

<div class="page-header">
    <div class="page-header-left">
        <h1>Créer un Panel</h1>
        <p>Configurez et générez un nouveau panel MDT en quelques étapes</p>
    </div>
    <div class="page-header-right">
        <a href="panels.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Retour aux panels</a>
    </div>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($err); ?></div>
<?php endforeach; ?>

<form method="POST" id="builderForm">

<!-- ══ ÉTAPE 1 : INFOS DE BASE ══════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);border-radius:8px;display:grid;place-items:center;color:var(--blue);font-weight:800;font-size:.85rem;">1</div>
            <div>
                <div style="font-weight:700;">Informations générales</div>
                <div style="font-size:.75rem;color:var(--text-secondary);">Nom, catégorie, apparence</div>
            </div>
        </div>
    </div>
    <div class="card-body">

        <!-- Nom + Sous-titre -->
        <div class="grid-2" style="margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Nom du panel <span style="color:#ef4444;">*</span></label>
                <input type="text" name="panel_name" class="form-control" required
                       placeholder="Ex : Ma Boutique, Club Privé..."
                       value="<?php echo htmlspecialchars($_POST['panel_name'] ?? ''); ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Sous-titre sidebar</label>
                <input type="text" name="panel_subtitle" class="form-control"
                       placeholder="Ex : Los Santos, Depuis 2024..."
                       value="<?php echo htmlspecialchars($_POST['panel_subtitle'] ?? ''); ?>">
            </div>
        </div>

        <!-- Catégorie -->
        <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Catégorie <span style="color:#ef4444;">*</span></label>
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                <?php foreach (['Services Publics','Bars & Boîtes','Garages','Commerces','Restaurants'] as $cat):
                    $catIcons = ['Services Publics'=>'shield-halved','Bars & Boîtes'=>'martini-glass-citrus','Garages'=>'car-wrench','Commerces'=>'store','Restaurants'=>'utensils'];
                    $sel = (($_POST['panel_category'] ?? 'Commerces') === $cat);
                ?>
                <label style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;
                    background:<?php echo $sel?'rgba(59,130,246,.12)':'rgba(255,255,255,.03)';?>;
                    border:1px solid <?php echo $sel?'rgba(59,130,246,.4)':'var(--border)';?>;
                    border-radius:10px;cursor:pointer;text-align:center;transition:all .2s;"
                    onclick="selectCategory(this,'<?php echo $cat;?>')">
                    <input type="radio" name="panel_category" value="<?php echo $cat; ?>" <?php echo $sel?'checked':'';?> style="display:none;">
                    <i class="fa-solid fa-<?php echo $catIcons[$cat]; ?>" style="font-size:1.1rem;color:<?php echo $sel?'var(--blue)':'var(--text-secondary)';?>"></i>
                    <span style="font-size:.72rem;font-weight:600;"><?php echo $cat; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Couleur + Style + Icône -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Couleur principale</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" name="panel_color" id="panelColor" class="form-control"
                           value="<?php echo $_POST['panel_color'] ?? '#3b82f6'; ?>"
                           style="height:42px;padding:4px;width:56px;flex-shrink:0;"
                           oninput="document.getElementById('panelColorText').value=this.value">
                    <input type="text" id="panelColorText" class="form-control"
                           value="<?php echo $_POST['panel_color'] ?? '#3b82f6'; ?>"
                           placeholder="#3b82f6"
                           oninput="document.getElementById('panelColor').value=this.value">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Style de thème</label>
                <select name="panel_style" class="form-control">
                    <?php foreach (['neon'=>'✦ Neon','classic'=>'◼ Classique','glass'=>'◈ Glassmorphisme','carbon'=>'▲ Carbon'] as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php echo (($_POST['panel_style']??'neon')===$k)?'selected':'';?>>
                        <?php echo $v; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Icône <small style="color:var(--text-muted);">(FontAwesome)</small></label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <div id="iconPreview" style="width:42px;height:42px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);border-radius:8px;display:grid;place-items:center;color:var(--blue);font-size:1.1rem;flex-shrink:0;">
                        <i class="fa-solid fa-store"></i>
                    </div>
                    <input type="text" name="panel_icon" id="panelIcon" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['panel_icon'] ?? 'store'); ?>"
                           placeholder="store, building, car..."
                           oninput="updateIconPreview(this.value)">
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ══ ÉTAPE 2 : TEMPLATE DE BASE ══════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);border-radius:8px;display:grid;place-items:center;color:var(--blue);font-weight:800;font-size:.85rem;">2</div>
            <div>
                <div style="font-weight:700;">Modèle de base</div>
                <div style="font-size:.75rem;color:var(--text-secondary);">Choisissez le type de panel qui correspond le mieux à votre activité</div>
            </div>
        </div>
    </div>
    <div class="card-body">

        <!-- Conseillers par panel existant -->
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px;">
            <i class="fa-solid fa-lightbulb" style="color:#eab308;margin-right:4px;"></i>
            Suggestions basées sur les panels existants
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;" id="advisorBtns">
            <?php foreach ($ADVISORS as $existingPanel => $tplKey):
                $tpl = $PAGE_TEMPLATES[$tplKey];
            ?>
            <button type="button"
                onclick="selectTemplate('<?php echo $tplKey; ?>')"
                style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:rgba(255,255,255,.03);
                       color:var(--text-secondary);font-size:.75rem;cursor:pointer;transition:all .2s;"
                data-tpl="<?php echo $tplKey; ?>">
                <i class="fa-solid fa-<?php echo $tpl['icon']; ?>" style="color:<?php echo $tpl['color']; ?>;margin-right:4px;"></i>
                <?php echo $existingPanel; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Sélection du template -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;">
            <?php foreach ($PAGE_TEMPLATES as $tplKey => $tpl):
                $sel = (($_POST['base_template'] ?? 'entreprise') === $tplKey);
            ?>
            <label id="tpl-card-<?php echo $tplKey; ?>"
                style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;
                    background:<?php echo $sel?'rgba(59,130,246,.1)':'rgba(255,255,255,.03)';?>;
                    border:1px solid <?php echo $sel?'rgba(59,130,246,.4)':'var(--border)';?>;
                    border-radius:10px;cursor:pointer;text-align:center;transition:all .2s;"
                onclick="selectTemplate('<?php echo $tplKey; ?>')">
                <input type="radio" name="base_template" value="<?php echo $tplKey; ?>"
                       id="tpl-<?php echo $tplKey; ?>"
                       <?php echo $sel?'checked':'';?> style="display:none;">
                <i class="fa-solid fa-<?php echo $tpl['icon']; ?>"
                   style="font-size:1.3rem;color:<?php echo $tpl['color']; ?>;"></i>
                <span style="font-size:.78rem;font-weight:700;"><?php echo $tpl['label']; ?></span>
                <span style="font-size:.65rem;color:var(--text-muted);"><?php echo count($tpl['pages']); ?> pages dispo.</span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ══ ÉTAPE 3 : SÉLECTION DES PAGES ══════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);border-radius:8px;display:grid;place-items:center;color:var(--blue);font-weight:800;font-size:.85rem;">3</div>
            <div>
                <div style="font-weight:700;">Pages du panel</div>
                <div style="font-size:.75rem;color:var(--text-secondary);">Cochez les pages à inclure dans ce panel</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="checkAllPages(true)" style="font-size:.75rem;padding:6px 12px;">
                <i class="fa-solid fa-check-double"></i> Tout sélectionner
            </button>
            <button type="button" class="btn btn-secondary" onclick="checkAllPages(false)" style="font-size:.75rem;padding:6px 12px;">
                <i class="fa-solid fa-xmark"></i> Tout déselectionner
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php foreach ($PAGE_TEMPLATES as $tplKey => $tpl): ?>
        <div id="pages-<?php echo $tplKey; ?>" style="display:<?php echo (($_POST['base_template']??'entreprise')===$tplKey)?'block':'none';?>">

            <?php
            $currentSection = null;
            $sortI = 0;
            foreach ($tpl['pages'] as $pageKey => $pageDef):
                $sec = $pageDef['section'] ?? null;
                $preselected = $_SERVER['REQUEST_METHOD'] === 'POST'
                    ? in_array($pageKey, $_POST['pages'] ?? [])
                    : true; // tout coché par défaut
            ?>
            <?php if ($sec && $sec !== $currentSection): $currentSection = $sec; ?>
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:12px 0 6px;border-bottom:1px solid var(--border);padding-bottom:4px;">
                <?php echo htmlspecialchars($sec); ?>
            </div>
            <?php endif; ?>

            <label style="display:flex;align-items:center;gap:12px;padding:10px 12px;
                background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:8px;
                cursor:<?php echo ($pageDef['can_disable'])?'pointer':'default';?>;margin-bottom:6px;
                transition:background .15s;" class="page-label-<?php echo $tplKey; ?>">
                <?php if ($pageDef['can_disable']): ?>
                <input type="checkbox" name="pages[]" value="<?php echo $pageKey; ?>"
                       <?php echo $preselected?'checked':'';?>
                       style="accent-color:<?php echo $tpl['color']; ?>;"
                       class="page-cb-<?php echo $tplKey; ?>">
                <?php else: ?>
                <input type="checkbox" checked disabled style="accent-color:<?php echo $tpl['color']; ?>;">
                <input type="hidden" name="pages[]" value="<?php echo $pageKey; ?>">
                <?php endif; ?>
                <i class="<?php echo htmlspecialchars($pageDef['icon']); ?>" style="width:16px;color:<?php echo $tpl['color']; ?>;opacity:.8;"></i>
                <span style="font-size:.85rem;font-weight:500;flex:1;"><?php echo htmlspecialchars($pageDef['label']); ?></span>
                <?php if (!$pageDef['can_disable']): ?>
                <span style="font-size:.65rem;color:var(--text-muted);border:1px solid var(--border);padding:1px 6px;border-radius:4px;">Requis</span>
                <?php endif; ?>
                <?php if ($pageDef['is_admin'] ?? false): ?>
                <span style="font-size:.65rem;color:#f59e0b;border:1px solid rgba(245,158,11,.3);padding:1px 6px;border-radius:4px;">Admin</span>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ ÉTAPE 4 : FACTION ══════════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);border-radius:8px;display:grid;place-items:center;color:var(--blue);font-weight:800;font-size:.85rem;">4</div>
            <div>
                <div style="font-weight:700;">Faction assignée</div>
                <div style="font-size:.75rem;color:var(--text-secondary);">Seuls les membres de cette faction (et le staff) auront accès</div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($factions)): ?>
        <div style="padding:1.5rem;text-align:center;background:rgba(255,255,255,.02);border:1px dashed var(--border);border-radius:10px;">
            <i class="fa-solid fa-users-slash" style="font-size:1.5rem;color:var(--text-muted);margin-bottom:.5rem;"></i>
            <p style="color:var(--text-secondary);font-size:.85rem;">Aucune faction en base de données.</p>
            <p style="color:var(--text-muted);font-size:.75rem;">Créez d'abord une faction depuis <a href="<?php echo SITE_URL; ?>/pages/staff/users/roles.php" style="color:var(--blue);">Gestion des Rôles</a>, ou laissez sans faction (panel accessible au staff uniquement).</p>
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">
            <!-- Option "aucune faction" -->
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                background:rgba(255,255,255,.03);border:1px solid var(--border);
                border-radius:8px;cursor:pointer;">
                <input type="radio" name="panel_faction" value=""
                       <?php echo empty($_POST['panel_faction']??'')?'checked':'';?>
                       style="accent-color:var(--blue);">
                <span style="font-size:.82rem;">Aucune (staff uniquement)</span>
            </label>
            <?php foreach ($factions as $f): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                background:rgba(255,255,255,.03);border:1px solid var(--border);
                border-radius:8px;cursor:pointer;">
                <input type="radio" name="panel_faction" value="<?php echo htmlspecialchars($f['faction_key']); ?>"
                       <?php echo (($_POST['panel_faction']??'')===$f['faction_key'])?'checked':'';?>
                       style="accent-color:<?php echo htmlspecialchars($f['color']); ?>;">
                <i class="fa-solid fa-<?php echo htmlspecialchars($f['icon'] ?? 'shield'); ?>"
                   style="color:<?php echo htmlspecialchars($f['color']); ?>;width:16px;"></i>
                <span style="font-size:.82rem;"><?php echo htmlspecialchars($f['name']); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ BOUTON CRÉER ═══════════════════════════════════════ -->
<div style="display:flex;justify-content:flex-end;gap:12px;margin-bottom:40px;">
    <a href="panels.php" class="btn btn-secondary">Annuler</a>
    <button type="submit" class="btn btn-primary" style="padding:.75rem 2rem;font-size:1rem;">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Générer le panel
    </button>
</div>

</form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// ── Template switcher ──
function selectTemplate(tplKey) {
    // Met à jour radio
    document.querySelectorAll('[name=base_template]').forEach(r => r.checked = (r.value === tplKey));

    // Met à jour visuels des cartes template
    document.querySelectorAll('[id^="tpl-card-"]').forEach(c => {
        const active = c.id === 'tpl-card-' + tplKey;
        c.style.background   = active ? 'rgba(59,130,246,.1)' : 'rgba(255,255,255,.03)';
        c.style.borderColor  = active ? 'rgba(59,130,246,.4)' : 'var(--border)';
    });

    // Affiche les pages correspondantes
    document.querySelectorAll('[id^="pages-"]').forEach(p => {
        p.style.display = p.id === 'pages-' + tplKey ? 'block' : 'none';
    });

    // Met à jour les boutons conseillers
    document.querySelectorAll('#advisorBtns button').forEach(b => {
        const active = b.dataset.tpl === tplKey;
        b.style.background  = active ? 'rgba(59,130,246,.12)' : 'rgba(255,255,255,.03)';
        b.style.borderColor = active ? 'rgba(59,130,246,.4)' : 'var(--border)';
        b.style.color       = active ? 'var(--blue)' : 'var(--text-secondary)';
    });
}

// ── Catégorie selector ──
function selectCategory(labelEl, cat) {
    document.querySelectorAll('[name=panel_category]').forEach(r => {
        const lbl = r.closest('label');
        r.checked = (r.value === cat);
        const sel = r.checked;
        lbl.style.background  = sel ? 'rgba(59,130,246,.12)' : 'rgba(255,255,255,.03)';
        lbl.style.borderColor = sel ? 'rgba(59,130,246,.4)'  : 'var(--border)';
        const ico = lbl.querySelector('i');
        if (ico) ico.style.color = sel ? 'var(--blue)' : 'var(--text-secondary)';
    });
}

// ── Icône preview ──
function updateIconPreview(val) {
    const el = document.querySelector('#iconPreview i');
    if (el) el.className = 'fa-solid fa-' + (val.trim() || 'store');
}

// ── Select all / none pages ──
function checkAllPages(state) {
    const active = document.querySelector('[name=base_template]:checked')?.value;
    if (!active) return;
    document.querySelectorAll('.page-cb-' + active).forEach(cb => cb.checked = state);
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    const current = document.querySelector('[name=base_template]:checked')?.value || 'entreprise';
    selectTemplate(current);
});
</script>