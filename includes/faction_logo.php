<?php
/**
 * includes/faction_logo.php
 * ─────────────────────────────────────────────────────────────────────────
 * Résolution du logo d'une faction, configuré côté staff dans
 * pages/staff/content/mdt.php (colonne admin_panel_config.custom_logo_url).
 *
 * Il s'agit d'URL externes : elles peuvent être vides, invalides ou pointer
 * vers un fichier disparu. Le contrat est donc simple — faction_logo_url()
 * renvoie null dès que l'affichage ne peut pas être garanti, et l'appelant
 * retombe sur l'icône FontAwesome de la faction. Le cas « URL présente mais
 * fichier mort » se traite côté client (onerror), voir faction_logo_img().
 *
 * Usage :
 *   require_once ROOT_PATH . '/includes/faction_logo.php';
 *   if ($logo = faction_logo_url($db, $f['faction_key'])) { … }
 */

declare(strict_types=1);

if (!function_exists('faction_logo_url')) {

    /**
     * Table panel_key → custom_logo_url, chargée en une seule requête.
     * Indispensable : roles.php boucle sur toutes les factions.
     *
     * @return array<string,string>
     */
    function faction_logo_map(PDO $db): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        try {
            // La colonne est ajoutée par l'auto-migration de mdt.php : sur une base
            // pas encore migrée, la requête échoue et l'on retombe sur les icônes.
            $rows = $db->query("SELECT panel_key, custom_logo_url FROM admin_panel_config")
                       ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $u = trim((string) ($r['custom_logo_url'] ?? ''));
                if ($u !== '') {
                    $map[(string) $r['panel_key']] = $u;
                }
            }
        } catch (Throwable $e) {
            // Silencieux et volontaire : une base non migrée n'est pas une erreur.
            $map = [];
        }
        return $map;
    }

    /**
     * Clé de panel correspondant à une clé de faction.
     *
     * Les deux coïncident la plupart du temps (DYNASTY, LSPD…), mais pas
     * toujours : le catalogue fait foi. On y cherche l'entrée dont le champ
     * `faction` vaut la clé demandée et on retient son `key`.
     */
    function faction_logo_panel_key(PDO $db, string $factionKey): string
    {
        static $alias = null;
        if ($alias === null) {
            $alias = [];
            try {
                if (!function_exists('mdt_panel_catalog_categories')) {
                    require_once __DIR__ . '/../config/mdt_panel_catalog.php';
                }
                foreach (mdt_panel_catalog_categories($db) as $panels) {
                    foreach ($panels as $p) {
                        $fk = (string) ($p['faction'] ?? '');
                        $pk = (string) ($p['key'] ?? '');
                        if ($fk !== '' && $pk !== '' && !isset($alias[$fk])) {
                            $alias[$fk] = $pk;
                        }
                    }
                }
            } catch (Throwable $e) {
                $alias = [];
            }
        }
        return $alias[$factionKey] ?? $factionKey;
    }

    /**
     * URL du logo d'une faction, ou null s'il n'y a rien d'affichable.
     *
     * null est retourné quand : la clé est vide, aucun logo n'est configuré,
     * la colonne custom_logo_url n'existe pas encore, ou l'URL n'est pas en
     * http(s) — même validation que getPanelLogoUrl() dans pages/dashboard.php.
     */
    function faction_logo_url(PDO $db, string $factionKey): ?string
    {
        $factionKey = trim($factionKey);
        if ($factionKey === '') {
            return null;
        }
        $map = faction_logo_map($db);

        // Correspondance directe, puis via le catalogue de panels.
        $u = $map[$factionKey] ?? ($map[faction_logo_panel_key($db, $factionKey)] ?? null);
        if ($u === null || $u === '') {
            return null;
        }
        return preg_match('#^https?://#i', $u) ? $u : null;
    }

    /**
     * Rend le contenu d'une pastille de faction : <img> avec repli JavaScript
     * sur l'icône si le fichier distant est mort, sinon directement l'icône.
     *
     * Trois cas couverts :
     *   · URL absente        → repli PHP, l'icône est rendue telle quelle
     *   · URL morte          → repli JS via onerror, jamais d'image cassée
     *   · logo au mauvais ratio → object-fit:contain dans le carré existant
     *
     * @param string $icon    Icône FontAwesome de repli (sans le préfixe fa-)
     * @param int    $padding Marge intérieure en px, à ajuster selon la taille
     */
    function faction_logo_img(PDO $db, string $factionKey, string $icon, int $padding = 3): string
    {
        $iconHtml = '<i class="fa-solid fa-' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>';
        $logo = faction_logo_url($db, $factionKey);
        if ($logo === null) {
            return $iconHtml;
        }
        // Le repli est injecté via outerHTML : les quotes sont encodées pour
        // rester valides dans un attribut HTML.
        $fallback = htmlspecialchars(
            "this.outerHTML=" . json_encode($iconHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
        return '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="" '
             . 'referrerpolicy="no-referrer" loading="lazy" '
             . 'style="width:100%;height:100%;object-fit:contain;padding:' . max(0, $padding) . 'px" '
             . 'onerror="' . $fallback . '">';
    }
}
