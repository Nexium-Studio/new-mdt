/* =====================================================================
   dashboard-builder.js — orchestrateur du constructeur de dashboard.
   Instancie la BuilderSidebar, branche le bouton « Custom », et implémente
   les panes : Design (thème), Catégories (CRUD), Ajouter/Gérer panels (phase 4).

   Dépend de : DBBuilderSidebar, DBCustomSelect, DBColorPicker, DBThemeManager,
   MDTConfirm. Style « MDTConfirm ».
   ===================================================================== */
(function (w, d) {
    'use strict';

    var API = (w.DASHBOARD_THEME && w.DASHBOARD_THEME.apiBase) || '/api/dashboard';

    function fetchJSON(url, opts) {
        return fetch(url, opts).then(function (r) { return r.json(); });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, type) { if (w.MDTConfirm) w.MDTConfirm.toast(msg, type || 'info'); }

    // ── Modale générique (réutilise les classes db-overlay/db-modal) ──
    function openModal(cfg) {
        var ov = d.createElement('div');
        ov.className = 'db-overlay';
        ov.innerHTML =
            '<div class="db-modal" role="dialog" aria-modal="true">' +
              '<div class="db-modal-head">' +
                '<div class="db-modal-ico"><i class="fa-solid fa-' + (cfg.icon || 'pen') + '"></i></div>' +
                '<div><div class="db-modal-title">' + esc(cfg.title) + '</div>' +
                (cfg.subtitle ? '<div class="db-modal-sub">' + esc(cfg.subtitle) + '</div>' : '') + '</div>' +
                '<button class="db-modal-close" data-act="close"><i class="fa-solid fa-xmark"></i></button>' +
              '</div>' +
              '<div class="db-modal-body"></div>' +
              '<div class="db-modal-foot">' +
                '<button class="db-btn db-btn-ghost" data-act="close">Annuler</button>' +
                '<button class="db-btn db-btn-primary" data-act="ok"><i class="fa-solid fa-check"></i> ' + esc(cfg.okLabel || 'Enregistrer') + '</button>' +
              '</div>' +
            '</div>';
        d.body.appendChild(ov);
        var body = ov.querySelector('.db-modal-body');
        if (cfg.render) cfg.render(body);

        function close() {
            ov.classList.remove('db-open');
            d.removeEventListener('keydown', onEsc);
            if (w.DBColorPicker) w.DBColorPicker.close();
            setTimeout(function () { ov.remove(); }, 240);
        }
        function onEsc(e) { if (e.key === 'Escape') close(); }
        ov.addEventListener('click', function (e) {
            if (e.target === ov) return close();
            var a = e.target.closest('[data-act]');
            if (!a) return;
            if (a.getAttribute('data-act') === 'close') return close();
            if (a.getAttribute('data-act') === 'ok' && cfg.onOk) cfg.onOk(close, a);
        });
        d.addEventListener('keydown', onEsc);
        requestAnimationFrame(function () { ov.classList.add('db-open'); });
        return { close: close, overlay: ov };
    }

    // ── Champ couleur (swatch -> ColorPicker) ──
    function colorField(initial) {
        var wrap = d.createElement('div');
        wrap.className = 'db-field';
        wrap.innerHTML =
            '<label class="db-field-label">Couleur</label>' +
            '<div class="db-color-field">' +
              '<button type="button" class="db-color-swatch-btn"></button>' +
              '<span class="db-color-field-hex"></span>' +
            '</div>';
        var state = { value: initial || '#64748B' };
        var sw = wrap.querySelector('.db-color-swatch-btn');
        var hex = wrap.querySelector('.db-color-field-hex');
        function refresh() { sw.style.background = state.value; hex.textContent = state.value.toUpperCase(); }
        refresh();
        sw.addEventListener('click', function (e) {
            e.stopPropagation();
            w.DBColorPicker.open(sw, state.value, function (h) { state.value = h; refresh(); });
        });
        return { el: wrap, get: function () { return state.value; } };
    }

    // =====================================================================
    //  PANE — CATÉGORIES
    // =====================================================================
    function renderCategoriesPane(container) {
        container.innerHTML =
            '<div class="db-pane-head">' +
              '<div><div class="db-pane-title">Catégories</div>' +
              '<div class="db-pane-desc">Organisez les sections du dashboard</div></div>' +
              '<button class="db-btn db-btn-primary db-cat-add"><i class="fa-solid fa-plus"></i> Ajouter</button>' +
            '</div>' +
            '<div class="db-cat-list"><div class="db-loading"><i class="fa-solid fa-spinner fa-spin"></i> Chargement…</div></div>';

        var list = container.querySelector('.db-cat-list');
        container.querySelector('.db-cat-add').addEventListener('click', function () {
            openCategoryForm(null, _cats, function () { loadCats(); });
        });

        var _cats = [];
        function loadCats() {
            _meta = null; // invalide la meta panel (catégories) après tout changement
            fetchJSON(API + '/categories.php?action=list').then(function (j) {
                _cats = (j && j.categories) || [];
                renderList();
            }).catch(function () { list.innerHTML = '<div class="db-empty">Erreur de chargement</div>'; });
        }

        function renderList() {
            if (!_cats.length) { list.innerHTML = '<div class="db-empty">Aucune catégorie</div>'; return; }
            list.innerHTML = '';
            _cats.forEach(function (c) {
                var row = d.createElement('div');
                row.className = 'db-cat-row' + (c.is_hidden ? ' db-cat-hidden' : '');
                row.innerHTML =
                    '<span class="db-cat-dot" style="background:' + esc(c.color) + '"></span>' +
                    '<div class="db-cat-info">' +
                      '<div class="db-cat-name">' + esc(c.label) +
                        (c.is_custom ? '<span class="db-tag db-tag-custom">Custom</span>' : '<span class="db-tag">Système</span>') +
                        (c.is_hidden ? '<span class="db-tag db-tag-hidden">Masquée</span>' : '') +
                      '</div>' +
                      '<div class="db-cat-meta">' + c.panel_count + ' panel' + (c.panel_count > 1 ? 's' : '') +
                        (c.description ? ' · ' + esc(c.description) : '') + '</div>' +
                    '</div>' +
                    '<div class="db-cat-actions">' +
                      '<button class="db-icon-btn" data-a="vis" title="' + (c.is_hidden ? 'Afficher' : 'Masquer') + '"><i class="fa-solid fa-' + (c.is_hidden ? 'eye-slash' : 'eye') + '"></i></button>' +
                      '<button class="db-icon-btn" data-a="edit" title="Modifier"><i class="fa-solid fa-pen"></i></button>' +
                      (c.is_custom ? '<button class="db-icon-btn db-icon-danger" data-a="del" title="Supprimer"><i class="fa-solid fa-trash"></i></button>' : '') +
                    '</div>';
                row.querySelector('[data-a="vis"]').addEventListener('click', function () { toggleVis(c); });
                row.querySelector('[data-a="edit"]').addEventListener('click', function () {
                    openCategoryForm(c, _cats, function () { loadCats(); });
                });
                var del = row.querySelector('[data-a="del"]');
                if (del) del.addEventListener('click', function () { confirmDelete(c); });
                list.appendChild(row);
            });
        }

        function toggleVis(c) {
            fetchJSON(API + '/categories.php?action=toggle_hidden', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category_key: c.category_key })
            }).then(function (j) { if (j && j.success) { loadCats(); } else { toast('Action impossible', 'error'); } });
        }

        function confirmDelete(c) {
            w.MDTConfirm.confirmDanger({
                title: 'Supprimer la catégorie',
                message: 'Supprimer « ' + c.label + ' » ? Les panels qui y étaient rattachés reviendront à leur catégorie d\'origine.',
                confirmText: 'Supprimer'
            }).then(function (ok) {
                if (!ok) return;
                fetchJSON(API + '/categories.php?action=delete', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category_key: c.category_key })
                }).then(function (j) {
                    if (j && j.success) { toast('Catégorie supprimée', 'success'); loadCats(); }
                    else { toast('Suppression impossible', 'error'); }
                });
            });
        }

        loadCats();
    }

    // ── Formulaire création / édition catégorie ──
    function openCategoryForm(existing, cats, onSaved) {
        var isEdit = !!existing;
        var posSel = null, colField = null;
        openModal({
            icon: isEdit ? 'pen' : 'plus',
            title: isEdit ? 'Modifier la catégorie' : 'Nouvelle catégorie',
            subtitle: isEdit ? existing.label : 'Ajouter une section au dashboard',
            okLabel: isEdit ? 'Enregistrer' : 'Créer',
            render: function (body) {
                body.innerHTML =
                    '<div class="db-field"><label class="db-field-label">Nom</label>' +
                      '<input class="db-input" data-f="label" maxlength="120" value="' + esc(isEdit ? existing.label : '') + '" placeholder="Ex : Services Publics"></div>' +
                    '<div class="db-field"><label class="db-field-label">Description</label>' +
                      '<textarea class="db-input db-textarea" data-f="description" maxlength="255" placeholder="Optionnel">' + esc(isEdit ? existing.description : '') + '</textarea></div>' +
                    '<div class="db-color-mount"></div>' +
                    '<div class="db-field"><label class="db-field-label">Position</label><div class="db-pos-mount"></div></div>';

                colField = colorField(isEdit ? existing.color : '#64748B');
                body.querySelector('.db-color-mount').appendChild(colField.el);

                // Options de position : "Au début" + "Après <cat>" (hors elle-même)
                var opts = [{ value: '', label: 'Au début' }];
                var preselect = '';
                var prevKey = '';
                cats.forEach(function (c) {
                    if (isEdit && c.category_key === existing.category_key) {
                        preselect = prevKey; // placer après la catégorie qui la précède
                        return;
                    }
                    opts.push({ value: c.category_key, label: 'Après ' + c.label, color: c.color });
                    prevKey = c.category_key;
                });
                if (!isEdit && cats.length) preselect = cats[cats.length - 1].category_key; // fin par défaut
                var mount = body.querySelector('.db-pos-mount');
                posSel = new w.DBCustomSelect(mount, { options: opts, value: preselect, searchable: cats.length > 6 });
            },
            onOk: function (close, btn) {
                var modal = btn.closest('.db-modal');
                var label = modal.querySelector('[data-f="label"]').value.trim();
                if (!label) { toast('Le nom est requis', 'error'); return; }
                var desc = modal.querySelector('[data-f="description"]').value.trim();
                var payload = {
                    label: label, description: desc, color: colField.get(),
                    after: posSel ? (posSel.getValue() || '') : ''
                };
                var url = API + '/categories.php?action=' + (isEdit ? 'update' : 'create');
                if (isEdit) payload.category_key = existing.category_key;
                btn.disabled = true; btn.style.opacity = '.6';
                fetchJSON(url, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                }).then(function (j) {
                    if (j && j.success) { toast(isEdit ? 'Catégorie modifiée' : 'Catégorie créée', 'success'); close(); onSaved(); }
                    else { toast('Enregistrement impossible', 'error'); btn.disabled = false; btn.style.opacity = ''; }
                }).catch(function () { toast('Erreur réseau', 'error'); btn.disabled = false; btn.style.opacity = ''; });
            }
        });
    }

    // =====================================================================
    //  PANELS — meta partagée (catégories + factions)
    // =====================================================================
    var _meta = null;
    function loadMeta(cb) {
        if (_meta) { cb(_meta); return; }
        fetchJSON(API + '/panels.php?action=meta').then(function (j) {
            _meta = j && j.success ? j : { categories: [], factions: [] };
            cb(_meta);
        }).catch(function () { cb({ categories: [], factions: [] }); });
    }

    // ── Champ icône (bouton -> IconPicker) ──
    function iconField(initial) {
        var wrap = d.createElement('div');
        wrap.className = 'db-field';
        wrap.innerHTML = '<label class="db-field-label">Icône</label>' +
            '<button type="button" class="db-icon-pick"><span class="db-icon-pick-prev"></span><span class="db-icon-pick-lbl"></span><i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:.7rem;opacity:.6"></i></button>';
        var state = { mode: (initial && initial.mode) || 'fa', value: (initial && initial.value) || 'store' };
        var btn = wrap.querySelector('.db-icon-pick');
        var prev = wrap.querySelector('.db-icon-pick-prev');
        var lbl = wrap.querySelector('.db-icon-pick-lbl');
        function refresh() {
            if (state.mode === 'url' && /^https?:\/\//i.test(state.value)) {
                prev.innerHTML = '<img src="' + esc(state.value) + '" alt="">';
                lbl.textContent = 'Image personnalisée';
            } else {
                prev.innerHTML = '<i class="fa-solid fa-' + esc(state.value) + '"></i>';
                lbl.textContent = state.value;
            }
        }
        refresh();
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            w.DBIconPicker.open(btn, state, function (res) { state = res; refresh(); });
        });
        return { el: wrap, get: function () { return state; } };
    }

    // ── Formulaire création / édition panel ──
    function openPanelForm(existing, meta, onSaved) {
        var isEdit = !!existing;
        var canFaction = !isEdit || existing.is_custom; // faction non éditable pour un panel catalogue
        var catSel = null, facSel = null, colField = null, icoField = null;

        var initIcon = isEdit
            ? (existing.logo ? { mode: 'url', value: existing.logo } : { mode: 'fa', value: existing.icon })
            : { mode: 'fa', value: 'store' };

        openModal({
            icon: isEdit ? 'pen' : 'square-plus',
            title: isEdit ? 'Modifier le panel' : 'Nouveau panel',
            subtitle: isEdit ? existing.name : 'Ajouter une carte au dashboard',
            okLabel: isEdit ? 'Enregistrer' : 'Créer',
            render: function (body) {
                body.innerHTML =
                    '<div class="db-field"><label class="db-field-label">Nom</label>' +
                      '<input class="db-input" data-f="name" maxlength="100" value="' + esc(isEdit ? existing.name : '') + '" placeholder="Ex : Ma Boutique"></div>' +
                    '<div class="db-field"><label class="db-field-label">Description</label>' +
                      '<textarea class="db-input db-textarea" data-f="description" maxlength="255" placeholder="Optionnel">' + esc(isEdit ? existing.description : '') + '</textarea></div>' +
                    '<div class="db-field"><label class="db-field-label">Catégorie</label><div class="db-cat-mount"></div></div>' +
                    (canFaction ? '<div class="db-field"><label class="db-field-label">Faction</label><div class="db-fac-mount"></div></div>' : '') +
                    '<div class="db-ico-mount"></div>' +
                    '<div class="db-col-mount"></div>';

                var catOpts = (meta.categories || []).map(function (c) { return { value: c.value, label: c.label }; });
                catSel = new w.DBCustomSelect(body.querySelector('.db-cat-mount'), {
                    options: catOpts, value: isEdit ? findCatKey(meta, existing.category) : (catOpts[0] && catOpts[0].value), searchable: catOpts.length > 6, placeholder: 'Choisir une catégorie'
                });

                if (canFaction) {
                    var facOpts = [{ value: '', label: 'Aucune faction' }].concat(
                        (meta.factions || []).map(function (f) { return { value: f.value, label: f.label, color: f.color, icon: f.icon }; })
                    );
                    facSel = new w.DBCustomSelect(body.querySelector('.db-fac-mount'), {
                        options: facOpts, value: isEdit ? (existing.faction || '') : '', searchable: true, placeholder: 'Aucune faction'
                    });
                }

                icoField = iconField(initIcon);
                body.querySelector('.db-ico-mount').appendChild(icoField.el);
                colField = colorField(isEdit ? existing.color : '#3B82F6');
                body.querySelector('.db-col-mount').appendChild(colField.el);
            },
            onOk: function (close, btn) {
                var modal = btn.closest('.db-modal');
                var name = modal.querySelector('[data-f="name"]').value.trim();
                if (!name) { toast('Le nom est requis', 'error'); return; }
                var ico = icoField.get();
                var payload = {
                    name: name,
                    description: modal.querySelector('[data-f="description"]').value.trim(),
                    category: catSel ? catSel.getValue() : '',
                    color: colField.get(),
                    icon_mode: ico.mode,
                    icon: ico.value
                };
                if (canFaction && facSel) payload.faction = facSel.getValue() || '';
                var url = API + '/panels.php?action=' + (isEdit ? 'update' : 'create');
                if (isEdit) payload.panel_key = existing.key;
                btn.disabled = true; btn.style.opacity = '.6';
                fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                    .then(function (j) {
                        if (j && j.success) { toast(isEdit ? 'Panel modifié' : 'Panel créé', 'success'); close(); onSaved(); }
                        else { toast('Enregistrement impossible', 'error'); btn.disabled = false; btn.style.opacity = ''; }
                    }).catch(function () { toast('Erreur réseau', 'error'); btn.disabled = false; btn.style.opacity = ''; });
            }
        });
    }

    function findCatKey(meta, label) {
        var hit = (meta.categories || []).filter(function (c) { return c.label === label; })[0];
        return hit ? hit.value : ((meta.categories || [])[0] && meta.categories[0].value);
    }

    // =====================================================================
    //  PANE — AJOUTER UN PANEL
    // =====================================================================
    function renderAddPanelPane(container) {
        container.innerHTML =
            '<div class="db-pane-head"><div><div class="db-pane-title">Ajouter un panel</div>' +
            '<div class="db-pane-desc">Crée une carte sur le dashboard (panel custom)</div></div></div>' +
            '<div class="db-empty db-soon"><i class="fa-solid fa-square-plus"></i><div>Renseigne nom, catégorie, faction et icône.</div>' +
            '<button class="db-btn db-btn-primary" style="margin-top:12px"><i class="fa-solid fa-plus"></i> Nouveau panel</button></div>';
        container.querySelector('.db-btn-primary').addEventListener('click', function () {
            loadMeta(function (meta) { openPanelForm(null, meta, function () { toast('Recharge le dashboard pour voir le panel', 'info'); }); });
        });
    }

    // =====================================================================
    //  PANE — GÉRER LES PANELS
    // =====================================================================
    function renderPanelsPane(container) {
        container.innerHTML =
            '<div class="db-pane-head"><div><div class="db-pane-title">Panels</div>' +
            '<div class="db-pane-desc">Activer, éditer ou supprimer les panels</div></div></div>' +
            '<div class="db-cs-search db-panel-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="Rechercher un panel…" spellcheck="false"></div>' +
            '<div class="db-panel-list"><div class="db-loading"><i class="fa-solid fa-spinner fa-spin"></i> Chargement…</div></div>';

        var list = container.querySelector('.db-panel-list');
        var search = container.querySelector('.db-panel-search input');
        var _panels = [], _metaC = null;

        function load() {
            Promise.all([
                fetchJSON(API + '/panels.php?action=list'),
                new Promise(function (res) { loadMeta(res); })
            ]).then(function (r) {
                _panels = (r[0] && r[0].panels) || [];
                _metaC = r[1];
                render(search.value);
            }).catch(function () { list.innerHTML = '<div class="db-empty">Erreur de chargement</div>'; });
        }

        search.addEventListener('input', function () { render(search.value); });

        function render(q) {
            q = (q || '').toLowerCase().trim();
            var rows = _panels.filter(function (p) {
                return !q || (p.name + ' ' + p.key + ' ' + p.category + ' ' + p.faction).toLowerCase().indexOf(q) >= 0;
            });
            if (!rows.length) { list.innerHTML = '<div class="db-empty">Aucun panel</div>'; return; }
            list.innerHTML = '';
            rows.forEach(function (p) {
                var row = d.createElement('div');
                row.className = 'db-panel-row' + (p.is_active ? '' : ' db-panel-off');
                var visual = p.logo
                    ? '<span class="db-panel-ico" style="--c:' + esc(p.color) + '"><img src="' + esc(p.logo) + '" alt=""></span>'
                    : '<span class="db-panel-ico" style="--c:' + esc(p.color) + '"><i class="fa-solid fa-' + esc(p.icon) + '"></i></span>';
                row.innerHTML = visual +
                    '<div class="db-panel-info">' +
                      '<div class="db-panel-name">' + esc(p.name) +
                        (p.is_custom ? '<span class="db-tag db-tag-custom">Custom</span>' : (p.is_division ? '<span class="db-tag">Division</span>' : '')) +
                      '</div>' +
                      '<div class="db-panel-meta">' + esc(p.category) + (p.faction ? ' · ' + esc(p.faction) : '') +
                        (p.description ? ' · ' + esc(p.description) : '') + '</div>' +
                    '</div>' +
                    '<div class="db-panel-actions">' +
                      '<label class="db-switch" title="' + (p.always_active ? 'Toujours actif' : (p.is_active ? 'Désactiver' : 'Activer')) + '">' +
                        '<input type="checkbox" ' + (p.is_active ? 'checked' : '') + (p.always_active ? ' disabled' : '') + '><span class="db-switch-track"></span></label>' +
                      '<button class="db-icon-btn" data-a="edit" title="Modifier"><i class="fa-solid fa-pen"></i></button>' +
                      (p.is_custom ? '<button class="db-icon-btn db-icon-danger" data-a="del" title="Supprimer"><i class="fa-solid fa-trash"></i></button>' : '') +
                    '</div>';
                var chk = row.querySelector('.db-switch input');
                if (chk && !p.always_active) chk.addEventListener('change', function () { toggle(p, chk.checked); });
                row.querySelector('[data-a="edit"]').addEventListener('click', function () {
                    openPanelForm(p, _metaC, function () { load(); });
                });
                var del = row.querySelector('[data-a="del"]');
                if (del) del.addEventListener('click', function () { confirmDelete(p); });
                list.appendChild(row);
            });
        }

        function toggle(p, on) {
            fetchJSON(API + '/panels.php?action=toggle', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ panel_key: p.key, is_active: on ? 1 : 0 })
            }).then(function (j) {
                if (j && j.success) { p.is_active = on; toast(on ? 'Panel activé' : 'Panel désactivé', 'success'); }
                else { toast('Action impossible', 'error'); load(); }
            }).catch(function () { toast('Erreur réseau', 'error'); load(); });
        }

        function confirmDelete(p) {
            w.MDTConfirm.confirmDanger({
                title: 'Supprimer le panel',
                message: 'Supprimer définitivement « ' + p.name + ' » ? Cette action est irréversible.',
                confirmText: 'Supprimer'
            }).then(function (ok) {
                if (!ok) return;
                fetchJSON(API + '/panels.php?action=delete', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ panel_key: p.key })
                }).then(function (j) {
                    if (j && j.success) { toast('Panel supprimé', 'success'); load(); }
                    else { toast('Suppression impossible', 'error'); }
                });
            });
        }

        load();
    }

    // =====================================================================
    //  INIT
    // =====================================================================
    function init() {
        var btn = d.getElementById('dbCustomBtn');
        if (!btn) return;
        var missing = ['DBBuilderSidebar', 'DBCustomSelect', 'DBColorPicker', 'DBThemeManager', 'DBIconPicker']
            .filter(function (n) { return !w[n]; });
        if (missing.length) {
            console.error('[dashboard-builder] Dépendances non chargées : ' + missing.join(', ') +
                ' — videz le cache (Ctrl+F5) ou vérifiez que les fichiers assets/js/dashboard-builder/*.js sont bien servis.');
            return;
        }

        // ── Mode édition (drag & drop) ──
        var dnd = w.DBDragDrop ? new w.DBDragDrop({
            onReorderPanels: function (catKey, keys) {
                fetchJSON(API + '/panels.php?action=reorder', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category_key: catKey, order: keys })
                }).then(function (j) { toast(j && j.success ? 'Ordre des panels enregistré' : 'Échec de l\'enregistrement', j && j.success ? 'success' : 'error'); });
            },
            onReorderCategories: function (keys) {
                fetchJSON(API + '/categories.php?action=reorder', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: keys })
                }).then(function (j) { toast(j && j.success ? 'Ordre des catégories enregistré' : 'Échec de l\'enregistrement', j && j.success ? 'success' : 'error'); });
            }
        }) : null;

        var editBar = null;
        function showEditBar() {
            if (editBar) return;
            editBar = d.createElement('div');
            editBar.className = 'db-editbar';
            editBar.innerHTML =
                '<i class="fa-solid fa-up-down-left-right"></i>' +
                '<span>Mode édition — glissez la poignée des panels et des catégories pour réorganiser. Sauvegarde automatique.</span>' +
                '<button class="db-btn db-btn-primary db-editbar-done"><i class="fa-solid fa-check"></i> Terminer</button>';
            d.body.appendChild(editBar);
            editBar.querySelector('.db-editbar-done').addEventListener('click', disableEditMode);
            requestAnimationFrame(function () { editBar.classList.add('db-open'); });
        }
        function enableEditMode() {
            if (!dnd) { toast('Module de réorganisation indisponible', 'error'); return; }
            sidebar.close();
            dnd.enable();
            showEditBar();
        }
        function disableEditMode() {
            if (dnd) dnd.disable();
            if (editBar) {
                editBar.classList.remove('db-open');
                var e = editBar; editBar = null;
                setTimeout(function () { e.remove(); }, 240);
            }
        }

        var sidebar = new w.DBBuilderSidebar({
            title: 'Personnalisation',
            subtitle: 'Constructeur de dashboard',
            menu: [
                { id: 'design', icon: 'palette', label: 'Design de la page', render: function (c) {
                    c.innerHTML =
                        '<div class="db-pane-head"><div><div class="db-pane-title">Design de la page</div>' +
                        '<div class="db-pane-desc">Thème global + organisation du dashboard</div></div></div>' +
                        '<button class="db-btn db-btn-primary db-open-theme" style="width:100%;justify-content:center;margin-bottom:10px"><i class="fa-solid fa-palette"></i> Ouvrir l\'éditeur de thème</button>' +
                        '<button class="db-btn db-btn-ghost db-edit-mode" style="width:100%;justify-content:center"><i class="fa-solid fa-up-down-left-right"></i> Mode édition (réorganiser)</button>';
                    c.querySelector('.db-open-theme').addEventListener('click', function () {
                        if (w.DBThemeManager) w.DBThemeManager.openEditor();
                    });
                    c.querySelector('.db-edit-mode').addEventListener('click', enableEditMode);
                } },
                { id: 'categories', icon: 'layer-group', label: 'Gérer les catégories', render: renderCategoriesPane },
                { id: 'addPanel', icon: 'square-plus', label: 'Ajouter un panel', render: renderAddPanelPane },
                { id: 'panels', icon: 'table-cells-large', label: 'Gérer les panels', render: renderPanelsPane }
            ]
        });

        btn.addEventListener('click', function () { sidebar.open('design'); });

        // Ouverture directe via ?builder=<pane> (lien depuis l'ancienne page de gestion)
        var m = /[?&]builder=([a-z]+)/i.exec(w.location.search);
        if (m) { sidebar.open(m[1]); }

        w.DBDashboardBuilder = { sidebar: sidebar, dnd: dnd };
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', init);
    else init();
})(window, document);
