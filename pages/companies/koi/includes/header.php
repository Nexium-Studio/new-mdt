<?php
// ════════════════════════════════════════════════════════════
// pages/companies/koi/includes/header.php
// ════════════════════════════════════════════════════════════
require_once dirname(__DIR__, 4) . '/config/panel_pages.php';
require_once dirname(__DIR__, 4) . '/config/panel_theme_auto.php';
checkPageActive('RESTAURANT_KOI');

$__restaurant_koiAllPages = [
    ['file'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'fas fa-chart-line', 'section'=>''],
    ['file'=>'effectifs', 'label'=>'Effectifs', 'icon'=>'fas fa-users', 'section'=>'Ressources Humaines'],
    ['file'=>'prises-service', 'label'=>'Prises de Service', 'icon'=>'fas fa-clock', 'section'=>''],
    ['file'=>'sanctions', 'label'=>'Sanctions', 'icon'=>'fas fa-gavel', 'section'=>''],
    ['file'=>'stock', 'label'=>'Stock', 'icon'=>'fas fa-boxes', 'section'=>'Gestion Commerciale'],
    ['file'=>'vente', 'label'=>'Vente', 'icon'=>'fas fa-shopping-cart', 'section'=>''],
    ['file'=>'facturation', 'label'=>'Facturation', 'icon'=>'fas fa-file-invoice-dollar', 'section'=>''],
    ['file'=>'depenses', 'label'=>'Dépenses', 'icon'=>'fas fa-money-bill-wave', 'section'=>''],
];
$__restaurant_koiNavPages = getActiveNavItems('RESTAURANT_KOI', $__restaurant_koiAllPages);
$currentPage     = basename($_SERVER['PHP_SELF'], '.php');
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
    <?php echo $__panelThemeCSS; ?>
</head>
<body>
    <aside class="company-sidebar">
        <div class="sidebar-header">
            <div class="company-logo">
                <i class="fas fa-utensils"></i>
            </div>
            <div class="company-name"><?php echo htmlspecialchars($__panelName); ?></div>
            <div class="company-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
        </div>
        
                <nav class="sidebar-nav">
            <?php
            $_restaurant_koiSec = '';
            foreach ($__restaurant_koiNavPages as $__p):
                if (!empty($__p['section']) && $__p['section'] !== $_restaurant_koiSec):
                    $_restaurant_koiSec = $__p['section'];
                    echo '<div class="nav-section-title">' . htmlspecialchars($_restaurant_koiSec) . '</div>';
                endif;
                $__isActive = ($currentPage === $__p['file']) ? ' active' : '';
            ?>
            <a href="<?php echo $__p['file']; ?>.php" class="nav-item<?php echo $__isActive; ?>">
                <i class="<?php echo $__p['icon']; ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div style="height: 1px; background: rgba(59, 130, 246, 0.1); margin: 1rem 1.5rem;"></div>

            
        <?php
        // -- Acces info_vente (independant de faction_helper) ------
        if (!isset($__ivRes)) {
            $__ivRes = false;
            if (hasRole(['*','Administrateur','Developpeur','Staff','Moderateur','Helper'])) {
                $__ivRes = true;
            } elseif (($_SESSION['user_faction'] ?? '') === 'RESTAURANT_KOI') {
                try {
                    $__ivDb2 = Database::getInstance()->getConnection();
                    $__ivGrd = $_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? '';
                    if (!$__ivGrd) {
                        $__ivSt = $__ivDb2->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
                        $__ivSt->execute([$_SESSION['user_id'] ?? 0]);
                        $__ivGrd = (string)($__ivSt->fetchColumn() ?: '');
                    }
                    if ($__ivGrd) {
                        $__ivQ = $__ivDb2->prepare(
                            'SELECT fg.can_recruit FROM faction_grades fg'
                            .' JOIN factions f ON f.id=fg.faction_id'
                            .' WHERE f.faction_key=? AND fg.name=? LIMIT 1'
                        );
                        $__ivQ->execute(['RESTAURANT_KOI', $__ivGrd]);
                        $__ivR = $__ivQ->fetch(PDO::FETCH_ASSOC);
                        if ($__ivR && !empty($__ivR['can_recruit'])) $__ivRes = true;
                    }
                } catch (Exception $__ivEx) {}
            }
        }
        if ($__ivRes):
        ?>
        <a href="info_vente.php" class="nav-item<?php echo ($currentPage??'')==='info_vente'?' active':''; ?>">
            <i class="fas fa-trophy"></i><span>Classement Ventes</span>
        </a>
        <?php endif; ?>
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
                <?php echo htmlspecialchars($_SESSION['user_faction_grade'] ?? $_SESSION['user_faction_rank'] ?? 'Employé'); ?>
            </span>
        </div>
        <div class="page-content">