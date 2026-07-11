<?php
/**
 * Taxonomie partagée des types d'incidents LSFD (cahier des charges §4).
 * Utilisée par dispatch.php et interventions.php.
 *
 * Renvoie :
 *   'categories' => clé => [label, couleur badge, icône]
 *   'types'      => clé catégorie => [sous-types]
 *   'sams'       => sous-types qui déclenchent un co-dispatch SAMS (blessés)
 *   'lspd'       => sous-types qui déclenchent une alerte LSPD (périmètre)
 */
return [
    'categories' => [
        'incendie'  => ['Incendie', 'red', 'fa-fire'],
        'secours'   => ['Secours à personne', 'orange', 'fa-truck-medical'],
        'hazmat'    => ['HazMat', 'yellow', 'fa-radiation'],
        'sauvetage' => ['Sauvetage technique', 'blue', 'fa-helmet-safety'],
        'divers'    => ['Divers', 'gray', 'fa-circle-info'],
    ],
    'types' => [
        'incendie' => [
            'Incendie structure (maison, immeuble, entrepôt)',
            'Incendie véhicule',
            'Incendie végétation / feu de forêt',
            'Incendie industriel',
            'Fuite de gaz avec risque incendie',
            'Explosion',
        ],
        'secours' => [
            'Accident de la route avec blessés',
            'Arrêt cardiaque',
            'Traumatisme grave',
            'Noyade / sauvetage nautique',
            'Chute de hauteur',
            'Intoxication / overdose',
            'Personne coincée / désincarcération',
        ],
        'hazmat' => [
            'Déversement de matières dangereuses',
            'Fuite chimique / gaz toxique',
            'Contamination radiologique',
            'Colis suspect',
        ],
        'sauvetage' => [
            'Sauvetage en hauteur',
            'Personne coincée dans ascenseur',
            'Effondrement de structure',
            'Espace confiné',
            'Sauvetage en eaux vives',
        ],
        'divers' => [
            'Alarme incendie (investigation)',
            'Inspection de sécurité',
            'Assistance à la population',
            'Assistance LSPD (sécurisation de scène)',
        ],
    ],
    // Tous les sous-types de "secours" impliquent des blessés → co-dispatch SAMS.
    'sams' => ['secours'],
    // Catégorie hazmat + le sous-type "Explosion" → alerte LSPD (périmètre).
    'lspd' => ['hazmat'],
    'lspd_types' => ['Explosion'],
];
