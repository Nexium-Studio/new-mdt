<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/mdt_panel_catalog.php';

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
$primaryColor = '#3B82F6';
$secondaryColor = '#6366F1';
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:       #03070f;
            --bg-2:     #080f1d;
            --bg-card:  rgba(10,17,32,0.75);
            --border:   rgba(255,255,255,0.07);
            --border-2: rgba(255,255,255,0.12);
            --text:     #e2e8f0;
            --muted:    #475569;
            --muted-2:  #64748b;
            --accent:   <?php echo htmlspecialchars($primaryColor); ?>;
            --accent-2: <?php echo htmlspecialchars($secondaryColor); ?>;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container { max-width: 1280px; margin: 0 auto; padding: 0 2rem; width: 100%; }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
            opacity: .4;
        }

        /* ══ NAV ══ */
        .nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 200;
            background: rgba(3,7,15,0.85);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }

        .nav-inner {
            height: 64px; display: flex;
            align-items: center; justify-content: space-between; gap: 24px;
        }

        .nav-brand {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none; color: var(--text);
        }

        .brand-icon {
            width: 34px; height: 34px; background: var(--accent); border-radius: 9px;
            display: grid; place-items: center; font-size: 15px; color: #fff; flex-shrink: 0;
        }

        .brand-name { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800; letter-spacing: -.02em; }

        .nav-links { display: flex; align-items: center; gap: 6px; list-style: none; }

        .nav-links a {
            display: block; padding: 6px 14px; color: var(--muted-2); text-decoration: none;
            font-size: .875rem; font-weight: 500; border-radius: 8px;
            transition: color .2s, background .2s;
        }

        .nav-links a:hover { color: var(--text); background: rgba(255,255,255,.05); }

        .nav-cta { display: flex; align-items: center; gap: 8px; }

        /* ══ BUTTONS ══ */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 20px; border-radius: 10px; font-size: .875rem; font-weight: 600;
            text-decoration: none; transition: all .2s cubic-bezier(.4,0,.2,1);
            cursor: pointer; border: none; white-space: nowrap;
        }

        .btn-ghost { color: var(--muted-2); background: transparent; border: 1px solid var(--border); }
        .btn-ghost:hover { color: var(--text); background: rgba(255,255,255,.06); border-color: var(--border-2); }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 8px 24px -4px rgba(59,130,246,.45); }

        .btn-lg { padding: 13px 28px; font-size: .95rem; border-radius: 12px; }

        .btn-outline { background: transparent; color: var(--text); border: 1px solid var(--border-2); }
        .btn-outline:hover { background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.2); }

        /* ══ HERO ══
           CORRECTION : le .container du hero est un CSS grid 2 colonnes.
           .hero-deco est dans le flux normal (colonne droite), plus en position: absolute.
           Résultat : les cards restent dans les limites du container.
        ══════════════════════════════════════════════════════════════════ */
        .hero {
            position: relative;
            padding: 140px 0 100px;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute; top: -200px; left: -200px;
            width: 700px; height: 700px;
            background: radial-gradient(circle, rgba(59,130,246,.12) 0%, transparent 65%);
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute; bottom: -100px; right: -100px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(99,102,241,.08) 0%, transparent 65%);
            pointer-events: none;
        }

        /* ↓ La clé du fix : grid sur le container, PAS position absolute sur la déco */
        .hero > .container {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 48px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .hero-inner { min-width: 0; }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.2);
            border-radius: 99px; padding: 5px 14px 5px 8px;
            font-size: .72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .12em; color: var(--accent); margin-bottom: 28px;
            animation: fadeInDown .6s ease both;
        }

        .hero-eyebrow i { font-size: .7rem; }

        .hero-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2.4rem, 4.5vw, 4rem);
            font-weight: 800; letter-spacing: -.04em; line-height: 1.06;
            margin-bottom: 24px;
            animation: fadeInUp .6s .1s ease both;
        }

        .hero-title .line { display: block; }

        .hero-title .accent-line {
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }

        .hero-desc {
            font-size: 1rem; color: var(--muted-2); max-width: 520px;
            margin-bottom: 40px; line-height: 1.7;
            animation: fadeInUp .6s .2s ease both;
        }

        .hero-actions {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            margin-bottom: 56px;
            animation: fadeInUp .6s .3s ease both;
        }

        .hero-stats { display: flex; gap: 0; animation: fadeIn .8s .5s ease both; }

        .hero-stat { padding: 0 28px 0 0; border-right: 1px solid var(--border); }
        .hero-stat:first-child { padding-left: 0; }
        .hero-stat:last-child  { border-right: none; padding-right: 0; }
        .hero-stat + .hero-stat { padding-left: 28px; }

        .hero-stat-val {
            font-family: 'Syne', sans-serif; font-size: 1.9rem; font-weight: 800;
            letter-spacing: -.04em; line-height: 1; margin-bottom: 4px;
        }

        .hero-stat-val.blue  { color: var(--accent); }
        .hero-stat-val.green { color: #22c55e; }
        .hero-stat-val.gold  { color: #f59e0b; }

        .hero-stat-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 600; }

        /* Colonne déco droite — dans le flux, animée */
        .hero-deco {
            display: flex;
            flex-direction: column;
            gap: 10px;
            animation: floatY 6s ease-in-out infinite;
        }

        @keyframes floatY {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-10px); }
        }

        .deco-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 14px; padding: 14px 18px; backdrop-filter: blur(12px);
            display: flex; align-items: center; gap: 14px;
        }

        .deco-card-icon {
            width: 36px; height: 36px; border-radius: 9px;
            display: grid; place-items: center; font-size: .95rem; flex-shrink: 0;
        }

        .deco-card-body { min-width: 0; flex: 1; }

        .deco-card-name { font-family: 'Syne', sans-serif; font-size: .83rem; font-weight: 700; margin-bottom: 2px; }

        .deco-card-sub { font-size: .65rem; color: var(--muted-2); text-transform: uppercase; letter-spacing: .07em; }

        .deco-card-badge {
            margin-left: auto; flex-shrink: 0;
            font-size: .58rem; font-weight: 700;
            padding: 2px 8px; border-radius: 99px; text-transform: uppercase;
        }

        .badge-active { background: rgba(34,197,94,.12); color: #4ade80; border: 1px solid rgba(34,197,94,.2); }
        .badge-soon   { background: rgba(100,116,139,.1); color: var(--muted-2); border: 1px solid var(--border); }

        /* ══ DIVIDER ══ */
        .section-divider {
            border: none; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-2), transparent);
            margin: 0;
        }

        /* ══ PANELS ══ */
        .panels-section { padding: 80px 0 60px; position: relative; }

        .section-header { margin-bottom: 56px; }

        .section-eyebrow {
            font-size: .7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .14em; color: var(--accent); margin-bottom: 12px;
            display: flex; align-items: center; gap: 8px;
        }

        .section-eyebrow::before {
            content: ''; display: inline-block; width: 18px; height: 2px;
            background: var(--accent); border-radius: 1px;
        }

        .section-title {
            font-family: 'Syne', sans-serif; font-size: clamp(1.8rem, 3vw, 2.4rem);
            font-weight: 800; letter-spacing: -.03em; line-height: 1.1; margin-bottom: 14px;
        }

        .section-desc { font-size: .95rem; color: var(--muted-2); max-width: 500px; }

        .category-block { margin-bottom: 44px; animation: fadeUp .5s ease both; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .category-header { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }

        .category-label {
            font-size: .66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .14em; color: var(--muted); white-space: nowrap;
            display: flex; align-items: center; gap: 8px;
        }

        .category-count {
            background: rgba(255,255,255,.06); border: 1px solid var(--border);
            border-radius: 4px; padding: 1px 6px; font-size: .58rem; color: var(--muted-2);
        }

        .category-line { flex: 1; height: 1px; background: var(--border); }

        .panels-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }

        .panel-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; padding: 20px 18px 18px;
            display: flex; flex-direction: column; gap: 12px;
            position: relative; overflow: hidden; text-decoration: none; color: inherit;
            backdrop-filter: blur(8px);
            transition: transform .3s cubic-bezier(.4,0,.2,1), border-color .3s, box-shadow .3s, background .3s;
            cursor: default;
        }

        .panel-card--clickable { cursor: pointer; }

        .panel-card::before {
            content: ''; position: absolute; top: 0; left: 20px; right: 20px; height: 1px;
            background: linear-gradient(90deg, transparent, var(--p-color, #3b82f6), transparent);
            opacity: 0; transition: opacity .3s;
        }

        .panel-card--clickable:hover {
            transform: translateY(-5px);
            border-color: color-mix(in srgb, var(--p-color,#3b82f6) 40%, transparent);
            background: rgba(10,18,36,.9);
            box-shadow: 0 16px 36px -10px rgba(0,0,0,.6), 0 0 0 1px color-mix(in srgb, var(--p-color,#3b82f6) 12%, transparent);
        }

        .panel-card--clickable:hover::before { opacity: 1; }

        .panel-card--off { opacity: .38; filter: grayscale(.6); }

        .panel-card-icon {
            width: 44px; height: 44px; border-radius: 11px;
            display: grid; place-items: center; font-size: 1.2rem;
            color: var(--p-color, #3b82f6);
            background: color-mix(in srgb, var(--p-color,#3b82f6) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--p-color,#3b82f6) 20%, transparent);
            flex-shrink: 0; transition: all .3s;
        }

        .panel-card--clickable:hover .panel-card-icon {
            background: var(--p-color, #3b82f6); color: #fff;
            box-shadow: 0 0 18px color-mix(in srgb, var(--p-color,#3b82f6) 50%, transparent);
        }

        .panel-card-icon--logo { padding: 6px; }
        .panel-card-icon--logo img {
            width: 100%; height: 100%; max-width: 30px; max-height: 30px;
            object-fit: contain; display: block;
        }
        .panel-card--clickable:hover .panel-card-icon--logo {
            background: color-mix(in srgb, var(--p-color,#3b82f6) 22%, transparent);
            color: inherit;
            box-shadow: 0 0 18px color-mix(in srgb, var(--p-color,#3b82f6) 45%, transparent);
        }

        .panel-card-name { font-family: 'Syne', sans-serif; font-size: .88rem; font-weight: 700; margin-bottom: 2px; }

        .panel-card-key { font-size: .62rem; color: var(--muted-2); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }

        .panel-status-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.5);
            position: absolute; top: 14px; right: 14px;
        }

        .panel-lock { position: absolute; top: 12px; right: 12px; font-size: .65rem; color: var(--muted); }

        /* ══ FEATURES ══ */
        .features-section { padding: 80px 0; position: relative; }

        .features-section::before {
            content: ''; position: absolute; top: 0; left: 50%; right: 0; height: 100%;
            background: radial-gradient(ellipse 60% 60% at 80% 50%, rgba(99,102,241,.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 48px; }

        .feature-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 20px; padding: 28px 24px; backdrop-filter: blur(8px);
            transition: border-color .3s, transform .3s; position: relative; overflow: hidden;
        }

        .feature-card:hover { border-color: var(--border-2); transform: translateY(-3px); }

        .feature-icon { width: 48px; height: 48px; border-radius: 13px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: 20px; }

        .feature-card h3 { font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 10px; }
        .feature-card p  { font-size: .875rem; color: var(--muted-2); line-height: 1.6; }

        /* ══ STATS ══ */
        .stats-section { padding: 80px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }

        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1px; background: var(--border);
            border: 1px solid var(--border); border-radius: 20px; overflow: hidden;
        }

        .stat-cell { background: var(--bg-2); padding: 36px 28px; text-align: center; }

        .stat-cell-val { font-family: 'Syne', sans-serif; font-size: 2.4rem; font-weight: 800; letter-spacing: -.04em; margin-bottom: 6px; }

        .stat-cell-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 600; }

        /* ══ CTA ══ */
        .cta-section { padding: 80px 0; }

        .cta-box {
            background: linear-gradient(135deg, rgba(59,130,246,.1) 0%, rgba(99,102,241,.08) 100%);
            border: 1px solid rgba(59,130,246,.2); border-radius: 24px;
            padding: 60px 48px; text-align: center; position: relative; overflow: hidden;
        }

        .cta-box::before {
            content: ''; position: absolute; top: -60px; left: 50%;
            transform: translateX(-50%); width: 400px; height: 200px;
            background: radial-gradient(ellipse, rgba(59,130,246,.2) 0%, transparent 70%);
            pointer-events: none;
        }

        .cta-box h2 { font-family: 'Syne', sans-serif; font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 800; letter-spacing: -.03em; margin-bottom: 14px; }
        .cta-box p { color: var(--muted-2); margin-bottom: 36px; font-size: .95rem; }
        .cta-actions { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }

        /* ══ FOOTER ══ */
        .footer { background: rgba(3,7,15,.95); border-top: 1px solid var(--border); padding: 32px 0; }

        .footer-inner { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }

        .footer-brand { display: flex; align-items: center; gap: 10px; font-family: 'Syne', sans-serif; font-size: .9rem; font-weight: 700; color: var(--text); text-decoration: none; }

        .footer-copy { font-size: .78rem; color: var(--muted); }
        .footer-copy a { color: var(--muted-2); text-decoration: none; transition: color .2s; }
        .footer-copy a:hover { color: var(--text); }

        .footer-nexium { font-size: .78rem; color: var(--muted); margin-top: 4px; }
        .footer-nexium a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .footer-nexium a:hover { text-decoration: underline; }

        .footer-links { display: flex; gap: 4px; }
        .footer-links a { color: var(--muted-2); text-decoration: none; font-size: .8rem; padding: 4px 10px; border-radius: 6px; transition: color .2s, background .2s; }
        .footer-links a:hover { color: var(--text); background: rgba(255,255,255,.05); }

        /* ══ RESPONSIVE ══ */

        /* Sur écrans < 1100px : colonne déco masquée, hero redevient 1 colonne */
        @media (max-width: 1100px) {
            .hero > .container { grid-template-columns: 1fr; }
            .hero-deco { display: none; }
        }

        @media (max-width: 768px) {
            .container { padding: 0 1rem; }
            .nav-links  { display: none; }
            .hero { padding: 110px 0 72px; }
            .panels-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .features-grid { grid-template-columns: 1fr; }
            .cta-box { padding: 40px 24px; }
            .footer-inner { flex-direction: column; text-align: center; }
        }

        @media (max-width: 480px) {
            .panels-grid { grid-template-columns: repeat(2, 1fr); }
            .hero-actions { flex-direction: column; align-items: flex-start; }
            .btn-lg { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($themeMode); ?>">

<!-- ══ NAV ══ -->
<nav class="nav" id="nav">
    <div class="container">
        <div class="nav-inner">
            <a href="#" class="nav-brand">
                <div class="brand-icon"><i class="fa-solid fa-terminal"></i></div>
                <span class="brand-name"><?php echo htmlspecialchars($siteName); ?></span>
            </a>
            <ul class="nav-links">
                <li><a href="#panels">Panels</a></li>
                <li><a href="#features">Fonctionnalités</a></li>
                <li><a href="#stats">À propos</a></li>
            </ul>
            <div class="nav-cta">
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
    <!-- .container est désormais un grid 2 colonnes : texte | déco -->
    <div class="container">

        <!-- Colonne gauche -->
        <div class="hero-inner">
            <div class="hero-eyebrow">
                <i class="fa-solid fa-circle-dot"></i>
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

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-val blue"><?php echo $totalPanels; ?></div>
                    <div class="hero-stat-label">Panels</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val green"><?php echo $totalActive; ?></div>
                    <div class="hero-stat-label">Actifs</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val gold"><?php echo $totalCategories; ?></div>
                    <div class="hero-stat-label">Catégories</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-val" style="color:#a78bfa;">24/7</div>
                    <div class="hero-stat-label">Disponible</div>
                </div>
            </div>
        </div>

        <!-- Colonne droite : cards déco (visibles ≥ 1100px uniquement) -->
        <div class="hero-deco" aria-hidden="true">
            <?php
            $decoItems = [
                ['key'=>'LSPD',  'name'=>'LSPD',        'faction'=>'Police Dept',     'icon'=>'shield-halved',  'color'=>'#2563eb'],
                ['key'=>'SAMS',  'name'=>'SAMS',         'faction'=>'Medical Service', 'icon'=>'kit-medical',    'color'=>'#f43f5e'],
                ['key'=>'DOJ',   'name'=>'DOJ',          'faction'=>'Justice Dept',    'icon'=>'scale-balanced', 'color'=>'#ef4444'],
                ['key'=>'GOUV',  'name'=>'Gouvernement', 'faction'=>'Service Public',  'icon'=>'landmark',       'color'=>'#1d4ed8'],
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

<hr class="section-divider">


<!-- ══ FEATURES ══ -->
<section class="features-section" id="features">
    <div class="container">
        <div class="section-eyebrow">Pourquoi nous choisir</div>
        <h2 class="section-title">Conçu pour la performance.</h2>
        <p class="section-desc">Chaque fonctionnalité a été pensée pour les serveurs roleplay FiveM les plus exigeants.</p>

        <div class="features-grid">
            <?php
            $features = [
                ['icon'=>'shield-halved', 'color'=>'#2563eb', 'title'=>'Sécurité Renforcée',
                 'desc'=>'Authentification multi-méthodes (Email + Discord OAuth2), sessions sécurisées, protection CSRF et journalisation complète de chaque action.'],
                ['icon'=>'bolt',          'color'=>'#f59e0b', 'title'=>'Temps Réel',
                 'desc'=>'Dispatching live, statuts mis à jour instantanément, notifications de service et suivi des patrouilles en temps réel.'],
                ['icon'=>'layer-group',   'color'=>'#a855f7', 'title'=>'Multi-Services',
                 'desc'=>"Services publics (LSPD, SAMS, DOJ), commerces, garages, restaurants — tout dans une seule plateforme unifiée."],
                ['icon'=>'file-lines',    'color'=>'#10b981', 'title'=>'Rapports & Logs',
                 'desc'=>"Système de rapports horodatés, casiers judiciaires, logs d'activité détaillés et historique complet de chaque intervention."],
                ['icon'=>'sliders',       'color'=>'#ef4444', 'title'=>'Configurable',
                 'desc'=>'Activation/désactivation de chaque panel via le panel admin, noms et couleurs personnalisables, thèmes et plugins extensibles.'],
                ['icon'=>'mobile-screen', 'color'=>'#06b6d4', 'title'=>'Responsive',
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
                <div class="stat-cell-val" style="color:var(--accent);"><?php echo $totalPanels; ?>+</div>
                <div class="stat-cell-label">Panels intégrés</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:#22c55e;"><?php echo $totalCategories; ?></div>
                <div class="stat-cell-label">Catégories</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:#f59e0b;">24/7</div>
                <div class="stat-cell-label">Disponibilité</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="color:#a855f7;">100%</div>
                <div class="stat-cell-label">Sécurisé</div>
            </div>
        </div>
    </div>
</section>


<!-- ══ CTA ══ -->
<section class="cta-section">
    <div class="container">
        <div class="cta-box">
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
            <a href="#" class="footer-brand">
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
                    Développé par <a href="https://nexium-studio.com" target="_blank">Nexium Studio</a>
                </div>
            </div>
        </div>
    </div>
</footer>


<script>
/* Navbar shadow */
(function () {
    const nav = document.getElementById('nav');
    window.addEventListener('scroll', () => {
        nav.style.boxShadow = window.scrollY > 10 ? '0 4px 24px rgba(0,0,0,.5)' : 'none';
    }, { passive: true });
})();

/* Scroll animations */
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
        el.style.transform  = 'translateY(20px)';
        el.style.transition = 'opacity .5s ease, transform .5s ease';
        io.observe(el);
    });
})();
</script>

</body>
</html>