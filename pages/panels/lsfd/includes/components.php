<?php
/**
 * Composants réutilisables du panel LSFD
 * pages/panels/lsfd/includes/components.php
 *
 * Inclus GLOBALEMENT via pages/panels/lsfd/includes/footer.php → actif sur toutes
 * les pages LSFD sans rien faire. Fournit, en pur JS (aucun <select> / input
 * date|time / confirm() / alert() natif) :
 *   - LsfdPicker.open({...})  : sélecteur de personnes (recherche + cartes + avatar).
 *   - LsfdConfirm({...})      : confirmation custom (remplace window.confirm()).
 *   - lsfdPersonField({...})  : champ « ajouter une personne » (bouton + chip).
 *   - LsfdSelect              : remplace AUTO tous les <select> natifs.
 *   - LsfdDateTime            : remplace AUTO les input date/time/datetime-local/month.
 *   - lsfdToast(msg, type)    : notification non-bloquante (remplace alert()).
 *
 * Thémé via --panel-color (rouge #dc2626 par défaut, suit l'admin/thème 3).
 */
?>
<style>
/* ══ Boutons utilitaires ══════════════════════════════════════════════════════ */
.lf-btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.6rem 1.1rem;
  font-family:'DM Sans',sans-serif; font-size:.85rem; font-weight:600; border-radius:8px; cursor:pointer;
  border:1px solid transparent; transition:all .2s; white-space:nowrap; text-decoration:none; }
.lf-btn-sm { padding:.45rem .8rem; font-size:.8rem; }
.lf-btn-primary { background:linear-gradient(135deg,var(--panel-color,#dc2626),#991b1b); color:#fff; box-shadow:0 4px 18px rgba(var(--panel-color-rgb,220,38,38),.28); }
.lf-btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 26px rgba(var(--panel-color-rgb,220,38,38),.45); }
.lf-btn-ghost { background:rgba(var(--panel-color-rgb,220,38,38),.08); color:var(--panel-color,#dc2626); border-color:rgba(var(--panel-color-rgb,220,38,38),.28); }
.lf-btn-ghost:hover { background:rgba(var(--panel-color-rgb,220,38,38),.16); border-color:rgba(var(--panel-color-rgb,220,38,38),.5); }
.lf-btn-danger { background:rgba(239,68,68,.12); color:#f87171; border-color:rgba(239,68,68,.3); }
.lf-btn-danger:hover { background:#ef4444; color:#fff; }

/* ══ Sélecteur de personnes (LsfdPicker) ══════════════════════════════════════ */
.lp-ov { position:fixed; inset:0; background:rgba(5,6,12,.9); backdrop-filter:blur(10px);
  z-index:10000; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.lp-ov.open { display:flex; }
.lp-modal { background:linear-gradient(135deg,#141824,#0a0c14); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.4);
  border-radius:16px; width:100%; max-width:560px; max-height:86vh; display:flex; flex-direction:column; overflow:hidden;
  box-shadow:0 0 60px rgba(var(--panel-color-rgb,220,38,38),.18),0 30px 90px rgba(0,0,0,.6); animation:lp-in .22s cubic-bezier(.34,1.56,.64,1); }
@keyframes lp-in { from{opacity:0;transform:scale(.95) translateY(12px)} to{opacity:1;transform:none} }
.lp-hd { padding:1.1rem 1.4rem; border-bottom:1px solid rgba(100,116,139,.15);
  display:flex; align-items:center; justify-content:space-between; background:rgba(var(--panel-color-rgb,220,38,38),.05); }
.lp-hd h2 { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:800; color:#f1f5f9;
  text-transform:uppercase; letter-spacing:.08em; display:flex; align-items:center; gap:.6rem; margin:0; }
.lp-hd h2 i { color:var(--panel-color,#dc2626); }
.lp-close { width:34px; height:34px; border-radius:9px; background:rgba(var(--panel-color-rgb,220,38,38),.08);
  border:1px solid rgba(var(--panel-color-rgb,220,38,38),.25); color:var(--panel-color,#dc2626); cursor:pointer;
  display:grid; place-items:center; font-size:1.2rem; transition:all .2s; }
.lp-close:hover { background:rgba(var(--panel-color-rgb,220,38,38),.2); transform:rotate(90deg); }
.lp-search { padding:.95rem 1.4rem; border-bottom:1px solid rgba(100,116,139,.12); position:relative; }
.lp-search i { position:absolute; left:1.95rem; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:.85rem; pointer-events:none; }
.lp-search input { width:100%; padding:.7rem 1rem .7rem 2.5rem; background:#0d1018;
  border:1px solid rgba(100,116,139,.2); border-radius:10px; color:#f1f5f9; font-family:'DM Sans',sans-serif; font-size:.9rem; outline:none; transition:all .2s; }
.lp-search input:focus { border-color:var(--panel-color,#dc2626); box-shadow:0 0 0 3px rgba(var(--panel-color-rgb,220,38,38),.12); }
.lp-list { overflow-y:auto; padding:.7rem; flex:1; display:flex; flex-direction:column; gap:.45rem; }
.lp-item { display:flex; align-items:center; gap:.85rem; padding:.7rem .85rem; border-radius:11px;
  border:1px solid transparent; cursor:pointer; transition:all .15s; background:rgba(255,255,255,.015); }
.lp-item:hover { background:rgba(var(--panel-color-rgb,220,38,38),.08); border-color:rgba(var(--panel-color-rgb,220,38,38),.2); }
.lp-item.selected { background:rgba(var(--panel-color-rgb,220,38,38),.14); border-color:var(--panel-color,#dc2626); }
.lp-ava { width:42px; height:42px; border-radius:11px; flex-shrink:0; overflow:hidden;
  background:linear-gradient(135deg,var(--panel-color,#dc2626),#991b1b); display:grid; place-items:center;
  font-family:'Syne',sans-serif; font-weight:800; color:#fff; font-size:.9rem; box-shadow:0 0 14px rgba(var(--panel-color-rgb,220,38,38),.3); }
.lp-ava img { width:100%; height:100%; object-fit:cover; }
.lp-info { flex:1; min-width:0; }
.lp-name { font-family:'DM Sans',sans-serif; font-weight:700; font-size:.92rem; color:#f1f5f9; }
.lp-sub { font-family:monospace; font-size:.72rem; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lp-mark { width:24px; height:24px; border-radius:8px; flex-shrink:0; border:2px solid rgba(var(--panel-color-rgb,220,38,38),.4);
  display:grid; place-items:center; color:#fff; font-size:.7rem; transition:all .15s; }
.lp-item.selected .lp-mark { background:var(--panel-color,#dc2626); border-color:var(--panel-color,#dc2626); }
.lp-mark i { opacity:0; transition:opacity .15s; }
.lp-item.selected .lp-mark i { opacity:1; }
.lp-empty { text-align:center; padding:2.5rem 1rem; color:#64748b; font-size:.85rem; }
.lp-empty i { font-size:2.2rem; opacity:.25; display:block; margin-bottom:.6rem; }
.lp-ft { padding:.9rem 1.4rem; border-top:1px solid rgba(100,116,139,.12);
  display:flex; align-items:center; justify-content:space-between; gap:.6rem; background:rgba(0,0,0,.2); }
.lp-count { font-size:.78rem; color:#94a3b8; }
.lp-count b { color:var(--panel-color,#dc2626); }
.lp-ft-btns { display:flex; gap:.5rem; }

/* ══ Confirmation custom (LsfdConfirm) ════════════════════════════════════════ */
.lc-ov { position:fixed; inset:0; background:rgba(5,6,12,.9); backdrop-filter:blur(10px);
  z-index:10001; display:none; align-items:center; justify-content:center; padding:1.5rem; }
.lc-ov.open { display:flex; }
.lc-modal { background:linear-gradient(135deg,#141824,#0a0c14); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.4);
  border-radius:16px; width:100%; max-width:420px; overflow:hidden;
  box-shadow:0 0 60px rgba(var(--panel-color-rgb,220,38,38),.18),0 30px 90px rgba(0,0,0,.6); animation:lp-in .2s cubic-bezier(.34,1.56,.64,1); }
.lc-body { padding:2rem 1.6rem 1.4rem; text-align:center; }
.lc-icon { width:62px; height:62px; border-radius:50%; margin:0 auto 1.2rem; display:grid; place-items:center;
  font-size:1.5rem; background:rgba(239,68,68,.1); border:2px solid rgba(239,68,68,.3); color:#f87171; animation:lc-pulse 1.7s ease-in-out infinite; }
.lc-icon.lc-info { background:rgba(var(--panel-color-rgb,220,38,38),.1); border-color:rgba(var(--panel-color-rgb,220,38,38),.3); color:var(--panel-color,#dc2626); }
@keyframes lc-pulse { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)} 50%{box-shadow:0 0 0 12px rgba(239,68,68,0)} }
.lc-title { font-family:'Syne',sans-serif; font-size:1rem; font-weight:800; color:#f1f5f9; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.6rem; }
.lc-msg { font-family:'DM Sans',sans-serif; font-size:.9rem; color:#94a3b8; line-height:1.6; }
.lc-target { font-family:monospace; font-size:.8rem; color:var(--panel-color,#dc2626);
  background:rgba(var(--panel-color-rgb,220,38,38),.08); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.2); border-radius:7px;
  padding:.4rem .85rem; margin:.5rem auto .2rem; display:inline-block; }
.lc-ft { padding:1rem 1.6rem 1.5rem; display:flex; gap:.6rem; justify-content:center; }

/* ══ Champ « personne » (bouton + chip) lié à LsfdPicker ══════════════════════ */
.lp-field-chip { display:inline-flex; align-items:center; gap:.55rem; padding:.45rem .8rem; border-radius:20px;
  font-family:'DM Sans',sans-serif; font-size:.82rem; font-weight:600;
  background:rgba(var(--panel-color-rgb,220,38,38),.1); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.3); color:var(--panel-color,#dc2626); }
.lp-field-chip .x { cursor:pointer; opacity:.7; } .lp-field-chip .x:hover { opacity:1; }

/* ══ Interrupteur custom (remplace les checkbox natives) ══════════════════════ */
.lsfd-switch { display:inline-flex; align-items:center; gap:.7rem; cursor:pointer; user-select:none; }
.lsfd-switch .sw-track { width:46px; height:26px; border-radius:26px; background:rgba(255,255,255,.12);
  border:1px solid rgba(100,116,139,.2); position:relative; flex-shrink:0; transition:all .2s; }
.lsfd-switch .sw-track::before { content:''; position:absolute; left:3px; top:2px; width:20px; height:20px; border-radius:50%; background:#fff; transition:transform .2s; }
.lsfd-switch.on .sw-track { background:var(--panel-color,#dc2626); border-color:var(--panel-color,#dc2626); }
.lsfd-switch.on .sw-track::before { transform:translateX(20px); }
.lsfd-switch .sw-lbl { font-family:'DM Sans',sans-serif; font-size:.85rem; color:#94a3b8; }

/* ══ Select custom (LsfdSelect) ═══════════════════════════════════════════════ */
.lss-wrap { position:relative; width:100%; }
.lss-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.lss-control { display:flex; align-items:center; gap:.5rem; width:100%; min-height:44px; padding:.6rem .9rem;
  background:#0d1018; border:1px solid rgba(100,116,139,.2); border-radius:8px;
  color:#f1f5f9; font-family:'DM Sans',sans-serif; font-size:.9rem; cursor:pointer; transition:all .15s; outline:none; position:relative; z-index:1; }
.lss-control:hover { border-color:rgba(var(--panel-color-rgb,220,38,38),.4); }
.lss-control:focus, .lss-wrap.open .lss-control, .lst-wrap.open .lss-control {
  border-color:var(--panel-color,#dc2626); box-shadow:0 0 0 3px rgba(var(--panel-color-rgb,220,38,38),.12); }
.lss-value { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:.5rem; }
.lss-value.placeholder { color:#475569; }
.lss-value .lss-ava { width:24px; height:24px; border-radius:6px; flex-shrink:0; object-fit:cover; }
.lss-arrow { font-size:.7rem; color:#94a3b8; transition:transform .2s; flex-shrink:0; }
.lss-wrap.open .lss-arrow, .lst-wrap.open .lss-arrow { transform:rotate(180deg); color:var(--panel-color,#dc2626); }
.lss-wrap.lss-disabled { opacity:.5; pointer-events:none; }
.lss-wrap.lss-error .lss-control { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.2); }
.lss-panel { position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:60;
  background:linear-gradient(135deg,#141824,#0a0c14); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.4);
  border-radius:11px; box-shadow:0 14px 44px rgba(0,0,0,.6); overflow:hidden; display:none; flex-direction:column; max-height:300px; animation:lss-in .16s ease; }
.lss-wrap.open .lss-panel { display:flex; }
@keyframes lss-in { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.lss-search { position:relative; padding:.55rem; border-bottom:1px solid rgba(100,116,139,.12); }
.lss-search i { position:absolute; left:1.1rem; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:.75rem; pointer-events:none; }
.lss-search input { width:100%; padding:.5rem .75rem .5rem 2rem; background:#0d1018;
  border:1px solid rgba(100,116,139,.2); border-radius:8px; color:#f1f5f9; font-size:.85rem; outline:none; font-family:'DM Sans',sans-serif; }
.lss-search input:focus { border-color:var(--panel-color,#dc2626); }
.lss-options { overflow-y:auto; padding:.35rem; display:flex; flex-direction:column; gap:1px; }
.lss-options::-webkit-scrollbar { width:6px; }
.lss-options::-webkit-scrollbar-thumb { background:rgba(var(--panel-color-rgb,220,38,38),.35); border-radius:3px; }
.lss-group { font-family:'Syne',sans-serif; font-size:.6rem; font-weight:800; text-transform:uppercase; letter-spacing:.12em; color:#94a3b8; padding:.55rem .7rem .25rem; }
.lss-opt { display:flex; align-items:center; gap:.6rem; padding:.55rem .7rem; border-radius:8px; cursor:pointer; font-family:'DM Sans',sans-serif; font-size:.88rem; color:#f1f5f9; transition:background .12s; }
.lss-opt:hover, .lss-opt.active { background:rgba(var(--panel-color-rgb,220,38,38),.12); }
.lss-opt.selected { background:rgba(var(--panel-color-rgb,220,38,38),.18); color:var(--panel-color,#dc2626); font-weight:600; }
.lss-opt.disabled { opacity:.4; pointer-events:none; }
.lss-opt .lss-oico { width:22px; text-align:center; flex-shrink:0; color:var(--panel-color,#dc2626); }
.lss-opt .lss-oava { width:28px; height:28px; border-radius:8px; object-fit:cover; flex-shrink:0; }
.lss-opt .lss-otext { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lss-opt .lss-check { opacity:0; color:var(--panel-color,#dc2626); font-size:.78rem; flex-shrink:0; }
.lss-opt.selected .lss-check { opacity:1; }
.lss-empty { padding:1.2rem; text-align:center; color:#94a3b8; font-size:.82rem; }

/* ══ Date / Heure custom (LsfdDateTime) ═══════════════════════════════════════ */
.lst-wrap { position:relative; width:100%; }
.lst-native { position:absolute; left:0; top:0; width:100%; height:100%; opacity:0; pointer-events:none; border:0; margin:0; }
.lst-control .lst-ico { color:var(--panel-color,#dc2626); font-size:.85rem; flex-shrink:0; }
.lst-pop { position:fixed; z-index:60; background:linear-gradient(135deg,#141824,#0a0c14);
  border:1px solid rgba(var(--panel-color-rgb,220,38,38),.4); border-radius:14px; box-shadow:0 16px 48px rgba(0,0,0,.6);
  padding:.9rem; display:none; animation:lss-in .16s ease; width:min(92vw,310px); }
.lst-wrap.open .lst-pop { display:block; }
.lst-pop.lst-pop-dual { width:min(94vw,560px); }
.lst-row { display:flex; gap:1rem; flex-wrap:wrap; }
.lst-row > div { flex:1; min-width:235px; }
.lst-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.6rem; gap:.3rem; }
.lst-nav { width:32px; height:32px; border-radius:8px; border:1px solid rgba(100,116,139,.2); background:rgba(var(--panel-color-rgb,220,38,38),.06);
  color:#94a3b8; cursor:pointer; display:grid; place-items:center; transition:all .15s; flex-shrink:0; font-size:.72rem; }
.lst-nav:hover { background:rgba(var(--panel-color-rgb,220,38,38),.18); color:var(--panel-color,#dc2626); border-color:rgba(var(--panel-color-rgb,220,38,38),.4); }
.lst-title { font-family:'Syne',sans-serif; font-weight:800; color:#f1f5f9; font-size:.9rem; text-transform:capitalize; padding:.2rem .55rem; border-radius:6px; flex:1; text-align:center; }
.lst-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.lst-dow { text-align:center; font-size:.6rem; font-weight:700; text-transform:uppercase; color:#94a3b8; padding:.3rem 0; font-family:'DM Sans',sans-serif; }
.lst-day { aspect-ratio:1; display:grid; place-items:center; border-radius:8px; font-family:monospace; font-size:.82rem; color:#f1f5f9; cursor:pointer; border:1px solid transparent; transition:all .12s; }
.lst-day:hover { background:rgba(var(--panel-color-rgb,220,38,38),.14); }
.lst-day.muted { color:#475569; opacity:.45; }
.lst-day.today { border-color:var(--panel-color,#dc2626); color:var(--panel-color,#dc2626); font-weight:700; }
.lst-day.selected { background:var(--panel-color,#dc2626); color:#fff; font-weight:700; box-shadow:0 0 14px rgba(var(--panel-color-rgb,220,38,38),.5); }
.lst-months { display:grid; grid-template-columns:repeat(3,1fr); gap:.45rem; }
.lst-month { padding:.65rem; border-radius:8px; text-align:center; font-family:'DM Sans',sans-serif; font-size:.82rem; color:#f1f5f9; cursor:pointer; border:1px solid transparent; transition:all .12s; text-transform:capitalize; }
.lst-month:hover { background:rgba(var(--panel-color-rgb,220,38,38),.14); }
.lst-month.selected { background:var(--panel-color,#dc2626); color:#fff; font-weight:700; }
.lst-time { display:flex; gap:.5rem; align-items:stretch; }
.lst-col { flex:1; display:flex; flex-direction:column; }
.lst-col-lbl { text-align:center; font-size:.6rem; font-weight:700; text-transform:uppercase; color:#94a3b8; margin-bottom:.3rem; font-family:'DM Sans',sans-serif; }
.lst-scroll { height:172px; overflow-y:auto; display:flex; flex-direction:column; gap:2px; padding-right:3px; }
.lst-scroll::-webkit-scrollbar { width:6px; }
.lst-scroll::-webkit-scrollbar-thumb { background:rgba(var(--panel-color-rgb,220,38,38),.35); border-radius:3px; }
.lst-tick { padding:.42rem; text-align:center; border-radius:8px; font-family:monospace; font-size:.84rem; color:#f1f5f9; cursor:pointer; border:1px solid transparent; transition:all .12s; }
.lst-tick:hover { background:rgba(var(--panel-color-rgb,220,38,38),.14); }
.lst-tick.selected { background:var(--panel-color,#dc2626); color:#fff; font-weight:700; }
.lst-ft { display:flex; align-items:center; justify-content:space-between; gap:.6rem; margin-top:.75rem; padding-top:.65rem; border-top:1px solid rgba(100,116,139,.12); flex-wrap:wrap; }
.lst-preview { font-family:monospace; font-size:.8rem; color:var(--panel-color,#dc2626); }

/* ══ Toasts LSFD (remplace alert()) ═══════════════════════════════════════════ */
#lsfdToastWrap { position:fixed; top:1.2rem; right:1.2rem; z-index:11000; display:flex; flex-direction:column; gap:.6rem; }
.lsfd-toast { display:flex; align-items:center; gap:.7rem; min-width:240px; max-width:380px; padding:.85rem 1.1rem;
  border-radius:11px; font-family:'DM Sans',sans-serif; font-size:.85rem; font-weight:500; color:#f1f5f9;
  background:linear-gradient(135deg,#141824,#0a0c14); border:1px solid rgba(var(--panel-color-rgb,220,38,38),.4);
  box-shadow:0 12px 40px rgba(0,0,0,.5); animation:lsfdToastIn .25s cubic-bezier(.34,1.56,.64,1); }
.lsfd-toast i { font-size:1.1rem; flex-shrink:0; }
.lsfd-toast.success { border-color:rgba(34,197,94,.4); } .lsfd-toast.success i { color:#22c55e; }
.lsfd-toast.error   { border-color:rgba(239,68,68,.4); } .lsfd-toast.error i { color:#ef4444; }
.lsfd-toast.info    { border-color:rgba(var(--panel-color-rgb,220,38,38),.4); } .lsfd-toast.info i { color:var(--panel-color,#dc2626); }
@keyframes lsfdToastIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:none} }
@keyframes lsfdToastOut { to{opacity:0;transform:translateX(30px)} }
</style>

<!-- Conteneur du sélecteur de personnes -->
<div class="lp-ov" id="lsfdPickerOv">
  <div class="lp-modal">
    <div class="lp-hd">
      <h2><i class="fas fa-user-magnifying-glass"></i> <span id="lpTitle">Sélection</span></h2>
      <button type="button" class="lp-close" onclick="LsfdPicker.close()">×</button>
    </div>
    <div class="lp-search">
      <i class="fas fa-search"></i>
      <input type="text" id="lpSearch" placeholder="Rechercher..." autocomplete="off">
    </div>
    <div class="lp-list" id="lpList"></div>
    <div class="lp-ft">
      <div class="lp-count" id="lpCount"></div>
      <div class="lp-ft-btns">
        <button type="button" class="lf-btn lf-btn-ghost lf-btn-sm" onclick="LsfdPicker.close()">Annuler</button>
        <button type="button" class="lf-btn lf-btn-primary lf-btn-sm" id="lpConfirm"><i class="fas fa-check"></i> Valider</button>
      </div>
    </div>
  </div>
</div>

<!-- Conteneur de confirmation custom -->
<div class="lc-ov" id="lsfdConfirmOv">
  <div class="lc-modal">
    <div class="lc-body">
      <div class="lc-icon" id="lcIcon"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="lc-title" id="lcTitle">Confirmer</div>
      <div id="lcTargetWrap" style="display:none"><span class="lc-target" id="lcTarget"></span></div>
      <div class="lc-msg" id="lcMsg"></div>
    </div>
    <div class="lc-ft">
      <button type="button" class="lf-btn lf-btn-ghost lf-btn-sm" id="lcCancel">Annuler</button>
      <button type="button" class="lf-btn lf-btn-danger lf-btn-sm" id="lcOk">Confirmer</button>
    </div>
  </div>
</div>

<!-- Conteneur des toasts -->
<div id="lsfdToastWrap"></div>

<script>
// ══ lsfdToast : notification non-bloquante (remplace alert) ═════════════════════
window.lsfdToast = function (msg, type) {
  const wrap = document.getElementById('lsfdToastWrap'); if (!wrap) return;
  type = type || 'info';
  const ic = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info');
  const t = document.createElement('div');
  t.className = 'lsfd-toast ' + type;
  t.innerHTML = '<i class="fas ' + ic + '"></i><span></span>';
  t.querySelector('span').textContent = msg;
  wrap.appendChild(t);
  setTimeout(() => { t.style.animation = 'lsfdToastOut .25s forwards'; setTimeout(() => t.remove(), 250); }, 3600);
};

// ══ LsfdPicker : sélecteur de personnes réutilisable ════════════════════════════
window.LsfdPicker = (function () {
  const ov = document.getElementById('lsfdPickerOv');
  const listEl = document.getElementById('lpList');
  const searchEl = document.getElementById('lpSearch');
  const countEl = document.getElementById('lpCount');
  const titleEl = document.getElementById('lpTitle');
  const confirmBtn = document.getElementById('lpConfirm');
  let items = [], selected = new Set(), multiple = false, onConfirm = null;

  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function render(filter) {
    const q = (filter || '').toLowerCase().trim();
    const shown = items.filter(it => !q || (it.search || (it.name + ' ' + (it.sub || ''))).toLowerCase().includes(q));
    if (!shown.length) {
      listEl.innerHTML = '<div class="lp-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div>';
    } else {
      listEl.innerHTML = shown.map(it => {
        const sel = selected.has(String(it.id)) ? ' selected' : '';
        const ava = it.avatar
          ? `<img src="${escapeHtml(it.avatar)}" alt="">`
          : (it.name ? escapeHtml(it.name.trim().charAt(0).toUpperCase()) : '?');
        return `<div class="lp-item${sel}" data-id="${escapeHtml(String(it.id))}">
            <div class="lp-ava">${ava}</div>
            <div class="lp-info">
              <div class="lp-name">${escapeHtml(it.name || '—')}</div>
              ${it.sub ? `<div class="lp-sub">${escapeHtml(it.sub)}</div>` : ''}
            </div>
            <div class="lp-mark"><i class="fas fa-check"></i></div>
          </div>`;
      }).join('');
      listEl.querySelectorAll('.lp-item').forEach(el => el.addEventListener('click', () => toggle(el.dataset.id)));
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
  /** open({ title, items, multiple, selected, onConfirm }) — items:[{id,name,sub,avatar,search}] */
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

// ══ LsfdConfirm : confirmation personnalisée (remplace window.confirm) ═══════════
window.LsfdConfirm = (function () {
  const ov = document.getElementById('lsfdConfirmOv');
  const okBtn = document.getElementById('lcOk');
  const cancelBtn = document.getElementById('lcCancel');
  let cb = null;

  function close() { ov.classList.remove('open'); document.body.style.overflow = ''; }
  /** LsfdConfirm({ title, message, target, okText, icon, danger=true, onConfirm }) */
  function open(cfg) {
    cfg = cfg || {};
    document.getElementById('lcTitle').textContent = cfg.title || 'Confirmer';
    document.getElementById('lcMsg').innerHTML = cfg.message || '';
    const iconEl = document.getElementById('lcIcon');
    iconEl.className = 'lc-icon' + (cfg.danger === false ? ' lc-info' : '');
    iconEl.innerHTML = `<i class="fas fa-${cfg.icon || (cfg.danger === false ? 'circle-info' : 'triangle-exclamation')}"></i>`;
    const tw = document.getElementById('lcTargetWrap');
    if (cfg.target) { tw.style.display = ''; document.getElementById('lcTarget').textContent = cfg.target; }
    else tw.style.display = 'none';
    okBtn.textContent = cfg.okText || 'Confirmer';
    okBtn.className = 'lf-btn lf-btn-sm ' + (cfg.danger === false ? 'lf-btn-primary' : 'lf-btn-danger');
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

// ══ lsfdPersonField : champ « ajouter une personne » (bouton + chip + input caché)
// lsfdPersonField({ button, hidden, chip, title, items, required, selected, onChange })
window.lsfdPersonField = function (cfg) {
  const btn  = document.getElementById(cfg.button);
  const hid  = document.getElementById(cfg.hidden);
  const chip = document.getElementById(cfg.chip);
  if (!btn || !hid || !chip) return;
  const items = cfg.items || [];
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function render(it){
    if (!it) { chip.innerHTML=''; return; }
    const ava = it.avatar ? `<img src="${esc(it.avatar)}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;">`
      : `<span style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--panel-color,#dc2626),#991b1b);display:inline-grid;place-items:center;font-size:.65rem;font-weight:700;color:#fff;">${esc((it.name||'?').charAt(0).toUpperCase())}</span>`;
    chip.innerHTML = '<span class="lp-field-chip">'+ava+esc(it.name)+'<span class="x"><i class="fas fa-times"></i></span></span>';
    chip.querySelector('.x').onclick = () => { hid.value=''; chip.innerHTML=''; if (cfg.onChange) cfg.onChange(null); };
  }
  btn.addEventListener('click', () => LsfdPicker.open({
    title: cfg.title || 'Sélection', items,
    onConfirm: (it) => { if(!it) return; hid.value = it.id; render(it); if (cfg.onChange) cfg.onChange(it); }
  }));
  if (cfg.selected) { const it = items.find(x => String(x.id) === String(cfg.selected)); if (it) { hid.value = it.id; render(it); } }
  if (cfg.required !== false) {
    const form = hid.closest('form');
    if (form) form.addEventListener('submit', e => { if (!hid.value) { e.preventDefault(); lsfdToast('Veuillez sélectionner '+(cfg.title?cfg.title.toLowerCase():'une personne'),'error'); } });
  }
  function setById(id){ const it = items.find(x => String(x.id) === String(id)); if (it){ hid.value = it.id; render(it); if (cfg.onChange) cfg.onChange(it); } }
  function clear(){ hid.value = ''; chip.innerHTML = ''; if (cfg.onChange) cfg.onChange(null); }
  return { set: render, setById, clear };
};

// ══ Confirmation de formulaire déléguée ════════════════════════════════════════
// Tout <form data-lsfd-confirm="message"> affiche LsfdConfirm avant submit.
// Options : data-lsfd-confirm-title, -target, -ok, -icon, -danger="false".
document.addEventListener('submit', function (e) {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || !form.dataset.lsfdConfirm || form.dataset.lsfdConfirmed) return;
  e.preventDefault();
  LsfdConfirm({
    title:   form.dataset.lsfdConfirmTitle  || 'Confirmation',
    message: form.dataset.lsfdConfirm,
    target:  form.dataset.lsfdConfirmTarget || '',
    okText:  form.dataset.lsfdConfirmOk     || 'Confirmer',
    icon:    form.dataset.lsfdConfirmIcon   || '',
    danger:  form.dataset.lsfdConfirmDanger !== 'false',
    onConfirm: () => { form.dataset.lsfdConfirmed = '1'; form.submit(); }
  });
}, true);

// Boutons/liens avec data-lsfd-confirm-click (la logique onclick n'est appelée qu'après confirmation)
document.addEventListener('click', function (e) {
  const t = e.target.closest('[data-lsfd-confirm-click]');
  if (!t || t.dataset.lsfdConfirmed) return;
  e.preventDefault(); e.stopPropagation();
  LsfdConfirm({
    title:   t.dataset.lsfdConfirmTitle  || 'Confirmation',
    message: t.dataset.lsfdConfirmClick,
    target:  t.dataset.lsfdConfirmTarget || '',
    okText:  t.dataset.lsfdConfirmOk     || 'Confirmer',
    danger:  t.dataset.lsfdConfirmDanger !== 'false',
    onConfirm: () => { t.dataset.lsfdConfirmed = '1'; t.click(); setTimeout(() => { delete t.dataset.lsfdConfirmed; }, 0); }
  });
}, true);

// Interrupteurs custom : clic délégué (toute .lsfd-switch avec data-target)
document.addEventListener('click', function (e) {
  const sw = e.target.closest('.lsfd-switch'); if (!sw) return;
  const on = sw.classList.toggle('on');
  const t = sw.dataset.target ? document.getElementById(sw.dataset.target) : null;
  if (t) { t.value = on ? '1' : ''; t.dispatchEvent(new Event('change', { bubbles:true })); }
});

// ══ LsfdSelect : remplace automatiquement TOUS les <select> natifs ═══════════════
window.LsfdSelect = (function () {
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

  function enhance(sel) {
    if (!sel || sel.tagName !== 'SELECT') return;
    if (sel.dataset.lssDone || sel.classList.contains('no-lss') || ('noEnhance' in sel.dataset)) return;
    sel.dataset.lssDone = '1';
    const multiple = sel.multiple;

    const wrap = document.createElement('div'); wrap.className = 'lss-wrap';
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (sel.style[p]) wrap.style[p] = sel.style[p]; });
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel); sel.classList.add('lss-native');
    if (sel.disabled) wrap.classList.add('lss-disabled');

    const ctrl = document.createElement('div'); ctrl.className = 'lss-control'; ctrl.tabIndex = sel.disabled ? -1 : 0;
    ctrl.setAttribute('role','combobox'); ctrl.setAttribute('aria-haspopup','listbox'); ctrl.setAttribute('aria-expanded','false');
    ctrl.innerHTML = '<span class="lss-value"></span><i class="fas fa-chevron-down lss-arrow"></i>';
    wrap.appendChild(ctrl);

    const panel = document.createElement('div'); panel.className = 'lss-panel'; panel.setAttribute('role','listbox');
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
        const s = document.createElement('div'); s.className = 'lss-search';
        s.innerHTML = '<i class="fas fa-search"></i><input type="text" placeholder="Rechercher...">';
        panel.appendChild(s); searchInput = s.querySelector('input');
        searchInput.addEventListener('input', () => filter(searchInput.value));
        searchInput.addEventListener('keydown', onKey);
      } else searchInput = null;
      const list = document.createElement('div'); list.className = 'lss-options'; panel.appendChild(list);
      m.forEach(it => {
        if (it.group) { const g = document.createElement('div'); g.className = 'lss-group'; g.textContent = it.group; list.appendChild(g); return; }
        const o = document.createElement('div');
        o.className = 'lss-opt' + (it.selected ? ' selected' : '') + (it.disabled ? ' disabled' : '');
        o.setAttribute('role','option');
        let html = '';
        if (it.avatar) html += '<img class="lss-oava" src="' + esc(it.avatar) + '" alt="">';
        else if (it.icon) html += '<span class="lss-oico"><i class="' + esc(it.icon) + '"></i></span>';
        html += '<span class="lss-otext"></span><i class="fas fa-check lss-check"></i>';
        o.innerHTML = html; o.querySelector('.lss-otext').textContent = it.label || '—';
        o.addEventListener('click', () => { if (!it.disabled) choose(it.el, o); });
        list.appendChild(o); optEls.push(o);
      });
      const empty = document.createElement('div'); empty.className = 'lss-empty'; empty.textContent = 'Aucun résultat'; empty.style.display = 'none';
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
    function fire() { sel.dispatchEvent(new Event('change', { bubbles: true })); wrap.classList.remove('lss-error'); }
    function updateValue() {
      const v = ctrl.querySelector('.lss-value');
      const chosen = Array.from(sel.selectedOptions);
      if (multiple) {
        if (!chosen.length) { v.className = 'lss-value placeholder'; v.textContent = sel.dataset.placeholder || 'Sélectionner…'; }
        else { v.className = 'lss-value'; v.textContent = chosen.map(o => o.textContent.trim()).join(', '); }
      } else {
        const o = chosen[0];
        if (!o || o.value === '') { v.className = 'lss-value placeholder'; v.textContent = (o ? o.textContent.trim() : '') || sel.dataset.placeholder || 'Sélectionner…'; }
        else {
          v.className = 'lss-value'; let html = '';
          if (o.dataset.avatar) html += '<img class="lss-ava" src="' + esc(o.dataset.avatar) + '">';
          else if (o.dataset.icon) html += '<i class="' + esc(o.dataset.icon) + '" style="color:var(--panel-color,#dc2626)"></i>';
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
      if (wrap.classList.contains('lss-disabled')) return;
      closeAll(); rebuild(); wrap.classList.add('open'); ctrl.setAttribute('aria-expanded','true');
      positionPanel(ctrl, panel);
      if (searchInput) setTimeout(() => searchInput.focus(), 30);
      else setTimeout(() => setActive(panel.querySelector('.lss-opt.selected') || visible()[0]), 30);
    }
    function close() { wrap.classList.remove('open'); ctrl.setAttribute('aria-expanded','false'); }

    ctrl.addEventListener('click', () => { wrap.classList.contains('open') ? close() : open(); });
    ctrl.addEventListener('keydown', e => {
      if (!wrap.classList.contains('open')) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      } else { onKey(e); }
    });
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
  function repositionOpen() { const w = document.querySelector('.lss-wrap.open'); if (!w) return; positionPanel(w.querySelector('.lss-control'), w.querySelector('.lss-panel')); }
  function closeAll() {
    document.querySelectorAll('.lss-wrap.open').forEach(w => { w.classList.remove('open'); const c = w.querySelector('.lss-control'); if (c) c.setAttribute('aria-expanded','false'); });
  }
  function enhanceAll(root) { (root || document).querySelectorAll('select').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.lss-wrap') && !e.target.closest('.lss-panel')) closeAll(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll };
})();

// ══ LsfdDateTime : remplace les inputs date/time/datetime-local/month natifs ═════
window.LsfdDateTime = (function () {
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
    if (!inp || inp.tagName !== 'INPUT' || inp.dataset.lstDone) return;
    const kind = (inp.getAttribute('type') || '').toLowerCase();
    if (!KINDS.includes(kind) || inp.classList.contains('no-lst') || ('noEnhance' in inp.dataset)) return;
    inp.dataset.lstDone = '1';
    inp.tabIndex = -1;
    const isDT = kind === 'datetime-local';

    const wrap = el('div','lst-wrap');
    ['width','minWidth','maxWidth','flex'].forEach(p => { if (inp.style[p]) wrap.style[p] = inp.style[p]; });
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp); inp.classList.add('lst-native');

    const icon = kind === 'time' ? 'fa-clock' : (kind === 'month' ? 'fa-calendar' : 'fa-calendar-days');
    const ctrl = el('div','lss-control lst-control'); ctrl.tabIndex = inp.disabled ? -1 : 0;
    ctrl.setAttribute('role','button'); ctrl.setAttribute('aria-haspopup','dialog');
    ctrl.innerHTML = '<i class="fas ' + icon + ' lst-ico"></i><span class="lss-value"></span><i class="fas fa-chevron-down lss-arrow"></i>';
    wrap.appendChild(ctrl);
    if (inp.disabled) wrap.classList.add('lss-disabled');

    const pop = el('div','lst-pop' + (isDT ? ' lst-pop-dual' : '')); wrap.appendChild(pop);

    const now = new Date();
    let s = parse(inp.value, kind) || { y:now.getFullYear(), m:now.getMonth(), d:now.getDate(), hh:now.getHours(), mm:now.getMinutes(), empty:true };
    let view = { y:s.y, m:s.m };

    function updateCtrl() {
      const v = ctrl.querySelector('.lss-value');
      if (!inp.value) { v.className = 'lss-value placeholder'; v.textContent = inp.dataset.placeholder || placeholder(kind); }
      else { v.className = 'lss-value'; v.textContent = fmtHuman(s, kind); }
    }
    function commit(close) { s.empty = false; inp.value = fmtValue(s, kind); inp.dispatchEvent(new Event('change',{bubbles:true})); wrap.classList.remove('lss-error'); updateCtrl(); if (close) closePop(); }
    function updateFooter() { const ft = pop.querySelector('.lst-ft'); if (ft && ft._upd) ft._upd(); }

    function calPicker() {
      const box = el('div');
      const head = el('div','lst-head');
      const nav = (ic, fn) => { const b = el('button','lst-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','lst-title');
      head.appendChild(nav('fa-angles-left', () => { view.y--; render(); }));
      head.appendChild(nav('fa-angle-left', () => { view.m--; if (view.m<0){view.m=11;view.y--;} render(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.m++; if (view.m>11){view.m=0;view.y++;} render(); }));
      head.appendChild(nav('fa-angles-right', () => { view.y++; render(); }));
      box.appendChild(head);
      const dow = el('div','lst-grid'); DOW.forEach(d => dow.appendChild(el('div','lst-dow',d))); box.appendChild(dow);
      const grid = el('div','lst-grid'); box.appendChild(grid);
      function render() {
        title.textContent = MONTHS[view.m] + ' ' + view.y; grid.innerHTML = '';
        const start = (new Date(view.y,view.m,1).getDay()+6)%7;
        const dim = new Date(view.y,view.m+1,0).getDate();
        const prev = new Date(view.y,view.m,0).getDate();
        const t = new Date();
        for (let i=0;i<start;i++) grid.appendChild(el('div','lst-day muted', String(prev-start+1+i)));
        for (let d=1;d<=dim;d++) {
          const c = el('div','lst-day', String(d));
          if (t.getFullYear()===view.y && t.getMonth()===view.m && t.getDate()===d) c.classList.add('today');
          if (!s.empty && s.y===view.y && s.m===view.m && s.d===d) c.classList.add('selected');
          c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=view.m; s.d=d; s.empty=false;
            if (isDT) { render(); updateFooter(); } else commit(true); };
          grid.appendChild(c);
        }
        const tail = (7 - ((start+dim)%7))%7;
        for (let i=1;i<=tail;i++) grid.appendChild(el('div','lst-day muted', String(i)));
      }
      render();
      return box;
    }

    function timePicker() {
      const box = el('div','lst-time');
      const col = (lbl, max, get, set) => {
        const c = el('div','lst-col'); c.appendChild(el('div','lst-col-lbl', lbl));
        const sc = el('div','lst-scroll');
        for (let i=0;i<max;i++) {
          const tk = el('div','lst-tick', pad(i));
          if (get()===i) tk.classList.add('selected');
          tk.onclick = e => { e.stopPropagation(); set(i); s.empty=false; sc.querySelectorAll('.lst-tick').forEach(x=>x.classList.remove('selected')); tk.classList.add('selected'); updateFooter(); };
          sc.appendChild(tk);
        }
        c.appendChild(sc);
        setTimeout(() => { const sel = sc.querySelector('.lst-tick.selected'); if (sel) sel.scrollIntoView({ block:'center' }); }, 20);
        return c;
      };
      box.appendChild(col('Heures', 24, () => s.hh, v => s.hh=v));
      box.appendChild(col('Minutes', 60, () => s.mm, v => s.mm=v));
      return box;
    }

    function monthPicker() {
      const box = el('div');
      const head = el('div','lst-head');
      const nav = (ic, fn) => { const b = el('button','lst-nav','<i class="fas ' + ic + '"></i>'); b.type='button'; b.onclick = e => { e.stopPropagation(); fn(); }; return b; };
      const title = el('div','lst-title'); title.textContent = view.y;
      head.appendChild(nav('fa-angle-left', () => { view.y--; title.textContent = view.y; mark(); }));
      head.appendChild(title);
      head.appendChild(nav('fa-angle-right', () => { view.y++; title.textContent = view.y; mark(); }));
      box.appendChild(head);
      const grid = el('div','lst-months'); const cells = [];
      MONTHS.forEach((mn, i) => { const c = el('div','lst-month', mn.slice(0,4)); c.onclick = e => { e.stopPropagation(); s.y=view.y; s.m=i; commit(true); }; grid.appendChild(c); cells.push(c); });
      function mark() { cells.forEach((c,i) => c.classList.toggle('selected', !s.empty && s.y===view.y && s.m===i)); }
      mark(); box.appendChild(grid);
      return box;
    }

    function footer() {
      const ft = el('div','lst-ft');
      const prev = el('div','lst-preview');
      ft._upd = () => { prev.textContent = fmtHuman(s, kind); };
      ft._upd();
      const btns = el('div'); btns.style.cssText = 'display:flex;gap:.5rem';
      const quick = el('button','lf-btn lf-btn-ghost lf-btn-sm', '<i class="fas fa-bolt"></i> ' + (kind === 'time' ? 'Maintenant' : "Aujourd'hui"));
      quick.type = 'button';
      quick.onclick = e => { e.stopPropagation(); const n = new Date(); s.y=n.getFullYear(); s.m=n.getMonth(); s.d=n.getDate(); s.hh=n.getHours(); s.mm=n.getMinutes(); s.empty=false; view={y:s.y,m:s.m}; if (kind==='date') commit(true); else { build(); updateFooter(); } };
      const ok = el('button','lf-btn lf-btn-primary lf-btn-sm', '<i class="fas fa-check"></i> Valider'); ok.type='button';
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
      else { const row = el('div','lst-row'); const l = el('div'); l.appendChild(calPicker()); const r = el('div'); r.appendChild(timePicker()); row.appendChild(l); row.appendChild(r); pop.appendChild(row); pop.appendChild(footer()); }
    }

    function openPop() { if (wrap.classList.contains('lss-disabled')) return; closeAllDT(); view = { y:s.y, m:s.m }; build(); wrap.classList.add('open'); position(); }
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
    inp.addEventListener('change', () => { const p = parse(inp.value, kind); if (p) { s = p; view = { y:s.y, m:s.m }; } updateCtrl(); });
    updateCtrl();
  }

  function closeAllDT() { document.querySelectorAll('.lst-wrap.open').forEach(w => w.classList.remove('open')); }
  function repositionOpen() { const w = document.querySelector('.lst-wrap.open'); if (!w) return; const p = w.querySelector('.lst-pop'); if (p && p._position) p._position(); }
  function enhanceAll(root) { (root || document).querySelectorAll('input[type=date],input[type=time],input[type="datetime-local"],input[type=month]').forEach(enhance); }

  document.addEventListener('click', e => { if (!e.target.closest('.lst-wrap') && !e.target.closest('.lst-pop')) closeAllDT(); });
  window.addEventListener('scroll', repositionOpen, true);
  window.addEventListener('resize', repositionOpen);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll());
  else enhanceAll();

  return { enhance, enhanceAll, closeAll: closeAllDT };
})();
</script>
