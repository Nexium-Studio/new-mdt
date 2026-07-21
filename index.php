<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/mdt_panel_catalog.php';
require_once __DIR__ . '/includes/nexium_theme.php';

if (isLoggedIn()) {
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
        redirect(SITE_URL . '/pages/staff/dashboard.php');
    } else {
        redirect(SITE_URL . '/pages/dashboard.php');
    }
}

$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
}

$siteName = defined('SITE_NAME') ? SITE_NAME : 'LysoriaMDT';
$siteTagline = 'Plateforme MDT complète pour la gestion des services publics de roleplay FiveM.';
$homeTitle = 'Le MDT nouvelle génération pour votre serveur.';
$homeMessage = 'Une plateforme centralisée, sécurisée et modulaire pour la gestion complète des services publics et privés de votre ville roleplay. Rapports, casiers, dispatching, facturation — tout en un.';
$themeMode = 'dark';
$primaryColor = '#1E6FE5';
$secondaryColor = '#6C3FC5';
$faviconUrl = '';

if ($db) {
    try {
        $branding = mdt_site_branding($db);
        $siteName = $branding['site_name'];
        $themeMode = $branding['theme_mode'];
        $primaryColor = $branding['primary_color'];
        $secondaryColor = $branding['secondary_color'];
        $faviconUrl = $branding['favicon_url'];

        $rows = mdt_admin_settings_fetch($db, ['home_title', 'home_message', 'site_tagline']);
        if (trim((string) ($rows['home_title'] ?? '')) !== '') {
            $homeTitle = (string) $rows['home_title'];
        }
        if (trim((string) ($rows['home_message'] ?? '')) !== '') {
            $homeMessage = (string) $rows['home_message'];
        }
        if (trim((string) ($rows['site_tagline'] ?? '')) !== '') {
            $siteTagline = (string) $rows['site_tagline'];
        }
    } catch (Exception $e) {
    }
}

$activePanels      = [];
$panelConfigs      = [];
$panelSystemActive = false;

if ($db) {
    try {
        $rows = $db->query("SELECT panel_key, is_active FROM admin_panels")->fetchAll();
        $panelSystemActive = true;
        foreach ($rows as $r) {
            $activePanels[$r['panel_key']] = (bool) $r['is_active'];
        }
    } catch (Exception $e) {
    }

    try {
        $rows = $db->query("SELECT panel_key, custom_name, custom_color, custom_logo_url FROM admin_panel_config")->fetchAll();
        foreach ($rows as $r) {
            $panelConfigs[$r['panel_key']] = $r;
        }
    } catch (Exception $e) {
        try {
            $rows = $db->query("SELECT panel_key, custom_name, custom_color FROM admin_panel_config")->fetchAll();
            foreach ($rows as $r) {
                $panelConfigs[$r['panel_key']] = $r;
            }
        } catch (Exception $e2) {
        }
    }
}

/** Aligné sur le dashboard MDT : clé absente ou table vide ⇒ actif par défaut */
function isPanelActive(string $key): bool {
    global $activePanels, $panelSystemActive;
    if (!$panelSystemActive) {
        return true;
    }
    if (!array_key_exists($key, $activePanels)) {
        return true;
    }
    return (bool) $activePanels[$key];
}

function indexPanelShowsActive(array $p): bool {
    if (!empty($p['always_active'])) {
        return true;
    }
    return isPanelActive($p['key']);
}

function getPanelName(string $key, string $default): string {
    global $panelConfigs;
    return htmlspecialchars($panelConfigs[$key]['custom_name'] ?? $default);
}

function getPanelColor(string $key, string $default): string {
    global $panelConfigs;
    $c = $panelConfigs[$key]['custom_color'] ?? $default;
    return preg_match('/^#[0-9a-fA-F]{3,6}$/', $c) ? $c : $default;
}

function getPanelLogoUrl(string $key): string {
    global $panelConfigs;
    $u = trim((string) ($panelConfigs[$key]['custom_logo_url'] ?? ''));
    if ($u === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $u)) {
        return '';
    }
    return $u;
}

$categories = $db ? mdt_panel_catalog_categories($db) : [];

$totalPanels  = 0;
$totalActive  = 0;
foreach ($categories as $panels) {
    foreach ($panels as $p) {
        $totalPanels++;
        if (indexPanelShowsActive($p)) {
            $totalActive++;
        }
    }
}
$totalCategories = count($categories);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($siteName . ' — ' . $siteTagline); ?>">
    <title><?php echo htmlspecialchars($siteName); ?> — Mobile Data Terminal</title>
    <?php if ($faviconUrl !== ''): ?><link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>"><?php endif; ?>
    <?php nexium_theme_head($themeMode); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ══════════════════════════════════════════════════════════════
           Charte graphique Nexium Studio
           Bleu #1E6FE5 → Violet #6C3FC5 · Montserrat / Inter
           Sobriété : aplats nets, ombres neutres, pas de néon ni de halo.
        ══════════════════════════════════════════════════════════════ */
        :root {
            --bleu:   #1E6FE5;
            --violet: #6C3FC5;
            --cyan:   #2EA8E6;
            --indigo: #241C63;
            --accent:   <?php echo htmlspecialchars($primaryColor); ?>;
            --accent-2: <?php echo htmlspecialchars($secondaryColor); ?>;
            --grad: linear-gradient(120deg, var(--bleu), var(--violet));
        }

        /* Thème sombre (défaut) */
        :root, :root[data-theme="dark"] {
            --bg:            #0C1023;
            --bg-2:          #0a0e1f;
            --surface:       #141a30;
            --surface-2:     #171C31;
            --border:        rgba(255,255,255,.08);
            --border-strong: rgba(255,255,255,.16);
            --text:          #F4F6FB;
            --text-2:        #C9CDDA;
            --muted:         #8891a8;
            --nav-bg:        rgba(12,16,35,.82);
            --footer-bg:     #0a0e1f;
            --card-shadow:   0 12px 30px -14px rgba(0,0,0,.55);
            --soft-shadow:   0 2px 10px rgba(0,0,0,.3);
        }

        /* Thème clair */
        :root[data-theme="light"] {
            --bg:            #FFFFFF;
            --bg-2:          #F5F6FA;
            --surface:       #FFFFFF;
            --surface-2:     #F5F6FA;
            --border:        #E4E7EF;
            --border-strong: #D5DAE6;
            --text:          #1A1F30;
            --text-2:        #3B4258;
            --muted:         #6B7386;
            --nav-bg:        rgba(255,255,255,.85);
            --footer-bg:     #F5F6FA;
            --card-shadow:   0 2px 10px rgba(20,25,45,.06);
            --soft-shadow:   0 2px 10px rgba(20,25,45,.06);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-2);
            min-height: 100vh;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            transition: background .25s ease, color .25s ease;
        }

        h1, h2, h3, h4, .brand-name { font-family: 'Montserrat', sans-serif; color: var(--text); }

        .container { max-width: 1140px; margin: 0 auto; padding: 0 2rem; width: 100%; }

        /* Filet dégradé signature (séparateur discret) */
        .rule {
            height: 3px; border: none; border-radius: 2px;
            background: var(--grad);
        }

        <?php echo nexium_theme_toggle_css(); ?>

        /* ══ NAV ══ */
        .nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 200;
            background: var(--nav-bg);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
        }

        .nav-inner {
            height: 64px; display: flex;
            align-items: center; justify-content: space-between; gap: 24px;
        }

        .nav-brand {
            display: flex; align-items: center; gap: 11px;
            text-decoration: none; color: var(--text);
        }

        .brand-icon {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--grad);
            display: grid; place-items: center; font-size: 15px; color: #fff; flex-shrink: 0;
        }

        .brand-name { font-size: 1.1rem; font-weight: 700; letter-spacing: -.01em; }

        .nav-links { display: flex; align-items: center; gap: 4px; list-style: none; }

        .nav-links a {
            display: block; padding: 7px 14px; color: var(--muted); text-decoration: none;
            font-size: .9rem; font-weight: 500; border-radius: 8px;
            transition: color .15s, background .15s;
        }

        .nav-links a:hover { color: var(--text); background: color-mix(in srgb, var(--text) 6%, transparent); }

        .nav-cta { display: flex; align-items: center; gap: 10px; }

        /* ══ BUTTONS ══ */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 8px;
            font-family: 'Montserrat', sans-serif; font-size: .875rem; font-weight: 600;
            text-decoration: none; cursor: pointer; white-space: nowrap;
            border: 1.5px solid transparent;
            transition: filter .15s ease, background .15s ease, color .15s ease, border-color .15s ease;
        }
        .btn:hover { filter: brightness(1.08); }

        .btn-primary { background: var(--bleu); color: #fff; }
        .btn-secondary { background: var(--violet); color: #fff; }

        .btn-ghost { background: transparent; border-color: var(--border-strong); color: var(--text); }
        .btn-ghost:hover { filter: none; background: color-mix(in srgb, var(--text) 6%, transparent); border-color: var(--bleu); color: var(--bleu); }

        .btn-outline { background: transparent; border-color: var(--bleu); color: var(--bleu); }
        .btn-outline:hover { filter: none; background: color-mix(in srgb, var(--bleu) 8%, transparent); }

        .btn-lg { padding: 13px 26px; font-size: .95rem; }

        /* ══ HERO ══ */
        .hero { position: relative; padding: 138px 0 92px; }

        .hero > .container {
            display: grid; grid-template-columns: 1fr 340px;
            gap: 56px; align-items: center;
        }

        .hero-inner { min-width: 0; }

        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeInUp   { from { opacity: 0; transform: translateY(18px);  } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn     { from { opacity: 0; } to { opacity: 1; } }

        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            border-radius: 99px; padding: 5px 14px;
            font-size: .72rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .16em; color: var(--bleu);
            background: color-mix(in srgb, var(--bleu) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--bleu) 22%, transparent);
            margin-bottom: 26px;
            animation: fadeInDown .6s ease both;
        }
        .hero-eyebrow i { font-size: .55rem; }

        .hero-title {
            font-size: clamp(2.2rem, 4.4vw, 3.5rem);
            font-weight: 700; letter-spacing: -.02em; line-height: 1.08;
            margin-bottom: 22px;
            animation: fadeInUp .6s .1s ease both;
        }

        .hero-desc {
            font-size: 1.02rem; color: var(--text-2); max-width: 520px;
            margin-bottom: 34px; line-height: 1.7;
            animation: fadeInUp .6s .2s ease both;
        }

        .hero-actions {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            margin-bottom: 40px;
            animation: fadeInUp .6s .3s ease both;
        }

        .hero .rule { width: 110px; margin-bottom: 32px; animation: fadeIn .8s .4s ease both; }

        .hero-stats { display: flex; gap: 0; animation: fadeIn .8s .5s ease both; }
        .hero-stat { padding: 0 26px 0 0; border-right: 1px solid var(--border); }
        .hero-stat:first-child { padding-left: 0; }
        .hero-stat:last-child  { border-right: none; padding-right: 0; }
        .hero-stat + .hero-stat { padding-left: 26px; }

        .hero-stat-val { font-family: 'Montserrat', sans-serif; font-size: 1.8rem; font-weight: 700; letter-spacing: -.02em; line-height: 1; margin-bottom: 4px; color: var(--text); }
        .hero-stat-val.blue   { color: var(--bleu); }
        .hero-stat-val.violet { color: var(--violet); }
        .hero-stat-val.cyan   { color: var(--cyan); }
        .hero-stat-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 600; }

        /* Colonne déco droite */
        .hero-deco { display: flex; flex-direction: column; gap: 12px; animation: fadeInUp .7s .3s ease both; }

        .deco-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 12px; padding: 14px 18px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: var(--soft-shadow);
        }
        .deco-card-icon { width: 38px; height: 38px; border-radius: 9px; display: grid; place-items: center; font-size: .95rem; flex-shrink: 0; }
        .deco-card-body { min-width: 0; flex: 1; }
        .deco-card-name { font-family: 'Montserrat', sans-serif; font-size: .85rem; font-weight: 600; margin-bottom: 2px; color: var(--text); }
        .deco-card-sub { font-size: .66rem; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; }
        .deco-card-badge { margin-left: auto; flex-shrink: 0; font-size: .58rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; text-transform: uppercase; letter-spacing: .04em; }
        .badge-active { background: color-mix(in srgb, #16a34a 16%, transparent); color: #16a34a; border: 1px solid color-mix(in srgb, #16a34a 30%, transparent); }
        .badge-soon   { background: color-mix(in srgb, var(--muted) 14%, transparent); color: var(--muted); border: 1px solid var(--border); }

        /* ══ DIVIDER ══ */
        .section-divider { border: none; height: 1px; background: var(--border); margin: 0; }

        /* ══ SECTION HEADERS ══ */
        .section-eyebrow {
            font-size: .72rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .18em; color: var(--bleu); margin-bottom: 12px;
            display: flex; align-items: center; gap: 10px;
        }
        .section-eyebrow::before { content: ''; display: inline-block; width: 22px; height: 2px; background: var(--grad); border-radius: 1px; }

        .section-title { font-size: clamp(1.7rem, 3vw, 2.3rem); font-weight: 700; letter-spacing: -.02em; line-height: 1.15; margin-bottom: 14px; }
        .section-desc { font-size: .98rem; color: var(--text-2); max-width: 540px; }

        /* ══ PANELS ══ */
        .panels-section { padding: 76px 0 56px; }
        .section-header { margin-bottom: 48px; }

        .category-block { margin-bottom: 40px; animation: fadeUp .5s ease both; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

        .category-header { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
        .category-label {
            font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .12em; color: var(--muted); white-space: nowrap;
            display: flex; align-items: center; gap: 8px;
        }
        .category-count { background: color-mix(in srgb, var(--text) 6%, transparent); border: 1px solid var(--border); border-radius: 5px; padding: 1px 7px; font-size: .6rem; color: var(--muted); }
        .category-line { flex: 1; height: 1px; background: var(--border); }

        .panels-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }

        .panel-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 12px; padding: 20px 18px 18px;
            display: flex; flex-direction: column; gap: 12px;
            position: relative; overflow: hidden; text-decoration: none; color: inherit;
            box-shadow: var(--soft-shadow);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
            cursor: default;
        }
        .panel-card--clickable { cursor: pointer; }
        .panel-card--clickable:hover {
            transform: translateY(-4px);
            border-color: color-mix(in srgb, var(--p-color, var(--bleu)) 45%, transparent);
            box-shadow: var(--card-shadow);
        }
        .panel-card--off { opacity: .45; }

        .panel-card-icon {
            width: 44px; height: 44px; border-radius: 10px;
            display: grid; place-items: center; font-size: 1.2rem;
            color: var(--p-color, var(--bleu));
            background: color-mix(in srgb, var(--p-color, var(--bleu)) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--p-color, var(--bleu)) 22%, transparent);
            flex-shrink: 0; transition: all .2s;
        }
        .panel-card--clickable:hover .panel-card-icon { background: var(--p-color, var(--bleu)); color: #fff; }
        .panel-card-icon--logo { padding: 6px; }
        .panel-card-icon--logo img { width: 100%; height: 100%; max-width: 30px; max-height: 30px; object-fit: contain; display: block; }
        .panel-card--clickable:hover .panel-card-icon--logo { background: color-mix(in srgb, var(--p-color, var(--bleu)) 20%, transparent); color: inherit; }

        .panel-card-name { font-family: 'Montserrat', sans-serif; font-size: .9rem; font-weight: 600; margin-bottom: 2px; color: var(--text); }
        .panel-card-key { font-size: .62rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }

        .panel-status-dot { width: 7px; height: 7px; border-radius: 50%; background: #16a34a; position: absolute; top: 14px; right: 14px; }
        .panel-lock { position: absolute; top: 12px; right: 12px; font-size: .65rem; color: var(--muted); }

        /* ══ FEATURES ══ */
        .features-section { padding: 76px 0; background: var(--bg-2); }
        .features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; margin-top: 44px; }

        .feature-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 14px; padding: 28px 24px;
            box-shadow: var(--soft-shadow);
            transition: border-color .2s, transform .2s, box-shadow .2s;
        }
        .feature-card:hover { border-color: var(--border-strong); transform: translateY(-3px); box-shadow: var(--card-shadow); }
        .feature-icon { width: 48px; height: 48px; border-radius: 12px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 18px; }
        .feature-card h3 { font-size: 1.02rem; font-weight: 600; margin-bottom: 10px; }
        .feature-card p  { font-size: .9rem; color: var(--text-2); line-height: 1.65; }

        /* ══ STATS ══ */
        .stats-section { padding: 72px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1px; background: var(--border);
            border: 1px solid var(--border); border-radius: 14px; overflow: hidden;
        }
        .stat-cell { background: var(--surface); padding: 34px 26px; text-align: center; }
        .stat-cell-val { font-family: 'Montserrat', sans-serif; font-size: 2.3rem; font-weight: 700; letter-spacing: -.02em; margin-bottom: 6px; }
        .stat-cell-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 600; }

        /* ══ CTA ══ */
        .cta-section { padding: 80px 0; }
        .cta-box {
            background: var(--indigo);
            border-radius: 18px; padding: 58px 48px; text-align: center; position: relative; overflow: hidden;
        }
        .cta-box .rule { width: 90px; margin: 0 auto 24px; }
        .cta-box h2 { color: #F4F6FB; font-size: clamp(1.6rem, 3vw, 2.1rem); font-weight: 700; letter-spacing: -.02em; margin-bottom: 14px; }
        .cta-box p { color: #C9CDDA; margin-bottom: 32px; font-size: .98rem; max-width: 560px; margin-left: auto; margin-right: auto; }
        .cta-actions { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .cta-box .btn-outline { border-color: var(--cyan); color: var(--cyan); }
        .cta-box .btn-outline:hover { background: color-mix(in srgb, var(--cyan) 14%, transparent); }

        /* ══ FOOTER ══ */
        .footer { background: var(--footer-bg); border-top: 1px solid var(--border); padding: 32px 0; }
        .footer-inner { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .footer-brand { display: flex; align-items: center; gap: 10px; font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 600; color: var(--text); text-decoration: none; }
        .footer-copy { font-size: .78rem; color: var(--muted); }
        .footer-copy a { color: var(--text-2); text-decoration: none; transition: color .15s; }
        .footer-copy a:hover { color: var(--text); }
        .footer-nexium { font-size: .78rem; color: var(--muted); margin-top: 4px; }
        .footer-nexium a { color: var(--bleu); text-decoration: none; font-weight: 600; }
        .footer-nexium a:hover { text-decoration: underline; }
        .footer-links { display: flex; gap: 4px; }
        .footer-links a { color: var(--text-2); text-decoration: none; font-size: .8rem; padding: 4px 10px; border-radius: 6px; transition: color .15s, background .15s; }
        .footer-links a:hover { color: var(--text); background: color-mix(in srgb, var(--text) 6%, transparent); }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 1050px) {
            .hero > .container { grid-template-columns: 1fr; }
            .hero-deco { display: none; }
        }
        @media (max-width: 768px) {
            .container { padding: 0 1.1rem; }
            .nav-links  { display: none; }
            .hero { padding: 108px 0 68px; }
            .panels-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .features-grid { grid-template-columns: 1fr; }
            .cta-box { padding: 42px 24px; }
            .footer-inner { flex-direction: column; text-align: center; }
        }
        @media (max-width: 480px) {
            .panels-grid { grid-template-columns: repeat(2, 1fr); }
            .hero-actions { flex-direction: column; align-items: stretch; }
            .btn-lg { width: 100%; justify-content: center; }
            .nav-cta .btn-ghost { display: none; }
        }
    </style>
</head>
<body>

<!-- ══ NAV ══ -->
<nav class="nav" id="nav">
    <div class="container">
        <div class="nav-inner">
            <a href="#home" class="nav-brand">
                <div class="brand-icon"><i class="fa-solid fa-terminal"></i></div>
                <span class="brand-name"><?php echo htmlspecialchars($siteName); ?></span>
            </a>
            <ul class="nav-links">
                <li><a href="#panels">Panels</a></li>
                <li><a href="#features">Fonctionnalités</a></li>
                <li><a href="#stats">À propos</a></li>
            </ul>
            <div class="nav-cta">
                <?php nexium_theme_toggle(); ?>
                <a href="register.php" class="btn btn-ghost">Inscription</a>
                <a href="login.php" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>Connexion
                </a>
            </div>
        </div>
    </div>
</nav>


<!-- ══ HERO ══ -->
<section class="hero" id="home">
    <div class="container">

        <!-- Colonne gauche -->
        <div class="hero-inner">
            <div class="hero-eyebrow">
                <i class="fa-solid fa-circle"></i>
                Système Opérationnel
            </div>

            <h1 class="hero-title"><?php echo htmlspecialchars($homeTitle); ?></h1>

            <p class="hero-desc">
                <?php echo htmlspecialchars($homeMessage); ?>
            </p>

            <div class="hero-actions">
                <a href="register.php" class="btn btn-primary btn-lg">
                    <i class="fa-solid fa-user-plus"></i>Créer un compte
                </a>
                <a href="login.php" class="btn btn-outline btn-lg">
                    Accéder au MDT<i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <hr class="rule">

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-val blue"><?php echo $totalPanels; ?></div>
                    <div class="hero-stat-label">Panels</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val cyan"><?php echo $totalActive; ?></div>
                    <div class="hero-stat-label">Actifs</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val violet"><?php echo $totalCategories; ?></div>
                    <div class="hero-stat-label">Catégories</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val">24/7</div>
                    <div class="hero-stat-label">Disponible</div>
                </div>
            </div>
        </div>

        <!-- Colonne droite : cards déco -->
        <div class="hero-deco" aria-hidden="true">
            <?php
            $decoItems = [
                ['key'=>'LSPD',  'name'=>'LSPD',        'faction'=>'Police Dept',     'icon'=>'shield-halved',  'color'=>'#1E6FE5'],
                ['key'=>'SAMS',  'name'=>'SAMS',         'faction'=>'Medical Service', 'icon'=>'kit-medical',    'color'=>'#c41e3a'],
                ['key'=>'DOJ',   'name'=>'DOJ',          'faction'=>'Justice Dept',    'icon'=>'scale-balanced', 'color'=>'#6C3FC5'],
                ['key'=>'GOUV',  'name'=>'Gouvernement', 'faction'=>'Service Public',  'icon'=>'landmark',       'color'=>'#2EA8E6'],
            ];
            foreach ($decoItems as $d):
                $active = isPanelActive($d['key']);
            ?>
            <div class="deco-card">
                <div class="deco-card-icon" style="background:color-mix(in srgb,<?php echo $d['color']; ?> 15%,transparent);color:<?php echo $d['color']; ?>;">
                    <i class="fa-solid fa-<?php echo htmlspecialchars($d['icon']); ?>"></i>
                </div>
                <div class="deco-card-body">
                    <div class="deco-card-name"><?php echo htmlspecialchars($d['name']); ?></div>
                    <div class="deco-card-sub"><?php echo htmlspecialchars($d['faction']); ?></div>
                </div>
                <span class="deco-card-badge <?php echo $active ? 'badge-active' : 'badge-soon'; ?>">
                    <?php echo $active ? 'Actif' : 'Bientôt'; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<hr class="section-divider">


<!-- ══ PANELS ══ -->
<section class="panels-section" id="panels">
    <div class="container">
        <div class="section-header">
            <div class="section-eyebrow">Panels disponibles</div>
            <h2 class="section-title">Tous vos services,<br>au même endroit.</h2>
            <p class="section-desc">
                <?php echo $totalPanels; ?> panels répartis en <?php echo $totalCategories; ?> catégories — services publics, commerces, garages, restaurants et plus encore.
            </p>
        </div>

        <?php $delay = 0; foreach ($categories as $catName => $panels): ?>
        <?php
            $activeCount = 0;
            foreach ($panels as $p) {
                if (indexPanelShowsActive($p)) {
                    $activeCount++;
                }
            }
        ?>
        <div class="category-block" style="animation-delay:<?php echo $delay * 70; ?>ms">
            <div class="category-header">
                <div class="category-label">
                    <?php echo htmlspecialchars($catName); ?>
                    <span class="category-count"><?php echo $activeCount; ?>/<?php echo count($panels); ?></span>
                </div>
                <div class="category-line"></div>
            </div>
            <div class="panels-grid">
                <?php foreach ($panels as $panel):
                    $key     = $panel['key'];
                    $name    = getPanelName($key, $panel['name']);
                    $color   = getPanelColor($key, $panel['color']);
                    $logoUrl = getPanelLogoUrl($key);
                    $active  = indexPanelShowsActive($panel);
                ?>
                <div
                    class="panel-card <?php echo $active ? 'panel-card--clickable' : 'panel-card--off'; ?>"
                    style="--p-color:<?php echo $color; ?>;"
                    title="<?php echo $name; ?><?php echo $active ? '' : ' — Bientôt disponible'; ?>"
                >
                    <?php if ($active): ?>
                        <div class="panel-status-dot" title="Actif"></div>
                    <?php else: ?>
                        <i class="fa-solid fa-lock panel-lock" title="Désactivé"></i>
                    <?php endif; ?>
                    <div class="panel-card-icon<?php echo $logoUrl !== '' ? ' panel-card-icon--logo' : ''; ?>">
                        <?php if ($logoUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                        <i class="fa-solid fa-<?php echo htmlspecialchars($panel['icon']); ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="panel-card-name"><?php echo $name; ?></div>
                        <div class="panel-card-key"><?php echo htmlspecialchars($key); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php $delay++; endforeach; ?>
    </div>
</section>


<!-- ══ FEATURES ══ -->
<section class="features-section" id="features">
    <div class="container">
        <div class="section-eyebrow">Pourquoi nous choisir</div>
        <h2 class="section-title">Conçu pour la performance.</h2>
        <p class="section-desc">Chaque fonctionnalité a été pensée pour les serveurs roleplay FiveM les plus exigeants.</p>

        <div class="features-grid">
            <?php
            $features = [
                ['icon'=>'shield-halved', 'color'=>'#1E6FE5', 'title'=>'Sécurité Renforcée',
                 'desc'=>'Authentification multi-méthodes (Email + Discord OAuth2), sessions sécurisées, protection CSRF et journalisation complète de chaque action.'],
                ['icon'=>'bolt',          'color'=>'#2EA8E6', 'title'=>'Temps Réel',
                 'desc'=>'Dispatching live, statuts mis à jour instantanément, notifications de service et suivi des patrouilles en temps réel.'],
                ['icon'=>'layer-group',   'color'=>'#6C3FC5', 'title'=>'Multi-Services',
                 'desc'=>"Services publics (LSPD, SAMS, DOJ), commerces, garages, restaurants — tout dans une seule plateforme unifiée."],
                ['icon'=>'file-lines',    'color'=>'#1E6FE5', 'title'=>'Rapports & Logs',
                 'desc'=>"Système de rapports horodatés, casiers judiciaires, logs d'activité détaillés et historique complet de chaque intervention."],
                ['icon'=>'sliders',       'color'=>'#6C3FC5', 'title'=>'Configurable',
                 'desc'=>'Activation/désactivation de chaque panel via le panel admin, noms et couleurs personnalisables, thèmes et plugins extensibles.'],
                ['icon'=>'mobile-screen', 'color'=>'#2EA8E6', 'title'=>'Responsive',
                 'desc'=>'Interface entièrement responsive, accessible sur desktop, tablette et mobile avec une expérience utilisateur optimisée.'],
            ];
            foreach ($features as $f):
            ?>
            <div class="feature-card">
                <div class="feature-icon" style="background:color-mix(in srgb,<?php echo $f['color']; ?> 12%,transparent);color:<?php echo $f['color']; ?>;">
                    <i class="fa-solid fa-<?php echo htmlspecialchars($f['icon']); ?>"></i>
                </div>
                <h3><?php echo htmlspecialchars($f['title']); ?></h3>
                <p><?php echo htmlspecialchars($f['desc']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- ══ STATS ══ -->
<section class="stats-section" id="stats">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:var(--bleu);"><?php echo $totalPanels; ?>+</div>
                <div class="stat-cell-label">Panels intégrés</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:var(--cyan);"><?php echo $totalCategories; ?></div>
                <div class="stat-cell-label">Catégories</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:var(--violet);">24/7</div>
                <div class="stat-cell-label">Disponibilité</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:var(--bleu);">100%</div>
                <div class="stat-cell-label">Sécurisé</div>
            </div>
        </div>
    </div>
</section>


<!-- ══ CTA ══ -->
<section class="cta-section">
    <div class="container">
        <div class="cta-box">
            <hr class="rule">
            <h2>Prêt à rejoindre la ville ?</h2>
            <p>Créez votre compte en quelques secondes et accédez à l'ensemble des services disponibles selon votre faction.</p>
            <div class="cta-actions">
                <a href="register.php" class="btn btn-primary btn-lg">
                    <i class="fa-solid fa-user-plus"></i>Créer un compte gratuitement
                </a>
                <a href="login.php" class="btn btn-outline btn-lg">J'ai déjà un compte</a>
            </div>
        </div>
    </div>
</section>


<!-- ══ FOOTER ══ -->
<footer class="footer">
    <div class="container">
        <div class="footer-inner">
            <a href="#home" class="footer-brand">
                <div class="brand-icon" style="width:28px;height:28px;font-size:12px;border-radius:7px;">
                    <i class="fa-solid fa-terminal"></i>
                </div>
                <?php echo htmlspecialchars($siteName); ?>
            </a>
            <nav class="footer-links">
                <a href="#panels">Panels</a>
                <a href="#features">Fonctionnalités</a>
                <a href="login.php">Connexion</a>
                <a href="register.php">Inscription</a>
            </nav>
            <div>
                <div class="footer-copy">
                    &copy; <?php echo date('Y'); ?>
                    <a href="login.php"><?php echo htmlspecialchars($siteName); ?></a>.
                    Tous droits réservés.
                </div>
                <div class="footer-nexium">
                    Développé par <a href="https://nexium-studio.com" target="_blank" rel="noopener">Nexium Studio</a>
                </div>
            </div>
        </div>
    </div>
</footer>


<script>
/* Ombre navbar au scroll */
(function () {
    const nav = document.getElementById('nav');
    if (!nav) return;
    window.addEventListener('scroll', () => {
        nav.style.boxShadow = window.scrollY > 10 ? '0 4px 20px rgba(20,25,45,.10)' : 'none';
    }, { passive: true });
})();

/* Animations d'apparition */
(function () {
    const blocks = document.querySelectorAll('.category-block, .feature-card, .stat-cell');
    if (!('IntersectionObserver' in window)) return;
    const io = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity   = '1';
                entry.target.style.transform = 'translateY(0)';
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.08 });
    blocks.forEach(el => {
        el.style.opacity    = '0';
        el.style.transform  = 'translateY(18px)';
        el.style.transition = 'opacity .5s ease, transform .5s ease';
        io.observe(el);
    });
})();
</script>
<?php nexium_theme_script(); ?>

</body>
</html>
