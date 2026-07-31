</div><!-- /page-content -->
</main><!-- /main-content -->

<script>
const Company = {
    openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); document.body.style.overflow='hidden'; }
    },
    closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); document.body.style.overflow=''; }
    },
    formatMoney(n) {
        return new Intl.NumberFormat('fr-FR',{style:'currency',currency:'USD',minimumFractionDigits:2}).format(n);
    },
    toggleSidebar(force) {
        const sb = document.querySelector('.company-sidebar');
        const sc = document.getElementById('cmpScrim');
        if (!sb) return;
        const open = (force === undefined) ? !sb.classList.contains('open') : !!force;
        sb.classList.toggle('open', open);
        if (sc) sc.classList.toggle('show', open);
    },
    toggleCollapse() {
        const collapsed = document.body.classList.toggle('cmp-collapsed');
        try { localStorage.setItem('ammuSidebarCollapsed', collapsed ? '1' : '0'); } catch(e){}
    },
    toggleSection(el) {
        const sec = el.closest('.nav-section');
        if (sec) sec.classList.toggle('collapsed');
    }
};
window.Company = Company;

document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                m.classList.add('hidden');
            });
            document.body.style.overflow = '';
        }
    });
    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', e => {
            if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow=''; }
        });
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