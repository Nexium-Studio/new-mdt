/* =====================================================================
   DBDragDrop — mode édition : réordonner les panels DANS leur catégorie
   et réordonner les catégories entre elles (drag natif, autosave debounce).
   Pas de déplacement inter-catégories (décision client).

   API :
     var dd = new DBDragDrop({ onReorderPanels(catKey, keys), onReorderCategories(catKeys) });
     dd.enable() / dd.disable() / dd.isOn()
   ===================================================================== */
(function (w, d) {
    'use strict';

    function debounce(fn, ms) {
        var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }

    function DBDragDrop(opts) {
        opts = opts || {};
        this.onReorderPanels = opts.onReorderPanels || function () {};
        this.onReorderCategories = opts.onReorderCategories || function () {};
        this.on = false;
        this.state = { mode: null, el: null, grid: null };
        this._bound = [];
        this._savePanels = debounce(this._emitPanels.bind(this), opts.autosaveDebounce || 500);
        this._saveCats = debounce(this._emitCats.bind(this), opts.autosaveDebounce || 500);
    }

    DBDragDrop.prototype._listen = function (el, type, fn) {
        el.addEventListener(type, fn);
        this._bound.push([el, type, fn]);
    };

    DBDragDrop.prototype._sections = function () {
        return Array.prototype.slice.call(d.querySelectorAll('.category-group[data-db-cat-key]'));
    };

    DBDragDrop.prototype.enable = function () {
        if (this.on) return;
        this.on = true;
        d.body.classList.add('db-editing');
        var self = this;

        // Bloque la navigation par clic sur les cartes pendant l'édition.
        this._clickBlocker = function (e) {
            var card = e.target.closest('.panel-card[data-db-panel-key]');
            if (card) { e.preventDefault(); e.stopPropagation(); }
        };
        d.addEventListener('click', this._clickBlocker, true);

        this._sections().forEach(function (section) {
            // Poignée de catégorie.
            var header = section.querySelector('.category-header');
            if (header && !header.querySelector('.db-cat-handle')) {
                var h = d.createElement('button');
                h.type = 'button'; h.className = 'db-cat-handle'; h.title = 'Déplacer la catégorie';
                h.setAttribute('draggable', 'true');
                h.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';
                header.insertBefore(h, header.firstChild);
                self._listen(h, 'dragstart', function (e) {
                    self.state = { mode: 'cat', el: section, grid: null };
                    section.classList.add('db-dragging');
                    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'cat'); } catch (x) {}
                });
                self._listen(h, 'dragend', function () {
                    section.classList.remove('db-dragging');
                    self.state.mode = null;
                    self._saveCats();
                });
            }

            // Cartes draggables.
            var grid = section.querySelector('.panels-grid');
            if (grid) {
                Array.prototype.forEach.call(grid.querySelectorAll('.panel-card[data-db-panel-key]'), function (card) {
                    card.setAttribute('draggable', 'true');
                    self._listen(card, 'dragstart', function (e) {
                        self.state = { mode: 'card', el: card, grid: grid };
                        card.classList.add('db-dragging');
                        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'card'); } catch (x) {}
                    });
                    self._listen(card, 'dragend', function () {
                        card.classList.remove('db-dragging');
                        if (self.state.mode === 'card') self._savePanels(grid);
                        self.state.mode = null;
                    });
                });
                self._listen(grid, 'dragover', function (e) {
                    if (self.state.mode !== 'card' || self.state.grid !== grid) return;
                    e.preventDefault();
                    var target = e.target.closest('.panel-card[data-db-panel-key]');
                    if (!target || target === self.state.el) return;
                    var r = target.getBoundingClientRect();
                    var before = e.clientX < r.left + r.width / 2;
                    grid.insertBefore(self.state.el, before ? target : target.nextSibling);
                });
            }
        });

        // Réordonnancement des catégories (au niveau du conteneur).
        var container = (this._sections()[0] || {}).parentNode;
        if (container) {
            this._catContainer = container;
            this._listen(container, 'dragover', function (e) {
                if (self.state.mode !== 'cat') return;
                e.preventDefault();
                var target = e.target.closest('.category-group[data-db-cat-key]');
                if (!target || target === self.state.el) return;
                var r = target.getBoundingClientRect();
                var before = e.clientY < r.top + r.height / 2;
                container.insertBefore(self.state.el, before ? target : target.nextSibling);
            });
        }
    };

    DBDragDrop.prototype._emitPanels = function (grid) {
        var section = grid.closest('.category-group[data-db-cat-key]');
        if (!section) return;
        var keys = Array.prototype.slice.call(grid.querySelectorAll('.panel-card[data-db-panel-key]'))
            .map(function (c) { return c.getAttribute('data-db-panel-key'); });
        this.onReorderPanels(section.getAttribute('data-db-cat-key'), keys);
    };

    DBDragDrop.prototype._emitCats = function () {
        var keys = this._sections().map(function (s) { return s.getAttribute('data-db-cat-key'); });
        this.onReorderCategories(keys);
    };

    DBDragDrop.prototype.disable = function () {
        if (!this.on) return;
        this.on = false;
        d.body.classList.remove('db-editing');
        if (this._clickBlocker) d.removeEventListener('click', this._clickBlocker, true);
        this._bound.forEach(function (b) { b[0].removeEventListener(b[1], b[2]); });
        this._bound = [];
        Array.prototype.forEach.call(d.querySelectorAll('.db-cat-handle'), function (h) { h.remove(); });
        Array.prototype.forEach.call(d.querySelectorAll('.panel-card[draggable]'), function (c) { c.removeAttribute('draggable'); });
        this.state = { mode: null, el: null, grid: null };
    };

    DBDragDrop.prototype.isOn = function () { return this.on; };

    w.DBDragDrop = DBDragDrop;
})(window, document);
