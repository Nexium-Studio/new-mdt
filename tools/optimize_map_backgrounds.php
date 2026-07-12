<?php
/**
 * tools/optimize_map_backgrounds.php — Optimisation des fonds de carte du Système de Cartes MDT.
 *
 * Lit les 3 fichiers bruts fournis dans   <racine_site>/map/   :
 *   - GTAV_ATLUS_8192x8192.png     → atlas.webp
 *   - GTAV-HD-MAP-roadmap.jpg      → roadmap.webp
 *   - GTAV-HD-MAP-satellite.jpg    → satellite.webp
 * et génère des versions web optimisées + miniatures dans
 *   assets/maps/backgrounds/{atlas,roadmap,satellite}.webp (+ *_thumb.webp)
 *
 * Ce système de cartes est INDÉPENDANT du système LSPD (assets/img_map/) : on ne
 * touche pas à ce dossier, on produit nos propres assets décorrélés.
 *
 * USAGE (CLI, GD peut devoir être activé explicitement) :
 *   php -d extension=gd -d memory_limit=2048M tools/optimize_map_backgrounds.php
 * ou simplement ouvrir le fichier dans le navigateur (serveur avec GD/Imagick actif).
 *
 * Moteurs supportés (par ordre de préférence) : Imagick ext → ImageMagick CLI → GD(WebP).
 * SÉCURITÉ : script d'outillage — le conserver hors des zones publiques ou le supprimer
 * après usage. Il n'exécute aucune entrée utilisateur.
 */

declare(strict_types=1);
set_time_limit(600);
ini_set('memory_limit', '2048M');

/* ─── Dossiers ─────────────────────────────────────────────── */
$projectRoot = dirname(__DIR__);                 // .../site/MDT V2
$rawDir      = dirname($projectRoot) . '/map/';  // .../site/map/  (fichiers bruts fournis)
$outDir      = $projectRoot . '/assets/maps/backgrounds/';

/* ─── Paramètres ───────────────────────────────────────────── */
define('TARGET_SIZE',  4096);  // px — dimension max de la version web
define('THUMB_SIZE',   320);   // px — miniatures (sélecteur de fond + previews galerie)
define('WEBP_QUALITY', 82);
define('THUMB_QUALITY', 78);

/* ─── Cartes source → sortie ───────────────────────────────── */
$targets = [
    'atlas' => [
        'candidates' => ['GTAV_ATLUS_8192x8192.png', 'GTAV_ATLAS_8192x8192.png', 'atlas.png', 'Atlas.png'],
        'output'     => 'atlas.webp',
        'thumb'      => 'atlas_thumb.webp',
        'label'      => 'Atlas',
    ],
    'roadmap' => [
        'candidates' => ['GTAV-HD-MAP-roadmap.jpg', 'GTAV-HD-MAP-roadmap.jpeg', 'roadmap.jpg', 'Roadmap.jpg'],
        'output'     => 'roadmap.webp',
        'thumb'      => 'roadmap_thumb.webp',
        'label'      => 'Roadmap',
    ],
    'satellite' => [
        'candidates' => ['GTAV-HD-MAP-satellite.jpg', 'GTAV-HD-MAP-satellite.jpeg', 'satellite.jpg', 'Satellite.jpg'],
        'output'     => 'satellite.webp',
        'thumb'      => 'satellite_thumb.webp',
        'label'      => 'Satellite',
    ],
];

$isCli = (PHP_SAPI === 'cli');

function rout(string $msg, string $type = 'info'): void
{
    global $isCli;
    static $colors = ['ok' => "\033[32m", 'error' => "\033[31m", 'warn' => "\033[33m", 'info' => "\033[36m", 'reset' => "\033[0m"];
    static $styles = ['ok' => 'color:#22c55e', 'error' => 'color:#ef4444', 'warn' => 'color:#f97316', 'info' => 'color:#38bdf8'];
    if ($isCli) {
        echo ($colors[$type] ?? '') . $msg . $colors['reset'] . PHP_EOL;
    } else {
        echo '<div style="' . ($styles[$type] ?? 'color:#e2e8f0') . ';font-family:monospace;font-size:13px;line-height:1.7;white-space:pre">'
           . htmlspecialchars($msg) . '</div>' . PHP_EOL;
        if (ob_get_level()) { ob_flush(); }
        flush();
    }
}

function findSource(array $candidates, string $dir): ?string
{
    foreach ($candidates as $f) {
        $p = $dir . $f;
        if (is_readable($p) && filesize($p) > 1000) {
            return $p;
        }
    }
    return null;
}

/* ─── Détection outils ─────────────────────────────────────── */
function hasImagickExt(): bool { return extension_loaded('imagick'); }
function hasImageMagickCli(): bool
{
    foreach (['magick -version 2>&1', 'convert -version 2>&1'] as $c) {
        $o = @shell_exec($c);
        if (is_string($o) && strpos($o, 'ImageMagick') !== false) { return true; }
    }
    return false;
}
function hasGdWebp(): bool
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) { return false; }
    $i = gd_info();
    return !empty($i['WebP Support']);
}

/* ─── Moteurs de redimensionnement ─────────────────────────── */
function resizeImagickExt(string $src, string $dst, int $size, int $quality): bool
{
    try {
        $im = new Imagick($src);
        $im->setImageCompressionQuality($quality);
        $im->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1, true);
        $im->setImageFormat('webp');
        $im->writeImage($dst);
        $im->destroy();
        return is_file($dst) && filesize($dst) > 500;
    } catch (Throwable $e) {
        rout('    Imagick ext : ' . $e->getMessage(), 'error');
        return false;
    }
}

function resizeImageMagickCli(string $src, string $dst, int $size, int $quality): bool
{
    $bin = (stripos((string) @shell_exec('magick -version 2>&1'), 'ImageMagick') !== false) ? 'magick' : 'convert';
    $cmd = sprintf('%s %s -auto-orient -filter Lanczos -resize %dx%d\> -quality %d %s 2>&1',
        $bin, escapeshellarg($src), $size, $size, $quality, escapeshellarg($dst));
    $out = @shell_exec($cmd);
    if (!is_file($dst) || filesize($dst) < 500) {
        rout('    ImageMagick CLI : ' . ($out ?: 'aucune sortie'), 'error');
        return false;
    }
    return true;
}

function resizeGd(string $src, string $dst, int $size, int $quality): bool
{
    $info = @getimagesize($src);
    if (!$info) { rout('    GD : lecture impossible ' . basename($src), 'error'); return false; }
    $mime = $info['mime'];
    $srcImg = null;
    if ($mime === 'image/webp')      { $srcImg = @imagecreatefromwebp($src); }
    elseif ($mime === 'image/jpeg')  { $srcImg = @imagecreatefromjpeg($src); }
    elseif ($mime === 'image/png')   { $srcImg = @imagecreatefrompng($src); }
    if (!$srcImg) { rout('    GD : format non supporté (' . $mime . ')', 'error'); return false; }

    $ow = imagesx($srcImg); $oh = imagesy($srcImg);
    $ratio = min($size / $ow, $size / $oh, 1);          // ne jamais agrandir
    $nw = max(1, (int) round($ow * $ratio));
    $nh = max(1, (int) round($oh * $ratio));

    $dstImg = imagecreatetruecolor($nw, $nh);
    imagealphablending($dstImg, false);
    imagesavealpha($dstImg, true);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($srcImg);

    $ok = imagewebp($dstImg, $dst, $quality);
    imagedestroy($dstImg);
    if (!$ok || !is_file($dst) || filesize($dst) < 500) { rout('    GD : écriture WebP échouée', 'error'); return false; }
    return true;
}

function doResize(string $src, string $dst, int $size, int $quality): bool
{
    if (hasImagickExt())     { return resizeImagickExt($src, $dst, $size, $quality); }
    if (hasImageMagickCli()) { return resizeImageMagickCli($src, $dst, $size, $quality); }
    if (hasGdWebp())         { return resizeGd($src, $dst, $size, $quality); }
    rout('    AUCUN moteur image disponible.', 'error');
    return false;
}

/* ─── Wrapper HTML si navigateur ───────────────────────────── */
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    @ini_set('output_buffering', '0');
    if (ob_get_level()) { ob_end_flush(); }
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Optimisation fonds de carte</title>'
       . '<style>body{background:#0a1020;color:#e2e8f0;padding:2rem;font-family:monospace;font-size:14px;line-height:1.7}'
       . 'h1{color:#38bdf8;font-size:1.2rem;margin-bottom:1rem}.box{background:#0f172a;border:1px solid #1e293b;border-radius:10px;padding:1.25rem}</style>'
       . '</head><body><h1>Optimisation des fonds de carte (Système de Cartes MDT)</h1><div class="box">';
}

/* ═══ MAIN ═══ */
rout('Dossier source : ' . $rawDir);
rout('Dossier sortie : ' . $outDir);
rout('PHP            : ' . PHP_VERSION);

if (!is_dir($rawDir)) { rout('ERREUR : dossier source introuvable (' . $rawDir . ').', 'error'); if (!$isCli) { echo '</div></body></html>'; } exit(1); }
if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) { rout('ERREUR : impossible de créer ' . $outDir, 'error'); if (!$isCli) { echo '</div></body></html>'; } exit(1); }

$engine = hasImagickExt() ? 'Imagick (ext)' : (hasImageMagickCli() ? 'ImageMagick (CLI)' : (hasGdWebp() ? 'GD + WebP' : null));
if ($engine === null) {
    rout('', 'warn');
    rout('Aucun moteur image disponible. Activez GD (php -d extension=gd ...) ou installez ImageMagick.', 'error');
    if (!$isCli) { echo '</div></body></html>'; }
    exit(1);
}
rout('Moteur         : ' . $engine, 'ok');
rout('');

$errors = 0; $done = 0;
foreach ($targets as $name => $cfg) {
    rout('─── ' . $cfg['label'] . ' ───────────────────────────');
    $src = findSource($cfg['candidates'], $rawDir);
    if (!$src) {
        rout('  IGNORÉ : source introuvable (' . implode(', ', $cfg['candidates']) . ').', 'warn');
        $errors++;
        continue;
    }
    $info = @getimagesize($src);
    $mb = round(filesize($src) / 1048576, 1);
    rout('  Source : ' . basename($src) . ' — ' . ($info ? $info[0] . '×' . $info[1] . ' px, ' : '') . $mb . ' Mo');

    /* Version web */
    $webDst = $outDir . $cfg['output'];
    $tmp    = $webDst . '.tmp.webp';
    $t = microtime(true);
    if (doResize($src, $tmp, TARGET_SIZE, WEBP_QUALITY)) {
        @rename($tmp, $webDst);
        rout('  ✓ ' . $cfg['output'] . ' : ' . round(filesize($webDst) / 1048576, 2) . ' Mo (' . round((microtime(true) - $t) * 1000) . ' ms)', 'ok');
    } else {
        @unlink($tmp);
        rout('  ✗ Échec version web.', 'error');
        $errors++;
        continue;
    }

    /* Miniature */
    $thDst = $outDir . $cfg['thumb'];
    $thTmp = $thDst . '.tmp.webp';
    if (doResize($src, $thTmp, THUMB_SIZE, THUMB_QUALITY)) {
        @rename($thTmp, $thDst);
        rout('  ✓ ' . $cfg['thumb'] . ' : ' . round(filesize($thDst) / 1024) . ' Ko', 'ok');
    } else {
        @unlink($thTmp);
        rout('  ⚠ Miniature échouée (non bloquant).', 'warn');
    }
    $done++;
}

/* .htaccess de cache */
$ht = $outDir . '.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht,
        "# Cache longue durée pour les fonds statiques du Système de Cartes MDT.\n"
      . "<IfModule mod_headers.c>\n  <FilesMatch \"\\.(webp|jpg|jpeg|png)$\">\n"
      . "    Header set Cache-Control \"public, max-age=2592000\"\n  </FilesMatch>\n</IfModule>\n");
}

rout('');
rout('═══════════════════════════════════════════════');
rout(($errors === 0 ? '✓ Terminé — ' : '⚠ Terminé avec avertissements — ') . $done . '/3 fonds générés.', $errors === 0 ? 'ok' : 'warn');
if (!$isCli) { echo '</div></body></html>'; }
