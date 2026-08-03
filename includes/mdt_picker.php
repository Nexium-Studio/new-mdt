<?php
/**
 * includes/mdt_picker.php
 * ─────────────────────────────────────────────────────────────────────────
 * Sélecteur de personnes / véhicules réutilisable — extrait du composant
 * « SamsPicker » du panel SAMS (pages/sams/includes/components.php) et rendu
 * neutre au thème afin d'être partagé par les autres panels (LSPD, etc.).
 *
 * Comportement IDENTIQUE à SamsPicker : recherche/filtre 100 % côté client,
 * mono OU multi-sélection, fermeture via ✕ / clic backdrop / Échap.
 *
 * ⚠️ On extrait uniquement le PICKER — jamais tout components.php — car son
 * `SamsSelect` remplace tous les <select> natifs et entrerait en conflit avec
 * le DojSelect déjà chargé sur les pages LSPD.
 *
 * Usage :
 *   require_once ROOT_PATH . '/includes/mdt_picker.php';   // une fois, dans le <body>
 *
 *   // API bas niveau (identique à SamsPicker.open)
 *   ⚠️ Noms de globals UNIQUES (MdtEntityPicker / mdtMultiField / mdtEntityMono) pour
 *   éviter toute collision avec assets/js/mdt-inputs.js, qui définit son propre
 *   window.MdtPicker et window.mdtPersonField (chargé APRÈS via le footer).
 *
 *   MdtEntityPicker.open({ title, items:[{id,name,sub,avatar,search}], multiple, selected:[ids], onConfirm });
 *
 *   // Champ MULTI : rend des chips + un <input hidden name="NAME[]"> par sélection
 *   const f = mdtMultiField({ chips:'idDiv', name:'officers', items:[...], title:'…', selected:[ids], onChange:n=>{} });
 *   f.open();  f.getIds();
 *
 *   // Champ MONO : un chip + un <input hidden> unique
 *   const g = mdtEntityMono({ hidden:'idInput', chip:'idDiv', items:[...], title:'…', selected:id });
 *   g.open();
 */
if (!defined('MDT_PICKER_RENDERED')):
    define('MDT_PICKER_RENDERED', 1);
?>
<style>
.mp-ov{position:fixed;inset:0;z-index:100050;display:none;align-items:center;justify-content:center;background:rgba(3,6,15,.82);backdrop-filter:blur(6px);padding:4vh 3vw}
.mp-ov.open{display:flex}
.mp-modal{width:min(560px,96vw);max-height:88vh;display:flex;flex-direction:column;background:var(--bg-c,#0f1220);border:1px solid var(--bdr,rgba(255,255,255,.12));border-radius:14px;overflow:hidden;box-shadow:0 25px 80px rgba(0,0,0,.6)}
.mp-hd{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--bdr,rgba(255,255,255,.08))}
.mp-hd h2{margin:0;font:700 .95rem/1.2 'Montserrat','Segoe UI',sans-serif;color:var(--txt,#e2e8f0);display:flex;align-items:center;gap:.5rem}
.mp-hd h2 i{color:var(--blue-l,#60a5fa)}
.mp-close{width:30px;height:30px;border-radius:8px;border:1px solid rgba(239,68,68,.25);background:rgba(239,68,68,.12);color:#fca5a5;cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:inherit;flex-shrink:0}
.mp-search{padding:.8rem 1.1rem 0}
.mp-search-in{position:relative}
.mp-search-in i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.72rem;color:var(--txt-m,#94a3b8);pointer-events:none}
.mp-search input{width:100%;padding:.6rem .8rem .6rem 2.2rem;background:rgba(0,0,0,.25);border:1px solid var(--bdr,rgba(255,255,255,.12));border-radius:9px;color:var(--txt,#e2e8f0);font-size:.85rem;font-family:inherit}
.mp-search input:focus{outline:none;border-color:var(--blue,#2563eb)}
.mp-list{flex:1;overflow-y:auto;padding:.7rem 1.1rem;display:flex;flex-direction:column;gap:.3rem;min-height:130px}
.mp-item{display:flex;align-items:center;gap:.7rem;padding:.5rem .6rem;border-radius:9px;border:1px solid transparent;cursor:pointer;transition:background .12s,border-color .12s}
.mp-item:hover{background:rgba(255,255,255,.04)}
.mp-item.selected{background:rgba(37,99,235,.12);border-color:rgba(37,99,235,.4)}
.mp-ava{width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff;background:linear-gradient(135deg,#2563eb,#1e3a8a);overflow:hidden}
.mp-ava img{width:100%;height:100%;object-fit:cover}
.mp-info{flex:1;min-width:0}
.mp-name{font-size:.85rem;font-weight:600;color:var(--txt,#e2e8f0);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mp-sub{font-size:.7rem;color:var(--txt-m,#94a3b8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mp-mark{width:22px;height:22px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.62rem;color:#fff;background:transparent;border:1.5px solid var(--bdr,rgba(255,255,255,.18));transition:all .12s}
.mp-item.selected .mp-mark{background:var(--blue,#2563eb);border-color:var(--blue,#2563eb)}
.mp-empty{text-align:center;padding:2rem 1rem;color:var(--txt-m,#94a3b8);font-size:.82rem}
.mp-empty i{display:block;font-size:1.4rem;opacity:.4;margin-bottom:.5rem}
.mp-ft{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.8rem 1.1rem;border-top:1px solid var(--bdr,rgba(255,255,255,.08))}
.mp-count{font-size:.75rem;color:var(--txt-m,#94a3b8)}
.mp-count b{color:var(--blue-l,#60a5fa)}
.mp-ft-btns{display:flex;gap:.5rem}
/* Chips (champ de formulaire) */
.mp-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.4rem}
.mp-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .6rem;border-radius:7px;font-size:.74rem;font-weight:600;background:rgba(37,99,235,.12);border:1px solid rgba(37,99,235,.3);color:var(--blue-xl,#93c5fd)}
.mp-chip .x{cursor:pointer;opacity:.7;font-size:.7rem;display:inline-flex}
.mp-chip .x:hover{opacity:1;color:#fca5a5}
.mp-trigger{width:100%}
</style>

<div class="mp-ov" id="mdtPickerOv">
  <div class="mp-modal">
    <div class="mp-hd"><h2><i class="fas fa-user-magnifying-glass"></i> <span id="mpTitle">Sélection</span></h2>
      <button type="button" class="mp-close" onclick="MdtEntityPicker.close()"><i class="fas fa-times"></i></button></div>
    <div class="mp-search"><div class="mp-search-in"><i class="fas fa-search"></i><input type="text" id="mpSearch" placeholder="Rechercher..." autocomplete="off"></div></div>
    <div class="mp-list" id="mpList"></div>
    <div class="mp-ft"><div class="mp-count" id="mpCount"></div>
      <div class="mp-ft-btns">
        <button type="button" class="btn btn-ghost btn-sm" onclick="MdtEntityPicker.close()">Annuler</button>
        <button type="button" class="btn btn-primary btn-sm" id="mpConfirm"><i class="fas fa-check"></i> Valider</button>
      </div></div>
  </div>
</div>

<script>
// ══ MdtEntityPicker : sélecteur réutilisable (extrait de SamsPicker) ═════════
window.MdtEntityPicker = (function () {
  const ov = document.getElementById('mdtPickerOv');
  const listEl = document.getElementById('mpList');
  const searchEl = document.getElementById('mpSearch');
  const countEl = document.getElementById('mpCount');
  const titleEl = document.getElementById('mpTitle');
  const confirmBtn = document.getElementById('mpConfirm');
  let items = [], selected = new Set(), multiple = false, onConfirm = null;

  function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render(filter) {
    const q = (filter || '').toLowerCase().trim();
    const shown = items.filter(it => !q || (it.search || (it.name + ' ' + (it.sub || ''))).toLowerCase().includes(q));
    if (!shown.length) {
      listEl.innerHTML = '<div class="mp-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div>';
    } else {
      listEl.innerHTML = shown.map(it => {
        const sel = selected.has(String(it.id)) ? ' selected' : '';
        const ava = it.avatar
          ? `<img src="${esc(it.avatar)}" alt="" referrerpolicy="no-referrer">`
          : (it.name ? esc(it.name.trim().charAt(0).toUpperCase()) : '?');
        return `<div class="mp-item${sel}" data-id="${esc(String(it.id))}">
            <div class="mp-ava">${ava}</div>
            <div class="mp-info">
              <div class="mp-name">${esc(it.name || '—')}</div>
              ${it.sub ? `<div class="mp-sub">${esc(it.sub)}</div>` : ''}
            </div>
            <div class="mp-mark"><i class="fas fa-check"></i></div>
          </div>`;
      }).join('');
      listEl.querySelectorAll('.mp-item').forEach(el => el.addEventListener('click', () => toggle(el.dataset.id)));
    }
    updateCount();
  }
  function toggle(id) {
    id = String(id);
    if (multiple) { selected.has(id) ? selected.delete(id) : selected.add(id); render(searchEl.value); }
    else { selected = new Set([id]); confirmSelection(); }
  }
  function updateCount() {
    countEl.innerHTML = multiple ? `<b>${selected.size}</b> sélectionné(s)` : '';
    confirmBtn.style.display = multiple ? '' : 'none';
  }
  function confirmSelection() {
    const chosen = items.filter(it => selected.has(String(it.id)));
    close();
    if (typeof onConfirm === 'function') onConfirm(multiple ? chosen : (chosen[0] || null));
  }
  function open(cfg) {
    items = cfg.items || [];
    multiple = !!cfg.multiple;
    onConfirm = cfg.onConfirm || null;
    selected = new Set((cfg.selected || []).map(String));
    titleEl.textContent = cfg.title || 'Sélection';
    searchEl.value = '';
    render('');
    ov.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => searchEl.focus(), 80);
  }
  function close() { ov.classList.remove('open'); document.body.style.overflow = ''; }

  searchEl.addEventListener('input', () => render(searchEl.value));
  confirmBtn.addEventListener('click', confirmSelection);
  ov.addEventListener('click', e => { if (e.target === ov) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('open')) close(); });

  return { open, close };
})();

// ══ Champ MULTI : chips + <input hidden name="NAME[]"> par sélection ═════════
window.mdtMultiField = function (cfg) {
  const chips = document.getElementById(cfg.chips);
  if (!chips) return null;
  const items = cfg.items || [];
  const name = cfg.name;
  const sel = new Set((cfg.selected || []).map(String));
  function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render() {
    chips.innerHTML = '';
    items.filter(it => sel.has(String(it.id))).forEach(it => {
      const span = document.createElement('span');
      span.className = 'mp-chip';
      span.innerHTML = esc(it.name) + ' <span class="x"><i class="fas fa-times"></i></span>'
                     + `<input type="hidden" name="${esc(name)}[]" value="${esc(String(it.id))}">`;
      span.querySelector('.x').onclick = () => { sel.delete(String(it.id)); render(); };
      chips.appendChild(span);
    });
    if (typeof cfg.onChange === 'function') cfg.onChange(sel.size);
  }
  const api = {
    open: () => MdtEntityPicker.open({
      title: cfg.title || 'Sélection', items, multiple: true, selected: [...sel],
      onConfirm: (arr) => { sel.clear(); (arr || []).forEach(it => sel.add(String(it.id))); render(); }
    }),
    getIds: () => [...sel],
    getItems: () => items.filter(it => sel.has(String(it.id))),
    render
  };
  render();
  return api;
};

// ══ Champ MONO : chip + input caché unique (name="NAME") ═════════════════════
window.mdtEntityMono = function (cfg) {
  const hid = document.getElementById(cfg.hidden);
  const chip = document.getElementById(cfg.chip);
  if (!hid || !chip) return null;
  const items = cfg.items || [];
  function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render(it) {
    if (!it) { chip.innerHTML = ''; hid.value = ''; return; }
    chip.innerHTML = '<span class="mp-chip">' + esc(it.name) + ' <span class="x"><i class="fas fa-times"></i></span></span>';
    chip.querySelector('.x').onclick = () => render(null);
    hid.value = it.id;
  }
  if (cfg.selected) { const it = items.find(x => String(x.id) === String(cfg.selected)); if (it) render(it); }
  return {
    open: () => MdtEntityPicker.open({ title: cfg.title || 'Sélection', items, onConfirm: it => { if (it) render(it); } }),
    set: render, get: () => hid.value
  };
};
</script>
<?php endif; ?>
