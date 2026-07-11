</div><!-- /.page-content -->
</main>

<script>
/* ════════════════════════════════════════════════════════════
   LIFE INVADER · GLOBAL HELPERS (Company / CO)
═════════════════════════════════════════════════════════════ */
const Company = {
    openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
    },
    closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); document.body.style.overflow = ''; }
    },
    formatMoney(a, d = 2) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD', minimumFractionDigits: d }).format(a);
    },
    toast(msg, type = 'success') {
        const t = document.createElement('div');
        const ic = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle', info: 'info-circle' };
        const cl = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#4f7cff' };
        Object.assign(t.style, {
            position: 'fixed', bottom: '1.4rem', right: '1.4rem', zIndex: '99999',
            background: 'var(--surface)', border: `1px solid ${cl[type]}55`,
            borderLeft: `3.5px solid ${cl[type]}`, borderRadius: '10px',
            padding: '.78rem 1.1rem', color: cl[type], fontFamily: 'Inter,sans-serif',
            fontSize: '.85rem', fontWeight: '600',
            display: 'flex', alignItems: 'center', gap: '.55rem',
            boxShadow: '0 8px 32px rgba(0,0,0,.5)',
            opacity: '0', transform: 'translateY(12px)', transition: 'all .25s'
        });
        t.innerHTML = `<i class="fas fa-${ic[type]}"></i> ${msg}`;
        document.body.appendChild(t);
        requestAnimationFrame(() => { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; });
        setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(12px)'; setTimeout(() => t.remove(), 280); }, 3800);
    },
};
window.Company = Company;
window.CO = Company;

/* ════════════════════════════════════════════════════════════
   THEME (clair / sombre)
═════════════════════════════════════════════════════════════ */
(function initTheme() {
    const saved = localStorage.getItem('li_theme');
    if (saved === 'light' || saved === 'dark') {
        document.documentElement.setAttribute('data-theme', saved);
    } else {
        // Préférence système
        const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        document.documentElement.setAttribute('data-theme', prefersLight ? 'light' : 'dark');
    }
})();
function toggleTheme() {
    const cur = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('li_theme', next);
}
window.toggleTheme = toggleTheme;

/* ════════════════════════════════════════════════════════════
   SIDEBAR MOBILE
═════════════════════════════════════════════════════════════ */
function toggleSidebar() {
    const sb = document.getElementById('companySidebar');
    const bd = document.getElementById('sidebarBackdrop');
    const ic = document.getElementById('menuIcon');
    const op = sb.classList.toggle('open');
    bd.classList.toggle('open', op);
    if (ic) ic.className = op ? 'fas fa-times' : 'fas fa-bars';
    document.body.style.overflow = op ? 'hidden' : '';
}
function closeSidebar() {
    document.getElementById('companySidebar')?.classList.remove('open');
    document.getElementById('sidebarBackdrop')?.classList.remove('open');
    const ic = document.getElementById('menuIcon');
    if (ic) ic.className = 'fas fa-bars';
    document.body.style.overflow = '';
}
window.toggleSidebar = toggleSidebar;
window.closeSidebar = closeSidebar;

/* ════════════════════════════════════════════════════════════
   RICH TEXT EDITOR (WYSIWYG)
   Usage : <textarea name="contenu" data-rt-editor></textarea>
   Le textarea sera caché et remplacé par un éditeur riche ;
   la valeur HTML est resynchronisée avant submit.
═════════════════════════════════════════════════════════════ */
const RTEditor = (function () {
    function exec(cmd, val = null) {
        try { document.execCommand(cmd, false, val); } catch (e) {}
    }

    function buildToolbar(editor, content) {
        const tb = document.createElement('div');
        tb.className = 'rt-toolbar';
        tb.setAttribute('contenteditable', 'false');

        const make = (icon, cmd, val, title) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'rt-btn';
            b.title = title;
            b.innerHTML = `<i class="fas ${icon}"></i>`;
            b.addEventListener('mousedown', e => { e.preventDefault(); content.focus(); exec(cmd, val); updateActive(tb, content); });
            return b;
        };
        const sep = () => { const s = document.createElement('div'); s.className = 'rt-sep'; return s; };

        // Block format (paragraph / headings)
        const blockSel = document.createElement('select');
        blockSel.className = 'rt-select';
        blockSel.innerHTML = `
            <option value="p">Paragraphe</option>
            <option value="h1">Titre 1</option>
            <option value="h2">Titre 2</option>
            <option value="h3">Titre 3</option>
            <option value="blockquote">Citation</option>
            <option value="pre">Code</option>
        `;
        blockSel.addEventListener('change', e => {
            content.focus();
            exec('formatBlock', e.target.value);
        });
        tb.appendChild(blockSel);

        tb.appendChild(sep());
        tb.appendChild(make('fa-bold', 'bold', null, 'Gras (Ctrl+B)'));
        tb.appendChild(make('fa-italic', 'italic', null, 'Italique (Ctrl+I)'));
        tb.appendChild(make('fa-underline', 'underline', null, 'Souligné (Ctrl+U)'));
        tb.appendChild(make('fa-strikethrough', 'strikeThrough', null, 'Barré'));

        tb.appendChild(sep());
        // Couleur texte
        const colorWrap = document.createElement('label');
        colorWrap.className = 'rt-btn rt-color-wrap';
        colorWrap.title = 'Couleur du texte';
        colorWrap.innerHTML = '<i class="fas fa-palette"></i>';
        const colorInp = document.createElement('input');
        colorInp.type = 'color';
        colorInp.className = 'rt-color-input';
        colorInp.value = '#4f7cff';
        colorInp.addEventListener('input', e => { content.focus(); exec('foreColor', e.target.value); });
        colorWrap.appendChild(colorInp);
        tb.appendChild(colorWrap);
        // Surlignage
        const hlWrap = document.createElement('label');
        hlWrap.className = 'rt-btn rt-color-wrap';
        hlWrap.title = 'Surlignage';
        hlWrap.innerHTML = '<i class="fas fa-highlighter"></i>';
        const hlInp = document.createElement('input');
        hlInp.type = 'color';
        hlInp.className = 'rt-color-input';
        hlInp.value = '#fef08a';
        hlInp.addEventListener('input', e => { content.focus(); exec('hiliteColor', e.target.value); });
        hlWrap.appendChild(hlInp);
        tb.appendChild(hlWrap);

        tb.appendChild(sep());
        tb.appendChild(make('fa-list-ul', 'insertUnorderedList', null, 'Liste à puces'));
        tb.appendChild(make('fa-list-ol', 'insertOrderedList', null, 'Liste numérotée'));
        tb.appendChild(make('fa-quote-right', 'formatBlock', 'blockquote', 'Citation'));

        tb.appendChild(sep());
        tb.appendChild(make('fa-align-left', 'justifyLeft', null, 'Aligner à gauche'));
        tb.appendChild(make('fa-align-center', 'justifyCenter', null, 'Centrer'));
        tb.appendChild(make('fa-align-right', 'justifyRight', null, 'Aligner à droite'));
        tb.appendChild(make('fa-align-justify', 'justifyFull', null, 'Justifier'));

        tb.appendChild(sep());
        // Lien
        const linkBtn = document.createElement('button');
        linkBtn.type = 'button';
        linkBtn.className = 'rt-btn';
        linkBtn.title = 'Insérer un lien';
        linkBtn.innerHTML = '<i class="fas fa-link"></i>';
        linkBtn.addEventListener('mousedown', e => {
            e.preventDefault();
            const url = prompt('URL du lien :', 'https://');
            if (url) { content.focus(); exec('createLink', url); }
        });
        tb.appendChild(linkBtn);
        // Image
        const imgBtn = document.createElement('button');
        imgBtn.type = 'button';
        imgBtn.className = 'rt-btn';
        imgBtn.title = 'Insérer une image (URL)';
        imgBtn.innerHTML = '<i class="fas fa-image"></i>';
        imgBtn.addEventListener('mousedown', e => {
            e.preventDefault();
            const url = prompt('URL de l\'image :', 'https://');
            if (url) { content.focus(); exec('insertImage', url); }
        });
        tb.appendChild(imgBtn);
        // HR
        tb.appendChild(make('fa-minus', 'insertHorizontalRule', null, 'Séparateur'));

        tb.appendChild(sep());
        tb.appendChild(make('fa-undo', 'undo', null, 'Annuler'));
        tb.appendChild(make('fa-redo', 'redo', null, 'Rétablir'));

        const updateActive = (tb, content) => {
            tb.querySelectorAll('.rt-btn').forEach(b => b.classList.remove('active'));
            ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList',
             'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'].forEach(cmd => {
                try {
                    if (document.queryCommandState(cmd)) {
                        const map = {
                            bold: 'fa-bold', italic: 'fa-italic', underline: 'fa-underline',
                            strikeThrough: 'fa-strikethrough',
                            insertUnorderedList: 'fa-list-ul', insertOrderedList: 'fa-list-ol',
                            justifyLeft: 'fa-align-left', justifyCenter: 'fa-align-center',
                            justifyRight: 'fa-align-right', justifyFull: 'fa-align-justify'
                        };
                        const ic = map[cmd];
                        tb.querySelector(`.fas.${ic}`)?.closest('.rt-btn')?.classList.add('active');
                    }
                } catch (e) {}
            });
        };
        content.addEventListener('keyup', () => updateActive(tb, content));
        content.addEventListener('mouseup', () => updateActive(tb, content));

        return tb;
    }

    function init(textarea) {
        if (textarea._rtInit) return;
        textarea._rtInit = true;

        const wrap = document.createElement('div');
        wrap.className = 'rt-editor';
        const content = document.createElement('div');
        content.className = 'rt-content';
        content.contentEditable = 'true';
        content.setAttribute('data-placeholder', textarea.getAttribute('placeholder') || 'Rédigez votre contenu…');
        content.innerHTML = textarea.value || '';

        // Restituer min-height si demandé
        const minH = textarea.getAttribute('data-min-height');
        if (minH) content.style.minHeight = minH;

        const tb = buildToolbar(wrap, content);
        wrap.appendChild(tb);
        wrap.appendChild(content);

        textarea.style.display = 'none';
        textarea.parentNode.insertBefore(wrap, textarea.nextSibling);

        const sync = () => { textarea.value = content.innerHTML; };
        content.addEventListener('input', sync);
        content.addEventListener('blur', sync);
        content.addEventListener('focus', () => wrap.classList.add('focused'));
        content.addEventListener('blur', () => wrap.classList.remove('focused'));

        // Sync avant submit
        const form = textarea.closest('form');
        if (form) form.addEventListener('submit', sync);

        // Coller sans formatage exotique
        content.addEventListener('paste', e => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            exec('insertText', text);
        });

        textarea._rtSync = sync;
        textarea._rtContent = content;
    }

    function initAll(root = document) {
        root.querySelectorAll('textarea[data-rt-editor]').forEach(init);
    }

    return { init, initAll };
})();
window.RTEditor = RTEditor;

/* ════════════════════════════════════════════════════════════
   PICKERS (sélecteurs dynamiques de citoyens, journalistes…)
═════════════════════════════════════════════════════════════ */
function initPickers(root = document) {
    root.querySelectorAll('[data-picker]').forEach(el => {
        if (el._pickerInit) return;
        el._pickerInit = true;

        const placeholder = el.dataset.placeholder || 'Rechercher…';
        const name = el.dataset.name || '';
        const required = el.hasAttribute('data-required');
        let data = [];
        let selected = null;

        el.innerHTML = `
            <div class="picker-trigger">
                <i class="fas fa-search" style="color:var(--tx-3);font-size:.78rem"></i>
                <span class="picked"><span class="ph">${placeholder}</span></span>
                <i class="fas fa-chevron-down caret"></i>
            </div>
            <div class="picker-panel">
                <div class="picker-search"><input type="text" placeholder="Filtrer…" autocomplete="off"></div>
                <div class="picker-list"></div>
            </div>
            <input type="hidden" name="${name}" ${required ? 'required' : ''}>
        `;

        const trigger = el.querySelector('.picker-trigger');
        const panel = el.querySelector('.picker-panel');
        const picked = el.querySelector('.picked');
        const list = el.querySelector('.picker-list');
        const search = el.querySelector('.picker-search input');
        const hidden = el.querySelector('input[type="hidden"]');

        function render(items) {
            if (!items || !items.length) {
                list.innerHTML = '<div class="picker-empty">Aucun résultat</div>';
                return;
            }
            list.innerHTML = items.map(it => `
                <div class="picker-item" data-value="${it.value}">
                    ${it.icon ? `<i class="fas ${it.icon}" style="color:var(--li);font-size:.78rem"></i>` : ''}
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.84rem;font-weight:600;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${it.label}</div>
                        ${it.sub ? `<div style="font-size:.7rem;color:var(--tx-mute)">${it.sub}</div>` : ''}
                    </div>
                </div>
            `).join('');
            list.querySelectorAll('.picker-item').forEach(item => {
                item.addEventListener('click', () => {
                    const v = item.dataset.value;
                    const it = items.find(x => String(x.value) === String(v));
                    if (it) choose(it);
                });
            });
        }

        function choose(it) {
            selected = it;
            hidden.value = it.value;
            picked.innerHTML = `<span style="font-weight:600">${it.label}</span>${it.sub ? `<span style="color:var(--tx-mute);font-size:.7rem;margin-left:.45rem">${it.sub}</span>` : ''}`;
            close();
            if (typeof el._onChange === 'function') el._onChange(it);
        }

        function open() {
            el.classList.add('open');
            setTimeout(() => search.focus(), 50);
            search.value = '';
            render(data);
        }
        function close() {
            el.classList.remove('open');
        }

        trigger.addEventListener('click', () => {
            if (el.classList.contains('open')) close();
            else open();
        });

        search.addEventListener('input', () => {
            const q = search.value.toLowerCase().trim();
            const f = !q ? data : data.filter(it =>
                (it.label || '').toLowerCase().includes(q) ||
                (it.sub || '').toLowerCase().includes(q)
            );
            render(f);
        });

        document.addEventListener('click', e => {
            if (!el.contains(e.target)) close();
        });

        // API publique
        el._picker = {
            setData(arr) { data = arr || []; render(data); },
            getValue() { return selected; },
            setValue(it) { if (it) choose(it); },
            clear() {
                selected = null;
                hidden.value = '';
                picked.innerHTML = `<span class="ph">${placeholder}</span>`;
            },
            onChange(fn) { el._onChange = fn; }
        };
    });
}
window.initPickers = initPickers;

/* ════════════════════════════════════════════════════════════
   DOMContentLoaded
═════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    // Init pickers + rich editors
    initPickers();
    RTEditor.initAll();

    // Escape closes modals & sidebar (les modals MDT gèrent Esc en interne)
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.querySelector('.mdt-confirm-overlay')) return;
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => m.classList.add('hidden'));
            closeSidebar();
            document.body.style.overflow = '';
        }
    });

    // Click overlay closes modal
    document.querySelectorAll('.modal-overlay').forEach(ov => {
        ov.addEventListener('click', e => {
            if (e.target === ov) { ov.classList.add('hidden'); document.body.style.overflow = ''; }
        });
    });

    // Mobile: close sidebar on nav click
    document.querySelectorAll('.company-sidebar .nav-item').forEach(a => {
        a.addEventListener('click', () => { if (window.innerWidth <= 1024) closeSidebar(); });
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
