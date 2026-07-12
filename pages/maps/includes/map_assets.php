<?php
/**
 * pages/maps/includes/map_assets.php
 * ─────────────────────────────────────────────────────────────────────────
 * Résolution des fonds de carte (Atlas / Roadmap / Satellite) du Système de
 * Cartes MDT. Les images optimisées vivent dans assets/maps/backgrounds/
 * (générées par tools/optimize_map_backgrounds.php). Décorrélé de img_map/ (LSPD).
 */

declare(strict_types=1);

if (!function_exists('maps_background_defs')) {
    /**
     * Métadonnées des 3 fonds disponibles.
     * @return array<string, array{key:string,label:string,file:string,thumb:string,icon:string}>
     */
    function maps_background_defs(): array
    {
        return [
            'atlas' => [
                'key'   => 'atlas',
                'label' => 'Atlas',
                'file'  => 'atlas.webp',
                'thumb' => 'atlas_thumb.webp',
                'icon'  => 'map',
            ],
            'roadmap' => [
                'key'   => 'roadmap',
                'label' => 'Roadmap',
                'file'  => 'roadmap.webp',
                'thumb' => 'roadmap_thumb.webp',
                'icon'  => 'road',
            ],
            'satellite' => [
                'key'   => 'satellite',
                'label' => 'Satellite',
                'file'  => 'satellite.webp',
                'thumb' => 'satellite_thumb.webp',
                'icon'  => 'satellite',
            ],
        ];
    }

    /** Normalise une clé de fond, avec repli sur 'atlas'. */
    function maps_background_key(?string $k): string
    {
        $k = strtolower(trim((string) $k));
        return isset(maps_background_defs()[$k]) ? $k : 'atlas';
    }

    /** URL absolue du fond plein écran pour une clé donnée. */
    function maps_background_url(string $key): string
    {
        $defs = maps_background_defs();
        $key  = maps_background_key($key);
        return SITE_URL . '/assets/maps/backgrounds/' . $defs[$key]['file'];
    }

    /** URL absolue de la miniature (previews galerie + sélecteur). */
    function maps_background_thumb(string $key): string
    {
        $defs = maps_background_defs();
        $key  = maps_background_key($key);
        return SITE_URL . '/assets/maps/backgrounds/' . $defs[$key]['thumb'];
    }

    /**
     * Table clé → URL plein écran des 3 fonds (injecté dans le moteur JS).
     * @return array<string,string>
     */
    function maps_background_sources(): array
    {
        $out = [];
        foreach (maps_background_defs() as $k => $_d) {
            $out[$k] = maps_background_url($k);
        }
        return $out;
    }
}
