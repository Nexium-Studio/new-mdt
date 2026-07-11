<?php
require_once __DIR__ . '/../../../config/config.php';

$companyName    = '🏨 Pearl\'s Resort';
$companyColor   = '#d4a853';
$companyFaction = 'PEARLS_RESORT';
$companyIcon    = 'building';
$companyPath    = 'pearls-resort';
$gradeFallback  = [
        ['name'=>'PDG','rank_order'=>1,'can_recruit'=>1,'is_owner'=>1,'is_ceo'=>0,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'CO-PDG','rank_order'=>2,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>1,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'RH','rank_order'=>3,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>"Maître d\'hôtel",'rank_order'=>4,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Réceptionniste','rank_order'=>5,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>'Employé','rank_order'=>6,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
];

include __DIR__ . '/../../../config/_effectifs_company.php';