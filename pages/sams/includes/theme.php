<?php
/**
 * Applique le thème visuel configuré dans l'administration SAMS
 * pages/sams/includes/theme.php
 *
 * Lit la table `sams_settings` (theme, gradient_color_1, gradient_color_2) et
 * émet un bloc <style> qui surcharge les variables CSS du design system SAMS.
 * À inclure dans le <head> du header SAMS, APRÈS le <style> principal.
 *
 * Thèmes :
 *   1 = Rouge (défaut, aucune surcharge)
 *   2 = Sombre professionnel (fond gris foncé, accents rouges)
 *   3 = Dégradé personnalisé (2 couleurs, dégradé subtil)
 */

if (!function_exists('sams_hex_rgb')) {
    /** "#e11d48" => "225,29,72" (avec fallback). */
    function sams_hex_rgb(string $hex, string $fallback = '225,29,72'): string {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) return $fallback;
        $h = $m[1];
        return hexdec(substr($h,0,2)) . ',' . hexdec(substr($h,2,2)) . ',' . hexdec(substr($h,4,2));
    }
}
if (!function_exists('sams_hex_shift')) {
    /** Éclaircit (+) / assombrit (-) une couleur hex de $amt (0-255). */
    function sams_hex_shift(string $hex, int $amt): string {
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

$__samsTheme = '1';
$__samsC1 = '#e11d48';
$__samsC2 = '#7c3aed';
try {
    $__themeDb = Database::getInstance()->getConnection();
    $__rows = $__themeDb->query("SELECT setting_key, setting_value FROM sams_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($__rows['theme']))            $__samsTheme = $__rows['theme'];
    if (!empty($__rows['gradient_color_1'])) $__samsC1 = $__rows['gradient_color_1'];
    if (!empty($__rows['gradient_color_2'])) $__samsC2 = $__rows['gradient_color_2'];
} catch (Exception $e) { /* table absente : thème par défaut */ }

if ($__samsTheme === '2'):
?>
<style id="sams-theme-vars">
/* ── THÈME 2 : Sombre professionnel ───────────────────────────────────────── */
:root{
  --bg:#0f0f12; --bg-b:#141418; --bg-c:#1a1a1f; --bg-e:#212128; --bg-ov:#26262e;
  --bdr:rgba(255,255,255,.08); --bdr-h:rgba(225,29,72,.42);
  --txt:#e8e8ea; --txt-s:#a1a1aa; --txt-m:#71717a;
}
body{ background:var(--bg); background-image:none; }
.s-sidebar{ background:linear-gradient(180deg,#16161a 0%,#0f0f12 100%);
  background-image:linear-gradient(180deg,#16161a 0%,#0f0f12 100%); border-right:1px solid var(--bdr); }
.s-brand{ background:rgba(255,255,255,.02); }
.s-topbar{ background:rgba(20,20,24,.97); }
.card::before, .tbl thead tr{ /* on garde l'accent rouge */ }
::-webkit-scrollbar-thumb{ background:rgba(225,29,72,.3); }
</style>
<?php
elseif ($__samsTheme === '3'):
    $rgb1 = sams_hex_rgb($__samsC1, '225,29,72');
    $rgb2 = sams_hex_rgb($__samsC2, '124,58,237');
    $c1   = htmlspecialchars($__samsC1);
    $c2   = htmlspecialchars($__samsC2);
    $c1d  = sams_hex_shift($__samsC1, -45);
    $c1l  = sams_hex_shift($__samsC1, 70);
    $c1xl = sams_hex_shift($__samsC1, 130);
?>
<style id="sams-theme-vars">
/* ── THÈME 3 : Dégradé personnalisé (<?php echo $c1.' → '.$c2; ?>) ──────────── */
:root{
  --red:<?php echo $c1; ?>; --red-d:<?php echo $c1d; ?>; --red-l:<?php echo $c1l; ?>; --red-xl:<?php echo $c1xl; ?>;
  --bdr:rgba(<?php echo $rgb1; ?>,.14); --bdr-h:rgba(<?php echo $rgb1; ?>,.34);
}
/* Dégradé subtil dans les arrière-plans principaux */
body{
  background:var(--bg);
  background-image:
    linear-gradient(135deg, rgba(<?php echo $rgb1; ?>,.07) 0%, transparent 38%, transparent 62%, rgba(<?php echo $rgb2; ?>,.07) 100%);
  background-attachment:fixed;
}
.s-sidebar{
  background-image:
    linear-gradient(180deg, rgba(<?php echo $rgb1; ?>,.10) 0%, rgba(<?php echo $rgb2; ?>,.04) 100%),
    linear-gradient(180deg,#0e060a 0%,#030609 100%);
}
.s-brand{ background:linear-gradient(90deg, rgba(<?php echo $rgb1; ?>,.10), rgba(<?php echo $rgb2; ?>,.04)); }
.s-brand-icon{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>); box-shadow:0 0 20px rgba(<?php echo $rgb1; ?>,.5); }
.btn-primary{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>); box-shadow:0 3px 14px rgba(<?php echo $rgb1; ?>,.35); }
.card::before{ background:linear-gradient(90deg,transparent,rgba(<?php echo $rgb1; ?>,.3),transparent); }
::-webkit-scrollbar-thumb{ background:rgba(<?php echo $rgb1; ?>,.4); }
</style>
<?php
endif;
