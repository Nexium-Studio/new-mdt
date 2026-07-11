<?php
return [
    'slug' => 'lsfd',
    'key' => 'LSFD',
    'name' => 'LSFD',
    'subtitle' => 'Los Santos Fire Department',
    'faction' => 'LSFD',
    'color' => '#dc2626',
    'icon' => 'fas fa-fire-flame-curved',
    'access' => 'faction',
    'nav' => [
        ['file' => 'dashboard',      'label' => 'Dashboard',        'icon' => 'fas fa-gauge-high',         'section' => null],

        // Opérations
        ['file' => 'dispatch',       'label' => 'Dispatch',         'icon' => 'fas fa-tower-broadcast',    'section' => 'Opérations'],
        ['file' => 'interventions',  'label' => 'Interventions',    'icon' => 'fas fa-truck-fast',         'section' => 'Opérations'],
        ['file' => 'rapports',       'label' => "Rapports d'intervention", 'icon' => 'fas fa-file-lines',  'section' => 'Opérations'],
        ['file' => 'pcr',            'label' => 'Dossiers PCR',     'icon' => 'fas fa-notes-medical',      'section' => 'Opérations'],
        ['file' => 'enquetes',       'label' => 'Enquêtes incendie','icon' => 'fas fa-magnifying-glass-chart', 'section' => 'Opérations'],

        // Ressources
        ['file' => 'parc',           'label' => 'Parc auto',        'icon' => 'fas fa-truck-medical',      'section' => 'Ressources'],
        ['file' => 'materiel',       'label' => 'Matériel & stock', 'icon' => 'fas fa-boxes-stacked',      'section' => 'Ressources'],
        ['file' => 'hazmat',         'label' => 'Codes HazMat',     'icon' => 'fas fa-radiation',          'section' => 'Ressources'],

        // Prévention
        ['file' => 'inspections',    'label' => 'Prévention & inspections', 'icon' => 'fas fa-clipboard-check', 'section' => 'Prévention'],

        // Personnel
        ['file' => 'effectifs',      'label' => 'Effectifs',        'icon' => 'fas fa-people-group',       'section' => 'Personnel'],
        ['file' => 'certifications', 'label' => 'Certifications',   'icon' => 'fas fa-award',              'section' => 'Personnel'],

        // Pilotage
        ['file' => 'statistiques',   'label' => 'Statistiques',     'icon' => 'fas fa-chart-line',         'section' => 'Pilotage'],
        ['file' => 'annonces',       'label' => 'Annonces',         'icon' => 'fas fa-bullhorn',           'section' => 'Pilotage'],
    ],
];
