    <!-- Footer -->
    <footer style="margin-top: 6rem; padding: 3rem 0; border-top: 1px solid rgba(255, 255, 255, 0.1); background: rgba(20, 24, 41, 0.4);">
        <div class="container">
            <div style="text-align: center; color: var(--text-secondary);">
                <p style="margin-bottom: 0.5rem;">© 2026 <?php echo SITE_NAME; ?> - Tous droits réservés</p>
                <p style="font-size: 0.85rem; margin: 0;">Plateforme de gestion des services publics</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.6.0/axios.min.js"></script>
    <script>
        // Modal management
        const MDT = {
            openModal: function(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.remove('hidden');
                }
            },
            closeModal: function(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.add('hidden');
                }
            }
        };

        // Close modals on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(modal => {
                    modal.classList.add('hidden');
                });
            }
        });

        // Close modal when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(event) {
                if (event.target === this) {
                    this.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
