<?php
/**
 * Catalogue des panels MDT — utilisé par pages/dashboard.php et index.php (landing).
 */
function mdt_panel_catalog_categories(PDO $db, bool $includeHidden = false): array
{
    $categories = [
        'Services' => [
            ['key' => 'MAIL', 'name' => 'Service Mail', 'icon' => 'envelope-open-text', 'url' => '/pages/mail/index.php', 'color' => '#3b82f6', 'always_active' => true, 'mail_panel' => true],
        ],
        'Académie' => [
            ['key' => 'ACADEMY_PUBLIC', 'name' => 'Académie', 'icon' => 'graduation-cap', 'url' => '/pages/academy/dashboard.php', 'color' => '#8b5cf6', 'always_active' => true, 'academy_public' => true],
            ['key' => 'ACADEMY_CENTRAL', 'name' => 'Académie Centralisée', 'icon' => 'building-columns', 'url' => '/pages/academy-central/dashboard.php', 'color' => '#6366f1', 'always_active' => true, 'academy_central' => true],
        ],
        'Services Publics' => [
            ['key' => 'STAFF',        'name' => 'Panel Staff',    'icon' => 'hard-drive',         'url' => '/pages/staff/dashboard.php',              'faction' => 'STAFF',         'color' => '#a855f7', 'roles' => ['*','Administrateur','Développeur','Staff','Modérateur','Helper','Recruteur'], 'always_active' => true],
            ['key' => 'GOUV',         'name' => 'Gouvernement',   'icon' => 'landmark',            'url' => '/pages/gouv/dashboard.php',               'faction' => 'GOUV',          'color' => '#1d4ed8', 'roles' => ['*','Administrateur','Développeur','Staff']],
            ['key' => 'AVOCATS',      'name' => "Cabinet d'Avocats", 'icon' => 'scale-balanced', 'url' => '/pages/avocats/dashboard.php', 'faction' => 'AVOCATS', 'color' => '#d97706'],
            ['key' => 'DOJ',          'name' => 'DOJ',            'icon' => 'scale-balanced',        'url' => '/pages/doj/dashboard.php',                'faction' => 'DOJ',           'color' => '#ef4444'],
            ['key' => 'USSS',         'name' => 'USSS',           'icon' => 'id-card-clip',           'url' => '/pages/usss/dashboard.php',               'faction' => 'USSS',          'color' => '#334155'],
            ['key' => 'LSPD',         'name' => 'LSPD',           'icon' => 'shield-halved',         'url' => '/pages/lspd/dashboard.php',               'faction' => 'LSPD',          'color' => '#2563eb'],
            ['key' => 'LSSD',         'name' => 'LSSD',           'icon' => 'star',                  'url' => '/pages/lssd/dashboard.php',               'faction' => 'LSSD',          'color' => '#10b981'],
            ['key' => 'SAMS',         'name' => 'SAMS',           'icon' => 'kit-medical',           'url' => '/pages/sams/dashboard.php',               'faction' => 'SAMS',          'color' => '#f43f5e'],
            ['key' => 'GRUPP6',       'name' => 'Grupp 6',        'icon' => 'warehouse',          'url' => '/pages/grupp6/dashboard.php',             'faction' => 'GRUPP6',        'color' => '#64748b'],
            ['key' => 'MARSHALS',     'name' => 'Marshals',       'icon' => 'shield-halved',         'url' => '/pages/panels/marshals/dashboard.php',              'faction' => 'MARSHALS',      'color' => '#475569'],
            ['key' => 'SUPERIOR_COURT_SA', 'name' => 'Superior court of S.A', 'icon' => 'gavel', 'url' => '/pages/panels/superior-court-sa/dashboard.php',   'faction' => 'SUPERIOR_COURT_SA', 'color' => '#b45309'],
            ['key' => 'LSFD',         'name' => 'LSFD',           'icon' => 'fire-flame-curved',     'url' => '/pages/panels/lsfd/dashboard.php',                  'faction' => 'LSFD',          'color' => '#dc2626'],
            ['key' => 'IRS_TREASURY', 'name' => 'Dept of Treasury (IRS)', 'icon' => 'landmark',   'url' => '/pages/panels/irs-treasury/dashboard.php',        'faction' => 'IRS_TREASURY',  'color' => '#0f766e'],
        ],
        'Bars & Boîtes' => [
            ['key' => 'HAUTE_LUXURY', 'name' => 'Haute Luxury',   'icon' => 'gem',                   'url' => '/pages/companies/haute-luxury/dashboard.php', 'faction' => 'HAUTE_LUXURY','color' => '#f472b6'],
            ['key' => 'BAHAMAS',      'name' => 'Bahamas',        'icon' => 'martini-glass-citrus',  'url' => '/pages/companies/bahamas/dashboard.php',  'faction' => 'BAHAMAS',       'color' => '#06b6d4'],
            ['key' => 'YELLOW_JACK',  'name' => 'Yellow Jack',    'icon' => 'whiskey-glass',         'url' => '/pages/panels/yellow-jack/dashboard.php',       'faction' => 'YELLOW_JACK',   'color' => '#ca8a04'],
            ['key' => 'TROPICAL',     'name' => 'Tropical',       'icon' => 'umbrella-beach',        'url' => '/pages/panels/tropical/dashboard.php',          'faction' => 'TROPICAL',      'color' => '#14b8a6'],
            ['key' => 'UNICORN',      'name' => 'Unicorn',        'icon' => 'horse',                 'url' => '/pages/panels/unicorn/dashboard.php',           'faction' => 'UNICORN',       'color' => '#ec4899'],
            ['key' => 'BLACK_WOOD_SALOON', 'name' => 'Black Wood Saloon', 'icon' => 'mug-hot',      'url' => '/pages/panels/black-wood-saloon/dashboard.php', 'faction' => 'BLACK_WOOD_SALOON', 'color' => '#78350f'],
            ['key' => 'TEQUI_LA_LA',  'name' => 'Tequi-la-la',    'icon' => 'martini-glass-citrus',  'url' => '/pages/panels/tequi-la-la/dashboard.php',       'faction' => 'TEQUI_LA_LA',   'color' => '#f97316'],
        ],
        'Garages' => [
            ['key' => 'BENNYS',       'name' => "Benny's",        'icon' => 'phone',   'url' => '/pages/companies/bennys/dashboard.php',   'faction' => 'BENNYS',        'color' => '#f59e0b'],
            ['key' => 'LS_CUSTOM',    'name' => 'LS Custom',      'icon' => 'toolbox',            'url' => '/pages/companies/ls-custom/dashboard.php','faction' => 'LS_CUSTOM',     'color' => '#8b5cf6'],
            ['key' => 'LS_MOTORS',    'name' => 'LS Motors',      'icon' => 'car-rear',              'url' => '/pages/companies/ls-motors/dashboard.php','faction' => 'LS_MOTORS',     'color' => '#3b82f6'],
            ['key' => 'HEAVY_IMPOUND','name' => 'Heavy Impound',  'icon' => 'truck-ramp-box',        'url' => '/pages/companies/heavy-impound/dashboard.php','faction' => 'HEAVY_IMPOUND','color' => '#94a3b8'],
            ['key' => 'TUNERS_SHOP',  'name' => 'Tuner\'s Shop',  'icon' => 'screwdriver-wrench', 'url' => '/pages/panels/tuners-shop/dashboard.php',           'faction' => 'TUNERS_SHOP',   'color' => '#eab308'],
            ['key' => 'PREMIUM_DELUXE_MOTORS', 'name' => 'Premium Deluxe Motorsport', 'icon' => 'car-side', 'url' => '/pages/panels/premium-deluxe-motors/dashboard.php', 'faction' => 'PREMIUM_DELUXE_MOTORS', 'color' => '#6366f1'],
            ['key' => 'HAYES_AUTO',   'name' => 'Hayes',          'icon' => 'wrench',                'url' => '/pages/panels/hayes-auto/dashboard.php',            'faction' => 'HAYES_AUTO',    'color' => '#78716c'],
            ['key' => 'HARMONY_REPAIR', 'name' => 'Harmony Repair', 'icon' => 'gears',             'url' => '/pages/panels/harmony-repair/dashboard.php',      'faction' => 'HARMONY_REPAIR','color' => '#64748b'],
            ['key' => 'LUXURY_AUTO',  'name' => 'Luxury Auto',    'icon' => 'car-rear',              'url' => '/pages/panels/luxury-auto/dashboard.php',           'faction' => 'LUXURY_AUTO',   'color' => '#a855f7'],
        ],
        'Commerces' => [
            ['key' => 'DYNASTY',      'name' => 'Dynasty',        'icon' => 'city',                  'url' => '/pages/companies/dynasty/dashboard.php',  'faction' => 'DYNASTY',       'color' => '#22c55e'],
            ['key' => 'LTD',          'name' => 'LTD Mirror Park','icon' => 'basket-shopping',       'url' => '/pages/companies/ltd/dashboard.php',      'faction' => 'LTD',           'color' => '#0ea5e9'],
            ['key' => 'LTD_LITTLE_SEOUL', 'name' => 'LTD Little Seoul', 'icon' => 'basket-shopping', 'url' => '/pages/panels/ltd-little-seoul/dashboard.php', 'faction' => 'LTD_LITTLE_SEOUL', 'color' => '#38bdf8'],
            ['key' => 'LTD_GRRAPESEED', 'name' => 'LTD Grapeseed', 'icon' => 'basket-shopping',     'url' => '/pages/panels/ltd-grrapeseed/dashboard.php',      'faction' => 'LTD_GRRAPESEED', 'color' => '#0ea5e9'],
            ['key' => 'LTD_STRAWBERRY', 'name' => 'LTD Strawberry', 'icon' => 'basket-shopping',    'url' => '/pages/panels/ltd-strawberry/dashboard.php',    'faction' => 'LTD_STRAWBERRY', 'color' => '#0ea5e9'],
            ['key' => 'LTD_BLAINE_COUNTY', 'name' => 'LTD Blaine County', 'icon' => 'basket-shopping', 'url' => '/pages/panels/ltd-blaine-county/dashboard.php', 'faction' => 'LTD_BLAINE_COUNTY', 'color' => '#0ea5e9'],
            ['key' => 'AMMUNATION',   'name' => 'Ammunation',     'icon' => 'gun',                   'url' => '/pages/companies/ammunation/dashboard.php','faction' => 'AMMUNATION',   'color' => '#ef4444'],
            ['key' => 'LIFE_INVADER', 'name' => 'Life Invader',   'icon' => 'clapperboard',          'url' => '/pages/companies/life-invader/dashboard.php','faction' => 'LIFE_INVADER','color' => '#6366f1'],
            ['key' => 'WEAZEL_NEWS',  'name' => 'Weazel News',    'icon' => 'newspaper',             'url' => '/pages/panels/weazel-news/dashboard.php',     'faction' => 'WEAZEL_NEWS', 'color' => '#38bdf8'],
            ['key' => 'ROSA_TATTOO',  'name' => 'Rosa Tattoo',    'icon' => 'wand-magic-sparkles',   'url' => '/pages/companies/rosa-tattoo/dashboard.php',    'faction' => 'ROSA_TATTOO',   'color' => '#e11d48'],
            ['key' => 'VANGELICO',    'name' => 'Vangelico',      'icon' => 'gem',                   'url' => '/pages/companies/vangelico/dashboard.php',      'faction' => 'VANGELICO',     'color' => '#eab308'],
            ['key' => 'POSTOP',       'name' => 'PostOP',         'icon' => 'truck-fast',            'url' => '/pages/companies/postop/dashboard.php',   'faction' => 'POSTOP',        'color' => '#10b981'],
            ['key' => 'TAXI',         'name' => 'Taxi',           'icon' => 'taxi',                  'url' => '/pages/companies/taxi/dashboard.php',     'faction' => 'TAXI',          'color' => '#eab308'],
            ['key' => 'TRIADS',       'name' => 'Triads Records', 'icon' => 'compact-disc',          'url' => '/pages/companies/triads/dashboard.php',   'faction' => 'TRIADS',        'color' => '#a855f7'],
        ],
        'Restaurants & Hôtels' => [
            ['key' => 'UWU_COFFEE',    'name' => 'UwU Coffee',     'icon' => 'mug-hot',   'url' => '/pages/companies/uwu-coffee/dashboard.php', 'faction' => 'UWU_COFFEE',    'color' => '#f472b6'],
            ['key' => 'RESTAURANT_KOI','name' => 'Restaurant KOI', 'icon' => 'shrimp',    'url' => '/pages/companies/koi/dashboard.php',         'faction' => 'RESTAURANT_KOI','color' => '#f87171'],
            ['key' => 'UP_ATOM',       'name' => 'UP & Atom',      'icon' => 'burger',    'url' => '/pages/companies/up-atom/dashboard.php',    'faction' => 'UP_ATOM',       'color' => '#fbbf24'],
            ['key' => 'PEARLS_RESORT', 'name' => "Pearl's Resort", 'icon' => 'hotel',     'url' => '/pages/companies/pearls-resort/dashboard.php',     'faction' => 'PEARLS_RESORT', 'color' => '#d4a853'],
            ['key' => 'BOAT_HOUSE',    'name' => 'Boat House',     'icon' => 'sailboat',  'url' => '/pages/companies/boat-house/dashboard.php',     'faction' => 'BOAT_HOUSE',    'color' => '#0891b2'],
            ['key' => 'BURGER_SHOT',   'name' => 'Burger Shot',    'icon' => 'burger',    'url' => '/pages/companies/burger-shot/dashboard.php',    'faction' => 'BURGER_SHOT',   'color' => '#f97316'],
            ['key' => 'HORNYS',        'name' => 'Horny\'s',       'icon' => 'utensils',  'url' => '/pages/companies/hornys/dashboard.php',         'faction' => 'HORNYS',        'color' => '#dc2626'],
        ],
        'Activités & Luxe' => [
            ['key' => 'DIAMOND_CASINO', 'name' => 'Diamond Casino', 'icon' => 'dice',    'url' => '/pages/companies/diamond-casino/dashboard.php', 'faction' => 'DIAMOND_CASINO', 'color' => '#a78bfa'],
        ],
        'Activités Illégales' => [
            ['key' => 'DARKNET',      'name' => 'DarkNet',        'icon' => 'network-wired',         'url' => '/pages/panels/darknet/dashboard.php',           'faction' => 'DARKNET',       'color' => '#7c3aed'],
        ],
    ];

    try {
        $customRows = $db->query("SELECT * FROM custom_panels WHERE is_active = 1 ORDER BY created_at ASC")->fetchAll();
        foreach ($customRows as $cp) {
            $cat = $cp['category'] ?? 'Commerces';
            if (!isset($categories[$cat])) {
                $categories[$cat] = [];
            }
            $existingKeys = array_column($categories[$cat], 'key');
            if (in_array($cp['panel_key'], $existingKeys)) {
                continue;
            }
            $categories[$cat][] = [
                'key'     => $cp['panel_key'],
                'name'    => $cp['name'],
                'icon'    => $cp['icon'],
                'color'   => $cp['color'],
                'url'     => '/pages/custom/?panel=' . urlencode($cp['panel_key']),
                'faction' => $cp['faction_key'] ?? '',
                'custom'  => true,
            ];
        }
    } catch (Exception $e) {
    }

    try {
        $divRows = $db->query("
            SELECT fd.panel_key, fd.name, fd.icon, fd.color, fd.target_url, f.faction_key
            FROM faction_divisions fd
            INNER JOIN factions f ON f.id = fd.faction_id
            WHERE fd.show_on_dashboard = 1
            ORDER BY f.faction_key ASC, fd.sort_order ASC, fd.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($divRows)) {
            $categories['Divisions MDT'] = [];
            foreach ($divRows as $dr) {
                $tu = trim((string) ($dr['target_url'] ?? ''));
                if ($tu !== '' && $tu[0] !== '/') {
                    $tu = '/' . ltrim($tu, '/');
                }
                if ($tu === '') {
                    $tu = '/pages/dashboard.php';
                }
                $categories['Divisions MDT'][] = [
                    'key'             => $dr['panel_key'],
                    'name'            => $dr['name'],
                    'icon'            => $dr['icon'] ?: 'layer-group',
                    'color'           => $dr['color'] ?: '#64748b',
                    'url'             => $tu,
                    'faction'         => $dr['faction_key'],
                    'division_panel'  => true,
                ];
            }
        }
    } catch (Exception $e) {
    }

    // ── OVERLAY non-destructif (ordre + catégorie + description depuis la DB) ──
    // Source de vérité = catalogue ci-dessus. L'overlay ne fait que ré-ordonner /
    // ré-catégoriser. Guardé : si l'include ou les tables manquent, on retourne
    // le catalogue inchangé (comportement actuel, zéro régression).
    $__overlayFile = __DIR__ . '/../includes/dashboard/overlay.php';
    if (is_file($__overlayFile)) {
        require_once $__overlayFile;
        if (function_exists('dashboard_overlay_apply')) {
            try {
                $categories = dashboard_overlay_apply($db, $categories, $includeHidden);
            } catch (Exception $e) {
                // toute erreur overlay → on garde le catalogue d'origine
            }
        }
    }

    return $categories;
}
