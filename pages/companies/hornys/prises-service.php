<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = "Horny's";
$companyColor = '#dc2626';
$companyFaction = 'HORNYS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Prises de Service';
$db = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'prises-service.view')) faction_acl_deny();
$canStart  = faction_acl_can($db, $uid, $companyFaction, 'prises-service.start');
$canStop   = faction_acl_can($db, $uid, $companyFaction, 'prises-service.stop');
$canManage = faction_acl_can($db, $uid, $companyFaction, 'prises-service.manage');
$message = '';
$messageType = '';

// PRENDRE/QUITTER SERVICE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'start_shift') {
            // Vérifier si déjà en service
            $stmt = $db->prepare("
                SELECT id FROM company_shifts 
                WHERE user_id = ? AND faction = ? AND shift_end IS NULL
            ");
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);
            
            if ($stmt->fetch()) {
                throw new Exception("Vous êtes déjà en service");
            }
            
            // Commencer le service
            $stmt = $db->prepare("
                INSERT INTO company_shifts (user_id, faction, shift_start)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);
            
            $message = 'Prise de service enregistrée';
            $messageType = 'success';
            
        } elseif ($_POST['action'] === 'end_shift') {
            // Terminer le service
            $stmt = $db->prepare("
                UPDATE company_shifts 
                SET shift_end = NOW(),
                    duration_minutes = TIMESTAMPDIFF(MINUTE, shift_start, NOW())
                WHERE user_id = ? AND faction = ? AND shift_end IS NULL
            ");
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("Aucune prise de service active");
            }
            
            $message = 'Fin de service enregistrée';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// VÉRIFIER SI EN SERVICE
$stmt = $db->prepare("
    SELECT id, shift_start, UNIX_TIMESTAMP(shift_start) AS shift_start_unix
    FROM company_shifts 
    WHERE user_id = ? AND faction = ? AND shift_end IS NULL
");
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$currentShift = $stmt->fetch();

// STATISTIQUES PERSONNELLES
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_shifts,
        SUM(duration_minutes) as total_minutes,
        AVG(duration_minutes) as avg_minutes
    FROM company_shifts
    WHERE user_id = ? AND faction = ? AND shift_end IS NOT NULL
");
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$userStats = $stmt->fetch();

// HISTORIQUE PERSONNEL (30 derniers)
$stmt = $db->prepare("
    SELECT *
    FROM company_shifts
    WHERE user_id = ? AND faction = ?
    ORDER BY shift_start DESC
    LIMIT 30
");
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$userHistory = $stmt->fetchAll();

// CLASSEMENT DU MOIS
$stmt = $db->prepare("
    SELECT 
        u.username,
        u.faction_rank,
        COUNT(*) as shifts_count,
        SUM(cs.duration_minutes) as total_minutes
    FROM company_shifts cs
    JOIN users u ON cs.user_id = u.id
    WHERE cs.faction = ?
        AND MONTH(cs.shift_start) = MONTH(CURRENT_DATE())
        AND YEAR(cs.shift_start) = YEAR(CURRENT_DATE())
        AND cs.shift_end IS NOT NULL
    GROUP BY cs.user_id
    ORDER BY total_minutes DESC
    LIMIT 10
");
$stmt->execute([$companyFaction]);
$leaderboard = $stmt->fetchAll();

// SERVICES EN COURS
$stmt = $db->prepare("
    SELECT 
        u.username,
        u.faction_rank,
        cs.shift_start
    FROM company_shifts cs
    JOIN users u ON cs.user_id = u.id
    WHERE cs.faction = ? AND cs.shift_end IS NULL
    ORDER BY cs.shift_start ASC
");
$stmt->execute([$companyFaction]);
$activeShifts = $stmt->fetchAll();

function formatDuration($minutes) {
    if (!$minutes) return '0min';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours > 0 ? "{$hours}h {$mins}min" : "{$mins}min";
}

include __DIR__ . '/includes/header.php';
?>

<style>
.shift-controls {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(219, 39, 119, 0.08));
    border: 2px solid rgba(236, 72, 153, 0.3);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    text-align: center;
}

.shift-status {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
}

.status-indicator {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-indicator.on-duty {
    background: #22c55e;
    box-shadow: 0 0 20px rgba(34, 197, 94, 0.5);
}

.status-indicator.off-duty {
    background: #64748b;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.shift-timer {
    font-size: 3rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    color: #22c55e;
    margin: 1rem 0;
    text-shadow: 0 0 20px rgba(34, 197, 94, 0.3);
}

.shift-btn {
    padding: 1.25rem 3rem;
    font-size: 1.1rem;
    border-radius: 12px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 1rem;
}

.shift-btn-start {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: white;
    box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
}

.shift-btn-start:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(34, 197, 94, 0.4);
}

.shift-btn-end {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
}

.shift-btn-end:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(239, 68, 68, 0.4);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-box {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.05));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 14px;
    padding: 1.75rem;
    text-align: center;
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: rgba(236, 72, 153, 0.15);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    color: #dc2626;
    font-size: 1.75rem;
}

.stat-value {
    font-size: 2.25rem;
    font-weight: 900;
    font-family: 'Orbitron', monospace;
    color: #e8edf5;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.85rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
    margin-top: 2rem;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th {
    text-align: left;
    padding: 1rem;
    background: rgba(236, 72, 153, 0.08);
    border-bottom: 2px solid rgba(236, 72, 153, 0.3);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #94a3b8;
    font-weight: 700;
}

.history-table td {
    padding: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.history-table tr:hover {
    background: rgba(236, 72, 153, 0.05);
}

.duration-badge {
    padding: 0.4rem 0.8rem;
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    color: #22c55e;
    font-family: 'Orbitron', monospace;
}

.leaderboard-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(236, 72, 153, 0.05);
    border: 1px solid rgba(236, 72, 153, 0.15);
    border-radius: 12px;
    margin-bottom: 0.75rem;
    transition: all 0.3s;
}

.leaderboard-item:hover {
    background: rgba(236, 72, 153, 0.1);
    transform: translateX(5px);
}

.rank-badge {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 1.25rem;
    color: white;
    flex-shrink: 0;
}

.rank-badge.first {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
}

.rank-badge.second {
    background: linear-gradient(135deg, #94a3b8, #64748b);
}

.rank-badge.third {
    background: linear-gradient(135deg, #fb923c, #ea580c);
}

.active-shift-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 12px;
    margin-bottom: 0.75rem;
}

.active-indicator {
    width: 12px;
    height: 12px;
    background: #22c55e;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@media (max-width: 1024px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Contrôle Prise de Service -->
<div class="shift-controls">
    <?php if ($currentShift): ?>
        <div class="shift-status">
            <span class="status-indicator on-duty"></span>
            <span>EN SERVICE</span>
        </div>
        
        <div class="shift-timer" id="shiftTimer" data-shift-start-ms="<?php echo (int)($currentShift['shift_start_unix'] ?? 0) * 1000; ?>">
            00:00:00
        </div>
        
        <form method="POST" style="margin-top: 1.5rem;">
            <input type="hidden" name="action" value="end_shift">
            <button type="submit" class="shift-btn shift-btn-end">
                <i class="fas fa-stop-circle"></i>
                Quitter le Service
            </button>
        </form>
    <?php else: ?>
        <div class="shift-status">
            <span class="status-indicator off-duty"></span>
            <span>HORS SERVICE</span>
        </div>
        
        <p style="color: #94a3b8; margin: 1.5rem 0;">Vous n'êtes pas en service actuellement</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="start_shift">
            <button type="submit" class="shift-btn shift-btn-start">
                <i class="fas fa-play-circle"></i>
                Prendre son Service
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Statistiques Personnelles -->
<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-icon">
            <i class="fas fa-clipboard-check"></i>
        </div>
        <div class="stat-value"><?php echo $userStats['total_shifts'] ?? 0; ?></div>
        <div class="stat-label">Prises de Service</div>
    </div>
    
    <div class="stat-box">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-value"><?php echo formatDuration($userStats['total_minutes'] ?? 0); ?></div>
        <div class="stat-label">Temps Total</div>
    </div>
    
    <div class="stat-box">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-value"><?php echo formatDuration($userStats['avg_minutes'] ?? 0); ?></div>
        <div class="stat-label">Moyenne par Service</div>
    </div>
</div>

<div class="grid-2">
    <!-- Classement du Mois -->
    <div class="card">
        <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-trophy"></i>
            Classement du Mois
        </h2>
        
        <?php if (empty($leaderboard)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Aucune donnée ce mois</p>
        <?php else: ?>
            <?php foreach ($leaderboard as $index => $entry): ?>
                <div class="leaderboard-item">
                    <div class="rank-badge <?php echo $index === 0 ? 'first' : ($index === 1 ? 'second' : ($index === 2 ? 'third' : '')); ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                            <?php echo htmlspecialchars($entry['username']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">
                            <?php echo htmlspecialchars($entry['faction_rank']); ?>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; color: #22c55e; font-family: 'Orbitron', monospace;">
                            <?php echo formatDuration($entry['total_minutes']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">
                            <?php echo $entry['shifts_count']; ?> service(s)
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Services en Cours -->
    <div class="card">
        <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-users"></i>
            Actuellement en Service (<?php echo count($activeShifts); ?>)
        </h2>
        
        <?php if (empty($activeShifts)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Personne en service</p>
        <?php else: ?>
            <?php foreach ($activeShifts as $shift): ?>
                <div class="active-shift-item">
                    <div class="active-indicator"></div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                            <?php echo htmlspecialchars($shift['username']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">
                            <?php echo htmlspecialchars($shift['faction_rank']); ?>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #94a3b8;">
                        Depuis <?php echo date('H:i', strtotime($shift['shift_start'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Historique Personnel -->
<div class="card" style="margin-top: 2rem;">
    <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fas fa-history"></i>
        Mon Historique (30 derniers)
    </h2>
    
    <?php if (empty($userHistory)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">Aucun historique</p>
    <?php else: ?>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Début</th>
                    <th>Fin</th>
                    <th>Durée</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($userHistory as $shift): ?>
                    <tr>
                        <td style="font-weight: 600; color: #e8edf5;">
                            <?php echo date('d/m/Y', strtotime($shift['shift_start'])); ?>
                        </td>
                        <td><?php echo date('H:i', strtotime($shift['shift_start'])); ?></td>
                        <td>
                            <?php echo $shift['shift_end'] ? date('H:i', strtotime($shift['shift_end'])) : '-'; ?>
                        </td>
                        <td>
                            <?php if ($shift['duration_minutes']): ?>
                                <span class="duration-badge"><?php echo formatDuration($shift['duration_minutes']); ?></span>
                            <?php else: ?>
                                <span style="color: #22c55e; font-weight: 700;">EN COURS</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($shift['shift_end']): ?>
                                <i class="fas fa-check-circle" style="color: #22c55e;"></i> Terminé
                            <?php else: ?>
                                <i class="fas fa-circle" style="color: #22c55e; animation: pulse 2s infinite;"></i> Actif
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($currentShift): ?>
<script>
(function () {
    function updateTimer() {
        const el = document.getElementById('shiftTimer');
        if (!el) return;
        const startMs = parseInt(el.getAttribute('data-shift-start-ms'), 10);
        const diff = Math.max(0, Date.now() - startMs);
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        el.textContent =
            String(hours).padStart(2, '0') + ':' +
            String(minutes).padStart(2, '0') + ':' +
            String(seconds).padStart(2, '0');
    }
    setInterval(updateTimer, 1000);
    updateTimer();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>