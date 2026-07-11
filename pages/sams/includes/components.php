<?php
/**
 * Composants réutilisables du panel SAMS
 * pages/sams/includes/components.php
 *
 * À inclure une fois par page (avant le footer). Fournit, en pur JS (aucun
 * élément natif Google/HTML basique) :
 *   - SamsPicker.open({...})  : sélecteur de personnes (patients / médecins /
 *                               civils) avec barre de recherche + liste de
 *                               cartes, mono ou multi-sélection.
 *   - SamsConfirm({...})      : fenêtre de confirmation personnalisée
 *                               (remplace window.confirm()).
 *
 * Dépend de showToast()/openModal() définis dans includes/footer.php — mais
 * fonctionne aussi de façon autonome.
 */
?>
<style>
/* ══ Sélecteur de personnes (SamsPicker) ══════════════════════════════════════ */
.sp-ov { position:fixed; inset:0; background:rgba(0,0,0,.9); backdrop-filter:blur(12px);
  z-index:10000; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.sp-ov.open { display:flex; }
.sp-modal { background:linear-gradient(145deg,var(--bg-e,#110c18),var(--bg-c,#0c0810));
  border:1px solid var(--bdr-h,rgba(225,29,72,.32)); border-radius:14px; width:100%; max-width:560px;
  max-height:86vh; display:flex; flex-direction:column; overflow:hidden;
  box-shadow:0 0 60px rgba(0,0,0,.8); animation:sp-in .22s cubic-bezier(.34,1.56,.64,1); }
@keyframes sp-in { from{opacity:0;transform:scale(.94) translateY(12px)} to{opacity:1;transform:none} }
.sp-hd { padding:1.05rem 1.3rem; border-bottom:1px solid var(--bdr,rgba(225,29,72,.12));
  display:flex; align-items:center; justify-content:space-between;
  background:linear-gradient(90deg,rgba(225,29,72,.06),transparent); }
.sp-hd h2 { font-family:'Rajdhani',sans-serif; font-size:.95rem; font-weight:700; color:#fff;
  text-transform:uppercase; letter-spacing:.06em; display:flex; align-items:center; gap:.55rem; margin:0; }
.sp-hd h2 i { color:var(--red-l,#fb7185); }
/* bouton de fermeture custom */
.sp-close { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,.05);
  border:1px solid var(--bdr,rgba(225,29,72,.12)); color:var(--txt-s,#9c8a90); cursor:pointer;
  display:grid; place-items:center; font-size:.95rem; transition:all .17s; }
.sp-close:hover { background:rgba(225,29,72,.18); color:var(--red-l,#fb7185); transform:rotate(90deg); }
.sp-search { padding:.9rem 1.3rem; border-bottom:1px solid var(--bdr,rgba(225,29,72,.12)); position:relative; }
.sp-search i { position:absolute; left:1.85rem; top:50%; transform:translateY(-50%);
  color:var(--txt-m,#5c4a52); font-size:.8rem; pointer-events:none; }
.sp-search input { width:100%; padding:.6rem .9rem .6rem 2.3rem; background:rgba(225,29,72,.04);
  border:1px solid var(--bdr,rgba(225,29,72,.15)); border-radius:9px; color:var(--txt,#f0e6e8);
  font-family:'Barlow',sans-serif; font-size:.85rem; outline:none; transition:all .17s; }
.sp-search input:focus { border-color:var(--red,#e11d48); box-shadow:0 0 0 3px rgba(225,29,72,.12); }
.sp-list { overflow-y:auto; padding:.6rem; flex:1; display:flex; flex-direction:column; gap:.4rem; }
.sp-item { display:flex; align-items:center; gap:.8rem; padding:.65rem .8rem; border-radius:10px;
  border:1px solid transparent; cursor:pointer; transition:all .14s; background:rgba(255,255,255,.015); }
.sp-item:hover { background:rgba(225,29,72,.07); border-color:var(--bdr,rgba(225,29,72,.18)); }
.sp-item.selected { background:rgba(225,29,72,.12); border-color:var(--red,#e11d48); }
.sp-ava { width:38px; height:38px; border-radius:10px; flex-shrink:0; overflow:hidden;
  background:linear-gradient(135deg,rgba(225,29,72,.25),rgba(159,18,57,.12));
  border:1px solid rgba(225,29,72,.25); display:grid; place-items:center;
  font-family:'Rajdhani',sans-serif; font-weight:700; color:var(--red-l,#fb7185); font-size:.85rem; }
.sp-ava img { width:100%; height:100%; object-fit:cover; }
.sp-info { flex:1; min-width:0; }
.sp-name { font-weight:700; font-size:.88rem; color:var(--txt,#f0e6e8); }
.sp-sub { font-size:.72rem; color:var(--txt-m,#9c8a90); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* indicateur de sélection custom (jamais de checkbox native) */
.sp-mark { width:22px; height:22px; border-radius:7px; flex-shrink:0; border:2px solid var(--bdr-h,rgba(225,29,72,.32));
  display:grid; place-items:center; color:#fff; font-size:.65rem; transition:all .14s; }
.sp-item.selected .sp-mark { background:var(--red,#e11d48); border-color:var(--red,#e11d48); }
.sp-mark i { opacity:0; transition:opacity .14s; }
.sp-item.selected .sp-mark i { opacity:1; }
.sp-empty { text-align:center; padding:2.5rem 1rem; color:var(--txt-m,#5c4a52); font-size:.82rem; }
.sp-empty i { font-size:2rem; opacity:.25; display:block; margin-bottom:.6rem; }
.sp-ft { padding:.85rem 1.3rem; border-top:1px solid var(--bdr,rgba(225,29,72,.12));
  display:flex; align-items:center; justify-content:space-between; gap:.6rem; background:rgba(0,0,0,.2); }
.sp-count { font-size:.74rem; color:var(--txt-m,#9c8a90); }
.sp-count b { color:var(--red-l,#fb7185); }
.sp-ft-btns { display:flex; gap:.5rem; }

/* ══ Confirmation custom (SamsConfirm) ════════════════════════════════════════ */
.sc-ov { position:fixed; inset:0; background:rgba(0,0,0,.9); backdrop-filter:blur(12px);
  z-index:10001; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.sc-ov.open { display:flex; }
.sc-modal { background:linear-gradient(145deg,var(--bg-e,#110c18),var(--bg-c,#0c0810));
  border:1px solid var(--bdr-h,rgba(225,29,72,.32)); border-radius:14px; width:100%; max-width:400px;
  overflow:hidden; box-shadow:0 0 60px rgba(0,0,0,.8); animation:sp-in .2s cubic-bezier(.34,1.56,.64,1); }
.sc-body { padding:1.9rem 1.5rem 1.3rem; text-align:center; }
.sc-icon { width:58px; height:58px; border-radius:50%; margin:0 auto 1.1rem; display:grid; place-items:center;
  font-size:1.4rem; background:rgba(225,29,72,.1); border:2px solid rgba(225,29,72,.3); color:var(--red-l,#fb7185);
  animation:sc-pulse 1.6s ease-in-out infinite; }
@keyframes sc-pulse { 0%,100%{box-shadow:0 0 0 0 rgba(225,29,72,.4)} 50%{box-shadow:0 0 0 12px rgba(225,29,72,0)} }
.sc-title { font-family:'Rajdhani',sans-serif; font-size:1.05rem; font-weight:700; color:#fff;
  text-transform:uppercase; letter-spacing:.05em; margin-bottom:.55rem; }
.sc-msg { font-size:.85rem; color:var(--txt-s,#9c8a90); line-height:1.6; }
.sc-target { font-family:'JetBrains Mono',monospace; font-size:.78rem; color:var(--red-l,#fb7185);
  background:rgba(225,29,72,.07); border:1px solid rgba(225,29,72,.2); border-radius:6px;
  padding:.4rem .8rem; margin:.4rem auto .2rem; display:inline-block; }
.sc-ft { padding:1rem 1.5rem 1.4rem; display:flex; gap:.6rem; justify-content:center; }

/* ══ Champ "personne" (bouton + chip) lié à SamsPicker ════════════════════════ */
.sp-field-chip { display:inline-flex; align-items:center; gap:.5rem; padding:.4rem .75rem; border-radius:20px; font-size:.78rem; font-weight:600;
  background:rgba(225,29,72,.1); border:1px solid rgba(225,29,72,.28); color:var(--red-l,#fb7185); }
.sp-field-chip .x { cursor:pointer; opacity:.7; } .sp-field-chip .x:hover { opacity:1; }

/* ══ Interrupteur custom réutilisable (remplace les checkbox natives) ══════════ */
.sams-switch { display:inline-flex; align-items:center; gap:.65rem; cursor:pointer; user-select:none; }
.sams-switch .sw-track { width:44px; height:25px; border-radius:25px; background:rgba(255,255,255,.12);
  border:1px solid var(--bdr,rgba(225,29,72,.15)); position:relative; flex-shrink:0; transition:all .2s; }
.sams-switch .sw-track::before { content:''; position:absolute; left:3px; top:2px; width:19px; height:19px; border-radius:50%; background:#fff; transition:transform .2s; }
.sams-switch.on .sw-track { background:var(--red,#e11d48); border-color:var(--red,#e11d48); }
.sams-switch.on .sw-track::before { transform:translateX(19px); }
.sams-switch .sw-lbl { font-size:.83rem; color:var(--txt-s,#9c8a90); }

/* ══ Select custom (SamsSelect) — remplace les <select> natifs ════════════════ */
.ss-wrap { position:relative; width:100%; }
.ss-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.ss-control { display:flex; align-items:center; gap:.5rem; width:100%; min-height:40px; padding:.55rem .8rem;
  background:rgba(225,29,72,.04); border:1px solid var(--bdr,rgba(225,29,72,.15)); border-radius:8px;
  color:var(--txt,#f0e6e8); font-family:'Barlow',sans-serif; font-size:.85rem; cursor:pointer; transition:all .15s; outline:none; position:relative; z-index:1; }
.ss-control:hover { border-color:var(--bdr-h,rgba(225,29,72,.32)); }
.ss-control:focus, .ss-wrap.open .ss-control, .dt-wrap.open .ss-control { border-color:var(--red,#e11d48); box-shadow:0 0 0 3px rgba(225,29,72,.12); background:rgba(225,29,72,.07); }
.dt-wrap.open .ss-arrow { transform:rotate(180deg); color:var(--red-l,#fb7185); }
.ss-value { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:.5rem; }
.ss-value.placeholder { color:var(--txt-m,#5c4a52); }
.ss-value .ss-ava { width:22px; height:22px; border-radius:6px; flex-shrink:0; object-fit:cover; }
.ss-arrow { font-size:.7rem; color:var(--txt-m,#9c8a90); transition:transform .2s; flex-shrink:0; }
.ss-wrap.open .ss-arrow { transform:rotate(180deg); color:var(--red-l,#fb7185); }
.ss-wrap.ss-disabled { opacity:.5; pointer-events:none; }
.ss-wrap.ss-error .ss-control { border-color:var(--red,#e11d48); box-shadow:0 0 0 3px rgba(225,29,72,.2); }
.ss-panel { position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:60;
  background:linear-gradient(145deg,var(--bg-e,#110c18),var(--bg-c,#0c0810));
  border:1px solid var(--bdr-h,rgba(225,29,72,.32)); border-radius:10px; box-shadow:0 12px 40px rgba(0,0,0,.6);
  overflow:hidden; display:none; flex-direction:column; max-height:300px; animation:ss-in .16s ease; }
.ss-wrap.open .ss-panel { display:flex; }
.ss-wrap.up .ss-panel { top:auto; bottom:calc(100% + 6px); }
@keyframes ss-in { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.ss-search { position:relative; padding:.55rem; border-bottom:1px solid var(--bdr,rgba(225,29,72,.12)); }
.ss-search i { position:absolute; left:1.05rem; top:50%; transform:translateY(-50%); color:var(--txt-m); font-size:.75rem; pointer-events:none; }
.ss-search input { width:100%; padding:.45rem .7rem .45rem 1.9rem; background:rgba(225,29,72,.04);
  border:1px solid var(--bdr,rgba(225,29,72,.15)); border-radius:7px; color:var(--txt); font-size:.82rem; outline:none; font-family:'Barlow',sans-serif; }
.ss-search input:focus { border-color:var(--red,#e11d48); }
.ss-options { overflow-y:auto; padding:.3rem; display:flex; flex-direction:column; gap:1px; }
.ss-options::-webkit-scrollbar { width:5px; }
.ss-options::-webkit-scrollbar-thumb { background:rgba(225,29,72,.35); border-radius:3px; }
.ss-group { font-family:'Barlow Condensed',sans-serif; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--txt-m); padding:.5rem .65rem .25rem; }
.ss-opt { display:flex; align-items:center; gap:.55rem; padding:.5rem .65rem; border-radius:7px; cursor:pointer; font-size:.84rem; color:var(--txt,#f0e6e8); transition:background .12s; }
.ss-opt:hover, .ss-opt.active { background:rgba(225,29,72,.1); }
.ss-opt.selected { background:rgba(225,29,72,.14); color:var(--red-l,#fb7185); font-weight:600; }
.ss-opt.disabled { opacity:.4; pointer-events:none; }
.ss-opt .ss-oico { width:22px; text-align:center; flex-shrink:0; color:var(--red-l,#fb7185); }
.ss-opt .ss-oava { width:26px; height:26px; border-radius:7px; object-fit:cover; flex-shrink:0; }
.ss-opt .ss-otext { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ss-opt .ss-check { opacity:0; color:var(--red-l,#fb7185); font-size:.75rem; flex-shrink:0; }
.ss-opt.selected .ss-check { opacity:1; }
.ss-empty { padding:1.2rem; text-align:center; color:var(--txt-m); font-size:.8rem; }

/* ══ Date / Heure custom (SamsDateTime) — remplace les inputs natifs ══════════ */
.dt-wrap { position:relative; width:100%; }
.dt-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.dt-control .dt-ico { color:var(--red-l,#fb7185); font-size:.8rem; flex-shrink:0; }
.dt-pop { position:fixed; z-index:60; background:linear-gradient(145deg,var(--bg-e,#110c18),var(--bg-c,#0c0810));
  border:1px solid var(--bdr-h,rgba(225,29,72,.32)); border-radius:12px; box-shadow:0 14px 44px rgba(0,0,0,.6);
  padding:.8rem; display:none; animation:ss-in .16s ease; width:min(92vw,300px); }
.dt-wrap.open .dt-pop { display:block; }
.dt-pop.dt-pop-dual { width:min(94vw,540px); }
.dt-row { display:flex; gap:1rem; flex-wrap:wrap; }
.dt-row > div { flex:1; min-width:230px; }
.dt-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.55rem; gap:.3rem; }
.dt-nav { width:30px; height:30px; border-radius:8px; border:1px solid var(--bdr,rgba(225,29,72,.15)); background:rgba(225,29,72,.05);
  color:var(--txt-s,#9c8a90); cursor:pointer; display:grid; place-items:center; transition:all .15s; flex-shrink:0; font-size:.7rem; }
.dt-nav:hover { background:rgba(225,29,72,.16); color:var(--red-l,#fb7185); border-color:var(--bdr-h,rgba(225,29,72,.32)); }
.dt-title { font-family:'Rajdhani',sans-serif; font-weight:700; color:#fff; font-size:.92rem; text-transform:capitalize; padding:.2rem .55rem; border-radius:6px; flex:1; text-align:center; }
.dt-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.dt-dow { text-align:center; font-size:.58rem; font-weight:700; text-transform:uppercase; color:var(--txt-m); padding:.3rem 0; }
.dt-day { aspect-ratio:1; display:grid; place-items:center; border-radius:7px; font-size:.8rem; color:var(--txt,#f0e6e8); cursor:pointer; border:1px solid transparent; transition:all .12s; }
.dt-day:hover { background:rgba(225,29,72,.12); }
.dt-day.muted { color:var(--txt-m); opacity:.35; }
.dt-day.today { border-color:var(--red,#e11d48); color:var(--red-l,#fb7185); font-weight:700; }
.dt-day.selected { background:var(--red,#e11d48); color:#fff; font-weight:700; box-shadow:0 0 12px rgba(225,29,72,.5); }
.dt-months { display:grid; grid-template-columns:repeat(3,1fr); gap:.4rem; }
.dt-month { padding:.6rem; border-radius:8px; text-align:center; font-size:.8rem; color:var(--txt,#f0e6e8); cursor:pointer; border:1px solid transparent; transition:all .12s; text-transform:capitalize; }
.dt-month:hover { background:rgba(225,29,72,.12); }
.dt-month.selected { background:var(--red,#e11d48); color:#fff; font-weight:700; }
.dt-time { display:flex; gap:.5rem; align-items:stretch; }
.dt-col { flex:1; display:flex; flex-direction:column; }
.dt-col-lbl { text-align:center; font-size:.58rem; font-weight:700; text-transform:uppercase; color:var(--txt-m); margin-bottom:.3rem; }
.dt-scroll { height:168px; overflow-y:auto; display:flex; flex-direction:column; gap:2px; padding-right:3px; }
.dt-scroll::-webkit-scrollbar { width:5px; }
.dt-scroll::-webkit-scrollbar-thumb { background:rgba(225,29,72,.35); border-radius:3px; }
.dt-tick { padding:.4rem; text-align:center; border-radius:7px; font-family:'JetBrains Mono',monospace; font-size:.82rem; color:var(--txt,#f0e6e8); cursor:pointer; border:1px solid transparent; transition:all .12s; }
.dt-tick:hover { background:rgba(225,29,72,.12); }
.dt-tick.selected { background:var(--red,#e11d48); color:#fff; font-weight:700; }
.dt-ft { display:flex; align-items:center; justify-content:space-between; gap:.6rem; margin-top:.7rem; padding-top:.6rem; border-top:1px solid var(--bdr,rgba(225,29,72,.12)); flex-wrap:wrap; }
.dt-preview { font-family:'JetBrains Mono',monospace; font-size:.78rem; color:var(--red-l,#fb7185); }
</style>

<!-- Conteneur du sélecteur de personnes -->
<div class="sp-ov" id="samsPickerOv">
  <div class="sp-modal">
    <div class="sp-hd">
      <h2><i class="fas fa-user-magnifying-glass"></i> <span id="spTitle">Sélection</span></h2>
      <button type="button" class="sp-close" onclick="SamsPicker.close()"><i class="fas fa-times"></i></button>
    </div>
    <div class="sp-search">
      <i class="fas fa-search"></i>
      <input type="text" id="spSearch" placeholder="Rechercher..." autocomplete="off">
    </div>
    <div class="sp-list" id="spList"></div>
    <div class="sp-ft">
      <div class="sp-count" id="spCount"></div>
      <div class="sp-ft-btns">
        <button type="button" class="btn btn-ghost btn-sm" onclick="SamsPicker.close()">Annuler</button>
        <button type="button" class="btn btn-primary btn-sm" id="spConfirm"><i class="fas fa-check"></i> Valider</button>
      </div>
    </div>
  </div>
</div>

<!-- Conteneur de confirmation custom -->
<div class="sc-ov" id="samsConfirmOv">
  <div class="sc-modal">
    <div class="sc-body">
      <div class="sc-icon" id="scIcon"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="sc-title" id="scTitle">Confirmer</div>
      <div id="scTargetWrap" style="display:none"><span class="sc-target" id="scTarget"></span></div>
      <div class="sc-msg" id="scMsg"></div>
    </div>
    <div class="sc-ft">
      <button type="button" class="btn btn-ghost btn-sm" id="scCancel">Annuler</button>
      <button type="button" class="btn btn-danger btn-sm" id="scOk">Confirmer</button>
    </div>
  </div>
</div>

<script>
// ══ SamsPicker : sélecteur de personnes réutilisable ════════════════════════════
window.SamsPicker = (function () {
  const ov = document.getElementById('samsPickerOv');
  const listEl = document.getElementById('spList');
  const searchEl = document.getElementById('spSearch');
  const countEl = document.getElementById('spCount');
  const titleEl = document.getElementById('spTitle');
  const confirmBtn = document.getElementById('spConfirm');
  let items = [], selected = new Set(), multiple = false, onConfirm = null;

  function render(filter) {
    const q = (filter || '').toLowerCase().trim();
    const shown = items.filter(it => !q || (it.search || (it.name + ' ' + (it.sub || ''))).toLowerCase().includes(q));
    if (!shown.length) {
      listEl.innerHTML = '<div class="sp-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div>';
    } else {
      listEl.innerHTML = shown.map(it => {
        const sel = selected.has(String(it.id)) ? ' selected' : '';
        const ava = it.avatar
          ? `<img src="${it.avatar}" alt="">`
          : (it.name ? it.name.trim().charAt(0).toUpperCase() : '?');
        return `<div class="sp-item${sel}" data-id="${it.id}">
            <div class="sp-ava">${ava}</div>
            <div class="sp-info">
              <div class="sp-name">${escapeHtml(it.name || '—')}</div>
              ${it.sub ? `<div class="sp-sub">${escapeHtml(it.sub)}</div>` : ''}
            </div>
            <div class="sp-mark"><i class="fas fa-check"></i></div>
          </div>`;
      }).join('');
      listEl.querySelectorAll('.sp-item').forEach(el => el.addEventListener('click', () => toggle(el.dataset.id)));
    }
    updateCount();
  }
  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
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

  /**
   * open({ title, items, multiple, selected, onConfirm })
   *   items    : [{ id, name, sub, avatar, search }]
   *   selected : tableau d'ids pré-sélectionnés (multi)
   *   onConfirm: callback(item)  (mono) ou callback([items]) (multi)
   */
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

// ══ SamsConfirm : confirmation personnalisée (remplace window.confirm) ═══════════
window.SamsConfirm = (function () {
  const ov = document.getElementById('samsConfirmOv');
  const okBtn = document.getElementById('scOk');
  const cancelBtn = document.getElementById('scCancel');
  let cb = null;

  function close() { ov.classList.remove('open'); document.body.style.overflow = ''; }
  /**
   * SamsConfirm({ title, message, target, okText, icon, danger=true, onConfirm })
   */
  function open(cfg) {
    cfg = cfg || {};
    document.getElementById('scTitle').textContent = cfg.title || 'Confirmer';
    document.getElementById('scMsg').innerHTML = cfg.message || '';
    document.getElementById('scIcon').innerHTML = `<i class="fas fa-${cfg.icon || 'triangle-exclamation'}"></i>`;
    const tw = document.getElementById('scTargetWrap');
    if (cfg.target) { tw.style.display = ''; document.getElementById('scTarget').textContent = cfg.target; }
    else tw.style.display = 'none';
    okBtn.textContent = cfg.okText || 'Confirmer';
    okBtn.className = 'btn btn-sm ' + (cfg.danger === false ? 'btn-primary' : 'btn-danger');
    cb = cfg.onConfirm || null;
    ov.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  okBtn.addEventListener('click', () => { close(); if (typeof cb === 'function') cb(); });
  cancelBtn.addEventListener('click', close);
  ov.addEventListener('click', e => { if (e.target === ov) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('open')) close(); });

  return open;
})();

// ══ Champ personne mono-sélection (bouton + chip + input caché) ═════════════════
// samsPatientField({ button, hidden, chip, title, items, required=true, selected })
window.samsPatientField = function (cfg) {
  const btn  = document.getElementById(cfg.button);
  const hid  = document.getElementById(cfg.hidden);
  const chip = document.getElementById(cfg.chip);
  if (!btn || !hid || !chip) return;
  const items = cfg.items || [];
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function render(it){
    if (!it) { chip.innerHTML=''; return; }
    chip.innerHTML = '<span class="sp-field-chip">'+esc(it.name)+'<span class="x"><i class="fas fa-times"></i></span></span>';
    chip.querySelector('.x').onclick = () => { hid.value=''; chip.innerHTML=''; };
  }
  btn.addEventListener('click', () => SamsPicker.open({
    title: cfg.title || 'Sélection', items,
    onConfirm: (it) => { if(!it) return; hid.value = it.id; render(it); }
  }));
  // pré-sélection éventuelle
  if (cfg.selected) { const it = items.find(x => String(x.id) === String(cfg.selected)); if (it) { hid.value = it.id; render(it); } }
  // garde-fou de soumission
  if (cfg.required !== false) {
    const form = hid.closest('form');
    if (form) form.addEventListener('submit', e => { if (!hid.value) { e.preventDefault(); if (window.showToast) showToast('Veuillez sélectionner '+(cfg.title?cfg.title.toLowerCase():'une personne'),'error'); } });
  }
  return { set: render };
};

// Interrupteurs custom : clic délégué (toute .sams-switch avec data-target)
document.addEventListener('click', function (e) {
  const sw = e.target.closest('.sams-switch'); if (!sw) return;
  const on = sw.classList.toggle('on');
  const t = sw.dataset.target ? document.getElementById(sw.dataset.target) : null;
  if (t) t.value = on ? '1' : '';
});

// ══ SamsSelect : remplace automatiquement TOUS les <select> natifs ═══════════════
// Le <select> d'origine est conservé caché (validation required, onchange,
// soumission de formulaire intacts). Thème géré via les variables CSS SAMS.
window.SamsSelect = (function () {
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

  function enhance(sel) {
    if (!sel || sel.tagName !== 'SELECT') return;
    if (sel.dataset.ssDone || sel.classList.contains('no-ss') || ('noEnhance' in sel.dataset)) return;
    sel.dataset.ssDone = '1';
    const multiple = sel.multiple;

    const wrap = document.createElement('div'); wrap.className = 'ss-wrap';
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (sel.style[p]) wrap.style[p] = sel.style[p]; });
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel); sel.classList.add('ss-native');
    if (sel.disabled) wrap.classList.add('ss-disabled');

    const ctrl = document.createElement('div'); ctrl.className = 'ss-control'; ctrl.tabIndex = sel.disabled ? -1 : 0;
    ctrl.setAttribute('role','combobox'); ctrl.setAttribute('aria-haspopup','listbox'); ctrl.setAttribute('aria-expanded','false');
    ctrl.innerHTML = '<span class="ss-value"></span><i class="fas fa-chevron-down ss-arrow"></i>';
    wrap.appendChild(ctrl);

    const panel = document.createElement('div'); panel.className = 'ss-panel'; panel.setAttribute('role','listbox');
    wrap.appendChild(panel);

    let optEls = [], searchInput = null, activeEl = null;

    function model() {
      const items = [];
      Array.from(sel.children).forEach(ch => {
        if (ch.tagName === 'OPTGROUP') { items.push({ group: ch.label });
          Array.from(ch.children).forEach(o => items.push(opt(o)));
        } else if (ch.tagName === 'OPTION') items.push(opt(ch));
      });
      return items;
    }
    function opt(o){ return { value:o.value, label:o.textContent.trim(), icon:o.dataset.icon||'', avatar:o.dataset.avatar||'', disabled:o.disabled, selected:o.selected, el:o }; }

    function rebuild() {
      panel.innerHTML = ''; optEls = []; activeEl = null;
      const m = model(), real = m.filter(x => x.el);
      const needSearch = sel.dataset.search === 'true' || real.length > 8;
      if (needSearch) {
        const s = document.createElement('div'); s.className = 'ss-search';
        s.innerHTML = '<i class="fas fa-search"></i><input type="text" placeholder="Rechercher...">';
        panel.appendChild(s); searchInput = s.querySelector('input');
        searchInput.addEventListener('input', () => filter(searchInput.value));
        searchInput.addEventListener('keydown', onKey);
      } else searchInput = null;
      const list = document.createElement('div'); list.className = 'ss-options'; panel.appendChild(list);
      m.forEach(it => {
        if (it.group) { const g = document.createElement('div'); g.className = 'ss-group'; g.textContent = it.group; list.appendChild(g); return; }
        const o = document.createElement('div');
        o.className = 'ss-opt' + (it.selected ? ' selected' : '') + (it.disabled ? ' disabled' : '');
        o.setAttribute('role','option');
        let html = '';
        if (it.avatar) html += '<img class="ss-oava" src="' + esc(it.avatar) + '" alt="">';
        else if (it.icon) html += '<span class="ss-oico"><i class="' + esc(it.icon) + '"></i></span>';
        html += '<span class="ss-otext"></span><i class="fas fa-check ss-check"></i>';
        o.innerHTML = html; o.querySelector('.ss-otext').textContent = it.label || '—';
        o.addEventListener('click', () => { if (!it.disabled) choose(it.el, o); });
        list.appendChild(o); optEls.push(o);
      });
      const empty = document.createElement('div'); empty.className = 'ss-empty'; empty.textContent = 'Aucun résultat'; empty.style.display = 'none';
      list.appendChild(empty); panel._empty = empty;
      updateValue();
    }
    function filter(q) {
      q = (q || '').toLowerCase().trim(); let any = false;
      optEls.forEach(o => { const show = !q || o.textContent.toLowerCase().includes(q); o.style.display = show ? '' : 'none'; if (show) any = true; });
      panel._empty.style.display = any ? 'none' : '';
    }
    function choose(optionEl, row) {
      if (multiple) { optionEl.selected = !optionEl.selected; row.classList.toggle('selected', optionEl.selected); fire(); updateValue(); }
      else { sel.value = optionEl.value; optEls.forEach(o => o.classList.remove('selected')); row.classList.add('selected'); fire(); updateValue(); close(); ctrl.focus(); }
    }
    function fire() { sel.dispatchEvent(new Event('change', { bubbles: true })); wrap.classList.remove('ss-error'); }
    function updateValue() {
      const v = ctrl.querySelector('.ss-value');
      const chosen = Array.from(sel.selectedOptions);
      if (multiple) {
        if (!chosen.length) { v.className = 'ss-value placeholder'; v.textContent = sel.dataset.placeholder || 'Sélectionner…'; }
        else { v.className = 'ss-value'; v.textContent = chosen.map(o => o.textContent.trim()).join(', '); }
      } else {
        const o = chosen[0];
        if (!o || o.value === '') { v.className = 'ss-value placeholder'; v.textContent = (o ? o.textContent.trim() : '') || sel.dataset.placeholder || 'Sélectionner…'; }
        else {
          v.className = 'ss-value'; let html = '';
          if (o.dataset.avatar) html += '<img class="ss-ava" src="' + esc(o.dataset.avatar) + '">';
          else if (o.dataset.icon) html += '<i class="' + esc(o.dataset.icon) + '" style="color:var(--red-l)"></i>';
          v.innerHTML = html + '<span></span>'; v.lastChild.textContent = o.textContent.trim();
        }
      }
    }
    function visible() { return optEls.filter(o => o.style.display !== 'none'); }
    function setActive(el) { if (activeEl) activeEl.classList.remove('active'); activeEl = el; if (el) { el.classList.add('active'); el.scrollIntoView({ block:'nearest' }); } }
    function onKey(e) {
      const opts = visible(); const idx = opts.indexOf(activeEl);
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(opts[Math.min(opts.length - 1, idx + 1)] || opts[0]); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(opts[Math.max(0, idx - 1)] || opts[0]); }
      else if (e.key === 'Enter') { e.preventDefault(); if (activeEl) activeEl.click(); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); ctrl.focus(); }
    }
    function open() {
      if (wrap.classList.contains('ss-disabled')) return;
      closeAll(); rebuild(); wrap.classList.add('open'); ctrl.setAttribute('aria-expanded','true');
      positionPanel(ctrl, panel);
      if (searchInput) setTimeout(() => searchInput.focus(), 30);
      else setTimeout(() => setActive(panel.querySelector('.ss-opt.selected') || visible()[0]), 30);
    }
    function close() { wrap.classList.remove('open'); ctrl.setAttribute('aria-expanded','false'); }

    ctrl.addEventListener('click', () => { wrap.classList.contains('open') ? close() : open(); });
    ctrl.addEventListener('keydown', e => {
      if (!wrap.classList.contains('open')) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      } else { onKey(e); }
    });
    rebuild();
  }

  // Positionnement fixe du panneau (évite le clipping dans les modales en overflow)
  function positionPanel(ctrl, panel) {
    const r = ctrl.getBoundingClientRect();
    panel.style.position = 'fixed'; panel.style.left = r.left + 'px'; panel.style.width = r.width + 'px';
    const below = window.innerHeight - r.bottom, ph = Math.min(300, panel.offsetHeight || 300);
    if (below < ph + 12 && r.top > below) { panel.style.top = 'auto'; panel.style.bottom = (window.innerHeight - r.top + 6) + 'px'; }
    else { panel.style.bottom = 'auto'; panel.style.top = (r.bottom + 6) + 'px'; }
  }
  function repositionOpen() { const w = document.querySelector('.ss-wrap.open'); if (!w) return; positionPanel(w.querySelector('.ss-control'), w.querySelector('.ss-panel')); }
  function closeAll() {
    document.querySelectorAll('.ss-wrap.open').forEach(w => { w.classList.remove('open'); const c = w.querySelector('.ss-control'); if (c) c.setAttribute('aria-expanded','false'); });
  }
  function enhanceAll(root) { (root || document).querySelectorAll('select').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.ss-wrap') && !e.target.closest('.ss-panel')) closeAll(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll };
})();

// ══ SamsDateTime : remplace les inputs date/time/datetime-local/month natifs ═════
// L'input natif est conservé caché (validation required + soumission intactes) ;
// sa valeur est pilotée par un calendrier / sélecteur d'heure custom thématisé.
window.SamsDateTime = (function () {
  const MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  const DOW = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
  const KINDS = ['date','time','datetime-local','month'];
  const pad = n => String(n).padStart(2,'0');
  const el = (tag, cls, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; };

  function parse(val, kind) {
    if (!val) return null;
    const n = new Date();
    let m;
    if (kind === 'time' && (m = val.match(/^(\d{1,2}):(\d{2})/)))
      return { y:n.getFullYear(), m:n.getMonth(), d:n.getDate(), hh:+m[1], mm:+m[2], empty:false };
    if (kind === 'month' && (m = val.match(/^(\d{4})-(\d{2})/)))
      return { y:+m[1], m:+m[2]-1, d:1, hh:0, mm:0, empty:false };
    if (kind === 'date' && (m = val.match(/^(\d{4})-(\d{2})-(\d{2})/)))
      return { y:+m[1], m:+m[2]-1, d:+m[3], hh:0, mm:0, empty:false };
    if (kind === 'datetime-local' && (m = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/)))
      return { y:+m[1], m:+m[2]-1, d:+m[3], hh:+m[4], mm:+m[5], empty:false };
    return null;
  }
  function fmtValue(s, kind) {
    if (kind === 'time') return pad(s.hh) + ':' + pad(s.mm);
    if (kind === 'month') return s.y + '-' + pad(s.m+1);
    if (kind === 'date') return s.y + '-' + pad(s.m+1) + '-' + pad(s.d);
    return s.y + '-' + pad(s.m+1) + '-' + pad(s.d) + 'T' + pad(s.hh) + ':' + pad(s.mm);
  }
  function fmtHuman(s, kind) {
    if (kind === 'time') return pad(s.hh) + ':' + pad(s.mm);
    if (kind === 'month') return MONTHS[s.m].charAt(0).toUpperCase() + MONTHS[s.m].slice(1) + ' ' + s.y;
    if (kind === 'date') return pad(s.d) + '/' + pad(s.m+1) + '/' + s.y;
    return pad(s.d) + '/' + pad(s.m+1) + '/' + s.y + ' ' + pad(s.hh) + ':' + pad(s.mm);
  }
  function placeholder(kind) {
    return kind === 'time' ? '--:--' : kind === 'month' ? 'Mois aaaa' : kind === 'datetime-local' ? 'jj/mm/aaaa --:--' : 'jj/mm/aaaa';
  }

  function enhance(inp) {
    if (!inp || inp.tagName !== 'INPUT' || inp.dataset.dtDone) return;
    const kind = (inp.getAttribute('type') || '').toLowerCase();
    if (!KINDS.includes(kind) || inp.classList.contains('no-dt') || ('noEnhance' in inp.dataset)) return;
    inp.dataset.dtDone = '1';
    inp.tabIndex = -1;
    const isDT = kind === 'datetime-local';

    const wrap = el('div','dt-wrap');
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (inp.style[p]) wrap.style[p] = inp.style[p]; });
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp); inp.classList.add('dt-native');

    const icon = kind === 'time' ? 'fa-clock' : (kind === 'month' ? 'fa-calendar' : 'fa-calendar-days');
    const ctrl = el('div','ss-control dt-control'); ctrl.tabIndex = inp.disabled ? -1 : 0;
    ctrl.setAttribute('role','button'); ctrl.setAttribute('aria-haspopup','dialog');
    ctrl.innerHTML = '<i class="fas ' + icon + ' dt-ico"></i><span class="ss-value"></span><i class="fas fa-chevron-down ss-arrow"></i>';
    wrap.appendChild(ctrl);
    if (inp.disabled) wrap.classList.add('ss-disabled');

    const pop = el('div','dt-pop' + (isDT ? ' dt-pop-dual' : '')); wrap.appendChild(pop);

    const now = new Date();
    let s = parse(inp.value, kind) || { y:now.getFullYear(), m:now.getMonth(), d:now.getDate(), hh:now.getHours(), mm:now.getMinutes(), empty:true };
    let view = { y:s.y, m:s.m };

    function updateCtrl() {
      const v = ctrl.querySelector('.ss-value');
      if (!inp.value) { v.className = 'ss-value placeholder'; v.textContent = inp.dataset.placeholder || placeholder(kind); }
      else { v.className = 'ss-value'; v.textContent = fmtHuman(s, kind); }
    }
    function commit(close) { s.empty = false; inp.value = fmtValue(s, kind); inp.dispatchEvent(new Event('change',{bubbles:true})); wrap.classList.remove('ss-error'); updateCtrl(); if (close) closePop(); }
    function updateFooter() { const ft = pop.querySelector('.dt-ft'); if (ft && ft._upd) ft._upd(); }

    function calPicker() {
      const box = el('div');
      const head = el('div','dt-head');
      const nav = (ic, fn) => { const b = el('button','dt-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','dt-title');
      head.appendChild(nav('fa-angles-left', () => { view.y--; render(); }));
      head.appendChild(nav('fa-angle-left', () => { view.m--; if (view.m<0){view.m=11;view.y--;} render(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.m++; if (view.m>11){view.m=0;view.y++;} render(); }));
      head.appendChild(nav('fa-angles-right', () => { view.y++; render(); }));
      box.appendChild(head);
      const dow = el('div','dt-grid'); DOW.forEach(d => dow.appendChild(el('div','dt-dow',d))); box.appendChild(dow);
      const grid = el('div','dt-grid'); box.appendChild(grid);
      function render() {
        title.textContent = MONTHS[view.m] + ' ' + view.y; grid.innerHTML = '';
        const start = (new Date(view.y,view.m,1).getDay()+6)%7;
        const dim = new Date(view.y,view.m+1,0).getDate();
        const prev = new Date(view.y,view.m,0).getDate();
        const t = new Date();
        for (let i=0;i<start;i++) grid.appendChild(el('div','dt-day muted', String(prev-start+1+i)));
        for (let d=1;d<=dim;d++) {
          const c = el('div','dt-day', String(d));
          if (t.getFullYear()===view.y && t.getMonth()===view.m && t.getDate()===d) c.classList.add('today');
          if (!s.empty && s.y===view.y && s.m===view.m && s.d===d) c.classList.add('selected');
          c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=view.m; s.d=d; s.empty=false;
            if (isDT) { render(); updateFooter(); } else commit(true); };
          grid.appendChild(c);
        }
        const tail = (7 - ((start+dim)%7))%7;
        for (let i=1;i<=tail;i++) grid.appendChild(el('div','dt-day muted', String(i)));
      }
      render();
      return box;
    }

    function timePicker() {
      const box = el('div','dt-time');
      const col = (lbl, max, get, set) => {
        const c = el('div','dt-col'); c.appendChild(el('div','dt-col-lbl', lbl));
        const sc = el('div','dt-scroll');
        for (let i=0;i<max;i++) {
          const tk = el('div','dt-tick', pad(i));
          if (get()===i) tk.classList.add('selected');
          tk.onclick = e => { e.stopPropagation(); set(i); s.empty=false; sc.querySelectorAll('.dt-tick').forEach(x=>x.classList.remove('selected')); tk.classList.add('selected'); updateFooter(); };
          sc.appendChild(tk);
        }
        c.appendChild(sc);
        setTimeout(() => { const sel = sc.querySelector('.dt-tick.selected'); if (sel) sel.scrollIntoView({ block:'center' }); }, 20);
        return c;
      };
      box.appendChild(col('Heures', 24, () => s.hh, v => s.hh=v));
      box.appendChild(col('Minutes', 60, () => s.mm, v => s.mm=v));
      return box;
    }

    function monthPicker() {
      const box = el('div');
      const head = el('div','dt-head');
      const nav = (ic, fn) => { const b = el('button','dt-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','dt-title'); title.textContent = view.y;
      head.appendChild(nav('fa-angle-left', () => { view.y--; title.textContent = view.y; mark(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.y++; title.textContent = view.y; mark(); }));
      box.appendChild(head);
      const grid = el('div','dt-months'); const cells = [];
      MONTHS.forEach((mn, i) => { const c = el('div','dt-month', mn.slice(0,4)); c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=i; commit(true); }; grid.appendChild(c); cells.push(c); });
      function mark() { cells.forEach((c,i) => c.classList.toggle('selected', !s.empty && s.y===view.y && s.m===i)); }
      mark(); box.appendChild(grid);
      return box;
    }

    function footer() {
      const ft = el('div','dt-ft');
      const prev = el('div','dt-preview');
      ft._upd = () => { prev.textContent = fmtHuman(s, kind); };
      ft._upd();
      const btns = el('div'); btns.style.cssText = 'display:flex;gap:.5rem';
      const quick = el('button','btn btn-ghost btn-sm', '<i class="fas fa-bolt"></i> ' + (kind === 'time' ? 'Maintenant' : "Aujourd'hui"));
      quick.type = 'button';
      quick.onclick = e => { e.stopPropagation(); const n = new Date(); s.y=n.getFullYear(); s.m=n.getMonth(); s.d=n.getDate(); s.hh=n.getHours(); s.mm=n.getMinutes(); s.empty=false; view={y:s.y,m:s.m}; if (kind==='date') commit(true); else { build(); updateFooter(); } };
      const ok = el('button','btn btn-primary btn-sm', '<i class="fas fa-check"></i> Valider'); ok.type='button';
      ok.onclick = e => { e.stopPropagation(); commit(true); };
      btns.appendChild(quick); btns.appendChild(ok);
      ft.appendChild(prev); ft.appendChild(btns);
      return ft;
    }

    function build() {
      pop.innerHTML = '';
      if (kind === 'month') { pop.appendChild(monthPicker()); }
      else if (kind === 'time') { pop.appendChild(timePicker()); pop.appendChild(footer()); }
      else if (kind === 'date') { pop.appendChild(calPicker()); pop.appendChild(footer()); }
      else { const row = el('div','dt-row'); const l = el('div'); l.appendChild(calPicker()); const r = el('div'); r.appendChild(timePicker()); row.appendChild(l); row.appendChild(r); pop.appendChild(row); pop.appendChild(footer()); }
    }

    function openPop() { if (wrap.classList.contains('ss-disabled')) return; closeAllDT(); view = { y:s.y, m:s.m }; build(); wrap.classList.add('open'); position(); }
    function closePop() { wrap.classList.remove('open'); }
    function position() {
      const r = ctrl.getBoundingClientRect();
      pop.style.left = r.left + 'px';
      const pw = pop.offsetWidth || 300, ph = pop.offsetHeight || 320;
      if (r.left + pw > window.innerWidth - 8) pop.style.left = Math.max(8, window.innerWidth - pw - 8) + 'px';
      if (window.innerHeight - r.bottom < ph + 12 && r.top > (window.innerHeight - r.bottom)) { pop.style.top = 'auto'; pop.style.bottom = (window.innerHeight - r.top + 6) + 'px'; }
      else { pop.style.bottom = 'auto'; pop.style.top = (r.bottom + 6) + 'px'; }
    }
    pop._position = position;

    ctrl.addEventListener('click', () => { wrap.classList.contains('open') ? closePop() : openPop(); });
    ctrl.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); openPop(); }
      else if (e.key === 'Escape') closePop();
    });
    updateCtrl();
  }

  function closeAllDT() { document.querySelectorAll('.dt-wrap.open').forEach(w => w.classList.remove('open')); }
  function repositionOpen() { const w = document.querySelector('.dt-wrap.open'); if (!w) return; const p = w.querySelector('.dt-pop'); if (p && p._position) p._position(); }
  function enhanceAll(root) { (root || document).querySelectorAll('input[type=date],input[type=time],input[type="datetime-local"],input[type=month]').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.dt-wrap') && !e.target.closest('.dt-pop')) closeAllDT(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll: closeAllDT };
})();
</script>
