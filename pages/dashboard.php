<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

// Multi-personnages : un personnage actif est requis
require_once ROOT_PATH . '/includes/characters.php';
mdt_require_character();

$pageTitle = 'Accueil MDT';

// ── Charger les panels actifs depuis la DB ──
$db = Database::getInstance()->getConnection();

// ── Dashboard Builder : permissions + thème global ──
require_once __DIR__ . '/../includes/dashboard/permissions.php';
require_once __DIR__ . '/../includes/dashboard/theme.php';
$dashboardTheme = dashboard_get_theme($db);

// ════════════════════════════════════════════════════════════════
// VÉRIFIER SI LE MDT EST HACKÉ
// ════════════════════════════════════════════════════════════════
$hackState = null;
$isHacked = false;
$hackTimeLeft = 0;
try {
    $hackState = $db->query("SELECT * FROM mdt_hack_state WHERE id=1 LIMIT 1")->fetch();
    if ($hackState && $hackState['is_hacked'] && $hackState['expires_at'] && strtotime($hackState['expires_at']) > time()) {
        $isHacked = true;
        $hackTimeLeft = strtotime($hackState['expires_at']) - time();
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════════
// VÉRIFIER LE RÔLE DE L'UTILISATEUR
// ════════════════════════════════════════════════════════════════
$isStaff = hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper']);

// ════════════════════════════════════════════════════════════════
// SI HACKÉ ET NON-STAFF (CITOYEN) → AFFICHER PAGE "SYSTÈME COMPROMIS"
// ════════════════════════════════════════════════════════════════
if ($isHacked && !$isStaff && $hackState) {
    $secondsLeft = max(0, strtotime($hackState['expires_at']) - time());
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>LYSORIA MDT — SYSTÈME COMPROMISE</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700;800&family=Orbitron:wght@400;700;900&display=swap');
            *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
            :root{--r1:#ff1744;--r2:#b71c1c;--bg:#000a00;--border:rgba(255,23,68,.2);}
            html,body{width:100%;height:100%;overflow:hidden}
            body{font-family:'JetBrains Mono',monospace;background:var(--bg);color:var(--r1);display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;}
            body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(0,0,0,.08) 0px,rgba(0,0,0,.08) 1px,transparent 1px,transparent 2px);}
            .container{position:relative;z-index:10;text-align:center;padding:40px;max-width:600px;}
            .warning-icon{font-size:4rem;margin-bottom:20px;animation:pulse 2s ease-in-out infinite;}
            @keyframes pulse{50%{opacity:.5;transform:scale(1.1);}}
            .title{font-family:'Orbitron',monospace;font-size:2rem;font-weight:900;letter-spacing:3px;margin-bottom:10px;text-shadow:0 0 20px rgba(255,23,68,.4);}
            .subtitle{font-size:.9rem;color:rgba(255,23,68,.6);margin-bottom:30px;}
            .box{border:2px solid var(--border);border-radius:8px;background:rgba(255,23,68,.05);padding:20px;margin-bottom:20px;font-size:.85rem;line-height:1.6;}
            .hacker-info{text-align:left;margin:10px 0;}
            .label{color:rgba(255,23,68,.5);font-weight:700;display:inline-block;width:120px;}
            .value{color:var(--r1);font-weight:700;}
            .timer{font-size:1.8rem;font-weight:900;color:var(--r1);letter-spacing:3px;text-shadow:0 0 15px rgba(255,23,68,.3);margin:20px 0;font-family:'DM Mono',monospace;}
            .footer{font-size:.7rem;color:rgba(255,23,68,.3);margin-top:30px;}
            .scanlines{position:fixed;inset:0;z-index:1;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(0,0,0,.08) 0px,rgba(0,0,0,.08) 1px,transparent 1px,transparent 2px);}
        </style>
    </head>
    <body>
        <div class="scanlines"></div>
        <div class="container">
            <div class="warning-icon"><i class="fa-solid fa-skull-crossbones"></i></div>
            <div class="title">⚠ SYSTÈME COMPROMISE ⚠</div>
            <div class="subtitle">Le MDT de Lysoria a été infiltré</div>
            <div class="box">
                <div class="hacker-info"><div class="label">INTRUS :</div><div class="value"><?php echo htmlspecialchars(strtoupper($hackState['hack_title'] ?? 'INCONNU')); ?></div></div>
                <div class="hacker-info"><div class="label">OPÉRATEUR :</div><div class="value"><?php echo htmlspecialchars($hackState['hacked_by'] ?? 'ANONYME'); ?></div></div>
                <div class="hacker-info"><div class="label">DÉBUT :</div><div class="value"><?php echo date('H:i:s', strtotime($hackState['hacked_at'])); ?></div></div>
            </div>
            <div style="font-size:.7rem;color:rgba(255,23,68,.4);margin-bottom:20px;">EXPIRATION DU HACK</div>
            <div class="timer" id="hackTimer"><?php echo gmdate('H:i:s', $secondsLeft); ?></div>
            <div class="footer">Accès au MDT suspendu jusqu'à restauration du système.<br>Contacter le DOJ ou les administrateurs pour plus d'informations.</div>
        </div>
        <script>
        (function(){
            const expTime = <?php echo strtotime($hackState['expires_at']); ?>;
            setInterval(()=>{
                const remaining = Math.max(0, expTime - Math.floor(Date.now()/1000));
                const h = Math.floor(remaining/3600).toString().padStart(2,'0');
                const m = Math.floor((remaining%3600)/60).toString().padStart(2,'0');
                const s = (remaining%60).toString().padStart(2,'0');
                document.getElementById('hackTimer').textContent = h + ':' + m + ':' + s;
                if(remaining <= 0) location.reload();
            }, 1000);
        })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

$activePanels      = [];
$panelConfigs      = [];
$panelSystemActive = false;

try {
    $rows = $db->query("SELECT panel_key, is_active FROM admin_panels")->fetchAll();
    $panelSystemActive = true;
    foreach ($rows as $r) $activePanels[$r['panel_key']] = (bool)$r['is_active'];
} catch (Exception $e) {
    $panelSystemActive = false;
}

try {
    $rows = $db->query("SELECT panel_key, custom_name, custom_color, custom_logo_url FROM admin_panel_config")->fetchAll();
    foreach ($rows as $r) $panelConfigs[$r['panel_key']] = $r;
} catch (Exception $e) {
    try {
        $rows = $db->query("SELECT panel_key, custom_name, custom_color FROM admin_panel_config")->fetchAll();
        foreach ($rows as $r) $panelConfigs[$r['panel_key']] = $r;
    } catch (Exception $e2) {}
}

$siteName = 'LysoriaMDT';
try {
    $row = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'site_name' LIMIT 1")->fetch();
    if ($row && $row['setting_value']) $siteName = $row['setting_value'];
} catch (Exception $e) {}

function isPanelActive($key) {
    global $activePanels, $panelSystemActive;
    if (!$panelSystemActive) {
        return true;
    }
    // Table vide : tout actif (legacy).
    // Clé absente : actif par défaut — évite d’effacer tout le dashboard si seule une migration
    // partielle a peuplé admin_panels (ex. nouveaux panels uniquement sans LSPD, GOUV, etc.).
    // Pour désactiver un panel, le staff crée / met à jour une ligne avec is_active = 0.
    if (!array_key_exists($key, $activePanels)) {
        return true;
    }
    return (bool) $activePanels[$key];
}

function getPanelName($key, $default) {
    global $panelConfigs;
    return $panelConfigs[$key]['custom_name'] ?? $default;
}

function getPanelColor($key, $default) {
    global $panelConfigs;
    return $panelConfigs[$key]['custom_color'] ?? $default;
}

/** URL logo MDT staff (http/https) ou chaîne vide → icône FA par défaut */
function getPanelLogoUrl($key) {
    global $panelConfigs;
    $u = trim((string) ($panelConfigs[$key]['custom_logo_url'] ?? ''));
    if ($u === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $u)) {
        return '';
    }
    return $u;
}

// ── Mail activé ? ─────────────────────────────────────────
$mailEnabled = false;
try {
    $r = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_enabled' LIMIT 1")->fetch();
    $mailEnabled = ($r && $r['setting_value'] == '1');
} catch(Exception $e){}

// ── Compteur mails non-lus ──────────────────────────────────
$mailUnread = 0;
try {
    $stMail = $db->prepare("
        SELECT COALESCE(SUM(mb.is_read=0),0)
        FROM mail_boxes mb
        JOIN mail_account_links l ON l.account_id=mb.account_id
        WHERE l.user_id=? AND mb.folder='inbox' AND mb.deleted_at IS NULL
    ");
    $stMail->execute([$_SESSION['user_id']]);
    $mailUnread = (int)$stMail->fetchColumn();
} catch(Exception $e){}

require_once __DIR__ . '/../config/mdt_panel_catalog.php';
$categories = mdt_panel_catalog_categories($db);

// Map label → category_key (pour le drag & drop des catégories en mode édition).
$__catKeyByLabel = [];
try {
    foreach ($db->query("SELECT category_key, label FROM dashboard_categories")->fetchAll(PDO::FETCH_ASSOC) as $__cr) {
        $__catKeyByLabel[(string) $__cr['label']] = (string) $__cr['category_key'];
    }
} catch (Exception $e) {}

// ── Filtrage panels par rôle MDT (mdt_role_panel_access + can_view_all_panels) ─
$__rolePanelRules      = [];
$__rolePanelHasRules   = false;
$__roleCanViewAll      = false; // "mode démo" : voit tous les panels actifs en lecture seule
try {
    if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
        $__userRoleName = (string)($_SESSION['user_role'] ?? 'Citoyen');
        // Vérifier si le rôle a can_view_all_panels (colonne peut être absente)
        try {
            $__rv = $db->prepare("SELECT can_view_all_panels FROM mdt_roles WHERE name = ? LIMIT 1");
            $__rv->execute([$__userRoleName]);
            $__roleCanViewAll = (bool)(int)($__rv->fetchColumn() ?: 0);
        } catch (Throwable $__colErr) {
            $__roleCanViewAll = false; // Colonne absente → migration non exécutée
        }

        // Charger les règles d'accès spécifiques
        $__rps = $db->prepare("SELECT panel_key, can_access FROM mdt_role_panel_access WHERE role_name = ?");
        $__rps->execute([$__userRoleName]);
        $__rows = $__rps->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($__rows)) {
            $__rolePanelHasRules = true;
            foreach ($__rows as $__r) {
                $__rolePanelRules[$__r['panel_key']] = (bool)(int)$__r['can_access'];
            }
        }
    }
} catch (Throwable $__e) {}

function isRoleAllowedPanel(string $panelKey): bool {
    global $__rolePanelRules, $__rolePanelHasRules, $__roleCanViewAll;
    if ($__roleCanViewAll) return true;           // Mode démo → tous les panels autorisés
    if (!$__rolePanelHasRules) return true;       // Pas de règles → permissif
    return $__rolePanelRules[$panelKey] ?? true;  // Pas de règle pour ce panel → permissif
}

/**
 * Liste des faction_keys appartenant à la catégorie « Services Publics » du catalogue,
 * utilisée pour gater l'accès au panel Académie Centralisée.
 * Construit dynamiquement depuis $categories pour rester en phase avec le catalog.
 */
function mdt_public_service_factions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $categories;
    $cache = [];
    if (isset($categories['Services Publics']) && is_array($categories['Services Publics'])) {
        foreach ($categories['Services Publics'] as $sp) {
            $fk = $sp['faction'] ?? '';
            if ($fk !== '' && $fk !== 'STAFF') $cache[] = $fk;
        }
    }
    return $cache;
}

function hasAccess($panel) {
    // Staff / admins : accès à tout
    if (hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
        return true;
    }
    // Mode démo : accès à tous les panels actifs (lecture seule)
    if (is_demo_mode()) return true;
    // DarkNet : uniquement les factions illégales listées
    if (($panel['key'] ?? '') === 'DARKNET') {
        return userHasDarknetAccess();
    }
    if (!empty($panel['mail_panel'])) return true;

    // ── Académie publique : accessible à tout citoyen connecté ──────────────
    if (!empty($panel['academy_public'])) {
        return isLoggedIn();
    }

    // ── Académie centralisée : accessible si l'utilisateur est dans une faction
    // de service public, OU s'il possède au moins un grade académie (instructeurs transverses).
    if (!empty($panel['academy_central'])) {
        $userFaction = $_SESSION['user_faction'] ?? '';
        if ($userFaction !== '' && in_array($userFaction, mdt_public_service_factions(), true)) {
            return true;
        }
        if (function_exists('academy_user_accessible_factions')) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid > 0 && !empty(academy_user_accessible_factions($uid))) return true;
        }
        return false;
    }

    if (isset($panel['roles']) && hasRole($panel['roles'])) return true;
    if (isset($panel['faction']) && !empty($panel['faction']) && ($_SESSION['user_faction'] ?? '') === $panel['faction']) return true;
    if (isset($panel['custom']) && empty($panel['faction'])) return false;
    return false;
}

// ── Divisions de l'utilisateur (panels de division accessibles) ─────────────
require_once __DIR__ . '/../includes/division_access.php';
$__userDivisions = div_get_user_memberships((int)($_SESSION['user_id'] ?? 0));
// Staff bypass : complète la liste avec toutes les divisions actives
if (div_is_staff_bypass()) {
    try {
        $rows = $db->query("
            SELECT fd.id AS division_id, fd.panel_key, fd.name AS division_name,
                   fd.abbreviation AS division_abbr, fd.color AS division_color,
                   fd.icon AS division_icon, fd.target_url, fd.is_active AS division_active,
                   fd.show_on_dashboard,
                   f.faction_key, f.name AS faction_name,
                   NULL AS membership_id, NULL AS division_grade_id,
                   NULL AS role, NULL AS joined_date,
                   NULL AS grade_name, NULL AS grade_can_manage
              FROM faction_divisions fd
              JOIN factions f ON f.id = fd.faction_id
             WHERE fd.is_active = 1 AND fd.show_on_dashboard = 1
             ORDER BY f.name, fd.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        // Merge : garde les memberships existants + ajoute les divisions non-membres
        $__existingIds = array_flip(array_column($__userDivisions, 'division_id'));
        foreach (($rows ?: []) as $r) {
            if (!isset($__existingIds[(int)$r['division_id']])) $__userDivisions[] = $r;
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr" data-dashboard-theme="<?php echo htmlspecialchars($dashboardTheme['mode']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php dashboard_render_theme_head($dashboardTheme); // thème global (anti-FOUC + vars custom) ?>
    <title><?php echo $pageTitle; ?> — <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:        #03070f;
            --bg-2:      #080f1d;
            --bg-card:   rgba(10, 17, 32, 0.7);
            --border:    rgba(255,255,255,0.07);
            --border-2:  rgba(255,255,255,0.12);
            --text:      #e2e8f0;
            --muted:     #475569;
            --muted-2:   #64748b;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            background-image:
                radial-gradient(ellipse 80% 50% at 20% -10%, rgba(59,130,246,0.08) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 80% 110%, rgba(139,92,246,0.06) 0%, transparent 60%);
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 0 2rem; width: 100%; }

        /* ── HEADER ── */
        header {
            background: rgba(3, 7, 15, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-inner {
            height: 64px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            font-family: 'Syne', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: #3b82f6;
            border-radius: 9px;
            display: grid; place-items: center;
            font-size: 15px; color: white;
        }

        .brand-text span { color: #3b82f6; }

        .header-right { display: flex; align-items: center; gap: 12px; }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 14px 6px 6px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 50px;
        }

        a.user-chip {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        a.user-chip:hover { border-color: rgba(59,130,246,.4); background: rgba(59,130,246,.06); }
        .user-switch-icon { font-size: .7rem; color: var(--muted-2); opacity: .6; margin-left: 4px; }

        .user-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #3b82f6;
            display: grid; place-items: center;
            font-weight: 700; font-size: 13px;
            overflow: hidden; flex-shrink: 0;
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-info-text { line-height: 1.2; }
        .user-name { font-size: .83rem; font-weight: 600; }
        .user-role { font-size: .68rem; color: var(--muted-2); }

        .header-btn {
            width: 36px; height: 36px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            color: var(--muted-2);
            display: grid; place-items: center;
            text-decoration: none;
            transition: all .2s;
            font-size: 14px;
        }

        .header-btn:hover { background: rgba(255,255,255,0.08); border-color: var(--border-2); color: var(--text); }
        .header-btn.logout:hover { color: #ef4444; border-color: rgba(239,68,68,.4); background: rgba(239,68,68,.08); }

        /* ── STAFF BANNER ── */
        .staff-banner {
            background: rgba(59,130,246,.06);
            border-bottom: 1px solid rgba(59,130,246,.15);
            padding: 10px 0;
        }

        .staff-banner-inner {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .8rem;
            color: #93c5fd;
        }

        .staff-banner-inner i { font-size: .85rem; }
        .staff-banner-inner strong { font-weight: 700; }

        .staff-banner-inner a {
            margin-left: auto;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            font-size: .75rem;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border: 1px solid rgba(59,130,246,.3);
            border-radius: 6px;
            transition: all .2s;
        }

        .staff-banner-inner a:hover { background: rgba(59,130,246,.1); }

        /* ── MAIN ── */
        main { padding: 3rem 0 5rem; flex: 1; }

        .hero {
            margin-bottom: 3.5rem;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .hero-eyebrow {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: #3b82f6;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hero-eyebrow::before {
            content: '';
            display: inline-block;
            width: 20px; height: 2px;
            background: #3b82f6;
            border-radius: 1px;
        }

        .hero h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -.03em;
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .hero p { color: var(--muted-2); font-size: .95rem; }

        .hero-stats { display: flex; gap: 24px; flex-shrink: 0; }

        .hero-stat { text-align: right; }

        .hero-stat-val {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 2px;
        }

        .hero-stat-label {
            font-size: .7rem;
            color: var(--muted-2);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        /* ── CATEGORY ── */
        /* Ne pas animer l’opacité : avec fill-mode "both", certains navigateurs / préférences
           laissent les blocs à opacity:0 → liste de panels invisible. */
        .category-group {
            margin-bottom: 3rem;
            opacity: 1;
            animation: fadeUpShift .45s ease;
        }

        @keyframes fadeUpShift {
            from { transform: translateY(12px); }
            to   { transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .category-group { animation: none; }
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }

        .category-label {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--muted);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .category-count {
            background: rgba(255,255,255,.06);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 1px 6px;
            font-size: .6rem;
            color: var(--muted-2);
        }

        .category-line { flex: 1; height: 1px; background: var(--border); }

        /* ── GRID ── */
        .panels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
        }

        /* ── PANEL CARD (ACTIVE) ── */
        .panel-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 22px 20px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            overflow: hidden;
            transition: transform .3s cubic-bezier(.4,0,.2,1),
                        border-color .3s,
                        box-shadow .3s,
                        background .3s;
            backdrop-filter: blur(8px);
        }

        .panel-card::before {
            content: '';
            position: absolute;
            top: 0; left: 20px; right: 20px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--p-color, #3b82f6), transparent);
            opacity: 0;
            transition: opacity .3s;
        }

        .panel-card:hover {
            transform: translateY(-6px);
            border-color: color-mix(in srgb, var(--p-color, #3b82f6) 40%, transparent);
            background: rgba(10, 18, 36, 0.9);
            box-shadow: 0 16px 40px -12px rgba(0,0,0,.6),
                        0 0 0 1px color-mix(in srgb, var(--p-color, #3b82f6) 15%, transparent);
        }

        .panel-card:hover::before { opacity: 1; }

        .panel-card-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: grid; place-items: center;
            font-size: 1.3rem;
            color: var(--p-color, #3b82f6);
            background: color-mix(in srgb, var(--p-color, #3b82f6) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--p-color, #3b82f6) 20%, transparent);
            transition: all .3s;
            flex-shrink: 0;
        }

        .panel-card:hover .panel-card-icon {
            background: var(--p-color, #3b82f6);
            color: white;
            box-shadow: 0 0 20px color-mix(in srgb, var(--p-color, #3b82f6) 50%, transparent);
        }

        .panel-card-icon--logo {
            padding: 6px;
        }
        .panel-card-icon--logo img {
            width: 100%;
            height: 100%;
            max-width: 32px;
            max-height: 32px;
            object-fit: contain;
            display: block;
        }
        .panel-card:hover .panel-card-icon--logo {
            background: color-mix(in srgb, var(--p-color, #3b82f6) 22%, transparent);
            color: inherit;
            box-shadow: 0 0 20px color-mix(in srgb, var(--p-color, #3b82f6) 45%, transparent);
        }

        .panel-card-name {
            font-family: 'Syne', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .panel-card-faction {
            font-size: .65rem;
            color: var(--muted-2);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .panel-card-arrow {
            position: absolute;
            bottom: 18px; right: 18px;
            width: 26px; height: 26px;
            border-radius: 7px;
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            display: grid; place-items: center;
            font-size: 10px;
            color: var(--muted);
            opacity: 0;
            transform: translateX(-4px);
            transition: all .25s;
        }

        .panel-card:hover .panel-card-arrow {
            opacity: 1;
            transform: translateX(0);
            color: var(--p-color, #3b82f6);
            border-color: color-mix(in srgb, var(--p-color, #3b82f6) 30%, transparent);
        }

        /* ── CARTE MAIL SPÉCIALE ── */
        .panel-card--mail {
            background: linear-gradient(135deg, rgba(30,64,175,.18), rgba(59,130,246,.08));
            border-color: rgba(59,130,246,.3) !important;
        }
        .panel-card--mail:hover {
            border-color: rgba(59,130,246,.55) !important;
            box-shadow: 0 8px 30px rgba(59,130,246,.2);
        }
        .panel-card--mail .panel-card-icon {
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            box-shadow: 0 4px 14px rgba(59,130,246,.4);
        }
        .mail-unread-badge {
            display: inline-flex; align-items: center; justify-content: center;
            background: #3b82f6; color: #fff;
            font-size: .58rem; font-weight: 800;
            border-radius: 99px; padding: 1px 6px; min-width: 18px;
            margin-left: 6px; vertical-align: middle;
            animation: mailpulse 2s infinite;
        }
        @keyframes mailpulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,.4); }
            50%      { box-shadow: 0 0 0 5px transparent; }
        }

        /* ── PANEL CARD DÉSACTIVÉ — visible staff seulement, toujours cliquable ── */
        .panel-card-inactive {
            opacity: .55;
            position: relative;
        }

        .panel-card-inactive::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 18px;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 4px,
                rgba(255,255,255,.015) 4px,
                rgba(255,255,255,.015) 8px
            );
            pointer-events: none;
        }

        .disabled-badge {
            position: absolute;
            top: 12px; right: 12px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 6px;
            padding: 2px 7px;
            font-size: .58rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 4px;
            z-index: 1;
        }

        .panel-card-inactive:hover .disabled-badge {
            background: rgba(239,68,68,.1);
            border-color: rgba(239,68,68,.2);
            color: #fca5a5;
        }

        /* ── EMPTY ── */
        .no-panels {
            grid-column: 1 / -1;
            padding: 40px;
            text-align: center;
            color: var(--muted-2);
            font-size: .85rem;
        }

        /* ── FOOTER ── */
        footer {
            background: rgba(3,7,15,.9);
            border-top: 1px solid var(--border);
            padding: 1.5rem 0;
        }

        .footer-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .78rem;
            color: var(--muted);
            gap: 12px;
            flex-wrap: wrap;
        }

        .footer-inner a { color: var(--muted-2); text-decoration: none; }
        .footer-inner a:hover { color: var(--text); }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .container { padding: 0 1rem; }
            .user-info-text { display: none; }
            .hero { flex-direction: column; align-items: flex-start; gap: 16px; }
            .hero-stats { gap: 16px; }
            .panels-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
            .panel-card { padding: 16px 14px; }
        }

        @media (max-width: 480px) {
            .panels-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* ── NOTIFICATION BELL ── */
        .notif-bell-wrap { position: relative; }
        .notif-bell-btn {
            width: 36px; height: 36px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            color: var(--muted-2);
            display: grid; place-items: center;
            cursor: pointer;
            transition: all .2s;
            font-size: 14px;
            position: relative;
        }
        .notif-bell-btn:hover { background: rgba(255,255,255,0.08); border-color: var(--border-2); color: var(--text); }
        .notif-bell-btn.has-notif { color: #3b82f6; border-color: rgba(59,130,246,.35); background: rgba(59,130,246,.08); }

        .notif-dot {
            display: none;
            position: absolute; top: 5px; right: 5px;
            width: 8px; height: 8px;
            background: #3b82f6;
            border-radius: 50%;
            border: 2px solid var(--bg);
            animation: bellpulse 2s infinite;
        }
        .notif-dot.show { display: block; }
        @keyframes bellpulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,.5); }
            50%      { box-shadow: 0 0 0 4px transparent; }
        }

        .notif-dropdown {
            display: none;
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 320px;
            background: rgba(8,14,28,.97);
            border: 1px solid var(--border-2);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.6);
            z-index: 500;
            overflow: hidden;
            animation: dropIn .18s ease;
        }
        @keyframes dropIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }
        .notif-dropdown.open { display: block; }

        .notif-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--border);
        }
        .notif-header-title { font-size: .8rem; font-weight: 700; display: flex; align-items: center; gap: 7px; }
        .notif-header-title i { color: #3b82f6; }
        .notif-count-badge { background: #3b82f6; color: #fff; font-size: .6rem; font-weight: 800; border-radius: 99px; padding: 1px 6px; min-width: 18px; text-align: center; }
        .notif-mark-all { font-size: .7rem; color: var(--muted-2); background: none; border: none; cursor: pointer; transition: color .15s; padding: 0; }
        .notif-mark-all:hover { color: #3b82f6; }

        .notif-list { max-height: 320px; overflow-y: auto; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-track { background: transparent; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 2px; }

        .notif-item-row {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 11px 16px;
            border-bottom: 1px solid rgba(255,255,255,.04);
            cursor: pointer;
            transition: background .15s;
            text-decoration: none; color: inherit;
        }
        .notif-item-row:hover { background: rgba(59,130,246,.06); }
        .notif-item-row:last-child { border-bottom: none; }

        .notif-ava-db {
            width: 34px; height: 34px; border-radius: 9px;
            background: #3b82f6;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: .78rem; color: #fff;
            flex-shrink: 0; overflow: hidden;
        }
        .notif-ava-db img { width: 100%; height: 100%; object-fit: cover; }

        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-from { font-size: .78rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
        .notif-item-sub { font-size: .72rem; color: var(--muted-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item-time { font-size: .62rem; color: var(--muted); white-space: nowrap; flex-shrink: 0; margin-top: 2px; }

        .notif-empty { padding: 28px 16px; text-align: center; font-size: .78rem; color: var(--muted-2); display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .notif-empty i { font-size: 1.4rem; color: var(--muted); }

        .notif-footer { padding: 10px 16px; border-top: 1px solid var(--border); text-align: center; }
        .notif-footer a { font-size: .75rem; color: #3b82f6; text-decoration: none; font-weight: 600; transition: opacity .15s; }
        .notif-footer a:hover { opacity: .8; }
    </style>
    <!-- Dashboard Builder : chargé APRÈS le style inline pour pouvoir surcharger les thèmes -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/dashboard-builder.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/dashboard-builder.css') ?: '1'; ?>">
</head>
<body>

<header>
    <div class="container header-inner">
        <a href="/pages/dashboard.php" class="brand">
            <div class="brand-icon"><i class="fa-solid fa-microchip"></i></div>
            <div class="brand-text"><?php echo htmlspecialchars($siteName); ?></div>
        </a>
        <div class="header-right">
            <a href="/pages/personnage.php" class="user-chip" title="Changer de personnage">
                <div class="user-avatar">
                    <?php if (!empty($_SESSION['user_avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($_SESSION['user_avatar']); ?>" alt="">
                    <?php else: ?>
                        <?php echo htmlspecialchars(mdt_session_display_initial()); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo htmlspecialchars(mdt_session_display_name()); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_role']); ?></div>
                </div>
                <i class="fa-solid fa-repeat user-switch-icon"></i>
            </a>
            <a href="/pages/espace-perso.php" class="header-btn" title="Mon Espace Personnel"><i class="fa-solid fa-id-card-clip"></i></a>
            <a href="/profile.php" class="header-btn" title="Paramètres"><i class="fa-solid fa-user-gear"></i></a>

            <?php if ($mailEnabled ?? false): ?>
            <div class="notif-bell-wrap" id="notifBellWrap">
                <button class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown()" title="Notifications mail">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-dot" id="notifDot"></span>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <div class="notif-header-title">
                            <i class="fa-solid fa-bell"></i>
                            Notifications
                            <span class="notif-count-badge" id="notifCountBadge" style="display:none;"></span>
                        </div>
                        <button class="notif-mark-all" onclick="markAllSeen()">Tout marquer lu</button>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>Aucune notification</div>
                    </div>
                    <div class="notif-footer">
                        <a href="<?php echo SITE_URL; ?>/pages/mail/index.php"><i class="fa-solid fa-inbox"></i> Ouvrir la messagerie</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <a href="/logout.php" class="header-btn logout" title="Déconnexion"><i class="fa-solid fa-power-off"></i></a>
        </div>
    </div>
</header>

<?php if ($isStaff): ?>
<div class="staff-banner">
    <div class="container staff-banner-inner">
        <i class="fa-solid fa-eye"></i>
        <span>Mode <strong>Staff</strong> — Tous les panels sont visibles et accessibles. Les panels grisés sont désactivés mais restent cliquables.</span>
        <a href="<?php echo SITE_URL; ?>/pages/staff/content/panels.php">
            <i class="fa-solid fa-sliders"></i> Gérer les panels
        </a>
        <?php if (dashboard_can_preview()): ?>
        <button type="button" class="db-preview-btn" id="dbPreviewBtn">
            <i class="fa-solid fa-user-group"></i> Version Joueurs
        </button>
        <?php endif; ?>
        <?php if (dashboard_can_build()): ?>
        <button type="button" class="db-custom-btn" id="dbCustomBtn">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Custom
        </button>
        <?php endif; ?>
    </div>
</div>
<?php elseif (dashboard_can_preview()): ?>
<div class="staff-banner">
    <div class="container staff-banner-inner">
        <i class="fa-solid fa-user-group"></i>
        <span>Outils de <strong>prévisualisation</strong></span>
        <button type="button" class="db-preview-btn" id="dbPreviewBtn" style="margin-left:auto">
            <i class="fa-solid fa-eye"></i> Version Joueurs
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($isHacked && $hackState): ?>
<div style="background:rgba(239,68,68,.1);border-bottom:2px solid #ef4444;padding:16px 0;">
    <div class="container" style="display:flex;align-items:center;gap:16px;justify-content:space-between;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:300px;">
            <i class="fa-solid fa-skull-crossbones" style="font-size:1.4rem;color:#ef4444;"></i>
            <div>
                <div style="font-weight:700;color:#ef4444;font-size:.95rem;">⚠ SYSTÈME COMPROMISE</div>
                <div style="font-size:.82rem;color:rgba(239,68,68,.8);">
                    Intrus: <strong><?php echo htmlspecialchars(strtoupper($hackState['hack_title'] ?? 'INCONNU')); ?></strong>
                    • Hackeur: <?php echo htmlspecialchars($hackState['hacked_by'] ?? 'anonyme'); ?>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="text-align:right;">
                <div style="font-size:.7rem;color:rgba(239,68,68,.6);letter-spacing:1px;">EXPIRATION</div>
                <div id="hackExpireCountdown" style="font-family:'DM Mono',monospace;font-size:1.1rem;font-weight:700;color:#ef4444;letter-spacing:2px;">
                    <?php echo gmdate('H:i:s', $hackTimeLeft); ?>
                </div>
            </div>
            <a href="<?php echo SITE_URL; ?>/pages/staff/content/hack-manager.php" class="header-btn" style="background:rgba(239,68,68,.15);border-color:#ef4444;color:#ef4444;padding:8px 12px;font-size:.75rem;" title="Gérer le hack">
                <i class="fa-solid fa-shield-halved"></i> Gérer
            </a>
        </div>
    </div>
</div>
<script>
(function(){
    const expTime = <?php echo strtotime($hackState['expires_at']); ?>;
    setInterval(()=>{
        const remaining = Math.max(0, expTime - Math.floor(Date.now()/1000));
        const h = Math.floor(remaining/3600).toString().padStart(2,'0');
        const m = Math.floor((remaining%3600)/60).toString().padStart(2,'0');
        const s = (remaining%60).toString().padStart(2,'0');
        document.getElementById('hackExpireCountdown').textContent = h + ':' + m + ':' + s;
    }, 1000);
})();
</script>
<?php endif; ?>

<main>
    <div class="container">

        <div class="hero">
            <div class="hero-left">
                <div class="hero-eyebrow">Tableau de bord</div>
                <h1>Bonjour, <?php echo htmlspecialchars(mdt_session_display_name()); ?> 👋</h1>
                <p>Accédez aux bases de données et outils de votre faction.</p>
            </div>
            <?php
            $totalVisible = 0; $totalActive = 0;
            foreach ($categories as $panels) {
                if (!is_array($panels)) {
                    continue;
                }
                foreach ($panels as $p) {
                    if (!hasAccess($p) && !$isStaff) {
                        continue;
                    }
                    $totalVisible++;
                    if (isPanelActive($p['key']) || ($p['always_active'] ?? false)) {
                        $totalActive++;
                    }
                }
            }
            ?>
            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-val" style="color:#3b82f6;"><?php echo $totalActive; ?></div>
                    <div class="hero-stat-label">Panels actifs</div>
                </div>
                <?php if ($isStaff): ?>
                <div class="hero-stat">
                    <div class="hero-stat-val" style="color:#64748b;"><?php echo $totalVisible - $totalActive; ?></div>
                    <div class="hero-stat-label">Désactivés</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $delay = 0;
        foreach ($categories as $categoryName => $panels):

            if (!is_array($panels)) {
                continue;
            }

            $visiblePanels = [];
            foreach ($panels as $p) {
                $active = isPanelActive($p['key']) || ($p['always_active'] ?? false);
                $access = hasAccess($p);

                if ($isStaff) {
                    // ══ STAFF : voit TOUT et peut accéder à TOUT ══
                    $visiblePanels[] = array_merge($p, ['_active' => true, '_disabled_for_others' => !$active]);
                } elseif ($__roleCanViewAll && $active && isRoleAllowedPanel($p['key'])) {
                    // ══ MODE DÉMO : voit tous les panels actifs, badge "Lecture seule" ══
                    $visiblePanels[] = array_merge($p, ['_active' => true, '_disabled_for_others' => false, '_demo_view' => true]);
                } else {
                    // Joueur normal : panels actifs + accessibles + autorisés par le rôle MDT
                    if ($access && $active && isRoleAllowedPanel($p['key'])) {
                        $visiblePanels[] = array_merge($p, ['_active' => true, '_disabled_for_others' => false]);
                    }
                }
            }

            if (empty($visiblePanels)) continue;

            $catActive = count(array_filter($visiblePanels, static function ($p) {
                return !($p['_disabled_for_others'] ?? false);
            }));
        ?>
        <section class="category-group" style="animation-delay: <?php echo $delay * 60; ?>ms" data-db-cat-key="<?php echo htmlspecialchars($__catKeyByLabel[$categoryName] ?? $categoryName); ?>">
            <div class="category-header">
                <div class="category-label">
                    <?php echo htmlspecialchars($categoryName); ?>
                    <span class="category-count"><?php echo $catActive; ?>/<?php echo count($visiblePanels); ?></span>
                </div>
                <div class="category-line"></div>
            </div>

            <div class="panels-grid">
                <?php foreach ($visiblePanels as $panel):
                    $name     = getPanelName($panel['key'], $panel['name']);
                    $color    = getPanelColor($panel['key'], $panel['color']);
                    $logoUrl  = getPanelLogoUrl($panel['key']);
                    $url      = SITE_URL . $panel['url'];
                    $isDisabledForOthers = $panel['_disabled_for_others'] ?? false;
                ?>
                <a href="<?php echo $url; ?>"
                   class="panel-card<?php echo !empty($panel['mail_panel']) ? ' panel-card--mail' : ''; ?><?php echo $isDisabledForOthers ? ' panel-card-inactive' : ''; ?>"
                   data-db-panel-key="<?php echo htmlspecialchars($panel['key']); ?>"
                   style="--p-color: <?php echo htmlspecialchars($color); ?>"
                   <?php if ($isDisabledForOthers): ?>title="Panel désactivé — visible staff uniquement"<?php endif; ?>>

                    <?php if ($isDisabledForOthers): ?>
                    <div class="disabled-badge">
                        <i class="fa-solid fa-eye-slash" style="font-size:.5rem;"></i>
                        Désactivé
                    </div>
                    <?php elseif ($panel['_demo_view'] ?? false): ?>
                    <div class="disabled-badge" style="background:rgba(139,92,246,.18);border-color:rgba(139,92,246,.4);color:#a78bfa">
                        <i class="fa-solid fa-eye" style="font-size:.5rem;"></i>
                        Démo
                    </div>
                    <?php endif; ?>

                    <div class="panel-card-icon<?php echo $logoUrl !== '' ? ' panel-card-icon--logo' : ''; ?>">
                        <?php if ($logoUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                        <i class="fa-solid fa-<?php echo htmlspecialchars($panel['icon']); ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="panel-card-body">
                        <div class="panel-card-name">
                            <?php echo htmlspecialchars($name); ?>
                            <?php if (!empty($panel['mail_panel']) && $mailUnread > 0): ?>
                                <span class="mail-unread-badge"><?php echo $mailUnread; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="panel-card-faction">
                            <?php if (!empty($panel['mail_panel'])): ?>
                                <?php echo $mailUnread > 0 ? $mailUnread . ' message' . ($mailUnread > 1 ? 's' : '') . ' non-lu' . ($mailUnread > 1 ? 's' : '') : 'Messagerie interne'; ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars($panel['key']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="panel-card-arrow">
                        <i class="fa-solid fa-arrow-right"></i>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php $delay++; endforeach; ?>

        <!-- ══ MES DIVISIONS ══ -->
        <?php if (!empty($__userDivisions)):
            // Filtrer divisions actives + panel activé dans admin_panels
            $__myDivs = [];
            foreach ($__userDivisions as $md) {
                if ((int)($md['division_active'] ?? 1) !== 1) continue;
                if (!empty($md['panel_key']) && !isPanelActive($md['panel_key'])) {
                    if (!$isStaff) continue;
                }
                $__myDivs[] = $md;
            }
        if (!empty($__myDivs)):
        ?>
        <section class="category-group" style="animation-delay:<?php echo $delay * 60; ?>ms">
            <div class="category-header">
                <div class="category-label">
                    Mes divisions
                    <span class="category-count"><?php echo count($__myDivs); ?></span>
                </div>
                <div class="category-line"></div>
            </div>
            <div class="panels-grid">
                <?php foreach ($__myDivs as $md):
                    $dUrl   = SITE_URL . '/pages/divisions/panel.php?key=' . urlencode($md['panel_key']);
                    $dColor = htmlspecialchars($md['division_color'] ?? '#64748b');
                    $dIcon  = htmlspecialchars($md['division_icon'] ?? 'layer-group');
                    $dName  = htmlspecialchars($md['division_name']);
                    $dAbbr  = htmlspecialchars($md['division_abbr'] ?? '');
                    $fName  = htmlspecialchars($md['faction_name'] ?? '');
                    $grade  = $md['grade_name'] ?? $md['role'] ?? '';
                ?>
                <a href="<?php echo $dUrl; ?>" class="panel-card" style="--p-color: <?php echo $dColor; ?>">
                    <div class="panel-card-icon"><i class="fa-solid fa-<?php echo $dIcon; ?>"></i></div>
                    <div class="panel-card-body">
                        <div class="panel-card-name"><?php echo $dName; ?></div>
                        <div class="panel-card-faction">
                            <?php echo $fName; ?><?php if ($dAbbr !== '' && $dAbbr !== $dName) echo ' · '.$dAbbr; ?>
                            <?php if ($grade): ?>
                                <span style="display:inline-block;margin-left:.4rem;padding:.1rem .4rem;border-radius:4px;background:rgba(255,255,255,.05);font-size:.65rem;">
                                    <?php echo htmlspecialchars($grade); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="panel-card-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php $delay++; endif; endif; ?>

        <!-- ══ SITES ANNEXES ══ -->
        <section class="category-group" style="animation-delay:<?php echo $delay * 60; ?>ms">
            <div class="category-header">
                <div class="category-label">
                    Sites Annexes
                    <span class="category-count">3/3</span>
                </div>
                <div class="category-line"></div>
            </div>
            <div class="panels-grid">
                <a href="https://lspa.lysoria.fr" class="panel-card" style="--p-color:#2563eb;">
                    <div class="panel-card-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="panel-card-body">
                        <div class="panel-card-name">Site LSPA</div>
                        <div class="panel-card-faction">Candidature LSPA</div>
                    </div>
                    <div class="panel-card-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                </a>
                <a href="<?php echo SITE_URL; ?>/pages/companies/life-invader/public/index.php" target="_blank" class="panel-card" style="--p-color:#4f7cff;">
                    <div class="panel-card-icon"><i class="fa-solid fa-newspaper"></i></div>
                    <div class="panel-card-body">
                        <div class="panel-card-name">Life Invader</div>
                        <div class="panel-card-faction">Le journal de Lysoria</div>
                    </div>
                    <div class="panel-card-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                </a>
                <a href="https://pearls.resort.lysoria.fr" class="panel-card" style="--p-color:#d4a853;">
                    <div class="panel-card-icon"><i class="fa-solid fa-hotel"></i></div>
                    <div class="panel-card-body">
                        <div class="panel-card-name">Pearl's Hotel</div>
                        <div class="panel-card-faction">Réservation d'hôtel</div>
                    </div>
                    <div class="panel-card-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                </a>
            </div>
        </section>

    </div>
</main>

<footer>
    <div class="container footer-inner">
        <div>&copy; <?php echo date('Y'); ?> <a href="/pages/dashboard.php"><?php echo htmlspecialchars($siteName); ?></a>. Tous droits réservés.</div>
        <div>Propulsé par Wolf12_ — développeur de Lysoria</div>
    </div>
</footer>

<?php if ($mailEnabled): ?>
<script>
const MAIL_API  = '<?php echo SITE_URL; ?>/pages/mail/api.php';
const SITE_BASE = '<?php echo SITE_URL; ?>';

let _notifOpen = false;
let _lastCount = 0;

function toggleNotifDropdown() {
    _notifOpen = !_notifOpen;
    document.getElementById('notifDropdown').classList.toggle('open', _notifOpen);
    if (_notifOpen) loadNotifications();
}

document.addEventListener('click', e => {
    const wrap = document.getElementById('notifBellWrap');
    if (wrap && !wrap.contains(e.target) && _notifOpen) {
        _notifOpen = false;
        document.getElementById('notifDropdown').classList.remove('open');
    }
});

async function loadNotifications() {
    try {
        const r = await fetch(MAIL_API + '?action=get_notifications');
        const data = await r.json();
        if (!data.ok) return;
        renderNotifications(data.data || []);
    } catch(e) {}
}

function renderNotifications(notifs) {
    const list  = document.getElementById('notifList');
    const dot   = document.getElementById('notifDot');
    const badge = document.getElementById('notifCountBadge');
    const btn   = document.getElementById('notifBellBtn');
    const count = notifs.length;

    if (count > 0) {
        dot.classList.add('show');
        btn.classList.add('has-notif');
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'inline-block';
    } else {
        dot.classList.remove('show');
        btn.classList.remove('has-notif');
        badge.style.display = 'none';
    }

    if (count > _lastCount && _lastCount >= 0) playNotifSound();
    _lastCount = count;

    if (!count) {
        list.innerHTML = `<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>Aucune notification</div>`;
        return;
    }

    list.innerHTML = notifs.slice(0, 8).map(n => {
        const initial = (n.from_address || '?')[0].toUpperCase();
        const colors  = ['#3b82f6','#8b5cf6','#10b981','#f97316','#ec4899','#06b6d4','#f59e0b'];
        const color   = colors[(initial.charCodeAt(0) || 0) % colors.length];
        const ava = n.from_logo
            ? `<div class="notif-ava-db"><img src="${SITE_BASE}/assets/${esc(n.from_logo)}" alt="" onerror="this.parentElement.innerHTML='${initial}'"></div>`
            : `<div class="notif-ava-db" style="background:${color};">${initial}</div>`;
        const time = timeAgo(n.created_at);
        return `
        <a class="notif-item-row" href="${SITE_BASE}/pages/mail/index.php" onclick="markAllSeen()">
            ${ava}
            <div class="notif-item-body">
                <div class="notif-item-from">${esc(n.from_address)}</div>
                <div class="notif-item-sub">${esc(n.subject || '(Sans objet)')}</div>
            </div>
            <div class="notif-item-time">${time}</div>
        </a>`;
    }).join('');
}

async function markAllSeen() {
    try {
        await fetch(MAIL_API + '?action=mark_notifications_seen', { method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=mark_notifications_seen'
        });
    } catch(e) {}
    document.getElementById('notifDot').classList.remove('show');
    document.getElementById('notifBellBtn').classList.remove('has-notif');
    document.getElementById('notifCountBadge').style.display = 'none';
    document.getElementById('notifList').innerHTML = `<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>Aucune notification</div>`;
    _lastCount = 0;
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)  return 'À l\'instant';
    if (diff < 3600) return Math.floor(diff/60) + 'min';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    return Math.floor(diff/86400) + 'j';
}

function playNotifSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
        osc.start(); osc.stop(ctx.currentTime + 0.3);
    } catch(e) {}
}

loadNotifications();
setInterval(loadNotifications, 30000);
</script>
<?php endif; ?>

<?php
$__canBuild   = dashboard_can_build();
$__canPreview = dashboard_can_preview();
if ($__canBuild || $__canPreview):
    require __DIR__ . '/../includes/mdt_company_confirm_load.php'; // MDTConfirm (toasts/confirmations)
    $__dbAsset = function (string $f) {
        $v = @filemtime(__DIR__ . '/../assets/js/dashboard-builder/' . $f . '.js') ?: '1';
        echo '<script src="' . SITE_URL . '/assets/js/dashboard-builder/' . $f . '.js?v=' . $v . '"></script>' . "\n";
    };
?>
<script>
window.DASHBOARD_THEME = {
    mode: <?php echo json_encode($dashboardTheme['mode']); ?>,
    vars: <?php echo json_encode($dashboardTheme['vars']); ?>,
    apiBase: '<?php echo SITE_URL; ?>/api/dashboard'
};
</script>
<?php
    // Version Joueurs : disponible pour tous les rôles de prévisualisation.
    $__dbAsset('faction-preview');

    // Builder complet : Dev/Admin uniquement.
    if ($__canBuild) {
        foreach (['color-picker', 'theme-manager', 'custom-select', 'icon-picker', 'drag-drop', 'builder-sidebar', 'dashboard-builder'] as $__j) {
            $__dbAsset($__j);
        }
    }
?>
<script>
(function () {
    var pv = document.getElementById('dbPreviewBtn');
    if (pv && window.DBFactionPreview) {
        pv.addEventListener('click', function () { window.DBFactionPreview.open(); });
    }
})();
</script>
<?php endif; ?>

</body>
</html>