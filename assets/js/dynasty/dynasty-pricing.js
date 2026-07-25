/**
 * ══════════════════════════════════════════════════════════════════════
 *  DynastyPricing — jumeau JavaScript de includes/dynasty_pricing.php
 * ══════════════════════════════════════════════════════════════════════
 *
 *  Sert l'affichage temps réel : la barre de facturation du site public et
 *  l'aperçu de l'onglet Tarification du panel. La référence reste le PHP —
 *  toute évolution de la formule doit être répercutée dans les deux fichiers.
 *
 *  Formule (identique à dynasty_pricing_compute) :
 *    base        = categorie.prix_base_{mode}   (× multiplicateur_achat en achat)
 *    + options « fixe »        → valeur_{mode} additionnée
 *    + garage.supplement_{mode}
 *    = sous_total
 *    × chaque option « multiplicateur » (valeur = facteur direct : 1.15 = +15 %)
 *    total       = sous_total × (1 + taxe)
 *
 *  Contrôle de non-régression :
 *    Quartier Pauvre, location, aucune option, garage 2 places
 *    → 1000 + 0 + 0 = 1000 → × 1.30 = 1300 $
 *
 *  Les structures `cfg` sont celles injectées par PHP :
 *    cfg.categories : { [id]: {id, cle, label, couleur, prix_base_location, prix_base_achat} }
 *    cfg.options    : { [cle]: {cle, label, type, valeur_location, valeur_achat} }
 *    cfg.garages    : { [places]: {places, supplement_location, supplement_achat} }
 *    cfg.config     : { taxe, devise, multiplicateur_achat, … }  (valeurs texte)
 */
(function (global) {
    'use strict';

    /** Réglage global typé, avec repli si la clé est absente ou vide. */
    function setting(cfg, cle, defaut) {
        var v = (cfg && cfg.config) ? cfg.config[cle] : null;
        if (v === undefined || v === null || v === '') return defaut;
        if (typeof defaut === 'number') {
            var n = parseFloat(v);
            return isNaN(n) ? defaut : n;
        }
        return v;
    }

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function round2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; }

    /** Première valeur d'un objet indexé (repli quand la catégorie est inconnue). */
    function firstOf(obj) {
        for (var k in obj) { if (Object.prototype.hasOwnProperty.call(obj, k)) return obj[k]; }
        return null;
    }

    /**
     * @param {object} cfg  grille tarifaire injectée par PHP
     * @param {object} sel  {mode, categorie_id, options:[cle], garage_places}
     * @returns {object} {mode, base, options, garage, sous_total, taxe_taux,
     *                    taxe_montant, total, devise, detail, categorie}
     */
    function compute(cfg, sel) {
        cfg = cfg || {}; sel = sel || {};
        var categories = cfg.categories || {};
        var optionsMap = cfg.options    || {};
        var garages    = cfg.garages    || {};

        var mode   = (sel.mode === 'achat') ? 'achat' : 'location';
        var devise = setting(cfg, 'devise', '$');
        var taxe   = clamp(setting(cfg, 'taxe', 0.30), 0, 10);
        var multAc = setting(cfg, 'multiplicateur_achat', 1);
        if (!(multAc > 0)) multAc = 1;

        /* ── Base : prix de la catégorie ── */
        var cat = categories[sel.categorie_id];
        if (!cat) cat = firstOf(categories);
        var base = cat ? parseFloat(cat['prix_base_' + mode]) || 0 : 0;
        if (mode === 'achat') base *= multAc;

        /* ── Options : fixes additionnées, multiplicateurs mis de côté ── */
        var choisies  = Array.isArray(sel.options) ? sel.options : [];
        var vues      = {};
        var fixes     = 0;
        var multiplis = [];
        var detail    = [];

        choisies.forEach(function (cle) {
            if (vues[cle]) return;           // doublon
            vues[cle] = true;
            var opt = optionsMap[cle];
            if (!opt) return;                // option inconnue ou désactivée
            var valeur = parseFloat(opt['valeur_' + mode]) || 0;
            if (opt.type === 'multiplicateur') {
                if (valeur > 0) multiplis.push({ cle: cle, label: opt.label, valeur: valeur });
            } else {
                fixes += valeur;
                detail.push({ cle: cle, label: opt.label, type: 'fixe', montant: valeur });
            }
        });

        /* ── Garage ── */
        var gar    = garages[sel.garage_places];
        var garage = gar ? (parseFloat(gar['supplement_' + mode]) || 0) : 0;

        /* ── Sous-total, puis multiplicateurs ── */
        var sousTotal = base + fixes + garage;
        multiplis.forEach(function (m) {
            var avant = sousTotal;
            sousTotal *= m.valeur;
            detail.push({
                cle: m.cle, label: m.label, type: 'multiplicateur',
                facteur: m.valeur, montant: sousTotal - avant
            });
        });

        // « Options » au sens de la barre de facturation : tout ce qui s'ajoute
        // à la base et au garage, multiplicateurs compris.
        var optionsTotal = sousTotal - base - garage;
        var taxeMontant  = sousTotal * taxe;

        return {
            mode:         mode,
            base:         round2(base),
            options:      round2(optionsTotal),
            garage:       round2(garage),
            sous_total:   round2(sousTotal),
            taxe_taux:    taxe,
            taxe_montant: round2(taxeMontant),
            total:        round2(sousTotal + taxeMontant),
            devise:       devise,
            detail:       detail,
            categorie:    cat || null
        };
    }

    /** Ex. 1300 → « 1 300 $ » · 1300.5 → « 1 300,50 $ » (miroir de dynasty_pricing_format). */
    function format(montant, devise) {
        montant = parseFloat(montant) || 0;
        devise  = devise || '$';
        var dec   = (montant % 1 === 0) ? 0 : 2;
        var parts = Math.abs(montant).toFixed(dec).split('.');
        parts[0]  = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' '); // séparateur de milliers, comme number_format
        var s = parts.join(',');
        return (montant < 0 ? '-' : '') + s + ' ' + devise;
    }

    global.DynastyPricing = { compute: compute, format: format, setting: setting };
})(window);
