</div>
    </main>

    <?php
    // Composants réutilisables DOJ (DojPicker, DojConfirm, DojSelect, DojDateTime,
    // dojToast, dojPersonField). Inclus une seule fois pour tout le panel : tous
    // les <select> et <input date|time> natifs sont automatiquement remplacés.
    include __DIR__ . '/components.php';
    ?>

    <style>
        /* === CARDS === */
        .card {
            background: #1e293b;
            border: 1px solid rgba(59, 130, 246, 0.15);
            border-radius: 16px;
            margin-bottom: 2rem;
            overflow: hidden;
            position: relative;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
            opacity: 0.5;
        }
        
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .card-header h2 {
            font-family: 'Orbitron', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: 1px;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        /* === FORMS === */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        
        .form-full {
            grid-column: 1 / -1;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .form-label {
            font-family: 'Chakra Petch', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #94a3b8;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #0f172a;
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            color: #f1f5f9;
            font-family: 'Chakra Petch', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-select {
            cursor: pointer;
        }
        
        /* === BUTTONS === */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.75rem 1.5rem;
            font-family: 'Chakra Petch', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            color: #fff;
            border: 1px solid transparent;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .btn-secondary:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .btn-block {
            width: 100%;
        }
        
        /* === RICH EDITOR === */
        .editor-toolbar {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.1);
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            flex-wrap: wrap;
        }
        
        .editor-btn {
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.1);
            border-radius: 6px;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.25s;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .editor-btn:hover {
            background: rgba(59, 130, 246, 0.15);
            border-color: #3b82f6;
            color: #3b82f6;
        }
        
        .rich-editor {
            min-height: 200px;
            padding: 1rem;
            background: #0f172a;
            border: 1px solid rgba(59, 130, 246, 0.1);
            border-radius: 0 0 8px 8px;
            color: #f1f5f9;
            font-family: 'Chakra Petch', sans-serif;
            font-size: 0.9rem;
            line-height: 1.6;
            outline: none;
            overflow-y: auto;
            max-height: 400px;
        }
        
        .rich-editor[contenteditable]:empty::before {
            content: attr(placeholder);
            color: #475569;
        }
        
        .rich-editor:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* === MODALS === */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(5, 5, 15, 0.92);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 0;
            overflow: hidden;
        }
        
        .modal-overlay:not(.hidden) {
            display: flex;
        }
        
        .modal {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            box-shadow: 
                0 0 60px rgba(59, 130, 246, 0.2),
                0 30px 90px rgba(0, 0, 0, 0.6);
            width: 90%;
            max-width: 700px;
            height: 90vh;
            max-height: 800px;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .modal-large {
            max-width: 1000px;
        }
        
        .modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #3b82f6, #60a5fa, #3b82f6, transparent);
            border-radius: 20px 20px 0 0;
            z-index: 1;
        }
        
        .modal-header {
            padding: 1.75rem 2rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(59, 130, 246, 0.03);
            flex-shrink: 0;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }
        
        .modal-header h2 {
            font-family: 'Orbitron', monospace;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .modal-close {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.08);
            color: #3b82f6;
            font-size: 1.4rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .modal-close:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1;
            position: relative;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 10px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0 20px 0 0;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #3b82f6, #1e40af);
            border-radius: 5px;
            border: 2px solid rgba(0, 0, 0, 0.3);
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #60a5fa, #3b82f6);
        }
        
        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(59, 130, 246, 0.1);
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-shrink: 0;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }
        
        /* === TABLES === */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            padding: 1rem;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.05);
            color: #f1f5f9;
            font-size: 0.9rem;
        }
        
        tr:hover {
            background: rgba(59, 130, 246, 0.03);
        }
        
        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .modal {
                width: 100%;
                height: 100vh;
                max-height: 100vh;
                border-radius: 0;
            }
            
            .modal::before {
                border-radius: 0;
            }
            
            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1.25rem;
            }
            
            .modal-header h2 {
                font-size: 0.85rem;
            }
            
            .modal-footer {
                flex-direction: column-reverse;
            }
            
            .modal-footer .btn {
                width: 100%;
            }
            
            .card-header,
            .card-body {
                padding: 1.25rem;
            }
        }
        
        @media (max-width: 480px) {
            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }
            
            .card-header,
            .card-body {
                padding: 1rem;
            }
        }
    </style>
    
    <script>
        // ============================================
        // DOJ MODAL SYSTEM
        // ============================================
        
        const DOJ = {
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
            
            closeAllModals: function() {
                document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(modal => {
                    modal.classList.add('hidden');
                });
                document.body.style.overflow = '';
            }
        };
        
        // ============================================
        // RICH EDITOR FUNCTIONS
        // ============================================
        
        function formatText(cmd) {
            document.execCommand(cmd, false, null);
        }
        
        function insertList() {
            document.execCommand('insertUnorderedList', false, null);
        }
        
        function saveEditorContent(editorId, inputId) {
            const editor = document.getElementById(editorId);
            const input = document.getElementById(inputId);
            if (editor && input) {
                input.value = editor.innerHTML;
            }
            return true;
        }
        
        // ============================================
        // EVENT LISTENERS
        // ============================================
        
        document.addEventListener('DOMContentLoaded', function() {
            // Fermer modals avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    DOJ.closeAllModals();
                }
            });
            
            // Fermer modal en cliquant sur l'overlay
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        overlay.classList.add('hidden');
                        document.body.style.overflow = '';
                    }
                });
            });
            
            // Activer le nav item courant
            const currentPath = window.location.pathname;
            document.querySelectorAll('.nav-item').forEach(item => {
                if (item.getAttribute('href') && currentPath.includes(item.getAttribute('href'))) {
                    item.classList.add('active');
                }
            });
        });
        
        // ============================================
        // UTILITY FUNCTIONS
        // ============================================
        
        function generateCaseNumber() {
            const year = new Date().getFullYear();
            const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
            return `DOJ-${year}-${random}`;
        }
        
        function calculateBail(severity) {
            const bailAmounts = {
                'low': 5000,
                'medium': 15000,
                'high': 50000,
                'critical': 100000
            };
            return bailAmounts[severity] || 10000;
        }
    </script>
    <script src="<?php echo ASSETS_PATH; ?>/js/page-tracker.js"></script>
</body>
</html>