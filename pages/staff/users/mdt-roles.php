<?php
// pages/staff/users/mdt-roles.php — Gestion des rôles MDT & synchronisation Discord
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/mdt_discord_sync.php';
require_once ROOT_PATH . '/includes/characters.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

$db = Database::getInstance()->getConnection();
mdt_discord_sync_ensure_tables($db);

$pageTitle  = 'Rôles MDT & Discord';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => 'index.php'], ['label' => 'Rôles MDT & Discord']];
$success = $error = '';
$uid = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['mdt_roles_csrf'])) $_SESSION['mdt_roles_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['mdt_roles_csrf'];

// ── AJAX : liste des utilisateurs Discord à resynchroniser (GET) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_discord_users') {
    header('Content-Type: application/json');
    try {
        $rows = $db->query("SELECT id, username, discord_id, role FROM users WHERE discord_id IS NOT NULL AND discord_id != '' AND is_active = 1 ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['users' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['users' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX : resync d'UN seul utilisateur (POST) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync_single') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $targetId  = (int)($_POST['user_id'] ?? 0);
    $discordId = trim($_POST['discord_id'] ?? '');
    if ($targetId > 0 && $discordId !== '') {
        $result = mdt_discord_sync_user($db, $targetId, $discordId, 'resync');
    } else {
        $result = ['success' => false, 'changed' => false, 'message' => 'Paramètres manquants'];
    }
    echo json_encode($result);
    exit;
}

// ── AJAX : permissions d'un rôle (GET) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_role_permissions') {
    header('Content-Type: application/json');
    $roleName   = trim($_GET['role'] ?? '');
    $panelPerms = $staffPerms = [];

    if ($roleName !== '') {
        try {
            $ps = $db->prepare("SELECT panel_key, can_access FROM mdt_role_panel_access WHERE role_name = ?");
            $ps->execute([$roleName]);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) $panelPerms[$r['panel_key']] = (bool)(int)$r['can_access'];
        } catch (Throwable $e) {}
        try {
            $ss = $db->prepare("SELECT permission_key, enabled FROM mdt_role_staff_permissions WHERE role_name = ?");
            $ss->execute([$roleName]);
            foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $r) $staffPerms[$r['permission_key']] = (bool)(int)$r['enabled'];
        } catch (Throwable $e) {}
    }

    // Catalogue des panels
    require_once ROOT_PATH . '/config/mdt_panel_catalog.php';
    $cats = mdt_panel_catalog_categories($db, true); // includeHidden : ne pas perdre de panels en gestion des droits

    echo json_encode([
        'panel_permissions' => $panelPerms,
        'staff_permissions' => $staffPerms,
        'panel_catalog'     => $cats,
        'staff_catalog'     => mdt_characters_merge_staff_catalog(mdt_staff_permissions_catalog()),
    ]);
    exit;
}

// ── AJAX : resync Discord d'un utilisateur (POST) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync_user') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $targetId  = (int)($_POST['user_id'] ?? 0);
    $discordId = trim($_POST['discord_id'] ?? '');
    if ($targetId > 0 && $discordId !== '') {
        $result = mdt_discord_sync_user($db, $targetId, $discordId, 'manual');
    } else {
        $result = ['success' => false, 'message' => 'Paramètres manquants'];
    }
    echo json_encode($result);
    exit;
}

// ── POST HANDLERS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Créer / modifier un rôle
    if ($action === 'create_role' || $action === 'update_role') {
        $name         = preg_replace('/[^A-Za-zÀ-ÿ0-9 _\-éèêëàâùûüôîïçæœ*]/u', '', trim($_POST['name'] ?? ''));
        $displayName  = trim($_POST['display_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#64748b';
        $icon         = preg_replace('/[^a-z0-9\-]/', '', $_POST['icon'] ?? 'shield');
        $hierarchy    = max(0, min(99, (int)($_POST['hierarchy_level'] ?? 0)));
        $discordRoleId= preg_replace('/[^0-9]/', '', trim($_POST['discord_role_id'] ?? ''));
        $isDefault      = !empty($_POST['is_default']) ? 1 : 0;
        $isStaff        = !empty($_POST['is_staff_panel']) ? 1 : 0;
        $discordSync    = !empty($_POST['discord_sync_enabled']) ? 1 : 0;
        $viewAllPanels  = !empty($_POST['can_view_all_panels']) ? 1 : 0;

        if ($name === '' || $displayName === '') {
            $error = 'Nom et nom affiché requis.';
        } else {
            try {
                if ($action === 'create_role') {
                    $db->prepare("INSERT INTO mdt_roles (name, display_name, description, color, icon, hierarchy_level, discord_role_id, is_default, is_staff_panel, can_view_all_panels, discord_sync_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$name, $displayName, $description, $color, $icon, $hierarchy, $discordRoleId ?: null, $isDefault, $isStaff, $viewAllPanels, $discordSync]);
                    $success = "Rôle \"$displayName\" créé.";
                    mdt_audit_log($uid, 'STAFF', 'Création rôle MDT', "Rôle: $name", 'role', $name);
                } else {
                    $oldName = trim($_POST['old_name'] ?? '');
                    $db->prepare("UPDATE mdt_roles SET name=?, display_name=?, description=?, color=?, icon=?, hierarchy_level=?, discord_role_id=?, is_default=?, is_staff_panel=?, can_view_all_panels=?, discord_sync_enabled=? WHERE name=?")
                       ->execute([$name, $displayName, $description, $color, $icon, $hierarchy, $discordRoleId ?: null, $isDefault, $isStaff, $viewAllPanels, $discordSync, $oldName]);
                    // Mettre à jour les références si le nom a changé
                    if ($name !== $oldName) {
                        $db->prepare("UPDATE users SET role = ? WHERE role = ?")->execute([$name, $oldName]);
                        $db->prepare("UPDATE mdt_role_panel_access SET role_name = ? WHERE role_name = ?")->execute([$name, $oldName]);
                        $db->prepare("UPDATE mdt_role_staff_permissions SET role_name = ? WHERE role_name = ?")->execute([$name, $oldName]);
                    }
                    $success = "Rôle \"$displayName\" mis à jour.";
                    mdt_audit_log($uid, 'STAFF', 'Modification rôle MDT', "Rôle: $name", 'role', $name);
                }
            } catch (Throwable $e) {
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    // Supprimer un rôle
    if ($action === 'delete_role') {
        $name = trim($_POST['role_name'] ?? '');
        if (in_array($name, ['*', 'Administrateur', 'Citoyen'], true)) {
            $error = 'Ce rôle système ne peut pas être supprimé.';
        } elseif ($name !== '') {
            try {
                $db->prepare("DELETE FROM mdt_roles WHERE name = ?")->execute([$name]);
                $db->prepare("DELETE FROM mdt_role_panel_access WHERE role_name = ?")->execute([$name]);
                $db->prepare("DELETE FROM mdt_role_staff_permissions WHERE role_name = ?")->execute([$name]);
                $db->prepare("UPDATE users SET role = 'Citoyen' WHERE role = ?")->execute([$name]);
                $success = "Rôle \"$name\" supprimé.";
                mdt_audit_log($uid, 'STAFF', 'Suppression rôle MDT', "Rôle: $name", 'role', $name);
            } catch (Throwable $e) {
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    // Sauvegarder les permissions d'un rôle
    if ($action === 'save_permissions') {
        $roleName   = trim($_POST['role_name'] ?? '');
        $panelPerms = (array)($_POST['panel_perms'] ?? []);
        $staffPerms = (array)($_POST['staff_perms'] ?? []);

        if ($roleName !== '') {
            try {
                $db->beginTransaction();
                // Panels
                $db->prepare("DELETE FROM mdt_role_panel_access WHERE role_name = ?")->execute([$roleName]);
                $insPan = $db->prepare("INSERT INTO mdt_role_panel_access (role_name, panel_key, can_access) VALUES (?,?,?)");
                foreach ($panelPerms as $panelKey => $val) {
                    $panelKey = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$panelKey));
                    if ($panelKey !== '') $insPan->execute([$roleName, $panelKey, $val ? 1 : 0]);
                }
                // Staff
                $db->prepare("DELETE FROM mdt_role_staff_permissions WHERE role_name = ?")->execute([$roleName]);
                $insSt = $db->prepare("INSERT INTO mdt_role_staff_permissions (role_name, permission_key, enabled) VALUES (?,?,?)");
                foreach ($staffPerms as $permKey => $val) {
                    $permKey = preg_replace('/[^a-z0-9._]/', '', strtolower((string)$permKey));
                    if ($permKey !== '') $insSt->execute([$roleName, $permKey, $val ? 1 : 0]);
                }
                $db->commit();
                $success = "Permissions du rôle \"$roleName\" enregistrées.";
                mdt_audit_log($uid, 'STAFF', 'Permissions rôle MDT', "Rôle: $roleName", 'role', $roleName);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    // Sauvegarder la configuration Discord
    if ($action === 'save_discord_config') {
        $guildId   = preg_replace('/[^0-9]/', '', trim($_POST['discord_guild_id'] ?? ''));
        $botToken  = trim($_POST['discord_bot_token'] ?? '');
        try {
            $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('discord_guild_id',?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$guildId, $guildId]);
            if ($botToken !== '') {
                $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('discord_bot_token',?) ON DUPLICATE KEY UPDATE setting_value=?")
                   ->execute([$botToken, $botToken]);
            }
            $success = 'Configuration Discord sauvegardée.';
        } catch (Throwable $e) {
            $error = 'Erreur : ' . $e->getMessage();
        }
    }

    // (resync_all supprimé — géré par AJAX progressif pour éviter le timeout)
}

// ── DATA ─────────────────────────────────────────────────────────────────────
$roles = [];
try {
    $roles = $db->query("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role COLLATE utf8mb4_unicode_ci = r.name COLLATE utf8mb4_unicode_ci) AS user_count FROM mdt_roles r ORDER BY r.hierarchy_level DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Erreur chargement rôles (migration SQL à exécuter ?) : ' . $e->getMessage();
}

$discordGuildId = '';
$discordBotToken = '';
try {
    $gs = $db->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ('discord_guild_id','discord_bot_token')");
    $gs->execute();
    foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'discord_guild_id')  $discordGuildId  = $row['setting_value'];
        if ($row['setting_key'] === 'discord_bot_token') $discordBotToken = $row['setting_value'];
    }
} catch (Throwable $e) {}

$recentSyncs = [];
try {
    $recentSyncs = $db->query("
        SELECT l.*, u.username FROM mdt_discord_sync_log l
        LEFT JOIN users u ON u.id = l.user_id
        ORDER BY l.synced_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

include __DIR__ . '/../includes/header.php';
?>

<style>
.role-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-top:20px}
.role-card{background:var(--bg-elevated);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:.2s}
.role-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:14px 0 0 14px}
.role-card:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,.3)}
.role-header{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.role-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.role-name{font-size:1.05rem;font-weight:800;color:var(--text)}
.role-meta{font-size:.72rem;color:var(--text-secondary);margin-top:2px}
.role-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.badge-discord{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:5px;font-size:.7rem;font-weight:700;background:rgba(88,101,242,.15);border:1px solid rgba(88,101,242,.3);color:#7289da}
.badge-staff{background:rgba(168,85,247,.12);border:1px solid rgba(168,85,247,.25);color:#a855f7}
.badge-default{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#22c55e}
.badge-level{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#60a5fa}
.role-actions{display:flex;gap:6px;padding-top:12px;border-top:1px solid var(--border)}
.role-user-count{font-size:.8rem;color:var(--text-secondary);margin-bottom:12px}
.discord-card{background:linear-gradient(135deg,rgba(88,101,242,.12),rgba(88,101,242,.04));border:1px solid rgba(88,101,242,.3);border-radius:14px;padding:24px;margin-top:24px}
.discord-logo{color:#7289da;font-size:1.4rem}
.sync-log-table{font-size:.78rem}
.perm-modal{background:var(--bg-elevated);border:1px solid var(--border);border-radius:18px;width:100%;max-width:860px;padding:24px;max-height:90vh;overflow-y:auto}
.perm-section-title{font-weight:700;font-size:.82rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 8px;display:flex;align-items:center;gap:8px}
.perm-section-title i{opacity:.6}
.panel-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px}
.perm-chip{display:flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:.78rem;transition:.15s;user-select:none}
.perm-chip:hover{border-color:rgba(59,130,246,.4);background:rgba(59,130,246,.05)}
.perm-chip input{accent-color:#3b82f6;width:14px;height:14px}
.perm-chip.staff-chip input{accent-color:#a855f7}
.cat-header{background:rgba(255,255,255,.03);padding:6px 10px;border-radius:6px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-top:10px;margin-bottom:6px}
</style>

<!-- Page header -->
<div class="page-header">
    <div class="page-header-left">
        <h1>
            <i class="fa-brands fa-discord" style="color:#7289da"></i>
            Rôles MDT & Discord
        </h1>
        <p><?php echo count($roles); ?> rôles — attribution automatique via Discord OAuth</p>
    </div>
    <div style="display:flex;gap:8px">
        <button class="btn btn-secondary" onclick="openDiscordConfig()">
            <i class="fa-brands fa-discord"></i> Config Discord
        </button>
        <button class="btn btn-primary" onclick="openRoleModal()">
            <i class="fa-solid fa-plus"></i> Nouveau rôle
        </button>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Rôles -->
<div class="role-grid">
<?php foreach ($roles as $r): ?>
<div class="role-card" style="--rc:<?php echo $r['color']; ?>">
    <div style="position:absolute;left:0;top:0;bottom:0;width:4px;background:<?php echo $r['color']; ?>;border-radius:14px 0 0 14px"></div>
    <div class="role-header">
        <div class="role-icon" style="background:<?php echo $r['color']; ?>22;color:<?php echo $r['color']; ?>">
            <i class="fa-solid fa-<?php echo htmlspecialchars($r['icon']); ?>"></i>
        </div>
        <div>
            <div class="role-name"><?php echo htmlspecialchars($r['display_name']); ?></div>
            <div class="role-meta">Clé : <code style="font-size:.68rem"><?php echo htmlspecialchars($r['name']); ?></code></div>
        </div>
    </div>
    <div class="role-badges">
        <span class="badge-level"><i class="fa-solid fa-ranking-star" style="font-size:.6rem"></i> Niveau <?php echo (int)$r['hierarchy_level']; ?></span>
        <?php if ($r['is_staff_panel']): ?><span class="badge-staff"><i class="fa-solid fa-hard-drive" style="font-size:.6rem"></i> Staff Panel</span><?php endif; ?>
        <?php if ($r['is_default']): ?><span class="badge-default"><i class="fa-solid fa-check" style="font-size:.6rem"></i> Défaut</span><?php endif; ?>
        <?php if (!empty($r['can_view_all_panels'])): ?><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:.68rem;font-weight:700;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.3);color:#8b5cf6"><i class="fa-solid fa-eye" style="font-size:.6rem"></i> Mode démo</span><?php endif; ?>
        <?php if ($r['discord_role_id']): ?><span class="badge-discord"><i class="fa-brands fa-discord" style="font-size:.65rem"></i> <?php echo htmlspecialchars($r['discord_role_id']); ?></span><?php endif; ?>
    </div>
    <?php if ($r['description']): ?>
    <div style="font-size:.78rem;color:var(--text-secondary);margin-bottom:10px;line-height:1.5"><?php echo htmlspecialchars($r['description']); ?></div>
    <?php endif; ?>
    <div class="role-user-count"><i class="fa-solid fa-users" style="margin-right:4px;opacity:.5"></i><?php echo (int)$r['user_count']; ?> utilisateur<?php echo $r['user_count'] != 1 ? 's' : ''; ?></div>
    <div class="role-actions">
        <button class="btn btn-secondary btn-sm" onclick='openPermModal(<?php echo json_encode($r['name'], JSON_HEX_APOS|JSON_HEX_TAG); ?>)'>
            <i class="fa-solid fa-key"></i> Permissions
        </button>
        <button class="btn btn-secondary btn-sm" onclick='openRoleModal(<?php echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_TAG); ?>)'>
            <i class="fa-solid fa-pen"></i>
        </button>
        <?php if (!in_array($r['name'], ['*', 'Administrateur', 'Citoyen'], true)): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer le rôle <?php echo addslashes($r['display_name']); ?> ?')">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="role_name" value="<?php echo htmlspecialchars($r['name']); ?>">
            <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Section Discord -->
<div class="discord-card" style="margin-top:28px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(88,101,242,.2);border:1px solid rgba(88,101,242,.4);display:flex;align-items:center;justify-content:center">
            <i class="fa-brands fa-discord discord-logo"></i>
        </div>
        <div>
            <div style="font-weight:800;font-size:1rem">Synchronisation Discord</div>
            <div style="font-size:.78rem;color:var(--text-secondary)">Attribution automatique des rôles MDT via les rôles Discord</div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <!-- Config Guild -->
        <div class="card" style="padding:16px">
            <div style="font-weight:700;font-size:.85rem;margin-bottom:12px"><i class="fa-brands fa-discord" style="color:#7289da;margin-right:6px"></i>Configuration Guilde</div>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="save_discord_config">
                <div class="form-group">
                    <label class="form-label">Guild ID Discord</label>
                    <input type="text" name="discord_guild_id" class="form-control" value="<?php echo htmlspecialchars($discordGuildId); ?>" placeholder="Ex: 123456789012345678">
                    <div class="form-hint">ID du serveur Discord (clic droit → Copier l'identifiant)</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Bot Token Discord <span style="color:#ef4444">*</span></label>
                    <input type="password" name="discord_bot_token" class="form-control" value="<?php echo htmlspecialchars($discordBotToken); ?>" placeholder="MTxxxxxxxxxxxxxxxx.Gxxxxxx.xxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="new-password">
                    <div class="form-hint">
                        <a href="https://discord.com/developers/applications" target="_blank" style="color:#7289da">Discord Developer Portal</a>
                        → ton application → Bot → <strong>Reset Token</strong>. Nécessaire pour la sync des rôles.
                        <?php if ($discordBotToken !== ''): ?><span style="color:#22c55e">✓ Configuré</span><?php endif; ?>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-save"></i> Sauvegarder</button>
            </form>
        </div>

        <!-- Resync globale (AJAX progressif) -->
        <div class="card" style="padding:16px">
            <div style="font-weight:700;font-size:.85rem;margin-bottom:12px"><i class="fa-solid fa-rotate" style="color:#22c55e;margin-right:6px"></i>Resynchronisation</div>
            <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:16px;line-height:1.5">
                Resynchronise les rôles MDT de tous les membres ayant un compte Discord lié.<br>
                <span style="font-size:.72rem;opacity:.7">Traitement asynchrone — pas de timeout.</span>
            </p>
            <button class="btn btn-primary btn-sm" style="background:rgba(34,197,94,.2);border-color:rgba(34,197,94,.4);color:#22c55e" onclick="startGlobalResync()">
                <i class="fa-solid fa-rotate"></i> Resync tous les membres
            </button>
        </div>
    </div>

    <!-- Log syncs récentes -->
    <?php if (!empty($recentSyncs)): ?>
    <div style="margin-top:16px">
        <div style="font-weight:700;font-size:.8rem;color:var(--text-secondary);margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em">Dernières synchronisations</div>
        <div style="max-height:220px;overflow-y:auto;border-radius:8px;border:1px solid var(--border)">
            <table style="width:100%;font-size:.76rem;border-collapse:collapse">
                <thead><tr style="background:rgba(255,255,255,.03)">
                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600;text-align:left">Utilisateur</th>
                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600">Avant</th>
                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600">Après</th>
                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600">Source</th>
                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600">Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentSyncs as $s): ?>
                <tr style="border-top:1px solid rgba(255,255,255,.04)">
                    <td style="padding:5px 10px"><?php echo htmlspecialchars($s['username'] ?? $s['discord_id'] ?? '—'); ?></td>
                    <td style="padding:5px 10px;text-align:center"><span style="color:var(--text-muted)"><?php echo htmlspecialchars($s['old_role'] ?? '—'); ?></span></td>
                    <td style="padding:5px 10px;text-align:center"><strong style="color:#22c55e"><?php echo htmlspecialchars($s['new_role'] ?? '—'); ?></strong></td>
                    <td style="padding:5px 10px;text-align:center"><span style="font-size:.65rem;background:rgba(59,130,246,.1);color:#60a5fa;padding:2px 6px;border-radius:4px"><?php echo htmlspecialchars($s['source']); ?></span></td>
                    <td style="padding:5px 10px;text-align:center;color:var(--text-muted)"><?php echo date('d/m H:i', strtotime($s['synced_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ MODAL RÔLE ═══ -->
<div class="modal-overlay" id="roleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;place-items:center;padding:20px">
<div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:18px;width:100%;max-width:580px;padding:24px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 id="roleModalTitle" style="font-size:1rem;font-weight:800">Nouveau rôle</h3>
        <button class="btn btn-secondary btn-icon" onclick="closeRoleModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="roleForm">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" id="roleAction" value="create_role">
        <input type="hidden" name="old_name" id="roleOldName" value="">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
                <label class="form-label">Clé interne *</label>
                <input type="text" name="name" id="roleName" class="form-control" required placeholder="Staff">
                <div class="form-hint">Sera stocké dans users.role</div>
            </div>
            <div class="form-group">
                <label class="form-label">Nom affiché *</label>
                <input type="text" name="display_name" id="roleDisplayName" class="form-control" required placeholder="Staff MDT">
            </div>
            <div class="form-group">
                <label class="form-label">Couleur</label>
                <input type="color" name="color" id="roleColor" class="form-control" value="#64748b" style="height:42px;padding:4px">
            </div>
            <div class="form-group">
                <label class="form-label">Icône FontAwesome</label>
                <input type="text" name="icon" id="roleIcon" class="form-control" value="shield" placeholder="shield">
            </div>
            <div class="form-group">
                <label class="form-label">Niveau hiérarchie</label>
                <input type="number" name="hierarchy_level" id="roleHierarchy" class="form-control" value="1" min="0" max="99">
                <div class="form-hint">Plus élevé = plus de pouvoir</div>
            </div>
            <div class="form-group">
                <label class="form-label">Discord Role ID</label>
                <input type="text" name="discord_role_id" id="roleDiscordId" class="form-control" placeholder="123456789012345678">
                <div class="form-hint">ID du rôle Discord lié</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" id="roleDescription" class="form-control" rows="2"></textarea>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px">
            <label style="display:flex;gap:8px;align-items:center;font-size:.82rem;cursor:pointer">
                <input type="checkbox" name="is_staff_panel" id="roleIsStaff"> Accès panel staff
            </label>
            <label style="display:flex;gap:8px;align-items:center;font-size:.82rem;cursor:pointer">
                <input type="checkbox" name="is_default" id="roleIsDefault"> Rôle par défaut
            </label>
            <label style="display:flex;gap:8px;align-items:center;font-size:.82rem;cursor:pointer">
                <input type="checkbox" name="discord_sync_enabled" id="roleDiscordSync" checked> Sync Discord
            </label>
            <label style="display:flex;gap:8px;align-items:center;font-size:.82rem;cursor:pointer;border:1px solid rgba(139,92,246,.35);padding:4px 10px;border-radius:6px;background:rgba(139,92,246,.06)" title="Voit tous les panels actifs du dashboard, sans accès à l'édition (mode démo)">
                <input type="checkbox" name="can_view_all_panels" id="roleViewAll">
                <i class="fa-solid fa-eye" style="color:#8b5cf6;font-size:.75rem"></i>
                Voir tous les panels (mode démo)
            </label>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
            <button type="button" class="btn btn-secondary" onclick="closeRoleModal()">Annuler</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ MODAL PERMISSIONS ═══ -->
<div class="modal-overlay" id="permModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;place-items:center;padding:20px">
<div class="perm-modal">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 id="permModalTitle" style="font-size:1rem;font-weight:800"><i class="fa-solid fa-key" style="color:#a855f7;margin-right:8px"></i>Permissions</h3>
        <button class="btn btn-secondary btn-icon" onclick="closePermModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="permForm">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="save_permissions">
        <input type="hidden" name="role_name" id="permRoleName">

        <div id="permLoading" style="text-align:center;padding:40px;color:var(--text-muted)">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Chargement…
        </div>
        <div id="permContent" style="display:none">
            <!-- Section Panels -->
            <div class="perm-section-title"><i class="fa-solid fa-grid-2"></i> Accès aux Panels (Dashboard)</div>
            <div class="form-hint" style="margin-bottom:10px">Cochez les panels auxquels ce rôle peut accéder depuis la page d'accueil.</div>
            <div id="permPanels"></div>

            <!-- Section Staff -->
            <div class="perm-section-title" style="margin-top:20px"><i class="fa-solid fa-hard-drive"></i> Permissions Panel Staff</div>
            <div class="form-hint" style="margin-bottom:10px">Définissez les fonctionnalités du panel staff accessibles à ce rôle.</div>
            <div id="permStaff"></div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closePermModal()">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer</button>
            </div>
        </div>
    </form>
</div>
</div>

<script>
// ── Modal Rôle ───────────────────────────────────────────────────────────────
function openRoleModal(r) {
    const title = document.getElementById('roleModalTitle');
    const act   = document.getElementById('roleAction');
    const old   = document.getElementById('roleOldName');
    title.textContent = r ? 'Modifier le rôle' : 'Nouveau rôle';
    act.value   = r ? 'update_role' : 'create_role';
    old.value   = r ? (r.name || '') : '';
    document.getElementById('roleName').value        = r ? r.name        : '';
    document.getElementById('roleDisplayName').value = r ? r.display_name: '';
    document.getElementById('roleColor').value       = r ? r.color       : '#64748b';
    document.getElementById('roleIcon').value        = r ? r.icon        : 'shield';
    document.getElementById('roleHierarchy').value   = r ? r.hierarchy_level : 1;
    document.getElementById('roleDiscordId').value   = r ? (r.discord_role_id || '') : '';
    document.getElementById('roleDescription').value = r ? (r.description || '') : '';
    document.getElementById('roleIsStaff').checked    = r ? !!+r.is_staff_panel       : false;
    document.getElementById('roleIsDefault').checked  = r ? !!+r.is_default            : false;
    document.getElementById('roleDiscordSync').checked = r ? !!+r.discord_sync_enabled : true;
    document.getElementById('roleViewAll').checked     = r ? !!+r.can_view_all_panels   : false;
    document.getElementById('roleModal').style.display = 'grid';
}
function closeRoleModal() { document.getElementById('roleModal').style.display = 'none'; }

// ── Modal Permissions ────────────────────────────────────────────────────────
function openPermModal(roleName) {
    document.getElementById('permRoleName').value = roleName;
    document.getElementById('permModalTitle').innerHTML = '<i class="fa-solid fa-key" style="color:#a855f7;margin-right:8px"></i>Permissions — ' + roleName;
    document.getElementById('permLoading').style.display = '';
    document.getElementById('permContent').style.display = 'none';
    document.getElementById('permModal').style.display = 'grid';

    fetch(window.location.pathname + '?action=get_role_permissions&role=' + encodeURIComponent(roleName))
        .then(r => r.json())
        .then(data => {
            renderPanels(data.panel_catalog, data.panel_permissions);
            renderStaff(data.staff_catalog, data.staff_permissions);
            document.getElementById('permLoading').style.display = 'none';
            document.getElementById('permContent').style.display = '';
        });
}
function closePermModal() { document.getElementById('permModal').style.display = 'none'; }

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderPanels(catalog, perms) {
    let html = '';
    for (const [catName, panels] of Object.entries(catalog)) {
        if (!panels || !panels.length) continue;
        html += `<div class="cat-header">${esc(catName)}</div><div class="panel-grid">`;
        for (const p of panels) {
            if (!p.key) continue;
            const checked = perms[p.key] !== false; // permissif par défaut
            html += `<label class="perm-chip">
                <input type="checkbox" name="panel_perms[${esc(p.key)}]" value="1" ${checked ? 'checked' : ''}>
                <span>${esc(p.name)}</span>
            </label>`;
        }
        html += '</div>';
    }
    document.getElementById('permPanels').innerHTML = html || '<p style="color:var(--text-muted);font-size:.8rem">Aucun panel trouvé.</p>';
}

function renderStaff(catalog, perms) {
    let html = '';
    for (const [, section] of Object.entries(catalog)) {
        html += `<div class="cat-header"><i class="fa-solid fa-${esc(section.icon)}" style="margin-right:5px;opacity:.6"></i>${esc(section.group)}</div><div class="panel-grid">`;
        for (const [key, label] of Object.entries(section.perms)) {
            const checked = perms[key] === true;
            html += `<label class="perm-chip staff-chip">
                <input type="checkbox" name="staff_perms[${esc(key)}]" value="1" style="accent-color:#a855f7" ${checked ? 'checked' : ''}>
                <span>${esc(label)}</span>
            </label>`;
        }
        html += '</div>';
    }
    document.getElementById('permStaff').innerHTML = html;
}

// ── Config Discord ───────────────────────────────────────────────────────────
function openDiscordConfig() {
    document.getElementById('discordConfig')?.scrollIntoView({ behavior: 'smooth' });
}

// ── Resync globale (AJAX un par un pour éviter le timeout) ───────────────────
async function startGlobalResync() {
    const modal   = document.getElementById('resyncModal');
    const bar     = document.getElementById('rsBar');
    const status  = document.getElementById('rsStatus');
    const log     = document.getElementById('rsLog');
    const btnClose= document.getElementById('rsBtnClose');

    // Reset UI
    bar.style.width = '0%';
    bar.textContent = '';
    log.innerHTML   = '';
    status.textContent = 'Chargement des utilisateurs…';
    btnClose.disabled  = true;
    modal.style.display = 'grid';

    // 1. Récupérer la liste des utilisateurs
    let users = [];
    try {
        const r = await fetch(window.location.pathname + '?action=get_discord_users');
        const d = await r.json();
        users = d.users || [];
    } catch(e) {
        status.textContent = 'Erreur : impossible de charger les utilisateurs.';
        btnClose.disabled = false;
        return;
    }

    if (users.length === 0) {
        status.textContent = 'Aucun utilisateur avec un compte Discord lié.';
        btnClose.disabled = false;
        return;
    }

    status.textContent = `0 / ${users.length} synchronisés…`;
    let synced = 0, changed = 0, errors = 0;

    // 2. Traiter un par un
    for (let i = 0; i < users.length; i++) {
        const u = users[i];
        const pct = Math.round(((i + 1) / users.length) * 100);

        try {
            const fd = new FormData();
            fd.append('csrf',       '<?php echo $csrf; ?>');
            fd.append('action',     'resync_single');
            fd.append('user_id',    u.id);
            fd.append('discord_id', u.discord_id);

            const r   = await fetch(window.location.pathname, { method: 'POST', body: fd });
            const res = await r.json();
            synced++;
            if (res.changed) {
                changed++;
                log.innerHTML += `<div style="color:#22c55e;font-size:.73rem;margin-bottom:2px">✓ <strong>${esc(u.username)}</strong> : ${esc(res.old_role)} → <strong>${esc(res.new_role)}</strong></div>`;
            }
            if (!res.success && res.message) {
                errors++;
                log.innerHTML += `<div style="color:#f59e0b;font-size:.73rem;margin-bottom:2px">⚠ <strong>${esc(u.username)}</strong> : ${esc(res.message)}</div>`;
            }
        } catch(e) {
            errors++;
            log.innerHTML += `<div style="color:#ef4444;font-size:.73rem;margin-bottom:2px">✗ <strong>${esc(u.username)}</strong> : erreur réseau</div>`;
        }

        bar.style.width   = pct + '%';
        bar.textContent   = pct + '%';
        status.textContent= `${i + 1} / ${users.length} synchronisés…`;
        log.scrollTop     = log.scrollHeight;
    }

    status.innerHTML = `<strong style="color:#22c55e">Terminé</strong> — ${synced} synchronisés, <span style="color:#22c55e">${changed} mis à jour</span>${errors > 0 ? `, <span style="color:#f59e0b">${errors} erreurs</span>` : ''}.`;
    btnClose.disabled = false;
    // Recharger la page après fermeture pour rafraîchir les logs
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Fermeture modals au clic extérieur / Escape
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
});
</script>

<!-- ═══ MODAL RESYNC PROGRESSIF ═══ -->
<div id="resyncModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:300;place-items:center;padding:20px">
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;padding:24px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
            <i class="fa-brands fa-discord" style="color:#7289da;font-size:1.2rem"></i>
            <h3 style="font-size:.95rem;font-weight:800;margin:0">Resynchronisation Discord</h3>
        </div>

        <!-- Barre de progression -->
        <div style="background:rgba(255,255,255,.06);border-radius:8px;height:28px;overflow:hidden;margin-bottom:12px;position:relative">
            <div id="rsBar" style="height:100%;background:linear-gradient(90deg,#7289da,#5865f2);border-radius:8px;width:0%;transition:width .3s;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:white;min-width:32px"></div>
        </div>

        <div id="rsStatus" style="font-size:.82rem;color:var(--text-secondary);margin-bottom:10px">Initialisation…</div>

        <!-- Log défilant -->
        <div id="rsLog" style="max-height:200px;overflow-y:auto;background:rgba(0,0,0,.25);border-radius:8px;padding:10px;font-family:monospace;font-size:.72rem;line-height:1.6;border:1px solid var(--border)">
            <span style="color:var(--text-muted)">Attente…</span>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:16px">
            <button id="rsBtnClose" class="btn btn-secondary" disabled onclick="document.getElementById('resyncModal').style.display='none';location.reload()">
                <i class="fa-solid fa-check"></i> Fermer
            </button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
