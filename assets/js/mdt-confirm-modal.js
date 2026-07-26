/**
 * ══════════════════════════════════════════════════════════════════════════
 *  MDT · Modale de confirmation globale
 *  Chargée par includes/mdt_company_confirm_load.php après le <script> du
 *  footer panel (qui définit window.Company / window.Pearl / window.CO).
 *
 *  Deux styles d'appel supportés :
 *   1. Promesse  →  const ok = await Company.confirm({ title, message, tone });
 *   2. Callback  →  Company.confirm({ title, msg, okLabel, okClass, callback, form });
 *
 *  Options : title, message|msg (HTML autorisé), okLabel, cancelLabel,
 *            tone|okClass ('danger'|'warning'|'success'|'info'|'btn-danger'…),
 *            icon (classe FontAwesome), callback(), form (soumis si confirmé).
 *  Retourne toujours une Promise<boolean>.
 * ══════════════════════════════════════════════════════════════════════════
 */
(function (global) {
    'use strict';

    var overlay = null, card = null, elIco = null, elTitle = null, elBody = null,
        elFoot = null, elOk = null, elCancel = null;
    var current = null; // { resolve }

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'mdtc-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="mdtc-card">' +
                '<div class="mdtc-head">' +
                    '<div class="mdtc-ico"><i class="fas fa-triangle-exclamation"></i></div>' +
                    '<div class="mdtc-title"></div>' +
                '</div>' +
                '<div class="mdtc-body"></div>' +
                '<div class="mdtc-foot">' +
                    '<button type="button" class="mdtc-btn mdtc-cancel"></button>' +
                    '<button type="button" class="mdtc-btn mdtc-btn-primary mdtc-ok"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        card     = overlay.querySelector('.mdtc-card');
        elIco    = overlay.querySelector('.mdtc-ico');
        elTitle  = overlay.querySelector('.mdtc-title');
        elBody   = overlay.querySelector('.mdtc-body');
        elFoot   = overlay.querySelector('.mdtc-foot');
        elOk     = overlay.querySelector('.mdtc-ok');
        elCancel = overlay.querySelector('.mdtc-cancel');

        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(false); });
        elCancel.addEventListener('click', function () { close(false); });
        elOk.addEventListener('click', function () { close(true); });
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('open')) return;
            if (e.key === 'Escape') close(false);
            else if (e.key === 'Enter') close(true);
        });
    }

    function toneToClass(tone) {
        if (!tone) return '';
        // Accepte 'danger' ou 'btn-danger'.
        return String(tone).replace(/^btn-/, '');
    }

    function close(result) {
        if (!overlay) return;
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        var ctx = current; current = null;
        if (ctx && ctx.resolve) ctx.resolve(!!result);
        if (result && ctx) {
            if (typeof ctx.callback === 'function') { try { ctx.callback(); } catch (e) {} }
            if (ctx.form && ctx.form.submit) { ctx.form.submit(); }
        }
    }

    /**
     * @param {object} opts
     * @returns {Promise<boolean>}
     */
    function confirm(opts) {
        opts = opts || {};
        build();

        var tone     = toneToClass(opts.tone || opts.okClass || 'danger');
        var icon     = opts.icon ||
            (tone === 'danger'  ? 'fas fa-triangle-exclamation' :
             tone === 'warning' ? 'fas fa-circle-exclamation' :
             tone === 'success' ? 'fas fa-circle-check' :
             tone === 'info'    ? 'fas fa-circle-info' : 'fas fa-triangle-exclamation');

        elIco.className = 'mdtc-ico ' + tone;
        elIco.innerHTML = '<i class="' + icon + '"></i>';
        elTitle.textContent = opts.title || 'Confirmation';
        elBody.innerHTML = opts.message || opts.msg || 'Confirmer cette action ?';
        if (opts.target) {
            elBody.innerHTML += '<div style="margin-top:.5rem;font-weight:700;color:var(--tx-2,#cbd5e1)">' +
                String(opts.target).replace(/[<>]/g, '') + '</div>';
        }
        elCancel.textContent = opts.cancelLabel || 'Annuler';
        elOk.textContent = opts.okLabel || 'Confirmer';
        elOk.className = 'mdtc-btn mdtc-ok ' +
            (tone === 'danger' ? 'mdtc-btn-danger' : tone === 'warning' ? 'mdtc-btn-warn' : 'mdtc-btn-primary');

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { elOk.focus(); }, 60);

        return new Promise(function (resolve) {
            current = { resolve: resolve, callback: opts.callback, form: opts.form || null };
        });
    }

    // Fonction globale + rattachement aux helpers panel existants.
    global.mdtConfirm = confirm;
    ['Company', 'CO', 'Pearl'].forEach(function (ns) {
        if (global[ns] && typeof global[ns] === 'object' && !global[ns].confirm) {
            global[ns].confirm = confirm;
        }
    });

    // Si les helpers sont définis plus tard, on rattache aussi au chargement complet.
    document.addEventListener('DOMContentLoaded', function () {
        ['Company', 'CO', 'Pearl'].forEach(function (ns) {
            if (global[ns] && typeof global[ns] === 'object' && !global[ns].confirm) {
                global[ns].confirm = confirm;
            }
        });
    });
})(window);
