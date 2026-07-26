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
        // Remplissage coloré de la piste jusqu'au pouce.
        var pct = fPrix.max > 0 ? (parseFloat(fPrix.value) / parseFloat(fPrix.max)) * 100 : 100;
        fPrix.style.setProperty('--fill', pct + '%');

        var vus = BIENS.filter(function (b) {
            // Un bien correspond au filtre quartier si l'un de ses quartiers colle.
            var cats = Array.isArray(b.cats) ? b.cats : (b.cat ? [b.cat] : []);
            if (fCat.value !== '' && cats.indexOf(parseInt(fCat.value, 10)) < 0) return false;
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

    /* Média du bien : carrousel si images, sinon vignette d'icône. */
    function mediaHtml(b) {
        var icon = ICONS[b.type] || 'fa-building';
        var imgs = Array.isArray(b.images) ? b.images : [];
        if (!imgs.length) {
            return '<div class="dy-good-media placeholder"><i class="fas ' + icon + '"></i></div>';
        }
        var slides = imgs.map(function (u, i) {
            return '<div class="dy-good-slide"><img src="' + esc(u) + '" alt="" loading="lazy" referrerpolicy="no-referrer" data-full="' + esc(u) + '"' +
                ' onerror="this.src=\'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22250%22%3E%3C/svg%3E\'"></div>';
        }).join('');
        var dots = imgs.length > 1 ? '<div class="dy-good-dots">' + imgs.map(function (_, i) {
            return '<button type="button" data-dot="' + i + '"' + (i === 0 ? ' class="active"' : '') + '></button>';
        }).join('') + '</div>' : '';
        var nav = imgs.length > 1 ?
            '<button type="button" class="dy-good-nav prev" data-nav="-1" disabled><i class="fas fa-chevron-left"></i></button>' +
            '<button type="button" class="dy-good-nav next" data-nav="1"><i class="fas fa-chevron-right"></i></button>' +
            '<span class="dy-good-count"><i class="fas fa-images"></i> ' + imgs.length + '</span>' : '';
        return '<div class="dy-good-media" data-idx="0" data-n="' + imgs.length + '">' +
            '<div class="dy-good-track">' + slides + '</div>' + nav + dots + '</div>';
    }

    function carte(b) {
        var typeLbl = TYPES[b.type] || b.type;
        var icon = ICONS[b.type] || 'fa-building';

        // Quartiers du bien → tags colorés (plusieurs possibles).
        var cats = Array.isArray(b.cats) ? b.cats : (b.cat ? [b.cat] : []);
        var quartierTags = cats.map(function (id) { return CAT[id]; }).filter(Boolean);
        var quartiersHtml = quartierTags.length
            ? '<div class="dy-good-cats">' + quartierTags.map(function (c) {
                  return '<span class="dy-good-cat"><i style="background:' + esc(c.couleur) + '"></i>' + esc(c.label) + '</span>';
              }).join('') + '</div>'
            : '';

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
            mediaHtml(b) +
            '<div class="dy-good-top">' +
                '<span class="dy-good-type"><i class="fa-solid ' + icon + '"></i> ' + esc(typeLbl) + '</span>' +
                '<span class="dy-good-ref">' + esc(b.ref) + '</span>' +
            '</div>' +
            '<div class="dy-good-body">' +
                '<div class="dy-good-title">' + esc(b.titre || 'Bien immobilier') + '</div>' +
                (b.quartier || b.adresse ?
                    '<div class="dy-good-addr"><i class="fa-solid fa-location-dot"></i> ' +
                    esc([b.quartier, b.adresse].filter(Boolean).join(', ')) + '</div>' : '') +
                quartiersHtml +
                (b.desc ? '<div class="dy-good-desc">' + esc(b.desc) + '</div>' : '') +
                (specs.length ? '<div class="dy-good-specs">' + specs.map(function (s) { return '<span>' + s + '</span>'; }).join('') + '</div>' : '') +
            '</div>' +
            '<div class="dy-good-foot">' +
                '<div class="dy-good-prices">' + prixHtml + '</div>' +
                '<div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">' + tags + '</div>' +
            '</div>' +
        '</article>';
    }

    /* ══════════ Carrousels (délégation sur la grille) ══════════ */
    function slideTo(media, idx) {
        var n = parseInt(media.dataset.n, 10) || 1;
        idx = Math.max(0, Math.min(n - 1, idx));
        media.dataset.idx = idx;
        media.querySelector('.dy-good-track').style.transform = 'translateX(' + (-idx * 100) + '%)';
        var prev = media.querySelector('.dy-good-nav.prev'), next = media.querySelector('.dy-good-nav.next');
        if (prev) prev.disabled = (idx === 0);
        if (next) next.disabled = (idx === n - 1);
        media.querySelectorAll('.dy-good-dots button').forEach(function (d, i) { d.classList.toggle('active', i === idx); });
    }

    var goods = $('dyGoods');
    goods.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-nav]');
        if (nav) {
            var m = nav.closest('.dy-good-media');
            slideTo(m, (parseInt(m.dataset.idx, 10) || 0) + parseInt(nav.dataset.nav, 10));
            return;
        }
        var dot = e.target.closest('[data-dot]');
        if (dot) { slideTo(dot.closest('.dy-good-media'), parseInt(dot.dataset.dot, 10)); return; }
        var img = e.target.closest('.dy-good-slide img');
        if (img) {
            var media = img.closest('.dy-good-media');
            var urls = [...media.querySelectorAll('.dy-good-slide img')].map(function (im) { return im.dataset.full; });
            openLightbox(urls, parseInt(media.dataset.idx, 10) || 0);
        }
    });

    /* ══════════ Lightbox ══════════ */
    var lb = null, lbUrls = [], lbIdx = 0;
    function ensureLightbox() {
        if (lb) return;
        lb = document.createElement('div');
        lb.className = 'dy-lightbox';
        lb.innerHTML =
            '<button type="button" class="dy-lightbox-close" data-lb="close"><i class="fas fa-times"></i></button>' +
            '<button type="button" class="dy-lightbox-nav prev" data-lb="-1"><i class="fas fa-chevron-left"></i></button>' +
            '<img alt="">' +
            '<button type="button" class="dy-lightbox-nav next" data-lb="1"><i class="fas fa-chevron-right"></i></button>' +
            '<div class="dy-lightbox-count"></div>';
        document.body.appendChild(lb);
        lb.addEventListener('click', function (e) {
            var b = e.target.closest('[data-lb]');
            if (e.target === lb || (b && b.dataset.lb === 'close')) { closeLightbox(); return; }
            if (b) lbShow(lbIdx + parseInt(b.dataset.lb, 10));
        });
        document.addEventListener('keydown', function (e) {
            if (!lb.classList.contains('open')) return;
            if (e.key === 'Escape') closeLightbox();
            else if (e.key === 'ArrowLeft') lbShow(lbIdx - 1);
            else if (e.key === 'ArrowRight') lbShow(lbIdx + 1);
        });
    }
    function lbShow(i) {
        lbIdx = (i + lbUrls.length) % lbUrls.length;
        lb.querySelector('img').src = lbUrls[lbIdx];
        var nav = lb.querySelectorAll('.dy-lightbox-nav');
        nav.forEach(function (n) { n.style.display = lbUrls.length > 1 ? '' : 'none'; });
        lb.querySelector('.dy-lightbox-count').textContent = lbUrls.length > 1 ? (lbIdx + 1) + ' / ' + lbUrls.length : '';
    }
    function openLightbox(urls, i) {
        if (!urls || !urls.length) return;
        ensureLightbox(); lbUrls = urls; lbShow(i || 0);
        lb.classList.add('open'); document.body.style.overflow = 'hidden';
    }
    function closeLightbox() { if (lb) { lb.classList.remove('open'); document.body.style.overflow = ''; } }

    [fCat, fType, fTrans].forEach(function (el) { if (el) el.addEventListener('change', render); });
    if (fPrix) fPrix.addEventListener('input', render);
    render();
})();
