/**
 * ══════════════════════════════════════════════════════════════════
 *  LSPD — Éditeur de carte collaboratif (v8 STABLE)
 * ══════════════════════════════════════════════════════════════════
 *
 *  API d'instanciation :
 *    new LspdMapEditor({
 *      vpId:     'mv-vp',       // ID du viewport (position:relative + taille)
 *      planeId:  'mv-plane',    // ID du div transformé (contient l'image)
 *      imgId:    'mv-img',      // ID de l'image de fond
 *      cvId:     'mv-cv',       // ID du canvas de dessin (HORS du plan)
 *      presId:   'mv-po',       // ID de l'overlay présence (optionnel)
 *      zinId:    'mvZi',        // ID bouton zoom +
 *      zoutId:   'mvZo',        // ID bouton zoom -
 *      collab:   true,          // Active le polling serveur
 *      onCommit: function(s){}, // Callback quand un tracé est terminé
 *    });
 *
 *  Lit window.LSPD_MAP_CONFIG pour :
 *    mapSources, map_layer, strokes, viewport, revision,
 *    ajaxBase, csrf, mapId, displayName
 *
 *  Logs : toutes les opérations critiques loggent dans console.
 * ══════════════════════════════════════════════════════════════════
 */
(function (G) {
  'use strict';

  var LOG_PREFIX = '[LSPD Map]';
  function log(msg, extra) {
    if (G.console) {
      if (extra !== undefined) console.log(LOG_PREFIX, msg, extra);
      else                     console.log(LOG_PREFIX, msg);
    }
  }
  function warn(msg, extra) {
    if (G.console) {
      if (extra !== undefined) console.warn(LOG_PREFIX, msg, extra);
      else                     console.warn(LOG_PREFIX, msg);
    }
  }
  function err(msg, extra) {
    if (G.console) {
      if (extra !== undefined) console.error(LOG_PREFIX, msg, extra);
      else                     console.error(LOG_PREFIX, msg);
    }
  }

  function CFG() { return G.LSPD_MAP_CONFIG || {}; }
  function byId(id) { return document.getElementById(id); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function ajax(url, opts) {
    return fetch(url, opts).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error(j.error || r.statusText);
        return j;
      });
    });
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ══════════════════════════════════════════
     Constructeur
     ══════════════════════════════════════════ */
  function LspdMapEditor(o) {
    this.opts  = o || {};
    this.vp    = null;  // viewport (position:relative)
    this.plane = null;  // div transformé (contient l'image)
    this.img   = null;  // img element
    this.cv    = null;  // canvas de dessin
    this.ctx   = null;
    this.pres  = null;  // overlay présence (optionnel)

    this.W  = 1;  this.H  = 1;   // taille image
    this.s  = 1;                  // scale
    this.tx = 0;  this.ty = 0;   // translation
    this.dpr = 1;

    this.brush   = false;
    this.strokes = [];
    this.drawing = false;
    this.panning = false;
    this.panStart = null;
    this.cur     = null;
    this.tool    = 'pen';
    this.color   = '#ef4444';
    this.lw      = 3;
    this.norm    = { nx: 0.5, ny: 0.5 };
    this.rev     = 0;

    this._pt = null; this._prt = null;  // timers polling + presence
    this._raf = null;
    this._layer = 'atlas';
    this._plist = []; this._praf = null;
    this._lastSrc = ''; this._fbIdx = 0;
    this._vis = null; this._ro = null;
    this._pre = {};

    this._ready = false;  // flag : init terminé avec succès
  }

  /* ── Dimensions viewport (clientWidth en priorité) ── */
  LspdMapEditor.prototype._sz = function () {
    if (!this.vp) return { w: 800, h: 600 };
    var cw = this.vp.clientWidth  || 0;
    var ch = this.vp.clientHeight || 0;
    if (cw < 10 || ch < 10) {
      var r = this.vp.getBoundingClientRect();
      cw = r.width  || cw;
      ch = r.height || ch;
    }
    return { w: Math.max(cw, 50), h: Math.max(ch, 50) };
  };

  /* ── Sources image ── */
  LspdMapEditor.prototype._src = function (mode) {
    var s = CFG().mapSources || {};
    if (s[mode]) return s[mode];
    var names = { atlas: 'Atlas.webp', road: 'Road.webp', satellite: 'Satellite.webp' };
    return G.location.origin + '/assets/img_map/' + (names[mode] || names.atlas);
  };

  /* ── Préchargement des 3 fonds ── */
  LspdMapEditor.prototype._preload = function () {
    var self = this;
    ['atlas', 'road', 'satellite'].forEach(function (m) {
      if (self._pre[m]) return;
      var i = new Image();
      i.decoding = 'async';
      i.onload = function () { self._pre[m] = true; log('Layer preloaded:', m); };
      i.onerror = function () { warn('Preload failed:', m); };
      i.src = self._src(m);
    });
  };

  /* ── RAF redraw ── */
  LspdMapEditor.prototype._draw = function () {
    var self = this;
    if (self._raf) cancelAnimationFrame(self._raf);
    self._raf = requestAnimationFrame(function () {
      self._raf = null;
      self.redraw();
    });
  };

  /* ── Transform plan ── */
  LspdMapEditor.prototype._tf = function () {
    if (!this.plane) return;
    this.plane.style.transform = 'translate(' + this.tx + 'px,' + this.ty + 'px) scale(' + this.s + ')';
    this.plane.style.transformOrigin = '0 0';
    this._refreshPresence();
  };

  /* ── Fit view ── */
  LspdMapEditor.prototype._fit = function (attempt) {
    var self = this;
    var sz = self._sz();
    if ((sz.w < 10 || sz.h < 10) && (attempt || 0) < 40) {
      requestAnimationFrame(function () { self._fit((attempt || 0) + 1); });
      return;
    }
    if (self.W < 2 || self.H < 2) return;
    self.s  = Math.min(sz.w / self.W, sz.h / self.H) * 0.97;
    self.tx = (sz.w - self.W * self.s) / 2;
    self.ty = (sz.h - self.H * self.s) / 2;
    log('Fit view', { w: self.W, h: self.H, scale: self.s, tx: self.tx, ty: self.ty, vp: sz });
    self._tf();
    self._rs();
    self._draw();
  };

  /* ── Resize canvas (viewport × DPR, cap 2) ── */
  LspdMapEditor.prototype._rs = function () {
    if (!this.cv) return;
    var sz  = this._sz();
    var dpr = Math.min(G.devicePixelRatio || 1, 2);
    var pw  = Math.max(1, Math.round(sz.w * dpr));
    var ph  = Math.max(1, Math.round(sz.h * dpr));
    this.dpr = dpr;
    if (this.cv.width === pw && this.cv.height === ph) return;
    this.cv.width  = pw;
    this.cv.height = ph;
  };

  /* ── Chargement image ── */
  LspdMapEditor.prototype._load = function (src) {
    var self = this;
    log('Loading image:', src);
    self._lastSrc = src;

    self.img.onload = function () {
      var nw = self.img.naturalWidth  | 0;
      var nh = self.img.naturalHeight | 0;
      log('Image loaded', { w: nw, h: nh });
      if (nw < 2 || nh < 2) { self._fb(); return; }
      self._dec(nw, nh);
    };
    self.img.onerror = function () {
      warn('Image load failed:', src);
      self._fb();
    };

    var cur = self.img.getAttribute('src') || '';
    if (cur !== src) self.img.src = src;

    if (self.img.complete) {
      var nw = self.img.naturalWidth  | 0;
      var nh = self.img.naturalHeight | 0;
      if (nw >= 2 && nh >= 2) self._dec(nw, nh);
    }
  };

  LspdMapEditor.prototype._fb = function () {
    var self = this;
    var layer = self._layer || 'atlas';
    var b = G.location.origin + '/assets/img_map/';
    var map = {
      atlas:     [b + 'Atlas.webp', b + 'Aatlas.webp', b + 'Atlas.png', b + 'atlas.webp'],
      road:      [b + 'Road.webp',  b + 'Road.png',   b + 'road.webp', b + 'road.png'],
      satellite: [b + 'Satellite.webp', b + 'Satellite.jpg', b + 'satellite.webp', b + 'Satelite.jpg'],
    };
    var list = (map[layer] || map.atlas).filter(function (u) { return u !== self._lastSrc; });
    if (self._fbIdx >= list.length) {
      err('All fallbacks exhausted for layer', layer);
      return;
    }
    self._load(list[self._fbIdx++]);
  };

  LspdMapEditor.prototype._dec = function (nw, nh) {
    var self = this;
    var c = CFG();
    var pW = self.W, pH = self.H;
    self.W = nw; self.H = nh;
    self.plane.style.width  = nw + 'px';
    self.plane.style.height = nh + 'px';

    var vp = c.viewport;
    var ok = vp && typeof vp.scale === 'number' && isFinite(vp.scale) &&
             Math.abs((vp.mapW || 0) - nw) <= 2 && Math.abs((vp.mapH || 0) - nh) <= 2;
    var same = pW > 8 && Math.abs(pW - nw) <= 2 && Math.abs(pH - nh) <= 2;

    if (ok) {
      self.s  = clamp(vp.scale, 0.02, 32);
      self.tx = vp.translateX;
      self.ty = vp.translateY;
      var sz = self._sz();
      var good = self.tx + self.W * self.s > 0 && self.ty + self.H * self.s > 0 &&
                 self.tx < sz.w && self.ty < sz.h && self.s > 0.001;
      if (!good) { self._fit(); return; }
      self._tf(); self._rs(); self._draw();
    } else if (same) {
      self._tf(); self._rs(); self._draw();
    } else {
      self._fit();
    }

    self._ready = true;
  };

  /* ── Coordonnées ── */
  LspdMapEditor.prototype._w2s = function (cx, cy) {
    var r = this.vp.getBoundingClientRect();
    var mx = cx - r.left, my = cy - r.top;
    return { wx: (mx - this.tx) / this.s, wy: (my - this.ty) / this.s, mx: mx, my: my };
  };

  LspdMapEditor.prototype._norm = function (cx, cy) {
    var w = this._w2s(cx, cy);
    this.norm.nx = clamp(w.wx / this.W, 0, 1);
    this.norm.ny = clamp(w.wy / this.H, 0, 1);
  };

  /* ── Tracés ── */
  LspdMapEditor.prototype._pts = function (s) {
    if (s.points && Array.isArray(s.points) && s.points.length) return s.points;
    if (s.latlngs && Array.isArray(s.latlngs) && s.latlngs.length >= 2) {
      var a = s.latlngs[0];
      if (Array.isArray(a) && typeof a[0] === 'number' && a[0] >= -0.2 && a[0] <= 1.2)
        return s.latlngs;
    }
    return null;
  };

  /* ── Rendu (coordonnées viewport) ── */
  LspdMapEditor.prototype._drawOne = function (ctx, s) {
    var pts = this._pts(s);
    if (!pts || pts.length < 2) return;
    var tx = this.tx, ty = this.ty, sc = this.s;
    var W = this.W, H = this.H, dpr = this.dpr || 1;
    ctx.beginPath();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = Math.max(1, (s.width || 3) * sc * dpr);
    if (s.tool === 'eraser') {
      ctx.globalCompositeOperation = 'destination-out';
      ctx.strokeStyle = 'rgba(0,0,0,1)';
    } else {
      ctx.globalCompositeOperation = 'source-over';
      ctx.strokeStyle = s.color || '#fff';
    }
    ctx.moveTo((tx + pts[0][0] * W * sc) * dpr, (ty + pts[0][1] * H * sc) * dpr);
    for (var i = 1; i < pts.length; i++)
      ctx.lineTo((tx + pts[i][0] * W * sc) * dpr, (ty + pts[i][1] * H * sc) * dpr);
    ctx.stroke();
  };

  LspdMapEditor.prototype.redraw = function () {
    var self = this;
    if (!self.ctx) return;
    if (!self.cv.width || !self.cv.height) {
      self._rs();
      if (!self.cv.width) return;
    }
    var ctx = self.ctx;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, self.cv.width, self.cv.height);
    self.strokes.forEach(function (s) { self._drawOne(ctx, s); });
    if (self.cur && self.cur.points && self.cur.points.length > 1)
      self._drawOne(ctx, self.cur);
    ctx.globalCompositeOperation = 'source-over';
  };

  /* ══════════════════════════════════════════
     init()
     ══════════════════════════════════════════ */
  LspdMapEditor.prototype.init = function () {
    var self = this;
    var c = CFG();

    log('Initializing editor…', c);

    self.vp    = byId(self.opts.vpId);
    self.plane = byId(self.opts.planeId);
    self.img   = byId(self.opts.imgId);
    self.cv    = byId(self.opts.cvId);
    self.pres  = self.opts.presId ? byId(self.opts.presId) : null;

    var missing = [];
    if (!self.vp)    missing.push('vp (' + self.opts.vpId + ')');
    if (!self.plane) missing.push('plane (' + self.opts.planeId + ')');
    if (!self.img)   missing.push('img (' + self.opts.imgId + ')');
    if (!self.cv)    missing.push('cv (' + self.opts.cvId + ')');
    if (missing.length) {
      err('Éléments DOM manquants :', missing.join(', '));
      throw new Error('LspdMapEditor: éléments manquants — ' + missing.join(', '));
    }

    self.ctx   = self.cv.getContext('2d');
    self._layer = c.map_layer || 'atlas';
    self.rev    = parseInt(c.revision, 10) || 0;
    self._fbIdx = 0;

    self.img.ondragstart = function (e) { e.preventDefault(); return false; };
    self.img.draggable = false;
    self.vp.addEventListener('dragstart', function (e) { e.preventDefault(); }, true);
    self.vp.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    /* Chargement image */
    self._load(self._src(self._layer));
    self._preload();

    /* ResizeObserver pour adapter le canvas */
    if (typeof ResizeObserver !== 'undefined') {
      self._ro = new ResizeObserver(function () {
        if (self.W < 2) return;
        self._rs();
        self._draw();
      });
      self._ro.observe(self.vp);
    }

    /* Molette zoom */
    self.vp.addEventListener('wheel', function (e) {
      e.preventDefault();
      var w  = self._w2s(e.clientX, e.clientY);
      var s2 = clamp(self.s * (e.deltaY > 0 ? 0.92 : 1.08), 0.02, 32);
      self.tx = w.mx - w.wx * s2;
      self.ty = w.my - w.wy * s2;
      self.s  = s2;
      self._tf(); self._draw();
    }, { passive: false });

    /* Mousemove */
    self.vp.addEventListener('mousemove', function (e) {
      self._norm(e.clientX, e.clientY);
      if (self.panning && self.panStart) {
        self.tx = self.panStart.tx + (e.clientX - self.panStart.x);
        self.ty = self.panStart.ty + (e.clientY - self.panStart.y);
        self._tf(); self._draw();
      }
      if (self.drawing && self.cur && self.brush && e.buttons === 1) {
        var u = self._w2s(e.clientX, e.clientY);
        var nx = clamp(u.wx / self.W, -0.02, 1.02);
        var ny = clamp(u.wy / self.H, -0.02, 1.02);
        var L = self.cur.points[self.cur.points.length - 1];
        if ((nx - L[0]) * (nx - L[0]) + (ny - L[1]) * (ny - L[1]) < 1e-8) return;
        self.cur.points.push([nx, ny]);
        self._draw();
      }
    });

    /* Mousedown */
    self.vp.addEventListener('mousedown', function (e) {
      if (e.target.closest && (
          e.target.closest('#mv-toolbar') ||
          e.target.closest('.mv-zoom')    ||
          e.target.closest('.mv-hint'))) return;

      if (e.button === 2) {
        e.preventDefault();
        self.panning = true;
        self.panStart = { x: e.clientX, y: e.clientY, tx: self.tx, ty: self.ty };
        if (typeof e.pointerId === 'number') {
          try { self.vp.setPointerCapture(e.pointerId); } catch (_) {}
        }
        return;
      }
      if (e.button !== 0) return;
      e.preventDefault();
      if (!self.brush) return;
      if (typeof e.pointerId === 'number') {
        try { self.vp.setPointerCapture(e.pointerId); } catch (_) {}
      }
      var u = self._w2s(e.clientX, e.clientY);
      self.drawing = true;
      self.cur = {
        id:     't' + Math.random().toString(36).slice(2),
        tool:   self.tool,
        color:  self.color,
        width:  self.lw,
        points: [[clamp(u.wx / self.W, -0.02, 1.02), clamp(u.wy / self.H, -0.02, 1.02)]],
      };
    });

    /* Mouseup global */
    G.addEventListener('mouseup', function (e) {
      if (typeof e.pointerId === 'number' && self.vp) {
        try {
          if (self.vp.hasPointerCapture && self.vp.hasPointerCapture(e.pointerId))
            self.vp.releasePointerCapture(e.pointerId);
        } catch (_) {}
      }
      if (e.button === 2) { self.panning = false; self.panStart = null; }
      if (e.button !== 0 || !self.drawing) return;
      self.drawing = false;
      if (self.cur && self.cur.points && self.cur.points.length > 1) {
        var m = {
          id:     self.cur.id,
          tool:   self.cur.tool,
          color:  self.cur.color,
          width:  self.cur.width,
          points: self.cur.points.slice(),
        };
        self.strokes.push(m);
        self.cur = null;
        self._draw();
        if (self.opts.onCommit) self.opts.onCommit(m);
      } else {
        self.cur = null;
        self._draw();
      }
    });

    /* Zoom UI */
    var zi = byId(self.opts.zinId), zo = byId(self.opts.zoutId);
    var zf = function (f) {
      return function (e) {
        e.preventDefault();
        var sz = self._sz();
        var cx = sz.w / 2, cy = sz.h / 2;
        var wx = (cx - self.tx) / self.s, wy = (cy - self.ty) / self.s;
        var s2 = clamp(self.s * f, 0.02, 32);
        self.tx = cx - wx * s2;
        self.ty = cy - wy * s2;
        self.s  = s2;
        self._tf(); self._draw();
      };
    };
    if (zi) zi.addEventListener('click', zf(1.15));
    if (zo) zo.addEventListener('click', zf(1 / 1.15));

    if (c.strokes && Array.isArray(c.strokes)) {
      self.strokes = c.strokes;
      log('Loaded strokes:', c.strokes.length);
    }

    if (self.opts.collab && c.mapId && c.ajaxBase) {
      self._startCollab();
    }

    log('Editor initialized successfully');
  };

  /* ── setBrush ── */
  LspdMapEditor.prototype.setBrush = function (on) {
    this.brush = !!on;
    if (this.cv) this.cv.style.pointerEvents = 'none';
    if (this.vp) this.vp.style.cursor = this.brush ? 'crosshair' : 'default';
  };

  /* ── setLayerMode ── */
  LspdMapEditor.prototype.setLayerMode = function (mode) {
    log('Switch layer to', mode);
    this._layer = mode;
    CFG().map_layer = mode;
    this._fbIdx = 0;
    this._load(this._src(mode));
  };

  /* ── Getters ── */
  LspdMapEditor.prototype.getViewport = function () {
    return {
      scale: this.s, translateX: this.tx, translateY: this.ty,
      mapW: this.W, mapH: this.H,
    };
  };

  /* ══════════════════════════════════════════
     Collaboration (polling)
     ══════════════════════════════════════════ */
  function pMs()    { return document.hidden ? 2200 : 500; }
  function pollMs() { return (document.hidden ? 2800 : 1000) + Math.floor(Math.random() * 200); }

  LspdMapEditor.prototype._startCollab = function () {
    var self = this, c = CFG();
    log('Starting collaboration polling');

    function doPoll() {
      ajax(c.ajaxBase + 'map_poll.php?id=' + encodeURIComponent(c.mapId) + '&_=' + Date.now(),
           { credentials: 'same-origin' })
        .then(function (j) {
          if (!j.ok) return;
          var jr = parseInt(j.revision, 10) || 0;
          if (jr > self.rev) {
            self.rev = jr;
            c.revision = jr;
            self.strokes = Array.isArray(j.strokes) ? j.strokes : [];
            self._draw();
          }
          self._plist = Array.isArray(j.presence) ? j.presence : [];
          self._renderPresence(self._plist);
        })
        .catch(function (e) { warn('Poll error:', e.message); });
    }
    function armPoll() {
      if (self._pt) clearInterval(self._pt);
      self._pt = setInterval(doPoll, pollMs());
    }
    doPoll(); armPoll();

    function sendPresence() {
      fetch(c.ajaxBase + 'map_presence.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          map_id:       c.mapId,
          lat:          self.norm.nx,
          lng:          self.norm.ny,
          display_name: (c.displayName && c.displayName.trim()) || 'Agent',
          csrf:         c.csrf,
        }),
        credentials: 'same-origin',
      }).catch(function (e) { warn('Presence send failed:', e.message); });
    }
    function armPresence() {
      if (self._prt) clearInterval(self._prt);
      self._prt = setInterval(sendPresence, pMs());
    }
    sendPresence(); armPresence();

    self._vis = function () { doPoll(); sendPresence(); armPoll(); armPresence(); };
    document.addEventListener('visibilitychange', self._vis);
  };

  LspdMapEditor.prototype.stopCollab = function () {
    if (this._pt)  clearInterval(this._pt);
    if (this._prt) clearInterval(this._prt);
    this._pt = this._prt = null;
    if (this._vis) {
      document.removeEventListener('visibilitychange', this._vis);
      this._vis = null;
    }
    if (this._ro) { this._ro.disconnect(); this._ro = null; }
  };

  /* ── Présence / curseurs ── */
  LspdMapEditor.prototype._refreshPresence = function () {
    var self = this;
    if (!self.pres || self._praf) return;
    self._praf = requestAnimationFrame(function () {
      self._praf = null;
      self._renderPresence(self._plist);
    });
  };

  LspdMapEditor.prototype._renderPresence = function (list) {
    if (!this.pres) return;
    this.pres.innerHTML = '';
    var self = this;
    list.forEach(function (p) {
      if (p.self) return;
      var nx = parseFloat(p.lat), ny = parseFloat(p.lng);
      if (isNaN(nx) || isNaN(ny)) return;
      var hue = (parseInt(p.user_id, 10) * 47) % 360;
      var d = document.createElement('div');
      d.style.cssText =
        'position:absolute;left:' + (self.tx + nx * self.W * self.s) +
        'px;top:' + (self.ty + ny * self.H * self.s) +
        'px;transform:translate(-6px,-6px);pointer-events:none;z-index:520';
      d.innerHTML =
        '<span style="display:block;width:12px;height:12px;border-radius:50%;' +
        'background:hsl(' + hue + ',70%,55%);border:2px solid #fff;box-shadow:0 0 6px rgba(0,0,0,.5)"></span>' +
        '<span style="position:absolute;left:16px;top:-16px;white-space:nowrap;' +
        'background:rgba(3,8,15,.95);border:1px solid #1e293b;padding:2px 7px;' +
        'border-radius:6px;font-size:.62rem;color:#93c5fd;font-weight:600">' +
        esc(p.display_name || '') + '</span>';
      self.pres.appendChild(d);
    });
  };

  /* ── Push tracé serveur ── */
  LspdMapEditor.prototype.push = function (stroke) {
    var self = this, c = CFG();
    if (!c.mapId || !c.ajaxBase) {
      warn('push(): mapId ou ajaxBase manquant'); return Promise.resolve();
    }
    return ajax(c.ajaxBase + 'map_append_stroke.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ map_id: c.mapId, stroke: stroke, csrf: c.csrf }),
      credentials: 'same-origin',
    }).then(function (j) {
      var jr = parseInt(j.revision, 10) || 0;
      if (jr) { self.rev = jr; c.revision = jr; }
      return j;
    });
  };

  G.LspdMapEditor = LspdMapEditor;
  log('LspdMapEditor class ready');

})(typeof window !== 'undefined' ? window : this);