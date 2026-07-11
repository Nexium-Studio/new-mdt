<?php
/**
 * resize_map_images.php — Optimisation des fonds de carte GTA V
 * Compatible PHP 7.4+
 *
 * PLACEMENT : à la racine du projet (même niveau que /assets/)
 * USAGE     : ouvrir dans le navigateur OU  php resize_map_images.php  en CLI
 * SÉCURITÉ  : supprimer ce fichier après utilisation.
 */

declare(strict_types=1);
set_time_limit(300);
ini_set('memory_limit', '512M');

/* ─── Détection du dossier images ──────────────────────────── */
$imgDir = __DIR__ . '/assets/img_map/';
if (!is_dir($imgDir)) {
    // Essai depuis un sous-dossier (ex : tools/, scripts/)
    $imgDir = dirname(__DIR__) . '/assets/img_map/';
}
if (!is_dir($imgDir)) {
    die('ERREUR : dossier assets/img_map/ introuvable depuis ' . __DIR__);
}
$imgDir    = rtrim($imgDir, '/') . '/';
$backupDir = $imgDir . 'originals/';

/* ─── Paramètres ────────────────────────────────────────────── */
define('TARGET_SIZE',  4096);  // px — largeur/hauteur max version web
define('THUMB_SIZE',   256);   // px — miniatures pour le sélecteur
define('WEBP_QUALITY', 82);    // qualité WebP 0-100

/* ─── Fichiers à traiter ────────────────────────────────────── */
$targets = [
    'atlas' => [
        'candidates' => ['Atlas.webp', 'atlas.webp', 'Atlas.png', 'atlas.png'],
        'output'     => 'Atlas.webp',
        'thumb'      => 'Atlas_thumb.webp',
    ],
    'road' => [
        'candidates' => ['Road.webp', 'road.webp', 'Road.png', 'road.png'],
        'output'     => 'Road.webp',
        'thumb'      => 'Road_thumb.webp',
    ],
    'satellite' => [
        'candidates' => ['Satellite.webp', 'satellite.webp', 'Satellite.jpg',
                         'satellite.jpg', 'Satelite.jpg', 'satelite.jpg'],
        'output'     => 'Satellite.webp',
        'thumb'      => 'Satellite_thumb.webp',
    ],
];

/* ─── Helpers ───────────────────────────────────────────────── */
$isCli = (PHP_SAPI === 'cli');

function rout(string $msg, string $type = 'info'): void
{
    global $isCli;
    static $colors = [
        'ok'    => "\033[32m",
        'error' => "\033[31m",
        'warn'  => "\033[33m",
        'info'  => "\033[36m",
        'reset' => "\033[0m",
    ];
    static $styles = [
        'ok'    => 'color:#22c55e',
        'error' => 'color:#ef4444',
        'warn'  => 'color:#f97316',
        'info'  => 'color:#38bdf8',
    ];

    if ($isCli) {
        echo ($colors[$type] ?? '') . $msg . $colors['reset'] . PHP_EOL;
    } else {
        $st = $styles[$type] ?? 'color:#e2e8f0';
        echo '<div style="' . $st . ';font-family:monospace;font-size:13px;line-height:1.7;white-space:pre">'
           . htmlspecialchars($msg) . '</div>' . PHP_EOL;
        // Flush pour affichage progressif dans le navigateur
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function findSource(array $candidates, string $dir): ?string
{
    foreach ($candidates as $f) {
        $path = $dir . $f;
        if (is_readable($path) && filesize($path) > 1000) {
            return $path;
        }
    }
    return null;
}

/* ─── Détection des outils disponibles ─────────────────────── */
function hasImagickExt(): bool
{
    return extension_loaded('imagick');
}

function hasImageMagickCli(): bool
{
    $out = @shell_exec('convert -version 2>&1');
    return is_string($out) && strpos($out, 'ImageMagick') !== false;
}

function hasGdWebp(): bool
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) return false;
    $info = gd_info();
    return !empty($info['WebP Support']);
}

/* ─── Redimensionnement via extension Imagick ───────────────── */
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
        rout('  Imagick ext : ' . $e->getMessage(), 'error');
        return false;
    }
}

/* ─── Redimensionnement via ImageMagick CLI ─────────────────── */
function resizeImageMagickCli(string $src, string $dst, int $size, int $quality): bool
{
    $cmd = sprintf(
        'convert %s -auto-orient -filter Lanczos -resize %dx%d\> -quality %d %s 2>&1',
        escapeshellarg($src),
        $size, $size,
        $quality,
        escapeshellarg($dst)
    );
    $output = @shell_exec($cmd);
    if (!is_file($dst) || filesize($dst) < 500) {
        rout('  ImageMagick CLI erreur : ' . ($output ?: 'aucune sortie'), 'error');
        return false;
    }
    return true;
}

/* ─── Redimensionnement via GD (fallback, déconseillé pour 16K) */
function resizeGd(string $src, string $dst, int $size, int $quality): bool
{
    $info = @getimagesize($src);
    if (!$info) {
        rout('  GD : impossible de lire ' . basename($src), 'error');
        return false;
    }

    $mime = $info['mime'];
    $srcImg = null;

    if ($mime === 'image/webp')  $srcImg = @imagecreatefromwebp($src);
    elseif ($mime === 'image/jpeg') $srcImg = @imagecreatefromjpeg($src);
    elseif ($mime === 'image/png')  $srcImg = @imagecreatefrompng($src);

    if (!$srcImg) {
        rout('  GD : format non supporté (' . $mime . ')', 'error');
        return false;
    }

    $ow    = imagesx($srcImg);
    $oh    = imagesy($srcImg);
    $ratio = min($size / $ow, $size / $oh);
    $nw    = max(1, (int) round($ow * $ratio));
    $nh    = max(1, (int) round($oh * $ratio));

    $dstImg = imagecreatetruecolor($nw, $nh);
    imagealphablending($dstImg, false);
    imagesavealpha($dstImg, true);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($srcImg);

    $ok = imagewebp($dstImg, $dst, $quality);
    imagedestroy($dstImg);

    if (!$ok || !is_file($dst) || filesize($dst) < 500) {
        rout('  GD : écriture WebP échouée', 'error');
        return false;
    }
    return true;
}

/* ─── Fonction principale de resize ────────────────────────── */
function doResize(string $src, string $dst, int $size, int $quality): bool
{
    if (hasImagickExt()) {
        rout('    moteur : Imagick (extension PHP)', 'info');
        return resizeImagickExt($src, $dst, $size, $quality);
    }
    if (hasImageMagickCli()) {
        rout('    moteur : ImageMagick (CLI)', 'info');
        return resizeImageMagickCli($src, $dst, $size, $quality);
    }
    if (hasGdWebp()) {
        rout('    moteur : GD — attention à la RAM pour les images 16K !', 'warn');
        return resizeGd($src, $dst, $size, $quality);
    }
    rout('    AUCUN moteur disponible', 'error');
    return false;
}

/* ═══════════════════════════════════════════════════
 *  HTML wrapper si navigateur
 * ═══════════════════════════════════════════════════ */
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    // ob_implicit_flush pour affichage progressif
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (ob_get_level()) ob_end_flush();

    echo '<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Optimisation cartes</title>
  <style>
    body{background:#0a1020;color:#e2e8f0;padding:2rem 2.5rem;font-family:monospace;font-size:14px;line-height:1.7}
    h1{color:#38bdf8;font-size:1.25rem;margin-bottom:1.5rem}
    .box{background:#0f172a;border:1px solid #1e293b;border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem}
    .warn{background:#431407;border-color:#9a3412;padding:.6rem 1rem;border-radius:8px;color:#fdba74;margin:.5rem 0}
  </style>
</head>
<body>
<h1>🗺 Optimisation des fonds de carte GTA V</h1>
<div class="warn">⚠ Supprimez ce fichier du serveur après utilisation.</div>
<div class="box">';
}

/* ═══════════════════════════════════════════════════
 *  MAIN
 * ═══════════════════════════════════════════════════ */
rout('Dossier images : ' . $imgDir);
rout('PHP version    : ' . PHP_VERSION);
rout('');

$useImagickExt = hasImagickExt();
$useImCli      = !$useImagickExt && hasImageMagickCli();
$useGd         = !$useImagickExt && !$useImCli && hasGdWebp();

rout('Imagick (ext)   : ' . ($useImagickExt ? '✓ disponible' : '✗'), $useImagickExt ? 'ok' : 'warn');
rout('ImageMagick CLI : ' . ($useImCli      ? '✓ disponible' : '✗'), $useImCli      ? 'ok' : 'warn');
rout('GD + WebP       : ' . ($useGd         ? '✓ disponible' : '✗'), $useGd         ? 'ok' : 'warn');

if (!$useImagickExt && !$useImCli && !$useGd) {
    rout('');
    rout('ERREUR : aucun outil de traitement image disponible.', 'error');
    rout('Solution 1 : installer ImageMagick  →  apt install imagemagick', 'warn');
    rout('Solution 2 : activer l\'extension PHP Imagick', 'warn');
    rout('Solution 3 : redimensionner manuellement (voir ci-dessous)', 'warn');
    rout('');
    rout('REDIMENSIONNEMENT MANUEL :', 'info');
    rout('Téléchargez vos images, redimensionnez-les à 4096×4096 px maximum', 'info');
    rout('avec Photoshop / GIMP / squoosh.app, et remettez-les dans assets/img_map/', 'info');
    if (!$isCli) { echo '</div></body></html>'; }
    exit(1);
}

rout('');

/* Création dossier sauvegarde */
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
    rout('Dossier originals/ créé.', 'info');
}

$errors = 0;
$skipped = 0;

foreach ($targets as $name => $cfg) {
    rout('');
    rout('─── ' . strtoupper($name) . ' ───────────────────────────────────────');

    $src = findSource($cfg['candidates'], $imgDir);
    if (!$src) {
        rout('  IGNORÉ : fichier source introuvable.', 'warn');
        rout('  Cherché : ' . implode(', ', $cfg['candidates']), 'warn');
        $skipped++;
        continue;
    }

    rout('  Source : ' . basename($src));

    /* Infos de l'image source */
    $info = @getimagesize($src);
    if ($info) {
        $sizeMb = round(filesize($src) / 1048576, 1);
        rout('  Dimensions : ' . $info[0] . '×' . $info[1] . ' px — ' . $sizeMb . ' Mo');

        if ($info[0] <= TARGET_SIZE && $info[1] <= TARGET_SIZE) {
            rout('  Image déjà ≤ ' . TARGET_SIZE . ' px, pas de redimensionnement.', 'ok');
            /* Créer quand même la miniature */
        }
    }

    /* Sauvegarde original */
    $backupFile = $backupDir . basename($src);
    if (!is_file($backupFile)) {
        if (@copy($src, $backupFile)) {
            rout('  ✓ Original sauvegardé → originals/' . basename($src), 'ok');
        } else {
            rout('  ⚠ Impossible de copier l\'original (droits ?).', 'warn');
        }
    } else {
        rout('  Original déjà sauvegardé.', 'info');
    }

    /* ── Version web (TARGET_SIZE) ── */
    $webDst  = $imgDir . $cfg['output'];
    $tmpDst  = $webDst . '.tmp_' . time() . '.webp';
    $needResize = !$info || $info[0] > TARGET_SIZE || $info[1] > TARGET_SIZE;

    if ($needResize) {
        rout('  Redimensionnement web (' . TARGET_SIZE . ' px)…');
        $t = microtime(true);
        if (doResize($src, $tmpDst, TARGET_SIZE, WEBP_QUALITY)) {
            $ms = round((microtime(true) - $t) * 1000);
            $mb = round(filesize($tmpDst) / 1048576, 2);
            rename($tmpDst, $webDst);
            rout('  ✓ Web : ' . $mb . ' Mo (' . $ms . ' ms)', 'ok');
        } else {
            @unlink($tmpDst);
            rout('  ✗ Échec redimensionnement web.', 'error');
            $errors++;
        }
    } else {
        /* Convertir en WebP si pas encore au bon format */
        if (strtolower(pathinfo($src, PATHINFO_EXTENSION)) !== 'webp') {
            rout('  Conversion en WebP…');
            if (doResize($src, $webDst, TARGET_SIZE, WEBP_QUALITY)) {
                rout('  ✓ Converti en WebP.', 'ok');
            }
        } else {
            rout('  ✓ Déjà au bon format et taille.', 'ok');
        }
    }

    /* ── Miniature (THUMB_SIZE) ── */
    $thumbDst = $imgDir . $cfg['thumb'];
    rout('  Miniature (' . THUMB_SIZE . ' px)…');
    $tmpThumb = $thumbDst . '.tmp_' . time() . '.webp';
    if (doResize($src, $tmpThumb, THUMB_SIZE, 75)) {
        $kb = round(filesize($tmpThumb) / 1024);
        rename($tmpThumb, $thumbDst);
        rout('  ✓ Miniature : ' . $kb . ' Ko', 'ok');
    } else {
        @unlink($tmpThumb);
        rout('  ⚠ Miniature échouée (non bloquant).', 'warn');
    }
}

/* ─── Résumé ────────────────────────────────────── */
rout('');
rout('═══════════════════════════════════════════════════════');

if ($errors === 0 && $skipped < 3) {
    rout('✓ Optimisation terminée.', 'ok');
    rout('');
    rout('Résultat dans assets/img_map/ :', 'info');
    foreach ($targets as $name => $cfg) {
        $p = $imgDir . $cfg['output'];
        if (is_file($p)) {
            $mb = round(filesize($p) / 1048576, 2);
            rout('  ' . $cfg['output'] . ' : ' . $mb . ' Mo', 'info');
        }
    }
    rout('');
    rout('Rechargez map-view.php → chargement quasi-instantané.', 'ok');
} else {
    rout($errors . ' erreur(s), ' . $skipped . ' ignoré(s).', 'error');
    rout('Vérifiez les messages ci-dessus.', 'warn');
}

rout('');
rout('⚠  SUPPRIMEZ CE FICHIER DU SERVEUR !', 'warn');

if (!$isCli) {
    echo '</div>
<div class="warn" style="margin-top:1.5rem">
  ⚠ <strong>Supprimez ce fichier</strong> du serveur pour des raisons de sécurité.
</div>
</body></html>';
}