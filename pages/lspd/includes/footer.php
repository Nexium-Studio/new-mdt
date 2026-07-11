</div><!-- /ls-content -->
</main><!-- /ls-main -->

<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/lspd.css">
<script>
const LSPD = {
    openModal(id){const m=document.getElementById(id);if(!m)return;m.classList.remove('hidden');m.style.display='flex';document.body.style.overflow='hidden';},
    closeModal(id){const m=document.getElementById(id);if(!m)return;m.classList.add('hidden');m.style.display='none';document.body.style.overflow='';},
    _confirmCallback:null,_confirmForm:null,
    confirm(o={}){
        const{title='Confirmer',msg='Cette action est <strong>irréversible</strong>.',target='',icon='fas fa-trash',okLabel='Supprimer',okClass='btn-danger',color='rgba(239,68,68,.12)',border='rgba(239,68,68,.3)',textColor='#fca5a5',callback=null,form=null}=o;
        LSPD._confirmCallback=callback;LSPD._confirmForm=form;
        const ic=document.getElementById('lspd-confirm-ico');if(ic){ic.innerHTML=`<i class="${icon}"></i>`;ic.style.background=color;ic.style.borderColor=border;ic.style.color=textColor;}
        const tt=document.getElementById('lspd-confirm-title');if(tt)tt.textContent=title;
        const me=document.getElementById('lspd-confirm-msg');if(me)me.innerHTML=msg;
        const ol=document.getElementById('lspd-confirm-ok-label');if(ol)ol.textContent=okLabel;
        const ob=document.getElementById('lspd-confirm-ok-btn');if(ob)ob.className=`btn ${okClass}`;
        const te=document.getElementById('lspd-confirm-target');if(te){te.textContent=target;te.style.display=target?'block':'none';}
        const ov=document.getElementById('lspd-confirm-ov');if(ov){ov.style.display='flex';document.body.style.overflow='hidden';}
    },
    confirmOk(){
        const ov=document.getElementById('lspd-confirm-ov');if(ov){ov.style.display='none';document.body.style.overflow='';}
        if(LSPD._confirmCallback){LSPD._confirmCallback();LSPD._confirmCallback=null;}
        else if(LSPD._confirmForm){LSPD._confirmForm.submit();LSPD._confirmForm=null;}
    },
    confirmCancel(){
        const ov=document.getElementById('lspd-confirm-ov');if(ov){ov.style.display='none';document.body.style.overflow='';}
        LSPD._confirmCallback=null;LSPD._confirmForm=null;
    },
    toast(msg,type='success',dur=3200){
        const c=document.getElementById('lspd-toasts');if(!c)return;
        const ic={success:'check-circle',error:'circle-xmark',info:'circle-info',warning:'triangle-exclamation'};
        const co={success:'rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#6ee7b7',error:'rgba(239,68,68,.08);border-color:rgba(239,68,68,.25);color:#fca5a5',info:'rgba(37,99,235,.08);border-color:rgba(37,99,235,.25);color:var(--blue-xl)',warning:'rgba(245,158,11,.08);border-color:rgba(245,158,11,.25);color:#fde68a'};
        const t=document.createElement('div');
        t.style.cssText=`display:flex;align-items:center;gap:.7rem;padding:.75rem 1.1rem;background:${co[type]||co.info};border:1px solid;border-radius:10px;font-size:.81rem;font-weight:500;min-width:240px;max-width:360px;box-shadow:0 8px 28px rgba(0,0,0,.4);pointer-events:all;animation:lspdTI .25s cubic-bezier(.34,1.56,.64,1)`;
        t.innerHTML=`<i class="fas fa-${ic[type]||'info'}"></i><span style="flex:1">${msg}</span><button onclick="this.parentNode.remove()" style="background:none;border:none;cursor:pointer;font-size:.9rem;opacity:.5;color:inherit">×</button>`;
        c.appendChild(t);
        setTimeout(()=>{t.style.cssText+=';opacity:0;transform:translateX(100%);transition:all .2s';setTimeout(()=>t.remove(),200);},dur);
    },
    formatText(cmd){document.execCommand(cmd,false,null);},
    insertList(){document.execCommand('insertUnorderedList',false,null);},
    saveEditor(eId,iId){const e=document.getElementById(eId),i=document.getElementById(iId);if(e&&i)i.value=e.innerHTML;return true;},
    fmtMoney(n){return'$'+new Intl.NumberFormat('fr-FR',{minimumFractionDigits:0}).format(n);}
};
window.LSPD=LSPD;window.MDT=LSPD;window.Sams=LSPD;
(function(){const s=document.createElement('style');s.textContent='@keyframes lspdTI{from{transform:translateX(100%);opacity:0}}';document.head.appendChild(s);})();
document.addEventListener('submit',function(e){
    const f=e.target;if(!f.hasAttribute('data-confirm'))return;e.preventDefault();
    LSPD.confirm({title:f.dataset.confirmTitle||'Confirmer la suppression',msg:f.dataset.confirmMsg||'Cette action est <strong>irréversible</strong>.',target:f.dataset.confirmTarget||'',icon:f.dataset.confirmIcon||'fas fa-trash',okLabel:f.dataset.confirmOk||'Supprimer',okClass:f.dataset.confirmClass||'btn-danger',form:f});
});
document.addEventListener('keydown',e=>{if(e.key!=='Escape')return;document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m=>{m.classList.add('hidden');m.style.display='none';});LSPD.confirmCancel();document.body.style.overflow='';});
document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.modal-ov').forEach(ov=>ov.addEventListener('click',e=>{if(e.target===ov)LSPD.closeModal(ov.id);}));
    window.addEventListener('pageshow',ev=>{if(!ev.persisted)return;document.querySelectorAll('.modal-ov').forEach(m=>{m.classList.add('hidden');m.style.display='none';});const co=document.getElementById('lspd-confirm-ov');if(co)co.style.display='none';document.body.style.overflow='';});
});
</script>
<?php $__mdtInputsVer = @filemtime(dirname(__DIR__,3).'/assets/js/mdt-inputs.js') ?: time(); ?>
<script src="<?php echo ASSETS_PATH ?? SITE_URL.'/assets'; ?>/js/mdt-inputs.js?v=<?php echo $__mdtInputsVer; ?>" onerror="void 0"></script>
<?php
// ── Composants partagés (DojSelect / DojDateTime / DojPicker) ───────────────
// Uniformise AUTOMATIQUEMENT tous les <select> et inputs date/heure du panel
// LSPD avec le même rendu MDT que le DOJ (aucun composant HTML natif visible).
if (!defined('MDT_SHARED_COMPONENTS')) {
    define('MDT_SHARED_COMPONENTS', 1);
    $__compPath = __DIR__ . '/../../doj/includes/components.php';
    if (is_file($__compPath)) include $__compPath;
}
?>
<style>
/* Thématisation LSPD des composants partagés (variables --doj-* → couleurs LSPD) */
:root{--doj-blue-bright:#60a5fa;--doj-surface:#0e1830;--doj-navy:#03080f;--doj-border:rgba(37,99,235,.2);--doj-border-active:rgba(37,99,235,.45);--text-bright:#e8edf5;--text-dim:#94a3b8;--text-ghost:#475569}
/* Les dropdowns / pickers passent au-dessus des modales LSPD (z-index 9999/99999) */
.djs-panel,.djt-pop{z-index:100060 !important}
.dp-ov,.dj-confirm-ov{z-index:100050 !important}
/* Police cohérente avec le thème LSPD */
.djs-control,.djs-panel,.djs-opt,.djs-search input,.djs-value,.djt-pop,.dp-modal,.dp-item,.dp-search input,.dp-hd h2{font-family:'IBM Plex Sans',system-ui,sans-serif !important}
.dp-hd h2,.djs-group{font-family:'Syne',sans-serif !important}
</style>
<script>
/* Les <select> sont remplacés par DojSelect, dont l'affichage ne se met à jour
   que sur l'évènement "change". Beaucoup de modales d'édition LSPD règlent la
   valeur des <select> par JS AVANT ouverture (sans déclencher "change").
   → On resynchronise les <select> de la modale à chaque ouverture. */
(function(){
    if(!window.LSPD || typeof LSPD.openModal!=='function' || LSPD.__selSync) return;
    LSPD.__selSync = true;
    var _open = LSPD.openModal.bind(LSPD);
    LSPD.openModal = function(id){
        _open(id);
        try{
            var m=document.getElementById(id);
            if(m) setTimeout(function(){ m.querySelectorAll('select').forEach(function(s){ s.dispatchEvent(new Event('change',{bubbles:true})); }); },0);
        }catch(e){}
    };
})();
</script>
<script src="<?php echo ASSETS_PATH ?? SITE_URL.'/assets'; ?>/js/page-tracker.js" onerror="void 0"></script>
</body>
</html>