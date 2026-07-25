/**
 * Dynasty · Site public — Catalogue (filtrage client, aucun appel réseau).
 */
(function () {
    'use strict';
    var BIENS = window.DY_BIENS || [];
    var CAT = window.DY_CAT || {}, TYPES = window.DY_TYPES || {}, ICONS = window.DY_ICONS || {};
    var DEV = window.DY_DEVISE || '$';
    var $ = function (id) { return document.getElementById(id); };
    var fmt = function (v) { return DynastyPricing.format(v, DEV); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    var fCat = $('fCat'), fType = $('fType'), fTrans = $('fTrans'), fPrix = $('fPrix');

    /* Prix « repère » d'un bien pour le filtre : le plus pertinent selon la dispo. */
    function prixRepere(b) {
        var p = [];
        if (b.location) { if (b.prix_sem) p.push(b.prix_sem * 4); if (b.prix_mois) p.push(b.prix_mois); }
        if (b.vente && b.prix_vente) p.push(b.prix_vente);
        return p.length ? Math.min.apply(null, p) : 0;
    }

    function render() {
        var pmax = parseFloat(fPrix.value) || Infinity;
        $('fPrixVal').textContent = fmt(pmax);

        var vus = BIENS.filter(function (b) {
            if (fCat.value !== '' && String(b.cat) !== String(fCat.value)) return false;
            if (fType.value && b.type !== fType.value) return false;
            if (fTrans.value === 'location' && !b.location) return false;
            if (fTrans.value === 'vente' && !b.vente) return false;
            var pr = prixRepere(b);
            if (pr > 0 && pr > pmax) return false;
            return true;
        });

        $('dyCount').textContent = vus.length + (vus.length > 1 ? ' biens' : ' bien') +
            (vus.length !== BIENS.length ? ' / ' + BIENS.length : '');

        var el = $('dyGoods');
        if (!vus.length) {
            el.innerHTML = '<div class="dy-empty"><i class="fa-solid fa-house-circle-xmark"></i>' +
                'Aucun bien ne correspond à ces critères.</div>';
            return;
        }
        el.innerHTML = vus.map(carte).join('');
    }

    function carte(b) {
        var c = CAT[b.cat];
        var typeLbl = TYPES[b.type] || b.type;
        var icon = ICONS[b.type] || 'fa-building';

        var prixHtml = '';
        if (b.location && (b.prix_sem || b.prix_mois)) {
            var loc = b.prix_sem ? (fmt(b.prix_sem) + ' <small>/ sem.</small>')
                                 : (fmt(b.prix_mois) + ' <small>/ mois</small>');
            prixHtml += '<div class="dy-good-price">' + loc + '</div>';
        }
        if (b.vente && b.prix_vente) {
            prixHtml += '<div class="dy-good-price">' + fmt(b.prix_vente) + ' <small>achat</small></div>';
        }
        if (!prixHtml) prixHtml = '<div class="dy-good-price"><small>Prix sur demande</small></div>';

        var tags = '';
        if (b.location) tags += '<span class="dy-tag loc">Location</span> ';
        if (b.vente) tags += '<span class="dy-tag ven">Vente</span>';

        var specs = [];
        if (b.surface > 0) specs.push('<b>' + b.surface + '</b> m²');
        if (b.pieces > 0) specs.push('<b>' + b.pieces + '</b> p.');
        if (b.chambres > 0) specs.push('<b>' + b.chambres + '</b> ch.');

        return '<article class="dy-good">' +
            '<div class="dy-good-top">' +
                '<span class="dy-good-type"><i class="fa-solid ' + icon + '"></i> ' + esc(typeLbl) + '</span>' +
                '<span class="dy-good-ref">' + esc(b.ref) + '</span>' +
            '</div>' +
            '<div class="dy-good-body">' +
                '<div class="dy-good-title">' + esc(b.titre || 'Bien immobilier') + '</div>' +
                (b.quartier || b.adresse ?
                    '<div class="dy-good-addr"><i class="fa-solid fa-location-dot"></i> ' +
                    esc([b.quartier, b.adresse].filter(Boolean).join(', ')) + '</div>' : '') +
                (c ? '<div class="dy-good-cat"><i style="background:' + esc(c.couleur) + '"></i>' + esc(c.label) + '</div>' : '') +
                (b.desc ? '<div class="dy-good-desc">' + esc(b.desc) + '</div>' : '') +
                (specs.length ? '<div class="dy-good-specs">' + specs.map(function (s) { return '<span>' + s + '</span>'; }).join('') + '</div>' : '') +
            '</div>' +
            '<div class="dy-good-foot">' +
                '<div class="dy-good-prices">' + prixHtml + '</div>' +
                '<div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">' + tags + '</div>' +
            '</div>' +
        '</article>';
    }

    [fCat, fType, fTrans].forEach(function (el) { if (el) el.addEventListener('change', render); });
    if (fPrix) fPrix.addEventListener('input', render);
    render();
})();
