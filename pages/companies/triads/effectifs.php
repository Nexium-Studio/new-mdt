<?php
require_once __DIR__ . '/../../../config/config.php';

$companyName    = '🎵 Triads Records';
$companyColor   = '#a855f7';
$companyFaction = 'TRIADS';
$companyIcon    = 'building';
$companyPath    = 'triads';
$gradeFallback  = [
        ['name'=>'Directeur Artistique','rank_order'=>1,'can_recruit'=>1,'is_owner'=>1,'is_ceo'=>0,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'Producteur','rank_order'=>2,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Ingénieur','rank_order'=>3,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>'Assistant','rank_order'=>4,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>'Stagiaire','rank_order'=>5,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
];

include __DIR__ . '/../../../config/_effectifs_company.php';