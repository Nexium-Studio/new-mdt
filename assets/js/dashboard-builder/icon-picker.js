/* =====================================================================
   DBIconPicker — sélecteur d'icône avancé (popover).
   Onglet « Icône » : recherche instantanée dans une bibliothèque FA + aperçu.
   Onglet « Image »  : coller une URL d'image (aperçu live).

   API :
     DBIconPicker.open(anchorEl, { mode:'fa'|'url', value }, onChange({mode,value}))
     DBIconPicker.close()
   ===================================================================== */
(function (w, d) {
    'use strict';

    var ICONS = (
        'house home building building-columns landmark city store shop warehouse industry hotel ' +
        'shield shield-halved shield-heart user-shield gun handcuffs gavel scale-balanced fingerprint ' +
        'kit-medical staff-snake heart heart-pulse hospital truck-medical pills prescription syringe stethoscope bone brain ' +
        'car car-side car-rear truck truck-fast truck-ramp-box motorcycle taxi bus van-shuttle gas-pump screwdriver-wrench wrench gears toolbox ' +
        'utensils burger pizza-slice mug-hot martini-glass-citrus wine-bottle bottle-water ice-cream cake-candles ' +
        'bag-shopping basket-shopping cart-shopping gem ring crown gift box boxes-stacked tags tag receipt ' +
        'music compact-disc record-vinyl microphone headphones guitar film clapperboard camera newspaper bullhorn ' +
        'briefcase folder file file-lines file-invoice clipboard clipboard-list book book-open graduation-cap ' +
        'users user user-tie user-plus user-group people-group id-card id-badge address-book phone envelope ' +
        'money-bill money-bill-wave coins sack-dollar credit-card wallet vault landmark-dome chart-line chart-bar chart-pie ' +
        'gear sliders wrench screwdriver bolt plug wifi network-wired server database microchip robot ' +
        'star flag fire fire-flame-curved bell crosshairs location-dot map map-location-dot route compass globe ' +
        'plane helicopter ship sailboat anchor water tree leaf paw dog cat fish dragon ' +
        'palette brush wand-magic-sparkles paintbrush scissors hammer trowel-bricks helmet-safety hard-hat ' +
        'dice trophy medal award certificate ticket dumbbell futbol basketball ' +
        'lock unlock key skull-crossbones biohazard radiation bomb explosion'
    ).split(/\s+/).filter(Boolean);

    var el = null, refs = {}, cb = null, state = { mode: 'fa', value: 'store' };

    function build() {
        el = d.createElement('div');
        el.className = 'db-iconpicker';
        el.innerHTML =
            '<div class="db-ip-tabs">' +
              '<button type="button" class="db-ip-tab db-active" data-tab="fa"><i class="fa-solid fa-icons"></i> Icône</button>' +
              '<button type="button" class="db-ip-tab" data-tab="url"><i class="fa-solid fa-image"></i> Image URL</button>' +
            '</div>' +
            '<div class="db-ip-pane" data-pane="fa">' +
              '<div class="db-ip-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="Rechercher une icône…" spellcheck="false"></div>' +
              '<div class="db-ip-grid"></div>' +
            '</div>' +
            '<div class="db-ip-pane" data-pane="url" style="display:none">' +
              '<div class="db-ip-urlrow"><div class="db-ip-urlprev"></div>' +
              '<input type="text" class="db-ip-urlinput" placeholder="https://… (png, jpg, svg)" spellcheck="false"></div>' +
              '<div class="db-ip-urlhint">Colle l\'URL d\'une image (logo). Elle remplacera l\'icône.</div>' +
            '</div>';
        d.body.appendChild(el);

        refs.search = el.querySelector('.db-ip-search input');
        refs.grid = el.querySelector('.db-ip-grid');
        refs.urlInput = el.querySelector('.db-ip-urlinput');
        refs.urlPrev = el.querySelector('.db-ip-urlprev');

        el.querySelectorAll('.db-ip-tab').forEach(function (t) {
            t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
        });
        refs.search.addEventListener('input', function () { renderGrid(refs.search.value); });
        refs.urlInput.addEventListener('input', function () {
            var v = refs.urlInput.value.trim();
            refs.urlPrev.innerHTML = /^https?:\/\//i.test(v) ? '<img src="' + v.replace(/"/g, '') + '" alt="">' : '';
            if (/^https?:\/\//i.test(v)) { state.mode = 'url'; state.value = v; emit(); }
        });
        el.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        el.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    function switchTab(tab) {
        el.querySelectorAll('.db-ip-tab').forEach(function (t) { t.classList.toggle('db-active', t.getAttribute('data-tab') === tab); });
        el.querySelectorAll('.db-ip-pane').forEach(function (p) { p.style.display = p.getAttribute('data-pane') === tab ? '' : 'none'; });
    }

    function renderGrid(q) {
        q = (q || '').toLowerCase().trim();
        refs.grid.innerHTML = '';
        var list = q ? ICONS.filter(function (n) { return n.indexOf(q) >= 0; }) : ICONS;
        list.slice(0, 120).forEach(function (n) {
            var b = d.createElement('button');
            b.type = 'button';
            b.className = 'db-ip-ico' + (state.mode === 'fa' && state.value === n ? ' db-active' : '');
            b.title = n;
            b.innerHTML = '<i class="fa-solid fa-' + n + '"></i>';
            b.addEventListener('click', function () { state.mode = 'fa'; state.value = n; renderGrid(q); emit(); });
            refs.grid.appendChild(b);
        });
        if (!list.length) { refs.grid.innerHTML = '<div class="db-ip-empty">Aucune icône</div>'; }
    }

    function emit() { if (typeof cb === 'function') cb({ mode: state.mode, value: state.value }); }

    function position(anchor) {
        var r = anchor.getBoundingClientRect();
        var top = r.bottom + w.scrollY + 8, left = r.left + w.scrollX;
        if (left + 300 > w.innerWidth) left = w.innerWidth - 312;
        el.style.top = top + 'px'; el.style.left = Math.max(8, left) + 'px';
    }

    function onDoc() { api.close(); }

    var api = {
        open: function (anchor, cur, onChange) {
            if (!el) build();
            cb = onChange;
            state.mode = (cur && cur.mode === 'url') ? 'url' : 'fa';
            state.value = (cur && cur.value) ? cur.value : 'store';
            switchTab(state.mode);
            refs.search.value = '';
            renderGrid('');
            refs.urlInput.value = state.mode === 'url' ? state.value : '';
            refs.urlPrev.innerHTML = (state.mode === 'url' && /^https?:\/\//i.test(state.value)) ? '<img src="' + state.value.replace(/"/g, '') + '" alt="">' : '';
            position(anchor);
            el.classList.add('db-open');
            setTimeout(function () { d.addEventListener('mousedown', onDoc); }, 0);
        },
        close: function () {
            if (!el) return;
            el.classList.remove('db-open');
            d.removeEventListener('mousedown', onDoc);
            cb = null;
        }
    };

    w.DBIconPicker = api;
})(window, document);
