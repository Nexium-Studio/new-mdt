<?php
require_once __DIR__ . '/../../../config/config.php';

$companyName    = 'Rosa Tattoo';
$companyColor   = '#e11d48';
$companyFaction = 'ROSA_TATTOO';
$companyIcon    = 'building';
$companyPath    = 'rosa-tattoo';
$gradeFallback  = [
        ['name'=>'PDG','rank_order'=>1,'can_recruit'=>1,'is_owner'=>1,'is_ceo'=>0,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'Co-PDG','rank_order'=>2,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>1,'can_manage_finances'=>1,'can_manage_stock'=>1],
        ['name'=>'DRH','rank_order'=>3,'can_recruit'=>1,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Expérimenté','rank_order'=>4,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>1],
        ['name'=>'Confirmé','rank_order'=>5,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
        ['name'=>'Novice','rank_order'=>6,'can_recruit'=>0,'is_owner'=>0,'is_ceo'=>0,'can_manage_finances'=>0,'can_manage_stock'=>0],
];

include __DIR__ . '/../../../config/_effectifs_company.php';