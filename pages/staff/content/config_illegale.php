<?php
/**
 * Gestion des accès illégaux (DarkNet) — Panel Staff.
 * Tables : darknet_illegal_accounts, darknet_subcodes, darknet_sessions, darknet_auth_log.
 * Prérequis : database/migration_darknet_system.sql exécuté.
 */
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';

// [DEBUG TEMP] Force l'affichage des erreurs (override MDT_DISPLAY_ERRORS=false)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

requireLogin();

require_once __DIR__ . '/../../../includes/darknet_api.php';

if (!darknet_staff_can_manage()) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Auto-migration des tables DarkNet (sans blocage)
darknet_ensure_schema($db);

$pageTitle  = 'Accès DarkNet';
$breadcrumb = [['label' => 'Contenu'], ['label' => 'Accès illégaux']];
$success = '';
$error   = '';
$flashCode = null; // {code: string, label: string, type: 'leader'|'sub'}

$csrf = darknet_ensure_csrf();

// ── Vérification schéma (post-migration) ───────────────────────────────────
$schemaOk = true;
try {
    $db->query("SELECT 1 FROM darknet_illegal_accounts LIMIT 1");
} catch (Throwable $e) {
    $schemaOk = false;
    $error = 'Migration DarkNet impossible : ' . $e->getMessage();
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($schemaOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf'] ?? '';
    if (!darknet_verify_csrf($tok)) {
        $error = 'Session expirée. Rechargez la page.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_account') {
                $res = darknet_create_account(
                    $db,
                    (string) ($_POST['faction_key'] ?? ''),
                    (string) ($_POST['grade'] ?? ''),
                    ($_POST['assigned_user_id'] ?? '') !== '' ? (int) $_POST['assigned_user_id'] : null,
                    ($_POST['label'] ?? '') !== '' ? (string) $_POST['label'] : null,
                    (int) ($_SESSION['user_id'] ?? 0)
                );
                $success = 'Compte illégal créé.';
                $flashCode = [
                    'type' => 'leader',
                    'code' => $res['code'],
                    'label' => 'Code principal — à communiquer UNE SEULE FOIS à son propriétaire.',
                ];
            } elseif ($action === 'update_account') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                darknet_update_account(
                    $db,
                    $id,
                    (string) ($_POST['grade'] ?? ''),
                    ($_POST['assigned_user_id'] ?? '') !== '' ? (int) $_POST['assigned_user_id'] : null,
                    ($_POST['label'] ?? '') !== '' ? (string) $_POST['label'] : null,
                    isset($_POST['is_active'])
                );
                $success = 'Compte mis à jour.';
            } elseif ($action === 'regen_account') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                $plain = darknet_regenerate_code($db, $id);
                $success = 'Nouveau code généré. L\'ancien est immédiatement invalidé.';
                $flashCode = [
                    'type' => 'leader',
                    'code' => $plain,
                    'label' => 'Nouveau code principal — remplace l\'ancien.',
                ];
            } elseif ($action === 'delete_account') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                darknet_delete_account($db, $id);
                $success = 'Compte supprimé (sous-codes également).';
            } elseif ($action === 'create_subcode') {
                $accId = (int) ($_POST['account_id'] ?? 0);
                if ($accId < 1) { throw new RuntimeException('ID compte invalide.'); }
                $res = darknet_create_subcode(
                    $db,
                    $accId,
                    ($_POST['member_label'] ?? '') !== '' ? (string) $_POST['member_label'] : null,
                    ($_POST['grade'] ?? '') !== '' ? (string) $_POST['grade'] : null,
                    ($_POST['assigned_user_id'] ?? '') !== '' ? (int) $_POST['assigned_user_id'] : null
                );
                $success = 'Sous-code créé.';
                $flashCode = [
                    'type' => 'sub',
                    'code' => $res['code'],
                    'label' => 'Code membre — à transmettre une seule fois.',
                ];
            } elseif ($action === 'regen_subcode') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                $plain = darknet_regenerate_subcode($db, $id);
                $success = 'Nouveau sous-code généré.';
                $flashCode = [
                    'type' => 'sub',
                    'code' => $plain,
                    'label' => 'Nouveau code membre.',
                ];
            } elseif ($action === 'delete_subcode') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) { throw new RuntimeException('ID invalide.'); }
                darknet_delete_subcode($db, $id);
                $success = 'Sous-code supprimé.';
            } else {
                $error = 'Action inconnue.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($success !== '') {
            $_SESSION['darknet_csrf'] = bin2hex(random_bytes(16));
            $csrf = $_SESSION['darknet_csrf'];
        }
    }
}

// ── Données pour l'affichage ────────────────────────────────────────────────
$factions = [];
$accounts = [];
try {
    if ($schemaOk) {
        $factions = darknet_get_illegal_factions($db);
    }
} catch (Throwable $e) {
    $error = $error ?: ('Erreur chargement factions : ' . $e->getMessage());
}
$factionFilter = isset($_GET['faction']) ? strtoupper(trim((string) $_GET['faction'])) : '';
try {
    if ($schemaOk) {
        $accounts = darknet_list_accounts($db, $factionFilter ?: null);
    }
} catch (Throwable $e) {
    $error = $error ?: ('Erreur chargement comptes : ' . $e->getMessage());
}

// Sous-codes : chargés à la demande, mais pour une vue en accordéon on précharge
$allSubcodes = [];
if ($schemaOk && $accounts !== []) {
    $ids = array_map(static fn ($a) => (int) $a['id'], $accounts);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT s.*, u.username AS assigned_username, p.firstname AS assigned_firstname, p.lastname AS assigned_lastname
         FROM darknet_subcodes s
         LEFT JOIN users u ON u.id = s.assigned_user_id
         LEFT JOIN profiles p ON p.id = u.profile_id
         WHERE s.account_id IN ($placeholders)
         ORDER BY s.created_at DESC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $allSubcodes[(int) $s['account_id']][] = $s;
    }
}

// Grades par faction (JS)
$gradesByFaction = [];
foreach ($factions as $f) {
    try {
        $gradesByFaction[$f['faction_key']] = array_column(
            darknet_faction_grades($db, $f['faction_key']),
            'name'
        );
    } catch (Throwable $e) {
        $gradesByFaction[$f['faction_key']] = [];
    }
}

// Stats globales
$stats = ['total' => 0, 'active' => 0, 'assigned' => 0, 'subcodes' => 0];
if ($schemaOk) {
    try {
        $stats['total']    = (int) $db->query("SELECT COUNT(*) FROM darknet_illegal_accounts")->fetchColumn();
        $stats['active']   = (int) $db->query("SELECT COUNT(*) FROM darknet_illegal_accounts WHERE is_active = 1")->fetchColumn();
        $stats['assigned'] = (int) $db->query("SELECT COUNT(*) FROM darknet_illegal_accounts WHERE assigned_user_id IS NOT NULL")->fetchColumn();
        $stats['subcodes'] = (int) $db->query("SELECT COUNT(*) FROM darknet_subcodes WHERE is_active = 1")->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
}

$extraCss = <<<'CSS'
/* ── DarkNet staff panel ─────────────────────────────────────────────────── */
.dk-hero{
    background:linear-gradient(135deg,rgba(124,58,237,.10),rgba(6,182,212,.06));
    border:1px solid rgba(124,58,237,.25);
    border-radius:16px;padding:22px 26px;margin-bottom:22px;
    display:flex;gap:24px;flex-wrap:wrap;align-items:center;justify-content:space-between;
    position:relative;overflow:hidden;
}
.dk-hero::before{content:'';position:absolute;inset:0;pointer-events:none;
    background:radial-gradient(circle at 85% 20%,rgba(124,58,237,.2),transparent 55%);}
.dk-hero h1{font-size:1.4rem;margin:0;display:flex;gap:10px;align-items:center;color:#e2e8f0}
.dk-hero h1 i{color:#a78bfa;filter:drop-shadow(0 0 8px rgba(167,139,250,.4))}
.dk-hero p{color:var(--text-secondary);margin:.35rem 0 0;font-size:.85rem;max-width:680px}
.dk-stats{display:flex;gap:12px;flex-wrap:wrap}
.dk-stat{background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.15);padding:10px 16px;border-radius:12px;min-width:96px}
.dk-stat .v{font-size:1.4rem;font-weight:800;color:#e2e8f0;line-height:1;font-family:'Syne',sans-serif}
.dk-stat .l{font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px}

.dk-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.dk-toolbar select,.dk-toolbar input[type=search]{
    background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.18);color:#e2e8f0;
    padding:.55rem .85rem;border-radius:10px;font-size:.85rem;
}
.dk-toolbar select:focus,.dk-toolbar input:focus{outline:none;border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}

.dk-table{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
.dk-table .row{display:grid;grid-template-columns:200px 140px 220px 110px 110px 1fr;gap:14px;padding:14px 20px;align-items:center;border-bottom:1px solid rgba(148,163,184,.08)}
.dk-table .row:last-child{border-bottom:none}
.dk-table .row.head{background:rgba(124,58,237,.06);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:1.2px;color:#94a3b8}
.dk-table .row:not(.head):hover{background:rgba(124,58,237,.04)}
.dk-faction{display:flex;align-items:center;gap:10px;font-weight:600;color:#e2e8f0;font-size:.9rem}
.dk-faction .fi{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.dk-grade{color:#cbd5e1;font-size:.85rem;font-weight:500}
.dk-assigned{font-size:.82rem;color:#94a3b8}
.dk-assigned strong{color:#e2e8f0;font-weight:600}
.dk-assigned.none{color:#64748b;font-style:italic}
.dk-code4{font-family:'JetBrains Mono','Consolas',monospace;background:rgba(15,23,42,.5);padding:4px 10px;border-radius:6px;color:#a78bfa;font-size:.8rem;border:1px solid rgba(167,139,250,.18)}
.dk-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.8px}
.dk-badge.on{background:rgba(34,197,94,.14);color:#86efac;border:1px solid rgba(34,197,94,.3)}
.dk-badge.off{background:rgba(239,68,68,.14);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
.dk-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.dk-btn{background:rgba(148,163,184,.1);border:1px solid rgba(148,163,184,.18);color:#cbd5e1;padding:6px 10px;border-radius:8px;cursor:pointer;font-size:.75rem;display:inline-flex;align-items:center;gap:5px;transition:all .15s;text-decoration:none}
.dk-btn:hover{background:rgba(148,163,184,.2);color:#fff;transform:translateY(-1px)}
.dk-btn.primary{background:rgba(124,58,237,.18);border-color:rgba(167,139,250,.4);color:#c4b5fd}
.dk-btn.primary:hover{background:rgba(124,58,237,.3);color:#fff}
.dk-btn.danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.28);color:#fca5a5}
.dk-btn.danger:hover{background:rgba(239,68,68,.2);color:#fff}
.dk-btn.success{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.28);color:#86efac}

/* Subcodes expansion */
.dk-subcodes{display:none;background:rgba(15,23,42,.45);border-top:1px solid rgba(148,163,184,.08);padding:14px 24px 18px}
.dk-subcodes.open{display:block}
.dk-subcodes h4{color:#a78bfa;font-size:.78rem;text-transform:uppercase;letter-spacing:1.4px;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.dk-sc-grid{display:grid;grid-template-columns:120px 1fr 140px 90px 80px 1fr;gap:10px;padding:8px 12px;align-items:center;border-radius:8px;font-size:.8rem;background:rgba(15,23,42,.4);border:1px solid rgba(148,163,184,.08);margin-bottom:6px}
.dk-sc-grid .l{color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.8px}
.dk-sc-add{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;padding:14px;background:rgba(124,58,237,.06);border:1px dashed rgba(167,139,250,.3);border-radius:10px}
.dk-sc-add input,.dk-sc-add select{background:rgba(15,23,42,.7);border:1px solid rgba(148,163,184,.15);color:#e2e8f0;padding:.45rem .7rem;border-radius:8px;font-size:.8rem}
.dk-sc-add input:focus,.dk-sc-add select:focus{outline:none;border-color:#a78bfa}

/* Modales */
.dk-modal-ov{position:fixed;inset:0;background:rgba(2,6,23,.85);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}
.dk-modal-ov.show{display:flex}
.dk-modal{background:linear-gradient(180deg,#131a2c,#0c1120);border:1px solid rgba(124,58,237,.3);border-radius:18px;width:min(560px,100%);max-height:90vh;overflow:auto;box-shadow:0 40px 80px rgba(0,0,0,.6)}
.dk-modal-head{padding:18px 22px;border-bottom:1px solid rgba(148,163,184,.1);display:flex;align-items:center;justify-content:space-between}
.dk-modal-head h3{color:#e2e8f0;font-size:1rem;display:flex;gap:10px;align-items:center;margin:0}
.dk-modal-head h3 i{color:#a78bfa}
.dk-modal-close{background:none;border:none;color:#64748b;font-size:1.2rem;cursor:pointer}
.dk-modal-close:hover{color:#e2e8f0}
.dk-modal-body{padding:20px 22px}
.dk-field{margin-bottom:14px}
.dk-field label{display:block;color:#94a3b8;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:600}
.dk-field input,.dk-field select,.dk-field textarea{width:100%;background:rgba(15,23,42,.7);border:1px solid rgba(148,163,184,.18);color:#e2e8f0;padding:.65rem .9rem;border-radius:10px;font-size:.88rem}
.dk-field input:focus,.dk-field select:focus{outline:none;border-color:#a78bfa;box-shadow:0 0 0 3px rgba(167,139,250,.15)}
.dk-modal-foot{padding:14px 22px;border-top:1px solid rgba(148,163,184,.1);display:flex;justify-content:flex-end;gap:10px}
.dk-btn-lg{padding:.65rem 1.25rem;font-size:.85rem;border-radius:10px}

/* Flash code */
.dk-flash-code{background:linear-gradient(135deg,rgba(34,197,94,.14),rgba(124,58,237,.1));border:1px solid rgba(167,139,250,.4);border-radius:14px;padding:20px 22px;margin-bottom:22px;position:relative}
.dk-flash-code h4{color:#c4b5fd;font-size:.78rem;text-transform:uppercase;letter-spacing:1.4px;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.dk-flash-code p{color:#cbd5e1;font-size:.85rem;margin-bottom:12px}
.dk-flash-value{display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.45);padding:10px 14px;border-radius:10px;border:1px solid rgba(167,139,250,.2)}
.dk-flash-value code{flex:1;font-family:'JetBrains Mono','Consolas',monospace;color:#fff;font-size:1.05rem;letter-spacing:2px;word-break:break-all}
.dk-flash-warn{color:#fbbf24;font-size:.72rem;margin-top:8px;display:flex;align-items:center;gap:6px}

/* Empty */
.dk-empty{padding:60px 20px;text-align:center;color:#64748b}
.dk-empty i{font-size:3rem;opacity:.3;margin-bottom:12px;display:block}

/* Typeahead user search */
.dk-user-ta{position:relative}
.dk-user-ta .suggest{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#0f172a;border:1px solid rgba(148,163,184,.2);border-radius:10px;max-height:200px;overflow:auto;z-index:10;display:none}
.dk-user-ta .suggest.show{display:block}
.dk-user-ta .suggest div{padding:8px 12px;cursor:pointer;font-size:.85rem;color:#cbd5e1;border-bottom:1px solid rgba(148,163,184,.06)}
.dk-user-ta .suggest div:hover{background:rgba(124,58,237,.12);color:#fff}
.dk-user-ta .chosen{display:flex;align-items:center;justify-content:space-between;padding:.55rem .9rem;background:rgba(124,58,237,.12);border:1px solid rgba(167,139,250,.35);border-radius:10px;color:#e2e8f0;font-size:.85rem;margin-top:6px}
.dk-user-ta .chosen .x{background:none;border:none;color:#fca5a5;cursor:pointer}

@media(max-width:1200px){
    .dk-table .row{grid-template-columns:1fr 1fr;gap:8px}
    .dk-table .row.head{display:none}
    .dk-table .row .dk-actions{grid-column:1/-1;justify-content:flex-start}
}
CSS;

include __DIR__ . '/../includes/header.php';
?>

<div class="dk-hero">
    <div>
        <h1><i class="fa-solid fa-user-secret"></i> Accès DarkNet — Factions illégales</h1>
        <p>Crée les comptes d'accès au DarkNet pour les chefs/bras-droits, régénère un code à la volée,
        surveille les sous-codes générés par les chefs, et suis l'activité par faction.</p>
    </div>
    <div class="dk-stats">
        <div class="dk-stat"><div class="v"><?= $stats['total']; ?></div><div class="l">Comptes</div></div>
        <div class="dk-stat"><div class="v"><?= $stats['active']; ?></div><div class="l">Actifs</div></div>
        <div class="dk-stat"><div class="v"><?= $stats['assigned']; ?></div><div class="l">Assignés</div></div>
        <div class="dk-stat"><div class="v"><?= $stats['subcodes']; ?></div><div class="l">Sous-codes</div></div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:14px;"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:14px;"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($flashCode !== null): ?>
<div class="dk-flash-code">
    <h4><i class="fa-solid fa-key"></i> <?= htmlspecialchars($flashCode['label']); ?></h4>
    <p>Ce code ne sera plus affiché par la suite. Note-le ou envoie-le immédiatement à son propriétaire.</p>
    <div class="dk-flash-value">
        <code id="flashCodeValue"><?= htmlspecialchars($flashCode['code']); ?></code>
        <button type="button" class="dk-btn success" onclick="navigator.clipboard.writeText(document.getElementById('flashCodeValue').textContent).then(()=>{this.innerHTML='<i class=\'fa-solid fa-check\'></i> Copié';});"><i class="fa-solid fa-copy"></i> Copier</button>
    </div>
    <div class="dk-flash-warn"><i class="fa-solid fa-triangle-exclamation"></i> À ne transmettre que via un canal sécurisé (RP / DM direct).</div>
</div>
<?php endif; ?>

<div class="dk-toolbar">
    <button type="button" class="dk-btn primary dk-btn-lg" onclick="DK.openCreate()"><i class="fa-solid fa-plus"></i> Ajouter un compte</button>
    <form method="get" style="display:flex;gap:10px;align-items:center;margin-left:auto;flex-wrap:wrap">
        <label for="factionFilter" style="color:#94a3b8;font-size:.72rem;text-transform:uppercase;letter-spacing:1.2px">Filtrer :</label>
        <select name="faction" id="factionFilter" onchange="this.form.submit()">
            <option value="">Toutes les factions</option>
            <?php foreach ($factions as $f): ?>
                <option value="<?= htmlspecialchars($f['faction_key']); ?>" <?= $factionFilter === $f['faction_key'] ? 'selected' : ''; ?>><?= htmlspecialchars($f['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="dk-table">
    <div class="row head">
        <div>Faction</div>
        <div>Grade</div>
        <div>Utilisateur assigné</div>
        <div>Code (****)</div>
        <div>Statut</div>
        <div style="text-align:right">Actions</div>
    </div>
    <?php if ($accounts === []): ?>
        <div class="dk-empty">
            <i class="fa-solid fa-ghost"></i>
            <p>Aucun compte illégal pour le moment.</p>
            <button type="button" class="dk-btn primary" onclick="DK.openCreate()" style="margin-top:12px"><i class="fa-solid fa-plus"></i> Créer le premier</button>
        </div>
    <?php else: foreach ($accounts as $acc):
        $fcolor = $acc['faction_color'] ?: '#7c3aed';
        $ficon = $acc['faction_icon'] ? 'fa-solid fa-' . $acc['faction_icon'] : 'fa-solid fa-user-secret';
        $assignedName = '';
        if ($acc['assigned_user_id']) {
            $assignedName = trim(($acc['assigned_firstname'] ?? '') . ' ' . ($acc['assigned_lastname'] ?? ''));
            if ($assignedName === '') { $assignedName = $acc['assigned_username'] ?? '—'; }
        }
        $subs = $allSubcodes[(int) $acc['id']] ?? [];
    ?>
    <div class="row" data-acc="<?= (int) $acc['id']; ?>">
        <div class="dk-faction">
            <div class="fi" style="background:<?= htmlspecialchars($fcolor); ?>22;border:1px solid <?= htmlspecialchars($fcolor); ?>55;color:<?= htmlspecialchars($fcolor); ?>"><i class="<?= htmlspecialchars($ficon); ?>"></i></div>
            <div>
                <?= htmlspecialchars($acc['faction_name'] ?? $acc['faction_key']); ?>
                <?php if ($acc['label']): ?><div style="font-size:.72rem;color:#64748b;font-weight:400;margin-top:2px"><?= htmlspecialchars($acc['label']); ?></div><?php endif; ?>
            </div>
        </div>
        <div class="dk-grade"><?= htmlspecialchars($acc['grade']); ?></div>
        <div class="dk-assigned <?= $assignedName === '' ? 'none' : ''; ?>">
            <?php if ($assignedName !== ''): ?>
                <strong><?= htmlspecialchars($assignedName); ?></strong>
                <div style="font-size:.7rem;color:#64748b">@<?= htmlspecialchars($acc['assigned_username'] ?? ''); ?></div>
            <?php else: ?>non assigné<?php endif; ?>
        </div>
        <div><span class="dk-code4">…<?= htmlspecialchars($acc['code_last4']); ?></span></div>
        <div><span class="dk-badge <?= $acc['is_active'] ? 'on' : 'off'; ?>"><?= $acc['is_active'] ? 'Actif' : 'Inactif'; ?></span></div>
        <div class="dk-actions">
            <button type="button" class="dk-btn" onclick="DK.toggleSub(<?= (int) $acc['id']; ?>)" title="Sous-codes (<?= count($subs); ?>)"><i class="fa-solid fa-users"></i> <?= count($subs); ?></button>
            <button type="button" class="dk-btn primary" onclick='DK.openEdit(<?= json_encode([
                'id' => (int) $acc['id'],
                'faction_key' => $acc['faction_key'],
                'grade' => $acc['grade'],
                'label' => $acc['label'],
                'assigned_user_id' => $acc['assigned_user_id'],
                'assigned_username' => $acc['assigned_username'] ?? '',
                'assigned_display' => $assignedName,
                'is_active' => (int) $acc['is_active'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Modifier"><i class="fa-solid fa-pen"></i></button>
            <form method="post" style="display:inline" onsubmit="return DK.confirm('Régénérer le code de ce compte ? L\'ancien sera invalidé.')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="regen_account">
                <input type="hidden" name="id" value="<?= (int) $acc['id']; ?>">
                <button type="submit" class="dk-btn" title="Régénérer le code"><i class="fa-solid fa-rotate"></i></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return DK.confirm('Supprimer ce compte et tous ses sous-codes ? Action irréversible.')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="delete_account">
                <input type="hidden" name="id" value="<?= (int) $acc['id']; ?>">
                <button type="submit" class="dk-btn danger" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
            </form>
        </div>
    </div>

    <!-- Sous-codes -->
    <div class="dk-subcodes" id="sub-<?= (int) $acc['id']; ?>">
        <h4><i class="fa-solid fa-key"></i> Sous-codes (<?= count($subs); ?>)</h4>
        <?php if ($subs === []): ?>
            <div style="color:#64748b;font-size:.82rem;padding:10px 0">Aucun sous-code généré pour l'instant par ce chef.</div>
        <?php else: foreach ($subs as $s):
            $sname = '';
            if ($s['assigned_user_id']) {
                $sname = trim(($s['assigned_firstname'] ?? '') . ' ' . ($s['assigned_lastname'] ?? ''));
                if ($sname === '') { $sname = $s['assigned_username'] ?? '—'; }
            }
        ?>
            <div class="dk-sc-grid">
                <span class="dk-code4">…<?= htmlspecialchars($s['code_last4']); ?></span>
                <div><?= htmlspecialchars($s['member_label'] ?? '—'); ?>
                    <?php if ($sname !== ''): ?><div style="font-size:.7rem;color:#94a3b8;margin-top:2px">→ <?= htmlspecialchars($sname); ?> (@<?= htmlspecialchars($s['assigned_username'] ?? ''); ?>)</div><?php endif; ?>
                </div>
                <div style="color:#cbd5e1"><?= htmlspecialchars($s['grade'] ?? '—'); ?></div>
                <span class="dk-badge <?= $s['is_active'] ? 'on' : 'off'; ?>" style="font-size:.62rem"><?= $s['is_active'] ? 'OK' : 'OFF'; ?></span>
                <div style="color:#64748b;font-size:.72rem"><?php $ts = $s['created_at'] ? strtotime($s['created_at']) : false; echo $ts ? htmlspecialchars(date('d/m H:i', $ts)) : '—'; ?></div>
                <div class="dk-actions">
                    <form method="post" style="display:inline" onsubmit="return DK.confirm('Régénérer ce sous-code ?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="regen_subcode">
                        <input type="hidden" name="id" value="<?= (int) $s['id']; ?>">
                        <button type="submit" class="dk-btn" title="Régénérer"><i class="fa-solid fa-rotate"></i></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return DK.confirm('Supprimer ce sous-code ?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="delete_subcode">
                        <input type="hidden" name="id" value="<?= (int) $s['id']; ?>">
                        <button type="submit" class="dk-btn danger"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>

        <div class="dk-sc-add">
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;width:100%">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="create_subcode">
                <input type="hidden" name="account_id" value="<?= (int) $acc['id']; ?>">
                <input type="text" name="member_label" placeholder="Libellé membre (ex. Tony)" maxlength="120" style="flex:1;min-width:180px">
                <select name="grade" style="width:160px">
                    <option value="">Grade (optionnel)</option>
                    <?php foreach (($gradesByFaction[$acc['faction_key']] ?? []) as $g): ?>
                        <option value="<?= htmlspecialchars($g); ?>"><?= htmlspecialchars($g); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="dk-btn primary"><i class="fa-solid fa-plus"></i> Générer un sous-code</button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ── Modale Création / Édition compte ─────────────────────────────────── -->
<div class="dk-modal-ov" id="dkAccountModal">
    <div class="dk-modal">
        <form method="post" id="dkAccountForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" id="dkAction" value="create_account">
            <input type="hidden" name="id" id="dkId" value="">
            <input type="hidden" name="assigned_user_id" id="dkAssignedUserId" value="">
            <div class="dk-modal-head">
                <h3 id="dkModalTitle"><i class="fa-solid fa-user-secret"></i> Nouveau compte illégal</h3>
                <button type="button" class="dk-modal-close" onclick="DK.closeModal()">&times;</button>
            </div>
            <div class="dk-modal-body">
                <div class="dk-field">
                    <label>Faction illégale</label>
                    <select name="faction_key" id="dkFaction" required onchange="DK.onFactionChange()">
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($factions as $f): ?>
                            <option value="<?= htmlspecialchars($f['faction_key']); ?>"><?= htmlspecialchars($f['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dk-field">
                    <label>Grade</label>
                    <select name="grade" id="dkGrade" required>
                        <option value="">— Choisir une faction d'abord —</option>
                    </select>
                </div>
                <div class="dk-field">
                    <label>Libellé (facultatif — pour repérage interne)</label>
                    <input type="text" name="label" id="dkLabel" maxlength="120" placeholder="Ex. : Chef territoire Grove">
                </div>
                <div class="dk-field dk-user-ta">
                    <label>Utilisateur assigné (facultatif)</label>
                    <input type="text" id="dkUserSearch" placeholder="Rechercher un utilisateur…" autocomplete="off">
                    <div class="suggest" id="dkUserSuggest"></div>
                    <div class="chosen" id="dkUserChosen" style="display:none">
                        <span id="dkUserChosenText"></span>
                        <button type="button" class="x" onclick="DK.clearUser()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div class="dk-field" id="dkActiveWrap" style="display:none">
                    <label><input type="checkbox" name="is_active" id="dkActive" checked style="width:auto;margin-right:8px">Compte actif</label>
                </div>
            </div>
            <div class="dk-modal-foot">
                <button type="button" class="dk-btn dk-btn-lg" onclick="DK.closeModal()">Annuler</button>
                <button type="submit" class="dk-btn primary dk-btn-lg" id="dkSubmitBtn"><i class="fa-solid fa-key"></i> Créer &amp; générer code</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modale confirmation ──────────────────────────────────────────────── -->
<div class="dk-modal-ov" id="dkConfirmModal">
    <div class="dk-modal" style="width:min(440px,100%)">
        <div class="dk-modal-head">
            <h3><i class="fa-solid fa-triangle-exclamation" style="color:#fbbf24"></i> Confirmation</h3>
        </div>
        <div class="dk-modal-body">
            <p id="dkConfirmMsg" style="color:#cbd5e1;font-size:.92rem;line-height:1.55"></p>
        </div>
        <div class="dk-modal-foot">
            <button type="button" class="dk-btn dk-btn-lg" onclick="DK.confirmResolve(false)">Annuler</button>
            <button type="button" class="dk-btn danger dk-btn-lg" onclick="DK.confirmResolve(true)"><i class="fa-solid fa-check"></i> Confirmer</button>
        </div>
    </div>
</div>

<script>
const DK = {
    gradesByFaction: <?= json_encode($gradesByFaction, JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    userSearchTimer: null,
    confirmCb: null,

    openCreate(){
        document.getElementById('dkModalTitle').innerHTML='<i class="fa-solid fa-user-secret"></i> Nouveau compte illégal';
        document.getElementById('dkAction').value='create_account';
        document.getElementById('dkId').value='';
        document.getElementById('dkFaction').value='';
        document.getElementById('dkFaction').disabled=false;
        document.getElementById('dkGrade').innerHTML='<option value="">— Choisir une faction d\'abord —</option>';
        document.getElementById('dkLabel').value='';
        document.getElementById('dkUserSearch').value='';
        document.getElementById('dkAssignedUserId').value='';
        document.getElementById('dkUserChosen').style.display='none';
        document.getElementById('dkActiveWrap').style.display='none';
        document.getElementById('dkSubmitBtn').innerHTML='<i class="fa-solid fa-key"></i> Créer & générer code';
        document.getElementById('dkAccountModal').classList.add('show');
    },

    openEdit(data){
        document.getElementById('dkModalTitle').innerHTML='<i class="fa-solid fa-pen"></i> Modifier le compte';
        document.getElementById('dkAction').value='update_account';
        document.getElementById('dkId').value=data.id;
        document.getElementById('dkFaction').value=data.faction_key;
        document.getElementById('dkFaction').disabled=true;
        DK.onFactionChange();
        setTimeout(()=>{ document.getElementById('dkGrade').value=data.grade; },50);
        document.getElementById('dkLabel').value=data.label||'';
        document.getElementById('dkAssignedUserId').value=data.assigned_user_id||'';
        if (data.assigned_user_id && data.assigned_display){
            document.getElementById('dkUserChosenText').textContent=data.assigned_display+' (@'+data.assigned_username+')';
            document.getElementById('dkUserChosen').style.display='flex';
            document.getElementById('dkUserSearch').value='';
        } else {
            document.getElementById('dkUserChosen').style.display='none';
            document.getElementById('dkUserSearch').value='';
        }
        document.getElementById('dkActiveWrap').style.display='block';
        document.getElementById('dkActive').checked=!!data.is_active;
        document.getElementById('dkSubmitBtn').innerHTML='<i class="fa-solid fa-save"></i> Enregistrer';
        document.getElementById('dkAccountModal').classList.add('show');
    },

    closeModal(){ document.getElementById('dkAccountModal').classList.remove('show'); },

    onFactionChange(){
        const f=document.getElementById('dkFaction').value;
        const sel=document.getElementById('dkGrade');
        const grades=DK.gradesByFaction[f]||[];
        if (grades.length===0){
            sel.innerHTML='<option value="">Aucun grade défini pour cette faction</option>';
        } else {
            sel.innerHTML='<option value="">— Choisir un grade —</option>'+grades.map(g=>`<option value="${g}">${g}</option>`).join('');
        }
    },

    toggleSub(id){
        const e=document.getElementById('sub-'+id);
        if (!e) return;
        e.classList.toggle('open');
    },

    searchUsers(){
        const q=document.getElementById('dkUserSearch').value.trim();
        const f=document.getElementById('dkFaction').value;
        const sugg=document.getElementById('dkUserSuggest');
        clearTimeout(DK.userSearchTimer);
        if (q.length<2){ sugg.classList.remove('show'); return; }
        DK.userSearchTimer=setTimeout(()=>{
            fetch('<?= SITE_URL; ?>/pages/staff/ajax/darknet_user_search.php?q='+encodeURIComponent(q)+'&faction='+encodeURIComponent(f))
                .then(r=>r.json()).then(d=>{
                    if (!d.ok||!d.users||d.users.length===0){ sugg.innerHTML='<div style="color:#64748b;cursor:default">Aucun résultat</div>'; sugg.classList.add('show'); return; }
                    sugg.innerHTML=d.users.map(u=>{
                        const name=((u.firstname||'')+' '+(u.lastname||'')).trim()||u.username;
                        return `<div data-id="${u.id}" data-name="${name} (@${u.username})" onclick="DK.pickUser(${u.id},'${name.replace(/'/g,'&#39;')} (@${u.username})')">${name} <span style="color:#64748b">@${u.username}${u.faction?' · '+u.faction:''}</span></div>`;
                    }).join('');
                    sugg.classList.add('show');
                });
        },250);
    },

    pickUser(id,label){
        document.getElementById('dkAssignedUserId').value=id;
        document.getElementById('dkUserChosenText').textContent=label;
        document.getElementById('dkUserChosen').style.display='flex';
        document.getElementById('dkUserSuggest').classList.remove('show');
        document.getElementById('dkUserSearch').value='';
    },

    clearUser(){
        document.getElementById('dkAssignedUserId').value='';
        document.getElementById('dkUserChosen').style.display='none';
    },

    confirm(msg){
        return new Promise((resolve)=>{
            document.getElementById('dkConfirmMsg').textContent=msg;
            document.getElementById('dkConfirmModal').classList.add('show');
            DK.confirmCb=resolve;
        });
    },

    confirmResolve(ok){
        document.getElementById('dkConfirmModal').classList.remove('show');
        if (DK.confirmCb) DK.confirmCb(ok);
        DK.confirmCb=null;
    },
};

document.addEventListener('DOMContentLoaded',()=>{
    document.getElementById('dkUserSearch').addEventListener('input',DK.searchUsers);
    // Intercept form submits with onsubmit="return DK.confirm(...)" — convert to async
    document.querySelectorAll('form[onsubmit^="return DK.confirm"]').forEach(f=>{
        const origAttr=f.getAttribute('onsubmit');
        const msgMatch=origAttr.match(/DK\.confirm\((['"])([\s\S]*?)\1\)/);
        if (!msgMatch) return;
        const msg=msgMatch[2].replace(/\\'/g,"'");
        f.removeAttribute('onsubmit');
        f.addEventListener('submit',async (e)=>{
            if (f.dataset.confirmed) return;
            e.preventDefault();
            const ok=await DK.confirm(msg);
            if (ok){ f.dataset.confirmed='1'; f.submit(); }
        });
    });
    // Close modal on overlay click
    document.querySelectorAll('.dk-modal-ov').forEach(ov=>{
        ov.addEventListener('click',(e)=>{ if (e.target===ov) ov.classList.remove('show'); });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
