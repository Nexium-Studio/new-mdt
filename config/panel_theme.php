<?php
// ============================================================
//  config/panel_theme.php
//  Moteur de thème dynamique — injecte les CSS overrides
//  selon la couleur et le style configurés dans le panel staff
// ============================================================

/**
 * Convertit un code hex en tableau [r, g, b]
 */
function _themeHexToRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/**
 * Génère une couleur plus claire (mélange avec blanc)
 */
function _themeLighten(array $rgb, float $amount): array {
    return [
        min(255, (int)($rgb[0] + (255 - $rgb[0]) * $amount)),
        min(255, (int)($rgb[1] + (255 - $rgb[1]) * $amount)),
        min(255, (int)($rgb[2] + (255 - $rgb[2]) * $amount)),
    ];
}

/**
 * Génère une couleur plus sombre
 */
function _themeDarken(array $rgb, float $amount): array {
    return [
        max(0, (int)($rgb[0] * (1 - $amount))),
        max(0, (int)($rgb[1] * (1 - $amount))),
        max(0, (int)($rgb[2] * (1 - $amount))),
    ];
}

/**
 * Charge la config thème depuis la DB pour un panel donné
 * Retourne ['color' => '#hex', 'style' => 'neon']
 */
function getPanelThemeConfig(string $panelKey, string $defaultColor = '#3b82f6'): array {
    try {
        $db  = Database::getInstance()->getConnection();
        $row = $db->prepare("SELECT custom_color, custom_style FROM admin_panel_config WHERE panel_key = ? LIMIT 1");
        $row->execute([$panelKey]);
        $r = $row->fetch();
        return [
            'color' => (!empty($r['custom_color'])) ? $r['custom_color'] : $defaultColor,
            'style' => (!empty($r['custom_style'])) ? $r['custom_style'] : 'neon',
        ];
    } catch (Exception $e) {
        return ['color' => $defaultColor, 'style' => 'neon'];
    }
}

/**
 * Génère et injecte le bloc <style> de thème dans le <head>
 *
 * @param string $panelKey   Clé du panel ex: 'LSPD'
 * @param string $prefix     Préfixe CSS ex: 'lspd' → .lspd-sidebar, .lspd-topbar
 * @param string $defaultColor  Couleur par défaut si pas de config
 */
function injectPanelTheme(string $panelKey, string $prefix, string $defaultColor = '#3b82f6'): void {
    $cfg   = getPanelThemeConfig($panelKey, $defaultColor);
    $color = $cfg['color'];
    $style = $cfg['style'];

    $rgb    = _themeHexToRgb($color);
    $light  = _themeLighten($rgb, 0.25);
    $dark   = _themeDarken($rgb, 0.3);

    $c        = "rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})";
    $cLight   = "rgb({$light[0]},{$light[1]},{$light[2]})";
    $cDark    = "rgb({$dark[0]},{$dark[1]},{$dark[2]})";

    // Helpers rgba
    $rgba = fn(array $r, float $a) => "rgba({$r[0]},{$r[1]},{$r[2]},{$a})";

    $css = '';

    switch ($style) {

        // ════════════════════════════════════════════════════
        // NEON — lueurs vives, bordures lumineuses, effets glow
        // ════════════════════════════════════════════════════
        case 'neon':
            $css = "
            :root {
                --panel-color:      {$color};
                --panel-color-rgb:  {$rgb[0]},{$rgb[1]},{$rgb[2]};
                --panel-glow:       {$rgba($rgb, 0.5)};
            }
            .{$prefix}-sidebar {
                border-right: 1px solid {$rgba($rgb, 0.2)};
                box-shadow: 0 0 60px {$rgba($rgb, 0.12)};
            }
            .{$prefix}-sidebar::before {
                background: linear-gradient(180deg, transparent, {$color}, transparent);
                opacity: 0.4;
            }
            .sidebar-header {
                border-bottom-color: {$rgba($rgb, 0.12)};
            }
            .sidebar-header::after {
                background: linear-gradient(90deg, transparent, {$color}, transparent);
                opacity: 0.6;
            }
            .sidebar-logo-icon {
                background: linear-gradient(135deg, {$rgba($rgb, 0.25)}, {$rgba($rgb, 0.08)});
                border: 2px solid {$rgba($rgb, 0.4)};
                color: {$color};
                box-shadow: 0 0 25px {$rgba($rgb, 0.35)}, inset 0 0 15px {$rgba($rgb, 0.1)};
            }
            .sidebar-logo-text {
                background: linear-gradient(135deg, {$color}, {$cLight});
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: drop-shadow(0 0 8px {$rgba($rgb, 0.6)});
            }
            .nav-item:hover, .nav-item.active {
                color: {$color};
                background: {$rgba($rgb, 0.09)};
                text-shadow: 0 0 10px {$rgba($rgb, 0.6)};
            }
            .nav-item::before {
                background: linear-gradient(180deg, {$color}, {$cLight});
                box-shadow: 0 0 12px {$rgba($rgb, 0.8)};
            }
            .nav-divider {
                background: linear-gradient(90deg, transparent, {$rgba($rgb, 0.2)}, transparent);
            }
            .nav-section-title { color: {$rgba($rgb, 0.55)}; }
            .sidebar-footer {
                border-top: 1px solid {$rgba($rgb, 0.15)};
            }
            .user-card {
                background: {$rgba($rgb, 0.07)};
                border: 1px solid {$rgba($rgb, 0.25)};
                box-shadow: 0 0 15px {$rgba($rgb, 0.1)};
            }
            .user-avatar {
                background: linear-gradient(135deg, {$color}, {$cLight});
                box-shadow: 0 0 18px {$rgba($rgb, 0.5)};
            }
            .{$prefix}-topbar {
                border-bottom: 1px solid {$rgba($rgb, 0.18)};
                box-shadow: 0 4px 30px {$rgba($rgb, 0.08)};
            }
            .{$prefix}-topbar::after {
                background: linear-gradient(90deg, transparent 5%, {$color} 30%, {$cDark} 50%, {$color} 70%, transparent 95%);
                opacity: 0.45;
            }
            .page-title {
                background: linear-gradient(135deg, {$color}, {$cLight});
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: drop-shadow(0 0 12px {$rgba($rgb, 0.4)});
            }
            .topbar-badge {
                background: linear-gradient(135deg, {$rgba($rgb, 0.18)}, {$rgba($rgb, 0.06)});
                border: 1px solid {$rgba($rgb, 0.35)};
                color: {$cLight};
                box-shadow: 0 0 10px {$rgba($rgb, 0.15)};
            }
            .sidebar-toggle {
                background: {$rgba($rgb, 0.12)};
                border: 1px solid {$rgba($rgb, 0.35)};
                color: {$color};
            }
            .sidebar-toggle:hover {
                background: {$rgba($rgb, 0.22)};
                box-shadow: 0 0 15px {$rgba($rgb, 0.35)};
            }
            ";
            break;

        // ════════════════════════════════════════════════════
        // CLASSIQUE — propre, professionnel, sans glow
        // ════════════════════════════════════════════════════
        case 'classic':
            $bg1 = '#1a1f2e';
            $bg2 = '#0f1318';
            $css = "
            :root {
                --panel-color:     {$color};
                --panel-color-rgb: {$rgb[0]},{$rgb[1]},{$rgb[2]};
            }
            .{$prefix}-sidebar {
                background: linear-gradient(180deg, {$bg1} 0%, {$bg2} 100%);
                border-right: 2px solid {$rgba($rgb, 0.35)};
                box-shadow: 4px 0 20px rgba(0,0,0,0.4);
            }
            .{$prefix}-sidebar::before { display: none; }
            .sidebar-header {
                border-bottom: 1px solid {$rgba($rgb, 0.2)};
            }
            .sidebar-header::after { display: none; }
            .sidebar-logo-icon {
                background: {$rgba($rgb, 0.15)};
                border: 1px solid {$rgba($rgb, 0.35)};
                color: {$color};
                box-shadow: none;
                border-radius: 8px;
            }
            .sidebar-logo-text {
                background: {$color};
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: none;
            }
            .nav-item { transition: background 0.15s, color 0.15s; }
            .nav-item:hover, .nav-item.active {
                color: {$color};
                background: {$rgba($rgb, 0.1)};
                text-shadow: none;
            }
            .nav-item::before {
                background: {$color};
                box-shadow: none;
                width: 4px;
                border-radius: 0 3px 3px 0;
            }
            .nav-divider {
                background: {$rgba($rgb, 0.15)};
                height: 1px;
            }
            .nav-section-title {
                color: {$rgba($rgb, 0.5)};
                border-left: 2px solid {$rgba($rgb, 0.3)};
                padding-left: calc(1.5rem - 2px);
            }
            .sidebar-footer { border-top: 1px solid {$rgba($rgb, 0.2)}; }
            .user-card {
                background: {$rgba($rgb, 0.08)};
                border: 1px solid {$rgba($rgb, 0.2)};
                border-radius: 6px;
                box-shadow: none;
            }
            .user-avatar {
                background: {$rgba($rgb, 0.2)};
                color: {$color};
                box-shadow: none;
                border: 1px solid {$rgba($rgb, 0.3)};
                font-size: 0.9rem;
            }
            .{$prefix}-topbar {
                background: rgba(15, 19, 24, 0.98);
                border-bottom: 2px solid {$rgba($rgb, 0.3)};
                box-shadow: 0 2px 10px rgba(0,0,0,0.3);
                backdrop-filter: none;
            }
            .{$prefix}-topbar::after { display: none; }
            .page-title {
                background: {$color};
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: none;
                letter-spacing: 2px;
            }
            .topbar-badge {
                background: {$rgba($rgb, 0.1)};
                border: 1px solid {$rgba($rgb, 0.25)};
                color: {$color};
                box-shadow: none;
                border-radius: 6px;
            }
            .sidebar-toggle {
                background: transparent;
                border: 1px solid {$rgba($rgb, 0.3)};
                color: {$color};
            }
            .sidebar-toggle:hover { background: {$rgba($rgb, 0.1)}; box-shadow: none; }
            ";
            break;

        // ════════════════════════════════════════════════════
        // GLASSMORPHISME — verre dépoli, transparences, blur
        // ════════════════════════════════════════════════════
        case 'glass':
            $css = "
            :root {
                --panel-color:     {$color};
                --panel-color-rgb: {$rgb[0]},{$rgb[1]},{$rgb[2]};
            }
            body { background: radial-gradient(ellipse at 20% 50%, {$rgba($rgb, 0.12)} 0%, transparent 60%), radial-gradient(ellipse at 80% 20%, {$rgba($rgb, 0.08)} 0%, transparent 50%), #080b14; }
            .{$prefix}-sidebar {
                background: rgba(15, 20, 35, 0.55);
                backdrop-filter: blur(24px) saturate(1.4);
                -webkit-backdrop-filter: blur(24px) saturate(1.4);
                border-right: 1px solid {$rgba($rgb, 0.2)};
                box-shadow: 0 0 80px {$rgba($rgb, 0.08)}, inset -1px 0 0 {$rgba($rgb, 0.15)};
            }
            .{$prefix}-sidebar::before {
                background: linear-gradient(180deg, transparent, {$rgba($rgb, 0.5)}, transparent);
                opacity: 0.25;
            }
            .sidebar-header {
                background: {$rgba($rgb, 0.06)};
                border-bottom: 1px solid {$rgba($rgb, 0.12)};
                backdrop-filter: none;
            }
            .sidebar-header::after {
                background: linear-gradient(90deg, transparent, {$rgba($rgb, 0.5)}, transparent);
                opacity: 0.4;
            }
            .sidebar-logo-icon {
                background: {$rgba($rgb, 0.15)};
                backdrop-filter: blur(10px);
                border: 1px solid {$rgba($rgb, 0.35)};
                color: {$cLight};
                box-shadow: 0 4px 16px {$rgba($rgb, 0.2)}, inset 0 1px 0 {$rgba([255,255,255], 0.1)};
            }
            .sidebar-logo-text {
                background: linear-gradient(135deg, {$cLight}, #fff);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: drop-shadow(0 2px 8px {$rgba($rgb, 0.4)});
            }
            .nav-item:hover, .nav-item.active {
                color: {$cLight};
                background: {$rgba($rgb, 0.12)};
                backdrop-filter: blur(8px);
            }
            .nav-item::before {
                background: linear-gradient(180deg, {$cLight}, {$color});
                box-shadow: 0 0 10px {$rgba($rgb, 0.6)};
            }
            .nav-divider { background: linear-gradient(90deg, transparent, {$rgba($rgb, 0.18)}, transparent); }
            .nav-section-title { color: {$rgba($rgb, 0.5)}; }
            .sidebar-footer {
                background: rgba(8, 11, 20, 0.6);
                border-top: 1px solid {$rgba($rgb, 0.12)};
                backdrop-filter: blur(12px);
            }
            .user-card {
                background: {$rgba($rgb, 0.08)};
                backdrop-filter: blur(12px);
                border: 1px solid {$rgba($rgb, 0.2)};
                box-shadow: inset 0 1px 0 rgba(255,255,255,0.05), 0 4px 12px rgba(0,0,0,0.2);
            }
            .user-avatar {
                background: linear-gradient(135deg, {$rgba($rgb, 0.4)}, {$rgba($rgb, 0.2)});
                backdrop-filter: blur(8px);
                border: 1px solid {$rgba($rgb, 0.35)};
                box-shadow: 0 0 12px {$rgba($rgb, 0.3)};
            }
            .{$prefix}-topbar {
                background: rgba(10, 14, 25, 0.6);
                backdrop-filter: blur(20px) saturate(1.3);
                -webkit-backdrop-filter: blur(20px) saturate(1.3);
                border-bottom: 1px solid {$rgba($rgb, 0.15)};
                box-shadow: 0 4px 30px rgba(0,0,0,0.2), inset 0 -1px 0 {$rgba($rgb, 0.1)};
            }
            .{$prefix}-topbar::after {
                background: linear-gradient(90deg, transparent 5%, {$rgba($rgb, 0.5)} 30%, {$rgba($rgb, 0.8)} 50%, {$rgba($rgb, 0.5)} 70%, transparent 95%);
                opacity: 0.3;
            }
            .page-title {
                background: linear-gradient(135deg, {$cLight}, #fff);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: drop-shadow(0 2px 8px {$rgba($rgb, 0.35)});
            }
            .topbar-badge {
                background: {$rgba($rgb, 0.12)};
                backdrop-filter: blur(8px);
                border: 1px solid {$rgba($rgb, 0.25)};
                color: {$cLight};
                box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
            }
            .sidebar-toggle {
                background: {$rgba($rgb, 0.1)};
                backdrop-filter: blur(8px);
                border: 1px solid {$rgba($rgb, 0.25)};
                color: {$cLight};
            }
            .sidebar-toggle:hover { background: {$rgba($rgb, 0.2)}; }
            ";
            break;

        // ════════════════════════════════════════════════════
        // CARBON — sombre industriel, accents subtils, minimal
        // ════════════════════════════════════════════════════
        case 'carbon':
            $css = "
            :root {
                --panel-color:     {$color};
                --panel-color-rgb: {$rgb[0]},{$rgb[1]},{$rgb[2]};
            }
            body { background: #09090b; }
            .{$prefix}-sidebar {
                background: linear-gradient(180deg, #111113 0%, #09090b 100%);
                border-right: 1px solid #222226;
                box-shadow: none;
            }
            .{$prefix}-sidebar::before { display: none; }
            .sidebar-header {
                background: #0d0d0f;
                border-bottom: 1px solid #1c1c1f;
            }
            .sidebar-header::after {
                background: linear-gradient(90deg, transparent, {$rgba($rgb, 0.4)}, transparent);
                opacity: 0.6;
                height: 2px;
            }
            .sidebar-logo-icon {
                background: #161618;
                border: 1px solid #2a2a2e;
                color: {$color};
                box-shadow: none;
                border-radius: 10px;
            }
            .sidebar-logo-text {
                background: linear-gradient(135deg, {$color}, {$cLight});
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: none;
                letter-spacing: 3px;
            }
            .nav-item {
                color: #52525b;
                transition: all 0.2s;
            }
            .nav-item:hover, .nav-item.active {
                color: {$color};
                background: #111113;
                text-shadow: none;
            }
            .nav-item::before {
                background: {$color};
                box-shadow: none;
                width: 2px;
            }
            .nav-divider { background: #1c1c1f; margin: 0.5rem 1.5rem; }
            .nav-section-title { color: #3f3f46; font-size: 0.6rem; }
            .sidebar-footer { background: #09090b; border-top: 1px solid #1c1c1f; }
            .user-card {
                background: #111113;
                border: 1px solid #222226;
                border-radius: 8px;
                box-shadow: none;
            }
            .user-avatar {
                background: #1c1c1f;
                border: 1px solid {$rgba($rgb, 0.3)};
                color: {$color};
                font-size: 0.85rem;
                box-shadow: none;
            }
            .{$prefix}-topbar {
                background: rgba(9, 9, 11, 0.98);
                border-bottom: 1px solid #1c1c1f;
                box-shadow: none;
                backdrop-filter: none;
            }
            .{$prefix}-topbar::after {
                background: {$color};
                opacity: 0.6;
                height: 1px;
            }
            .page-title {
                background: linear-gradient(135deg, {$color}, {$cLight});
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                filter: none;
                letter-spacing: 4px;
                font-size: 1.1rem;
            }
            .topbar-badge {
                background: #111113;
                border: 1px solid #222226;
                color: {$color};
                box-shadow: none;
                border-radius: 6px;
                font-size: 0.7rem;
            }
            .sidebar-toggle {
                background: #111113;
                border: 1px solid #2a2a2e;
                color: {$color};
            }
            .sidebar-toggle:hover { background: #1c1c1f; box-shadow: none; }
            ";
            break;

        default:
            return; // style inconnu → pas de CSS
    }

    // Nettoyage et injection
    $css = preg_replace('/\s+/', ' ', trim($css));
    echo "\n<style id=\"panel-theme-override\">\n{$css}\n</style>\n";
}