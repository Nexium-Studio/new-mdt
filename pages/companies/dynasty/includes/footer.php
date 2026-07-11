</div><!-- /page-content -->
    </main>

    <script>
    /* ================================================================
       Dynasty — Helper global (Company)
       ================================================================ */
    const Company = {
        openModal(id) {
            const m = document.getElementById(id);
            if (m) {
                m.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                m.scrollTop = 0;
            }
        },
        closeModal(id) {
            const m = document.getElementById(id);
            if (m) { m.classList.add('hidden'); document.body.style.overflow = ''; }
        },
        formatMoney(n, decimals = 0) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency', currency: 'USD', minimumFractionDigits: decimals
            }).format(n);
        },
        formatNumber(n, decimals = 0) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: decimals, maximumFractionDigits: decimals
            }).format(n);
        },
        calculatePercentage(amount, pct) {
            return (parseFloat(amount) * parseFloat(pct)) / 100;
        },
        toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `dy-toast dy-toast-${type}`;
            const icon = type === 'success' ? 'check-circle'
                       : type === 'warning' ? 'exclamation-triangle'
                       : type === 'info'    ? 'info-circle'
                       : 'times-circle';
            t.innerHTML = `<i class="fas fa-${icon}"></i> <span>${msg}</span>`;
            document.body.appendChild(t);
            requestAnimationFrame(() => t.classList.add('show'));
            setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3600);
        }
    };
    window.Company = Company;
    window.CO = Company; // alias

    /* ================================================================
       Sidebar mobile
       ================================================================ */
    function toggleSidebar() {
        const sidebar  = document.getElementById('companySidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const icon     = document.getElementById('menuIcon');
        const isOpen   = sidebar.classList.toggle('open');
        backdrop.classList.toggle('open', isOpen);
        icon.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }
    function closeSidebar() {
        const sidebar  = document.getElementById('companySidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const icon     = document.getElementById('menuIcon');
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
        if (icon) icon.className = 'fas fa-bars';
        document.body.style.overflow = '';
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                    m.classList.add('hidden');
                    document.body.style.overflow = '';
                });
                document.querySelectorAll('.picker.open').forEach(p => p.classList.remove('open'));
                closeSidebar();
            }
        });
        document.querySelectorAll('.modal-overlay').forEach(ov => {
            ov.addEventListener('click', e => {
                if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow = ''; }
            });
        });
        document.querySelectorAll('.company-sidebar .nav-item').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 1024) closeSidebar();
            });
        });

        // Init pickers
        initPickers();
    });

    /* ================================================================
       USER PICKER — dropdown dynamique avec recherche temps réel
       Usage : <div class="picker" data-picker
                    data-name="locataire_nom"
                    data-placeholder="Sélectionner un citoyen..."></div>
       Le picker doit aussi recevoir une liste de personnes en JSON via :
         picker._setData([{ id, firstname, lastname, phone, avatar }, ...])
       ================================================================ */
    function initPickers() {
        document.querySelectorAll('.picker[data-picker]').forEach(setupPicker);
        // Fermeture en cliquant en dehors
        document.addEventListener('click', e => {
            document.querySelectorAll('.picker.open').forEach(p => {
                if (!p.contains(e.target)) p.classList.remove('open');
            });
        });
    }
    function setupPicker(root) {
        if (root._picker) return;
        const placeholder = root.dataset.placeholder || 'Sélectionner...';
        const name = root.dataset.name || '';
        const required = root.hasAttribute('data-required');
        const subField = root.dataset.subFieldName || ''; // ex: 'locataire_contact' to auto-fill phone

        // Hidden field for the selected name (locataire_nom contains the display name)
        // Build markup
        root.innerHTML = `
            <button type="button" class="picker-input" tabindex="0">
                <span class="picked"><span class="ph">${placeholder}</span></span>
                <span class="caret"><i class="fas fa-chevron-down"></i></span>
            </button>
            <input type="hidden" name="${name}" value="" ${required ? 'required' : ''}>
            ${subField ? `<input type="hidden" name="${subField}" value="">` : ''}
            <div class="picker-panel">
                <div class="picker-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Rechercher par nom..." autocomplete="off">
                </div>
                <div class="picker-list"><div class="picker-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div></div>
            </div>
        `;

        const inputBtn = root.querySelector('.picker-input');
        const hidden   = root.querySelector(`input[name="${name}"]`);
        const subHidden = subField ? root.querySelector(`input[name="${subField}"]`) : null;
        const search   = root.querySelector('.picker-search input');
        const list     = root.querySelector('.picker-list');
        const picked   = root.querySelector('.picked');

        let data = [];
        let filtered = [];
        let activeIdx = -1;
        let selected = null;

        function render(items) {
            list.innerHTML = '';
            filtered = items;
            if (!items.length) {
                list.innerHTML = '<div class="picker-empty"><i class="fas fa-user-slash"></i>Aucun résultat</div>';
                return;
            }
            items.forEach((item, idx) => {
                const initials = (item.firstname || item.lastname || item.name || '?').charAt(0).toUpperCase();
                const fullName = (item.firstname && item.lastname)
                    ? `${item.firstname} ${item.lastname}`
                    : (item.name || item.firstname || item.lastname || '—');
                const sub = item.phone || item.sub || '';
                const av = item.avatar
                    ? `<div class="picker-item-av"><img src="${item.avatar}" alt="" onerror="this.parentNode.textContent='${initials}'"></div>`
                    : `<div class="picker-item-av">${initials}</div>`;

                const el = document.createElement('div');
                el.className = 'picker-item' + (selected && selected.id == item.id ? ' selected' : '');
                el.dataset.idx = idx;
                el.innerHTML = `
                    ${av}
                    <div class="picker-item-info">
                        <div class="picker-item-name">${escapeHtml(fullName)}</div>
                        ${sub ? `<div class="picker-item-sub">${escapeHtml(sub)}</div>` : ''}
                    </div>
                    <i class="fas fa-check picker-item-check"></i>
                `;
                el.addEventListener('click', () => choose(item));
                list.appendChild(el);
            });
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function choose(item) {
            selected = item;
            const fullName = (item.firstname && item.lastname)
                ? `${item.firstname} ${item.lastname}`
                : (item.name || item.firstname || item.lastname || '—');
            const initials = fullName.charAt(0).toUpperCase();
            hidden.value = fullName;
            if (subHidden) subHidden.value = item.phone || '';
            picked.innerHTML = `
                <span class="picked-av">${initials}</span>
                <span class="picked-txt">${escapeHtml(fullName)}</span>
                ${item.phone ? `<span class="picked-sub">· ${escapeHtml(item.phone)}</span>` : ''}
            `;
            root.classList.remove('open');
            root.dispatchEvent(new CustomEvent('picker:change', { detail: item }));
        }

        function filter(q) {
            q = (q || '').toLowerCase().trim();
            if (!q) return render(data);
            const r = data.filter(it => {
                const hay = `${it.firstname||''} ${it.lastname||''} ${it.name||''} ${it.phone||''}`.toLowerCase();
                return hay.includes(q);
            });
            render(r);
        }

        inputBtn.addEventListener('click', e => {
            e.stopPropagation();
            const wasOpen = root.classList.contains('open');
            document.querySelectorAll('.picker.open').forEach(p => p.classList.remove('open'));
            if (!wasOpen) {
                root.classList.add('open');
                setTimeout(() => search.focus(), 50);
            }
        });

        search.addEventListener('input', e => filter(e.target.value));
        search.addEventListener('keydown', e => {
            if (e.key === 'Escape') { root.classList.remove('open'); }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!filtered.length) return;
                activeIdx = e.key === 'ArrowDown'
                    ? Math.min(activeIdx + 1, filtered.length - 1)
                    : Math.max(activeIdx - 1, 0);
                list.querySelectorAll('.picker-item').forEach(el => el.classList.remove('active'));
                const el = list.querySelector(`.picker-item[data-idx="${activeIdx}"]`);
                if (el) { el.classList.add('active'); el.scrollIntoView({ block:'nearest' }); }
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIdx >= 0 && filtered[activeIdx]) choose(filtered[activeIdx]);
                else if (filtered.length === 1) choose(filtered[0]);
            }
        });

        // API publique
        root._picker = {
            setData(arr) { data = arr || []; render(data); },
            getValue() { return selected; },
            setValue(item) { if (item) choose(item); },
            clear() {
                selected = null; hidden.value = '';
                if (subHidden) subHidden.value = '';
                picked.innerHTML = `<span class="ph">${placeholder}</span>`;
            }
        };
    }
    window.setupPicker = setupPicker;
    </script>

    <!-- Toast / styles globaux -->
    <style>
    .dy-toast {
        position:fixed; bottom:1.5rem; right:1.5rem; z-index:99999;
        padding:.78rem 1.1rem; border-radius:11px;
        font-weight:600; font-size:.84rem;
        display:flex; align-items:center; gap:.55rem;
        transform:translateX(130%); transition:transform .35s cubic-bezier(.32,.72,.4,1);
        box-shadow:0 12px 36px rgba(0,0,0,.42);
        border: 1px solid;
        max-width: 380px;
    }
    .dy-toast.show { transform:none; }
    .dy-toast-success { background:#062018; border-color:rgba(16,185,129,.4); color:#6ee7b7; }
    .dy-toast-error   { background:#1f0606; border-color:rgba(239,68,68,.4);  color:#fca5a5; }
    .dy-toast-warning { background:#1e1304; border-color:rgba(245,158,11,.4); color:#fbbf24; }
    .dy-toast-info    { background:#0a1729; border-color:rgba(59,130,246,.4); color:#93c5fd; }

    @media print {
        .company-sidebar, .company-topbar, .btn, .filters-bar,
        .modal-overlay, .dy-toast, .no-print { display:none !important; }
        .main-content { margin-left:0 !important; }
        body { background:white !important; color:black !important; }
    }
    </style>

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
