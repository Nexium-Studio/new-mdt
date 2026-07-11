</div><!-- /av-content -->
</main>

<script>
const Cabinet = {
    openModal(id) { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
    closeModal(id){ const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} },
    fmtMoney(n){ return '$'+new Intl.NumberFormat('fr-FR',{minimumFractionDigits:2}).format(n); }
};
window.Cabinet = Cabinet;
document.addEventListener('DOMContentLoaded',()=>{
    document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal-ov:not(.hidden)').forEach(m=>{m.classList.add('hidden');document.body.style.overflow=''}); });
    document.querySelectorAll('.modal-ov').forEach(ov=>ov.addEventListener('click',e=>{ if(e.target===ov){ov.classList.add('hidden');document.body.style.overflow='';} }));
});
</script>
<script src="<?php echo ASSETS_PATH; ?>/js/page-tracker.js"></script>
</body>
</html>