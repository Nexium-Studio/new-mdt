/* =====================================================================
   DBFactionPreview — « Version Joueurs ».
   Sélecteur de faction custom (recherche + cartes couleur/icône) puis rendu
   FIDÈLE du dashboard tel qu'un joueur de cette faction le voit.
   Aucune popup navigateur. Lecture seule.

   API : DBFactionPreview.open()
   ===================================================================== */
(function (w, d) {
    'use strict';

    var API = (w.DASHBOARD_THEME && w.DASHBOARD_THEME.apiBase) || '/api/dashboard';
    var overlay = null, refs = {}, factions = [], selected = '__citizen__';

    function fetchJSON(u) { return fetch(u).then(function (r) { return r.json(); }); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function build() {
        overlay = d.createElement('div');
        overlay.className = 'db-overlay';
        overlay.innerHTML =
            '<div class="db-modal db-preview-modal" role="dialog" aria-modal="true">' +
              '<div class="db-modal-head">' +
                '<div class="db-modal-ico"><i class="fa-solid fa-user-group"></i></div>' +
                '<div><div class="db-modal-title">Prévisualisation du Dashboard Joueur</div>' +
                '<div class="db-modal-sub">Visualise le dashboard tel qu\'il est vu par un joueur</div></div>' +
                '<button class="db-modal-close" data-act="close"><i class="fa-solid fa-xmark"></i></button>' +
              '</div>' +
              '<div class="db-modal-body">' +
                '<div class="db-pv-pick">' +
                  '<div class="db-cs-search db-pv-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="Rechercher une faction…" spellcheck="false"></div>' +
                  '<div class="db-pv-factions"></div>' +
                '</div>' +
                '<div class="db-pv-stage"><div class="db-loading"><i class="fa-solid fa-spinner fa-spin"></i> Chargement…</div></div>' +
              '</div>' +
            '</div>';
        d.body.appendChild(overlay);
        refs.search = overlay.querySelector('.db-pv-search input');
        refs.factions = overlay.querySelector('.db-pv-factions');
        refs.stage = overlay.querySelector('.db-pv-stage');

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) return close();
            var a = e.target.closest('[data-act]');
            if (a && a.getAttribute('data-act') === 'close') close();
        });
        refs.search.addEventListener('input', function () { renderFactions(refs.search.value); });
        d.addEventListener('keydown', onEsc);
    }

    function onEsc(e) { if (e.key === 'Escape') close(); }

    function close() {
        if (!overlay) return;
        overlay.classList.remove('db-open');
        d.removeEventListener('keydown', onEsc);
        setTimeout(function () { if (overlay) { overlay.remove(); overlay = null; } }, 240);
    }

    function renderFactions(q) {
        q = (q || '').toLowerCase().trim();
        refs.factions.innerHTML = '';
        var items = [{ value: '__citizen__', label: 'Citoyen', color: '#64748b', icon: 'user', desc: 'Sans faction' }]
            .concat(factions);
        items.filter(function (f) { return !q || f.label.toLowerCase().indexOf(q) >= 0; })
            .forEach(function (f) {
                var c = d.createElement('button');
                c.type = 'button';
                c.className = 'db-pv-faction' + (f.value === selected ? ' db-active' : '');
                c.style.setProperty('--c', f.color || '#64748b');
                c.innerHTML =
                    '<span class="db-pv-faction-ico"><i class="fa-solid fa-' + esc(f.icon || 'shield') + '"></i></span>' +
                    '<span class="db-pv-faction-txt"><span class="db-pv-faction-name">' + esc(f.label) + '</span>' +
                    (f.desc ? '<span class="db-pv-faction-desc">' + esc(f.desc) + '</span>' : '') + '</span>';
                c.addEventListener('click', function () { selected = f.value; renderFactions(refs.search.value); loadPreview(); });
                refs.factions.appendChild(c);
            });
    }

    function loadPreview() {
        refs.stage.innerHTML = '<div class="db-loading"><i class="fa-solid fa-spinner fa-spin"></i> Chargement…</div>';
        var fac = selected === '__citizen__' ? '' : selected;
        fetchJSON(API + '/preview.php?action=render&faction=' + encodeURIComponent(fac))
            .then(function (j) { renderStage(j && j.categories ? j.categories : []); })
            .catch(function () { refs.stage.innerHTML = '<div class="db-empty">Erreur de chargement</div>'; });
    }

    function renderStage(categories) {
        if (!categories.length) {
            refs.stage.innerHTML = '<div class="db-empty db-soon"><i class="fa-solid fa-ghost"></i><div>Aucun panel visible pour ce profil</div></div>';
            return;
        }
        var html = '';
        categories.forEach(function (cat) {
            html += '<section class="category-group"><div class="category-header"><div class="category-label">' +
                esc(cat.label) + '<span class="category-count">' + cat.panels.length + '</span></div><div class="category-line"></div></div>' +
                '<div class="panels-grid">';
            cat.panels.forEach(function (p) {
                var visual = p.logo
                    ? '<div class="panel-card-icon panel-card-icon--logo"><img src="' + esc(p.logo) + '" alt=""></div>'
                    : '<div class="panel-card-icon"><i class="fa-solid fa-' + esc(p.icon) + '"></i></div>';
                html += '<div class="panel-card db-pv-card" style="--p-color:' + esc(p.color) + '">' + visual +
                    '<div class="panel-card-body"><div class="panel-card-name">' + esc(p.name) + '</div>' +
                    '<div class="panel-card-faction">' + esc(p.description || p.faction || p.key) + '</div></div></div>';
            });
            html += '</div></section>';
        });
        refs.stage.innerHTML = html;
    }

    var api = {
        open: function () {
            if (overlay) return;
            selected = '__citizen__';
            build();
            requestAnimationFrame(function () { overlay.classList.add('db-open'); });
            fetchJSON(API + '/preview.php?action=factions').then(function (j) {
                factions = (j && j.factions) || [];
                renderFactions('');
            }).catch(function () { factions = []; renderFactions(''); });
            loadPreview();
        }
    };

    w.DBFactionPreview = api;
})(window, document);
