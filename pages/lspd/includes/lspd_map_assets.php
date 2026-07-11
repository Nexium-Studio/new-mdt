<?php
/**
 * Cartes GTA V locales (assets/img_map/) — aucun service externe.
 */
declare(strict_types=1);

function lspd_map_pick_img_file(array $candidates, string $fallback): string
{
    $dir = ROOT_PATH . '/assets/img_map/';
    foreach ($candidates as $f) {
        if ($f !== '' && is_readable($dir . $f) && filesize($dir . $f) > 100) {
            return $f;
        }
    }
    return $fallback;
}

/**
 * URLs absolues des trois fonds (Atlas / Routes / Satellite).
 * Couvre tous les noms de fichiers possibles, y compris les variantes avec casse ou typo.
 *
 * @return array{atlas: string, road: string, satellite: string}
 */
function lspd_map_layer_urls(): array
{
    $base = rtrim(SITE_URL, '/') . '/assets/img_map/';

    $atlasFile = lspd_map_pick_img_file([
        // Variantes courantes + typo "Aatlas"
        'Atlas.webp', 'atlas.webp',
        'Aatlas.webp', 'aatlas.webp',
        'Atlas.png',  'atlas.png',
        'Atlas.jpg',  'atlas.jpg',
    ], 'Atlas.webp');

    $roadFile = lspd_map_pick_img_file([
        'Road.webp', 'road.webp',
        'Road.png',  'road.png',
        'Road.jpg',  'road.jpg',
    ], 'Road.webp');

    $satFile = lspd_map_pick_img_file([
        'Satellite.webp',  'satellite.webp',
        'Satellite.jpg',   'satellite.jpg',
        'Satellite.png',   'satellite.png',
        'Satelite.webp',   'satelite.webp',
        'Satelite.jpg',    'satelite.jpg',
    ], 'Satellite.webp');

    return [
        'atlas'     => $base . $atlasFile,
        'road'      => $base . $roadFile,
        'satellite' => $base . $satFile,
    ];
}

/**
 * Miniatures 256 px (générées par resize_map_images.php).
 * Fallback sur les images full si miniatures absentes.
 *
 * @return array{atlas: string, road: string, satellite: string}
 */
function lspd_map_thumb_urls(): array
{
    $base = rtrim(SITE_URL, '/') . '/assets/img_map/';
    $full = lspd_map_layer_urls();

    $at = lspd_map_pick_img_file(['Atlas_thumb.webp', 'atlas_thumb.webp'], '');
    $ro = lspd_map_pick_img_file(['Road_thumb.webp',  'road_thumb.webp'],  '');
    $sa = lspd_map_pick_img_file(['Satellite_thumb.webp', 'satellite_thumb.webp'], '');

    return [
        'atlas'     => $at !== '' ? $base . $at : $full['atlas'],
        'road'      => $ro !== '' ? $base . $ro : $full['road'],
        'satellite' => $sa !== '' ? $base . $sa : $full['satellite'],
    ];
}

/** @deprecated */
function lspd_map_local_sources(): array  { return lspd_map_layer_urls(); }
/** @deprecated */
function lspd_map_public_img_paths(): array { return lspd_map_layer_urls(); }