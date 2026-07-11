</div>
    </main>
    
    <script>
        // Système modal réutilisable
        const Company = {
            openModal: function(id) {
                const modal = document.getElementById(id);
                if (modal) {
                    modal.classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                }
            },
            
            closeModal: function(id) {
                const modal = document.getElementById(id);
                if (modal) {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            },
            
            // Formater les montants
            formatMoney: function(amount) {
                return new Intl.NumberFormat('fr-FR', {
                    style: 'currency',
                    currency: 'USD',
                    minimumFractionDigits: 2
                }).format(amount);
            },
            
            // Calculer pourcentage
            calculatePercentage: function(amount, percentage) {
                return (parseFloat(amount) * parseFloat(percentage)) / 100;
            },
            
            // Calculer marge
            calculateMarge: function(prixAchat, prixVente) {
                const marge = parseFloat(prixVente) - parseFloat(prixAchat);
                const margePct = (marge / parseFloat(prixAchat)) * 100;
                return {
                    marge: marge,
                    margePct: margePct
                };
            }
        };
        
        // Alias global
        window.Company = Company;
        
        // Event listeners globaux
        document.addEventListener('DOMContentLoaded', function() {
            // Fermer modals avec ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(modal => {
                        modal.classList.add('hidden');
                    });
                    document.body.style.overflow = '';
                }
            });
            
            // Fermer modal en cliquant à l'extérieur
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        overlay.classList.add('hidden');
                        document.body.style.overflow = '';
                    }
                });
            });
            
            // Marquer le lien actif dans la sidebar
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.nav-item').forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });

            // Slider header LS Motors
            (function () {
                const root = document.querySelector('[data-lm-slider]');
                if (!root) return;
                const track = root.querySelector('.lm-header-slider__track');
                const slides = root.querySelectorAll('.lm-header-slider__slide');
                const dotsWrap = root.querySelector('.lm-header-slider__dots');
                const prev = root.querySelector('.lm-header-slider__nav--prev');
                const next = root.querySelector('.lm-header-slider__nav--next');
                if (!track || slides.length === 0) return;

                let idx = 0;
                let timer = null;
                const n = slides.length;

                function go(i) {
                    idx = (i + n * 10) % n;
                    track.style.transform = 'translate3d(-' + idx * 100 + '%,0,0)';
                    dotsWrap.querySelectorAll('.lm-header-slider__dot').forEach((d, j) => {
                        d.classList.toggle('is-active', j === idx);
                        d.setAttribute('aria-selected', j === idx ? 'true' : 'false');
                    });
                }

                function resetAuto() {
                    if (timer) clearInterval(timer);
                    timer = setInterval(function () { go(idx + 1); }, 8000);
                }

                slides.forEach(function (_, j) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lm-header-slider__dot' + (j === 0 ? ' is-active' : '');
                    b.setAttribute('role', 'tab');
                    b.setAttribute('aria-selected', j === 0 ? 'true' : 'false');
                    b.addEventListener('click', function () { go(j); resetAuto(); });
                    dotsWrap.appendChild(b);
                });

                prev.addEventListener('click', function () { go(idx - 1); resetAuto(); });
                next.addEventListener('click', function () { go(idx + 1); resetAuto(); });
                resetAuto();
            })();
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