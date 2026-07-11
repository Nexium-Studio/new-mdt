<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Accès: SAMS superviseurs + Admin/Dev/Staff
if ($_SESSION['user_faction'] !== 'SAMS' && !in_array($_SESSION['user_role'], ['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Vérifier grade SAMS (superviseur+)
$gradeStmt = $db->prepare("SELECT faction_grade FROM users WHERE id = ?");
$gradeStmt->execute([$_SESSION['user_id']]);
$userGrade = $gradeStmt->fetch()['faction_grade'] ?? '';

$allowedGrades = ['Clinical Supervisor', 'Deputy Director', 'Executive Director', 'Chief of Medicine'];
$allowedRoles = ['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal'];
$hasAccess = in_array($_SESSION['user_role'], $allowedRoles) || in_array($userGrade, $allowedGrades);

if (!$hasAccess) {
    $_SESSION['error'] = 'Accès refusé : Grade Clinical Supervisor minimum requis';
    redirect(SITE_URL . '/pages/sams/dashboard.php');
}

// Filtres
$filterAction = $_GET['action'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Construction requête
$where = [];
$params = [];

if ($filterAction) {
    $where[] = "sal.action_type LIKE ?";
    $params[] = '%' . $filterAction . '%';
}
if ($filterUser) {
    $where[] = "sal.user_id = ?";
    $params[] = $filterUser;
}
if ($filterDate) {
    $where[] = "DATE(sal.created_at) = ?";
    $params[] = $filterDate;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Total
$totalLogs = 0;
try {
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM sams_activity_logs sal $whereClause");
    $stmtCount->execute($params);
    $totalLogs = $stmtCount->fetchColumn();
} catch (PDOException $e) { error_log("Logs count: " . $e->getMessage()); }

$totalPages = max(1, ceil($totalLogs / $perPage));

// Fetch logs
$logs = [];
try {
    $sql = "SELECT sal.*, NULLIF(TRIM(CONCAT(COALESCE(pp.firstname,''),' ',COALESCE(pp.lastname,''))),'') AS username FROM sams_activity_logs sal LEFT JOIN users u ON sal.user_id = u.id LEFT JOIN profiles pp ON pp.id = u.profile_id $whereClause ORDER BY sal.created_at DESC LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) { error_log("Logs fetch: " . $e->getMessage()); }

// Users pour filtre
$usersFilter = [];
try {
    $usersFilter = $db->query("SELECT DISTINCT u.id, NULLIF(TRIM(CONCAT(COALESCE(pp.firstname,''),' ',COALESCE(pp.lastname,''))),'') AS username FROM users u INNER JOIN sams_activity_logs sal ON u.id = sal.user_id LEFT JOIN profiles pp ON pp.id = u.profile_id ORDER BY username")->fetchAll();
} catch (PDOException $e) {}

// Stats
$statsToday = $statsWeek = $statsMonth = 0;
try { $statsToday = $db->query("SELECT COUNT(*) FROM sams_activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(); } catch (PDOException $e) {}
try { $statsWeek = $db->query("SELECT COUNT(*) FROM sams_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(); } catch (PDOException $e) {}
try { $statsMonth = $db->query("SELECT COUNT(*) FROM sams_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn(); } catch (PDOException $e) {}

$pageTitle = 'Logs d\'Activité';
include __DIR__ . '/includes/header.php';
?>

<style>
.logs-header {
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.1), rgba(220, 20, 60, 0.05));
    border: 1px solid rgba(255, 23, 68, 0.2);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.logs-header::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #ff1744, transparent);
}
.logs-title {
    font-family: 'Orbitron', monospace;
    font-size: 1.5rem;
    font-weight: 800;
    color: #f0e6e8;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.logs-subtitle { color: #7a6b70; font-size: 0.9rem; }

.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-box {
    background: rgba(255, 23, 68, 0.05);
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
}
.stat-box-value {
    font-family: 'Orbitron', monospace;
    font-size: 1.8rem;
    font-weight: 800;
    background: linear-gradient(135deg, #ff1744, #ff4569);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.stat-box-label {
    font-size: 0.75rem;
    color: #7a6b70;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 0.5rem;
}

.filters-section {
    background: #0d1220;
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.filters-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}

.log-entry {
    background: rgba(255, 23, 68, 0.03);
    border: 1px solid rgba(255, 23, 68, 0.1);
    border-left: 3px solid #ff1744;
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    transition: all 0.3s;
}
.log-entry:hover {
    background: rgba(255, 23, 68, 0.06);
    border-left-width: 5px;
    transform: translateX(4px);
}
.log-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}
.log-action {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.log-action-icon {
    width: 35px; height: 35px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.2), rgba(220, 20, 60, 0.1));
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #ff1744;
}
.log-action-type {
    font-family: 'Chakra Petch', sans-serif;
    font-weight: 700; font-size: 0.9rem; color: #f0e6e8;
}
.log-timestamp {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem; color: #7a6b70;
    display: flex; align-items: center; gap: 0.5rem;
}
.log-meta {
    display: flex; gap: 2rem; flex-wrap: wrap;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 23, 68, 0.1);
    font-size: 0.8rem; color: #7a6b70;
}
.log-meta-item {
    display: flex; align-items: center; gap: 0.5rem;
}
.log-meta-item i { color: #ff1744; width: 16px; }

.action-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.action-create { background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; }
.action-edit { background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #f59e0b; }
.action-delete { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; }
.action-view { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #3b82f6; }
.action-other { background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.3); color: #8b5cf6; }

.pagination {
    display: flex; justify-content: center; align-items: center;
    gap: 0.5rem; margin-top: 2rem;
}
.pagination-btn {
    padding: 0.6rem 1rem;
    background: rgba(255, 23, 68, 0.1);
    border: 1px solid rgba(255, 23, 68, 0.2);
    border-radius: 8px;
    color: #ff4569; text-decoration: none;
    font-weight: 600; transition: all 0.3s;
}
.pagination-btn:hover:not(.disabled) { background: rgba(255, 23, 68, 0.2); border-color: #ff1744; }
.pagination-btn.disabled { opacity: 0.3; cursor: not-allowed; }

@media (max-width: 1024px) { .filters-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px) { .filters-grid, .stats-row { grid-template-columns: 1fr; } }
</style>

<div class="logs-header">
    <h1 class="logs-title"><i class="fas fa-clipboard-list"></i> Journal d'Activité SAMS</h1>
    <p class="logs-subtitle"><i class="fas fa-lock"></i> Accès réservé aux Clinical Supervisors et supérieurs</p>
</div>

<div class="stats-row">
    <div class="stat-box">
        <div class="stat-box-value"><?php echo $statsToday; ?></div>
        <div class="stat-box-label">Aujourd'hui</div>
    </div>
    <div class="stat-box">
        <div class="stat-box-value"><?php echo $statsWeek; ?></div>
        <div class="stat-box-label">Cette Semaine</div>
    </div>
    <div class="stat-box">
        <div class="stat-box-value"><?php echo $statsMonth; ?></div>
        <div class="stat-box-label">Ce Mois</div>
    </div>
</div>

<!-- Filtres -->
<div class="filters-section">
    <form method="GET">
        <div class="filters-grid">
            <div>
                <label class="form-label">Type d'action</label>
                <select name="action" class="form-select">
                    <option value="">Toutes</option>
                    <option value="create" <?php echo $filterAction === 'create' ? 'selected' : ''; ?>>Créations</option>
                    <option value="edit" <?php echo $filterAction === 'edit' ? 'selected' : ''; ?>>Modifications</option>
                    <option value="delete" <?php echo $filterAction === 'delete' ? 'selected' : ''; ?>>Suppressions</option>
                    <option value="recruit" <?php echo $filterAction === 'recruit' ? 'selected' : ''; ?>>Recrutements</option>
                </select>
            </div>
            <div>
                <label class="form-label">Utilisateur</label>
                <select name="user" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($usersFilter as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-input" value="<?php echo htmlspecialchars($filterDate); ?>">
            </div>
            <div style="display:flex;align-items:flex-end;gap:0.5rem;">
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="logs.php" class="btn btn-secondary" style="white-space:nowrap;"><i class="fas fa-times"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- Logs -->
<?php if (empty($logs)): ?>
    <div class="card"><div class="card-body">
        <div style="text-align:center;padding:3rem;color:#7a6b70;">
            <i class="fas fa-inbox" style="font-size:3rem;opacity:0.2;margin-bottom:1rem;display:block;"></i>
            <h3>Aucun log trouvé</h3>
            <p>Modifiez vos filtres ou attendez que des actions soient effectuées</p>
        </div>
    </div></div>
<?php else: ?>
    <?php foreach ($logs as $log): 
        // Déterminer le type pour le badge
        $badgeClass = 'action-other';
        $iconClass = 'fas fa-cog';
        if (strpos($log['action_type'], 'create') !== false || strpos($log['action_type'], 'recruit') !== false) {
            $badgeClass = 'action-create'; $iconClass = 'fas fa-plus-circle';
        } elseif (strpos($log['action_type'], 'edit') !== false || strpos($log['action_type'], 'update') !== false) {
            $badgeClass = 'action-edit'; $iconClass = 'fas fa-pen';
        } elseif (strpos($log['action_type'], 'delete') !== false || strpos($log['action_type'], 'remove') !== false) {
            $badgeClass = 'action-delete'; $iconClass = 'fas fa-trash';
        } elseif (strpos($log['action_type'], 'view') !== false) {
            $badgeClass = 'action-view'; $iconClass = 'fas fa-eye';
        }
    ?>
        <div class="log-entry">
            <div class="log-header">
                <div class="log-action">
                    <div class="log-action-icon"><i class="<?php echo $iconClass; ?>"></i></div>
                    <div>
                        <div class="log-action-type"><?php echo htmlspecialchars($log['description']); ?></div>
                        <span class="action-badge <?php echo $badgeClass; ?>">
                            <?php echo strtoupper(str_replace('_', ' ', $log['action_type'])); ?>
                        </span>
                    </div>
                </div>
                <div class="log-timestamp">
                    <i class="fas fa-clock"></i>
                    <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?>
                </div>
            </div>
            <div class="log-meta">
                <div class="log-meta-item">
                    <i class="fas fa-user"></i>
                    <span><strong>Par:</strong> <?php echo htmlspecialchars($log['username'] ?? 'Inconnu'); ?></span>
                </div>
                <?php if (!empty($log['target_name'])): ?>
                    <div class="log-meta-item">
                        <i class="fas fa-bullseye"></i>
                        <span><strong>Cible:</strong> <?php echo htmlspecialchars($log['target_name']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php $qs = http_build_query(array_filter(['action' => $filterAction, 'user' => $filterUser, 'date' => $filterDate])); ?>
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&<?php echo $qs; ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i> Précédent</a>
            <?php else: ?>
                <span class="pagination-btn disabled"><i class="fas fa-chevron-left"></i> Précédent</span>
            <?php endif; ?>
            <span style="color:#7a6b70;font-family:'JetBrains Mono',monospace;font-size:0.9rem;">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&<?php echo $qs; ?>" class="pagination-btn">Suivant <i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="pagination-btn disabled">Suivant <i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/components.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>