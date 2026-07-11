<?php
// pages/staff/logs.php — journal d’audit global
declare(strict_types=1);

define('STAFF_PANEL', true);
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

function mdt_logs_viewer_sees_all_ips(): bool
{
    return hasRole(['*', 'Administrateur', 'Développeur']);
}

function mdt_logs_format_ip_for_viewer(?string $ip, ?int $logUserId, ?string $logUserRole): string
{
    $raw = trim((string) ($ip ?? ''));
    if ($raw === '') {
        return '—';
    }
    if (mdt_logs_viewer_sees_all_ips()) {
        return $raw;
    }
    $viewerRole = trim((string) ($_SESSION['user_role'] ?? 'Citoyen'));
    $viewerRank = mdt_role_hierarchy_rank($viewerRole);
    if (!$logUserId) {
        return $raw;
    }
    $subjectRole = trim((string) ($logUserRole ?? ''));
    if ($subjectRole === '') {
        return $raw;
    }
    $subjectRank = mdt_role_hierarchy_rank($subjectRole);
    if ($subjectRank <= $viewerRank) {
        return $raw;
    }
    return '*** masqué ***';
}

function mdt_logs_can_purge(): bool
{
    return hasRole(['*', 'Administrateur', 'Développeur']);
}

$db = Database::getInstance()->getConnection();

if (!isset($_SESSION['mdt_logs_csrf'])) {
    $_SESSION['mdt_logs_csrf'] = bin2hex(random_bytes(16));
}

$flashOk = $flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'purge_logs') {
    if (!mdt_logs_can_purge()) {
        $flashErr = 'Action réservée aux administrateurs et développeurs.';
    } elseif (!hash_equals($_SESSION['mdt_logs_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $flashErr = 'Jeton de sécurité invalide. Rechargez la page.';
    } elseif (trim((string) ($_POST['purge_confirm'] ?? '')) !== 'SUPPRIMER LOGS') {
        $flashErr = 'Confirmation incorrecte (tapez exactement : SUPPRIMER LOGS).';
    } else {
        $pFrom = trim((string) ($_POST['purge_from'] ?? ''));
        $pTo   = trim((string) ($_POST['purge_to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pTo)) {
            $flashErr = 'Dates de purge invalides.';
        } elseif ($pFrom > $pTo) {
            $flashErr = 'La date de début doit précéder la date de fin.';
        } else {
            try {
                $tStart = $pFrom . ' 00:00:00';
                $tEndEx = date('Y-m-d', strtotime($pTo . ' +1 day')) . ' 00:00:00';
                $cntSt = $db->prepare('SELECT COUNT(*) FROM logs WHERE created_at >= ? AND created_at < ?');
                $cntSt->execute([$tStart, $tEndEx]);
                $n = (int) $cntSt->fetchColumn();
                $del = $db->prepare('DELETE FROM logs WHERE created_at >= ? AND created_at < ?');
                $del->execute([$tStart, $tEndEx]);
                $_SESSION['mdt_logs_csrf'] = bin2hex(random_bytes(16));
                mdt_audit_log(
                    (int) $_SESSION['user_id'],
                    'STAFF',
                    'Purge journal audit',
                    $n . ' entrée(s) supprimées entre ' . $pFrom . ' et ' . $pTo,
                    'system',
                    null
                );
                $flashOk = $n . ' entrée(s) de log supprimées pour la période sélectionnée.';
            } catch (Throwable $e) {
                $flashErr = 'Erreur lors de la purge : ' . $e->getMessage();
            }
        }
    }
}

$pageTitle  = 'Logs & audit';
$breadcrumb = [['label' => 'Logs']];

$search       = trim($_GET['q'] ?? '');
$panel        = trim($_GET['panel'] ?? '');
$action       = trim($_GET['action_filter'] ?? '');
$dateFrom     = trim($_GET['from'] ?? '');
$dateTo       = trim($_GET['to'] ?? '');
$filterUserId = (int) ($_GET['user_id'] ?? 0);
$filterRole   = trim($_GET['role'] ?? '');
$filterFaction = trim($_GET['faction'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$logSchema = mdt_audit_log_schema($db);
$hasCategoryCol = $logSchema['category'];
$hasTargetFactionCol = $logSchema['target_faction'];

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(l.action LIKE ? OR l.description LIKE ? OR u.username LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($panel !== '') {
    $where[] = 'l.panel = ?';
    $params[] = $panel;
}
if ($action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(l.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(l.created_at) <= ?';
    $params[] = $dateTo;
}
if ($filterUserId > 0) {
    $where[] = 'l.user_id = ?';
    $params[] = $filterUserId;
}
if ($filterRole !== '') {
    $where[] = 'u.role = ?';
    $params[] = $filterRole;
}
if ($filterFaction !== '') {
    if ($hasTargetFactionCol) {
        $where[] = '(u.faction = ? OR l.target_faction = ?)';
        $params[] = $filterFaction;
        $params[] = $filterFaction;
    } else {
        $where[] = 'u.faction = ?';
        $params[] = $filterFaction;
    }
}
if ($filterCategory !== '' && $hasCategoryCol) {
    $where[] = 'l.category = ?';
    $params[] = $filterCategory;
}

$wStr = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = [];
$total = 0;
$totalPages = 1;
$panels = [];
$actions = [];
$logUsers = [];
$rolesDistinct = [];
$factionsDistinct = [];
$categoriesDistinct = [];

try {
    $cnt = $db->prepare("SELECT COUNT(*) FROM logs l LEFT JOIN users u ON l.user_id = u.id $wStr");
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $db->prepare("
        SELECT l.*, u.username, u.avatar, u.role AS log_user_role, u.faction AS log_user_faction
        FROM logs l
        LEFT JOIN users u ON l.user_id = u.id
        $wStr
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $panels = $db->query("SELECT DISTINCT panel FROM logs WHERE panel IS NOT NULL AND panel != '' ORDER BY panel LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $actions = $db->query('SELECT DISTINCT action FROM logs ORDER BY action LIMIT 200')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $logUsers = $db->query("
        SELECT DISTINCT u.id, u.username FROM logs l
        INNER JOIN users u ON l.user_id = u.id
        ORDER BY u.username ASC
        LIMIT 400
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rolesDistinct = $db->query("
        SELECT DISTINCT u.role FROM logs l
        INNER JOIN users u ON l.user_id = u.id
        WHERE u.role IS NOT NULL AND u.role != ''
        ORDER BY u.role ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $factionsDistinct = $db->query("
        SELECT DISTINCT u.faction FROM logs l
        INNER JOIN users u ON l.user_id = u.id
        WHERE u.faction IS NOT NULL AND u.faction NOT IN ('','NONE')
        ORDER BY u.faction ASC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if ($hasCategoryCol) {
        $categoriesDistinct = $db->query("SELECT DISTINCT category FROM logs WHERE category IS NOT NULL AND category != '' ORDER BY category LIMIT 50")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (PDOException $e) {
    $logs = [];
    $flashErr = $flashErr ?: ('Erreur chargement des logs : ' . $e->getMessage());
}

$stats = ['today' => 0, 'week' => 0, 'total' => 0];
try {
    $stats['today'] = (int) $db->query("SELECT COUNT(*) FROM logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['week'] = (int) $db->query("SELECT COUNT(*) FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['total'] = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
} catch (Throwable $e) {
}

$qBase = [
    'q' => $search,
    'panel' => $panel,
    'action_filter' => $action,
    'from' => $dateFrom,
    'to' => $dateTo,
    'user_id' => $filterUserId > 0 ? (string) $filterUserId : '',
    'role' => $filterRole,
    'faction' => $filterFaction,
    'category' => $filterCategory,
];
$buildPageUrl = static function (int $p) use ($qBase): string {
    $q = array_filter($qBase, static fn ($v) => $v !== null && $v !== '');
    $q['page'] = (string) $p;
    return '?' . http_build_query($q);
};

$catColors = [
    'auth'       => '#38bdf8',
    'user'       => '#a78bfa',
    'faction'    => '#34d399',
    'division'   => '#f472b6',
    'security'   => '#fb7185',
    'billing'    => '#fbbf24',
    'vehicle'    => '#94a3b8',
    'panel'      => '#60a5fa',
    'system'     => '#94a3b8',
    'error'      => '#f87171',
    'other'      => '#64748b',
];

include __DIR__ . '/includes/header.php';
?>

<style>
.mdtlog-wrap { max-width: 1480px; margin: 0 auto; }
.mdtlog-hero {
    display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 20px;
    margin-bottom: 22px; padding: 22px 24px;
    background: linear-gradient(135deg, rgba(59,130,246,.12) 0%, rgba(139,92,246,.08) 50%, transparent 100%);
    border: 1px solid var(--border); border-radius: 16px;
}
.mdtlog-hero h1 { font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 6px 0; display: flex; align-items: center; gap: 12px; }
.mdtlog-hero p { margin: 0; color: var(--text-secondary); font-size: .9rem; }
.mdtlog-stats { display: flex; gap: 12px; flex-wrap: wrap; }
.mdtlog-stat {
    min-width: 110px; padding: 12px 16px; border-radius: 12px;
    background: var(--bg-elevated); border: 1px solid var(--border); text-align: center;
}
.mdtlog-stat .n { font-size: 1.35rem; font-weight: 800; font-family: 'DM Mono', monospace; color: var(--blue); line-height: 1.1; }
.mdtlog-stat .l { font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-top: 4px; }

.mdtlog-filters {
    background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 14px;
    padding: 18px 20px; margin-bottom: 18px;
}
.mdtlog-fgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 10px 12px;
    align-items: end;
}
.mdtlog-fgrid .span-2 { grid-column: span 2; }
@media (max-width: 720px) { .mdtlog-fgrid .span-2 { grid-column: span 1; } }
.mdtlog-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 5px; display: block; }
.mdtlog-input, .mdtlog-select {
    width: 100%; padding: 9px 11px; border-radius: 9px; border: 1px solid var(--border);
    background: var(--bg-base); color: var(--text-primary); font-size: .86rem; font-family: inherit;
}
.mdtlog-input:focus, .mdtlog-select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.mdtlog-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; align-items: center; }
.mdtlog-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 9px;
    font-weight: 600; font-size: .84rem; border: none; cursor: pointer; text-decoration: none; font-family: inherit;
}
.mdtlog-btn-primary { background: var(--blue); color: #fff; }
.mdtlog-btn-primary:hover { filter: brightness(1.08); }
.mdtlog-btn-ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
.mdtlog-btn-ghost:hover { background: var(--bg-hover); color: var(--text-primary); }

.mdtlog-card { background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.mdtlog-table-wrap { overflow-x: auto; }
.mdtlog-table { width: 100%; border-collapse: collapse; min-width: 920px; }
.mdtlog-table th {
    text-align: left; padding: 11px 14px; font-size: .68rem; text-transform: uppercase; letter-spacing: .07em;
    color: var(--text-muted); background: rgba(0,0,0,.2); border-bottom: 1px solid var(--border); white-space: nowrap;
}
.mdtlog-table td { padding: 11px 14px; border-bottom: 1px solid rgba(255,255,255,.04); vertical-align: middle; font-size: .83rem; }
.mdtlog-table tbody tr:hover { background: rgba(255,255,255,.02); }
.mdtlog-mono { font-family: 'DM Mono', monospace; font-size: .74rem; color: var(--text-secondary); }
.mdtlog-desc { max-width: 340px; color: var(--text-secondary); line-height: 1.45; word-break: break-word; }
.mdtlog-badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
    font-size: .72rem; font-weight: 700; white-space: nowrap;
}
.mdtlog-usercell { display: flex; align-items: center; gap: 8px; }
.mdtlog-ava {
    width: 30px; height: 30px; border-radius: 8px; overflow: hidden; flex-shrink: 0;
    background: var(--blue); color: #fff; font-weight: 700; font-size: .72rem; display: grid; place-items: center;
}
.mdtlog-ava img { width: 100%; height: 100%; object-fit: cover; }

.mdtlog-pager {
    padding: 14px 18px; border-top: 1px solid var(--border);
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; font-size: .83rem;
}

.mdtlog-purge {
    margin-top: 18px; padding: 18px; border-radius: 14px;
    border: 1px dashed rgba(239,68,68,.35); background: rgba(239,68,68,.06);
}
.mdtlog-purge h3 { font-size: .95rem; margin: 0 0 8px 0; color: #fca5a5; display: flex; align-items: center; gap: 8px; }
.mdtlog-purge p { font-size: .8rem; color: var(--text-secondary); margin: 0 0 12px 0; max-width: 720px; line-height: 1.5; }
.mdtlog-pgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-width: 480px; }
@media (max-width: 600px) { .mdtlog-pgrid { grid-template-columns: 1fr; } }

.mdtlog-flash-ok { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25); color: #4ade80; padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; font-size: .88rem; }
.mdtlog-flash-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #fca5a5; padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; font-size: .88rem; }
</style>

<div class="mdtlog-wrap">
    <div class="mdtlog-hero">
        <div>
            <h1><i class="fa-solid fa-shield-halved" style="color:var(--blue);"></i> Journal d’audit</h1>
            <p>Historique centralisé des actions sur le MDT — filtres avancés, export visuel, conformité IP selon la hiérarchie.</p>
        </div>
        <div class="mdtlog-stats">
            <div class="mdtlog-stat"><div class="n"><?php echo number_format($stats['today']); ?></div><div class="l">Aujourd’hui</div></div>
            <div class="mdtlog-stat"><div class="n"><?php echo number_format($stats['week']); ?></div><div class="l">7 jours</div></div>
            <div class="mdtlog-stat"><div class="n"><?php echo number_format($stats['total']); ?></div><div class="l">Total base</div></div>
        </div>
    </div>

    <?php if ($flashOk): ?><div class="mdtlog-flash-ok"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashOk); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="mdtlog-flash-err"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($flashErr); ?></div><?php endif; ?>

    <form class="mdtlog-filters" method="get" action="">
        <input type="hidden" name="page" value="1">
        <div class="mdtlog-fgrid">
            <div class="span-2">
                <label class="mdtlog-label">Recherche libre</label>
                <input class="mdtlog-input" type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Action, description, pseudo…">
            </div>
            <div>
                <label class="mdtlog-label">Utilisateur</label>
                <select class="mdtlog-select" name="user_id">
                    <option value="">— Tous —</option>
                    <?php foreach ($logUsers as $lu): ?>
                        <option value="<?php echo (int) $lu['id']; ?>" <?php echo $filterUserId === (int) $lu['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($lu['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mdtlog-label">Rôle (auteur)</label>
                <select class="mdtlog-select" name="role">
                    <option value="">— Tous —</option>
                    <?php foreach ($rolesDistinct as $r): ?>
                        <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $filterRole === $r ? 'selected' : ''; ?>><?php echo htmlspecialchars($r); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mdtlog-label">Faction (auteur)</label>
                <select class="mdtlog-select" name="faction">
                    <option value="">— Toutes —</option>
                    <?php foreach ($factionsDistinct as $f): ?>
                        <option value="<?php echo htmlspecialchars($f); ?>" <?php echo $filterFaction === $f ? 'selected' : ''; ?>><?php echo htmlspecialchars($f); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasCategoryCol): ?>
            <div>
                <label class="mdtlog-label">Catégorie</label>
                <select class="mdtlog-select" name="category">
                    <option value="">— Toutes —</option>
                    <?php foreach ($categoriesDistinct as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filterCategory === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="mdtlog-label">Panel</label>
                <select class="mdtlog-select" name="panel">
                    <option value="">— Tous —</option>
                    <?php foreach ($panels as $p): ?>
                        <option value="<?php echo htmlspecialchars((string) $p); ?>" <?php echo $panel === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mdtlog-label">Type d’action</label>
                <select class="mdtlog-select" name="action_filter">
                    <option value="">— Toutes —</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo htmlspecialchars((string) $a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mdtlog-label">Du</label>
                <input class="mdtlog-input" type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div>
                <label class="mdtlog-label">Au</label>
                <input class="mdtlog-input" type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
        </div>
        <div class="mdtlog-actions">
            <button type="submit" class="mdtlog-btn mdtlog-btn-primary"><i class="fa-solid fa-filter"></i> Appliquer les filtres</button>
            <a href="logs.php" class="mdtlog-btn mdtlog-btn-ghost"><i class="fa-solid fa-rotate-left"></i> Réinitialiser</a>
            <span style="margin-left:auto;color:var(--text-muted);font-size:.8rem;">
                <?php echo number_format($total); ?> résultat(s) · page <?php echo $page; ?>/<?php echo $totalPages; ?>
            </span>
        </div>
    </form>

    <div class="mdtlog-card">
        <div class="mdtlog-table-wrap">
            <table class="mdtlog-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Auteur</th>
                        <?php if ($hasCategoryCol): ?><th>Cat.</th><?php endif; ?>
                        <th>Panel</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Faction</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs === []): ?>
                    <tr><td colspan="<?php echo $hasCategoryCol ? 9 : 8; ?>" style="text-align:center;padding:40px;color:var(--text-secondary);">Aucune entrée pour ces critères.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $cat = $hasCategoryCol ? (string) ($log['category'] ?? '') : '';
                        $cCol = $catColors[$cat] ?? '#64748b';
                        $pU = strtoupper((string) ($log['panel'] ?? ''));
                        $pBadge = match ($pU) {
                            'STAFF', 'ADMIN' => 'rgba(168,85,247,.2)',
                            'LSPD' => 'rgba(59,130,246,.2)',
                            'LSSD' => 'rgba(16,185,129,.2)',
                            'SAMS', 'DOJ' => 'rgba(239,68,68,.18)',
                            'AUTH', 'PROFILE' => 'rgba(6,182,212,.18)',
                            default => 'rgba(100,116,139,.2)',
                        };
                        ?>
                    <tr>
                        <td class="mdtlog-mono"><?php echo (int) $log['id']; ?></td>
                        <td class="mdtlog-mono" style="white-space:nowrap;"><?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime((string) $log['created_at']))); ?></td>
                        <td>
                            <div class="mdtlog-usercell">
                                <div class="mdtlog-ava">
                                    <?php if (!empty($log['avatar'])): ?>
                                        <img src="<?php echo htmlspecialchars((string) $log['avatar']); ?>" alt="">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr((string) ($log['username'] ?? 'S'), 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars((string) ($log['username'] ?? '—')); ?></div>
                                    <div class="mdtlog-mono" style="opacity:.75;"><?php echo htmlspecialchars((string) ($log['log_user_role'] ?? '')); ?></div>
                                </div>
                            </div>
                        </td>
                        <?php if ($hasCategoryCol): ?>
                        <td>
                            <?php if ($cat !== ''): ?>
                                <span class="mdtlog-badge" style="background:<?php echo $cCol; ?>22;color:<?php echo htmlspecialchars($cCol); ?>;border:1px solid <?php echo $cCol; ?>44;">
                                    <?php echo htmlspecialchars($cat); ?>
                                </span>
                            <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if (!empty($log['panel'])): ?>
                                <span class="mdtlog-badge" style="background:<?php echo $pBadge; ?>;color:var(--text-primary);border:1px solid var(--border);"><?php echo htmlspecialchars((string) $log['panel']); ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><strong style="font-size:.82rem;"><?php echo htmlspecialchars((string) ($log['action'] ?? '')); ?></strong></td>
                        <td class="mdtlog-desc"><?php echo htmlspecialchars((string) ($log['description'] ?? '—')); ?></td>
                        <td class="mdtlog-mono"><?php
                            $fk = (string) ($log['target_faction'] ?? '');
                            if ($fk === '' && !empty($log['log_user_faction'])) {
                                $fk = (string) $log['log_user_faction'];
                            }
                            echo $fk !== '' ? htmlspecialchars($fk) : '—';
                        ?></td>
                        <td class="mdtlog-mono"><?php
                            echo htmlspecialchars(mdt_logs_format_ip_for_viewer(
                                $log['ip_address'] ?? null,
                                isset($log['user_id']) ? (int) $log['user_id'] : null,
                                $log['log_user_role'] ?? null
                            ));
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="mdtlog-pager">
            <span style="color:var(--text-secondary);"><?php echo number_format($total); ?> lignes</span>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                    <a class="mdtlog-btn mdtlog-btn-ghost" href="<?php echo htmlspecialchars($buildPageUrl($page - 1)); ?>">← Précédent</a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a class="mdtlog-btn <?php echo $i === $page ? 'mdtlog-btn-primary' : 'mdtlog-btn-ghost'; ?>" href="<?php echo htmlspecialchars($buildPageUrl($i)); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="mdtlog-btn mdtlog-btn-ghost" href="<?php echo htmlspecialchars($buildPageUrl($page + 1)); ?>">Suivant →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (mdt_logs_can_purge()): ?>
    <div class="mdtlog-purge">
        <h3><i class="fa-solid fa-calendar-xmark"></i> Suppression par période</h3>
        <p>
            Supprime définitivement les entrées du journal dont la date se situe dans l’intervalle choisi (bornes inclusives).
            Les administrateurs et développeurs voient une trace de la purge dans un nouveau log (hors plage supprimée si vous purgez jusqu’à aujourd’hui).
        </p>
        <form method="post" action="" onsubmit="return confirm('Confirmer la suppression DÉFINITIVE des logs sur cette période ?');">
            <input type="hidden" name="form" value="purge_logs">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['mdt_logs_csrf'] ?? ''); ?>">
            <div class="mdtlog-pgrid">
                <div>
                    <label class="mdtlog-label">Date début</label>
                    <input class="mdtlog-input" type="date" name="purge_from" required value="<?php echo htmlspecialchars($dateFrom ?: date('Y-m-d', strtotime('-30 days'))); ?>">
                </div>
                <div>
                    <label class="mdtlog-label">Date fin</label>
                    <input class="mdtlog-input" type="date" name="purge_to" required value="<?php echo htmlspecialchars($dateTo ?: date('Y-m-d')); ?>">
                </div>
            </div>
            <div style="margin-top:12px;">
                <label class="mdtlog-label">Confirmation (saisie exacte)</label>
                <input class="mdtlog-input" type="text" name="purge_confirm" placeholder="SUPPRIMER LOGS" autocomplete="off" style="max-width:320px;">
            </div>
            <div style="margin-top:14px;">
                <button type="submit" class="mdtlog-btn" style="background:rgba(239,68,68,.2);color:#fecaca;border:1px solid rgba(239,68,68,.4);">
                    <i class="fa-solid fa-trash-can"></i> Supprimer les logs de la période
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
