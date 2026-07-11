/* =====================================================================
   DBColorPicker — color picker custom (HSV maison, PAS de <input type=color>)
   Palette de presets + couleurs récentes + favorites (localStorage) + preview live.

   API :
     DBColorPicker.open(anchorEl, currentHex, onChange[, onClose])
     DBColorPicker.close()

   Style « MDTConfirm » : IIFE, singleton global, sans dépendance.
   ===================================================================== */
(function (w, d) {
    'use strict';

    var LS_RECENT = 'mdt_recent_colors';
    var LS_FAV = 'mdt_fav_colors';
    var PRESETS = [
        '#7C3AED', '#A855F7', '#3B82F6', '#2563EB', '#06B6D4', '#10B981',
        '#22C55E', '#EAB308', '#F59E0B', '#F97316', '#EF4444', '#F43F5E',
        '#EC4899', '#F472B6', '#64748B', '#94A3B8', '#FFFFFF', '#0F172A'
    ];

    // ── utils localStorage ──
    function load(key) {
        try { return JSON.parse(w.localStorage.getItem(key) || '[]'); } catch (e) { return []; }
    }
    function save(key, arr) {
        try { w.localStorage.setItem(key, JSON.stringify(arr.slice(0, 12))); } catch (e) {}
    }
    function pushRecent(hex) {
        var r = load(LS_RECENT).filter(function (c) { return c.toUpperCase() !== hex.toUpperCase(); });
        r.unshift(hex.toUpperCase());
        save(LS_RECENT, r);
    }
    function toggleFav(hex) {
        var f = load(LS_FAV);
        var i = f.map(function (c) { return c.toUpperCase(); }).indexOf(hex.toUpperCase());
        if (i >= 0) { f.splice(i, 1); } else { f.unshift(hex.toUpperCase()); }
        save(LS_FAV, f);
        return f;
    }

    // ── conversions ──
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function hexToRgb(hex) {
        var m = /^#?([0-9a-f]{6})$/i.exec((hex || '').trim());
        if (!m) return null;
        var n = parseInt(m[1], 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }
    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(function (x) {
            return ('0' + clamp(Math.round(x), 0, 255).toString(16)).slice(-2);
        }).join('').toUpperCase();
    }
    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), dl = max - min;
        var h = 0;
        if (dl) {
            if (max === r) h = ((g - b) / dl) % 6;
            else if (max === g) h = (b - r) / dl + 2;
            else h = (r - g) / dl + 4;
            h *= 60; if (h < 0) h += 360;
        }
        return { h: h, s: max ? dl / max : 0, v: max };
    }
    function hsvToRgb(h, s, v) {
        var c = v * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = v - c, r = 0, g = 0, b = 0;
        if (h < 60) { r = c; g = x; }
        else if (h < 120) { r = x; g = c; }
        else if (h < 180) { g = c; b = x; }
        else if (h < 240) { g = x; b = c; }
        else if (h < 300) { r = x; b = c; }
        else { r = c; b = x; }
        return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
    }

    var state = { h: 0, s: 1, v: 1 };
    var el = null, cb = null, closeCb = null;
    var refs = {};

    function curHex() {
        var rgb = hsvToRgb(state.h, state.s, state.v);
        return rgbToHex(rgb.r, rgb.g, rgb.b);
    }

    function build() {
        el = d.createElement('div');
        el.className = 'db-colorpicker';
        el.innerHTML =
            '<div class="db-cp-sv"><div class="db-cp-sv-white"></div><div class="db-cp-sv-black"></div><div class="db-cp-sv-cursor"></div></div>' +
            '<div class="db-cp-hue"><div class="db-cp-hue-cursor"></div></div>' +
            '<div class="db-cp-row"><div class="db-cp-preview"></div><input class="db-cp-hexinput" maxlength="7" spellcheck="false"></div>' +
            '<div class="db-cp-section-label">Presets</div><div class="db-cp-swatches" data-zone="presets"></div>' +
            '<div class="db-cp-section-label">Favorites</div><div class="db-cp-swatches" data-zone="fav"></div>' +
            '<div class="db-cp-section-label">Récentes</div><div class="db-cp-swatches" data-zone="recent"></div>';
        d.body.appendChild(el);

        refs.sv = el.querySelector('.db-cp-sv');
        refs.svCursor = el.querySelector('.db-cp-sv-cursor');
        refs.hue = el.querySelector('.db-cp-hue');
        refs.hueCursor = el.querySelector('.db-cp-hue-cursor');
        refs.preview = el.querySelector('.db-cp-preview');
        refs.hex = el.querySelector('.db-cp-hexinput');

        bindDrag(refs.sv, function (px, py, rect) {
            state.s = clamp(px / rect.width, 0, 1);
            state.v = clamp(1 - py / rect.height, 0, 1);
            emit();
        });
        bindDrag(refs.hue, function (px, _py, rect) {
            state.h = clamp(px / rect.width, 0, 1) * 360;
            emit();
        });

        refs.hex.addEventListener('input', function () {
            var rgb = hexToRgb(refs.hex.value);
            if (rgb) { setFromHex(rgbToHex(rgb.r, rgb.g, rgb.b)); emit(false); }
        });
        refs.hex.addEventListener('keydown', function (e) { if (e.key === 'Enter') { commit(); } });

        el.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        el.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    function bindDrag(area, onMove) {
        function run(e) {
            var rect = area.getBoundingClientRect();
            var cx = (e.touches ? e.touches[0].clientX : e.clientX);
            var cy = (e.touches ? e.touches[0].clientY : e.clientY);
            onMove(clamp(cx - rect.left, 0, rect.width), clamp(cy - rect.top, 0, rect.height), rect);
        }
        function down(e) {
            e.preventDefault();
            run(e);
            function mv(ev) { run(ev); }
            function up() {
                d.removeEventListener('mousemove', mv); d.removeEventListener('mouseup', up);
                d.removeEventListener('touchmove', mv); d.removeEventListener('touchend', up);
                commit();
            }
            d.addEventListener('mousemove', mv); d.addEventListener('mouseup', up);
            d.addEventListener('touchmove', mv, { passive: false }); d.addEventListener('touchend', up);
        }
        area.addEventListener('mousedown', down);
        area.addEventListener('touchstart', down, { passive: false });
    }

    function setFromHex(hex) {
        var rgb = hexToRgb(hex); if (!rgb) return;
        var hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);
        state.h = hsv.h; state.s = hsv.s; state.v = hsv.v;
    }

    function renderSwatches(zone, colors, fav) {
        var box = el.querySelector('.db-cp-swatches[data-zone="' + zone + '"]');
        box.innerHTML = '';
        colors.forEach(function (c) {
            var s = d.createElement('span');
            s.style.background = c; s.title = c;
            s.addEventListener('click', function () { setFromHex(c); emit(); commit(); });
            box.appendChild(s);
        });
        if (zone === 'presets') {
            var fb = d.createElement('button');
            fb.type = 'button'; fb.className = 'db-cp-fav-btn'; fb.title = 'Ajouter la couleur courante aux favorites';
            fb.innerHTML = '<i class="fa-solid fa-star"></i>';
            fb.addEventListener('click', function () { var f = toggleFav(curHex()); renderSwatches('fav', f, true); });
            box.appendChild(fb);
        }
    }

    function emit(notify) {
        var hex = curHex();
        refs.preview.style.background = hex;
        if (d.activeElement !== refs.hex) refs.hex.value = hex;
        // SV bg = hue pure
        var pure = hsvToRgb(state.h, 1, 1);
        refs.sv.style.background = rgbToHex(pure.r, pure.g, pure.b);
        refs.svCursor.style.left = (state.s * 100) + '%';
        refs.svCursor.style.top = ((1 - state.v) * 100) + '%';
        refs.hueCursor.style.left = (state.h / 360 * 100) + '%';
        if (notify !== false && typeof cb === 'function') cb(hex);
    }

    function commit() {
        var hex = curHex();
        pushRecent(hex);
        renderSwatches('recent', load(LS_RECENT));
        if (typeof cb === 'function') cb(hex);
    }

    function position(anchor) {
        var r = anchor.getBoundingClientRect();
        var top = r.bottom + w.scrollY + 8;
        var left = r.left + w.scrollX;
        if (left + 240 > w.innerWidth) left = w.innerWidth - 252;
        el.style.top = top + 'px';
        el.style.left = Math.max(8, left) + 'px';
    }

    function onDocDown() { api.close(); }

    var api = {
        open: function (anchor, hex, onChange, onClose) {
            if (!el) build();
            cb = onChange; closeCb = onClose || null;
            setFromHex(hexToRgb(hex) ? hex : '#7C3AED');
            renderSwatches('presets', PRESETS);
            renderSwatches('fav', load(LS_FAV), true);
            renderSwatches('recent', load(LS_RECENT));
            emit(false);
            position(anchor);
            el.classList.add('db-open');
            setTimeout(function () { d.addEventListener('mousedown', onDocDown); }, 0);
        },
        close: function () {
            if (!el) return;
            el.classList.remove('db-open');
            d.removeEventListener('mousedown', onDocDown);
            if (typeof closeCb === 'function') closeCb(curHex());
            cb = null; closeCb = null;
        }
    };

    w.DBColorPicker = api;
})(window, document);
