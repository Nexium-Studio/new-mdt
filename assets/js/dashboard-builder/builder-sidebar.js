/* =====================================================================
   DBBuilderSidebar — panneau d'administration latéral (slide depuis la droite).
   Shell générique : menu + panes interchangeables. Scrim, Escape, clic extérieur.

   API :
     var sb = new DBBuilderSidebar({
       title, subtitle,
       menu: [{ id, icon, label, render(container, sidebar) }]
     });
     sb.open(paneId) / sb.close() / sb.go(paneId)

   render() est rappelée à chaque affichage du pane (données fraîches).
   ===================================================================== */
(function (w, d) {
    'use strict';

    function DBBuilderSidebar(opts) {
        opts = opts || {};
        this.title = opts.title || 'Personnalisation';
        this.subtitle = opts.subtitle || '';
        this.menu = opts.menu || [];
        this.active = null;
        this.opened = false;
        this._build();
    }

    DBBuilderSidebar.prototype._build = function () {
        var self = this;
        this.root = d.createElement('div');
        this.root.className = 'db-sb-root';
        this.root.innerHTML =
            '<div class="db-sb-scrim"></div>' +
            '<aside class="db-sb-panel" role="dialog" aria-modal="true">' +
              '<div class="db-sb-head">' +
                '<div class="db-sb-head-ico"><i class="fa-solid fa-wand-magic-sparkles"></i></div>' +
                '<div class="db-sb-head-txt"><div class="db-sb-title">' + this.title + '</div>' +
                  '<div class="db-sb-sub">' + this.subtitle + '</div></div>' +
                '<button class="db-sb-close" type="button"><i class="fa-solid fa-xmark"></i></button>' +
              '</div>' +
              '<div class="db-sb-nav"></div>' +
              '<div class="db-sb-body"></div>' +
            '</aside>';
        d.body.appendChild(this.root);

        this.scrim = this.root.querySelector('.db-sb-scrim');
        this.nav = this.root.querySelector('.db-sb-nav');
        this.body = this.root.querySelector('.db-sb-body');

        this.scrim.addEventListener('click', function () { self.close(); });
        this.root.querySelector('.db-sb-close').addEventListener('click', function () { self.close(); });
        this._esc = function (e) { if (e.key === 'Escape') self.close(); };

        this.menu.forEach(function (m) {
            var b = d.createElement('button');
            b.type = 'button';
            b.className = 'db-sb-nav-item';
            b.setAttribute('data-pane', m.id);
            b.innerHTML = '<i class="fa-solid fa-' + m.icon + '"></i><span>' + m.label + '</span>' +
                          '<i class="fa-solid fa-chevron-right db-sb-nav-caret"></i>';
            b.addEventListener('click', function () { self.go(m.id); });
            self.nav.appendChild(b);
        });
    };

    DBBuilderSidebar.prototype._menuDef = function (id) {
        for (var i = 0; i < this.menu.length; i++) if (this.menu[i].id === id) return this.menu[i];
        return null;
    };

    DBBuilderSidebar.prototype.go = function (paneId) {
        var def = this._menuDef(paneId);
        if (!def) return;
        this.active = paneId;
        Array.prototype.forEach.call(this.nav.querySelectorAll('.db-sb-nav-item'), function (b) {
            b.classList.toggle('db-active', b.getAttribute('data-pane') === paneId);
        });
        this.body.innerHTML = '';
        if (typeof def.render === 'function') def.render(this.body, this);
    };

    DBBuilderSidebar.prototype.open = function (paneId) {
        var self = this;
        this.opened = true;
        this.root.classList.add('db-sb-shown');
        requestAnimationFrame(function () { self.root.classList.add('db-sb-anim'); });
        d.addEventListener('keydown', this._esc);
        this.go(paneId || (this.menu[0] && this.menu[0].id));
    };

    DBBuilderSidebar.prototype.close = function () {
        var self = this;
        this.opened = false;
        this.root.classList.remove('db-sb-anim');
        d.removeEventListener('keydown', this._esc);
        setTimeout(function () { if (!self.opened) self.root.classList.remove('db-sb-shown'); }, 280);
    };

    w.DBBuilderSidebar = DBBuilderSidebar;
})(window, document);
