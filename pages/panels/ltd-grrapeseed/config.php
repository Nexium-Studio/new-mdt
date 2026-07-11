<?php
return [
    'slug' => 'ltd-grrapeseed',
    'key' => 'LTD_GRRAPESEED',
    'name' => 'LTD Grapeseed',
    'subtitle' => 'Supérette',
    'faction' => 'LTD_GRRAPESEED',
    'color' => '#0ea5e9',
    'icon' => 'fas fa-basket-shopping',
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
