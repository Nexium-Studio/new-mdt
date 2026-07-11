<?php // pages/staff/includes/footer.php ?>
    </main>
</div>
</div>

<div id="toastContainer" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;"></div>

<script>
/* ── SIDEBAR ── */
const sidebar = document.getElementById('sidebar');
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('staffSidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (localStorage.getItem('staffSidebarCollapsed') === 'true') sidebar.classList.add('collapsed');

/* ── THEME (dark / light) ── */
(function(){
    const root = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('staffTheme', next); } catch(e) {}
        btn.title = next === 'light' ? 'Passer en mode sombre' : 'Passer en mode clair';
    });
    btn.title = root.getAttribute('data-theme') === 'light' ? 'Passer en mode sombre' : 'Passer en mode clair';
})();

/* ── SUBMENU ── */
function toggleSubmenu(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open');
}
document.querySelectorAll('.nav-submenu').forEach(sub => {
    if (sub.querySelector('.nav-sub-item.active')) sub.classList.add('open');
});

/* ── TABS ── */
function switchTab(groupId, tabId) {
    const group = groupId ? document.querySelector('[data-tab-group="'+groupId+'"]') : document;
    (group || document).querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    const btn  = document.querySelector('[data-tab="'+tabId+'"]');
    const pane = document.getElementById('tab-'+tabId);
    if (btn)  btn.classList.add('active');
    if (pane) pane.classList.add('active');
    history.replaceState(null, null, '#'+tabId);
}

document.addEventListener('DOMContentLoaded', () => {
    // Init tab from URL hash or GET param
    const hash = location.hash.replace('#','') || new URLSearchParams(location.search).get('tab');
    if (hash) {
        const btn = document.querySelector('[data-tab="'+hash+'"]');
        if (btn) { const g = btn.closest('[data-tab-group]')?.dataset.tabGroup; switchTab(g, hash); }
    }
    document.querySelectorAll('[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const g = btn.closest('[data-tab-group]')?.dataset.tabGroup;
            switchTab(g, btn.dataset.tab);
        });
    });
    // Animate counters
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10);
        let cur = 0, step = Math.ceil(target / 30);
        const t = setInterval(() => {
            cur = Math.min(cur + step, target);
            el.textContent = cur.toLocaleString('fr-FR');
            if (cur >= target) clearInterval(t);
        }, 30);
    });
    // Animate progress bars
    document.querySelectorAll('.progress-fill[data-width]').forEach(el => {
        setTimeout(() => el.style.width = el.dataset.width + '%', 100);
    });
});

/* ── TOAST ── */
function toast(msg, type = 'success') {
    const col = {success:'var(--green)',error:'var(--red)',info:'var(--blue)',warning:'var(--yellow)'};
    const ico = {success:'circle-check',error:'circle-xmark',info:'circle-info',warning:'triangle-exclamation'};
    const el = document.createElement('div');
    el.style.cssText = `background:var(--bg-elevated);border:1px solid var(--border);border-left:3px solid ${col[type]};border-radius:10px;padding:12px 16px;font-size:.83rem;display:flex;align-items:center;gap:10px;min-width:240px;box-shadow:0 4px 20px rgba(0,0,0,.4);animation:toastIn .25s ease;`;
    el.innerHTML = `<i class="fa-solid fa-${ico[type]}" style="color:${col[type]};"></i>${msg}`;
    document.getElementById('toastContainer').appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transform='translateX(20px)'; el.style.transition='.3s'; setTimeout(()=>el.remove(),300); }, 3500);
}

/* ── MODALS ── */
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.addEventListener('click', e => { if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open'); });

/* ── CONFIRM ── */
function confirmAction(msg, url) { if (confirm(msg)) window.location.href = url; }
</script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
