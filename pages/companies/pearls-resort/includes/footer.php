</div><!-- /.page-content -->
</div><!-- /.main-content -->

<script>
const Pearl = {
    openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
    },
    closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); document.body.style.overflow = ''; }
    },
    formatMoney(n) {
        return new Intl.NumberFormat('fr-FR', { style:'currency', currency:'USD', minimumFractionDigits:0 }).format(n);
    }
};

window.Pearl = Pearl;

document.addEventListener('DOMContentLoaded', () => {
    // ESC to close modal
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                m.classList.add('hidden');
                document.body.style.overflow = '';
            });
        }
    });
    // Click outside to close
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) {
                overlay.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
    });
    // Counter animation
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10);
        let cur = 0, step = Math.ceil(target / 40);
        const t = setInterval(() => {
            cur = Math.min(cur + step, target);
            el.textContent = cur.toLocaleString('fr-FR');
            if (cur >= target) clearInterval(t);
        }, 25);
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
<script src="<?php echo ASSETS_PATH ?? ''; ?>/js/page-tracker.js"></script>
</body>
</html>