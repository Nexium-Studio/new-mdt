<?php
return [
    'slug' => 'harmony-repair',
    'key' => 'HARMONY_REPAIR',
    'name' => 'Harmony Repair',
    'subtitle' => 'Atelier mécanique',
    'faction' => 'HARMONY_REPAIR',
    'color' => '#64748b',
    'icon' => 'fas fa-gears',
    'access' => 'faction',
    'nav' => [
        ['file' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'section' => null],
        ['file' => 'effectifs', 'label' => 'Effectifs', 'icon' => 'fas fa-users', 'section' => null],
        ['file' => 'prises-service', 'label' => 'Prises de service', 'icon' => 'fas fa-clock', 'section' => null],
        ['file' => 'sanctions', 'label' => 'Sanctions', 'icon' => 'fas fa-gavel', 'section' => null],
        ['file' => 'stock', 'label' => 'Stock', 'icon' => 'fas fa-boxes', 'section' => null],
        ['file' => 'vente', 'label' => 'Vente', 'icon' => 'fas fa-shopping-cart', 'section' => null],
        ['file' => 'facturation', 'label' => 'Facturation', 'icon' => 'fas fa-file-invoice-dollar', 'section' => null],
        ['file' => 'depenses', 'label' => 'Dépenses', 'icon' => 'fas fa-money-bill-wave', 'section' => null],
    ],
];
