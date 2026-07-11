/* ════════════════════════════════════════════════════════════════════════
 * MDT Inputs — composants d'input unifiés pour le panel LSPD MDT
 *   • mdtPersonField()  : sélecteur citoyen (recherche + avatar + chip)
 *   • [data-mdt-date]   : date picker custom (calendrier modal, thème LSPD)
 *   • [data-mdt-time]   : time picker custom (heures / minutes)
 * Self-contained : injecte son propre CSS, s'appuie sur les variables LSPD
 * (--blue, --bg-e, --txt, …) définies dans pages/lspd/includes/header.php.
 * ════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__mdtInputsLoaded) return;
  window.__mdtInputsLoaded = true;

  function esc(s){ const d=document.createElement('div'); d.textContent = s==null?'':s; return d.innerHTML; }
  function pad(n){ return String(n).padStart(2,'0'); }

  // ── CSS injecté ──────────────────────────────────────────────────────────
  const css = `
  .mdt-ov{position:fixed;inset:0;background:rgba(0,0,0,.86);backdrop-filter:blur(7px);display:none;align-items:center;justify-content:center;z-index:100000;padding:1.5rem}
  .mdt-ov.open{display:flex}
  .mdt-pop{background:linear-gradient(140deg,var(--bg-e,#0a1020),var(--bg,#03080f));border:1px solid rgba(37,99,235,.3);border-radius:15px;box-shadow:0 0 70px rgba(0,0,0,.7);width:92%;max-width:430px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;animation:mdtPop .22s cubic-bezier(.34,1.56,.64,1)}
  @keyframes mdtPop{from{transform:translateY(14px) scale(.97);opacity:0}}
  .mdt-pop-hd{padding:1rem 1.2rem;border-bottom:1px solid var(--bdr,rgba(37,99,235,.14));display:flex;align-items:center;justify-content:space-between;background:rgba(37,99,235,.04)}
  .mdt-pop-hd h3{font-family:'Syne',serif;font-size:.92rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.55rem;margin:0}
  .mdt-pop-hd h3 i{color:var(--blue-l,#60a5fa)}
  .mdt-pop-x{width:27px;height:27px;border-radius:7px;background:rgba(37,99,235,.08);border:1px solid var(--bdr,rgba(37,99,235,.14));color:var(--txt-s,#94a3b8);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.95rem;transition:all .16s}
  .mdt-pop-x:hover{background:rgba(239,68,68,.15);color:#fca5a5;transform:rotate(90deg)}
  .mdt-pop-bd{padding:1rem 1.2rem;overflow-y:auto}
  /* search */
  .mdt-search{display:flex;align-items:center;gap:.5rem;padding:.55rem .8rem;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:8px;margin-bottom:.8rem}
  .mdt-search i{color:var(--txt-s,#94a3b8);font-size:.8rem}
  .mdt-search input{flex:1;background:none;border:none;outline:none;color:var(--txt,#e8edf5);font-family:inherit;font-size:.84rem}
  .mdt-plist{display:flex;flex-direction:column;gap:.3rem;max-height:48vh;overflow-y:auto}
  .mdt-pitem{display:flex;align-items:center;gap:.65rem;padding:.5rem .65rem;border-radius:9px;border:1px solid transparent;cursor:pointer;transition:all .14s}
  .mdt-pitem:hover{background:rgba(37,99,235,.09);border-color:rgba(37,99,235,.25)}
  .mdt-pitem.sel{background:rgba(37,99,235,.14);border-color:rgba(37,99,235,.4)}
  .mdt-pava{width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#0a1020}
  .mdt-pava-ph{width:34px;height:34px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-weight:700;color:#fff;font-size:.85rem;background:linear-gradient(135deg,#3b82f6,#1e40af)}
  .mdt-pinfo{flex:1;min-width:0}
  .mdt-pname{font-size:.84rem;font-weight:600;color:var(--txt,#e8edf5);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mdt-psub{font-size:.7rem;color:var(--txt-s,#94a3b8);margin-top:.1rem}
  .mdt-pempty{text-align:center;color:var(--txt-s,#94a3b8);font-size:.82rem;padding:1.4rem}
  /* chip / trigger */
  .mdt-field-chip{display:inline-flex;align-items:center;gap:.45rem;padding:.32rem .6rem .32rem .42rem;background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.3);border-radius:20px;font-size:.8rem;color:var(--txt,#e8edf5);margin-top:.4rem}
  .mdt-field-chip img{width:22px;height:22px;border-radius:50%;object-fit:cover}
  .mdt-field-chip .x{cursor:pointer;color:var(--txt-s,#94a3b8);width:18px;height:18px;display:grid;place-items:center;border-radius:50%;transition:all .15s}
  .mdt-field-chip .x:hover{background:rgba(239,68,68,.2);color:#fca5a5}
  /* date/time trigger inputs */
  .mdt-trigger{display:flex;align-items:center;gap:.5rem;width:100%;padding:.6rem .85rem;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:7px;color:var(--txt,#e8edf5);font-family:inherit;font-size:.83rem;cursor:pointer;transition:all .16s;text-align:left}
  .mdt-trigger:hover{border-color:var(--blue,#2563eb)}
  .mdt-trigger i{color:var(--blue-l,#60a5fa)}
  .mdt-trigger .mdt-trigger-val{flex:1}
  .mdt-trigger .mdt-trigger-val.empty{color:var(--txt-m,#374151)}
  /* calendar */
  .mdt-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:.7rem}
  .mdt-cal-nav button{width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.08);border:1px solid var(--bdr,rgba(37,99,235,.14));color:var(--txt,#e8edf5);cursor:pointer;transition:all .15s}
  .mdt-cal-nav button:hover{background:rgba(37,99,235,.2)}
  .mdt-cal-title{font-weight:700;font-size:.88rem;color:#fff;text-transform:capitalize}
  .mdt-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.2rem}
  .mdt-cal-dow{text-align:center;font-size:.62rem;font-weight:700;text-transform:uppercase;color:var(--txt-m,#374151);padding:.3rem 0}
  .mdt-cal-day{aspect-ratio:1;display:grid;place-items:center;border-radius:8px;font-size:.8rem;color:var(--txt,#e8edf5);cursor:pointer;border:1px solid transparent;transition:all .12s}
  .mdt-cal-day:hover{background:rgba(37,99,235,.14)}
  .mdt-cal-day.out{color:var(--txt-m,#374151);opacity:.5}
  .mdt-cal-day.today{border-color:rgba(37,99,235,.45)}
  .mdt-cal-day.sel{background:linear-gradient(135deg,var(--blue,#2563eb),#1d4ed8);color:#fff;font-weight:700}
  .mdt-cal-foot{display:flex;justify-content:space-between;gap:.5rem;margin-top:.8rem}
  .mdt-mini-btn{padding:.45rem .85rem;border-radius:7px;font-size:.78rem;font-weight:600;cursor:pointer;border:1px solid var(--bdr,rgba(37,99,235,.14));background:rgba(37,99,235,.06);color:var(--blue-xl,#bfdbfe);transition:all .15s}
  .mdt-mini-btn:hover{background:rgba(37,99,235,.16)}
  /* time */
  .mdt-time-wrap{display:flex;align-items:center;justify-content:center;gap:.8rem;padding:.6rem 0}
  .mdt-time-col{display:flex;flex-direction:column;align-items:center;gap:.4rem}
  .mdt-time-col button{width:46px;height:30px;border-radius:7px;background:rgba(37,99,235,.08);border:1px solid var(--bdr,rgba(37,99,235,.14));color:var(--txt,#e8edf5);cursor:pointer;transition:all .15s}
  .mdt-time-col button:hover{background:rgba(37,99,235,.2)}
  .mdt-time-val{width:64px;text-align:center;font-family:'IBM Plex Mono',monospace;font-size:1.7rem;font-weight:800;color:#fff;background:#020508;border:1px solid rgba(37,99,235,.2);border-radius:9px;padding:.35rem 0;outline:none}
  .mdt-time-sep{font-size:1.6rem;font-weight:800;color:var(--blue-l,#60a5fa);padding-bottom:.2rem}
  .mdt-time-lbl{font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m,#374151)}
  /* image field */
  .mdt-imgf{display:flex;flex-wrap:wrap;gap:.5rem}
  .mdt-imgf-item{position:relative;width:64px;height:64px;border-radius:9px;background:#020508 center/cover no-repeat;border:1px solid rgba(37,99,235,.25)}
  .mdt-imgf-rm{position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:#ef4444;color:#fff;display:grid;place-items:center;font-size:.6rem;cursor:pointer;border:2px solid var(--bg,#03080f)}
  .mdt-imgf-add{width:64px;height:64px;border-radius:9px;border:2px dashed rgba(37,99,235,.35);background:rgba(37,99,235,.05);color:var(--blue-l,#60a5fa);cursor:pointer;font-size:1rem;transition:all .15s}
  .mdt-imgf-add:hover{border-color:var(--blue,#2563eb);background:rgba(37,99,235,.12)}
  .mdt-tabs{display:flex;gap:.4rem;margin-bottom:.9rem;background:#020508;border:1px solid var(--bdr,rgba(37,99,235,.14));border-radius:8px;padding:.25rem}
  .mdt-tab{flex:1;padding:.45rem;text-align:center;border-radius:6px;font-size:.78rem;font-weight:600;color:var(--txt-s,#94a3b8);cursor:pointer}
  .mdt-tab.active{background:rgba(37,99,235,.16);color:var(--blue-xl,#bfdbfe)}
  .mdt-pane2{display:none}.mdt-pane2.active{display:block}
  .mdt-drop{border:2px dashed rgba(37,99,235,.3);border-radius:10px;padding:1.3rem;text-align:center;color:var(--txt-s,#94a3b8);cursor:pointer}
  .mdt-drop:hover{border-color:var(--blue,#2563eb)}
  .mdt-drop i{font-size:1.5rem;color:var(--blue-l,#60a5fa);display:block;margin-bottom:.4rem}
  .mdt-cdn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:.5rem;max-height:44vh;overflow-y:auto}
  .mdt-cdn-pick{aspect-ratio:1;border-radius:8px;background:#020508 center/cover no-repeat;border:2px solid transparent;cursor:pointer}
  .mdt-cdn-pick.sel{border-color:var(--blue,#2563eb)}
  .mdt-row-input{display:flex;gap:.5rem}
  `;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  // ── Overlay générique réutilisable ────────────────────────────────────────
  function buildOverlay(title, icon) {
    const ov = document.createElement('div'); ov.className = 'mdt-ov';
    ov.innerHTML =
      '<div class="mdt-pop">' +
        '<div class="mdt-pop-hd"><h3><i class="fas fa-' + esc(icon) + '"></i>' + esc(title) + '</h3>' +
        '<button type="button" class="mdt-pop-x"><i class="fas fa-times"></i></button></div>' +
        '<div class="mdt-pop-bd"></div>' +
      '</div>';
    document.body.appendChild(ov);
    const close = () => { ov.classList.remove('open'); document.body.style.overflow=''; };
    ov.querySelector('.mdt-pop-x').addEventListener('click', close);
    ov.addEventListener('click', e => { if (e.target === ov) close(); });
    return { ov, body: ov.querySelector('.mdt-pop-bd'), title: ov.querySelector('.mdt-pop-hd h3'),
             open(){ ov.classList.add('open'); document.body.style.overflow='hidden'; }, close };
  }
  // ESC ferme tous les overlays MDT
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.mdt-ov.open').forEach(o => { o.classList.remove('open'); });
    document.body.style.overflow = '';
  });

  /* ══ 1. Person picker ════════════════════════════════════════════════════ */
  const Picker = (function () {
    let ui = null, current = [], onPick = null;
    function ensure(){ if (!ui) ui = buildOverlay('Sélection', 'user'); return ui; }
    function render(filter) {
      const q = (filter||'').toLowerCase().trim();
      const list = current.filter(it => !q ||
        (it.name||'').toLowerCase().includes(q) || (it.sub||'').toLowerCase().includes(q));
      const html = list.length ? list.map(it => {
        const ava = it.avatar
          ? '<img class="mdt-pava" src="'+esc(it.avatar)+'" alt="">'
          : '<span class="mdt-pava-ph">'+esc((it.name||'?').charAt(0).toUpperCase())+'</span>';
        return '<div class="mdt-pitem" data-id="'+esc(it.id)+'">'+ava+
          '<div class="mdt-pinfo"><div class="mdt-pname">'+esc(it.name)+'</div>'+
          (it.sub?'<div class="mdt-psub">'+esc(it.sub)+'</div>':'')+'</div></div>';
      }).join('') : '<div class="mdt-pempty">Aucun résultat</div>';
      ui.body.querySelector('.mdt-plist').innerHTML = html;
      ui.body.querySelectorAll('.mdt-pitem').forEach(el => el.addEventListener('click', () => {
        const it = current.find(x => String(x.id) === el.dataset.id);
        if (it && onPick) onPick(it);
        ui.close();
      }));
    }
    return {
      open(cfg) {
        ensure();
        current = cfg.items || []; onPick = cfg.onConfirm || null;
        ui.title.innerHTML = '<i class="fas fa-'+esc(cfg.icon||'user')+'"></i>'+esc(cfg.title||'Sélection');
        ui.body.innerHTML =
          '<div class="mdt-search"><i class="fas fa-search"></i><input type="text" placeholder="Rechercher..."></div>'+
          '<div class="mdt-plist"></div>';
        const inp = ui.body.querySelector('.mdt-search input');
        inp.addEventListener('input', () => render(inp.value));
        render('');
        ui.open(); setTimeout(()=>inp.focus(), 50);
      }
    };
  })();
  window.MdtPicker = Picker;

  // mdtPersonField({ button, hidden, chip, title, items, required, selected, onChange })
  window.mdtPersonField = function (cfg) {
    const btn  = document.getElementById(cfg.button);
    const hid  = document.getElementById(cfg.hidden);
    const chip = document.getElementById(cfg.chip);
    if (!btn || !hid || !chip) return;
    const items = cfg.items || [];
    function render(it) {
      if (!it) { chip.innerHTML = ''; return; }
      const ava = it.avatar
        ? '<img src="'+esc(it.avatar)+'">'
        : '<span class="mdt-pava-ph" style="width:22px;height:22px;font-size:.65rem">'+esc((it.name||'?').charAt(0).toUpperCase())+'</span>';
      chip.innerHTML = '<span class="mdt-field-chip">'+ava+esc(it.name)+'<span class="x"><i class="fas fa-times"></i></span></span>';
      chip.querySelector('.x').onclick = () => { hid.value=''; chip.innerHTML=''; if (cfg.onChange) cfg.onChange(null); };
    }
    btn.addEventListener('click', () => Picker.open({
      title: cfg.title || 'Sélection citoyen', icon: cfg.icon || 'user', items,
      onConfirm: (it) => { hid.value = it.id; render(it); if (cfg.onChange) cfg.onChange(it); }
    }));
    if (cfg.selected != null) { const it = items.find(x => String(x.id) === String(cfg.selected)); if (it) { hid.value = it.id; render(it); } }
    if (cfg.required) {
      const form = hid.closest('form');
      if (form) form.addEventListener('submit', e => {
        if (!hid.value) { e.preventDefault();
          if (window.LSPD && LSPD.toast) LSPD.toast('Veuillez sélectionner ' + (cfg.title?cfg.title.toLowerCase():'un élément'), 'error');
        }
      });
    }
    return {
      set: render,
      setById(id){ const it = items.find(x => String(x.id) === String(id)); if (it){ hid.value=it.id; render(it); if(cfg.onChange) cfg.onChange(it); } },
      clear(){ hid.value=''; chip.innerHTML=''; if (cfg.onChange) cfg.onChange(null); }
    };
  };

  /* ══ 2. Date picker ══════════════════════════════════════════════════════ */
  const MONTHS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
  const DOW = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
  function fmtFR(iso){ if(!iso) return ''; const [y,m,d]=iso.split('-'); return d+'/'+m+'/'+y; }

  const DateUI = (function () {
    let ui = null, view = new Date(), selected = null, target = null;
    function ensure(){ if (!ui) ui = buildOverlay('Sélection de date', 'calendar-day'); return ui; }
    function build() {
      const y = view.getFullYear(), m = view.getMonth();
      const first = new Date(y, m, 1);
      let start = (first.getDay() + 6) % 7; // Lundi = 0
      const daysInMonth = new Date(y, m+1, 0).getDate();
      const prevDays = new Date(y, m, 0).getDate();
      const todayISO = new Date().toISOString().slice(0,10);
      let cells = '';
      for (let i = start-1; i >= 0; i--) cells += '<div class="mdt-cal-day out">'+(prevDays-i)+'</div>';
      for (let d = 1; d <= daysInMonth; d++) {
        const iso = y+'-'+pad(m+1)+'-'+pad(d);
        let cls = 'mdt-cal-day';
        if (iso === todayISO) cls += ' today';
        if (iso === selected) cls += ' sel';
        cells += '<div class="'+cls+'" data-iso="'+iso+'">'+d+'</div>';
      }
      const total = start + daysInMonth; const tail = (7 - (total % 7)) % 7;
      for (let d = 1; d <= tail; d++) cells += '<div class="mdt-cal-day out">'+d+'</div>';
      ui.body.innerHTML =
        '<div class="mdt-cal-nav"><button type="button" data-nav="-1"><i class="fas fa-chevron-left"></i></button>'+
        '<div class="mdt-cal-title">'+MONTHS[m]+' '+y+'</div>'+
        '<button type="button" data-nav="1"><i class="fas fa-chevron-right"></i></button></div>'+
        '<div class="mdt-cal-grid">'+DOW.map(d=>'<div class="mdt-cal-dow">'+d+'</div>').join('')+cells+'</div>'+
        '<div class="mdt-cal-foot"><button type="button" class="mdt-mini-btn" data-act="today">Aujourd\'hui</button>'+
        '<button type="button" class="mdt-mini-btn" data-act="clear">Effacer</button></div>';
      ui.body.querySelectorAll('[data-nav]').forEach(b => b.addEventListener('click', () => { view.setMonth(view.getMonth()+parseInt(b.dataset.nav,10)); build(); }));
      ui.body.querySelectorAll('.mdt-cal-day[data-iso]').forEach(c => c.addEventListener('click', () => commit(c.dataset.iso)));
      ui.body.querySelector('[data-act="today"]').addEventListener('click', () => commit(todayISO));
      ui.body.querySelector('[data-act="clear"]').addEventListener('click', () => commit(''));
    }
    function commit(iso){ if (target) target.set(iso); ui.close(); }
    return {
      open(t) {
        ensure(); target = t; selected = t.value();
        view = selected ? new Date(selected+'T00:00:00') : new Date();
        build(); ui.open();
      }
    };
  })();

  function initDate(hidden) {
    if (hidden.dataset.mdtBound) return; hidden.dataset.mdtBound = '1';
    const trig = document.createElement('button');
    trig.type = 'button'; trig.className = 'mdt-trigger';
    trig.innerHTML = '<i class="fas fa-calendar-day"></i><span class="mdt-trigger-val"></span>';
    hidden.style.display = 'none';
    hidden.parentNode.insertBefore(trig, hidden.nextSibling);
    const valEl = trig.querySelector('.mdt-trigger-val');
    const api = {
      value(){ return hidden.value || ''; },
      set(iso){ hidden.value = iso || '';
        if (iso) { valEl.textContent = fmtFR(iso); valEl.classList.remove('empty'); }
        else { valEl.textContent = hidden.dataset.placeholder || 'Sélectionner une date'; valEl.classList.add('empty'); }
        hidden.dispatchEvent(new Event('change', { bubbles:true })); }
    };
    api.set(hidden.value);
    trig.addEventListener('click', () => DateUI.open(api));
  }

  /* ══ 3. Time picker ══════════════════════════════════════════════════════ */
  const TimeUI = (function () {
    let ui = null, h = 0, mi = 0, target = null;
    function ensure(){ if (!ui) ui = buildOverlay('Sélection de l\'heure', 'clock'); return ui; }
    function build() {
      ui.body.innerHTML =
        '<div class="mdt-time-wrap">'+
          '<div class="mdt-time-col"><button type="button" data-h="1"><i class="fas fa-chevron-up"></i></button>'+
            '<input class="mdt-time-val" data-f="h" inputmode="numeric" maxlength="2" value="'+pad(h)+'"><div class="mdt-time-lbl">Heures</div>'+
            '<button type="button" data-h="-1"><i class="fas fa-chevron-down"></i></button></div>'+
          '<div class="mdt-time-sep">:</div>'+
          '<div class="mdt-time-col"><button type="button" data-m="1"><i class="fas fa-chevron-up"></i></button>'+
            '<input class="mdt-time-val" data-f="m" inputmode="numeric" maxlength="2" value="'+pad(mi)+'"><div class="mdt-time-lbl">Minutes</div>'+
            '<button type="button" data-m="-1"><i class="fas fa-chevron-down"></i></button></div>'+
        '</div>'+
        '<div class="mdt-cal-foot"><button type="button" class="mdt-mini-btn" data-act="now">Maintenant</button>'+
        '<button type="button" class="mdt-mini-btn" data-act="ok" style="background:linear-gradient(135deg,var(--blue,#2563eb),#1d4ed8);color:#fff;border-color:transparent">Valider</button></div>';
      const hi = ui.body.querySelector('[data-f="h"]'), mIn = ui.body.querySelector('[data-f="m"]');
      ui.body.querySelectorAll('[data-h]').forEach(b => b.addEventListener('click', () => { h=(h+parseInt(b.dataset.h,10)+24)%24; hi.value=pad(h); }));
      ui.body.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => { mi=(mi+parseInt(b.dataset.m,10)+60)%60; mIn.value=pad(mi); }));
      hi.addEventListener('input', () => { let v=parseInt(hi.value.replace(/\D/g,''),10); h=isNaN(v)?0:Math.max(0,Math.min(23,v)); });
      mIn.addEventListener('input', () => { let v=parseInt(mIn.value.replace(/\D/g,''),10); mi=isNaN(v)?0:Math.max(0,Math.min(59,v)); });
      hi.addEventListener('blur', ()=>hi.value=pad(h)); mIn.addEventListener('blur', ()=>mIn.value=pad(mi));
      ui.body.querySelector('[data-act="now"]').addEventListener('click', () => { const d=new Date(); h=d.getHours(); mi=d.getMinutes(); hi.value=pad(h); mIn.value=pad(mi); });
      ui.body.querySelector('[data-act="ok"]').addEventListener('click', () => { if (target) target.set(pad(h)+':'+pad(mi)); ui.close(); });
    }
    return {
      open(t){ ensure(); target=t; const v=(t.value()||'').split(':'); h=Math.min(23,parseInt(v[0],10)||0); mi=Math.min(59,parseInt(v[1],10)||0); build(); ui.open(); }
    };
  })();

  function initTime(hidden) {
    if (hidden.dataset.mdtBound) return; hidden.dataset.mdtBound = '1';
    const trig = document.createElement('button');
    trig.type = 'button'; trig.className = 'mdt-trigger';
    trig.innerHTML = '<i class="fas fa-clock"></i><span class="mdt-trigger-val"></span>';
    hidden.style.display = 'none';
    hidden.parentNode.insertBefore(trig, hidden.nextSibling);
    const valEl = trig.querySelector('.mdt-trigger-val');
    const api = {
      value(){ return hidden.value || ''; },
      set(t){ hidden.value = t || '';
        if (t) { valEl.textContent = t; valEl.classList.remove('empty'); }
        else { valEl.textContent = hidden.dataset.placeholder || 'Sélectionner une heure'; valEl.classList.add('empty'); }
        hidden.dispatchEvent(new Event('change', { bubbles:true })); }
    };
    api.set(hidden.value);
    trig.addEventListener('click', () => TimeUI.open(api));
  }

  /* ══ 4. Image field (URL / upload / CDN) ═════════════════════════════════ */
  // mdtImageField({ container, hidden, uploadUrl, listUrl, max })
  //   uploadUrl : endpoint POST (FormData img_action=upload, file) -> {success,url}
  //   listUrl   : endpoint GET renvoyant {success,images:[{url,title}]}
  window.mdtImageField = function (cfg) {
    const cont = document.getElementById(cfg.container);
    const hid  = document.getElementById(cfg.hidden);
    if (!cont || !hid) return;
    const max  = cfg.max || 8;
    let images = [];
    try { images = JSON.parse(hid.value || '[]') || []; } catch (e) { images = []; }
    let ui = null;

    function save(){ hid.value = JSON.stringify(images); }
    function add(u){ u = (u||'').trim(); if (u && !images.includes(u) && images.length < max) { images.push(u); save(); render(); } }
    function render(){
      cont.className = 'mdt-imgf';
      cont.innerHTML = images.map((u,i)=>'<div class="mdt-imgf-item" style="background-image:url(\''+esc(u)+'\')"><span class="mdt-imgf-rm" data-i="'+i+'"><i class="fas fa-times"></i></span></div>').join('')
        + (images.length<max ? '<button type="button" class="mdt-imgf-add"><i class="fas fa-plus"></i></button>' : '');
      cont.querySelectorAll('.mdt-imgf-rm').forEach(b => b.onclick = () => { images.splice(+b.dataset.i,1); save(); render(); });
      const a = cont.querySelector('.mdt-imgf-add'); if (a) a.onclick = openMenu;
    }

    function openMenu(){
      if (!ui) ui = buildOverlay('Ajouter une image', 'image');
      const pick = new Set();
      let cdnLoaded = false;
      const hasUpload = !!cfg.uploadUrl, hasCdn = !!cfg.listUrl;

      async function loadCdn(){
        if (!hasCdn || cdnLoaded) return; cdnLoaded = true;
        const box = ui.body.querySelector('#mdtCdnList');
        try {
          const r = await fetch(cfg.listUrl, {headers:{'X-Requested-With':'XMLHttpRequest'}});
          const d = await r.json();
          const imgs = (d.images||[]).filter(x=>x.url);
          if (!imgs.length){ box.innerHTML = '<div style="color:var(--txt-s);font-size:.8rem">Aucune image dans le CDN.</div>'; return; }
          box.innerHTML = imgs.map(im=>'<div class="mdt-cdn-pick" data-url="'+esc(im.url)+'" style="background-image:url(\''+esc(im.url)+'\')" title="'+esc(im.title||'')+'"></div>').join('');
          box.querySelectorAll('.mdt-cdn-pick').forEach(p=>p.onclick=()=>{ const u=p.dataset.url; if(pick.has(u)){pick.delete(u);p.classList.remove('sel');}else{pick.add(u);p.classList.add('sel');} });
        } catch(e){ box.innerHTML = '<div style="color:var(--red,#ef4444)">Erreur de chargement</div>'; }
      }

      ui.body.innerHTML =
        '<div class="mdt-tabs">'+
          '<div class="mdt-tab active" data-t="url">URL</div>'+
          (hasUpload?'<div class="mdt-tab" data-t="up">Upload</div>':'')+
          (hasCdn?'<div class="mdt-tab" data-t="cdn">CDN</div>':'')+
        '</div>'+
        '<div class="mdt-pane2 active" data-p="url"><div class="mdt-row-input"><input type="url" class="form-input" id="mdtImgUrl" placeholder="https://..."><button type="button" class="mdt-mini-btn" id="mdtImgUrlBtn">Ajouter</button></div></div>'+
        (hasUpload?'<div class="mdt-pane2" data-p="up"><div class="mdt-drop" id="mdtImgDrop"><i class="fas fa-cloud-arrow-up"></i>Choisir un fichier (max 8 Mo)</div><input type="file" id="mdtImgFile" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none"><div id="mdtImgUpMsg" style="font-size:.78rem;color:var(--txt-s);margin-top:.5rem"></div></div>':'')+
        (hasCdn?'<div class="mdt-pane2" data-p="cdn"><div id="mdtCdnList" class="mdt-cdn-grid"><div style="color:var(--txt-s);font-size:.8rem">Chargement...</div></div><div style="text-align:right;margin-top:.7rem"><button type="button" class="mdt-mini-btn" id="mdtCdnAddBtn">Ajouter la sélection</button></div></div>':'');

      ui.body.querySelectorAll('.mdt-tab').forEach(t => t.onclick = () => {
        ui.body.querySelectorAll('.mdt-tab').forEach(x=>x.classList.toggle('active',x===t));
        ui.body.querySelectorAll('.mdt-pane2').forEach(p=>p.classList.toggle('active',p.dataset.p===t.dataset.t));
        if (t.dataset.t==='cdn') loadCdn();
      });
      ui.body.querySelector('#mdtImgUrlBtn').onclick = () => { const i=ui.body.querySelector('#mdtImgUrl'); add(i.value); i.value=''; ui.close(); };

      if (hasUpload) {
        const fi = ui.body.querySelector('#mdtImgFile');
        ui.body.querySelector('#mdtImgDrop').onclick = () => fi.click();
        fi.onchange = async () => {
          const f = fi.files[0]; if (!f) return;
          const msg = ui.body.querySelector('#mdtImgUpMsg'); msg.textContent = 'Envoi...';
          const fd = new FormData(); fd.append('img_action','upload'); fd.append('file',f);
          try {
            const r = await fetch(cfg.uploadUrl, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
            const d = await r.json();
            if (d.success) { add(d.url); ui.close(); } else msg.textContent = d.error || 'Erreur';
          } catch(e){ msg.textContent = 'Erreur réseau'; }
        };
      }
      if (hasCdn) ui.body.querySelector('#mdtCdnAddBtn').onclick = () => { pick.forEach(u=>add(u)); ui.close(); };

      ui.open();
      setTimeout(()=>{ const u=ui.body.querySelector('#mdtImgUrl'); if(u) u.focus(); }, 50);
    }

    render();
    return { get(){ return images.slice(); }, set(arr){ images = Array.isArray(arr)?arr.slice():[]; save(); render(); } };
  };

  // ── Auto-init ──────────────────────────────────────────────────────────────
  function autoInit(root) {
    (root||document).querySelectorAll('input[data-mdt-date]').forEach(initDate);
    (root||document).querySelectorAll('input[data-mdt-time]').forEach(initTime);
  }
  window.MdtInputs = { autoInit, initDate, initTime };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => autoInit());
  else autoInit();
})();
