<?php
// pages/custom/index.php
// ============================================================
//  Moteur de rendu générique pour les panels personnalisés
//  URL : /pages/custom/?panel=MON_PANEL&page=employees
// ============================================================

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
requireLogin();

$db         = Database::getInstance()->getConnection();
$panelKey   = strtoupper(trim($_GET['panel'] ?? ''));
$pageKey    = trim($_GET['page'] ?? 'dashboard');

if (empty($panelKey)) {
    header('Location: ' . SITE_URL . '/pages/dashboard.php');
    exit;
}

// ── Charger la config du panel ────────────────────────────────
$panelCfg = null;
try {
    $stmt = $db->prepare("SELECT * FROM custom_panels WHERE panel_key = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$panelKey]);
    $panelCfg = $stmt->fetch();
} catch (Exception $e) {}

if (!$panelCfg) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;padding:3rem;text-align:center;color:#94a3b8;background:#0a0e1a;min-height:100vh;">
        <h2>Panel introuvable</h2><p>Ce panel n\'existe pas ou est désactivé.</p>
        <a href="' . SITE_URL . '/pages/dashboard.php" style="color:#3b82f6;">← Retour au dashboard</a>
    </div>');
}

// ── Vérification accès ────────────────────────────────────────
$userRole    = $_SESSION['user_role']    ?? '';
$userFaction = $_SESSION['user_faction'] ?? '';

$isStaff     = hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper']);
$hasFaction  = !empty($panelCfg['faction_key']) && ($userFaction === $panelCfg['faction_key']);
$canAccess   = $isStaff || $hasFaction;

if (!$canAccess) {
    header('Location: ' . SITE_URL . '/pages/dashboard.php?error=access_denied');
    exit;
}

// ── Charger les pages actives ─────────────────────────────────
$navPages = [];
try {
    $stmt = $db->prepare("SELECT * FROM custom_panel_pages WHERE panel_key = ? ORDER BY sort_order ASC");
    $stmt->execute([$panelKey]);
    $navPages = $stmt->fetchAll();
} catch (Exception $e) {}

// Vérifie que la page demandée existe dans ce panel
$validPageKeys = array_column($navPages, 'page_key');
if ($pageKey !== 'dashboard' && !in_array($pageKey, $validPageKeys)) {
    $pageKey = 'dashboard';
}

// ── Variables de rendu ────────────────────────────────────────
$panelName     = $panelCfg['name'];
$panelSubtitle = $panelCfg['subtitle'] ?? '';
$panelColor    = $panelCfg['color'];
$panelStyle    = $panelCfg['style'];
$panelIcon     = $panelCfg['icon'];
$baseTemplate  = $panelCfg['base_template'];
$currentPageDef= null;
foreach ($navPages as $np) {
    if ($np['page_key'] === $pageKey) { $currentPageDef = $np; break; }
}
$pageTitle = $currentPageDef ? $currentPageDef['label'] : ucfirst($pageKey);

// ── Couleur RGB pour les styles CSS ──────────────────────────
function hexToRgb(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2));
}
$colorRgb = hexToRgb($panelColor);

// ── Groupes de navigation ─────────────────────────────────────
$navGeneral = array_filter($navPages, fn($p) => !$p['is_admin']);
$navAdmin   = array_filter($navPages, fn($p) => $p['is_admin']);

// ── Grade utilisateur pour accès admin ────────────────────────
$userGrade = '';
try {
    $gs = $db->prepare("SELECT faction_grade FROM users WHERE id = ?");
    $gs->execute([$_SESSION['user_id']]);
    $userGrade = $gs->fetchColumn() ?? '';
} catch (Exception $e) {}

// Pour les panels personnalisés, seul le staff peut accéder à la section admin
$canAccessAdmin = $isStaff;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . $panelName); ?> - MDT</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --pc:      <?php echo $panelColor; ?>;
            --pc-rgb:  <?php echo $colorRgb; ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Chakra Petch', -apple-system, sans-serif; background: #0a0e1a; color: #e8edf5; overflow-x: hidden; }

        /* ── SIDEBAR ── */
        .cp-sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(180deg, #0f1729 0%, #0a0e1a 100%);
            border-right: 1px solid rgba(var(--pc-rgb), 0.15);
            overflow-y: auto; z-index: 1000;
            box-shadow: 0 0 50px rgba(var(--pc-rgb), 0.08);
        }
        .cp-sidebar::before {
            content: ''; position: absolute; top: 0; right: 0; width: 1px; height: 100%;
            background: linear-gradient(180deg, transparent, var(--pc), transparent); opacity: 0.3;
        }
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(var(--pc-rgb), 0.1);
        }
        .sidebar-logo { display: flex; align-items: center; gap: 1rem; }
        .sidebar-logo-icon {
            width: 52px; height: 52px; border-radius: 12px;
            background: linear-gradient(135deg, rgba(var(--pc-rgb), 0.25), rgba(var(--pc-rgb), 0.08));
            border: 2px solid rgba(var(--pc-rgb), 0.3);
            display: flex; align-items: center; justify-content: center;
            color: var(--pc); font-size: 1.5rem;
            box-shadow: 0 0 20px rgba(var(--pc-rgb), 0.25);
        }
        .sidebar-logo-text {
            font-family: 'Orbitron', monospace; font-size: 1.1rem; font-weight: 800; letter-spacing: 2px;
            background: linear-gradient(135deg, var(--pc), #fff); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .sidebar-logo-subtitle { font-size: 0.6rem; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin-top: .2rem; }
        .sidebar-nav { padding: 1.5rem 0; }
        .nav-section-title {
            padding: .75rem 1.5rem .4rem;
            font-family: 'Orbitron', monospace; font-size: .6rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 2px;
            color: rgba(var(--pc-rgb), 0.45);
        }
        .nav-item {
            display: flex; align-items: center; gap: .75rem; padding: .875rem 1.5rem;
            color: #64748b; text-decoration: none; font-weight: 500; font-size: .9rem;
            transition: all .3s cubic-bezier(.22,1,.36,1); position: relative;
        }
        .nav-item::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 3px; height: 0;
            background: linear-gradient(180deg, var(--pc), rgba(var(--pc-rgb),.5));
            transition: height .3s; box-shadow: 0 0 10px rgba(var(--pc-rgb), 0.6);
        }
        .nav-item:hover, .nav-item.active { color: var(--pc); background: rgba(var(--pc-rgb), .08); }
        .nav-item:hover::before, .nav-item.active::before { height: 60%; }
        .nav-item i { width: 20px; text-align: center; font-size: .9rem; }
        .nav-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(var(--pc-rgb), .15), transparent);
            margin: 1rem 1.5rem;
        }
        .sidebar-footer {
            position: sticky; bottom: 0; padding: 1.5rem;
            background: #0a0e1a; border-top: 1px solid rgba(var(--pc-rgb), .12);
        }
        .user-card {
            display: flex; align-items: center; gap: .75rem; padding: .875rem 1rem;
            background: rgba(var(--pc-rgb), .07); border: 1px solid rgba(var(--pc-rgb), .2);
            border-radius: 10px;
        }
        .user-avatar {
            width: 42px; height: 42px; border-radius: 10px;
            background: linear-gradient(135deg, var(--pc), rgba(var(--pc-rgb),.5));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Orbitron', monospace; font-weight: 700; font-size: 1rem;
            box-shadow: 0 0 15px rgba(var(--pc-rgb), .35);
        }
        .user-name  { font-weight: 600; font-size: .88rem; color: #e8edf5; }
        .user-role  { font-size: .7rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }

        /* ── MAIN ── */
        .main-content { margin-left: 280px; min-height: 100vh; }
        .cp-topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(15, 23, 41, .96); backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(var(--pc-rgb), .15);
            padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center;
        }
        .cp-topbar::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent 5%, var(--pc) 40%, transparent 95%); opacity: .3;
        }
        .page-title {
            font-family: 'Orbitron', monospace; font-size: 1.3rem; font-weight: 800;
            letter-spacing: 3px; text-transform: uppercase;
            background: linear-gradient(135deg, var(--pc), #fff);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 10px rgba(var(--pc-rgb), .3));
        }
        .topbar-badge {
            padding: .6rem 1.2rem;
            background: linear-gradient(135deg, rgba(var(--pc-rgb),.15), rgba(var(--pc-rgb),.05));
            border: 1px solid rgba(var(--pc-rgb), .3); border-radius: 20px;
            font-weight: 600; font-size: .75rem; color: var(--pc);
            text-transform: uppercase; letter-spacing: 1px;
        }
        .sidebar-toggle { display: none; padding: .6rem; background: rgba(var(--pc-rgb),.1); border: 1px solid rgba(var(--pc-rgb),.3); border-radius: 8px; color: var(--pc); cursor: pointer; }
        .page-content { padding: 2rem; }
        @media (max-width:1024px) { .cp-sidebar { transform: translateX(-100%); transition: transform .3s; } .cp-sidebar.show { transform: translateX(0); } .main-content { margin-left: 0; } .sidebar-toggle { display:flex; } }

        <?php
        // ── Inject theme overrides ──
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/config/panel_theme.php')) {
            // On génère manuellement les overrides sans passer par injectPanelTheme
            // car on utilise des variables CSS --pc déjà définies ci-dessus
        }
        ?>
    </style>
</head>
<body>
    <aside class="cp-sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <i class="fa-solid fa-<?php echo htmlspecialchars($panelIcon); ?>"></i>
                </div>
                <div>
                    <div class="sidebar-logo-text"><?php echo htmlspecialchars($panelName); ?></div>
                    <?php if ($panelSubtitle): ?>
                    <div class="sidebar-logo-subtitle"><?php echo htmlspecialchars($panelSubtitle); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php
            $shownSections = [];
            foreach ($navGeneral as $np):
                $sec    = $np['section'] ?? null;
                $active = ($np['page_key'] === $pageKey) ? 'active' : '';
                if ($sec && !in_array($sec, $shownSections)):
                    $shownSections[] = $sec;
            ?>
                <div class="nav-section-title"><?php echo htmlspecialchars($sec); ?></div>
            <?php endif; ?>
            <a href="<?php echo SITE_URL; ?>/pages/custom/?panel=<?php echo urlencode($panelKey); ?>&page=<?php echo urlencode($np['page_key']); ?>"
               class="nav-item <?php echo $active; ?>">
                <i class="<?php echo htmlspecialchars($np['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($np['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <?php if ($canAccessAdmin && !empty($navAdmin)): ?>
                <div class="nav-section-title">Administration</div>
                <?php foreach ($navAdmin as $np):
                    $active = ($np['page_key'] === $pageKey) ? 'active' : '';
                ?>
                <a href="<?php echo SITE_URL; ?>/pages/custom/?panel=<?php echo urlencode($panelKey); ?>&page=<?php echo urlencode($np['page_key']); ?>"
                   class="nav-item <?php echo $active; ?>">
                    <i class="<?php echo htmlspecialchars($np['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($np['label']); ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="nav-divider"></div>

            <?php if ($isStaff): ?>
            <a href="<?php echo SITE_URL; ?>/pages/staff/dashboard.php" class="nav-item">
                <i class="fas fa-layer-group"></i><span>Panel Staff</span>
            </a>
            <?php endif; ?>
            <a href="<?php echo SITE_URL; ?>/pages/dashboard.php" class="nav-item">
                <i class="fas fa-house"></i><span>Accueil MDT</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/logout.php" class="nav-item">
                <i class="fas fa-right-from-bracket"></i><span>Déconnexion</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?php echo htmlspecialchars(mdt_session_display_initial()); ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars(mdt_session_display_name() ?: 'Utilisateur'); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($panelName); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="cp-topbar">
            <div style="display:flex;align-items:center;gap:1.5rem;">
                <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('show')">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
            </div>
            <span class="topbar-badge"><?php echo htmlspecialchars($panelName); ?></span>
        </div>
        <div class="page-content">

<?php
// ── RENDU DU CONTENU DE PAGE ──────────────────────────────────
// Cherche un template spécifique dans /pages/custom/templates/
$templateFile = $_SERVER['DOCUMENT_ROOT'] . '/pages/custom/templates/' . $baseTemplate . '/' . $pageKey . '.php';
$genericFile  = $_SERVER['DOCUMENT_ROOT'] . '/pages/custom/templates/generic/' . $pageKey . '.php';

if (file_exists($templateFile)) {
    // Template spécifique (base_template + page_key)
    include $templateFile;

} elseif (file_exists($genericFile)) {
    // Template générique commun à tous les panels
    include $genericFile;

} else {
    // Rendu par défaut — page "en construction"
    echo '<div style="padding:3rem;text-align:center;background:rgba(255,255,255,.02);border:1px dashed rgba(var(--pc-rgb),.2);border-radius:16px;max-width:600px;margin:4rem auto;">';
    echo '<i class="fa-solid fa-hammer" style="font-size:2.5rem;color:var(--pc);opacity:.6;margin-bottom:1rem;"></i>';
    echo '<h3 style="font-family:Orbitron,monospace;letter-spacing:2px;margin-bottom:.5rem;">' . htmlspecialchars(strtoupper($pageTitle)) . '</h3>';
    echo '<p style="color:#64748b;font-size:.9rem;">Cette page est configurée mais son template n\'a pas encore été créé.</p>';
    echo '<p style="color:#475569;font-size:.78rem;margin-top:.5rem;">Créez le fichier :</p>';
    echo '<code style="display:inline-block;margin-top:.5rem;padding:4px 12px;background:rgba(255,255,255,.05);border-radius:6px;font-size:.75rem;color:var(--pc);">';
    echo htmlspecialchars('/pages/custom/templates/' . $baseTemplate . '/' . $pageKey . '.php');
    echo '</code>';
    echo '</div>';
}
?>

        </div><!-- .page-content -->
    </main>
</body>
</html>