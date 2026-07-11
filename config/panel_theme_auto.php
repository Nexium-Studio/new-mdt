<?php
// ================================================================
//  config/panel_theme_auto.php — LysoriaMDT
//  Système de thème universel pour tous les panels
//
//  Détecte automatiquement le panel depuis l'URL,
//  charge nom / sous-titre / couleur / style depuis la DB,
//  et génère le bloc <style> de thème à injecter dans le <head>.
//
//  UTILISATION dans n'importe quel includes/header.php :
//
//    // En haut du fichier, avec panel_pages.php :
//    require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';
//    // Pour les panels companies (4 niveaux) :
//    require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
//
//    // Dans le <head>, après le bloc <style> existant :
//    <?php echo $__panelThemeCSS; ?
//
//  Variables exposées après l'include :
//    $__panelKey      → clé DB du panel  (ex: 'SAMS')
//    $__panelName     → nom affiché      (custom ou défaut)
//    $__panelSubtitle → sous-titre       (custom ou défaut)
//    $__panelColor    → couleur hex      (ex: '#f43f5e')
//    $__panelStyle    → style appliqué   ('neon','classic','glass','carbon')
//    $__panelThemeCSS → bloc <style> complet prêt à injecter
// ================================================================

// ── 1. Mapping chemin URL → panel_key ────────────────────────────
$__pta_paths = [
    // Services publics
    '/pages/doj/'                    => 'DOJ',
    '/pages/usss/'                   => 'USSS',
    '/pages/lspd/'                   => 'LSPD',
    '/pages/lssd/'                   => 'LSSD',
    '/pages/sams/'                   => 'SAMS',
    '/pages/grupp6/'                 => 'GRUPP6',
    // Bars & Boîtes
    '/pages/companies/haute-luxury/' => 'HAUTE_LUXURY',
    '/pages/companies/bahamas/'      => 'BAHAMAS',
    // Garages
    '/pages/companies/bennys/'       => 'BENNYS',
    '/pages/companies/ls-custom/'    => 'LS_CUSTOM',
    '/pages/companies/ls-motors/'    => 'LS_MOTORS',
    '/pages/companies/heavy-impound/'=> 'HEAVY_IMPOUND',
    // Immobilier
    '/pages/companies/dynasty/'      => 'DYNASTY',
    // Commerces
    '/pages/companies/ltd/'             => 'LTD',
    '/pages/companies/rosa-tattoo/'     => 'ROSA_TATTOO',
    '/pages/companies/vangelico/'       => 'VANGELICO',
    '/pages/companies/diamond-casino/'  => 'DIAMOND_CASINO',
    '/pages/companies/ammunation/'      => 'AMMUNATION',
    '/pages/companies/life-invader/'    => 'LIFE_INVADER',
    '/pages/companies/postop/'          => 'POSTOP',
    '/pages/companies/taxi/'            => 'TAXI',
    '/pages/companies/triads/'          => 'TRIADS',
    // Restaurants
    '/pages/companies/uwu-coffee/'      => 'UWU_COFFEE',
    '/pages/companies/boat-house/'      => 'BOAT_HOUSE',
    '/pages/companies/burger-shot/'     => 'BURGER_SHOT',
    '/pages/companies/hornys/'          => 'HORNYS',
    '/pages/companies/koi/'             => 'RESTAURANT_KOI',
    '/pages/companies/up-atom/'         => 'UP_ATOM',
];

// ── 2. Défauts par panel (couleur + sous-titre) ──────────────────
$__pta_defaults = [
    'DOJ'           => ['color' => '#ef4444', 'subtitle' => 'Department of Justice'],
    'USSS'          => ['color' => '#334155', 'subtitle' => 'United States Secret Service'],
    'LSPD'          => ['color' => '#2563eb', 'subtitle' => 'Los Santos Police Department'],
    'LSSD'          => ['color' => '#10b981', 'subtitle' => 'Los Santos Sheriff Department'],
    'SAMS'          => ['color' => '#f43f5e', 'subtitle' => 'San Andreas Medical Services'],
    'GRUPP6'        => ['color' => '#64748b', 'subtitle' => 'Gruppe 6 Security'],
    'HAUTE_LUXURY'  => ['color' => '#f472b6', 'subtitle' => 'Haute Luxury'],
    'BAHAMAS'       => ['color' => '#06b6d4', 'subtitle' => 'Bahamas Mamas'],
    'BENNYS'        => ['color' => '#f59e0b', 'subtitle' => "Benny's Original Motor Works"],
    'LS_CUSTOM'     => ['color' => '#8b5cf6', 'subtitle' => 'Los Santos Customs'],
    'LS_MOTORS'     => ['color' => '#3b82f6', 'subtitle' => 'LS Motors'],
    'HEAVY_IMPOUND' => ['color' => '#94a3b8', 'subtitle' => 'Heavy Impound'],
    'DYNASTY'       => ['color' => '#22c55e', 'subtitle' => 'Dynasty Real Estate'],
    'LTD'            => ['color' => '#0ea5e9', 'subtitle' => 'LTD Mirror Park'],
    'ROSA_TATTOO'    => ['color' => '#e11d48', 'subtitle' => 'Rosa Tattoo'],
    'VANGELICO'      => ['color' => '#eab308', 'subtitle' => 'Vangelico Fine Jewelry'],
    'DIAMOND_CASINO' => ['color' => '#a78bfa', 'subtitle' => 'Diamond Casino & Resort'],
    'AMMUNATION'     => ['color' => '#ef4444', 'subtitle' => 'Ammu-Nation'],
    'LIFE_INVADER'   => ['color' => '#6366f1', 'subtitle' => 'Life Invader Media'],
    'POSTOP'         => ['color' => '#10b981', 'subtitle' => 'Post OP Delivery'],
    'TAXI'           => ['color' => '#eab308', 'subtitle' => 'Taxi Services'],
    'TRIADS'         => ['color' => '#a855f7', 'subtitle' => 'Triads Records'],
    'UWU_COFFEE'     => ['color' => '#f472b6', 'subtitle' => 'UwU Coffee'],
    'BOAT_HOUSE'     => ['color' => '#0891b2', 'subtitle' => 'Boat House'],
    'BURGER_SHOT'    => ['color' => '#f97316', 'subtitle' => 'Burger Shot'],
    'HORNYS'         => ['color' => '#dc2626', 'subtitle' => "Horny's Diner"],
    'RESTAURANT_KOI' => ['color' => '#f87171', 'subtitle' => 'Restaurant KOI'],
    'UP_ATOM'        => ['color' => '#fbbf24', 'subtitle' => 'UP & Atom'],
];

// ── Panels génériques /pages/panels/{slug}/ (registre) ───────────
$__pta_reg = ['slugToKey' => [], 'defaults' => []];
if (is_readable(__DIR__ . '/panel_registry.php')) {
    $__pta_reg = require __DIR__ . '/panel_registry.php';
    if (!empty($__pta_reg['defaults']) && is_array($__pta_reg['defaults'])) {
        $__pta_defaults = array_merge($__pta_defaults, $__pta_reg['defaults']);
    }
}

// ── 3. Détection automatique du panel depuis l'URL ───────────────
$__pta_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$__panelKey   = null;

// Panel custom : la clé est dans ?panel=
if (strpos($__pta_script, '/pages/custom/') === 0) {
    $__panelKey = strtoupper(trim($_GET['panel'] ?? ''));
} else {
    // Trier du chemin le plus long au plus court (match le plus précis en premier)
    uksort($__pta_paths, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($__pta_paths as $__pta_path => $__pta_key) {
        if (strpos($__pta_script, $__pta_path) === 0) {
            $__panelKey = $__pta_key;
            break;
        }
    }
}

if (
    !$__panelKey
    && !empty($__pta_reg['slugToKey'])
    && is_array($__pta_reg['slugToKey'])
    && preg_match('#/pages/panels/([a-z0-9-]+)/#', $__pta_script, $__pta_mslug)
) {
    $__pta_slug_try = $__pta_mslug[1];
    if (!empty($__pta_reg['slugToKey'][$__pta_slug_try])) {
        $__panelKey = $__pta_reg['slugToKey'][$__pta_slug_try];
    }
}

// Fallback : variable $panelKey définie manuellement dans l'appelant
if (!$__panelKey && !empty($panelKey)) {
    $__panelKey = strtoupper($panelKey);
}

// Aucun panel détecté → variables vides, pas de CSS
if (!$__panelKey) {
    $__panelKey      = '';
    $__panelName     = '';
    $__panelSubtitle = '';
    $__panelColor    = '#3b82f6';
    $__panelStyle    = 'neon';
    $__panelLogoUrl  = '';
    $__panelThemeCSS = '';
    return;
}

// ── 4. Valeurs par défaut ────────────────────────────────────────
$__pta_def       = $__pta_defaults[$__panelKey] ?? ['color' => '#3b82f6', 'subtitle' => ''];
$__pta_isCustom  = !isset($__pta_defaults[$__panelKey]);

$__panelName     = $__panelKey;
$__panelSubtitle = $__pta_def['subtitle'];
$__panelColor    = $__pta_def['color'];
$__panelStyle    = 'neon';
$__panelLogoUrl  = '';

// ── 5. Chargement depuis la DB ───────────────────────────────────
try {
    $__pta_db = Database::getInstance()->getConnection();

    if ($__pta_isCustom) {
        // Panel personnalisé → table custom_panels
        $__pta_s = $__pta_db->prepare(
            "SELECT name, subtitle, color, style FROM custom_panels WHERE panel_key = ? LIMIT 1"
        );
        $__pta_s->execute([$__panelKey]);
        $__pta_r = $__pta_s->fetch(PDO::FETCH_ASSOC);
        if ($__pta_r) {
            if (!empty($__pta_r['name']))     $__panelName     = $__pta_r['name'];
            if (!empty($__pta_r['subtitle'])) $__panelSubtitle = $__pta_r['subtitle'];
            if (!empty($__pta_r['color']))    $__panelColor    = $__pta_r['color'];
            if (!empty($__pta_r['style']))    $__panelStyle    = $__pta_r['style'];
        }
    } else {
        // Panel statique → table admin_panel_config
        // Utilise deux requêtes séparées pour gérer l'absence de colonnes (avant migration)
        try {
            $__pta_s = $__pta_db->prepare(
                "SELECT custom_name, custom_subtitle, custom_color, custom_style, custom_logo_url
                 FROM admin_panel_config WHERE panel_key = ? LIMIT 1"
            );
            $__pta_s->execute([$__panelKey]);
            $__pta_r = $__pta_s->fetch(PDO::FETCH_ASSOC);
            if ($__pta_r) {
                if (!empty($__pta_r['custom_name']))     $__panelName     = $__pta_r['custom_name'];
                if (!empty($__pta_r['custom_subtitle'])) $__panelSubtitle = $__pta_r['custom_subtitle'];
                if (!empty($__pta_r['custom_color']))    $__panelColor    = $__pta_r['custom_color'];
                if (!empty($__pta_r['custom_style']))    $__panelStyle    = $__pta_r['custom_style'];
                $lu = trim((string) ($__pta_r['custom_logo_url'] ?? ''));
                if ($lu !== '' && preg_match('#^https?://#i', $lu)) {
                    $__panelLogoUrl = $lu;
                }
            }
        } catch (Exception $e) {
            try {
                $__pta_s = $__pta_db->prepare(
                    "SELECT custom_name, custom_subtitle, custom_color, custom_style
                     FROM admin_panel_config WHERE panel_key = ? LIMIT 1"
                );
                $__pta_s->execute([$__panelKey]);
                $__pta_r = $__pta_s->fetch(PDO::FETCH_ASSOC);
                if ($__pta_r) {
                    if (!empty($__pta_r['custom_name']))     $__panelName     = $__pta_r['custom_name'];
                    if (!empty($__pta_r['custom_subtitle'])) $__panelSubtitle = $__pta_r['custom_subtitle'];
                    if (!empty($__pta_r['custom_color']))    $__panelColor    = $__pta_r['custom_color'];
                    if (!empty($__pta_r['custom_style']))    $__panelStyle    = $__pta_r['custom_style'];
                }
            } catch (Exception $e2) {
            }
        }
    }
} catch (Exception $e) {
    // DB inaccessible → défauts conservés, pas d'erreur fatale
}

// Valider le style (sécurité)
if (!in_array($__panelStyle, ['neon', 'classic', 'glass', 'carbon'])) {
    $__panelStyle = 'neon';
}

// ── 6. Helpers couleur ───────────────────────────────────────────
if (!function_exists('__pta_hex')) {
    function __pta_hex(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
    function __pta_rgba(array $rgb, float $a): string {
        return "rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},{$a})";
    }
    function __pta_lighten(array $rgb, float $pct): array {
        return [
            min(255, (int)($rgb[0] + (255 - $rgb[0]) * $pct)),
            min(255, (int)($rgb[1] + (255 - $rgb[1]) * $pct)),
            min(255, (int)($rgb[2] + (255 - $rgb[2]) * $pct)),
        ];
    }
}

$__pta_rgb  = __pta_hex($__panelColor);
$__pta_lRgb = __pta_lighten($__pta_rgb, 0.28);
$__pta_c    = $__panelColor;
$__pta_cL   = "rgb({$__pta_lRgb[0]},{$__pta_lRgb[1]},{$__pta_lRgb[2]})";
$__r        = $__pta_rgb; // alias court pour les blocs CSS

// ── 7. Blocs CSS par style ───────────────────────────────────────
//  Les sélecteurs [class*="-sidebar"] et [class*="-topbar"] ciblent
//  tous les panels sans connaître leur préfixe CSS :
//  .lspd-sidebar, .sams-topbar, .doj-sidebar, etc.

$__pta_css = '';

switch ($__panelStyle) {

    // ── NEON ─────────────────────────────────────────────────────
    case 'neon':
        $__pta_css = "
[class*='-sidebar']{border-right-color:". __pta_rgba($__r,.2) .";box-shadow:0 0 60px ". __pta_rgba($__r,.1) .";}
[class*='-sidebar']::before{background:linear-gradient(180deg,transparent,{$__pta_c},transparent);opacity:.3;}
[class*='-topbar']{border-bottom-color:". __pta_rgba($__r,.18) .";box-shadow:0 4px 30px ". __pta_rgba($__r,.07) .";}
[class*='-topbar']::after{background:linear-gradient(90deg,transparent 5%,{$__pta_c} 35%,{$__pta_cL} 50%,{$__pta_c} 65%,transparent 95%);opacity:.4;}
.sidebar-header{border-bottom-color:". __pta_rgba($__r,.12) .";}
.sidebar-header::after{background:linear-gradient(90deg,transparent,{$__pta_c},transparent);opacity:.55;}
.sidebar-logo-icon{background:linear-gradient(135deg,". __pta_rgba($__r,.25) .",". __pta_rgba($__r,.08) .");border-color:". __pta_rgba($__r,.4) .";color:{$__pta_c};box-shadow:0 0 25px ". __pta_rgba($__r,.32) .";}
.sidebar-logo-text{background:linear-gradient(135deg,{$__pta_c},{$__pta_cL});-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 8px ". __pta_rgba($__r,.5) .");}
.nav-item:hover,.nav-item.active{color:{$__pta_c};background:". __pta_rgba($__r,.09) .";text-shadow:0 0 10px ". __pta_rgba($__r,.5) .";}
.nav-item::before{background:linear-gradient(180deg,{$__pta_c},{$__pta_cL});box-shadow:0 0 12px ". __pta_rgba($__r,.7) .";}
.nav-section-title{color:". __pta_rgba($__r,.48) .";}
.nav-divider{background:linear-gradient(90deg,transparent,". __pta_rgba($__r,.2) .",transparent);}
.sidebar-footer{border-top-color:". __pta_rgba($__r,.15) .";}
.user-card{background:". __pta_rgba($__r,.07) .";border-color:". __pta_rgba($__r,.25) .";box-shadow:0 0 15px ". __pta_rgba($__r,.09) .";}
.user-avatar{background:linear-gradient(135deg,{$__pta_c},{$__pta_cL});box-shadow:0 0 18px ". __pta_rgba($__r,.4) .";}
.page-title{background:linear-gradient(135deg,{$__pta_c},{$__pta_cL});-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:drop-shadow(0 0 12px ". __pta_rgba($__r,.38) .");}
.topbar-badge{background:linear-gradient(135deg,". __pta_rgba($__r,.18) .",". __pta_rgba($__r,.06) .");border-color:". __pta_rgba($__r,.35) .";color:{$__pta_cL};box-shadow:0 0 10px ". __pta_rgba($__r,.14) .";}";
        break;

    // ── CLASSIC ──────────────────────────────────────────────────
    case 'classic':
        $__pta_css = "
[class*='-sidebar']{background:linear-gradient(180deg,#1a1f2e,#0f1318);border-right:2px solid ". __pta_rgba($__r,.35) .";box-shadow:4px 0 20px rgba(0,0,0,.4);}
[class*='-sidebar']::before{display:none;}
[class*='-topbar']{background:rgba(15,19,24,.98);border-bottom:2px solid ". __pta_rgba($__r,.3) .";box-shadow:0 2px 10px rgba(0,0,0,.3);backdrop-filter:none;}
[class*='-topbar']::after{display:none;}
.sidebar-header{border-bottom-color:". __pta_rgba($__r,.2) .";}
.sidebar-header::after{display:none;}
.sidebar-logo-icon{background:". __pta_rgba($__r,.15) .";border:1px solid ". __pta_rgba($__r,.35) .";color:{$__pta_c};box-shadow:none;border-radius:8px;}
.sidebar-logo-text{background:{$__pta_c};-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:none;}
.nav-item:hover,.nav-item.active{color:{$__pta_c};background:". __pta_rgba($__r,.1) .";text-shadow:none;}
.nav-item::before{background:{$__pta_c};box-shadow:none;width:4px;}
.nav-section-title{color:". __pta_rgba($__r,.45) .";border-left:2px solid ". __pta_rgba($__r,.3) .";padding-left:calc(1.5rem - 2px);}
.nav-divider{background:". __pta_rgba($__r,.15) .";height:1px;}
.sidebar-footer{border-top-color:". __pta_rgba($__r,.2) .";}
.user-card{background:". __pta_rgba($__r,.08) .";border-color:". __pta_rgba($__r,.2) .";box-shadow:none;}
.user-avatar{background:". __pta_rgba($__r,.2) .";border:1px solid ". __pta_rgba($__r,.3) .";box-shadow:none;}
.page-title{background:{$__pta_c};-webkit-background-clip:text;-webkit-text-fill-color:transparent;filter:none;}
.topbar-badge{background:". __pta_rgba($__r,.1) .";border-color:". __pta_rgba($__r,.25) .";color:{$__pta_c};box-shadow:none;}";
        break;

    // ── GLASS ────────────────────────────────────────────────────
    case 'glass':
        $__pta_css = "
body{background:radial-gradient(ellipse at 20% 50%,". __pta_rgba($__r,.12) .",transparent 60%),radial-gradient(ellipse at 80% 20%,". __pta_rgba($__r,.08) .",transparent 50%),#080b14;}
[class*='-sidebar']{background:rgba(15,20,35,.55);backdrop-filter:blur(24px) saturate(1.4);-webkit-backdrop-filter:blur(24px) saturate(1.4);border-right:1px solid ". __pta_rgba($__r,.2) .";box-shadow:0 0 80px ". __pta_rgba($__r,.07) .";}
[class*='-sidebar']::before{display:none;}
[class*='-topbar']{background:rgba(10,14,25,.6);backdrop-filter:blur(20px) saturate(1.3);-webkit-backdrop-filter:blur(20px) saturate(1.3);border-bottom:1px solid ". __pta_rgba($__r,.15) .";}
[class*='-topbar']::after{background:linear-gradient(90deg,transparent 5%,". __pta_rgba($__r,.5) ." 35%,". __pta_rgba($__r,.8) ." 50%,". __pta_rgba($__r,.5) ." 65%,transparent 95%);opacity:.3;}
.sidebar-header{background:". __pta_rgba($__r,.06) .";border-bottom-color:". __pta_rgba($__r,.12) .";}
.sidebar-header::after{background:linear-gradient(90deg,transparent,". __pta_rgba($__r,.5) .",transparent);opacity:.4;}
.sidebar-logo-icon{background:". __pta_rgba($__r,.15) .";backdrop-filter:blur(10px);border-color:". __pta_rgba($__r,.35) .";color:{$__pta_cL};box-shadow:0 4px 16px ". __pta_rgba($__r,.2) .";}
.sidebar-logo-text{background:linear-gradient(135deg,{$__pta_cL},#fff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.nav-item:hover,.nav-item.active{color:{$__pta_cL};background:". __pta_rgba($__r,.12) .";backdrop-filter:blur(8px);}
.nav-item::before{background:linear-gradient(180deg,{$__pta_cL},{$__pta_c});box-shadow:0 0 10px ". __pta_rgba($__r,.5) .";}
.nav-section-title{color:". __pta_rgba($__r,.5) .";}
.nav-divider{background:linear-gradient(90deg,transparent,". __pta_rgba($__r,.2) .",transparent);}
.sidebar-footer{background:rgba(8,11,20,.6);border-top-color:". __pta_rgba($__r,.12) .";backdrop-filter:blur(12px);}
.user-card{background:". __pta_rgba($__r,.08) .";backdrop-filter:blur(12px);border-color:". __pta_rgba($__r,.2) .";}
.user-avatar{background:linear-gradient(135deg,". __pta_rgba($__r,.4) .",". __pta_rgba($__r,.2) .");border-color:". __pta_rgba($__r,.35) .";box-shadow:0 0 12px ". __pta_rgba($__r,.3) .";}
.page-title{background:linear-gradient(135deg,{$__pta_cL},#fff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.topbar-badge{background:". __pta_rgba($__r,.12) .";backdrop-filter:blur(8px);border-color:". __pta_rgba($__r,.25) .";color:{$__pta_cL};}";
        break;

    // ── CARBON ───────────────────────────────────────────────────
    case 'carbon':
        $__pta_css = "
body{background:#09090b;}
[class*='-sidebar']{background:linear-gradient(180deg,#111113,#09090b);border-right:1px solid #222226;box-shadow:none;}
[class*='-sidebar']::before{display:none;}
[class*='-topbar']{background:rgba(9,9,11,.98);border-bottom:1px solid #1c1c1f;box-shadow:none;backdrop-filter:none;}
[class*='-topbar']::after{background:{$__pta_c};opacity:.6;height:1px;}
.sidebar-header{background:#0d0d0f;border-bottom:1px solid #1c1c1f;}
.sidebar-header::after{background:linear-gradient(90deg,transparent,". __pta_rgba($__r,.4) .",transparent);opacity:.6;height:2px;}
.sidebar-logo-icon{background:#161618;border:1px solid #2a2a2e;color:{$__pta_c};box-shadow:none;}
.sidebar-logo-text{background:linear-gradient(135deg,{$__pta_c},{$__pta_cL});-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:3px;}
.nav-item{color:#52525b;}
.nav-item:hover,.nav-item.active{color:{$__pta_c};background:#111113;}
.nav-item::before{background:{$__pta_c};box-shadow:none;width:2px;}
.nav-divider{background:#1c1c1f;}
.nav-section-title{color:#3f3f46;}
.sidebar-footer{background:#09090b;border-top:1px solid #1c1c1f;}
.user-card{background:#111113;border:1px solid #222226;box-shadow:none;}
.user-avatar{background:#1c1c1f;border:1px solid ". __pta_rgba($__r,.3) .";color:{$__pta_c};box-shadow:none;}
.page-title{background:linear-gradient(135deg,{$__pta_c},{$__pta_cL});-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:4px;}
.topbar-badge{background:#111113;border:1px solid #222226;color:{$__pta_c};box-shadow:none;}";
        break;
}

// ── 8. Assemblage du bloc <style> final ──────────────────────────
$__pta_css = preg_replace('/\s+/', ' ', trim($__pta_css));

$__panelThemeCSS = "\n<style id=\"panel-theme-auto\">"
    . "\n/* LysoriaMDT — Panel: {$__panelKey} | Style: {$__panelStyle} */"
    . "\n:root{"
        . "--panel-color:{$__pta_c};"
        . "--panel-color-rgb:{$__r[0]},{$__r[1]},{$__r[2]};"
    . "}\n"
    . $__pta_css
    . "\n</style>\n";

// Nettoyage des variables temporaires (garder l'espace de noms propre)
unset($__pta_paths, $__pta_defaults, $__pta_def, $__pta_script,
      $__pta_path, $__pta_key, $__pta_isCustom, $__pta_db,
      $__pta_s, $__pta_r, $__pta_rgb, $__pta_lRgb, $__pta_css,
      $__r, $__pta_c, $__pta_cL, $__pta_reg, $__pta_slug_try);