/* =====================================================================
   DBCustomSelect — sélecteur custom (PAS de <select> HTML).
   Recherche instantanée, pastille couleur / icône / description, animation.

   API :
     var s = new DBCustomSelect(mountEl, {
       options: [{ value, label, icon?, color?, description? }],
       value, placeholder, searchable=true, emptyText,
       onChange(value, option)
     });
     s.getValue() / s.setValue(v) / s.setOptions(arr) / s.destroy()

   Style « MDTConfirm » : sans dépendance.
   ===================================================================== */
(function (w, d) {
    'use strict';

    function el(tag, cls, html) {
        var e = d.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }

    function DBCustomSelect(mount, opts) {
        opts = opts || {};
        this.mount = mount;
        this.options = opts.options || [];
        this.value = opts.value != null ? opts.value : null;
        this.placeholder = opts.placeholder || 'Sélectionner…';
        this.searchable = opts.searchable !== false;
        this.emptyText = opts.emptyText || 'Aucun résultat';
        this.onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};
        this.open = false;
        this._build();
    }

    DBCustomSelect.prototype._optHtml = function (o) {
        var lead = '';
        if (o.color) lead = '<span class="db-cs-dot" style="background:' + o.color + '"></span>';
        else if (o.icon) lead = '<span class="db-cs-ico"><i class="fa-solid fa-' + o.icon + '"></i></span>';
        var desc = o.description ? '<span class="db-cs-desc">' + o.description + '</span>' : '';
        return lead + '<span class="db-cs-txt"><span class="db-cs-lbl">' + (o.label || o.value) + '</span>' + desc + '</span>';
    };

    DBCustomSelect.prototype._build = function () {
        var self = this;
        this.mount.classList.add('db-cs');
        this.mount.innerHTML =
            '<button type="button" class="db-cs-trigger"><span class="db-cs-current"></span><i class="fa-solid fa-chevron-down db-cs-caret"></i></button>' +
            '<div class="db-cs-panel">' +
                (this.searchable ? '<div class="db-cs-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="Rechercher…" spellcheck="false"></div>' : '') +
                '<div class="db-cs-list"></div>' +
            '</div>';

        this.trigger = this.mount.querySelector('.db-cs-trigger');
        this.panel = this.mount.querySelector('.db-cs-panel');
        this.list = this.mount.querySelector('.db-cs-list');
        this.search = this.mount.querySelector('.db-cs-search input');
        this.current = this.mount.querySelector('.db-cs-current');

        this.trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            self.toggle();
        });
        if (this.search) {
            this.search.addEventListener('input', function () { self._renderList(self.search.value); });
            this.search.addEventListener('click', function (e) { e.stopPropagation(); });
        }
        this._docClose = function () { self.close(); };

        this._renderCurrent();
    };

    DBCustomSelect.prototype._renderCurrent = function () {
        var o = this._find(this.value);
        if (o) { this.current.innerHTML = this._optHtml(o); this.current.classList.remove('db-cs-ph'); }
        else { this.current.innerHTML = this.placeholder; this.current.classList.add('db-cs-ph'); }
    };

    DBCustomSelect.prototype._renderList = function (q) {
        var self = this;
        q = (q || '').toLowerCase().trim();
        this.list.innerHTML = '';
        var filtered = this.options.filter(function (o) {
            if (!q) return true;
            return ((o.label || '') + ' ' + (o.value || '') + ' ' + (o.description || '')).toLowerCase().indexOf(q) >= 0;
        });
        if (!filtered.length) {
            this.list.appendChild(el('div', 'db-cs-empty', this.emptyText));
            return;
        }
        filtered.forEach(function (o) {
            var item = el('div', 'db-cs-item' + (o.value === self.value ? ' db-cs-sel' : ''), self._optHtml(o));
            item.addEventListener('click', function () {
                self.value = o.value;
                self._renderCurrent();
                self.close();
                self.onChange(o.value, o);
            });
            self.list.appendChild(item);
        });
    };

    DBCustomSelect.prototype._find = function (v) {
        for (var i = 0; i < this.options.length; i++) if (this.options[i].value === v) return this.options[i];
        return null;
    };

    DBCustomSelect.prototype.toggle = function () { this.open ? this.close() : this.openPanel(); };

    DBCustomSelect.prototype.openPanel = function () {
        var self = this;
        this.open = true;
        this.mount.classList.add('db-cs-active');
        this._renderList('');
        if (this.search) { this.search.value = ''; setTimeout(function () { self.search.focus(); }, 30); }
        setTimeout(function () { d.addEventListener('click', self._docClose); }, 0);
    };

    DBCustomSelect.prototype.close = function () {
        this.open = false;
        this.mount.classList.remove('db-cs-active');
        d.removeEventListener('click', this._docClose);
    };

    DBCustomSelect.prototype.getValue = function () { return this.value; };
    DBCustomSelect.prototype.setValue = function (v) { this.value = v; this._renderCurrent(); };
    DBCustomSelect.prototype.setOptions = function (arr) { this.options = arr || []; this._renderCurrent(); if (this.open) this._renderList(this.search ? this.search.value : ''); };
    DBCustomSelect.prototype.destroy = function () { d.removeEventListener('click', this._docClose); this.mount.innerHTML = ''; this.mount.classList.remove('db-cs', 'db-cs-active'); };

    w.DBCustomSelect = DBCustomSelect;
})(window, document);
