/**
 * Dynasty · Site public — Calculateur (colonne droite + carte lecture seule).
 * Aucun appel réseau : tout vient de window.DY_DATA injecté par index.php.
 */
(function () {
    'use strict';
    var DATA = window.DY_DATA;
    if (!DATA) return;
    var G = DATA.grille || {};
    var CATS = G.categories || {};
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    /* ── État de la sélection ── */
    var sel = { mode: 'location', categorie_id: 0, options: new Set(), garage_places: 0 };

    var catList = Object.keys(CATS).map(function (k) { return CATS[k]; })
        .sort(function (a, b) { return a.ordre - b.ordre || a.id - b.id; });
    if (catList.length) sel.categorie_id = catList[0].id;

    var optList = Object.keys(G.options || {}).map(function (k) { return G.options[k]; })
        .sort(function (a, b) { return a.ordre - b.ordre || a.id - b.id; });

    var garList = Object.keys(G.garages || {}).map(function (k) { return G.garages[k]; })
        .sort(function (a, b) { return a.ordre - b.ordre || a.places - b.places; });
    if (garList.length) sel.garage_places = garList[0].places;

    /* ══════════ Carte (map-engine.js en lecture seule) ══════════ */
    var engine = null;
    if ($('dyStage') && window.MdtMapEngine) {
        engine = new MdtMapEngine({
            stage: $('dyStage'), plane: $('dyPlane'), img: $('dyImg'),
            canvas: $('dyCanvas'), overlay: $('dyOverlay'),
            apiBase: '', target: { id: 0 },
            background: DATA.background, backgroundSources: DATA.backgroundSources,
            elements: DATA.zones || [], revision: 0,
            canEdit: false,      // lecture seule stricte
            collab: false,       // aucun polling, aucune présence
            zoneHover: true,     // survol + clic → sélection de catégorie
            onZoneHover: function (el, mx, my) { showTip(el, mx, my); },
            onZoneClick: function (el) {
                if (el && el.meta && el.meta.categorie_id && CATS[el.meta.categorie_id]) {
                    setCat(el.meta.categorie_id);
                }
            }
        });
        engine.init();
        window.__dyMap = engine;

        $('dyZoomIn').addEventListener('click', function () { engine.zoomIn(); });
        $('dyZoomOut').addEventListener('click', function () { engine.zoomOut(); });
        $('dyZoomFit').addEventListener('click', function () { engine.resetView(); });
    }

    var tip = $('dyTip');
    function showTip(el, mx, my) {
        if (!tip) return;
        if (!el) { tip.style.display = 'none'; return; }
        var cat = CATS[(el.meta && el.meta.categorie_id) || 0];
        tip.innerHTML = esc(el.title || 'Quartier') +
            '<span class="cat" style="--_c:' + esc(cat ? cat.couleur : '#94a3b8') + '">' +
            esc(cat ? cat.label : 'Non catégorisé') + '</span>';
        tip.style.left = mx + 'px'; tip.style.top = my + 'px'; tip.style.display = 'block';
    }

    /* ══════════ Rendu de la colonne droite ══════════ */

    // Bascule Location / Achat
    var modeEl = $('dyMode');
    if (modeEl) modeEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-mode]'); if (!b) return;
        sel.mode = b.dataset.mode;
        modeEl.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); });
        renderCats(); renderGars(); compute();
    });

    // Catégories (radios pleine largeur, barre colorée à gauche)
    function renderCats() {
        var el = $('dyCats'); if (!el) return;
        el.innerHTML = catList.map(function (c) {
            var prix = DynastyPricing.format(
                sel.mode === 'achat' ? c.prix_base_achat : c.prix_base_location, devise());
            return '<div class="dy-cat' + (c.id === sel.categorie_id ? ' active' : '') + '" data-cat="' + c.id + '" style="--_c:' + esc(c.couleur) + '">' +
                '<div class="dy-cat-body"><div class="dy-cat-name">' + esc(c.label) + '</div>' +
                '<div class="dy-cat-price">à partir de ' + prix + (sel.mode === 'location' ? ' / sem.' : '') + '</div></div>' +
                '<div class="dy-cat-check"><i class="fa-solid fa-check"></i></div></div>';
        }).join('');
    }
    function setCat(id) {
        sel.categorie_id = parseInt(id, 10) || 0;
        renderCats(); compute();
        // Met en évidence la zone correspondante sur la carte.
        if (engine) {
            var z = (DATA.zones || []).find(function (zz) { return zz.meta && zz.meta.categorie_id === sel.categorie_id; });
            engine.hoverZoneId = z ? z.id : null; engine.draw();
        }
    }
    if ($('dyCats')) $('dyCats').addEventListener('click', function (e) {
        var b = e.target.closest('[data-cat]'); if (b) setCat(b.dataset.cat);
    });

    // Options (toggles)
    function renderOpts() {
        var el = $('dyOpts'); if (!el) return;
        el.innerHTML = optList.map(function (o) {
            var val = sel.mode === 'achat' ? o.valeur_achat : o.valeur_location;
            var suffixe = o.type === 'multiplicateur'
                ? '×' + val
                : (val > 0 ? '+' + DynastyPricing.format(val, devise()) : 'incluse');
            return '<div class="dy-opt' + (sel.options.has(o.cle) ? ' on' : '') + '" data-opt="' + esc(o.cle) + '">' +
                '<div class="dy-opt-l">' + esc(o.label) + '<small>' + suffixe + '</small></div>' +
                '<div class="dy-switch"></div></div>';
        }).join('');
    }
    if ($('dyOpts')) $('dyOpts').addEventListener('click', function (e) {
        var b = e.target.closest('[data-opt]'); if (!b) return;
        var k = b.dataset.opt;
        sel.options.has(k) ? sel.options.delete(k) : sel.options.add(k);
        renderOpts(); compute();
    });

    // Garage (grille 3 colonnes)
    function renderGars() {
        var el = $('dyGars'); if (!el) return;
        el.innerHTML = garList.map(function (g) {
            var supp = sel.mode === 'achat' ? g.supplement_achat : g.supplement_location;
            var lbl = supp > 0 ? '+' + DynastyPricing.format(supp, devise()) : DynastyPricing.format(0, devise());
            return '<div class="dy-gar' + (g.places === sel.garage_places ? ' active' : '') + '" data-gar="' + g.places + '">' +
                '<div class="dy-gar-n">' + g.places + '<small>places</small></div>' +
                '<div class="dy-gar-s">' + lbl + '</div></div>';
        }).join('');
    }
    if ($('dyGars')) $('dyGars').addEventListener('click', function (e) {
        var b = e.target.closest('[data-gar]'); if (!b) return;
        sel.garage_places = parseInt(b.dataset.gar, 10) || 0;
        renderGars(); compute();
    });

    function devise() { return DynastyPricing.setting(G, 'devise', '$'); }

    /* ══════════ Calcul ══════════ */
    function compute() {
        var r = DynastyPricing.compute(G, {
            mode: sel.mode, categorie_id: sel.categorie_id,
            options: Array.from(sel.options), garage_places: sel.garage_places
        });
        var f = function (v) { return DynastyPricing.format(v, r.devise); };
        set('dyFBase', f(r.base)); set('dyFOpts', f(r.options)); set('dyFGar', f(r.garage));
        set('dyFST', f(r.sous_total)); set('dyFTaxe', f(r.taxe_montant)); set('dyFTotal', f(r.total));
        var pct = (r.taxe_taux * 100);
        set('dyFTaxeLbl', 'Taxe : ' + (pct % 1 === 0 ? pct.toFixed(0) : pct.toFixed(2)) + ' %');
    }
    function set(id, v) { var e = $(id); if (e) e.textContent = v; }

    /* ── Init ── */
    renderCats(); renderOpts(); renderGars(); compute();
})();
