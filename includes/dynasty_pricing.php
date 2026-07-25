<?php
/**
 * includes/dynasty_pricing.php
 * ─────────────────────────────────────────────────────────────────────────
 * Formule de tarification immobilière Dynasty — source unique de vérité,
 * partagée par le panel (pages/companies/dynasty/zone.php) et par le site
 * public (pages/companies/dynasty/public/index.php).
 *
 * Le calcul est redondé à l'identique en JavaScript dans
 * assets/js/dynasty/dynasty-pricing.js pour l'affichage temps réel. Toute
 * évolution de la formule doit être répercutée dans les deux fichiers —
 * le contrôle de non-régression ci-dessous sert de garde-fou.
 *
 * ── Formule ──────────────────────────────────────────────────────────────
 *   base        = categorie.prix_base_{mode}   (× multiplicateur_achat en achat)
 *   + options « fixe »        → valeur_{mode} additionnée
 *   + garage.supplement_{mode}
 *   = sous_total
 *   × chaque option « multiplicateur » (valeur = facteur direct : 1.15 = +15 %)
 *   total       = sous_total × (1 + taxe)
 *
 * ── Contrôle de non-régression ───────────────────────────────────────────
 *   Quartier Pauvre, location, aucune option, garage 2 places :
 *   1000 + 0 + 0 = 1000  →  × 1.30  =  1300 $   (cf. maquette)
 */

declare(strict_types=1);

if (!function_exists('dynasty_pricing_load')) {

    /**
     * Charge l'intégralité de la grille tarifaire (catégories, options,
     * garages, réglages globaux) en une passe.
     *
     * Dégradation gracieuse : si la base répond mal, on retourne une structure
     * vide plutôt que de laisser remonter l'exception — une page de calcul
     * sans données vaut mieux qu'une page blanche.
     *
     * @param bool $onlyActive true = seulement les lignes actives (site public),
     *                         false = tout, y compris l'inactif (panel d'édition).
     * @return array{categories:array,options:array,garages:array,config:array}
     */
    function dynasty_pricing_load(PDO $db, bool $onlyActive = true): array
    {
        $cache = &dynasty_pricing_cache();
        $ck    = $onlyActive ? 'actifs' : 'tous';
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }

        $out = ['categories' => [], 'options' => [], 'garages' => [], 'config' => []];

        try {
            $wActif = $onlyActive ? 'WHERE actif = 1' : '';

            $rows = $db->query("SELECT id, cle, label, couleur, prix_base_location, prix_base_achat, ordre, actif
                                  FROM dynasty_zone_categories $wActif
                                 ORDER BY ordre ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out['categories'][(int) $r['id']] = [
                    'id'                 => (int) $r['id'],
                    'cle'                => (string) $r['cle'],
                    'label'              => (string) $r['label'],
                    'couleur'            => (string) $r['couleur'],
                    'prix_base_location' => (float) $r['prix_base_location'],
                    'prix_base_achat'    => (float) $r['prix_base_achat'],
                    'ordre'              => (int) $r['ordre'],
                    'actif'              => (int) $r['actif'] === 1,
                ];
            }

            $rows = $db->query("SELECT id, cle, label, type, valeur_location, valeur_achat, ordre, actif
                                  FROM dynasty_tarif_options $wActif
                                 ORDER BY ordre ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out['options'][(string) $r['cle']] = [
                    'id'              => (int) $r['id'],
                    'cle'             => (string) $r['cle'],
                    'label'           => (string) $r['label'],
                    'type'            => ($r['type'] === 'multiplicateur') ? 'multiplicateur' : 'fixe',
                    'valeur_location' => (float) $r['valeur_location'],
                    'valeur_achat'    => (float) $r['valeur_achat'],
                    'ordre'           => (int) $r['ordre'],
                    'actif'           => (int) $r['actif'] === 1,
                ];
            }

            $rows = $db->query("SELECT id, places, supplement_location, supplement_achat, icone, ordre, actif
                                  FROM dynasty_tarif_garages $wActif
                                 ORDER BY ordre ASC, places ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out['garages'][(int) $r['places']] = [
                    'id'                  => (int) $r['id'],
                    'places'              => (int) $r['places'],
                    'supplement_location' => (float) $r['supplement_location'],
                    'supplement_achat'    => (float) $r['supplement_achat'],
                    'icone'               => $r['icone'] !== null ? (string) $r['icone'] : null,
                    'ordre'               => (int) $r['ordre'],
                    'actif'               => (int) $r['actif'] === 1,
                ];
            }

            foreach ($db->query("SELECT cle, valeur FROM dynasty_tarif_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['config'][(string) $r['cle']] = (string) $r['valeur'];
            }
        } catch (Throwable $e) {
            error_log('[Dynasty Pricing] load: ' . $e->getMessage());
        }

        return $cache[$ck] = $out;
    }

    /**
     * Cache par requête de la grille tarifaire, retourné par référence pour
     * que dynasty_pricing_flush_cache() puisse le vider.
     */
    function &dynasty_pricing_cache(): array
    {
        static $cache = [];
        return $cache;
    }

    /**
     * Vide le cache — indispensable dans l'API, qui écrit la grille puis
     * relit immédiatement pour renvoyer l'état à jour au client.
     */
    function dynasty_pricing_flush_cache(): void
    {
        $cache = &dynasty_pricing_cache();
        $cache = [];
    }

    /**
     * Prépare la grille pour json_encode à destination du JavaScript.
     *
     * Les trois tables sont indexées par id / clé / nombre de places. PHP les
     * sérialiserait en tableau JSON si les clés se trouvaient être 0,1,2… — un
     * palier de garage « 0 place » puis « 1 place » suffirait à casser
     * garages[2] côté JS. Le cast en objet garantit un accès par clé stable.
     */
    function dynasty_pricing_for_js(array $cfg): array
    {
        return [
            'categories' => (object) ($cfg['categories'] ?? []),
            'options'    => (object) ($cfg['options'] ?? []),
            'garages'    => (object) ($cfg['garages'] ?? []),
            'config'     => (object) ($cfg['config'] ?? []),
        ];
    }

    /** Réglage global typé, avec repli si la clé est absente ou vide. */
    function dynasty_pricing_setting(array $cfg, string $cle, $defaut)
    {
        $v = $cfg['config'][$cle] ?? null;
        if ($v === null || $v === '') {
            return $defaut;
        }
        return is_float($defaut) ? (float) $v : $v;
    }

    /**
     * Calcule un devis à partir de la grille et d'une sélection.
     *
     * @param array $cfg Retour de dynasty_pricing_load()
     * @param array $sel [
     *     'mode'          => 'location'|'achat',
     *     'categorie_id'  => int,
     *     'options'       => string[]  clés d'options cochées,
     *     'garage_places' => int,
     * ]
     * @return array{
     *     mode:string, base:float, options:float, garage:float, sous_total:float,
     *     taxe_taux:float, taxe_montant:float, total:float, devise:string,
     *     detail:array, categorie:?array
     * }
     */
    function dynasty_pricing_compute(array $cfg, array $sel): array
    {
        $mode   = (($sel['mode'] ?? 'location') === 'achat') ? 'achat' : 'location';
        $devise = (string) dynasty_pricing_setting($cfg, 'devise', '$');
        $taxe   = (float) dynasty_pricing_setting($cfg, 'taxe', 0.30);
        $multAc = (float) dynasty_pricing_setting($cfg, 'multiplicateur_achat', 1.0);

        // Taux de taxe borné : une valeur aberrante en base ne doit pas produire
        // un montant absurde côté public.
        $taxe = max(0.0, min(10.0, $taxe));
        if ($multAc <= 0) {
            $multAc = 1.0;
        }

        /* ── Base : prix de la catégorie ── */
        $catId = (int) ($sel['categorie_id'] ?? 0);
        $cat   = $cfg['categories'][$catId] ?? null;
        if ($cat === null) {
            // Repli : première catégorie disponible (la page publique arrive
            // toujours avec une sélection, mais une URL bricolée ne doit pas casser).
            $cat = $cfg['categories'] ? reset($cfg['categories']) : null;
        }
        $base = $cat ? (float) $cat['prix_base_' . $mode] : 0.0;
        if ($mode === 'achat') {
            $base *= $multAc;
        }

        /* ── Options : fixes additionnées, multiplicateurs mis de côté ── */
        $choisies    = array_values(array_unique(array_map('strval', (array) ($sel['options'] ?? []))));
        $fixes       = 0.0;
        $multiplis   = [];
        $detail      = [];
        foreach ($choisies as $cle) {
            $opt = $cfg['options'][$cle] ?? null;
            if ($opt === null) {
                continue; // option inconnue ou désactivée : ignorée silencieusement
            }
            $valeur = (float) $opt['valeur_' . $mode];
            if ($opt['type'] === 'multiplicateur') {
                if ($valeur > 0) {
                    $multiplis[] = ['cle' => $cle, 'label' => $opt['label'], 'valeur' => $valeur];
                }
            } else {
                $fixes += $valeur;
                $detail[] = ['cle' => $cle, 'label' => $opt['label'], 'type' => 'fixe', 'montant' => $valeur];
            }
        }

        /* ── Garage ── */
        $places = (int) ($sel['garage_places'] ?? 0);
        $gar    = $cfg['garages'][$places] ?? null;
        $garage = $gar ? (float) $gar['supplement_' . $mode] : 0.0;

        /* ── Sous-total, puis multiplicateurs ── */
        $sousTotal = $base + $fixes + $garage;
        foreach ($multiplis as $m) {
            $avant = $sousTotal;
            $sousTotal *= $m['valeur'];
            $detail[] = [
                'cle'     => $m['cle'],
                'label'   => $m['label'],
                'type'    => 'multiplicateur',
                'facteur' => $m['valeur'],
                'montant' => $sousTotal - $avant,
            ];
        }

        // « Options » au sens de la barre de facturation : tout ce qui s'ajoute
        // à la base et au garage, multiplicateurs compris.
        $optionsTotal = $sousTotal - $base - $garage;

        $taxeMontant = $sousTotal * $taxe;
        $total       = $sousTotal + $taxeMontant;

        return [
            'mode'         => $mode,
            'base'         => round($base, 2),
            'options'      => round($optionsTotal, 2),
            'garage'       => round($garage, 2),
            'sous_total'   => round($sousTotal, 2),
            'taxe_taux'    => $taxe,
            'taxe_montant' => round($taxeMontant, 2),
            'total'        => round($total, 2),
            'devise'       => $devise,
            'detail'       => $detail,
            'categorie'    => $cat,
        ];
    }

    /**
     * Formatage d'un montant pour l'affichage (panel et site public).
     * Ex. 1300.0 → « 1 300 $ », 1300.5 → « 1 300,50 $ ».
     */
    function dynasty_pricing_format(float $montant, string $devise = '$'): string
    {
        $decimales = (fmod($montant, 1.0) === 0.0) ? 0 : 2;
        return number_format($montant, $decimales, ',', ' ') . ' ' . $devise;
    }
}
