<?php
// pages/staff/content/mail-manager.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Addon "Gestionnaire Mail" — doit être activé depuis Extensions > Addons (avec licence)
$mailAddonActive = false;
try {
    $mailAddonActive = (bool) $db->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'mail_enabled' LIMIT 1")->fetchColumn();
} catch (Throwable $e) {
    $mailAddonActive = false;
}
if (!$mailAddonActive) {
    // Seuls les admins/dev peuvent être renvoyés sur la page addons pour activer ;
    // les autres staff retombent sur le dashboard.
    if (hasRole(['*', 'Administrateur', 'Développeur'])) {
        redirect(SITE_URL . '/pages/staff/extensions/addons.php');
    }
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}
$pageTitle  = 'Gestionnaire Mail';
$breadcrumb = [['label'=>'Dashboard','url'=> SITE_URL . '/pages/staff/dashboard.php'],['label'=>'Gestionnaire Mail']];

$serverName = $db->query("SELECT setting_value FROM admin_settings WHERE setting_key='mail_server_name'")->fetchColumn() ?: 'lysoria';

// ── Action POST ────────────────────────────────────────────────
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'create_faction_mail') {
        $factionKey = strtoupper(trim($_POST['faction_key'] ?? ''));
        $prefix     = strtolower(trim($_POST['prefix'] ?? ''));
        $domain     = strtolower(trim($_POST['domain'] ?? $factionKey));
        $userId     = (int)($_POST['user_id'] ?? 0);
        $rawPwd     = trim($_POST['mail_password'] ?? '');
        $address = "{$prefix}@{$domain}.us";
        try {
            $hashedPwd = $rawPwd ? password_hash($rawPwd, PASSWORD_DEFAULT) : null;
            $db->prepare("INSERT INTO mail_accounts (user_id, address, display_name, domain, tld, type, faction_key, mail_password) VALUES (?,?,?,?,'us','faction',?,?)")
               ->execute([$userId, $address, "{$prefix} — {$factionKey}", "{$domain}.us", $factionKey, $hashedPwd]);
            $accId = $db->lastInsertId();
            if ($userId) {
                $db->prepare("INSERT IGNORE INTO mail_account_links (user_id,account_id) VALUES (?,?)")->execute([$userId, $accId]);
            }
            $db->prepare("UPDATE mail_faction_templates SET created=1, account_id=? WHERE faction_key=? AND prefix=?")->execute([$accId, $factionKey, $prefix]);
            $msg = "Adresse $address créée avec succès.";
        } catch (PDOException $e) {
            $err = "Erreur : " . ($e->getCode() === '23000' ? "Adresse déjà existante." : $e->getMessage());
        }
    }

    if ($act === 'update_faction_password') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $rawPwd    = trim($_POST['mail_password'] ?? '');
        if (!$accountId) {
            $err = 'ID manquant.';
        } elseif (strlen($rawPwd) < 4) {
            $err = 'Le mot de passe doit faire au moins 4 caractères.';
        } else {
            $hashed = password_hash($rawPwd, PASSWORD_DEFAULT);
            $db->prepare("UPDATE mail_accounts SET mail_password=? WHERE id=?")->execute([$hashed, $accountId]);
            $msg = 'Mot de passe mis à jour.';
        }
    }

    if ($act === 'clear_faction_password') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $db->prepare("UPDATE mail_accounts SET mail_password=NULL WHERE id=?")->execute([$accountId]);
        $msg = 'Mot de passe supprimé.';
    }

    if ($act === 'reassign_faction_mail') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $newUserId = (int)($_POST['user_id'] ?? 0);
        $db->prepare("DELETE FROM mail_account_links WHERE account_id=?")->execute([$accountId]);
        $db->prepare("UPDATE mail_accounts SET user_id=? WHERE id=?")->execute([$newUserId ?: 0, $accountId]);
        if ($newUserId) {
            $db->prepare("INSERT IGNORE INTO mail_account_links (user_id,account_id) VALUES (?,?)")->execute([$newUserId, $accountId]);
        }
        $msg = 'Assignation mise à jour.';
    }

    if ($act === 'create_custom_mail') {
        $address  = strtolower(trim($_POST['address'] ?? ''));
        $userId   = (int)($_POST['user_id'] ?? 0);
        $dispName = trim($_POST['display_name'] ?? '');
        $type     = in_array($_POST['type']??'faction',['personal','faction','admin']) ? $_POST['type'] : 'admin';
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');
        $tld = pathinfo($domain, PATHINFO_EXTENSION) ?: 'us';
        try {
            $db->prepare("INSERT INTO mail_accounts (user_id,address,display_name,domain,tld,type) VALUES (?,?,?,?,?,?)")
               ->execute([$userId, $address, $dispName, $domain, $tld, $type]);
            $accId = $db->lastInsertId();
            if ($userId) {
                $db->prepare("INSERT IGNORE INTO mail_account_links (user_id,account_id) VALUES (?,?)")->execute([$userId, $accId]);
            }
            $msg = "Adresse $address créée.";
        } catch (PDOException $e) {
            $err = "Erreur : " . ($e->getCode() === '23000' ? "Adresse déjà existante." : $e->getMessage());
        }
    }

    if ($act === 'toggle_active') {
        $id = (int)($_POST['account_id'] ?? 0);
        $db->prepare("UPDATE mail_accounts SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        $msg = 'Statut modifié.';
    }

    if ($act === 'delete_account') {
        $id = (int)($_POST['account_id'] ?? 0);
        // Supprimer le logo associé si existant
        $logo = $db->query("SELECT logo FROM mail_accounts WHERE id=$id")->fetchColumn();
        if ($logo) {
            $path = dirname(__DIR__, 3) . '/assets/' . $logo;
            if (file_exists($path)) unlink($path);
        }
        $db->prepare("DELETE FROM mail_accounts WHERE id=?")->execute([$id]);
        $msg = 'Compte supprimé.';
    }

    if ($act === 'delete_logo_staff') {
        $id = (int)($_POST['account_id'] ?? 0);
        $logo = $db->query("SELECT logo FROM mail_accounts WHERE id=$id")->fetchColumn();
        if ($logo) {
            $path = dirname(__DIR__, 3) . '/assets/' . $logo;
            if (file_exists($path)) unlink($path);
        }
        $db->prepare("UPDATE mail_accounts SET logo=NULL WHERE id=?")->execute([$id]);
        $msg = 'Logo supprimé.';
    }

    if ($act === 'add_faction_category') {
        $newKey = strtoupper(preg_replace('/[^A-Z0-9_]/', '', trim($_POST['cat_key'] ?? '')));
        if (!$newKey) {
            $err = 'Nom de catégorie requis.';
        } else {
            try {
                $db->prepare("INSERT INTO mail_faction_templates (faction_key, prefix, label, created) VALUES (?,?,?,0)")
                   ->execute([$newKey, 'contact', "Adresse de contact $newKey"]);
                $msg = "Catégorie $newKey créée.";
            } catch (PDOException $e) {
                $err = $e->getCode() === '23000' ? 'Cette catégorie existe déjà.' : $e->getMessage();
            }
        }
    }

    if ($act === 'rename_faction_category') {
        $oldKey = strtoupper(trim($_POST['old_key'] ?? ''));
        $newKey = strtoupper(preg_replace('/[^A-Z0-9_]/', '', trim($_POST['new_key'] ?? '')));
        if (!$oldKey || !$newKey) {
            $err = 'Clé requise.';
        } elseif ($oldKey === $newKey) {
            $msg = 'Aucun changement.';
        } else {
            try {
                $db->prepare("UPDATE mail_faction_templates SET faction_key=? WHERE faction_key=?")->execute([$newKey, $oldKey]);
                $db->prepare("UPDATE mail_accounts SET faction_key=? WHERE faction_key=?")->execute([$newKey, $oldKey]);
                $msg = "Catégorie renommée : $oldKey → $newKey";
            } catch (PDOException $e) {
                $err = $e->getMessage();
            }
        }
    }

    if ($act === 'add_faction_template') {
        $factionKey = strtoupper(trim($_POST['tpl_faction_key'] ?? ''));
        $prefix     = strtolower(preg_replace('/[^a-z0-9._-]/', '', trim($_POST['tpl_prefix'] ?? '')));
        $label      = trim($_POST['tpl_label'] ?? $prefix);
        if (!$factionKey || !$prefix) {
            $err = 'Faction et préfixe requis.';
        } else {
            try {
                $db->prepare("INSERT INTO mail_faction_templates (faction_key, prefix, label, created) VALUES (?,?,?,0)")
                   ->execute([$factionKey, $prefix, $label ?: $prefix]);
                $msg = "Template $prefix@… ajouté pour $factionKey.";
            } catch (PDOException $e) {
                $err = $e->getCode() === '23000' ? 'Ce template existe déjà.' : $e->getMessage();
            }
        }
    }

    if ($act === 'delete_faction_template') {
        $tplId = (int)($_POST['tpl_id'] ?? 0);
        $deleteAccount = (int)($_POST['delete_account'] ?? 0);
        if ($deleteAccount) {
            $accId = $db->prepare("SELECT account_id FROM mail_faction_templates WHERE id=?");
            $accId->execute([$tplId]);
            $linked = $accId->fetchColumn();
            if ($linked) $db->prepare("DELETE FROM mail_accounts WHERE id=?")->execute([$linked]);
        }
        $db->prepare("DELETE FROM mail_faction_templates WHERE id=?")->execute([$tplId]);
        $msg = 'Template supprimé.';
    }

    if ($act === 'update_settings') {
        $settings = [
            'mail_server_name' => trim($_POST['mail_server_name'] ?? 'lysoria'),
            'mail_max_per_user'=> (int)($_POST['mail_max_per_user'] ?? 3),
            'mail_default_tld' => $_POST['mail_default_tld'] ?? 'fr',
            'mail_enabled'     => isset($_POST['mail_enabled']) ? '1' : '0',
        ];
        foreach ($settings as $k=>$v) {
            $check = $db->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_key=?");
            $check->execute([$k]);
            if ($check->fetchColumn()) {
                $db->prepare("UPDATE admin_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
            } else {
                $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,?)")->execute([$k, $v]);
            }
        }
        $serverName = $settings['mail_server_name'];
        $msg = 'Paramètres sauvegardés.';
    }
}

// ── Pagination & recherche comptes ────────────────────────────
$perPage    = 20;
$curPage    = max(1, (int)($_GET['page'] ?? 1));
$search     = trim($_GET['q'] ?? '');
$activeTab  = $_GET['tab'] ?? 'accounts';

$whereClause = $search ? "WHERE a.address LIKE :q1 OR u.username LIKE :q2" : "";
$params      = $search ? [':q1' => "%$search%", ':q2' => "%$search%"] : [];

$totalStmt = $db->prepare("SELECT COUNT(*) FROM mail_accounts a LEFT JOIN users u ON u.id = a.user_id $whereClause");
$totalStmt->execute($params);
$totalAccounts = (int)$totalStmt->fetchColumn();
$totalPages    = max(1, (int)ceil($totalAccounts / $perPage));
$curPage       = min($curPage, $totalPages);
$offset        = ($curPage - 1) * $perPage;

// ── Requête comptes paginée ────────────────────────────────────
try {
    $accStmt = $db->prepare("
        SELECT a.*, u.username,
            (SELECT COUNT(*) FROM mail_boxes mb WHERE mb.account_id=a.id AND mb.folder='inbox') as inbox_count,
            CASE WHEN a.mail_password IS NOT NULL THEN 1 ELSE 0 END as has_password
        FROM mail_accounts a
        LEFT JOIN users u ON u.id = a.user_id
        $whereClause
        ORDER BY a.type, a.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $accStmt->bindValue($k, $v);
    $accStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $accStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $accStmt->execute();
    $accounts = $accStmt->fetchAll();
} catch (Exception $e) {
    $accStmt = $db->prepare("
        SELECT a.*, u.username,
            (SELECT COUNT(*) FROM mail_boxes mb WHERE mb.account_id=a.id AND mb.folder='inbox') as inbox_count,
            0 as has_password
        FROM mail_accounts a
        LEFT JOIN users u ON u.id = a.user_id
        $whereClause
        ORDER BY a.type, a.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $accStmt->bindValue($k, $v);
    $accStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $accStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $accStmt->execute();
    $accounts = $accStmt->fetchAll();
}

// ── Données complémentaires ────────────────────────────────────
// Tous les comptes faction (pas paginé — pour la section templates)
try {
    $allFactionAccounts = $db->query("
        SELECT a.*, u.username,
            CASE WHEN a.mail_password IS NOT NULL THEN 1 ELSE 0 END as has_password
        FROM mail_accounts a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.type IN ('faction','admin')
    ")->fetchAll();
} catch (Exception $e) {
    $allFactionAccounts = $db->query("
        SELECT a.*, u.username, 0 as has_password
        FROM mail_accounts a LEFT JOIN users u ON u.id=a.user_id
        WHERE a.type IN ('faction','admin')
    ")->fetchAll();
}

$templates = $db->query("SELECT * FROM mail_faction_templates ORDER BY faction_key, prefix")->fetchAll();
$factions  = array_unique(array_column($templates, 'faction_key'));
sort($factions);
$users     = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

$settings = [];
$stSet    = $db->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'mail_%'");
foreach ($stSet->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
$mailIsEnabled = ($settings['mail_enabled'] ?? '1') === '1';

$stats = [
    'total_accounts'  => $db->query("SELECT COUNT(*) FROM mail_accounts")->fetchColumn(),
    'total_messages'  => $db->query("SELECT COUNT(*) FROM mail_messages WHERE sent_at IS NOT NULL")->fetchColumn(),
    'total_unread'    => $db->query("SELECT COUNT(*) FROM mail_boxes WHERE is_read=0 AND folder='inbox'")->fetchColumn(),
    'active_accounts' => $db->query("SELECT COUNT(*) FROM mail_accounts WHERE is_active=1")->fetchColumn(),
];

// Map adresses faction pour les templates
$factionAccountMap = [];
foreach ($allFactionAccounts as $acc) {
    $factionAccountMap[$acc['address']] = $acc;
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Variables & reset ── */
.mm-wrap { max-width: 1400px; }

/* ── Page header ── */
.mm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    gap: 16px;
    flex-wrap: wrap;
}
.mm-title {
    margin: 0 0 4px;
    font-size: 1.75rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 12px;
    letter-spacing: -.02em;
}
.mm-subtitle { margin: 0; color: var(--text-secondary); font-size: .9rem; }

/* ── Stats bar ── */
.mm-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 28px;
}
@media (max-width: 900px) { .mm-stats { grid-template-columns: repeat(2,1fr); } }
.mm-stat {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: border-color .2s, box-shadow .2s;
}
.mm-stat:hover { border-color: var(--blue); box-shadow: 0 4px 16px rgba(59,130,246,.1); }
.mm-stat-icon {
    width: 44px; height: 44px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.mm-stat-val  { font-size: 1.45rem; font-weight: 800; line-height: 1; }
.mm-stat-lbl  { font-size: .78rem; color: var(--text-secondary); margin-top: 2px; }

/* ── Service toggle banner ── */
.mm-service-banner {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 18px;
    border-radius: 11px;
    margin-bottom: 22px;
    font-weight: 600;
    font-size: .9rem;
    gap: 12px;
}
.mm-service-banner.on  { background: rgba(34,197,94,.08);  border: 1px solid rgba(34,197,94,.25);  color: var(--green); }
.mm-service-banner.off { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.25);  color: #ef4444; }

/* ── Tabs ── */
.mm-tabs {
    display: flex;
    gap: 4px;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 5px;
    margin-bottom: 22px;
    width: fit-content;
}
.mm-tab {
    padding: 9px 18px;
    border-radius: 9px;
    font-weight: 600;
    font-size: .875rem;
    cursor: pointer;
    border: none;
    background: none;
    color: var(--text-secondary);
    display: flex; align-items: center; gap: 7px;
    transition: all .18s;
    text-decoration: none;
    white-space: nowrap;
}
.mm-tab:hover { color: var(--text); background: var(--bg-overlay); }
.mm-tab.active { background: var(--blue); color: #fff; box-shadow: 0 2px 10px rgba(59,130,246,.35); }
.mm-tab .tab-count {
    background: rgba(255,255,255,.2);
    border-radius: 20px;
    padding: 1px 7px;
    font-size: .72rem;
    font-weight: 700;
}
.mm-tab:not(.active) .tab-count {
    background: var(--bg-overlay);
    color: var(--text-secondary);
}

/* ── Tab panels ── */
.mm-panel { display: none; }
.mm-panel.active { display: block; }

/* ── Card ── */
.mm-card {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 20px;
}
.mm-card-head {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
    background: var(--bg-overlay);
    gap: 12px;
    flex-wrap: wrap;
}
.mm-card-title {
    font-weight: 700; font-size: .95rem;
    display: flex; align-items: center; gap: 8px;
    margin: 0;
}
.mm-card-body { padding: 20px; }

/* ── Search bar ── */
.mm-search-row {
    display: flex; gap: 10px; align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-overlay);
    flex-wrap: wrap;
}
.mm-search-wrap {
    position: relative;
    flex: 1; min-width: 220px;
}
.mm-search-wrap i {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: .85rem;
    pointer-events: none;
}
.mm-search {
    width: 100%;
    padding: 9px 12px 9px 34px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--bg-elevated);
    color: var(--text);
    font-size: .875rem;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.mm-search:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.mm-result-info { font-size: .78rem; color: var(--text-secondary); white-space: nowrap; }

/* ── Table ── */
.mm-table-wrap { overflow-x: auto; }
.mm-table {
    width: 100%;
    border-collapse: collapse;
}
.mm-table thead { background: var(--bg-overlay); }
.mm-table th {
    padding: 11px 14px;
    text-align: left;
    font-weight: 700;
    font-size: .73rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .6px;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.mm-table td {
    padding: 11px 14px;
    border-bottom: 1px solid rgba(var(--border-rgb, 255,255,255),.06);
    vertical-align: middle;
    font-size: .875rem;
}
.mm-table tbody tr { transition: background .12s; }
.mm-table tbody tr:hover { background: var(--bg-overlay); }
.mm-table tbody tr:last-child td { border-bottom: none; }

/* ── Badges ── */
.mm-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.mm-badge-personal { background: rgba(59,130,246,.12);  color: var(--blue); }
.mm-badge-faction  { background: rgba(168,85,247,.12);  color: var(--purple, #a855f7); }
.mm-badge-admin    { background: rgba(249,115,22,.12);  color: var(--orange, #f97316); }
.mm-badge-on  { background: rgba(34,197,94,.12);  color: var(--green); }
.mm-badge-off { background: rgba(100,116,139,.12); color: var(--text-secondary); }

/* ── Address cell ── */
.mm-addr { font-family: 'DM Mono', monospace; font-weight: 600; color: var(--blue); font-size: .82rem; }
.mm-addr-name { font-size: .75rem; color: var(--text-secondary); margin-top: 2px; }

/* ── Action buttons ── */
.mm-actions { display: flex; gap: 5px; justify-content: flex-end; align-items: center; }
.mm-btn {
    padding: 6px 11px;
    border-radius: 7px;
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all .15s;
    white-space: nowrap;
}
.mm-btn:hover { transform: translateY(-1px); }
.mm-btn-primary { background: var(--blue); color: #fff; }
.mm-btn-primary:hover { background: #2563eb; }
.mm-btn-sec { background: var(--bg-overlay); border: 1px solid var(--border); color: var(--text); }
.mm-btn-sec:hover { background: var(--bg-elevated); }
.mm-btn-danger { background: rgba(239,68,68,.12); color: #ef4444; }
.mm-btn-danger:hover { background: rgba(239,68,68,.22); }
.mm-btn-warn { background: rgba(249,115,22,.12); color: var(--orange, #f97316); }
.mm-btn-warn:hover { background: rgba(249,115,22,.22); }
.mm-btn-lg { padding: 9px 16px; font-size: .875rem; border-radius: 9px; }

/* ── Pagination ── */
.mm-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-top: 1px solid var(--border);
    gap: 12px; flex-wrap: wrap;
}
.mm-page-info { font-size: .8rem; color: var(--text-secondary); }
.mm-page-btns { display: flex; gap: 4px; align-items: center; }
.mm-page-btn {
    min-width: 34px; height: 34px;
    padding: 0 8px;
    border-radius: 8px;
    font-size: .82rem; font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--bg-elevated);
    color: var(--text);
    display: flex; align-items: center; justify-content: center;
    text-decoration: none;
    transition: all .15s;
}
.mm-page-btn:hover { border-color: var(--blue); color: var(--blue); }
.mm-page-btn.current { background: var(--blue); border-color: var(--blue); color: #fff; }
.mm-page-btn.disabled { opacity: .35; cursor: default; pointer-events: none; }

/* ── Faction templates ── */
.faction-group { margin-bottom: 24px; }
.faction-group-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    background: var(--bg-overlay);
    border: 1px solid var(--border);
    border-radius: 10px 10px 0 0;
    border-bottom: none;
}
.faction-group-label {
    display: flex; align-items: center; gap: 8px;
    font-weight: 800; font-size: .82rem;
    text-transform: uppercase; letter-spacing: .5px;
}
.faction-group-body {
    border: 1px solid var(--border);
    border-radius: 0 0 10px 10px;
    overflow: hidden;
}
.tpl-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px;
    border-bottom: 1px solid var(--border);
    transition: background .12s;
}
.tpl-row:last-child { border-bottom: none; }
.tpl-row:hover { background: var(--bg-overlay); }
.tpl-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
.tpl-addr { font-family: 'DM Mono', monospace; font-size: .8rem; font-weight: 600; }
.tpl-label { font-size: .72rem; color: var(--text-secondary); margin-top: 1px; }
.tpl-user  { font-size: .72rem; color: var(--blue); }
.tpl-pwd   {
    display: flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    background: var(--bg-overlay);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: .72rem;
}
.tpl-actions { display: flex; gap: 5px; margin-left: auto; flex-shrink: 0; }

/* ── Settings layout ── */
.mm-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 780px) { .mm-settings-grid { grid-template-columns: 1fr; } }

/* ── Form helpers ── */
.mm-form-group { margin-bottom: 16px; }
.mm-form-group:last-child { margin-bottom: 0; }
.mm-label {
    display: block;
    font-weight: 700; font-size: .75rem;
    color: var(--text-secondary);
    margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: .5px;
}
.mm-input {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-input, var(--bg-elevated));
    color: var(--text);
    font-size: .875rem;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.mm-input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.mm-input:disabled { opacity: .55; cursor: not-allowed; }
.mm-hint { font-size: .72rem; color: var(--text-secondary); margin-top: 5px; }

/* ── Activity bars ── */
.activity-bars {
    display: flex; align-items: flex-end; gap: 6px;
    height: 100px;
}
.activity-bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.activity-bar { width: 100%; border-radius: 4px 4px 0 0; transition: height .3s; }
.activity-bar-val { font-size: .7rem; font-weight: 700; height: 14px; }
.activity-bar-day { font-size: .65rem; color: var(--text-secondary); }

/* ── Alert ── */
.mm-alert {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
    font-size: .9rem;
    font-weight: 500;
    animation: mmSlide .3s ease-out;
}
@keyframes mmSlide { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: none; } }
.mm-alert-ok  { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.3);  color: var(--green); }
.mm-alert-err { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: #ef4444; }

/* ── Modal ── */
.mm-modal {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 999;
    align-items: center; justify-content: center;
    padding: 20px;
}
.mm-modal-box {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 16px;
    width: min(500px, 96vw);
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
}
.mm-modal-head {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
}
.mm-modal-head h3 { margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 9px; }
.mm-modal-close {
    background: none; border: none;
    font-size: 1.1rem;
    cursor: pointer; color: var(--text-secondary);
    padding: 4px 7px; border-radius: 6px;
    transition: color .15s, background .15s;
}
.mm-modal-close:hover { color: var(--text); background: var(--bg-overlay); }
.mm-modal-body { padding: 22px; }
.mm-modal-foot { padding: 14px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

/* ── Logo miniature + bouton supprimer ── */
.logo-cell-wrap { display: inline-block; position: relative; }
.logo-del-btn {
    display: none;
    position: absolute; top: -5px; right: -5px;
    width: 16px; height: 16px;
    border-radius: 50%;
    background: #ef4444;
    border: none;
    color: #fff;
    font-size: .5rem;
    cursor: pointer;
    align-items: center; justify-content: center;
    padding: 0;
    line-height: 1;
    box-shadow: 0 1px 4px rgba(0,0,0,.3);
    transition: transform .15s;
}
.logo-cell-wrap:hover .logo-del-btn { display: flex; }
.logo-del-btn:hover { transform: scale(1.15); }
.mm-empty {
    text-align: center; padding: 52px 20px;
    color: var(--text-secondary);
}
.mm-empty i { font-size: 2.2rem; opacity: .3; margin-bottom: 12px; display: block; }
.mm-empty p { margin: 0; font-size: .9rem; }

/* ── Toggle ── */
.mm-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px;
    border-radius: 9px;
    border: 1px solid var(--border);
    background: var(--bg-overlay);
    gap: 12px;
    margin-bottom: 16px;
}
.mm-toggle-info strong { font-size: .9rem; display: block; }
.mm-toggle-info span { font-size: .78rem; color: var(--text-secondary); }
</style>

<!-- ── PAGE HEADER ── -->
<div class="mm-header">
    <div>
        <h1 class="mm-title">
            <i class="fa-solid fa-envelope-open-text" style="color:var(--blue)"></i>
            Gestionnaire Mail
        </h1>
        <p class="mm-subtitle">Administration du système de messagerie LysoriaMail · <span style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--blue);"><?php echo htmlspecialchars($serverName); ?></span></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="mm-btn mm-btn-primary mm-btn-lg" onclick="openModal('createCustomModal')">
            <i class="fa-solid fa-plus"></i> Nouvelle adresse
        </button>
        <a href="<?php echo SITE_URL; ?>/pages/mail/index.php" target="_blank" class="mm-btn mm-btn-sec mm-btn-lg">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Ouvrir le mail
        </a>
    </div>
</div>

<!-- ── ALERTES ── -->
<?php if($msg): ?>
<div class="mm-alert mm-alert-ok"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if($err): ?>
<div class="mm-alert mm-alert-err"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($err); ?></div>
<?php endif; ?>

<!-- ── STATS ── -->
<div class="mm-stats">
    <div class="mm-stat">
        <div class="mm-stat-icon" style="background:rgba(59,130,246,.12);color:var(--blue)">
            <i class="fa-solid fa-at"></i>
        </div>
        <div>
            <div class="mm-stat-val"><?php echo number_format($stats['total_accounts']); ?></div>
            <div class="mm-stat-lbl">Comptes mail</div>
        </div>
    </div>
    <div class="mm-stat">
        <div class="mm-stat-icon" style="background:rgba(34,197,94,.12);color:var(--green)">
            <i class="fa-solid fa-paper-plane"></i>
        </div>
        <div>
            <div class="mm-stat-val"><?php echo number_format($stats['total_messages']); ?></div>
            <div class="mm-stat-lbl">Messages envoyés</div>
        </div>
    </div>
    <div class="mm-stat">
        <div class="mm-stat-icon" style="background:rgba(249,115,22,.12);color:var(--orange,#f97316)">
            <i class="fa-solid fa-envelope"></i>
        </div>
        <div>
            <div class="mm-stat-val"><?php echo number_format($stats['total_unread']); ?></div>
            <div class="mm-stat-lbl">Non-lus</div>
        </div>
    </div>
    <div class="mm-stat">
        <div class="mm-stat-icon" style="background:rgba(168,85,247,.12);color:var(--purple,#a855f7)">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
            <div class="mm-stat-val"><?php echo number_format($stats['active_accounts']); ?></div>
            <div class="mm-stat-lbl">Comptes actifs</div>
        </div>
    </div>
</div>

<!-- ── TABS ── -->
<?php
function tabUrl($tab, $search='') {
    $q = $search ? '&q='.urlencode($search) : '';
    return '?tab='.$tab.$q;
}
?>
<div class="mm-tabs">
    <a href="<?php echo tabUrl('accounts', $search); ?>" class="mm-tab <?php echo $activeTab==='accounts' ? 'active' : ''; ?>">
        <i class="fa-solid fa-at"></i> Comptes
        <span class="tab-count"><?php echo $stats['total_accounts']; ?></span>
    </a>
    <a href="<?php echo tabUrl('factions', $search); ?>" class="mm-tab <?php echo $activeTab==='factions' ? 'active' : ''; ?>">
        <i class="fa-solid fa-shield-halved"></i> Factions
        <span class="tab-count"><?php echo count($templates); ?></span>
    </a>
    <a href="<?php echo tabUrl('activity'); ?>" class="mm-tab <?php echo $activeTab==='activity' ? 'active' : ''; ?>">
        <i class="fa-solid fa-chart-bar"></i> Activité
    </a>
    <a href="<?php echo tabUrl('settings'); ?>" class="mm-tab <?php echo $activeTab==='settings' ? 'active' : ''; ?>">
        <i class="fa-solid fa-cog"></i> Paramètres
    </a>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- TAB : COMPTES                                                  -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="mm-panel <?php echo $activeTab==='accounts' ? 'active' : ''; ?>" id="panel-accounts">
<div class="mm-card">
    <!-- Search bar -->
    <form method="get" action="" class="mm-search-row">
        <input type="hidden" name="tab" value="accounts">
        <div class="mm-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" class="mm-search"
                placeholder="Rechercher par adresse, utilisateur…"
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off">
        </div>
        <?php if($search): ?>
        <a href="?tab=accounts" class="mm-btn mm-btn-sec" style="padding:8px 12px;font-size:.8rem;">
            <i class="fa-solid fa-xmark"></i> Effacer
        </a>
        <?php endif; ?>
        <div class="mm-result-info">
            <?php if($search): ?>
                <?php echo $totalAccounts; ?> résultat<?php echo $totalAccounts>1?'s':''; ?> pour « <?php echo htmlspecialchars($search); ?> »
            <?php else: ?>
                <?php echo $totalAccounts; ?> compte<?php echo $totalAccounts>1?'s':''; ?> au total
            <?php endif; ?>
        </div>
    </form>

    <!-- Table -->
    <div class="mm-table-wrap">
        <table class="mm-table">
            <thead>
                <tr>
                    <th style="width:48px;padding-left:16px;"></th>
                    <th>Adresse mail</th>
                    <th>Utilisateur</th>
                    <th>Type</th>
                    <th style="text-align:center;">Msgs inbox</th>
                    <th style="text-align:center;">Statut</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($accounts as $acc):
                $typeBadge = ['personal'=>'mm-badge-personal','faction'=>'mm-badge-faction','admin'=>'mm-badge-admin'][$acc['type']] ?? 'mm-badge-faction';
                $typeLbl   = ['personal'=>'Personnel','faction'=>'Faction','admin'=>'Admin'][$acc['type']] ?? $acc['type'];
            ?>
            <tr>
                <td style="padding:8px 6px 8px 14px;">
                    <?php if(!empty($acc['logo'])): ?>
                    <div style="position:relative;display:inline-block;" class="logo-cell-wrap">
                        <img src="<?php echo SITE_URL.'/assets/'.htmlspecialchars($acc['logo']); ?>"
                             alt="logo"
                             style="width:34px;height:34px;border-radius:8px;object-fit:cover;border:1px solid var(--border);display:block;">
                        <form method="post" style="display:none;" class="logo-delete-form" onsubmit="return confirm('Supprimer le logo ?')">
                            <input type="hidden" name="action" value="delete_logo_staff">
                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                            <input type="hidden" name="_tab" value="accounts">
                            <?php if(isset($_GET['page'])): ?><input type="hidden" name="_page" value="<?php echo (int)$_GET['page']; ?>"><?php endif; ?>
                            <?php if($search): ?><input type="hidden" name="_q" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                        </form>
                        <button type="button"
                            onclick="this.closest('.logo-cell-wrap').querySelector('.logo-delete-form').submit()"
                            class="logo-del-btn"
                            title="Supprimer le logo">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <?php else: ?>
                    <div style="width:34px;height:34px;border-radius:8px;background:var(--bg-overlay);border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;">
                        <i class="fa-solid fa-at" style="font-size:.7rem;color:var(--text-secondary);opacity:.4;"></i>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="mm-addr"><?php echo htmlspecialchars($acc['address']); ?></div>
                    <?php if($acc['display_name']): ?>
                    <div class="mm-addr-name"><?php echo htmlspecialchars($acc['display_name']); ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($acc['username']): ?>
                        <span style="font-size:.85rem;"><?php echo htmlspecialchars($acc['username']); ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-secondary);font-size:.8rem;">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="mm-badge <?php echo $typeBadge; ?>"><?php echo $typeLbl; ?></span></td>
                <td style="text-align:center;color:var(--text-secondary);font-size:.85rem;"><?php echo $acc['inbox_count']; ?></td>
                <td style="text-align:center;">
                    <span class="mm-badge <?php echo $acc['is_active'] ? 'mm-badge-on' : 'mm-badge-off'; ?>">
                        <i class="fa-solid fa-circle" style="font-size:.45rem;"></i>
                        <?php echo $acc['is_active'] ? 'Actif' : 'Inactif'; ?>
                    </span>
                </td>
                <td>
                    <div class="mm-actions">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                            <?php if($search): ?><input type="hidden" name="_redirect_q" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                            <button type="submit" class="mm-btn mm-btn-sec">
                                <i class="fa-solid fa-<?php echo $acc['is_active'] ? 'ban' : 'play'; ?>"></i>
                                <?php echo $acc['is_active'] ? 'Désactiver' : 'Activer'; ?>
                            </button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cette adresse et tous ses messages ?')">
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                            <button type="submit" class="mm-btn mm-btn-danger">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($accounts)): ?>
            <tr>
                <td colspan="6">
                    <div class="mm-empty">
                        <i class="fa-solid fa-inbox"></i>
                        <p><?php echo $search ? 'Aucun résultat pour cette recherche.' : 'Aucun compte mail créé.'; ?></p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if($totalPages > 1 || $totalAccounts > 0): ?>
    <div class="mm-pagination">
        <div class="mm-page-info">
            Affichage <?php echo min($offset+1, $totalAccounts); ?>–<?php echo min($offset+$perPage, $totalAccounts); ?>
            sur <?php echo $totalAccounts; ?> compte<?php echo $totalAccounts>1?'s':''; ?>
        </div>
        <div class="mm-page-btns">
            <?php
            $baseUrl = '?tab=accounts'.($search ? '&q='.urlencode($search) : '');
            // Précédent
            if($curPage > 1):
            ?>
            <a href="<?php echo $baseUrl.'&page='.($curPage-1); ?>" class="mm-page-btn">
                <i class="fa-solid fa-chevron-left" style="font-size:.7rem;"></i>
            </a>
            <?php else: ?>
            <span class="mm-page-btn disabled"><i class="fa-solid fa-chevron-left" style="font-size:.7rem;"></i></span>
            <?php endif; ?>

            <?php
            // Numéros de page (max 7 affichés)
            $start = max(1, min($curPage - 3, $totalPages - 6));
            $end   = min($totalPages, $start + 6);
            for($p = $start; $p <= $end; $p++):
            ?>
            <a href="<?php echo $baseUrl.'&page='.$p; ?>"
               class="mm-page-btn <?php echo $p === $curPage ? 'current' : ''; ?>">
                <?php echo $p; ?>
            </a>
            <?php endfor; ?>

            <?php if($curPage < $totalPages): ?>
            <a href="<?php echo $baseUrl.'&page='.($curPage+1); ?>" class="mm-page-btn">
                <i class="fa-solid fa-chevron-right" style="font-size:.7rem;"></i>
            </a>
            <?php else: ?>
            <span class="mm-page-btn disabled"><i class="fa-solid fa-chevron-right" style="font-size:.7rem;"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- TAB : FACTIONS                                                 -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="mm-panel <?php echo $activeTab==='factions' ? 'active' : ''; ?>" id="panel-factions">

<div class="mm-card">
    <div class="mm-card-head">
        <div class="mm-card-title">
            <i class="fa-solid fa-shield-halved" style="color:var(--purple,#a855f7)"></i>
            Adresses faction prédéfinies
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="mm-btn mm-btn-sec" onclick="openModal('addCategoryModal')">
                <i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie
            </button>
            <button class="mm-btn mm-btn-primary" onclick="openModal('addTplModal')">
                <i class="fa-solid fa-plus"></i> Ajouter un template
            </button>
        </div>
    </div>
    <div class="mm-card-body">

    <?php if(empty($factions)): ?>
    <div class="mm-empty">
        <i class="fa-solid fa-shield-halved"></i>
        <p>Aucune catégorie faction. Créez-en une pour commencer.</p>
    </div>
    <?php endif; ?>

    <?php foreach($factions as $faction):
        $ftmpls = array_values(array_filter($templates, fn($t)=>$t['faction_key']===$faction));
        $createdCount = count(array_filter($ftmpls, fn($t)=>$t['created']));
    ?>
    <div class="faction-group" data-faction-group="<?php echo htmlspecialchars($faction); ?>">
        <div class="faction-group-head">
            <div class="faction-group-label">
                <i class="fa-solid fa-shield-halved" style="color:var(--blue);font-size:.8rem;"></i>
                <span id="faction-label-<?php echo htmlspecialchars($faction); ?>"><?php echo htmlspecialchars($faction); ?></span>
                <span style="font-weight:400;font-size:.72rem;color:var(--text-secondary);text-transform:none;letter-spacing:0;">
                    <?php echo count($ftmpls); ?> template<?php echo count($ftmpls)>1?'s':''; ?>
                    <?php if($createdCount): ?> · <span style="color:var(--green);"><?php echo $createdCount; ?> actif<?php echo $createdCount>1?'s':''; ?></span><?php endif; ?>
                </span>
            </div>
            <div style="display:flex;gap:6px;">
                <button class="mm-btn mm-btn-sec" style="padding:5px 10px;font-size:.75rem;"
                    onclick="openRenameFaction('<?php echo htmlspecialchars($faction); ?>')">
                    <i class="fa-solid fa-pen"></i> Renommer
                </button>
                <button class="mm-btn mm-btn-primary" style="padding:5px 10px;font-size:.75rem;"
                    onclick="openAddTplForFaction('<?php echo htmlspecialchars($faction); ?>')">
                    <i class="fa-solid fa-plus"></i> Template
                </button>
            </div>
        </div>

        <div class="faction-group-body">
        <?php foreach($ftmpls as $t):
            $tAddr = $t['prefix'].'@'.strtolower($faction).'.us';
            $tAcc  = $factionAccountMap[$tAddr] ?? null;
        ?>
        <div class="tpl-row" id="tpl-card-<?php echo $t['id']; ?>">
            <div class="tpl-dot" style="background:<?php echo $t['created'] ? 'var(--green)' : 'var(--text-secondary)'; ?>;"></div>

            <div style="flex:1;min-width:0;">
                <div class="tpl-addr" style="color:<?php echo $t['created'] ? 'var(--text)' : 'var(--text-secondary)'; ?>">
                    <?php echo htmlspecialchars($t['prefix']); ?>@<?php echo strtolower($faction); ?>.us
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-top:2px;flex-wrap:wrap;">
                    <span class="tpl-label"><?php echo htmlspecialchars($t['label']); ?></span>
                    <?php if($tAcc && $tAcc['username']): ?>
                    <span class="tpl-user"><i class="fa-solid fa-user" style="font-size:.6rem;"></i> <?php echo htmlspecialchars($tAcc['username']); ?></span>
                    <?php endif; ?>
                    <?php if($tAcc && $tAcc['has_password']): ?>
                    <span class="tpl-pwd">
                        <i class="fa-solid fa-key" style="font-size:.65rem;color:var(--orange,#f97316);"></i>
                        <span class="pwd-dots" id="pwd-display-<?php echo $tAcc['id']; ?>" style="font-family:'DM Mono',monospace;letter-spacing:2px;">••••••</span>
                        <button type="button" onclick="togglePwdVisibility(<?php echo $tAcc['id']; ?>)"
                            id="pwd-eye-<?php echo $tAcc['id']; ?>"
                            style="background:none;border:none;color:var(--text-secondary);cursor:pointer;padding:0;font-size:.7rem;">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tpl-actions">
                <?php if(!$t['created']): ?>
                <button class="mm-btn mm-btn-primary" style="padding:5px 10px;font-size:.72rem;"
                    onclick="openFactionCreate('<?php echo htmlspecialchars($t['faction_key']); ?>','<?php echo htmlspecialchars($t['prefix']); ?>')">
                    <i class="fa-solid fa-plus"></i> Créer
                </button>
                <?php else: ?>
                <button class="mm-btn mm-btn-sec" style="padding:5px 9px;font-size:.72rem;"
                    onclick="openFactionEdit('<?php echo $tAcc['id'] ?? 0; ?>','<?php echo htmlspecialchars($tAddr); ?>','<?php echo htmlspecialchars($tAcc['username'] ?? ''); ?>')">
                    <i class="fa-solid fa-user-pen"></i>
                </button>
                <?php if($tAcc): ?>
                <button class="mm-btn mm-btn-warn" style="padding:5px 9px;font-size:.72rem;"
                    onclick="openPwdEdit(<?php echo $tAcc['id']; ?>,'<?php echo htmlspecialchars($tAddr); ?>')">
                    <i class="fa-solid fa-key"></i>
                </button>
                <?php endif; ?>
                <?php if($tAcc && $tAcc['has_password']): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer le mot de passe ?')">
                    <input type="hidden" name="action" value="clear_faction_password">
                    <input type="hidden" name="account_id" value="<?php echo $tAcc['id']; ?>">
                    <input type="hidden" name="_tab" value="factions">
                    <button type="submit" class="mm-btn mm-btn-danger" style="padding:5px 9px;font-size:.72rem;" title="Supprimer le mot de passe">
                        <i class="fa-solid fa-key" style="text-decoration:line-through;"></i>
                    </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <button class="mm-btn mm-btn-danger" style="padding:5px 9px;font-size:.72rem;"
                    onclick="deleteTemplate(<?php echo $t['id']; ?>,<?php echo $t['created'] ? 1 : 0; ?>,this)">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    </div>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- TAB : ACTIVITÉ                                                 -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="mm-panel <?php echo $activeTab==='activity' ? 'active' : ''; ?>" id="panel-activity">
<div class="mm-card">
    <div class="mm-card-head">
        <div class="mm-card-title"><i class="fa-solid fa-chart-bar" style="color:var(--blue)"></i> Activité mail — 14 derniers jours</div>
    </div>
    <div class="mm-card-body">
        <?php
        $daily = $db->query("
            SELECT DATE(sent_at) as d, COUNT(*) as cnt FROM mail_messages
            WHERE sent_at IS NOT NULL AND sent_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY DATE(sent_at) ORDER BY d ASC
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        $maxDay = max(array_values($daily) ?: [1]);
        ?>
        <div class="activity-bars">
        <?php for($i=13; $i>=0; $i--):
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $cnt = (int)($daily[$day] ?? 0);
            $h   = $maxDay > 0 ? max(6, round(($cnt / $maxDay) * 80)) : 6;
        ?>
        <div class="activity-bar-wrap">
            <div class="activity-bar-val"><?php echo $cnt ?: ''; ?></div>
            <div class="activity-bar" style="height:<?php echo $h; ?>px;background:<?php echo $i===0 ? 'var(--blue)' : 'rgba(59,130,246,.3)'; ?>;"></div>
            <div class="activity-bar-day"><?php echo mb_strtoupper(mb_substr(date('D', strtotime($day)), 0, 1)); ?></div>
        </div>
        <?php endfor; ?>
        </div>

        <!-- Récapitulatif rapide -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:24px;">
            <?php
            $last7  = $db->query("SELECT COUNT(*) FROM mail_messages WHERE sent_at IS NOT NULL AND sent_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
            $last24 = $db->query("SELECT COUNT(*) FROM mail_messages WHERE sent_at IS NOT NULL AND sent_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $users7 = $db->query("SELECT COUNT(DISTINCT ml.user_id) FROM mail_boxes mb JOIN mail_account_links ml ON ml.account_id=mb.account_id WHERE mb.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
            ?>
            <div style="text-align:center;padding:16px;background:var(--bg-overlay);border-radius:10px;border:1px solid var(--border);">
                <div style="font-size:1.6rem;font-weight:800;color:var(--blue);"><?php echo $last24; ?></div>
                <div style="font-size:.75rem;color:var(--text-secondary);margin-top:3px;">Messages (24h)</div>
            </div>
            <div style="text-align:center;padding:16px;background:var(--bg-overlay);border-radius:10px;border:1px solid var(--border);">
                <div style="font-size:1.6rem;font-weight:800;color:var(--green);"><?php echo $last7; ?></div>
                <div style="font-size:.75rem;color:var(--text-secondary);margin-top:3px;">Messages (7 jours)</div>
            </div>
            <div style="text-align:center;padding:16px;background:var(--bg-overlay);border-radius:10px;border:1px solid var(--border);">
                <div style="font-size:1.6rem;font-weight:800;color:var(--purple,#a855f7);"><?php echo $users7; ?></div>
                <div style="font-size:.75rem;color:var(--text-secondary);margin-top:3px;">Utilisateurs actifs (7j)</div>
            </div>
        </div>
    </div>
</div>
</div>


<!-- ══════════════════════════════════════════════════════════════ -->
<!-- TAB : PARAMÈTRES                                               -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="mm-panel <?php echo $activeTab==='settings' ? 'active' : ''; ?>" id="panel-settings">

<!-- Bannière service ON/OFF -->
<div class="mm-service-banner <?php echo $mailIsEnabled ? 'on' : 'off'; ?>">
    <div style="display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-<?php echo $mailIsEnabled ? 'circle-check' : 'circle-xmark'; ?>"></i>
        Service LysoriaMail — <?php echo $mailIsEnabled ? 'Actif' : 'Inactif'; ?>
    </div>
</div>

<div class="mm-settings-grid">

<!-- Colonne gauche : paramètres -->
<div class="mm-card">
    <div class="mm-card-head">
        <div class="mm-card-title"><i class="fa-solid fa-sliders" style="color:var(--blue)"></i> Configuration</div>
    </div>
    <div class="mm-card-body">
        <form method="post">
            <input type="hidden" name="action" value="update_settings">
            <input type="hidden" name="_tab" value="settings">

            <div class="mm-toggle-row">
                <div class="mm-toggle-info">
                    <strong style="color:<?php echo $mailIsEnabled ? 'var(--green)' : '#ef4444'; ?>">
                        <i class="fa-solid fa-power-off" style="font-size:.8rem;"></i>
                        Service mail
                    </strong>
                    <span><?php echo $mailIsEnabled ? 'Le service est opérationnel' : 'Le service est désactivé'; ?></span>
                </div>
                <label class="toggle">
                    <input type="checkbox" name="mail_enabled" <?php echo $mailIsEnabled ? 'checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="mm-form-group">
                <label class="mm-label">Nom du serveur</label>
                <input type="text" name="mail_server_name" class="mm-input"
                    value="<?php echo htmlspecialchars($settings['mail_server_name'] ?? 'lysoria'); ?>">
                <div class="mm-hint">Utilisé dans les adresses : utilisateur@<strong><?php echo htmlspecialchars($settings['mail_server_name'] ?? 'lysoria'); ?></strong>.tld</div>
            </div>

            <div class="mm-form-group">
                <label class="mm-label">Extension par défaut</label>
                <select name="mail_default_tld" class="mm-input">
                    <?php foreach(['fr','com','net','store','us'] as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo ($settings['mail_default_tld'] ?? 'fr') === $t ? 'selected' : ''; ?>>.<?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mm-form-group">
                <label class="mm-label">Max comptes par utilisateur</label>
                <input type="number" name="mail_max_per_user" class="mm-input"
                    value="<?php echo (int)($settings['mail_max_per_user'] ?? 3); ?>" min="1" max="20">
            </div>

            <button type="submit" class="mm-btn mm-btn-primary mm-btn-lg" style="width:100%;justify-content:center;margin-top:4px;">
                <i class="fa-solid fa-save"></i> Sauvegarder
            </button>
        </form>
    </div>
</div>

<!-- Colonne droite : infos serveur -->
<div>
<div class="mm-card">
    <div class="mm-card-head">
        <div class="mm-card-title"><i class="fa-solid fa-circle-info" style="color:var(--blue)"></i> Informations</div>
    </div>
    <div class="mm-card-body">
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php
            $infos = [
                ['Serveur',          $settings['mail_server_name'] ?? 'lysoria',     'fa-server',   'var(--blue)'],
                ['Extension défaut', '.'.($settings['mail_default_tld'] ?? 'fr'),    'fa-globe',    'var(--green)'],
                ['Max/utilisateur',  ($settings['mail_max_per_user'] ?? 3).' comptes','fa-at',      'var(--purple,#a855f7)'],
                ['Comptes actifs',   $stats['active_accounts'].' / '.$stats['total_accounts'], 'fa-circle-check', 'var(--green)'],
                ['Templates faction',count($templates).' définis',                   'fa-shield-halved', 'var(--orange,#f97316)'],
            ];
            foreach($infos as [$lbl, $val, $icon, $color]):
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--bg-overlay);border-radius:8px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--text-secondary);">
                    <i class="fa-solid <?php echo $icon; ?>" style="color:<?php echo $color; ?>;width:14px;text-align:center;"></i>
                    <?php echo $lbl; ?>
                </div>
                <div style="font-size:.85rem;font-weight:700;font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($val); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</div>

</div><!-- end settings grid -->
</div><!-- end panel settings -->


<!-- ════════════════════════════════════════════════════════════════ -->
<!-- MODALS                                                           -->
<!-- ════════════════════════════════════════════════════════════════ -->

<!-- Créer adresse custom -->
<div id="createCustomModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:480px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-envelope-circle-check" style="color:var(--blue)"></i> Créer une adresse mail</h3>
            <button class="mm-modal-close" onclick="closeModal('createCustomModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="create_custom_mail">
        <div class="mm-modal-body">
            <div class="mm-form-group">
                <label class="mm-label">Adresse complète</label>
                <input type="text" name="address" class="mm-input" placeholder="admin@lysoria.fr" required>
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Nom affiché</label>
                <input type="text" name="display_name" class="mm-input" placeholder="Administrateur Système">
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Assigner à un utilisateur <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optionnel)</span></label>
                <select name="user_id" class="mm-input">
                    <option value="0">— Aucun —</option>
                    <?php foreach($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Type</label>
                <select name="type" class="mm-input">
                    <option value="admin">Admin</option>
                    <option value="faction">Faction</option>
                    <option value="personal">Personnel</option>
                </select>
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('createCustomModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-plus"></i> Créer</button>
        </div>
        </form>
    </div>
</div>

<!-- Créer adresse faction depuis template -->
<div id="factionCreateModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:460px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-shield-halved" style="color:var(--purple,#a855f7)"></i> Créer adresse faction</h3>
            <button class="mm-modal-close" onclick="closeModal('factionCreateModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="create_faction_mail">
        <input type="hidden" name="faction_key" id="fm_faction_key">
        <input type="hidden" name="prefix" id="fm_prefix">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:12px 14px;font-family:'DM Mono',monospace;font-size:.9rem;color:var(--blue);margin-bottom:18px;" id="fm_preview"></div>
            <div class="mm-form-group">
                <label class="mm-label">Domaine faction (avant .us)</label>
                <input type="text" name="domain" class="mm-input" id="fm_domain" placeholder="lspd" oninput="updateFmPreview()">
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Assigner à un utilisateur <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optionnel)</span></label>
                <select name="user_id" class="mm-input">
                    <option value="0">— Compte non-assigné —</option>
                    <?php foreach($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Mot de passe <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optionnel)</span></label>
                <div style="position:relative;">
                    <input type="password" name="mail_password" class="mm-input" id="fm_password" placeholder="Laisser vide = aucun mot de passe" style="padding-right:40px;">
                    <button type="button" onclick="toggleInput('fm_password','fm_pwd_eye')"
                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-secondary);cursor:pointer;" id="fm_pwd_eye">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('factionCreateModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-plus"></i> Créer</button>
        </div>
        </form>
    </div>
</div>

<!-- Modifier mot de passe faction -->
<div id="pwdEditModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:420px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-key" style="color:var(--orange,#f97316)"></i> Modifier le mot de passe</h3>
            <button class="mm-modal-close" onclick="closeModal('pwdEditModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="update_faction_password">
        <input type="hidden" name="account_id" id="pwd_account_id">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--blue);margin-bottom:16px;padding:8px 12px;background:var(--bg-overlay);border-radius:8px;" id="pwd_addr_display"></div>
            <div class="mm-form-group">
                <label class="mm-label">Nouveau mot de passe</label>
                <div style="position:relative;">
                    <input type="password" name="mail_password" class="mm-input" id="pwd_input" placeholder="Min. 4 caractères" style="padding-right:40px;" required minlength="4">
                    <button type="button" onclick="toggleInput('pwd_input','pwd_eye')"
                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-secondary);cursor:pointer;" id="pwd_eye">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
                <div class="mm-hint">Permet à n'importe quel utilisateur de se connecter à cette adresse.</div>
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('pwdEditModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-save"></i> Sauvegarder</button>
        </div>
        </form>
    </div>
</div>

<!-- Modifier assignation faction -->
<div id="factionEditModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:420px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-user-pen" style="color:var(--blue)"></i> Modifier l'assignation</h3>
            <button class="mm-modal-close" onclick="closeModal('factionEditModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="reassign_faction_mail">
        <input type="hidden" name="account_id" id="fe_account_id">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--blue);margin-bottom:16px;padding:8px 12px;background:var(--bg-overlay);border-radius:8px;" id="fe_addr_display"></div>
            <div class="mm-form-group">
                <label class="mm-label">Assigner à un utilisateur</label>
                <select name="user_id" class="mm-input" id="fe_user_select">
                    <option value="0">— Aucun —</option>
                    <?php foreach($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('factionEditModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-save"></i> Sauvegarder</button>
        </div>
        </form>
    </div>
</div>

<!-- Ajouter template faction -->
<div id="addTplModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:460px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-layer-plus" style="color:var(--blue)"></i> Nouveau template faction</h3>
            <button class="mm-modal-close" onclick="closeModal('addTplModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="add_faction_template">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div class="mm-form-group">
                <label class="mm-label">Faction</label>
                <input type="text" name="tpl_faction_key" class="mm-input" id="tpl_faction_key"
                    placeholder="ex : LSPD, SAMS, DOJ…"
                    oninput="this.value=this.value.toUpperCase();updateTplPreview()">
                <div class="mm-hint">Clé en majuscules, sans espace.</div>
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Préfixe (avant le @)</label>
                <input type="text" name="tpl_prefix" class="mm-input" id="tpl_prefix"
                    placeholder="ex : chief, recrutement…"
                    oninput="updateTplPreview()">
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Label <span style="font-weight:400;text-transform:none;letter-spacing:0;">(description)</span></label>
                <input type="text" name="tpl_label" class="mm-input" id="tpl_label" placeholder="ex : Chef de la police">
            </div>
            <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:10px 14px;font-family:'DM Mono',monospace;font-size:.85rem;color:var(--blue);" id="tpl_preview">
                prefix@faction.us
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('addTplModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </div>
        </form>
    </div>
</div>

<!-- Nouvelle catégorie faction -->
<div id="addCategoryModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:420px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-folder-plus" style="color:var(--blue)"></i> Nouvelle catégorie faction</h3>
            <button class="mm-modal-close" onclick="closeModal('addCategoryModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="add_faction_category">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div class="mm-form-group">
                <label class="mm-label">Nom de la catégorie</label>
                <input type="text" name="cat_key" class="mm-input" id="cat_key_input"
                    placeholder="ex : LSPD, BCSO, USMS…"
                    oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_]/g,'')">
                <div class="mm-hint">Majuscules et chiffres uniquement — ex : LSPD, BCSO, FBI.</div>
            </div>
            <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.15);border-radius:9px;padding:10px 13px;font-size:.77rem;color:var(--text-secondary);">
                <i class="fa-solid fa-circle-info" style="color:var(--blue);margin-right:5px;"></i>
                Un template <code style="font-family:'DM Mono',monospace;">contact@…</code> sera créé automatiquement. Tu pourras le supprimer ensuite.
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('addCategoryModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-folder-plus"></i> Créer</button>
        </div>
        </form>
    </div>
</div>

<!-- Renommer catégorie faction -->
<div id="renameFactionModal" class="mm-modal">
    <div class="mm-modal-box" style="max-width:420px;">
        <div class="mm-modal-head">
            <h3><i class="fa-solid fa-pen" style="color:var(--orange,#f97316)"></i> Renommer la catégorie</h3>
            <button class="mm-modal-close" onclick="closeModal('renameFactionModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="post">
        <input type="hidden" name="action" value="rename_faction_category">
        <input type="hidden" name="old_key" id="rename_old_key">
        <input type="hidden" name="_tab" value="factions">
        <div class="mm-modal-body">
            <div style="font-size:.75rem;color:var(--text-secondary);margin-bottom:16px;">
                Tous les templates et comptes mail liés seront mis à jour.
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Ancien nom</label>
                <input type="text" class="mm-input" id="rename_old_display" disabled style="opacity:.45;">
            </div>
            <div class="mm-form-group">
                <label class="mm-label">Nouveau nom</label>
                <input type="text" name="new_key" class="mm-input" id="rename_new_key"
                    placeholder="ex : LSPD"
                    oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_]/g,'')">
            </div>
        </div>
        <div class="mm-modal-foot">
            <button type="button" class="mm-btn mm-btn-sec" onclick="closeModal('renameFactionModal')">Annuler</button>
            <button type="submit" class="mm-btn mm-btn-primary"><i class="fa-solid fa-save"></i> Renommer</button>
        </div>
        </form>
    </div>
</div>


<script>
// ── Helpers modals ──────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// Fermer au clic extérieur
document.querySelectorAll('.mm-modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
// Fermer à Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.mm-modal').forEach(m => m.style.display = 'none');
});

// ── Maintenir l'onglet actif après POST ─────────────────────────
// Les forms avec _tab transmettent le tab courant
document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', function() {
        const tab = this.querySelector('[name="_tab"]');
        if (!tab) {
            // Ajouter le tab courant
            const t = document.createElement('input');
            t.type = 'hidden'; t.name = '_tab';
            t.value = '<?php echo htmlspecialchars($activeTab); ?>';
            this.appendChild(t);
        }
    });
});

// ── Recherche live (debounce) ────────────────────────────────────
const searchInput = document.querySelector('.mm-search');
if (searchInput) {
    let timer;
    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const q = this.value.trim();
            const url = '?tab=accounts' + (q ? '&q=' + encodeURIComponent(q) : '');
            window.location.href = url;
        }, 450);
    });
}

// ── Show/hide password (générique) ──────────────────────────────
function toggleInput(inputId, btnId) {
    const inp = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if (!inp) return;
    const show = inp.type === 'text';
    inp.type = show ? 'password' : 'text';
    btn.innerHTML = show ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
}

// ── Show/hide mot de passe faction (cache client) ────────────────
const pwdValues = {};
function togglePwdVisibility(accountId) {
    const display = document.getElementById('pwd-display-' + accountId);
    const eye     = document.getElementById('pwd-eye-' + accountId);
    if (!display) return;
    const isHidden = display.textContent.includes('•');
    if (isHidden) {
        display.textContent = '(haché — non lisible)';
        display.style.letterSpacing = 'normal';
        display.style.fontStyle = 'italic';
        display.style.color = 'var(--text-secondary)';
        eye.innerHTML = '<i class="fa-regular fa-eye-slash"></i>';
    } else {
        display.textContent = '••••••';
        display.style.letterSpacing = '2px';
        display.style.fontStyle = '';
        display.style.color = '';
        eye.innerHTML = '<i class="fa-regular fa-eye"></i>';
    }
}

// ── Modal faction create ─────────────────────────────────────────
function openFactionCreate(faction, prefix) {
    document.getElementById('fm_faction_key').value = faction;
    document.getElementById('fm_prefix').value = prefix;
    document.getElementById('fm_domain').value = faction.toLowerCase();
    document.getElementById('fm_preview').textContent = prefix + '@' + faction.toLowerCase() + '.us';
    document.getElementById('fm_password').value = '';
    openModal('factionCreateModal');
}
function updateFmPreview() {
    const prefix = document.getElementById('fm_prefix').value;
    const domain = document.getElementById('fm_domain').value;
    document.getElementById('fm_preview').textContent = prefix + '@' + domain + '.us';
}

// ── Modal modifier mot de passe ──────────────────────────────────
function openPwdEdit(accountId, address) {
    document.getElementById('pwd_account_id').value = accountId;
    document.getElementById('pwd_addr_display').textContent = address;
    document.getElementById('pwd_input').value = '';
    document.getElementById('pwd_input').type = 'password';
    document.getElementById('pwd_eye').innerHTML = '<i class="fa-regular fa-eye"></i>';
    openModal('pwdEditModal');
}

// ── Modal modifier assignation ───────────────────────────────────
function openFactionEdit(accountId, address, currentUsername) {
    document.getElementById('fe_account_id').value = accountId;
    document.getElementById('fe_addr_display').textContent = address;
    const sel = document.getElementById('fe_user_select');
    Array.from(sel.options).forEach(o => { o.selected = o.text === currentUsername; });
    openModal('factionEditModal');
}

// ── Template preview ─────────────────────────────────────────────
function openAddTplForFaction(faction) {
    document.getElementById('tpl_faction_key').value = faction;
    document.getElementById('tpl_prefix').value = '';
    document.getElementById('tpl_label').value = '';
    updateTplPreview();
    openModal('addTplModal');
}
function updateTplPreview() {
    const faction = document.getElementById('tpl_faction_key').value.toUpperCase() || 'FACTION';
    const prefix  = document.getElementById('tpl_prefix').value || 'prefix';
    document.getElementById('tpl_preview').textContent = `${prefix}@${faction.toLowerCase()}.us`;
}

// ── Renommer catégorie ───────────────────────────────────────────
function openRenameFaction(factionKey) {
    document.getElementById('rename_old_key').value    = factionKey;
    document.getElementById('rename_old_display').value = factionKey;
    document.getElementById('rename_new_key').value    = factionKey;
    openModal('renameFactionModal');
    setTimeout(() => document.getElementById('rename_new_key').select(), 60);
}

// ── Supprimer template (AJAX, sans confirmation) ─────────────────
async function deleteTemplate(tplId, hasAccount, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const body = new URLSearchParams({
        action: 'delete_faction_template',
        tpl_id: tplId,
        delete_account: hasAccount ? '1' : '0'
    });
    try {
        await fetch(location.href, { method:'POST', body });
        const card = document.getElementById('tpl-card-' + tplId);
        if (card) {
            card.style.transition = 'opacity .18s, transform .18s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(12px)';
            setTimeout(() => {
                card.remove();
                const group = document.querySelector('[data-faction-group]');
                if (group && group.querySelectorAll('[id^="tpl-card-"]').length === 0) {
                    group.style.opacity = '0';
                    group.style.transition = 'opacity .2s';
                    setTimeout(() => group.remove(), 200);
                }
            }, 200);
        }
        toast('Template supprimé', 'success', 'check');
    } catch(e) {
        toast('Erreur', 'error', 'xmark');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash"></i>';
    }
}
</script>