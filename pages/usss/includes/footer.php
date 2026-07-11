</div><!-- /us-content -->
</main><!-- /us-main -->

<!-- ══ CONFIRM MODAL ══ -->
<div id="usss-confirm-ov" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);z-index:99999;align-items:center;justify-content:center;padding:1.5rem">
  <div style="background:linear-gradient(140deg,#0f1422,#07090f);border:1px solid rgba(191,28,46,.3);border-radius:12px;max-width:400px;width:90%;overflow:hidden;box-shadow:0 0 60px rgba(0,0,0,.7)">
    <div style="padding:1.5rem 1.5rem 1rem;text-align:center">
      <div id="usss-confirm-ico" style="width:56px;height:56px;border-radius:50%;margin:0 auto .85rem;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:rgba(191,28,46,.12);border:2px solid rgba(191,28,46,.3);color:#f56270"><i class="fas fa-trash"></i></div>
      <div id="usss-confirm-title" style="font-family:'Barlow Condensed',sans-serif;font-size:1.1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#fff;margin-bottom:.45rem">Confirmer</div>
      <div id="usss-confirm-msg" style="font-size:.82rem;color:#7a8aa8;line-height:1.55">Cette action est irréversible.</div>
      <div id="usss-confirm-target" style="display:none;margin-top:.6rem;padding:.35rem .75rem;background:rgba(191,28,46,.07);border:1px solid rgba(191,28,46,.2);border-radius:6px;font-family:'IBM Plex Mono',monospace;font-size:.72rem;color:#f56270"></div>
    </div>
    <div style="padding:.75rem 1.5rem 1.35rem;display:flex;justify-content:center;gap:.55rem">
      <button class="btn btn-ghost btn-sm" onclick="USSS.confirmCancel()">Annuler</button>
      <button id="usss-confirm-ok-btn" class="btn btn-danger btn-sm" onclick="USSS.confirmOk()">
        <i class="fas fa-check"></i> <span id="usss-confirm-ok-lbl">Confirmer</span>
      </button>
    </div>
  </div>
</div>

<!-- ══ TOAST CONTAINER ══ -->
<div id="usss-toasts" style="position:fixed;top:72px;right:18px;z-index:99998;display:flex;flex-direction:column;gap:.45rem;pointer-events:none"></div>

<script>
const USSS = {
  /* ── Modals ── */
  openModal(id)  { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
  closeModal(id) { const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} },

  /* ── Confirm ── */
  _confirmCb: null, _confirmForm: null,
  confirm(o={}) {
    const {title='Confirmer la suppression',msg='Cette action est <strong>irréversible</strong>.',target='',icon='fas fa-trash',okLabel='Supprimer',callback=null,form=null}=o;
    USSS._confirmCb=callback; USSS._confirmForm=form;
    const ic=document.getElementById('usss-confirm-ico'); if(ic)ic.innerHTML=`<i class="${icon}"></i>`;
    const tt=document.getElementById('usss-confirm-title'); if(tt)tt.textContent=title;
    const me=document.getElementById('usss-confirm-msg'); if(me)me.innerHTML=msg;
    const ol=document.getElementById('usss-confirm-ok-lbl'); if(ol)ol.textContent=okLabel;
    const te=document.getElementById('usss-confirm-target'); if(te){te.textContent=target;te.style.display=target?'block':'none';}
    const ov=document.getElementById('usss-confirm-ov'); if(ov){ov.style.display='flex';document.body.style.overflow='hidden';}
  },
  confirmOk() {
    const ov=document.getElementById('usss-confirm-ov'); if(ov){ov.style.display='none';document.body.style.overflow='';}
    if(USSS._confirmCb){USSS._confirmCb();USSS._confirmCb=null;}
    else if(USSS._confirmForm){USSS._confirmForm.submit();USSS._confirmForm=null;}
  },
  confirmCancel() {
    const ov=document.getElementById('usss-confirm-ov'); if(ov){ov.style.display='none';document.body.style.overflow='';}
    USSS._confirmCb=null; USSS._confirmForm=null;
  },

  /* ── Toast ── */
  toast(msg,type='success',dur=3000) {
    const c=document.getElementById('usss-toasts'); if(!c)return;
    const ic={success:'check-circle',error:'circle-xmark',info:'circle-info',amber:'triangle-exclamation'};
    const co={success:'rgba(21,128,61,.1);border-color:rgba(21,128,61,.3);color:#4ade80',error:'rgba(191,28,46,.1);border-color:rgba(191,28,46,.3);color:#f56270',info:'rgba(26,86,219,.1);border-color:rgba(26,86,219,.3);color:#93b4f8',amber:'rgba(180,83,9,.1);border-color:rgba(180,83,9,.3);color:#fbbf24'};
    const t=document.createElement('div');
    t.style.cssText=`display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;background:${co[type]||co.info};border:1px solid;border-radius:8px;font-size:.8rem;font-weight:500;min-width:230px;max-width:340px;pointer-events:all;box-shadow:0 6px 24px rgba(0,0,0,.4);animation:usssToastIn .22s ease-out`;
    t.innerHTML=`<i class="fas fa-${ic[type]||'info'}"></i><span style="flex:1">${msg}</span><button onclick="this.parentNode.remove()" style="background:none;border:none;cursor:pointer;color:inherit;opacity:.5;font-size:.85rem;padding:0">×</button>`;
    c.appendChild(t);
    setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(100%)';t.style.transition='all .2s';setTimeout(()=>t.remove(),200);},dur);
  },

  /* ── Money format ── */
  fmt$(n){return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',minimumFractionDigits:0}).format(n);}
};
window.USSS=USSS;

/* CSS animation */
(function(){const s=document.createElement('style');s.textContent='@keyframes usssToastIn{from{transform:translateX(100%);opacity:0}}';document.head.appendChild(s);})();

/* Auto data-confirm forms */
document.addEventListener('submit',function(e){
  const f=e.target;
  if(!f.hasAttribute('data-confirm'))return;
  e.preventDefault();
  USSS.confirm({title:f.dataset.confirmTitle||'Confirmer la suppression',msg:f.dataset.confirmMsg||'Cette action est <strong>irréversible</strong>.',target:f.dataset.confirmTarget||'',okLabel:f.dataset.confirmOk||'Supprimer',form:f});
});

/* ESC + overlay close */
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m=>{m.classList.add('hidden');});
  USSS.confirmCancel();
  document.body.style.overflow='';
});
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.modal-ov').forEach(ov=>ov.addEventListener('click',e=>{if(e.target===ov)USSS.closeModal(ov.id);}));
  window.addEventListener('pageshow',ev=>{
    if(!ev.persisted)return;
    document.querySelectorAll('.modal-ov').forEach(m=>m.classList.add('hidden'));
    const co=document.getElementById('usss-confirm-ov');if(co)co.style.display='none';
    document.body.style.overflow='';
  });
});
</script>
</body>
</html>