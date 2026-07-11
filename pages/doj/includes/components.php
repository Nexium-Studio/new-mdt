<?php
/**
 * Composants réutilisables du panel DOJ
 * pages/doj/includes/components.php
 *
 * À inclure une fois par page (avant le footer). Fournit, en pur JS (aucun
 * élément natif <select>/<input date|time>/confirm()/alert()) :
 *   - DojPicker.open({...})  : sélecteur de personnes (citoyens) avec barre de
 *                              recherche + cartes + avatar, mono ou multi.
 *   - DojConfirm({...})      : fenêtre de confirmation personnalisée
 *                              (remplace window.confirm()).
 *   - dojPersonField({...})  : champ « ajouter un citoyen » (bouton + chip).
 *   - DojSelect              : remplace AUTO tous les <select> natifs.
 *   - DojDateTime            : remplace AUTO les input date/time/datetime-local.
 *   - dojToast(msg, type)    : notification non-bloquante (remplace alert()).
 *
 * Tout est thémé via les variables CSS DOJ (--doj-*) → s'adapte aux 3 thèmes.
 */
?>
<style>
/* ══ Boutons utilitaires (au cas où non définis par le footer) ════════════════ */
.dj-btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.6rem 1.1rem;
  font-family:'Chakra Petch',sans-serif; font-size:.85rem; font-weight:600; border-radius:8px; cursor:pointer;
  border:1px solid transparent; transition:all .2s; white-space:nowrap; text-decoration:none; }
.dj-btn-sm { padding:.45rem .8rem; font-size:.8rem; }
.dj-btn-primary { background:linear-gradient(135deg,#3b82f6,#1e40af); color:#fff; box-shadow:0 4px 18px rgba(59,130,246,.25); }
.dj-btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 26px rgba(59,130,246,.4); }
.dj-btn-ghost { background:rgba(59,130,246,.08); color:var(--doj-blue-bright,#3b82f6); border-color:rgba(59,130,246,.25); }
.dj-btn-ghost:hover { background:rgba(59,130,246,.16); border-color:rgba(59,130,246,.5); }
.dj-btn-danger { background:rgba(239,68,68,.12); color:#f87171; border-color:rgba(239,68,68,.3); }
.dj-btn-danger:hover { background:#ef4444; color:#fff; }

/* ══ Sélecteur de personnes (DojPicker) ═══════════════════════════════════════ */
.dp-ov { position:fixed; inset:0; background:rgba(5,5,15,.9); backdrop-filter:blur(10px);
  z-index:10000; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.dp-ov.open { display:flex; }
.dp-modal { background:linear-gradient(135deg,var(--doj-surface,#1e293b),var(--doj-navy,#0f172a));
  border:1px solid var(--doj-border-active,rgba(59,130,246,.4)); border-radius:16px; width:100%; max-width:560px;
  max-height:86vh; display:flex; flex-direction:column; overflow:hidden;
  box-shadow:0 0 60px rgba(59,130,246,.2),0 30px 90px rgba(0,0,0,.6); animation:dp-in .22s cubic-bezier(.34,1.56,.64,1); }
@keyframes dp-in { from{opacity:0;transform:scale(.95) translateY(12px)} to{opacity:1;transform:none} }
.dp-hd { padding:1.1rem 1.4rem; border-bottom:1px solid var(--doj-border,rgba(59,130,246,.15));
  display:flex; align-items:center; justify-content:space-between; background:rgba(59,130,246,.04); }
.dp-hd h2 { font-family:'Orbitron',monospace; font-size:.9rem; font-weight:700; color:var(--text-bright,#f1f5f9);
  text-transform:uppercase; letter-spacing:.08em; display:flex; align-items:center; gap:.6rem; margin:0; }
.dp-hd h2 i { color:var(--doj-blue-bright,#3b82f6); }
.dp-close { width:34px; height:34px; border-radius:9px; background:rgba(59,130,246,.08);
  border:1px solid var(--doj-border,rgba(59,130,246,.25)); color:var(--doj-blue-bright,#3b82f6); cursor:pointer;
  display:grid; place-items:center; font-size:1.2rem; transition:all .2s; }
.dp-close:hover { background:rgba(59,130,246,.2); transform:rotate(90deg); }
.dp-search { padding:.95rem 1.4rem; border-bottom:1px solid var(--doj-border,rgba(59,130,246,.12)); position:relative; }
.dp-search i { position:absolute; left:1.95rem; top:50%; transform:translateY(-50%);
  color:var(--text-dim,#94a3b8); font-size:.85rem; pointer-events:none; }
.dp-search input { width:100%; padding:.7rem 1rem .7rem 2.5rem; background:var(--doj-navy,#0f172a);
  border:1px solid var(--doj-border,rgba(59,130,246,.2)); border-radius:10px; color:var(--text-bright,#f1f5f9);
  font-family:'Chakra Petch',sans-serif; font-size:.9rem; outline:none; transition:all .2s; }
.dp-search input:focus { border-color:var(--doj-blue-bright,#3b82f6); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.dp-list { overflow-y:auto; padding:.7rem; flex:1; display:flex; flex-direction:column; gap:.45rem; }
.dp-item { display:flex; align-items:center; gap:.85rem; padding:.7rem .85rem; border-radius:11px;
  border:1px solid transparent; cursor:pointer; transition:all .15s; background:rgba(255,255,255,.015); }
.dp-item:hover { background:rgba(59,130,246,.08); border-color:var(--doj-border,rgba(59,130,246,.2)); }
.dp-item.selected { background:rgba(59,130,246,.14); border-color:var(--doj-blue-bright,#3b82f6); }
.dp-ava { width:42px; height:42px; border-radius:11px; flex-shrink:0; overflow:hidden;
  background:linear-gradient(135deg,#3b82f6,#1e40af); display:grid; place-items:center;
  font-family:'Orbitron',monospace; font-weight:800; color:#fff; font-size:.9rem; box-shadow:0 0 14px rgba(59,130,246,.3); }
.dp-ava img { width:100%; height:100%; object-fit:cover; }
.dp-info { flex:1; min-width:0; }
.dp-name { font-family:'Chakra Petch',sans-serif; font-weight:700; font-size:.92rem; color:var(--text-bright,#f1f5f9); }
.dp-sub { font-family:'JetBrains Mono',monospace; font-size:.72rem; color:var(--text-dim,#64748b);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dp-mark { width:24px; height:24px; border-radius:8px; flex-shrink:0; border:2px solid var(--doj-border-active,rgba(59,130,246,.4));
  display:grid; place-items:center; color:#fff; font-size:.7rem; transition:all .15s; }
.dp-item.selected .dp-mark { background:var(--doj-blue-bright,#3b82f6); border-color:var(--doj-blue-bright,#3b82f6); }
.dp-mark i { opacity:0; transition:opacity .15s; }
.dp-item.selected .dp-mark i { opacity:1; }
.dp-empty { text-align:center; padding:2.5rem 1rem; color:var(--text-dim,#64748b); font-size:.85rem; }
.dp-empty i { font-size:2.2rem; opacity:.25; display:block; margin-bottom:.6rem; }
.dp-ft { padding:.9rem 1.4rem; border-top:1px solid var(--doj-border,rgba(59,130,246,.12));
  display:flex; align-items:center; justify-content:space-between; gap:.6rem; background:rgba(0,0,0,.2); }
.dp-count { font-size:.78rem; color:var(--text-dim,#94a3b8); }
.dp-count b { color:var(--doj-blue-bright,#3b82f6); }
.dp-ft-btns { display:flex; gap:.5rem; }

/* ══ Confirmation custom (DojConfirm) ═════════════════════════════════════════ */
.dc-ov { position:fixed; inset:0; background:rgba(5,5,15,.9); backdrop-filter:blur(10px);
  z-index:10001; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.dc-ov.open { display:flex; }
.dc-modal { background:linear-gradient(135deg,var(--doj-surface,#1e293b),var(--doj-navy,#0f172a));
  border:1px solid var(--doj-border-active,rgba(59,130,246,.4)); border-radius:16px; width:100%; max-width:420px;
  overflow:hidden; box-shadow:0 0 60px rgba(59,130,246,.2),0 30px 90px rgba(0,0,0,.6); animation:dp-in .2s cubic-bezier(.34,1.56,.64,1); }
.dc-body { padding:2rem 1.6rem 1.4rem; text-align:center; }
.dc-icon { width:62px; height:62px; border-radius:50%; margin:0 auto 1.2rem; display:grid; place-items:center;
  font-size:1.5rem; background:rgba(239,68,68,.1); border:2px solid rgba(239,68,68,.3); color:#f87171;
  animation:dc-pulse 1.7s ease-in-out infinite; }
.dc-icon.dc-info { background:rgba(59,130,246,.1); border-color:rgba(59,130,246,.3); color:var(--doj-blue-bright,#3b82f6); }
@keyframes dc-pulse { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)} 50%{box-shadow:0 0 0 12px rgba(239,68,68,0)} }
.dc-title { font-family:'Orbitron',monospace; font-size:1rem; font-weight:700; color:var(--text-bright,#f1f5f9);
  text-transform:uppercase; letter-spacing:.06em; margin-bottom:.6rem; }
.dc-msg { font-family:'Chakra Petch',sans-serif; font-size:.9rem; color:var(--text-dim,#94a3b8); line-height:1.6; }
.dc-target { font-family:'JetBrains Mono',monospace; font-size:.8rem; color:var(--doj-blue-bright,#3b82f6);
  background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:7px;
  padding:.4rem .85rem; margin:.5rem auto .2rem; display:inline-block; }
.dc-ft { padding:1rem 1.6rem 1.5rem; display:flex; gap:.6rem; justify-content:center; }

/* ══ Champ « personne » (bouton + chip) lié à DojPicker ═══════════════════════ */
.dp-field-chip { display:inline-flex; align-items:center; gap:.55rem; padding:.45rem .8rem; border-radius:20px;
  font-family:'Chakra Petch',sans-serif; font-size:.82rem; font-weight:600;
  background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.3); color:var(--doj-blue-bright,#3b82f6); }
.dp-field-chip .x { cursor:pointer; opacity:.7; } .dp-field-chip .x:hover { opacity:1; }

/* ══ Interrupteur custom réutilisable (remplace les checkbox natives) ══════════ */
.doj-switch { display:inline-flex; align-items:center; gap:.7rem; cursor:pointer; user-select:none; }
.doj-switch .sw-track { width:46px; height:26px; border-radius:26px; background:rgba(255,255,255,.12);
  border:1px solid var(--doj-border,rgba(59,130,246,.2)); position:relative; flex-shrink:0; transition:all .2s; }
.doj-switch .sw-track::before { content:''; position:absolute; left:3px; top:2px; width:20px; height:20px; border-radius:50%; background:#fff; transition:transform .2s; }
.doj-switch.on .sw-track { background:var(--doj-blue-bright,#3b82f6); border-color:var(--doj-blue-bright,#3b82f6); }
.doj-switch.on .sw-track::before { transform:translateX(20px); }
.doj-switch .sw-lbl { font-family:'Chakra Petch',sans-serif; font-size:.85rem; color:var(--text-dim,#94a3b8); }

/* ══ Select custom (DojSelect) — remplace les <select> natifs ═════════════════ */
.djs-wrap { position:relative; width:100%; }
.djs-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.djs-control { display:flex; align-items:center; gap:.5rem; width:100%; min-height:44px; padding:.6rem .9rem;
  background:var(--doj-navy,#0f172a); border:1px solid var(--doj-border,rgba(59,130,246,.2)); border-radius:8px;
  color:var(--text-bright,#f1f5f9); font-family:'Chakra Petch',sans-serif; font-size:.9rem; cursor:pointer;
  transition:all .15s; outline:none; position:relative; z-index:1; }
.djs-control:hover { border-color:var(--doj-border-active,rgba(59,130,246,.4)); }
.djs-control:focus, .djs-wrap.open .djs-control, .djt-wrap.open .djs-control {
  border-color:var(--doj-blue-bright,#3b82f6); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.djs-value { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:.5rem; }
.djs-value.placeholder { color:var(--text-ghost,#475569); }
.djs-value .djs-ava { width:24px; height:24px; border-radius:6px; flex-shrink:0; object-fit:cover; }
.djs-arrow { font-size:.7rem; color:var(--text-dim,#94a3b8); transition:transform .2s; flex-shrink:0; }
.djs-wrap.open .djs-arrow, .djt-wrap.open .djs-arrow { transform:rotate(180deg); color:var(--doj-blue-bright,#3b82f6); }
.djs-wrap.djs-disabled { opacity:.5; pointer-events:none; }
.djs-wrap.djs-error .djs-control { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.2); }
.djs-panel { position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:60;
  background:linear-gradient(135deg,var(--doj-surface,#1e293b),var(--doj-navy,#0f172a));
  border:1px solid var(--doj-border-active,rgba(59,130,246,.4)); border-radius:11px; box-shadow:0 14px 44px rgba(0,0,0,.6);
  overflow:hidden; display:none; flex-direction:column; max-height:300px; animation:djs-in .16s ease; }
.djs-wrap.open .djs-panel { display:flex; }
@keyframes djs-in { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.djs-search { position:relative; padding:.55rem; border-bottom:1px solid var(--doj-border,rgba(59,130,246,.12)); }
.djs-search i { position:absolute; left:1.1rem; top:50%; transform:translateY(-50%); color:var(--text-dim,#94a3b8); font-size:.75rem; pointer-events:none; }
.djs-search input { width:100%; padding:.5rem .75rem .5rem 2rem; background:var(--doj-navy,#0f172a);
  border:1px solid var(--doj-border,rgba(59,130,246,.2)); border-radius:8px; color:var(--text-bright,#f1f5f9); font-size:.85rem; outline:none; font-family:'Chakra Petch',sans-serif; }
.djs-search input:focus { border-color:var(--doj-blue-bright,#3b82f6); }
.djs-options { overflow-y:auto; padding:.35rem; display:flex; flex-direction:column; gap:1px; }
.djs-options::-webkit-scrollbar { width:6px; }
.djs-options::-webkit-scrollbar-thumb { background:rgba(59,130,246,.35); border-radius:3px; }
.djs-group { font-family:'Orbitron',monospace; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--text-dim,#94a3b8); padding:.55rem .7rem .25rem; }
.djs-opt { display:flex; align-items:center; gap:.6rem; padding:.55rem .7rem; border-radius:8px; cursor:pointer; font-family:'Chakra Petch',sans-serif; font-size:.88rem; color:var(--text-bright,#f1f5f9); transition:background .12s; }
.djs-opt:hover, .djs-opt.active { background:rgba(59,130,246,.12); }
.djs-opt.selected { background:rgba(59,130,246,.18); color:var(--doj-blue-bright,#3b82f6); font-weight:600; }
.djs-opt.disabled { opacity:.4; pointer-events:none; }
.djs-opt .djs-oico { width:22px; text-align:center; flex-shrink:0; color:var(--doj-blue-bright,#3b82f6); }
.djs-opt .djs-oava { width:28px; height:28px; border-radius:8px; object-fit:cover; flex-shrink:0; }
.djs-opt .djs-otext { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.djs-opt .djs-check { opacity:0; color:var(--doj-blue-bright,#3b82f6); font-size:.78rem; flex-shrink:0; }
.djs-opt.selected .djs-check { opacity:1; }
.djs-empty { padding:1.2rem; text-align:center; color:var(--text-dim,#94a3b8); font-size:.82rem; }

/* ══ Date / Heure custom (DojDateTime) ════════════════════════════════════════ */
.djt-wrap { position:relative; width:100%; }
.djt-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.djt-control .djt-ico { color:var(--doj-blue-bright,#3b82f6); font-size:.85rem; flex-shrink:0; }
.djt-pop { position:fixed; z-index:60; background:linear-gradient(135deg,var(--doj-surface,#1e293b),var(--doj-navy,#0f172a));
  border:1px solid var(--doj-border-active,rgba(59,130,246,.4)); border-radius:14px; box-shadow:0 16px 48px rgba(0,0,0,.6);
  padding:.9rem; display:none; animation:djs-in .16s ease; width:min(92vw,310px); }
.djt-wrap.open .djt-pop { display:block; }
.djt-pop.djt-pop-dual { width:min(94vw,560px); }
.djt-row { display:flex; gap:1rem; flex-wrap:wrap; }
.djt-row > div { flex:1; min-width:235px; }
.djt-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.6rem; gap:.3rem; }
.djt-nav { width:32px; height:32px; border-radius:8px; border:1px solid var(--doj-border,rgba(59,130,246,.2)); background:rgba(59,130,246,.06);
  color:var(--text-dim,#94a3b8); cursor:pointer; display:grid; place-items:center; transition:all .15s; flex-shrink:0; font-size:.72rem; }
.djt-nav:hover { background:rgba(59,130,246,.18); color:var(--doj-blue-bright,#3b82f6); border-color:var(--doj-border-active,rgba(59,130,246,.4)); }
.djt-title { font-family:'Orbitron',monospace; font-weight:700; color:var(--text-bright,#f1f5f9); font-size:.9rem; text-transform:capitalize; padding:.2rem .55rem; border-radius:6px; flex:1; text-align:center; }
.djt-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.djt-dow { text-align:center; font-size:.6rem; font-weight:700; text-transform:uppercase; color:var(--text-dim,#94a3b8); padding:.3rem 0; font-family:'Chakra Petch',sans-serif; }
.djt-day { aspect-ratio:1; display:grid; place-items:center; border-radius:8px; font-family:'JetBrains Mono',monospace; font-size:.82rem; color:var(--text-bright,#f1f5f9); cursor:pointer; border:1px solid transparent; transition:all .12s; }
.djt-day:hover { background:rgba(59,130,246,.14); }
.djt-day.muted { color:var(--text-ghost,#475569); opacity:.45; }
.djt-day.today { border-color:var(--doj-blue-bright,#3b82f6); color:var(--doj-blue-bright,#3b82f6); font-weight:700; }
.djt-day.selected { background:var(--doj-blue-bright,#3b82f6); color:#fff; font-weight:700; box-shadow:0 0 14px rgba(59,130,246,.5); }
.djt-months { display:grid; grid-template-columns:repeat(3,1fr); gap:.45rem; }
.djt-month { padding:.65rem; border-radius:8px; text-align:center; font-family:'Chakra Petch',sans-serif; font-size:.82rem; color:var(--text-bright,#f1f5f9); cursor:pointer; border:1px solid transparent; transition:all .12s; text-transform:capitalize; }
.djt-month:hover { background:rgba(59,130,246,.14); }
.djt-month.selected { background:var(--doj-blue-bright,#3b82f6); color:#fff; font-weight:700; }
.djt-time { display:flex; gap:.5rem; align-items:stretch; }
.djt-col { flex:1; display:flex; flex-direction:column; }
.djt-col-lbl { text-align:center; font-size:.6rem; font-weight:700; text-transform:uppercase; color:var(--text-dim,#94a3b8); margin-bottom:.3rem; font-family:'Chakra Petch',sans-serif; }
.djt-scroll { height:172px; overflow-y:auto; display:flex; flex-direction:column; gap:2px; padding-right:3px; }
.djt-scroll::-webkit-scrollbar { width:6px; }
.djt-scroll::-webkit-scrollbar-thumb { background:rgba(59,130,246,.35); border-radius:3px; }
.djt-tick { padding:.42rem; text-align:center; border-radius:8px; font-family:'JetBrains Mono',monospace; font-size:.84rem; color:var(--text-bright,#f1f5f9); cursor:pointer; border:1px solid transparent; transition:all .12s; }
.djt-tick:hover { background:rgba(59,130,246,.14); }
.djt-tick.selected { background:var(--doj-blue-bright,#3b82f6); color:#fff; font-weight:700; }
.djt-ft { display:flex; align-items:center; justify-content:space-between; gap:.6rem; margin-top:.75rem; padding-top:.65rem; border-top:1px solid var(--doj-border,rgba(59,130,246,.12)); flex-wrap:wrap; }
.djt-preview { font-family:'JetBrains Mono',monospace; font-size:.8rem; color:var(--doj-blue-bright,#3b82f6); }

/* ══ Toasts DOJ (remplace alert()) ════════════════════════════════════════════ */
#dojToastWrap { position:fixed; top:1.2rem; right:1.2rem; z-index:11000; display:flex; flex-direction:column; gap:.6rem; }
.doj-toast { display:flex; align-items:center; gap:.7rem; min-width:240px; max-width:380px; padding:.85rem 1.1rem;
  border-radius:11px; font-family:'Chakra Petch',sans-serif; font-size:.85rem; font-weight:500; color:#f1f5f9;
  background:linear-gradient(135deg,var(--doj-surface,#1e293b),var(--doj-navy,#0f172a));
  border:1px solid var(--doj-border-active,rgba(59,130,246,.4)); box-shadow:0 12px 40px rgba(0,0,0,.5);
  animation:dojToastIn .25s cubic-bezier(.34,1.56,.64,1); }
.doj-toast i { font-size:1.1rem; flex-shrink:0; }
.doj-toast.success { border-color:rgba(34,197,94,.4); } .doj-toast.success i { color:#22c55e; }
.doj-toast.error   { border-color:rgba(239,68,68,.4); } .doj-toast.error i { color:#ef4444; }
.doj-toast.info    { border-color:rgba(59,130,246,.4); } .doj-toast.info i { color:#3b82f6; }
@keyframes dojToastIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
@keyframes dojToastOut { to{opacity:0;transform:translateX(30px)} }
</style>

<!-- Conteneur du sélecteur de personnes -->
<div class="dp-ov" id="dojPickerOv">
  <div class="dp-modal">
    <div class="dp-hd">
      <h2><i class="fas fa-user-magnifying-glass"></i> <span id="dpTitle">Sélection citoyen</span></h2>
      <button type="button" class="dp-close" onclick="DojPicker.close()">×</button>
    </div>
    <div class="dp-search">
      <i class="fas fa-search"></i>
      <input type="text" id="dpSearch" placeholder="Rechercher un citoyen..." autocomplete="off">
    </div>
    <div class="dp-list" id="dpList"></div>
    <div class="dp-ft">
      <div class="dp-count" id="dpCount"></div>
      <div class="dp-ft-btns">
        <button type="button" class="dj-btn dj-btn-ghost dj-btn-sm" onclick="DojPicker.close()">Annuler</button>
        <button type="button" class="dj-btn dj-btn-primary dj-btn-sm" id="dpConfirm"><i class="fas fa-check"></i> Valider</button>
      </div>
    </div>
  </div>
</div>

<!-- Conteneur de confirmation custom -->
<div class="dc-ov" id="dojConfirmOv">
  <div class="dc-modal">
    <div class="dc-body">
      <div class="dc-icon" id="dcIcon"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="dc-title" id="dcTitle">Confirmer</div>
      <div id="dcTargetWrap" style="display:none"><span class="dc-target" id="dcTarget"></span></div>
      <div class="dc-msg" id="dcMsg"></div>
    </div>
    <div class="dc-ft">
      <button type="button" class="dj-btn dj-btn-ghost dj-btn-sm" id="dcCancel">Annuler</button>
      <button type="button" class="dj-btn dj-btn-danger dj-btn-sm" id="dcOk">Confirmer</button>
    </div>
  </div>
</div>

<!-- Conteneur des toasts -->
<div id="dojToastWrap"></div>

<script>
// ══ dojToast : notification non-bloquante (remplace alert) ══════════════════════
window.dojToast = function (msg, type) {
  const wrap = document.getElementById('dojToastWrap'); if (!wrap) return;
  type = type || 'info';
  const ic = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info');
  const t = document.createElement('div');
  t.className = 'doj-toast ' + type;
  t.innerHTML = '<i class="fas ' + ic + '"></i><span></span>';
  t.querySelector('span').textContent = msg;
  wrap.appendChild(t);
  setTimeout(() => { t.style.animation = 'dojToastOut .25s forwards'; setTimeout(() => t.remove(), 250); }, 3600);
};

// ══ DojPicker : sélecteur de citoyens réutilisable ══════════════════════════════
window.DojPicker = (function () {
  const ov = document.getElementById('dojPickerOv');
  const listEl = document.getElementById('dpList');
  const searchEl = document.getElementById('dpSearch');
  const countEl = document.getElementById('dpCount');
  const titleEl = document.getElementById('dpTitle');
  const confirmBtn = document.getElementById('dpConfirm');
  let items = [], selected = new Set(), multiple = false, onConfirm = null;

  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render(filter) {
    const q = (filter || '').toLowerCase().trim();
    const shown = items.filter(it => !q || (it.search || (it.name + ' ' + (it.sub || ''))).toLowerCase().includes(q));
    if (!shown.length) {
      listEl.innerHTML = '<div class="dp-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div>';
    } else {
      listEl.innerHTML = shown.map(it => {
        const sel = selected.has(String(it.id)) ? ' selected' : '';
        const ava = it.avatar
          ? `<img src="${escapeHtml(it.avatar)}" alt="">`
          : (it.name ? escapeHtml(it.name.trim().charAt(0).toUpperCase()) : '?');
        return `<div class="dp-item${sel}" data-id="${escapeHtml(String(it.id))}">
            <div class="dp-ava">${ava}</div>
            <div class="dp-info">
              <div class="dp-name">${escapeHtml(it.name || '—')}</div>
              ${it.sub ? `<div class="dp-sub">${escapeHtml(it.sub)}</div>` : ''}
            </div>
            <div class="dp-mark"><i class="fas fa-check"></i></div>
          </div>`;
      }).join('');
      listEl.querySelectorAll('.dp-item').forEach(el => el.addEventListener('click', () => toggle(el.dataset.id)));
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
  /**
   * open({ title, items, multiple, selected, onConfirm })
   *   items    : [{ id, name, sub, avatar, search }]
   */
  function open(cfg) {
    items = cfg.items || [];
    multiple = !!cfg.multiple;
    onConfirm = cfg.onConfirm || null;
    selected = new Set((cfg.selected || []).map(String));
    titleEl.textContent = cfg.title || 'Sélection citoyen';
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

// ══ DojConfirm : confirmation personnalisée (remplace window.confirm) ════════════
window.DojConfirm = (function () {
  const ov = document.getElementById('dojConfirmOv');
  const okBtn = document.getElementById('dcOk');
  const cancelBtn = document.getElementById('dcCancel');
  let cb = null;

  function close() { ov.classList.remove('open'); document.body.style.overflow = ''; }
  /**
   * DojConfirm({ title, message, target, okText, icon, danger=true, onConfirm })
   */
  function open(cfg) {
    cfg = cfg || {};
    document.getElementById('dcTitle').textContent = cfg.title || 'Confirmer';
    document.getElementById('dcMsg').innerHTML = cfg.message || '';
    const iconEl = document.getElementById('dcIcon');
    iconEl.className = 'dc-icon' + (cfg.danger === false ? ' dc-info' : '');
    iconEl.innerHTML = `<i class="fas fa-${cfg.icon || (cfg.danger === false ? 'circle-info' : 'triangle-exclamation')}"></i>`;
    const tw = document.getElementById('dcTargetWrap');
    if (cfg.target) { tw.style.display = ''; document.getElementById('dcTarget').textContent = cfg.target; }
    else tw.style.display = 'none';
    okBtn.textContent = cfg.okText || 'Confirmer';
    okBtn.className = 'dj-btn dj-btn-sm ' + (cfg.danger === false ? 'dj-btn-primary' : 'dj-btn-danger');
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

// ══ dojPersonField : champ « ajouter un citoyen » (bouton + chip + input caché) ══
// dojPersonField({ button, hidden, chip, title, items, required, selected, onChange })
window.dojPersonField = function (cfg) {
  const btn  = document.getElementById(cfg.button);
  const hid  = document.getElementById(cfg.hidden);
  const chip = document.getElementById(cfg.chip);
  if (!btn || !hid || !chip) return;
  const items = cfg.items || [];
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function render(it){
    if (!it) { chip.innerHTML=''; return; }
    const ava = it.avatar ? `<img src="${esc(it.avatar)}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;">`
      : `<span style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1e40af);display:inline-grid;place-items:center;font-size:.65rem;font-weight:700;color:#fff;">${esc((it.name||'?').charAt(0).toUpperCase())}</span>`;
    chip.innerHTML = '<span class="dp-field-chip">'+ava+esc(it.name)+'<span class="x"><i class="fas fa-times"></i></span></span>';
    chip.querySelector('.x').onclick = () => { hid.value=''; chip.innerHTML=''; if (cfg.onChange) cfg.onChange(null); };
  }
  btn.addEventListener('click', () => DojPicker.open({
    title: cfg.title || 'Sélection citoyen', items,
    onConfirm: (it) => { if(!it) return; hid.value = it.id; render(it); if (cfg.onChange) cfg.onChange(it); }
  }));
  if (cfg.selected) { const it = items.find(x => String(x.id) === String(cfg.selected)); if (it) { hid.value = it.id; render(it); } }
  if (cfg.required !== false) {
    const form = hid.closest('form');
    if (form) form.addEventListener('submit', e => { if (!hid.value) { e.preventDefault(); dojToast('Veuillez sélectionner '+(cfg.title?cfg.title.toLowerCase():'un citoyen'),'error'); } });
  }
  // Affecte la sélection par id (ex. auto-remplissage depuis un autre champ)
  function setById(id){ const it = items.find(x => String(x.id) === String(id)); if (it){ hid.value = it.id; render(it); if (cfg.onChange) cfg.onChange(it); } }
  function clear(){ hid.value = ''; chip.innerHTML = ''; if (cfg.onChange) cfg.onChange(null); }
  return { set: render, setById, clear };
};

// ══ Confirmation de formulaire déléguée ════════════════════════════════════════
// Tout <form data-doj-confirm="message"> affiche DojConfirm avant de se soumettre.
// Options : data-doj-confirm-title, data-doj-confirm-target, data-doj-confirm-ok,
//           data-doj-confirm-icon, data-doj-confirm-danger="false".
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || !form.dataset.dojConfirm || form.dataset.dojConfirmed) return;
  e.preventDefault();
  DojConfirm({
    title:   form.dataset.dojConfirmTitle  || 'Confirmation',
    message: form.dataset.dojConfirm,
    target:  form.dataset.dojConfirmTarget || '',
    okText:  form.dataset.dojConfirmOk     || 'Confirmer',
    icon:    form.dataset.dojConfirmIcon   || '',
    danger:  form.dataset.dojConfirmDanger !== 'false',
    onConfirm: () => { form.dataset.dojConfirmed = '1'; form.submit(); }
  });
}, true);

// Boutons/liens avec data-doj-confirm + (onclick logique via data-doj-confirm-run)
// Usage simple : <button data-doj-confirm="..." onclick="maFn()"> → la fonction
// n'est appelée qu'après confirmation. (intercepte le click en capture)
document.addEventListener('click', function (e) {
  const t = e.target.closest('[data-doj-confirm-click]');
  if (!t || t.dataset.dojConfirmed) return;
  e.preventDefault(); e.stopPropagation();
  DojConfirm({
    title:   t.dataset.dojConfirmTitle  || 'Confirmation',
    message: t.dataset.dojConfirmClick,
    target:  t.dataset.dojConfirmTarget || '',
    okText:  t.dataset.dojConfirmOk     || 'Confirmer',
    danger:  t.dataset.dojConfirmDanger !== 'false',
    onConfirm: () => { t.dataset.dojConfirmed = '1'; t.click(); setTimeout(() => { delete t.dataset.dojConfirmed; }, 0); }
  });
}, true);

// Interrupteurs custom : clic délégué (toute .doj-switch avec data-target)
document.addEventListener('click', function (e) {
  const sw = e.target.closest('.doj-switch'); if (!sw) return;
  const on = sw.classList.toggle('on');
  const t = sw.dataset.target ? document.getElementById(sw.dataset.target) : null;
  if (t) { t.value = on ? '1' : ''; t.dispatchEvent(new Event('change', { bubbles:true })); }
});

// ══ DojSelect : remplace automatiquement TOUS les <select> natifs ════════════════
window.DojSelect = (function () {
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

  function enhance(sel) {
    if (!sel || sel.tagName !== 'SELECT') return;
    if (sel.dataset.djsDone || sel.classList.contains('no-djs') || ('noEnhance' in sel.dataset)) return;
    sel.dataset.djsDone = '1';
    const multiple = sel.multiple;

    const wrap = document.createElement('div'); wrap.className = 'djs-wrap';
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (sel.style[p]) wrap.style[p] = sel.style[p]; });
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel); sel.classList.add('djs-native');
    if (sel.disabled) wrap.classList.add('djs-disabled');

    const ctrl = document.createElement('div'); ctrl.className = 'djs-control'; ctrl.tabIndex = sel.disabled ? -1 : 0;
    ctrl.setAttribute('role','combobox'); ctrl.setAttribute('aria-haspopup','listbox'); ctrl.setAttribute('aria-expanded','false');
    ctrl.innerHTML = '<span class="djs-value"></span><i class="fas fa-chevron-down djs-arrow"></i>';
    wrap.appendChild(ctrl);

    const panel = document.createElement('div'); panel.className = 'djs-panel'; panel.setAttribute('role','listbox');
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
        const s = document.createElement('div'); s.className = 'djs-search';
        s.innerHTML = '<i class="fas fa-search"></i><input type="text" placeholder="Rechercher...">';
        panel.appendChild(s); searchInput = s.querySelector('input');
        searchInput.addEventListener('input', () => filter(searchInput.value));
        searchInput.addEventListener('keydown', onKey);
      } else searchInput = null;
      const list = document.createElement('div'); list.className = 'djs-options'; panel.appendChild(list);
      m.forEach(it => {
        if (it.group) { const g = document.createElement('div'); g.className = 'djs-group'; g.textContent = it.group; list.appendChild(g); return; }
        const o = document.createElement('div');
        o.className = 'djs-opt' + (it.selected ? ' selected' : '') + (it.disabled ? ' disabled' : '');
        o.setAttribute('role','option');
        let html = '';
        if (it.avatar) html += '<img class="djs-oava" src="' + esc(it.avatar) + '" alt="">';
        else if (it.icon) html += '<span class="djs-oico"><i class="' + esc(it.icon) + '"></i></span>';
        html += '<span class="djs-otext"></span><i class="fas fa-check djs-check"></i>';
        o.innerHTML = html; o.querySelector('.djs-otext').textContent = it.label || '—';
        o.addEventListener('click', () => { if (!it.disabled) choose(it.el, o); });
        list.appendChild(o); optEls.push(o);
      });
      const empty = document.createElement('div'); empty.className = 'djs-empty'; empty.textContent = 'Aucun résultat'; empty.style.display = 'none';
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
    function fire() { sel.dispatchEvent(new Event('change', { bubbles: true })); wrap.classList.remove('djs-error'); }
    function updateValue() {
      const v = ctrl.querySelector('.djs-value');
      const chosen = Array.from(sel.selectedOptions);
      if (multiple) {
        if (!chosen.length) { v.className = 'djs-value placeholder'; v.textContent = sel.dataset.placeholder || 'Sélectionner…'; }
        else { v.className = 'djs-value'; v.textContent = chosen.map(o => o.textContent.trim()).join(', '); }
      } else {
        const o = chosen[0];
        if (!o || o.value === '') { v.className = 'djs-value placeholder'; v.textContent = (o ? o.textContent.trim() : '') || sel.dataset.placeholder || 'Sélectionner…'; }
        else {
          v.className = 'djs-value'; let html = '';
          if (o.dataset.avatar) html += '<img class="djs-ava" src="' + esc(o.dataset.avatar) + '">';
          else if (o.dataset.icon) html += '<i class="' + esc(o.dataset.icon) + '" style="color:var(--doj-blue-bright,#3b82f6)"></i>';
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
      if (wrap.classList.contains('djs-disabled')) return;
      closeAll(); rebuild(); wrap.classList.add('open'); ctrl.setAttribute('aria-expanded','true');
      positionPanel(ctrl, panel);
      if (searchInput) setTimeout(() => searchInput.focus(), 30);
      else setTimeout(() => setActive(panel.querySelector('.djs-opt.selected') || visible()[0]), 30);
    }
    function close() { wrap.classList.remove('open'); ctrl.setAttribute('aria-expanded','false'); }

    ctrl.addEventListener('click', () => { wrap.classList.contains('open') ? close() : open(); });
    ctrl.addEventListener('keydown', e => {
      if (!wrap.classList.contains('open')) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      } else { onKey(e); }
    });
    // Rafraîchit l'affichage custom quand le <select> natif est modifié par programme
    sel.addEventListener('change', updateValue);
    rebuild();
  }

  function positionPanel(ctrl, panel) {
    const r = ctrl.getBoundingClientRect();
    panel.style.position = 'fixed'; panel.style.left = r.left + 'px'; panel.style.width = r.width + 'px';
    const below = window.innerHeight - r.bottom, ph = Math.min(300, panel.offsetHeight || 300);
    if (below < ph + 12 && r.top > below) { panel.style.top = 'auto'; panel.style.bottom = (window.innerHeight - r.top + 6) + 'px'; }
    else { panel.style.bottom = 'auto'; panel.style.top = (r.bottom + 6) + 'px'; }
  }
  function repositionOpen() { const w = document.querySelector('.djs-wrap.open'); if (!w) return; positionPanel(w.querySelector('.djs-control'), w.querySelector('.djs-panel')); }
  function closeAll() {
    document.querySelectorAll('.djs-wrap.open').forEach(w => { w.classList.remove('open'); const c = w.querySelector('.djs-control'); if (c) c.setAttribute('aria-expanded','false'); });
  }
  function enhanceAll(root) { (root || document).querySelectorAll('select').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.djs-wrap') && !e.target.closest('.djs-panel')) closeAll(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll };
})();

// ══ DojDateTime : remplace les inputs date/time/datetime-local/month natifs ══════
window.DojDateTime = (function () {
  const MONTHS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  const DOW = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
  const KINDS = ['date','time','datetime-local','month'];
  const pad = n => String(n).padStart(2,'0');
  const el = (tag, cls, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; };

  function parse(val, kind) {
    if (!val) return null;
    let m;
    const n = new Date();
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
    if (!inp || inp.tagName !== 'INPUT' || inp.dataset.djtDone) return;
    const kind = (inp.getAttribute('type') || '').toLowerCase();
    if (!KINDS.includes(kind) || inp.classList.contains('no-djt') || ('noEnhance' in inp.dataset)) return;
    inp.dataset.djtDone = '1';
    inp.tabIndex = -1;
    const isDT = kind === 'datetime-local';

    const wrap = el('div','djt-wrap');
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (inp.style[p]) wrap.style[p] = inp.style[p]; });
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp); inp.classList.add('djt-native');

    const icon = kind === 'time' ? 'fa-clock' : (kind === 'month' ? 'fa-calendar' : 'fa-calendar-days');
    const ctrl = el('div','djs-control djt-control'); ctrl.tabIndex = inp.disabled ? -1 : 0;
    ctrl.setAttribute('role','button'); ctrl.setAttribute('aria-haspopup','dialog');
    ctrl.innerHTML = '<i class="fas ' + icon + ' djt-ico"></i><span class="djs-value"></span><i class="fas fa-chevron-down djs-arrow"></i>';
    wrap.appendChild(ctrl);
    if (inp.disabled) wrap.classList.add('djs-disabled');

    const pop = el('div','djt-pop' + (isDT ? ' djt-pop-dual' : '')); wrap.appendChild(pop);

    const now = new Date();
    let s = parse(inp.value, kind) || { y:now.getFullYear(), m:now.getMonth(), d:now.getDate(), hh:now.getHours(), mm:now.getMinutes(), empty:true };
    let view = { y:s.y, m:s.m };

    function updateCtrl() {
      const v = ctrl.querySelector('.djs-value');
      if (!inp.value) { v.className = 'djs-value placeholder'; v.textContent = inp.dataset.placeholder || placeholder(kind); }
      else { v.className = 'djs-value'; v.textContent = fmtHuman(s, kind); }
    }
    function commit(close) { s.empty = false; inp.value = fmtValue(s, kind); inp.dispatchEvent(new Event('change',{bubbles:true})); wrap.classList.remove('djs-error'); updateCtrl(); if (close) closePop(); }
    function updateFooter() { const ft = pop.querySelector('.djt-ft'); if (ft && ft._upd) ft._upd(); }

    function calPicker() {
      const box = el('div');
      const head = el('div','djt-head');
      const nav = (ic, fn) => { const b = el('button','djt-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','djt-title');
      head.appendChild(nav('fa-angles-left', () => { view.y--; render(); }));
      head.appendChild(nav('fa-angle-left', () => { view.m--; if (view.m<0){view.m=11;view.y--;} render(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.m++; if (view.m>11){view.m=0;view.y++;} render(); }));
      head.appendChild(nav('fa-angles-right', () => { view.y++; render(); }));
      box.appendChild(head);
      const dow = el('div','djt-grid'); DOW.forEach(d => dow.appendChild(el('div','djt-dow',d))); box.appendChild(dow);
      const grid = el('div','djt-grid'); box.appendChild(grid);
      function render() {
        title.textContent = MONTHS[view.m] + ' ' + view.y; grid.innerHTML = '';
        const start = (new Date(view.y,view.m,1).getDay()+6)%7;
        const dim = new Date(view.y,view.m+1,0).getDate();
        const prev = new Date(view.y,view.m,0).getDate();
        const t = new Date();
        for (let i=0;i<start;i++) grid.appendChild(el('div','djt-day muted', String(prev-start+1+i)));
        for (let d=1;d<=dim;d++) {
          const c = el('div','djt-day', String(d));
          if (t.getFullYear()===view.y && t.getMonth()===view.m && t.getDate()===d) c.classList.add('today');
          if (!s.empty && s.y===view.y && s.m===view.m && s.d===d) c.classList.add('selected');
          c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=view.m; s.d=d; s.empty=false;
            if (isDT) { render(); updateFooter(); } else commit(true); };
          grid.appendChild(c);
        }
        const tail = (7 - ((start+dim)%7))%7;
        for (let i=1;i<=tail;i++) grid.appendChild(el('div','djt-day muted', String(i)));
      }
      render();
      return box;
    }

    function timePicker() {
      const box = el('div','djt-time');
      const col = (lbl, max, get, set) => {
        const c = el('div','djt-col'); c.appendChild(el('div','djt-col-lbl', lbl));
        const sc = el('div','djt-scroll');
        for (let i=0;i<max;i++) {
          const tk = el('div','djt-tick', pad(i));
          if (get()===i) tk.classList.add('selected');
          tk.onclick = e => { e.stopPropagation(); set(i); s.empty=false; sc.querySelectorAll('.djt-tick').forEach(x=>x.classList.remove('selected')); tk.classList.add('selected'); updateFooter(); };
          sc.appendChild(tk);
        }
        c.appendChild(sc);
        setTimeout(() => { const sel = sc.querySelector('.djt-tick.selected'); if (sel) sel.scrollIntoView({ block:'center' }); }, 20);
        return c;
      };
      box.appendChild(col('Heures', 24, () => s.hh, v => s.hh=v));
      box.appendChild(col('Minutes', 60, () => s.mm, v => s.mm=v));
      return box;
    }

    function monthPicker() {
      const box = el('div');
      const head = el('div','djt-head');
      const nav = (ic, fn) => { const b = el('button','djt-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','djt-title'); title.textContent = view.y;
      head.appendChild(nav('fa-angle-left', () => { view.y--; title.textContent = view.y; mark(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.y++; title.textContent = view.y; mark(); }));
      box.appendChild(head);
      const grid = el('div','djt-months'); const cells = [];
      MONTHS.forEach((mn, i) => { const c = el('div','djt-month', mn.slice(0,4)); c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=i; commit(true); }; grid.appendChild(c); cells.push(c); });
      function mark() { cells.forEach((c,i) => c.classList.toggle('selected', !s.empty && s.y===view.y && s.m===i)); }
      mark(); box.appendChild(grid);
      return box;
    }

    function footer() {
      const ft = el('div','djt-ft');
      const prev = el('div','djt-preview');
      ft._upd = () => { prev.textContent = fmtHuman(s, kind); };
      ft._upd();
      const btns = el('div'); btns.style.cssText = 'display:flex;gap:.5rem';
      const quick = el('button','dj-btn dj-btn-ghost dj-btn-sm', '<i class="fas fa-bolt"></i> ' + (kind === 'time' ? 'Maintenant' : "Aujourd'hui"));
      quick.type = 'button';
      quick.onclick = e => { e.stopPropagation(); const n = new Date(); s.y=n.getFullYear(); s.m=n.getMonth(); s.d=n.getDate(); s.hh=n.getHours(); s.mm=n.getMinutes(); s.empty=false; view={y:s.y,m:s.m}; if (kind==='date') commit(true); else { build(); updateFooter(); } };
      const ok = el('button','dj-btn dj-btn-primary dj-btn-sm', '<i class="fas fa-check"></i> Valider'); ok.type='button';
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
      else { const row = el('div','djt-row'); const l = el('div'); l.appendChild(calPicker()); const r = el('div'); r.appendChild(timePicker()); row.appendChild(l); row.appendChild(r); pop.appendChild(row); pop.appendChild(footer()); }
    }

    function openPop() { if (wrap.classList.contains('djs-disabled')) return; closeAllDT(); view = { y:s.y, m:s.m }; build(); wrap.classList.add('open'); position(); }
    function closePop() { wrap.classList.remove('open'); }
    function position() {
      const r = ctrl.getBoundingClientRect();
      pop.style.left = r.left + 'px';
      const pw = pop.offsetWidth || 310, ph = pop.offsetHeight || 340;
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
    // Rafraîchit l'affichage quand la valeur est modifiée par programme (ex. édition)
    inp.addEventListener('change', () => { const p = parse(inp.value, kind); if (p) { s = p; view = { y:s.y, m:s.m }; } updateCtrl(); });
    updateCtrl();
  }

  function closeAllDT() { document.querySelectorAll('.djt-wrap.open').forEach(w => w.classList.remove('open')); }
  function repositionOpen() { const w = document.querySelector('.djt-wrap.open'); if (!w) return; const p = w.querySelector('.djt-pop'); if (p && p._position) p._position(); }
  function enhanceAll(root) { (root || document).querySelectorAll('input[type=date],input[type=time],input[type="datetime-local"],input[type=month]').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.djt-wrap') && !e.target.closest('.djt-pop')) closeAllDT(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll: closeAllDT };
})();
</script>
