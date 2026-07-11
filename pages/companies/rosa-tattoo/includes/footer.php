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