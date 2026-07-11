/* =====================================================================
   DBThemeManager — thème GLOBAL du dashboard (dark/light/classic/custom).
   Applique data-dashboard-theme + variables --db-*, persiste via API,
   et fournit l'éditeur de thème (preview live + ColorPicker custom).

   Dépend de : DBColorPicker, MDTConfirm (toasts). Style « MDTConfirm ».

   Etat initial fourni par window.DASHBOARD_THEME = { mode, vars, apiBase }.
   ===================================================================== */
(function (w, d) {
    'use strict';

    var FIELDS = [
        { key: 'primary',   label: 'Couleur principale' },
        { key: 'secondary', label: 'Couleur secondaire' },
        { key: 'btn',       label: 'Couleur des boutons' },
        { key: 'text',      label: 'Couleur du texte' },
        { key: 'card',      label: 'Couleur des cartes' },
        { key: 'border',    label: 'Couleur des bordures' },
        { key: 'category',  label: 'Couleur des catégories' }
    ];
    var MODES = [
        { id: 'classic', name: 'Classique', desc: 'Design actuel', sw: ['#03070f', '#3b82f6', '#e2e8f0'] },
        { id: 'dark',    name: 'Sombre',    desc: 'Noir & violet',  sw: ['#0a0a0f', '#7c3aed', '#e7e7ee'] },
        { id: 'light',   name: 'Clair',     desc: 'Blanc & doux',   sw: ['#f4f6fb', '#6366f1', '#1e293b'] },
        { id: 'custom',  name: 'Personnalisé', desc: 'Éditeur',     sw: ['#141829', '#a855f7', '#ffffff'] }
    ];
    var DEFAULTS = {
        primary: '#7C3AED', secondary: '#3B82F6', btn: '#7C3AED',
        text: '#FFFFFF', card: '#141829', border: '#232A45', category: '#A0AEC0'
    };
    var VARMAP = {
        primary: '--db-primary', secondary: '--db-secondary', btn: '--db-btn',
        text: '--db-text', card: '--db-card', border: '--db-border', category: '--db-category'
    };

    var cfg = w.DASHBOARD_THEME || { mode: 'classic', vars: {}, apiBase: '/api/dashboard' };
    var current = { mode: cfg.mode || 'classic', vars: Object.assign({}, DEFAULTS, cfg.vars || {}) };
    var overlayEl = null, draft = null;

    function applyVars(vars) {
        var root = d.documentElement;
        FIELDS.forEach(function (f) {
            root.style.setProperty(VARMAP[f.key], vars[f.key] || DEFAULTS[f.key]);
        });
    }

    function apply(mode, vars) {
        d.documentElement.setAttribute('data-dashboard-theme', mode);
        if (mode === 'custom') { applyVars(vars || current.vars); }
    }

    function escClose(e) { if (e.key === 'Escape') Manager.closeEditor(); }

    function buildEditor() {
        overlayEl = d.createElement('div');
        overlayEl.className = 'db-overlay';
        overlayEl.innerHTML =
            '<div class="db-modal" role="dialog" aria-modal="true">' +
              '<div class="db-modal-head">' +
                '<div class="db-modal-ico"><i class="fa-solid fa-palette"></i></div>' +
                '<div><div class="db-modal-title">Design de la page</div>' +
                '<div class="db-modal-sub">Thème global du dashboard — appliqué à tous</div></div>' +
                '<button class="db-modal-close" data-act="close"><i class="fa-solid fa-xmark"></i></button>' +
              '</div>' +
              '<div class="db-modal-body">' +
                '<div class="db-theme-modes"></div>' +
                '<div class="db-theme-custom-wrap" style="display:none">' +
                  '<div class="db-cp-section-label">Couleurs personnalisées</div>' +
                  '<div class="db-theme-custom-fields"></div>' +
                '</div>' +
              '</div>' +
              '<div class="db-modal-foot">' +
                '<button class="db-btn db-btn-ghost" data-act="cancel">Annuler</button>' +
                '<button class="db-btn db-btn-primary" data-act="save"><i class="fa-solid fa-check"></i> Enregistrer</button>' +
              '</div>' +
            '</div>';
        d.body.appendChild(overlayEl);

        overlayEl.addEventListener('click', function (e) {
            if (e.target === overlayEl) Manager.closeEditor();
            var act = e.target.closest('[data-act]');
            if (!act) return;
            var a = act.getAttribute('data-act');
            if (a === 'close' || a === 'cancel') Manager.closeEditor();
            else if (a === 'save') saveDraft();
        });

        renderModes();
        renderCustomFields();
    }

    function renderModes() {
        var box = overlayEl.querySelector('.db-theme-modes');
        box.innerHTML = '';
        MODES.forEach(function (m) {
            var c = d.createElement('div');
            c.className = 'db-theme-mode' + (draft.mode === m.id ? ' db-active' : '');
            c.innerHTML =
                '<div class="db-theme-mode-swatch">' +
                    m.sw.map(function (s) { return '<span style="background:' + s + '"></span>'; }).join('') +
                '</div>' +
                '<div class="db-theme-mode-name">' + m.name + '</div>' +
                '<div class="db-theme-mode-desc">' + m.desc + '</div>';
            c.addEventListener('click', function () {
                draft.mode = m.id;
                renderModes();
                toggleCustom();
                apply(draft.mode, draft.vars); // preview live
            });
            box.appendChild(c);
        });
    }

    function toggleCustom() {
        overlayEl.querySelector('.db-theme-custom-wrap').style.display =
            (draft.mode === 'custom') ? 'block' : 'none';
    }

    function renderCustomFields() {
        var box = overlayEl.querySelector('.db-theme-custom-fields');
        box.innerHTML = '';
        FIELDS.forEach(function (f) {
            var row = d.createElement('div');
            row.className = 'db-color-row';
            row.innerHTML =
                '<button class="db-color-swatch-btn" type="button"></button>' +
                '<div class="db-color-row-label">' + f.label + '</div>' +
                '<div class="db-color-row-hex"></div>';
            var swatch = row.querySelector('.db-color-swatch-btn');
            var hexLbl = row.querySelector('.db-color-row-hex');
            function refresh() {
                swatch.style.background = draft.vars[f.key];
                hexLbl.textContent = draft.vars[f.key].toUpperCase();
            }
            refresh();
            swatch.addEventListener('click', function (e) {
                e.stopPropagation();
                w.DBColorPicker.open(swatch, draft.vars[f.key], function (hex) {
                    draft.vars[f.key] = hex; refresh();
                    if (draft.mode === 'custom') applyVars(draft.vars);
                });
            });
            box.appendChild(row);
        });
        toggleCustom();
    }

    function saveDraft() {
        var btn = overlayEl.querySelector('[data-act="save"]');
        btn.disabled = true; btn.style.opacity = '.6';
        var payload = { mode: draft.mode, vars: draft.vars };
        fetch((cfg.apiBase || '/api/dashboard') + '/theme.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.success) {
                current = { mode: draft.mode, vars: Object.assign({}, draft.vars) };
                apply(current.mode, current.vars);
                if (w.MDTConfirm) w.MDTConfirm.toast('Thème enregistré pour tous les utilisateurs', 'success');
                Manager.closeEditor(true);
            } else {
                throw new Error((j && j.error) || 'save_failed');
            }
        }).catch(function () {
            if (w.MDTConfirm) w.MDTConfirm.toast('Échec de l\'enregistrement du thème', 'error');
            btn.disabled = false; btn.style.opacity = '';
        });
    }

    var Manager = {
        apply: apply,
        getCurrent: function () { return { mode: current.mode, vars: Object.assign({}, current.vars) }; },
        openEditor: function () {
            draft = { mode: current.mode, vars: Object.assign({}, current.vars) };
            if (!overlayEl) buildEditor(); else { renderModes(); renderCustomFields(); }
            requestAnimationFrame(function () { overlayEl.classList.add('db-open'); });
            d.addEventListener('keydown', escClose);
        },
        closeEditor: function (saved) {
            if (!overlayEl) return;
            overlayEl.classList.remove('db-open');
            d.removeEventListener('keydown', escClose);
            if (w.DBColorPicker) w.DBColorPicker.close();
            if (!saved) { apply(current.mode, current.vars); } // revert preview
        }
    };

    // Garantir l'état initial appliqué (notamment vars custom).
    apply(current.mode, current.vars);

    w.DBThemeManager = Manager;
})(window, document);
