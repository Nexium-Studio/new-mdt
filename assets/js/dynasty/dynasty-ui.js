/**
 * ══════════════════════════════════════════════════════════════════════════
 *  DynastyUI — composants de formulaire personnalisés (module Dynasty)
 *
 *  Le style ouvert d'un <select> natif (la liste d'options) et le popup d'un
 *  <input type="color"> sont rendus par le système et ne peuvent PAS être mis
 *  en forme en CSS. Ce module les remplace par des composants entièrement
 *  thémés, tout en gardant l'élément natif synchronisé (valeur + événement
 *  'change'/'input' + soumission de formulaire inchangés).
 *
 *  API :
 *    DynastyUI.enhance(root)         → selects + color pickers sous `root`
 *    DynastyUI.enhanceSelects(root)
 *    DynastyUI.enhanceColors(root)
 *    DynastyUI.refresh(el)           → resynchronise un contrôle déjà amélioré
 *
 *  La CSS est auto-injectée avec des chaînes de repli var(--x, var(--y, …))
 *  pour fonctionner à la fois dans le panel (--surface/--tx) et sur le site
 *  public (--panel/--tx, thème clair ou sombre).
 * ══════════════════════════════════════════════════════════════════════════
 */
(function (global) {
    'use strict';

    /* ── CSS injectée une seule fois ── */
    function injectCss() {
        if (document.getElementById('dui-styles')) return;
        var css = `
        .dui-hidden-native { position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important; overflow:hidden!important; clip:rect(0 0 0 0)!important; border:0!important; }
        .dui-select { position:relative; display:block; }
        .dui-btn {
            display:flex; align-items:center; justify-content:space-between; gap:.5rem; width:100%;
            background: var(--surface-2, var(--panel-2, #182030));
            border: 1px solid var(--bd-2, rgba(148,163,184,.18));
            color: var(--tx, #f1f5f9);
            border-radius: var(--r-md, 10px);
            padding: .55rem .75rem; font: 600 .82rem/1.2 'Inter', system-ui, sans-serif;
            cursor: pointer; text-align:left; transition: border-color .15s, box-shadow .15s;
        }
        .dui-btn:hover { border-color: var(--bd-h, rgba(148,163,184,.32)); }
        .dui-btn:focus-visible, .dui-btn.open { outline:0; border-color: var(--dy, #10b981);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--dy, #10b981) 22%, transparent); }
        .dui-btn .dui-lbl { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .dui-btn .dui-cur-color { width:12px; height:12px; border-radius:3px; margin-right:.15rem; flex-shrink:0; display:inline-block; vertical-align:-1px; }
        .dui-btn .dui-caret { flex-shrink:0; color: var(--tx-3, #94a3b8); transition: transform .18s; font-size:.7rem; }
        .dui-btn.open .dui-caret { transform: rotate(180deg); }

        .dui-menu {
            position: fixed; z-index: 100050; min-width: 160px; max-height: 280px; overflow-y: auto;
            background: var(--surface, var(--panel, #131922));
            border: 1px solid var(--bd-2, rgba(148,163,184,.2));
            border-radius: var(--r-md, 10px);
            box-shadow: 0 16px 44px rgba(0,0,0,.42);
            padding: .3rem; opacity:0; transform: translateY(-6px); pointer-events:none;
            transition: opacity .14s ease, transform .14s ease;
        }
        .dui-menu.open { opacity:1; transform:none; pointer-events:auto; }
        .dui-opt {
            display:flex; align-items:center; gap:.55rem; padding:.5rem .6rem; border-radius: var(--r-sm, 8px);
            font: 500 .82rem/1.2 'Inter', system-ui, sans-serif; color: var(--tx-2, #cbd5e1); cursor:pointer;
            transition: background .12s, color .12s;
        }
        .dui-opt:hover, .dui-opt.active { background: var(--surface-2, var(--panel-2, rgba(148,163,184,.10))); color: var(--tx, #f1f5f9); }
        .dui-opt.selected { color: var(--dy, #10b981); font-weight:700; }
        .dui-opt .dui-check { margin-left:auto; color: var(--dy, #10b981); font-size:.72rem; opacity:0; }
        .dui-opt.selected .dui-check { opacity:1; }
        .dui-opt .dui-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }

        /* Sélecteur de couleur */
        .dui-color { position:relative; display:inline-flex; }
        .dui-color-swatch {
            width:34px; height:28px; border-radius:7px; cursor:pointer; padding:0;
            border: 1px solid var(--bd-2, rgba(148,163,184,.28));
            box-shadow: inset 0 0 0 2px var(--surface, var(--panel, #131922));
            transition: transform .1s, border-color .15s;
        }
        .dui-color-swatch:hover { border-color: var(--dy, #10b981); }
        .dui-color-swatch:active { transform: scale(.94); }
        .dui-pop {
            position: fixed; z-index: 100050; width: 208px; padding:.7rem;
            background: var(--surface, var(--panel, #131922));
            border: 1px solid var(--bd-2, rgba(148,163,184,.2));
            border-radius: var(--r-md, 10px); box-shadow: 0 16px 44px rgba(0,0,0,.42);
            opacity:0; transform: translateY(-6px); pointer-events:none; transition: opacity .14s, transform .14s;
        }
        .dui-pop.open { opacity:1; transform:none; pointer-events:auto; }
        .dui-pop-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:.35rem; margin-bottom:.6rem; }
        .dui-pop-sw { aspect-ratio:1/1; border-radius:6px; cursor:pointer; border:2px solid transparent; transition:transform .1s, border-color .1s; }
        .dui-pop-sw:hover { transform: scale(1.1); }
        .dui-pop-sw.on { border-color: var(--tx, #fff); }
        .dui-pop-row { display:flex; gap:.4rem; align-items:center; }
        .dui-pop-hex {
            flex:1; min-width:0; background: var(--bg-2, var(--panel-2, #0f141b));
            border:1px solid var(--bd-2, rgba(148,163,184,.2)); color: var(--tx, #f1f5f9);
            border-radius: var(--r-sm, 8px); padding:.4rem .55rem; font: 600 .78rem 'JetBrains Mono', monospace; text-transform:uppercase;
        }
        .dui-pop-hex:focus { outline:0; border-color: var(--dy, #10b981); }
        .dui-pop-native { width:32px; height:32px; border:1px solid var(--bd-2, rgba(148,163,184,.2)); border-radius: var(--r-sm,8px); overflow:hidden; padding:0; cursor:pointer; background:none; }
        .dui-pop-native::-webkit-color-swatch-wrapper { padding:2px; }
        .dui-pop-native::-webkit-color-swatch { border:none; border-radius:5px; }
        `;
        var s = document.createElement('style');
        s.id = 'dui-styles';
        s.textContent = css;
        document.head.appendChild(s);
    }

    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); };

    /* ══════════════════════ SELECT ══════════════════════ */

    var openMenu = null; // { menu, sel, btn, cleanup }

    function closeMenu() {
        if (!openMenu) return;
        var o = openMenu; openMenu = null;
        o.btn.classList.remove('open');
        o.menu.classList.remove('open');
        setTimeout(function () { if (o.menu.parentNode) o.menu.parentNode.removeChild(o.menu); }, 160);
        document.removeEventListener('mousedown', o.outside, true);
        window.removeEventListener('scroll', o.reposition, true);
        window.removeEventListener('resize', o.reposition);
    }

    function labelOf(sel) {
        var opt = sel.options[sel.selectedIndex];
        return opt ? opt.textContent : '';
    }

    function enhanceSelect(sel) {
        if (sel.dataset.dui || sel.multiple) return;
        sel.dataset.dui = '1';
        injectCss();

        var wrap = document.createElement('div');
        wrap.className = 'dui-select';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.classList.add('dui-hidden-native');
        sel.setAttribute('tabindex', '-1');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dui-btn';
        btn.innerHTML = '<span class="dui-lbl"></span><i class="fas fa-chevron-down dui-caret"></i>';
        wrap.appendChild(btn);

        var lbl = btn.querySelector('.dui-lbl');
        function syncLabel() { lbl.textContent = labelOf(sel); }
        syncLabel();

        btn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (openMenu && openMenu.sel === sel) { closeMenu(); return; }
            openSelectMenu(sel, btn, syncLabel);
        });

        // Re-sync si la valeur change par programme.
        sel.addEventListener('change', syncLabel);
        sel._duiSync = syncLabel;
    }

    function openSelectMenu(sel, btn, syncLabel) {
        closeMenu();
        var menu = document.createElement('div');
        menu.className = 'dui-menu';

        Array.prototype.forEach.call(sel.options, function (opt, i) {
            var it = document.createElement('div');
            it.className = 'dui-opt' + (i === sel.selectedIndex ? ' selected' : '');
            var dot = opt.getAttribute('data-couleur') || opt.getAttribute('data-color');
            it.innerHTML = (dot ? '<span class="dui-dot" style="background:' + esc(dot) + '"></span>' : '') +
                '<span>' + esc(opt.textContent) + '</span><i class="fas fa-check dui-check"></i>';
            it.addEventListener('click', function () {
                if (sel.selectedIndex !== i) {
                    sel.selectedIndex = i;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    sel.dispatchEvent(new Event('input', { bubbles: true }));
                }
                syncLabel();
                closeMenu();
            });
            menu.appendChild(it);
        });

        document.body.appendChild(menu);
        btn.classList.add('open');

        function reposition() {
            var r = btn.getBoundingClientRect();
            menu.style.minWidth = r.width + 'px';
            menu.style.left = r.left + 'px';
            var below = window.innerHeight - r.bottom;
            var mh = Math.min(menu.scrollHeight, 280);
            if (below < mh + 8 && r.top > below) {
                menu.style.top = (r.top - mh - 6) + 'px';
            } else {
                menu.style.top = (r.bottom + 6) + 'px';
            }
        }
        reposition();
        requestAnimationFrame(function () { menu.classList.add('open'); });

        var outside = function (e) { if (!menu.contains(e.target) && e.target !== btn && !btn.contains(e.target)) closeMenu(); };
        document.addEventListener('mousedown', outside, true);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        openMenu = { menu: menu, sel: sel, btn: btn, outside: outside, reposition: reposition };
    }

    /* ══════════════════════ COLOR ══════════════════════ */

    var PRESETS = ['#22C55E', '#10B981', '#3B82F6', '#6366F1', '#8B5CF6', '#EC4899',
                   '#EF4444', '#F97316', '#EAB308', '#84CC16', '#06B6D4', '#94A3B8'];
    var openPop = null;

    function closePop() {
        if (!openPop) return;
        var o = openPop; openPop = null;
        o.pop.classList.remove('open');
        setTimeout(function () { if (o.pop.parentNode) o.pop.parentNode.removeChild(o.pop); }, 160);
        document.removeEventListener('mousedown', o.outside, true);
        window.removeEventListener('scroll', o.reposition, true);
        window.removeEventListener('resize', o.reposition);
    }

    function normHex(v) {
        v = String(v || '').trim();
        if (/^#?[0-9a-fA-F]{6}$/.test(v)) return (v[0] === '#' ? v : '#' + v).toUpperCase();
        if (/^#?[0-9a-fA-F]{3}$/.test(v)) {
            var h = v.replace('#', '');
            return ('#' + h[0]+h[0]+h[1]+h[1]+h[2]+h[2]).toUpperCase();
        }
        return null;
    }

    function enhanceColor(inp) {
        if (inp.dataset.dui) return;
        inp.dataset.dui = '1';
        injectCss();

        var wrap = document.createElement('div');
        wrap.className = 'dui-color';
        inp.parentNode.insertBefore(wrap, inp);
        wrap.appendChild(inp);
        inp.classList.add('dui-hidden-native');
        inp.setAttribute('tabindex', '-1');

        var sw = document.createElement('button');
        sw.type = 'button';
        sw.className = 'dui-color-swatch';
        sw.style.background = inp.value || '#000000';
        wrap.appendChild(sw);

        function sync() { sw.style.background = inp.value; }
        inp.addEventListener('input', sync);
        inp.addEventListener('change', sync);
        inp._duiSync = sync;

        sw.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (openPop && openPop.inp === inp) { closePop(); return; }
            openColorPop(inp, sw);
        });
    }

    function openColorPop(inp, sw) {
        closePop();
        var pop = document.createElement('div');
        pop.className = 'dui-pop';
        pop.innerHTML =
            '<div class="dui-pop-grid">' +
                PRESETS.map(function (c) {
                    return '<button type="button" class="dui-pop-sw" data-c="' + c + '" style="background:' + c + '"></button>';
                }).join('') +
            '</div>' +
            '<div class="dui-pop-row">' +
                '<input type="text" class="dui-pop-hex" maxlength="7" value="' + esc(inp.value || '') + '">' +
                '<input type="color" class="dui-pop-native" value="' + esc(inp.value || '#000000') + '">' +
            '</div>';
        document.body.appendChild(pop);

        function setVal(v) {
            var h = normHex(v);
            if (!h) return;
            inp.value = h;
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            inp.dispatchEvent(new Event('change', { bubbles: true }));
            sw.style.background = h;
            pop.querySelectorAll('.dui-pop-sw').forEach(function (b) { b.classList.toggle('on', b.dataset.c.toUpperCase() === h); });
            hex.value = h;
        }

        pop.querySelectorAll('.dui-pop-sw').forEach(function (b) {
            b.classList.toggle('on', (b.dataset.c.toUpperCase() === String(inp.value).toUpperCase()));
            b.addEventListener('click', function () { setVal(b.dataset.c); });
        });
        var hex = pop.querySelector('.dui-pop-hex');
        hex.addEventListener('input', function () { var h = normHex(hex.value); if (h) setVal(h); });
        var nat = pop.querySelector('.dui-pop-native');
        nat.addEventListener('input', function () { setVal(nat.value); });

        function reposition() {
            var r = sw.getBoundingClientRect();
            pop.style.left = Math.min(r.left, window.innerWidth - 220) + 'px';
            var below = window.innerHeight - r.bottom;
            if (below < pop.offsetHeight + 8 && r.top > below) pop.style.top = (r.top - pop.offsetHeight - 6) + 'px';
            else pop.style.top = (r.bottom + 6) + 'px';
        }
        reposition();
        requestAnimationFrame(function () { pop.classList.add('open'); });

        var outside = function (e) { if (!pop.contains(e.target) && e.target !== sw) closePop(); };
        document.addEventListener('mousedown', outside, true);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        openPop = { pop: pop, inp: inp, sw: sw, outside: outside, reposition: reposition };
    }

    /* ══════════════════════ API ══════════════════════ */

    function enhanceSelects(root) {
        (root || document).querySelectorAll('select:not([data-dui])').forEach(function (s) {
            if (!s.hasAttribute('data-no-dui')) enhanceSelect(s);
        });
    }
    function enhanceColors(root) {
        (root || document).querySelectorAll('input[type="color"]:not([data-dui])').forEach(function (c) {
            if (!c.hasAttribute('data-no-dui')) enhanceColor(c);
        });
    }
    function enhance(root) { injectCss(); enhanceSelects(root); enhanceColors(root); }
    function refresh(el) { if (el && el._duiSync) el._duiSync(); }

    global.DynastyUI = {
        enhance: enhance,
        enhanceSelects: enhanceSelects,
        enhanceColors: enhanceColors,
        refresh: refresh
    };
})(window);
