<?php
/**
 * Dashboard Builder — thème GLOBAL (décision client : un seul thème pour tous).
 *
 * Stocké dans la table admin_settings via les helpers de includes/site_settings.php :
 *   - dashboard_theme_mode   : dark | light | classic | custom   (défaut dark)
 *   - dashboard_theme_custom : JSON { primary, secondary, btn, text, card, border, category }
 *
 * dashboard_render_theme_head() est appelée tôt dans le <head> de dashboard.php
 * (anti-FOUC). Les surcharges concrètes des modes dark/light/classic vivent dans
 * assets/css/dashboard-builder.css ; ici on ne pose que data-theme + les variables
 * du mode « custom ».
 */

if (!function_exists('dashboard_theme_default_vars')) {
    function dashboard_theme_default_vars(): array
    {
        return [
            'primary'   => '#7C3AED',
            'secondary' => '#3B82F6',
            'btn'       => '#7C3AED',
            'text'      => '#FFFFFF',
            'card'      => '#141829',
            'border'    => '#232a45',
            'category'  => '#A0AEC0',
        ];
    }
}

if (!function_exists('dashboard_get_theme')) {
    /**
     * @return array{mode:string, vars:array<string,string>}
     */
    function dashboard_get_theme(PDO $db): array
    {
        // Défaut = classic : conserve EXACTEMENT le design actuel tant qu'aucun
        // admin n'a explicitement choisi un thème (zéro changement visuel).
        $allowed = ['dark', 'light', 'classic', 'custom'];
        $mode = 'classic';
        $vars = dashboard_theme_default_vars();

        try {
            $rows = mdt_admin_settings_fetch($db, ['dashboard_theme_mode', 'dashboard_theme_custom']);
            $m = trim((string) ($rows['dashboard_theme_mode'] ?? ''));
            if (in_array($m, $allowed, true)) {
                $mode = $m;
            }
            $raw = (string) ($rows['dashboard_theme_custom'] ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($vars as $k => $def) {
                        if (isset($decoded[$k]) && is_string($decoded[$k])) {
                            $vars[$k] = mdt_validate_hex_color($decoded[$k], $def);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // table absente / requête KO → défauts (zéro régression)
        }

        return ['mode' => $mode, 'vars' => $vars];
    }
}

if (!function_exists('dashboard_render_theme_head')) {
    /**
     * Émet le marqueur data-theme (anti-FOUC) + les variables CSS du mode custom.
     * À appeler dans le <head> de dashboard.php, le plus tôt possible.
     */
    function dashboard_render_theme_head(array $theme): void
    {
        $mode = (string) ($theme['mode'] ?? 'dark');
        $safeMode = preg_replace('/[^a-z]/', '', $mode);

        // Anti-FOUC : poser l'attribut avant le rendu CSS.
        echo "<script>document.documentElement.setAttribute('data-dashboard-theme'," . json_encode($safeMode) . ");</script>\n";

        if ($mode === 'custom') {
            $vars = is_array($theme['vars'] ?? null) ? $theme['vars'] : dashboard_theme_default_vars();
            $map = [
                'primary'   => '--db-primary',
                'secondary' => '--db-secondary',
                'btn'       => '--db-btn',
                'text'      => '--db-text',
                'card'      => '--db-card',
                'border'    => '--db-border',
                'category'  => '--db-category',
            ];
            $lines = '';
            foreach ($map as $k => $cssVar) {
                $hex = mdt_validate_hex_color((string) ($vars[$k] ?? ''), dashboard_theme_default_vars()[$k]);
                $lines .= $cssVar . ':' . $hex . ';';
            }
            echo "<style id=\"dashboard-theme-custom\">:root[data-dashboard-theme=\"custom\"]{" . $lines . "}</style>\n";
        }
    }
}
