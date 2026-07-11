</div><!-- /.page -->
</main><!-- /.main -->

<script>
/* ═══════════════════════════════════════
   Company — utilitaires globaux
═══════════════════════════════════════ */
const Company = {

    openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
    },

    closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); document.body.style.overflow = ''; }
    },

    formatMoney(n, decimals = 0) {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency', currency: 'USD',
            minimumFractionDigits: decimals, maximumFractionDigits: decimals
        }).format(n);
    },

    formatDate(str) {
        if (!str) return '—';
        return new Date(str).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric' });
    },

    pct(amount, pct) { return (parseFloat(amount) * parseFloat(pct)) / 100; },

    toast(msg, type = 'success', duration = 3500) {
        const colors = {
            success: { bg:'rgba(34,197,94,0.14)',  border:'rgba(34,197,94,0.28)',  color:'#22c55e', icon:'check-circle' },
            error:   { bg:'rgba(239,68,68,0.14)',  border:'rgba(239,68,68,0.28)',  color:'#ef4444', icon:'times-circle' },
            info:    { bg:'rgba(96,165,250,0.14)', border:'rgba(96,165,250,0.28)', color:'#60a5fa', icon:'info-circle' },
            warning: { bg:'rgba(245,158,11,0.14)', border:'rgba(245,158,11,0.28)', color:'#f59e0b', icon:'exclamation-circle' },
        };
        const c = colors[type] || colors.info;
        const t = document.createElement('div');
        t.innerHTML = `<i class="fas fa-${c.icon}" style="font-size:.85rem;"></i> ${msg}`;
        Object.assign(t.style, {
            position:'fixed', bottom:'1.5rem', right:'1.5rem', zIndex:'99999',
            padding:'.8rem 1.1rem', borderRadius:'10px',
            fontFamily:"'DM Sans',sans-serif", fontWeight:'600', fontSize:'.82rem',
            display:'flex', alignItems:'center', gap:'.5rem',
            background: c.bg, border:`1px solid ${c.border}`, color: c.color,
            animation:'_tIn .3s ease', boxShadow:'0 8px 24px rgba(0,0,0,.3)',
        });
        document.body.appendChild(t);
        setTimeout(() => { t.style.transition='opacity .35s'; t.style.opacity='0'; setTimeout(()=>t.remove(), 400); }, duration);
    },
};

window.Company = Company;

/* ═══ Keyframe toast ═══ */
const _s = document.createElement('style');
_s.textContent = '@keyframes _tIn{from{transform:translateX(120%);opacity:0}to{transform:none;opacity:1}}';
document.head.appendChild(_s);

/* ═══ Global listeners ═══ */
document.addEventListener('DOMContentLoaded', () => {

    /* ESC ferme les modals */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                m.classList.add('hidden');
            });
            document.body.style.overflow = '';
        }
    });

    /* Clic en dehors du modal */
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) {
                overlay.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
    });

    /* Auto-dismiss alertes PHP après 5s */
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        }, 5000);
    });
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