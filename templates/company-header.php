<?php
// Ce fichier doit être inclus dans chaque page d'entreprise
// Variables requises : $pageTitle, $companyName, $companyColor

if (!isset($companyName)) $companyName = 'Entreprise';
if (!isset($companyColor)) $companyColor = '#3b82f6';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Panel'; ?> - <?php echo $companyName; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --company-color: <?php echo $companyColor; ?>;
            --company-dark: color-mix(in srgb, var(--company-color) 70%, black);
            --company-light: color-mix(in srgb, var(--company-color) 50%, white);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0a0e1a;
            color: #e8edf5;
            overflow-x: hidden;
        }
        
        /* SIDEBAR */
        .company-sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #0f1729 0%, #0a0e1a 100%);
            border-right: 1px solid rgba(59, 130, 246, 0.15);
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
        }
        
        .company-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--company-color), var(--company-dark));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1rem;
            box-shadow: 0 8px 32px rgba(var(--company-color), 0.3);
        }
        
        .company-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #e8edf5;
            margin-bottom: 0.25rem;
        }
        
        .company-subtitle {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .sidebar-nav {
            padding: 1.5rem 0;
        }
        
        .nav-section-title {
            padding: 1rem 1.5rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #475569;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.5rem;
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s;
            position: relative;
        }
        
        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 0;
            background: var(--company-color);
            transition: height 0.3s;
            box-shadow: 0 0 10px var(--company-color);
        }
        
        .nav-item:hover,
        .nav-item.active {
            color: var(--company-light);
            background: rgba(var(--company-color), 0.08);
        }
        
        .nav-item:hover::before,
        .nav-item.active::before {
            height: 60%;
        }
        
        .nav-item i {
            width: 20px;
            text-align: center;
        }
        
        /* MAIN CONTENT */
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
        }
        
        .company-topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(15, 23, 41, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(59, 130, 246, 0.15);
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #e8edf5;
        }
        
        .user-badge {
            padding: 0.6rem 1.2rem;
            background: linear-gradient(135deg, rgba(var(--company-color), 0.15), rgba(var(--company-dark), 0.08));
            border: 1px solid rgba(var(--company-color), 0.3);
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--company-light);
        }
        
        .page-content {
            padding: 2rem;
        }
        
        /* CARDS */
        .card {
            background: rgba(15, 23, 41, 0.6);
            border: 1px solid rgba(59, 130, 246, 0.15);
            border-radius: 16px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }
        
        .card:hover {
            border-color: rgba(var(--company-color), 0.4);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }
        
        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            border: none;
            font-family: inherit;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--company-color), var(--company-dark));
            color: white;
            box-shadow: 0 4px 15px rgba(var(--company-color), 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(var(--company-color), 0.4);
        }
        
        .btn-secondary {
            background: rgba(var(--company-color), 0.1);
            color: var(--company-light);
            border: 1px solid rgba(var(--company-color), 0.3);
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        /* FORMS */
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
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #0a0e1a;
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            color: #e8edf5;
            font-family: inherit;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--company-color);
            box-shadow: 0 0 0 3px rgba(var(--company-color), 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* MODALS */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(5, 5, 15, 0.92);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .modal-overlay:not(.hidden) {
            display: flex;
        }
        
        .modal {
            background: linear-gradient(135deg, #0f1729, #0a0e1a);
            border: 1px solid rgba(var(--company-color), 0.3);
            border-radius: 20px;
            box-shadow: 0 0 60px rgba(0, 0, 0, 0.5);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .modal-header {
            padding: 1.75rem 2rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(var(--company-color), 0.03);
        }
        
        .modal-header h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #e8edf5;
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
            color: #60a5fa;
            font-size: 1.4rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(59, 130, 246, 0.1);
            background: rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        /* ALERTS */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .hidden {
            display: none !important;
        }
        
        @media (max-width: 1024px) {
            .company-sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <aside class="company-sidebar">
        <div class="sidebar-header">
            <div class="company-logo">
                <i class="fas fa-gem"></i>
            </div>
            <div class="company-name"><?php echo $companyName; ?></div>
            <div class="company-subtitle">Gestion d'entreprise</div>
        </div>
        
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="nav-section-title">Ressources Humaines</div>
            
            <a href="effectifs.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span>Effectifs</span>
            </a>
            
            <a href="prises-service.php" class="nav-item">
                <i class="fas fa-clock"></i>
                <span>Prises de Service</span>
            </a>
            
            <a href="sanctions.php" class="nav-item">
                <i class="fas fa-gavel"></i>
                <span>Sanctions</span>
            </a>
            
            <div class="nav-section-title">Gestion Commerciale</div>
            
            <a href="stock.php" class="nav-item">
                <i class="fas fa-boxes"></i>
                <span>Stock</span>
            </a>
            
            <a href="vente.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i>
                <span>Vente</span>
            </a>
            
            <a href="facturation.php" class="nav-item">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Facturation</span>
            </a>
            
            <a href="depenses.php" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Dépenses</span>
            </a>
            
            <div style="height: 1px; background: rgba(59, 130, 246, 0.1); margin: 1rem 1.5rem;"></div>
            
            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-item">
                <i class="fas fa-home"></i>
                <span>Accueil MDT</span>
            </a>
            
            <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </a>
        </nav>
    </aside>
    
    <main class="main-content">
        <div class="company-topbar">
            <h1 class="page-title"><?php echo $pageTitle; ?></h1>
            <span class="user-badge">
                <?php echo htmlspecialchars(mdt_session_display_name()); ?> - 
                <?php echo htmlspecialchars($_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </span>
        </div>
        <div class="page-content">
    <script src="<?php echo ASSETS_PATH; ?>/js/page-tracker.js"></script>
    </body>