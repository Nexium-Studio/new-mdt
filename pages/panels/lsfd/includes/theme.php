<?php
/**
 * Thème visuel du panel LSFD
 * pages/panels/lsfd/includes/theme.php
 *
 * Lit la table `lsfd_settings` (theme, gradient_color_1, gradient_color_2) et émet
 * un bloc <style> qui surcharge la variable `--panel-color` et les surfaces du
 * chrome générique panel-base (.mdt-standard-sidebar, .mdt-standard-topbar, …).
 *
 * Inclus depuis pages/panels/lsfd/includes/header.php JUSTE APRÈS render_header.php
 * (donc en tout début de <body> → un <style> en body est valide et surcharge le
 * thème auto injecté dans le <head>).
 *
 * Thèmes :
 *   1 = Rouge (défaut, surcharge minimale)
 *   2 = Sombre professionnel (surfaces gris très foncé, accent rouge)
 *   3 = Dégradé personnalisé (2 couleurs)
 */

if (!function_exists('lsfd_hex_rgb')) {
    /** "#dc2626" => "220,38,38" (avec fallback). */
    function lsfd_hex_rgb(string $hex, string $fallback = '220,38,38'): string {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) return $fallback;
        $h = $m[1];
        return hexdec(substr($h,0,2)) . ',' . hexdec(substr($h,2,2)) . ',' . hexdec(substr($h,4,2));
    }
}
if (!function_exists('lsfd_hex_shift')) {
    /** Éclaircit (+) / assombrit (-) une couleur hex de $amt (0-255). */
    function lsfd_hex_shift(string $hex, int $amt): string {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) return $hex;
        $h = $m[1]; $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $v = hexdec(substr($h, $i*2, 2)) + $amt;
            $v = max(0, min(255, $v));
            $out .= str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}

$__lsfdTheme = '1';
$__lsfdC1 = '#dc2626';
$__lsfdC2 = '#f97316';
try {
    $__themeDb = Database::getInstance()->getConnection();
    $__rows = $__themeDb->query("SELECT setting_key, setting_value FROM lsfd_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($__rows['theme']))            $__lsfdTheme = $__rows['theme'];
    if (!empty($__rows['gradient_color_1'])) $__lsfdC1 = $__rows['gradient_color_1'];
    if (!empty($__rows['gradient_color_2'])) $__lsfdC2 = $__rows['gradient_color_2'];
} catch (Exception $e) { /* table absente : thème par défaut */ }

if ($__lsfdTheme === '2'):
?>
<style id="lsfd-theme-vars">
/* ── THÈME 2 : Sombre professionnel ───────────────────────────────────────── */
body{ background:#0d0d10; }
.mdt-standard-sidebar{ background:linear-gradient(180deg,#161619,#0d0d10); border-right:1px solid rgba(220,38,38,.18); box-shadow:none; }
.mdt-standard-sidebar::before{ display:none; }
.mdt-standard-topbar{ background:rgba(16,16,20,.97); backdrop-filter:none; }
.sidebar-header{ background:rgba(255,255,255,.02); }
::-webkit-scrollbar-thumb{ background:rgba(220,38,38,.3); border-radius:4px; }
</style>
<?php
elseif ($__lsfdTheme === '3'):
    $rgb1 = lsfd_hex_rgb($__lsfdC1, '220,38,38');
    $rgb2 = lsfd_hex_rgb($__lsfdC2, '249,115,22');
    $c1   = htmlspecialchars($__lsfdC1);
    $c2   = htmlspecialchars($__lsfdC2);
    $c1l  = lsfd_hex_shift($__lsfdC1, 60);
?>
<style id="lsfd-theme-vars">
/* ── THÈME 3 : Dégradé personnalisé (<?php echo $c1.' → '.$c2; ?>) ──────────── */
:root{ --panel-color:<?php echo $c1; ?>; --panel-color-rgb:<?php echo $rgb1; ?>; }
body{
  background:#0a0c14;
  background-image:linear-gradient(135deg, rgba(<?php echo $rgb1; ?>,.08) 0%, transparent 40%, transparent 60%, rgba(<?php echo $rgb2; ?>,.08) 100%);
  background-attachment:fixed;
}
.mdt-standard-sidebar{
  background-image:linear-gradient(180deg, rgba(<?php echo $rgb1; ?>,.12) 0%, rgba(<?php echo $rgb2; ?>,.04) 100%), linear-gradient(180deg,#111420,#0a0c14);
}
.sidebar-logo-icon{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>); border-color:rgba(<?php echo $rgb1; ?>,.5); color:#fff; box-shadow:0 0 22px rgba(<?php echo $rgb1; ?>,.5); }
.sidebar-logo-text,.page-title{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.user-avatar{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>); }
.btn-mdt,.lf-btn-primary{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>) !important; }
::-webkit-scrollbar-thumb{ background:rgba(<?php echo $rgb1; ?>,.4); border-radius:4px; }
</style>
<?php
endif;
