<?php
// ══════════════════════════════════════════════════════════════
// AJOUT 1 — Inclusion du helper de gestion des pages panels
// ══════════════════════════════════════════════════════════════
require_once dirname(__DIR__, 3) . '/config/panel_pages.php';
require_once dirname(__DIR__, 3) . '/config/panel_theme_auto.php';

// ══════════════════════════════════════════════════════════════
// AJOUT 2 — Bloque l'accès si la page est désactivée
//           Pages "natives DOJ" toujours accessibles sans vérification DB
// ══════════════════════════════════════════════════════════════
$__dojNativePages = ['rapports','rapports_doj','dispatch-view'];
$__currentFile    = basename($_SERVER['PHP_SELF'], '.php');
if (!in_array($__currentFile, $__dojNativePages)) {
    checkPageActive('DOJ');
}

// ══════════════════════════════════════════════════════════════
// AJOUT 3 — Liste complète de toutes les pages du panel DOJ
// Champ 'section' : null = pas de titre, sinon affiche le titre
//                   uniquement avant la première page du groupe
// ══════════════════════════════════════════════════════════════
$__dojAllPages = [
    ['file' => 'dashboard', 'label' => 'Dashboard',        'icon' => 'fas fa-chart-line',  'section' => null],
    ['file' => 'citoyens',  'label' => 'Citoyens',         'icon' => 'fas fa-users',        'section' => null],
    ['file' => 'casier',    'label' => 'Casier Judiciaire','icon' => 'fas fa-file-alt',     'section' => 'Justice'],
    ['file' => 'mandats',   'label' => 'Mandats',          'icon' => 'fas fa-gavel',        'section' => null],
    ['file' => 'proces',    'label' => 'Procès',           'icon' => 'fas fa-landmark',     'section' => null],
    ['file' => 'sentences', 'label' => 'Sentences',        'icon' => 'fas fa-handcuffs',    'section' => null],
    ['file' => 'code-penal',     'label' => 'Code Pénal',         'icon' => 'fas fa-book-open',       'section' => 'Législation'],
    ['file' => 'rapports_doj',   'label' => 'Rapports DOJ',       'icon' => 'fas fa-file-signature',  'section' => 'Rapports'],
    ['file' => 'rapports',       'label' => 'Rapports SASP',      'icon' => 'fas fa-file-alt',        'section' => 'SASP — Accès DOJ'],
    ['file' => 'dispatch-view',  'label' => 'Dispatch Live',      'icon' => 'fas fa-satellite-dish',  'section' => null],
];

// ══════════════════════════════════════════════════════════════
// AJOUT 4 — Filtre selon les pages activées dans le panel staff
//           Les pages natives DOJ sont toujours visibles
// ══════════════════════════════════════════════════════════════
$__dojNavPages = getActiveNavItems('DOJ', $__dojAllPages);
// S'assurer que les pages natives apparaissent toujours dans la nav
$__nativeFiles = array_column($__dojNavPages, 'file');
foreach ($__dojAllPages as $__p) {
    if (in_array($__p['file'], ['rapports','rapports_doj','dispatch-view']) && !in_array($__p['file'], $__nativeFiles)) {
        $__dojNavPages[] = $__p;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'DOJ Panel'; ?> - MDT</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/doj.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Chakra Petch', -apple-system, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            overflow-x: hidden;
        }
        
        /* === SIDEBAR DOJ === */
        .doj-sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            border-right: 1px solid rgba(59, 130, 246, 0.15);
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 0 50px rgba(59, 130, 246, 0.1);
        }
        
        .doj-sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 1px;
            height: 100%;
            background: linear-gradient(180deg, transparent, #3b82f6, transparent);
            opacity: 0.3;
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
            position: relative;
        }
        
        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60%;
            height: 1px;
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
            opacity: 0.5;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .sidebar-logo-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.25), rgba(30, 64, 175, 0.15));
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
            font-size: 1.6rem;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
        }
        
        .sidebar-logo-text {
            font-family: 'Orbitron', monospace;
            font-size: 1.2rem;
            font-weight: 900;
            letter-spacing: 2px;
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .sidebar-logo-subtitle {
            font-size: 0.6rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 0.25rem;
        }
        
        .sidebar-nav {
            padding: 1.5rem 0;
        }
        
        .nav-section-title {
            padding: 1rem 1.5rem 0.5rem;
            font-family: 'Orbitron', monospace;
            font-size: 0.65rem;
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
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            font-family: 'Chakra Petch', sans-serif;
        }
        
        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 0;
            background: linear-gradient(180deg, #3b82f6, #60a5fa);
            transition: height 0.3s;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.6);
        }
        
        .nav-item:hover,
        .nav-item.active {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.08);
        }
        
        .nav-item:hover::before,
        .nav-item.active::before {
            height: 60%;
        }
        
        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
        }
        
        .nav-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.15), transparent);
            margin: 1rem 1.5rem;
        }
        
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            padding: 1.5rem;
            background: #0f172a;
            border-top: 1px solid rgba(59, 130, 246, 0.15);
        }
        
        .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.4);
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.88rem;
            color: #f1f5f9;
        }
        
        .user-role {
            font-size: 0.7rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: 'JetBrains Mono', monospace;
        }
        
        /* === MAIN CONTENT === */
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
        }
        
        .doj-topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(59, 130, 246, 0.15);
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .doj-topbar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 5%, #3b82f6 30%, #1e40af 50%, #3b82f6 70%, transparent 95%);
            opacity: 0.3;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .sidebar-toggle {
            display: none;
            padding: 0.6rem;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            color: #3b82f6;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .sidebar-toggle:hover {
            background: rgba(59, 130, 246, 0.2);
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.3);
        }
        
        .page-title {
            font-family: 'Orbitron', monospace;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            background: linear-gradient(135deg, #3b82f6, #60a5fa, #93c5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 10px rgba(59, 130, 246, 0.3));
        }
        
        .topbar-badge {
            padding: 0.6rem 1.2rem;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(30, 64, 175, 0.08));
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            font-family: 'Chakra Petch', sans-serif;
            font-weight: 600;
            font-size: 0.75rem;
            color: #60a5fa;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .page-content {
            padding: 2rem;
        }
        
        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .doj-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .doj-sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: flex;
            }
        }
    </style>

    <!-- Scrollbar personnalisée DOJ (sidebar + global) — s'adapte au thème actif -->
    <style id="doj-scrollbar">
        /* Sidebar : scrollbar fine, animée, hover bleu DOJ */
        .doj-sidebar { scrollbar-width: thin; scrollbar-color: rgba(59,130,246,0.35) transparent; }
        .doj-sidebar::-webkit-scrollbar { width: 8px; }
        .doj-sidebar::-webkit-scrollbar-track { background: transparent; margin: 6px 0; }
        .doj-sidebar::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(59,130,246,0.5), rgba(30,64,175,0.4));
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
            transition: background 0.3s;
        }
        .doj-sidebar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #3b82f6, #1e40af);
            background-clip: padding-box;
        }
        /* Scrollbar globale du panel (contenu, tables, modales) */
        .page-content ::-webkit-scrollbar,
        .main-content ::-webkit-scrollbar { width: 8px; height: 8px; }
        .page-content ::-webkit-scrollbar-track,
        .main-content ::-webkit-scrollbar-track { background: rgba(0,0,0,0.15); border-radius: 10px; }
        .page-content ::-webkit-scrollbar-thumb,
        .main-content ::-webkit-scrollbar-thumb {
            background: rgba(59,130,246,0.35); border-radius: 10px; transition: background 0.25s;
        }
        .page-content ::-webkit-scrollbar-thumb:hover,
        .main-content ::-webkit-scrollbar-thumb:hover { background: rgba(59,130,246,0.6); }
    </style>

    <?php echo $__panelThemeCSS; ?>
    <?php include __DIR__ . '/theme.php'; ?>
</head>
<body>
    <?php
    // ══════════════════════════════════════════════════════════
    // AJOUT 5 — Variables de rendu
    // ══════════════════════════════════════════════════════════
    $__currentFile     = basename($_SERVER['PHP_SELF'], '.php');
    $__shownSections   = []; // garde trace des titres de section déjà affichés
    ?>

    <aside class="doj-sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <div>
                    <div class="sidebar-logo-text"><?php echo htmlspecialchars($__panelName); ?></div>
                    <div class="sidebar-logo-subtitle"><?php echo htmlspecialchars($__panelSubtitle); ?></div>
                </div>
            </div>
        </div>
        
        <nav class="sidebar-nav">

            <?php
            // ══════════════════════════════════════════════════
            // AJOUT 6 — Rendu dynamique du menu DOJ
            //
            // Le champ 'section' d'un item déclenche l'affichage
            // de son titre de groupe la première fois qu'il
            // apparaît dans les pages filtrées.
            //
            // Exemples :
            //  - Si "casier" est désactivé mais "mandats" actif
            //    → le titre "Justice" s'affiche quand même avant
            //      "mandats" (premier item restant du groupe)
            //  - Si toute la section "Législation" est désactivée
            //    → le titre "Législation" n'apparaît pas du tout
            // ══════════════════════════════════════════════════
            foreach ($__dojNavPages as $__p):
                $__active  = ($__currentFile === $__p['file']) ? 'active' : '';
                $__section = $__p['section'] ?? null;

                // Affiche le titre de section la 1re fois qu'il apparaît
                if ($__section && !in_array($__section, $__shownSections)):
                    $__shownSections[] = $__section;
            ?>
                <div class="nav-section-title"><?php echo htmlspecialchars($__section); ?></div>
            <?php endif; ?>

            <a href="<?php echo SITE_URL; ?>/pages/doj/<?php echo $__p['file']; ?>.php"
               class="nav-item <?php echo $__active; ?>">
                <i class="<?php echo htmlspecialchars($__p['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($__p['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="nav-divider"></div>

            <?php if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])): ?>
            <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="nav-item">
                <i class="fas fa-layer-group"></i>
                <span>Panel Staff</span>
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
        
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar">
                    <?php echo htmlspecialchars(mdt_session_display_initial()); ?>
                </div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars(mdt_session_display_name() ?: 'Utilisateur'); ?></div>
                    <div class="user-role">DOJ</div>
                </div>
            </div>
        </div>
    </aside>
    
    <main class="main-content">
        <div class="doj-topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('show')">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title"><?php echo $pageTitle ?? 'Panel DOJ'; ?></h1>
            </div>
            <div class="topbar-right">
                <span class="topbar-badge">⚖️ <?php echo htmlspecialchars($__panelName); ?></span>
            </div>
        </div>
        <div class="page-content">