</div><!-- /page-content -->
</main>

<script>
const Taxi = {
    openModal:  id => { const m=document.getElementById(id); if(m){m.classList.remove('hidden');document.body.style.overflow='hidden';} },
    closeModal: id => { const m=document.getElementById(id); if(m){m.classList.add('hidden');document.body.style.overflow='';} },
    formatMoney: v  => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'USD',minimumFractionDigits:2}).format(v),
    formatDuration: min => { const h=Math.floor(min/60),m=min%60; return h>0?`${h}h${m.toString().padStart(2,'0')}`:`${m}min`; },
};
window.Taxi = Taxi;
window.Company = Taxi;

document.addEventListener('DOMContentLoaded', () => {
    // ESC ferme les modals
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                m.classList.add('hidden');
            });
            document.body.style.overflow = '';
        }
    });
    // Click extérieur
    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', e => {
            if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow=''; }
        });
    });
    // Auto-dismiss alertes
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(a => {
            a.style.transition = 'opacity .4s'; a.style.opacity = '0';
            setTimeout(() => a.remove(), 400);
        });
    }, 5000);
    // Compteur courses en attente dans nav
    fetch('?_count=1').then(r => r.json()).then(d => {
        const el = document.getElementById('nb-attente');
        if (el && d.attente != null) { el.textContent = d.attente || ''; el.style.display = d.attente ? '' : 'none'; }
    }).catch(()=>{});
});
</script>
<?php
$__mdtRoot = __DIR__;
while ($__mdtRoot !== '' && !is_file($__mdtRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php')) {
    $__mdtParent = dirname($__mdtRoot);
    if ($__mdtParent === $__mdtRoot) { $__mdtRoot = ''; break; }
    $__mdtRoot = $__mdtParent;
}
if ($__mdtRoot !== '') {
    require_once $__mdtRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'mdt_company_confirm_load.php';
}
?>
<script src="<?php echo ASSETS_PATH; ?>/js/page-tracker.js"></script>
</body>
</html>