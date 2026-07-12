/**
 * ══════════════════════════════════════════════════════════════════════
 *  MdtMapEngine — moteur de cartographie du Système de Cartes MDT
 *  Écrit spécifiquement pour ce module (indépendant du moteur LSPD).
 * ══════════════════════════════════════════════════════════════════════
 *
 *  Principe : une image de fond dans un plan transformé en CSS (translate+scale),
 *  un canvas overlay (hors du plan) qui redessine strokes + zones à chaque frame,
 *  des overlays DOM pour les marqueurs, les libellés de zone et la présence.
 *  Coordonnées stockées normalisées 0..1 (indépendantes de la résolution).
 *
 *  Configuration : new MdtMapEngine(cfg) où cfg =
 *    { stage, plane, img, canvas, overlay, presence,     // éléments DOM
 *      apiBase, target:{id}|{special}, background, backgroundSources,
 *      elements:[], revision, csrf, canEdit, isGang, displayName,
 *      onPointCreate(nx,ny), onPointEdit(el), onZoneCreate(points), onZoneEdit(el),
 *      onSaveState(state) }
 */
(function (global) {
    'use strict';

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function uid() { return 'l' + Math.random().toString(36).slice(2, 9); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function MdtMapEngine(cfg) {
        this.cfg = cfg || {};
        this.stage   = cfg.stage;
        this.plane   = cfg.plane;
        this.img     = cfg.img;
        this.canvas  = cfg.canvas;
        this.overlay = cfg.overlay;
        this.presEl  = cfg.presence || null;
        this.ctx     = this.canvas.getContext('2d');

        this.W = 1; this.H = 1;          // dimensions naturelles image
        this.s = 1; this.tx = 0; this.ty = 0;  // transform (px viewport)
        this.dpr = Math.min(global.devicePixelRatio || 1, 2);

        this.elements = Array.isArray(cfg.elements) ? cfg.elements.slice() : [];
        this.revision = parseInt(cfg.revision, 10) || 0;
        this.canEdit  = !!cfg.canEdit;
        this.isGang   = !!cfg.isGang;

        this.tool  = 'move';
        this.color = '#ef4444';
        this.size  = 4;

        this.panning = false; this.panStart = null;
        this.drawing = false; this.curStroke = null;
        this.pointDown = null;
        this.zonePts = [];               // polygone en cours (normalisé)
        this.norm = { nx: 0.5, ny: 0.5 };

        this._raf = null; this._pollT = null; this._presT = null;
        this._pending = 0;               // sauvegardes en vol (évite le flicker au polling)
        this._presList = [];
        this._ready = false;
        this._userAdjusted = false;      // vue recadrée automatiquement tant que l'utilisateur n'a pas agi
    }

    /* ── Dimensions du viewport ── */
    MdtMapEngine.prototype._vp = function () {
        var r = this.stage.getBoundingClientRect();
        return { w: Math.max(r.width, 50), h: Math.max(r.height, 50) };
    };

    /* ── Source du fond ── */
    MdtMapEngine.prototype._src = function (key) {
        var s = this.cfg.backgroundSources || {};
        return s[key] || s.atlas || '';
    };

    /* ══════════════════════ init ══════════════════════ */
    MdtMapEngine.prototype.init = function () {
        var self = this;

        this.img.draggable = false;
        this.img.addEventListener('dragstart', function (e) { e.preventDefault(); });
        this.stage.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        this.img.onload = function () {
            self.W = self.img.naturalWidth || 1;
            self.H = self.img.naturalHeight || 1;
            self.plane.style.width = self.W + 'px';
            self.plane.style.height = self.H + 'px';
            self._resizeCanvas();
            self._fit();
            self._ready = true;
            self.rebuildOverlays();
            self.draw();
        };
        this.img.src = this._src(this.cfg.background || 'atlas');

        /* Redimensionnement */
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(function () {
                if (!self._ready) return;
                self._resizeCanvas();
                if (!self._userAdjusted) self._fit();   // recadre tant que l'utilisateur n'a pas déplacé/zoomé
                self.layoutOverlays();
                self.draw();
            }).observe(this.stage);
        }

        this._bindPointer();
        this._bindKeys();

        /* Collaboration / persistance temps réel */
        this._startCollab();
        return this;
    };

    /* ── Transform du plan + overlays ── */
    MdtMapEngine.prototype._applyTransform = function () {
        this.plane.style.transform = 'translate(' + this.tx + 'px,' + this.ty + 'px) scale(' + this.s + ')';
        this.layoutOverlays();
    };

    MdtMapEngine.prototype._fit = function (attempt) {
        if (this.W < 2 || this.H < 2) return;
        var vp = this._vp();
        // Le layout flex peut ne pas être encore stabilisé quand l'image se charge :
        // on réessaie tant que le viewport est trop petit (course de rendu).
        if ((vp.w < 60 || vp.h < 60) && (attempt || 0) < 30) {
            var self = this;
            requestAnimationFrame(function () { self._fit((attempt || 0) + 1); });
            return;
        }
        this.s = Math.min(vp.w / this.W, vp.h / this.H) * 0.95;
        this.tx = (vp.w - this.W * this.s) / 2;
        this.ty = (vp.h - this.H * this.s) / 2;
        this._applyTransform();
    };

    MdtMapEngine.prototype._resizeCanvas = function () {
        var vp = this._vp();
        this.dpr = Math.min(global.devicePixelRatio || 1, 2);
        var pw = Math.max(1, Math.round(vp.w * this.dpr));
        var ph = Math.max(1, Math.round(vp.h * this.dpr));
        if (this.canvas.width !== pw)  this.canvas.width = pw;
        if (this.canvas.height !== ph) this.canvas.height = ph;
        this.canvas.style.width = vp.w + 'px';
        this.canvas.style.height = vp.h + 'px';
    };

    /* ── Conversions ── */
    MdtMapEngine.prototype._toNorm = function (clientX, clientY) {
        var r = this.stage.getBoundingClientRect();
        var mx = clientX - r.left, my = clientY - r.top;
        return {
            nx: (mx - this.tx) / this.s / this.W,
            ny: (my - this.ty) / this.s / this.H,
            mx: mx, my: my
        };
    };
    MdtMapEngine.prototype._normToScreen = function (nx, ny) {
        return { x: this.tx + nx * this.W * this.s, y: this.ty + ny * this.H * this.s };
    };

    /* ══════════════════════ Rendu ══════════════════════ */
    MdtMapEngine.prototype.draw = function () {
        var self = this;
        if (this._raf) return;
        this._raf = requestAnimationFrame(function () {
            self._raf = null;
            self._render();
        });
    };

    MdtMapEngine.prototype._render = function () {
        var ctx = this.ctx, dpr = this.dpr;
        if (!ctx) return;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        /* Zones (sous les traits) */
        for (var z = 0; z < this.elements.length; z++) {
            if (this.elements[z].type === 'zone') this._drawZone(ctx, this.elements[z]);
        }
        if (this.tool === 'zone' && this.zonePts.length) this._drawZoneInProgress(ctx);

        /* Traits */
        for (var i = 0; i < this.elements.length; i++) {
            if (this.elements[i].type === 'stroke') this._drawStroke(ctx, this.elements[i]);
        }
        if (this.curStroke) this._drawStroke(ctx, this.curStroke);

        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
    };

    MdtMapEngine.prototype._drawStroke = function (ctx, s) {
        var pts = s.points; if (!pts || pts.length < 1) return;
        var dpr = this.dpr, W = this.W, H = this.H, sc = this.s, tx = this.tx, ty = this.ty;
        ctx.beginPath();
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        var w = (s.width || 3) * sc;
        if (s.tool === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.strokeStyle = 'rgba(0,0,0,1)';
            ctx.globalAlpha = 1;
        } else if (s.tool === 'marker') {           // surligneur
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = s.color || '#facc15';
            ctx.globalAlpha = 0.4;
            w *= 1.7;
        } else {                                    // pinceau
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = s.color || '#ef4444';
            ctx.globalAlpha = 1;
        }
        ctx.lineWidth = Math.max(1, w * dpr);
        var p0 = this._normToScreen(pts[0][0], pts[0][1]);
        ctx.moveTo(p0.x * dpr, p0.y * dpr);
        if (pts.length === 1) { ctx.lineTo(p0.x * dpr + 0.1, p0.y * dpr + 0.1); }
        for (var i = 1; i < pts.length; i++) {
            var p = this._normToScreen(pts[i][0], pts[i][1]);
            ctx.lineTo(p.x * dpr, p.y * dpr);
        }
        ctx.stroke();
        ctx.globalAlpha = 1;
        ctx.globalCompositeOperation = 'source-over';
    };

    MdtMapEngine.prototype._zonePath = function (ctx, pts) {
        var dpr = this.dpr;
        ctx.beginPath();
        for (var i = 0; i < pts.length; i++) {
            var p = this._normToScreen(pts[i][0], pts[i][1]);
            if (i === 0) ctx.moveTo(p.x * dpr, p.y * dpr);
            else ctx.lineTo(p.x * dpr, p.y * dpr);
        }
        ctx.closePath();
    };

    MdtMapEngine.prototype._drawZone = function (ctx, z) {
        if (!z.points || z.points.length < 3) return;
        var col = z.color || '#94a3b8';
        ctx.globalCompositeOperation = 'source-over';
        this._zonePath(ctx, z.points);
        ctx.globalAlpha = 0.25; ctx.fillStyle = col; ctx.fill();
        ctx.globalAlpha = 0.95; ctx.lineWidth = 2 * this.dpr; ctx.strokeStyle = col; ctx.stroke();
        ctx.globalAlpha = 1;
    };

    MdtMapEngine.prototype._drawZoneInProgress = function (ctx) {
        var pts = this.zonePts, dpr = this.dpr;
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
        ctx.strokeStyle = this.color; ctx.lineWidth = 2 * dpr;
        ctx.setLineDash([6 * dpr, 5 * dpr]);
        ctx.beginPath();
        for (var i = 0; i < pts.length; i++) {
            var p = this._normToScreen(pts[i][0], pts[i][1]);
            if (i === 0) ctx.moveTo(p.x * dpr, p.y * dpr); else ctx.lineTo(p.x * dpr, p.y * dpr);
        }
        ctx.stroke();
        ctx.setLineDash([]);
        for (var j = 0; j < pts.length; j++) {
            var q = this._normToScreen(pts[j][0], pts[j][1]);
            ctx.beginPath();
            ctx.arc(q.x * dpr, q.y * dpr, (j === 0 ? 6 : 4) * dpr, 0, 6.2832);
            ctx.fillStyle = j === 0 ? '#fff' : this.color; ctx.fill();
        }
    };

    /* ══════════════════════ Overlays DOM (marqueurs + libellés zone) ══════════════════════ */
    MdtMapEngine.prototype.rebuildOverlays = function () {
        var self = this;
        this.overlay.innerHTML = '';
        this.elements.forEach(function (el) {
            if (el.type === 'marker') self._addMarkerDom(el);
            else if (el.type === 'zone') self._addZoneLabelDom(el);
        });
        this.layoutOverlays();
    };

    MdtMapEngine.prototype._addMarkerDom = function (el) {
        var self = this;
        var d = document.createElement('div');
        d.className = 'map-marker';
        d.dataset.id = el.id;
        d.innerHTML = '<span class="mk-pin" style="background:' + esc(el.color || '#ef4444') + '"></span>' +
            (el.title ? '<span class="mk-label">' + esc(el.title) + '</span>' : '');
        d.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        d.addEventListener('click', function (e) {
            e.stopPropagation();
            if (self.cfg.onPointEdit && self.canEdit) self.cfg.onPointEdit(self._byId(el.id));
        });
        this.overlay.appendChild(d);
    };

    MdtMapEngine.prototype._addZoneLabelDom = function (el) {
        var self = this;
        var c = this._centroid(el.points);
        var d = document.createElement('div');
        d.className = 'zone-hotspot';
        d.dataset.id = el.id;
        d.innerHTML = '<span class="mk-label" style="border-color:' + esc(el.color || '#94a3b8') +
            ';background:rgba(3,8,15,.85)">' + esc(el.title || 'Zone') + '</span>';
        d.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        d.addEventListener('contextmenu', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (self.cfg.onZoneEdit && self.canEdit) self.cfg.onZoneEdit(self._byId(el.id));
        });
        d.addEventListener('click', function (e) {
            e.stopPropagation();
            if (self.cfg.onZoneEdit && self.canEdit) self.cfg.onZoneEdit(self._byId(el.id));
        });
        this.overlay.appendChild(d);
    };

    MdtMapEngine.prototype.layoutOverlays = function () {
        var self = this;
        this.overlay.querySelectorAll('.map-marker').forEach(function (d) {
            var el = self._byId(d.dataset.id); if (!el) return;
            var p = self._normToScreen(el.x, el.y);
            d.style.left = p.x + 'px'; d.style.top = p.y + 'px';
        });
        this.overlay.querySelectorAll('.zone-hotspot').forEach(function (d) {
            var el = self._byId(d.dataset.id); if (!el) return;
            var c = self._centroid(el.points);
            var p = self._normToScreen(c[0], c[1]);
            d.style.left = p.x + 'px'; d.style.top = p.y + 'px';
        });
    };

    MdtMapEngine.prototype._centroid = function (pts) {
        var x = 0, y = 0;
        for (var i = 0; i < pts.length; i++) { x += pts[i][0]; y += pts[i][1]; }
        return [x / pts.length, y / pts.length];
    };
    MdtMapEngine.prototype._byId = function (id) {
        for (var i = 0; i < this.elements.length; i++) {
            if (String(this.elements[i].id) === String(id)) return this.elements[i];
        }
        return null;
    };

    /* ══════════════════════ Interactions pointeur ══════════════════════ */
    MdtMapEngine.prototype._bindPointer = function () {
        var self = this;

        this.stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            var n = self._toNorm(e.clientX, e.clientY);
            var factor = e.deltaY > 0 ? 0.9 : 1.1;
            self._zoomAt(n.mx, n.my, factor);
        }, { passive: false });

        this.stage.addEventListener('mousedown', function (e) {
            var n = self._toNorm(e.clientX, e.clientY);

            if (e.button === 2) {                          // clic droit → pan
                self.panning = true; self._userAdjusted = true;
                self.panStart = { x: e.clientX, y: e.clientY, tx: self.tx, ty: self.ty };
                return;
            }
            if (e.button !== 0) return;

            if (self.tool === 'zone' && self.canEdit && self.isGang) { self._zoneClick(n); return; }
            if (self.tool === 'point' && self.canEdit) { self.pointDown = { nx: n.nx, ny: n.ny, x: e.clientX, y: e.clientY }; return; }

            if (self.canEdit && (self.tool === 'pen' || self.tool === 'marker' || self.tool === 'eraser')) {
                self.drawing = true; self._userAdjusted = true;
                self.curStroke = { id: uid(), type: 'stroke', tool: self.tool, color: self.color, width: self.size,
                    points: [[clamp(n.nx, -0.05, 1.05), clamp(n.ny, -0.05, 1.05)]] };
                self.draw();
                return;
            }
            if (self.tool === 'move') {                    // pan au clic gauche en mode déplacement
                self.panning = true; self._userAdjusted = true;
                self.panStart = { x: e.clientX, y: e.clientY, tx: self.tx, ty: self.ty };
            }
        });

        window.addEventListener('mousemove', function (e) {
            var n = self._toNorm(e.clientX, e.clientY);
            self.norm.nx = clamp(n.nx, 0, 1); self.norm.ny = clamp(n.ny, 0, 1);

            if (self.panning && self.panStart) {
                self.tx = self.panStart.tx + (e.clientX - self.panStart.x);
                self.ty = self.panStart.ty + (e.clientY - self.panStart.y);
                self._applyTransform(); self.draw();
                return;
            }
            if (self.drawing && self.curStroke && (e.buttons & 1)) {
                var last = self.curStroke.points[self.curStroke.points.length - 1];
                var nx = clamp(n.nx, -0.05, 1.05), ny = clamp(n.ny, -0.05, 1.05);
                if ((nx - last[0]) * (nx - last[0]) + (ny - last[1]) * (ny - last[1]) < 2e-7) return;
                self.curStroke.points.push([nx, ny]);
                self.draw();
            }
        });

        window.addEventListener('mouseup', function (e) {
            if (e.button === 2 || self.tool === 'move') { self.panning = false; self.panStart = null; }
            if (e.button !== 0) return;

            if (self.pointDown) {                          // outil "Plan" : pose d'un point au clic
                var moved = Math.abs(e.clientX - self.pointDown.x) + Math.abs(e.clientY - self.pointDown.y);
                var pd = self.pointDown; self.pointDown = null;
                if (moved < 6 && self.cfg.onPointCreate) {
                    self.cfg.onPointCreate(clamp(pd.nx, 0, 1), clamp(pd.ny, 0, 1));
                }
                return;
            }
            if (self.drawing) {
                self.drawing = false;
                var st = self.curStroke; self.curStroke = null;
                if (st && st.points.length >= 1) { self.elements.push(st); self.draw(); self._persistCreate('stroke', st, st); }
            }
        });
    };

    MdtMapEngine.prototype._zoneClick = function (n) {
        var np = [clamp(n.nx, 0, 1), clamp(n.ny, 0, 1)];
        if (this.zonePts.length >= 3) {
            var first = this._normToScreen(this.zonePts[0][0], this.zonePts[0][1]);
            if (Math.abs(first.x - n.mx) < 12 && Math.abs(first.y - n.my) < 12) { this._closeZone(); return; }
        }
        this.zonePts.push(np);
        this.draw();
    };

    MdtMapEngine.prototype._closeZone = function () {
        if (this.zonePts.length < 3) { this.zonePts = []; this.draw(); return; }
        var pts = this.zonePts.slice();
        this.zonePts = [];
        this.draw();
        if (this.cfg.onZoneCreate) this.cfg.onZoneCreate(pts);
    };

    MdtMapEngine.prototype._bindKeys = function () {
        var self = this;
        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { self.zonePts = []; self.draw(); }
            else if ((e.key === 'Enter') && self.tool === 'zone' && self.zonePts.length >= 3) self._closeZone();
        });
        this.stage.addEventListener('dblclick', function () {
            if (self.tool === 'zone' && self.zonePts.length >= 3) self._closeZone();
        });
    };

    /* ══════════════════════ Zoom ══════════════════════ */
    MdtMapEngine.prototype._zoomAt = function (mx, my, factor) {
        this._userAdjusted = true;
        var wx = (mx - this.tx) / this.s, wy = (my - this.ty) / this.s;
        var ns = clamp(this.s * factor, 0.03, 40);
        this.tx = mx - wx * ns; this.ty = my - wy * ns; this.s = ns;
        this._applyTransform(); this.draw();
    };
    MdtMapEngine.prototype.zoomIn  = function () { var v = this._vp(); this._zoomAt(v.w / 2, v.h / 2, 1.2); };
    MdtMapEngine.prototype.zoomOut = function () { var v = this._vp(); this._zoomAt(v.w / 2, v.h / 2, 1 / 1.2); };
    MdtMapEngine.prototype.resetView = function () { this._fit(); this.draw(); };

    /* ══════════════════════ Outils / état ══════════════════════ */
    MdtMapEngine.prototype.setTool = function (t) {
        this.tool = t;
        if (t !== 'zone') { this.zonePts = []; this.draw(); }
        var cur = 'default';
        if (t === 'pen' || t === 'marker' || t === 'eraser' || t === 'zone') cur = 'crosshair';
        else if (t === 'point') cur = 'cell';
        else if (t === 'move') cur = 'grab';
        this.stage.style.cursor = cur;
    };
    MdtMapEngine.prototype.setColor = function (c) { this.color = c; };
    MdtMapEngine.prototype.setSize  = function (s) { this.size = Math.max(1, Math.min(64, parseInt(s, 10) || 4)); };

    /* ══════════════════════ Persistance (API) ══════════════════════ */
    MdtMapEngine.prototype._target = function () {
        var t = this.cfg.target || {};
        return t.special ? { special: t.special } : { map_id: t.id };
    };
    MdtMapEngine.prototype._api = function (action, payload) {
        var body = Object.assign({ csrf: this.cfg.csrf }, this._target(), payload || {});
        return fetch(this.cfg.apiBase + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body), credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    };
    MdtMapEngine.prototype._saveState = function (st) { if (this.cfg.onSaveState) this.cfg.onSaveState(st); };

    /** Crée un élément côté serveur puis fixe son id réel sur l'objet local. */
    MdtMapEngine.prototype._persistCreate = function (type, dataObj, localRef) {
        var self = this;
        this._pending++; this._saveState('saving');
        var data = this._elementData(type, dataObj);
        this._api('save_element', { type: type, data: data }).then(function (j) {
            self._pending = Math.max(0, self._pending - 1);
            if (j.ok) {
                if (localRef) localRef.id = j.data.id;
                // Marqueurs / zones : rafraîchir le DOM pour que dataset.id porte l'id serveur
                // (sinon layoutOverlays ne repositionne plus l'élément au pan/zoom).
                if (type === 'marker' || type === 'zone') self.rebuildOverlays();
                self.revision = j.data.revision;
                self._saveState('saved');
            } else { self._saveState('error'); }
        }).catch(function () { self._pending = Math.max(0, self._pending - 1); self._saveState('error'); });
    };

    MdtMapEngine.prototype._elementData = function (type, o) {
        if (type === 'stroke') return { tool: o.tool, color: o.color, width: o.width, points: o.points };
        if (type === 'marker') return { x: o.x, y: o.y, title: o.title, color: o.color };
        if (type === 'zone')   return { points: o.points, title: o.title, color: o.color };
        return {};
    };

    /* Marqueurs (points) */
    MdtMapEngine.prototype.createPoint = function (nx, ny, title, color) {
        var el = { id: uid(), type: 'marker', x: nx, y: ny, title: title || '', color: color || '#ef4444' };
        this.elements.push(el); this._addMarkerDom(el); this.layoutOverlays();
        this._persistCreate('marker', el, el);
    };
    MdtMapEngine.prototype.updatePoint = function (id, title, color) {
        var el = this._byId(id); if (!el) return;
        el.title = title; el.color = color;
        this.rebuildOverlays();
        this._persistUpdate(id, 'marker', el);
    };
    MdtMapEngine.prototype.deletePoint = function (id) { this._deleteElement(id); };

    /* Zones */
    MdtMapEngine.prototype.createZone = function (points, title, color) {
        var el = { id: uid(), type: 'zone', points: points, title: title || 'Zone', color: color || '#94a3b8' };
        this.elements.push(el); this._addZoneLabelDom(el); this.layoutOverlays(); this.draw();
        this._persistCreate('zone', el, el);
    };
    MdtMapEngine.prototype.updateZone = function (id, title, color) {
        var el = this._byId(id); if (!el) return;
        el.title = title; el.color = color;
        this.rebuildOverlays(); this.draw();
        this._persistUpdate(id, 'zone', el);
    };
    MdtMapEngine.prototype.deleteZone = function (id) { this._deleteElement(id); };

    MdtMapEngine.prototype._persistUpdate = function (id, type, el) {
        var self = this;
        if (String(id).charAt(0) === 'l') return; // pas encore d'id serveur
        this._pending++; this._saveState('saving');
        this._api('update_element', { element_id: id, data: this._elementData(type, el) }).then(function (j) {
            self._pending = Math.max(0, self._pending - 1);
            if (j.ok) { self.revision = j.data.revision; self._saveState('saved'); } else self._saveState('error');
        }).catch(function () { self._pending = Math.max(0, self._pending - 1); self._saveState('error'); });
    };

    MdtMapEngine.prototype._deleteElement = function (id) {
        var self = this;
        this.elements = this.elements.filter(function (e) { return String(e.id) !== String(id); });
        this.rebuildOverlays(); this.draw();
        if (String(id).charAt(0) === 'l') return;
        this._pending++; this._saveState('saving');
        this._api('delete_element', { element_id: id }).then(function (j) {
            self._pending = Math.max(0, self._pending - 1);
            if (j.ok) { self.revision = j.data.revision; self._saveState('saved'); } else self._saveState('error');
        }).catch(function () { self._pending = Math.max(0, self._pending - 1); self._saveState('error'); });
    };

    /* ══════════════════════ Collaboration (polling + présence) ══════════════════════ */
    MdtMapEngine.prototype._startCollab = function () {
        var self = this;
        var t = this.cfg.target || {};
        var q = t.special ? ('special=' + encodeURIComponent(t.special)) : ('id=' + encodeURIComponent(t.id));

        function pollMs() { return document.hidden ? 3000 : 1300; }

        function poll() {
            fetch(self.cfg.apiBase + '?action=poll&' + q + '&since=' + self.revision + '&_=' + Date.now(),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) return;
                    var d = j.data;
                    if (d.revision > self.revision && self._pending === 0 && Array.isArray(d.elements)) {
                        self.revision = d.revision;
                        // conserver un tracé local en cours de dessin
                        self.elements = d.elements;
                        self.rebuildOverlays();
                        self.draw();
                    } else if (d.revision > self.revision && self._pending === 0) {
                        self.revision = d.revision;
                    }
                    self._presList = Array.isArray(d.presence) ? d.presence : [];
                    self._renderPresence();
                }).catch(function () {});
        }
        function sendPresence() {
            self._api('presence', { nx: self.norm.nx, ny: self.norm.ny }).catch(function () {});
        }

        poll();
        this._pollT = setInterval(poll, pollMs());
        // relance l'intervalle si la visibilité change (cadence adaptative)
        document.addEventListener('visibilitychange', function () {
            clearInterval(self._pollT); self._pollT = setInterval(poll, pollMs()); poll();
        });
        sendPresence();
        this._presT = setInterval(sendPresence, 900);
    };

    MdtMapEngine.prototype._renderPresence = function () {
        if (!this.presEl) return;
        var self = this;
        this.presEl.innerHTML = '';
        this._presList.forEach(function (p) {
            if (p.self) return;
            var s = self._normToScreen(p.nx, p.ny);
            var hue = (parseInt(p.user_id, 10) * 47) % 360;
            var d = document.createElement('div');
            d.className = 'pres-cursor';
            d.style.left = s.x + 'px'; d.style.top = s.y + 'px';
            d.innerHTML = '<span class="pres-dot" style="background:hsl(' + hue + ',70%,55%)"></span>' +
                '<span class="pres-name">' + esc(p.display_name) + '</span>';
            self.presEl.appendChild(d);
        });
    };

    global.MdtMapEngine = MdtMapEngine;
})(window);
