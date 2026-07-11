/**
 * Affichage factures — retire __MDT_GARAGE_META__ et formate la description (atelier + stock).
 */
(function () {
    'use strict';

    var META = '\n__MDT_GARAGE_META__\n';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function stripGarageMeta(raw) {
        if (!raw) return '';
        var i = raw.indexOf(META);
        if (i === -1) return raw.trim();
        return raw.slice(0, i).trim();
    }

    function formatWorkshop(text) {
        var lines = text.split(/\r?\n/);
        var html = '<div class="mdt-inv-root">';
        var state = 'idle';
        var i = 0;

        while (i < lines.length) {
            var T = lines[i].trim();

            if (state === 'idle' && T === '=== Configuration atelier ===') {
                html += '<section class="mdt-inv-card"><header class="mdt-inv-card-head"><i class="fas fa-car-side" aria-hidden="true"></i><span>Configuration atelier</span></header><div class="mdt-inv-card-body">';
                state = 'workshop';
                i++;
                continue;
            }

            if (state === 'workshop') {
                if (T.indexOf('===') === 0 && T !== '=== Configuration atelier ===') {
                    html += '</div></section>';
                    state = 'idle';
                    continue;
                }
                if (T === 'Détail modifications :') {
                    html += '<p class="mdt-inv-sub">Modifications</p><ul class="mdt-inv-mods">';
                    state = 'workshop_mods';
                    i++;
                    continue;
                }
                if (/^Montant customisation/i.test(T)) {
                    html += '<div class="mdt-inv-highlight">' + escapeHtml(T) + '</div>';
                    i++;
                    continue;
                }
                if (T.indexOf(':') !== -1 && T.indexOf('·') !== 0 && T.indexOf('•') !== 0) {
                    var c = T.indexOf(':');
                    html += '<div class="mdt-inv-kv"><span class="mdt-inv-k">' + escapeHtml(T.slice(0, c).trim()) + '</span><span class="mdt-inv-v">' + escapeHtml(T.slice(c + 1).trim()) + '</span></div>';
                }
                i++;
                continue;
            }

            if (state === 'workshop_mods') {
                if (!T || (T.indexOf('·') !== 0 && T.indexOf('•') !== 0)) {
                    html += '</ul>';
                    state = 'workshop';
                    continue;
                }
                html += '<li>' + escapeHtml(T.replace(/^[·•]\s*/, '')) + '</li>';
                i++;
                continue;
            }

            if (state === 'idle' && T === '=== Articles (stock) ===') {
                html += '<section class="mdt-inv-card mdt-inv-card--stock"><header class="mdt-inv-card-head"><i class="fas fa-box-open" aria-hidden="true"></i><span>Articles (stock)</span></header><div class="mdt-inv-card-body"><ul class="mdt-inv-stock">';
                state = 'stock_lines';
                i++;
                continue;
            }

            if (state === 'stock_lines') {
                if (!T) {
                    i++;
                    continue;
                }
                if (T.indexOf('·') === 0) {
                    html += '<li>' + escapeHtml(T.replace(/^·\s*/, '')) + '</li>';
                    i++;
                    continue;
                }
                if (/Sous-total stock/i.test(T)) {
                    html += '</ul><div class="mdt-inv-stock-foot">' + escapeHtml(T) + '</div></div></section>';
                    state = 'idle';
                    i++;
                    continue;
                }
                html += '</ul><div class="mdt-inv-card-body"></div></section>';
                state = 'idle';
                continue;
            }

            if (T === '---') {
                html += '<hr class="mdt-inv-sep" />';
                i++;
                continue;
            }

            if (/^Sous-total\s*:/i.test(T) || /^TOTAL\s*:/i.test(T) || /^Statut\s*:/i.test(T)) {
                var isTot = /^TOTAL/i.test(T);
                html += '<div class="mdt-inv-line' + (isTot ? ' mdt-inv-line--total' : '') + '">' + escapeHtml(T) + '</div>';
                i++;
                continue;
            }

            i++;
        }

        if (state === 'workshop_mods') {
            html += '</ul>';
            html += '</div></section>';
        } else if (state === 'workshop') {
            html += '</div></section>';
        } else if (state === 'stock_lines') {
            html += '</ul></div></section>';
        }

        html += '</div>';
        return html;
    }

    function formatPlain(text) {
        return '<div class="mdt-inv-plain">' + escapeHtml(text).replace(/\n/g, '<br />') + '</div>';
    }

    function format(raw) {
        var text = stripGarageMeta(raw);
        if (!text) {
            return '<p class="mdt-inv-empty">Aucune description</p>';
        }
        if (text.indexOf('=== Configuration atelier ===') !== -1) {
            return formatWorkshop(text);
        }
        return formatPlain(text);
    }

    function injectStylesOnce() {
        if (document.getElementById('mdt-inv-detail-styles')) return;
        var s = document.createElement('style');
        s.id = 'mdt-inv-detail-styles';
        s.textContent = [
            '.mdt-inv-root{font-size:0.88rem;line-height:1.45;color:#cbd5e1;max-height:min(55vh,520px);overflow-y:auto;padding-right:4px;}',
            '.mdt-inv-root::-webkit-scrollbar{width:6px;}',
            '.mdt-inv-root::-webkit-scrollbar-thumb{background:rgba(148,163,184,.35);border-radius:6px;}',
            '.mdt-inv-card{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.18);border-radius:12px;margin-bottom:1rem;overflow:hidden;}',
            '.mdt-inv-card--stock{border-color:rgba(52,211,153,.22);}',
            '.mdt-inv-card-head{display:flex;align-items:center;gap:.5rem;padding:.65rem .9rem;font-weight:800;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#e2e8f0;background:linear-gradient(90deg,rgba(99,102,241,.12),transparent);border-bottom:1px solid rgba(148,163,184,.12);}',
            '.mdt-inv-card-head i{color:#a5b4fc;opacity:.95;}',
            '.mdt-inv-card-body{padding:.85rem 1rem 1rem;}',
            '.mdt-inv-kv{display:flex;justify-content:space-between;gap:1rem;padding:.38rem 0;border-bottom:1px solid rgba(148,163,184,.08);font-size:.86rem;}',
            '.mdt-inv-kv:last-of-type{border-bottom:none;}',
            '.mdt-inv-k{color:#94a3b8;flex:0 0 auto;max-width:46%;}',
            '.mdt-inv-v{color:#f1f5f9;font-weight:600;text-align:right;word-break:break-word;}',
            '.mdt-inv-sub{margin:.35rem 0 .5rem;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;}',
            '.mdt-inv-mods,.mdt-inv-stock{list-style:none;margin:0;padding:0;display:grid;gap:.35rem;}',
            '.mdt-inv-mods li,.mdt-inv-stock li{padding:.42rem .55rem;background:rgba(0,0,0,.2);border-radius:8px;border:1px solid rgba(148,163,184,.1);font-size:.82rem;color:#e2e8f0;}',
            '.mdt-inv-mods li::before{content:"◆";color:#818cf8;font-size:.55rem;margin-right:.45rem;vertical-align:middle;}',
            '.mdt-inv-stock li::before{content:"▸";color:#34d399;margin-right:.4rem;}',
            '.mdt-inv-highlight{margin-top:.75rem;padding:.65rem .85rem;border-radius:10px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#86efac;font-weight:700;font-size:.9rem;}',
            '.mdt-inv-stock-foot{margin-top:.6rem;padding-top:.6rem;border-top:1px dashed rgba(148,163,184,.2);font-size:.85rem;color:#94a3b8;}',
            '.mdt-inv-sep{border:none;height:1px;background:linear-gradient(90deg,transparent,rgba(148,163,184,.35),transparent);margin:1rem 0;}',
            '.mdt-inv-line{display:flex;justify-content:space-between;padding:.35rem 0;font-size:.86rem;color:#94a3b8;}',
            '.mdt-inv-line--total{margin-top:.35rem;padding:.65rem .85rem;background:rgba(15,23,42,.8);border-radius:10px;border:1px solid rgba(236,72,153,.25);color:#f8fafc;font-weight:800;font-size:1rem;}',
            '.mdt-inv-plain{font-size:.86rem;line-height:1.55;color:#cbd5e1;}',
            '.mdt-inv-empty{color:#64748b;font-style:italic;margin:0;}'
        ].join('');
        document.head.appendChild(s);
    }

    window.MDTInvoiceDetail = {
        stripMeta: stripGarageMeta,
        format: function (raw) {
            injectStylesOnce();
            return format(raw);
        },
        escapeHtml: escapeHtml
    };
})();
