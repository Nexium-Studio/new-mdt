<?php
/**
 * Applique le thème visuel configuré dans l'administration DOJ
 * pages/doj/includes/theme.php
 *
 * Lit la table `doj_settings` (theme, gradient_color_1, gradient_color_2) et
 * émet un bloc <style> qui surcharge les variables CSS / surfaces du design
 * system DOJ. À inclure dans le <head> du header DOJ, APRÈS le <style> principal.
 *
 * Thèmes :
 *   1 = DOJ Classique  (bleu grisé, défaut — aucune surcharge)
 *   2 = DOJ Sombre      (fond gris foncé, éléments noirs, accents bleus)
 *   3 = DOJ Dégradé     (2 couleurs personnalisées, dégradé subtil)
 *
 * Expose aussi $__dojTheme / $__dojC1 / $__dojC2 pour le reste du header
 * (ex. pour adapter la scrollbar et certains accents).
 */

if (!function_exists('doj_hex_rgb')) {
    /** "#3b82f6" => "59,130,246" (avec fallback). */
    function doj_hex_rgb(string $hex, string $fallback = '59,130,246'): string {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) return $fallback;
        $h = $m[1];
        return hexdec(substr($h,0,2)) . ',' . hexdec(substr($h,2,2)) . ',' . hexdec(substr($h,4,2));
    }
}
if (!function_exists('doj_hex_shift')) {
    /** Éclaircit (+) / assombrit (-) une couleur hex de $amt (0-255). */
    function doj_hex_shift(string $hex, int $amt): string {
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

$__dojTheme = '1';
$__dojC1 = '#3b82f6';
$__dojC2 = '#1e40af';
try {
    $__themeDb = Database::getInstance()->getConnection();
    $__rows = $__themeDb->query("SELECT setting_key, setting_value FROM doj_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($__rows['theme']))            $__dojTheme = $__rows['theme'];
    if (!empty($__rows['gradient_color_1'])) $__dojC1 = $__rows['gradient_color_1'];
    if (!empty($__rows['gradient_color_2'])) $__dojC2 = $__rows['gradient_color_2'];
} catch (Exception $e) { /* table absente : thème par défaut */ }

if ($__dojTheme === '2'):
?>
<style id="doj-theme-vars">
/* ── THÈME 2 : DOJ Sombre ──────────────────────────────────────────────────── */
.page-content{
  --doj-navy:#0c0c0f; --doj-surface:#151519; --doj-elevated:#1f1f26;
  --doj-border:rgba(255,255,255,.07); --doj-border-active:rgba(59,130,246,.4);
  --text-bright:#e8eaed; --text-dim:#9aa0a6; --text-ghost:#5f6368;
  background:
    radial-gradient(ellipse 80% 60% at 15% 20%, rgba(59,130,246,.05) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 85% 80%, rgba(59,130,246,.03) 0%, transparent 50%);
}
body{ background:#0c0c0f; }
.doj-sidebar{ background:linear-gradient(180deg,#151519 0%,#0c0c0f 100%); border-right:1px solid rgba(255,255,255,.06); }
.doj-topbar{ background:rgba(21,21,25,.95); border-bottom:1px solid rgba(255,255,255,.06); }
.sidebar-footer{ background:#0c0c0f; border-top:1px solid rgba(255,255,255,.06); }
.doj-card, .citizen-card, .doj-stat, .penal-code-card{ background:#151519; }
.modal{ background:linear-gradient(135deg,#1f1f26,#151519); }
.form-input, .form-select, .form-textarea, .rich-editor{ background:#0c0c0f; }
</style>
<?php
elseif ($__dojTheme === '3'):
    $rgb1 = doj_hex_rgb($__dojC1, '59,130,246');
    $rgb2 = doj_hex_rgb($__dojC2, '30,64,175');
    $c1   = htmlspecialchars($__dojC1);
    $c2   = htmlspecialchars($__dojC2);
    $c1d  = doj_hex_shift($__dojC1, -40);
    $c1l  = doj_hex_shift($__dojC1, 60);
?>
<style id="doj-theme-vars">
/* ── THÈME 3 : DOJ Dégradé (<?php echo $c1.' → '.$c2; ?>) ───────────────────── */
.page-content{
  --doj-blue:<?php echo $c2; ?>; --doj-blue-bright:<?php echo $c1; ?>; --doj-blue-dim:<?php echo $c1d; ?>;
  --doj-border:rgba(<?php echo $rgb1; ?>,.15); --doj-border-active:rgba(<?php echo $rgb1; ?>,.42);
  background:
    radial-gradient(ellipse 80% 60% at 15% 20%, rgba(<?php echo $rgb1; ?>,.10) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 85% 80%, rgba(<?php echo $rgb2; ?>,.08) 0%, transparent 55%);
}
body{ background:#0f172a; }
.doj-sidebar{
  background:
    linear-gradient(180deg, rgba(<?php echo $rgb1; ?>,.12) 0%, rgba(<?php echo $rgb2; ?>,.05) 100%),
    linear-gradient(180deg,#1e293b 0%,#0f172a 100%);
}
.sidebar-logo-icon, .user-avatar{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>) !important; }
.sidebar-logo-text, .page-title{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c1l; ?>) !important;
  -webkit-background-clip:text !important; background-clip:text !important; }
.btn-primary{ background:linear-gradient(135deg,<?php echo $c1; ?>,<?php echo $c2; ?>) !important; }
.doj-card::before, .doj-stat::before, .modal::before{
  background:linear-gradient(90deg,transparent,<?php echo $c1; ?>,transparent) !important; }
</style>
<?php
endif;
