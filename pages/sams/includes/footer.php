</div><!-- /s-content -->
</main><!-- /s-main -->

<script>
// ══ SAMS Core ════════════════════════════════════════════════════════════════
const Sams = {
    openModal(id)  { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
    closeModal(id) { const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} },
    fmtMoney(n)    { return '$'+new Intl.NumberFormat('fr-FR',{minimumFractionDigits:0}).format(n); }
};
window.Sams=Sams;
window.openModal  = id => Sams.openModal(id);
window.closeModal = id => Sams.closeModal(id);

// ── Horloge ───────────────────────────────────────────────────────────────────
(function liveClock(){
    const pad=n=>String(n).padStart(2,'0');
    function tick(){
        const now=new Date();
        const h=document.getElementById('clock-h'), m=document.getElementById('clock-m'), s=document.getElementById('clock-s');
        if(h)h.textContent=pad(now.getHours());
        if(m)m.textContent=pad(now.getMinutes());
        if(s)s.textContent=pad(now.getSeconds());
    }
    tick(); setInterval(tick,1000);
})();

// ── Toast ─────────────────────────────────────────────────────────────────────
window.showToast=function(msg,type,dur){
    type=type||'info'; dur=dur||3500;
    const c=document.getElementById('toast-container'); if(!c)return;
    const icons={success:'check-circle',error:'exclamation-circle',info:'info-circle',warning:'triangle-exclamation'};
    const cls={success:'toast-success',error:'toast-error',info:'toast-info',warning:'toast-warning'};
    const t=document.createElement('div');
    t.className='toast '+(cls[type]||'toast-info');
    t.innerHTML='<i class="fas fa-'+(icons[type]||'info-circle')+' toast-icon"></i><span style="flex:1">'+msg+'</span><button class="toast-close" onclick="this.parentNode.remove()">×</button>';
    c.appendChild(t);
    setTimeout(()=>{t.classList.add('out');setTimeout(()=>t.remove(),200);},dur);
};

// ── Fermeture modals ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',function(){
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape')
            document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m=>{m.classList.add('hidden');document.body.style.overflow='';});
    });
    document.querySelectorAll('.modal-ov').forEach(ov=>
        ov.addEventListener('click',function(e){if(e.target===ov){ov.classList.add('hidden');document.body.style.overflow='';};})
    );

    // ── Filtre de recherche dynamique ─────────────────────────────────────────
    const si=document.getElementById('docSearch');
    if(si){
        si.addEventListener('input',function(){
            const q=this.value.toLowerCase().trim();
            document.querySelectorAll('.doc-card').forEach(card=>{
                const txt=card.textContent.toLowerCase();
                card.style.display=(!q||txt.includes(q))?'':'none';
            });
        });
    }

    // ── Intercepteur data-confirm ─────────────────────────────────────────────
    document.querySelectorAll('form[data-confirm]').forEach(function(form){
        form.addEventListener('submit',function(e){
            e.preventDefault();
            const title  = form.dataset.confirmTitle  || "Confirmer l'action";
            const target = form.dataset.confirmTarget || '';
            const okTxt  = form.dataset.confirmOk     || 'Confirmer';
            const msgHtml= form.dataset.confirmMsg    || '';

            let modal=document.getElementById('__sams-confirm-modal');
            if(!modal){
                modal=document.createElement('div');
                modal.id='__sams-confirm-modal';
                modal.className='modal-ov hidden';
                modal.innerHTML=
                    '<div class="modal modal-sm">'
                  +   '<div class="modal-hd" style="background:rgba(225,29,72,.07);border-color:rgba(225,29,72,.25)">'
                  +     '<h2><i class="fas fa-triangle-exclamation"></i><span id="__scm-title"></span></h2>'
                  +     '<button class="modal-close" onclick="closeModal(\'__sams-confirm-modal\')"><i class="fas fa-times"></i></button>'
                  +   '</div>'
                  +   '<div class="modal-bd" style="text-align:center;padding:1.75rem 1.4rem">'
                  +     '<div style="width:54px;height:54px;border-radius:50%;background:rgba(225,29,72,.1);border:2px solid rgba(225,29,72,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:var(--red-l);animation:danger-pulse 1.5s ease-in-out infinite"><i class="fas fa-trash"></i></div>'
                  +     '<div id="__scm-target" style="font-family:\'JetBrains Mono\',monospace;font-size:.78rem;color:var(--red-l);background:rgba(225,29,72,.07);border:1px solid rgba(225,29,72,.2);border-radius:6px;padding:.4rem .8rem;margin-bottom:.75rem"></div>'
                  +     '<div id="__scm-msg" style="font-size:.82rem;color:var(--txt-s);line-height:1.55"></div>'
                  +   '</div>'
                  +   '<div class="modal-ft">'
                  +     '<button class="btn btn-ghost btn-sm" onclick="closeModal(\'__sams-confirm-modal\')">Annuler</button>'
                  +     '<button id="__scm-ok" class="btn btn-danger btn-sm"></button>'
                  +   '</div>'
                  + '</div>';
                document.body.appendChild(modal);
            }

            document.getElementById('__scm-title').textContent  = title;
            document.getElementById('__scm-target').textContent = target;
            document.getElementById('__scm-msg').innerHTML      = msgHtml;

            const btn=document.getElementById('__scm-ok');
            btn.textContent=okTxt;
            const nb=btn.cloneNode(true);
            btn.parentNode.replaceChild(nb,btn);
            nb.addEventListener('click',function(){closeModal('__sams-confirm-modal');form.submit();});
            openModal('__sams-confirm-modal');
        });
    });
});

// CSS pour danger-pulse si pas déjà présent
if(!document.getElementById('__sams-keyframes')){
    const s=document.createElement('style');
    s.id='__sams-keyframes';
    s.textContent='@keyframes danger-pulse{0%,100%{box-shadow:0 0 0 0 rgba(225,29,72,.4)}50%{box-shadow:0 0 0 10px rgba(225,29,72,0)}}';
    document.head.appendChild(s);
}
</script>
</body>
</html>