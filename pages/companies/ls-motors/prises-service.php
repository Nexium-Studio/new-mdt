<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'LS Motors';
$companyColor = '#3b82f6';
$companyFaction = 'LS_MOTORS';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'start_shift') {
            $stmt = $db->prepare('
                SELECT id FROM company_shifts
                WHERE user_id = ? AND faction = ? AND shift_end IS NULL
            ');
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);

            if ($stmt->fetch()) {
                throw new Exception('Vous êtes déjà en service');
            }

            $stmt = $db->prepare('
                INSERT INTO company_shifts (user_id, faction, shift_start)
                VALUES (?, ?, NOW())
            ');
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);

            $message = 'Prise de service enregistrée';
            $messageType = 'success';
        } elseif ($_POST['action'] === 'end_shift') {
            $stmt = $db->prepare('
                UPDATE company_shifts
                SET shift_end = NOW(),
                    duration_minutes = TIMESTAMPDIFF(MINUTE, shift_start, NOW())
                WHERE user_id = ? AND faction = ? AND shift_end IS NULL
            ');
            $stmt->execute([$_SESSION['user_id'], $companyFaction]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Aucune prise de service active');
            }

            $message = 'Fin de service enregistrée';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$stmt = $db->prepare('
    SELECT id, shift_start, UNIX_TIMESTAMP(shift_start) AS shift_start_unix
    FROM company_shifts
    WHERE user_id = ? AND faction = ? AND shift_end IS NULL
');
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$currentShift = $stmt->fetch();

$stmt = $db->prepare('
    SELECT
        COUNT(*) as total_shifts,
        SUM(duration_minutes) as total_minutes,
        AVG(duration_minutes) as avg_minutes
    FROM company_shifts
    WHERE user_id = ? AND faction = ? AND shift_end IS NOT NULL
');
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$userStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT *
    FROM company_shifts
    WHERE user_id = ? AND faction = ?
    ORDER BY shift_start DESC
    LIMIT 30
');
$stmt->execute([$_SESSION['user_id'], $companyFaction]);
$userHistory = $stmt->fetchAll();

$stmt = $db->prepare('
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
    GROUP BY cs.user_id, u.username, u.faction_rank
    ORDER BY total_minutes DESC
    LIMIT 10
');
$stmt->execute([$companyFaction]);
$leaderboard = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT
        u.username,
        u.faction_rank,
        cs.shift_start
    FROM company_shifts cs
    JOIN users u ON cs.user_id = u.id
    WHERE cs.faction = ? AND cs.shift_end IS NULL
    ORDER BY cs.shift_start ASC
');
$stmt->execute([$companyFaction]);
$activeShifts = $stmt->fetchAll();

function formatDuration($minutes) {
    if (!$minutes) {
        return '0min';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours > 0 ? "{$hours}h {$mins}min" : "{$mins}min";
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="lm-shift-hero" aria-labelledby="shift-hero-title">
    <div class="lm-shift-hero__inner">
        <div>
            <p id="shift-hero-title" class="shift-status">
                <span class="status-indicator <?php echo $currentShift ? 'on-duty' : 'off-duty'; ?>"></span>
                <?php echo $currentShift ? 'En service' : 'Hors service'; ?>
            </p>
            <?php if ($currentShift): ?>
                <div class="shift-timer" id="shiftTimer" data-shift-start-ms="<?php echo (int)($currentShift['shift_start_unix'] ?? 0) * 1000; ?>">00:00:00</div>
                <p class="lm-shift-hint">Chronomètre temps réel — pensez à clôturer en fin de prestation.</p>
            <?php else: ?>
                <div class="shift-timer" style="opacity:.35">—:—:—</div>
                <p class="lm-shift-hint">Démarrez votre service pour apparaitre dans l’équipe présente et compter votre temps.</p>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($currentShift): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="end_shift">
                    <button type="submit" class="shift-btn shift-btn-end">
                        <i class="fas fa-stop-circle"></i>
                        Quitter le service
                    </button>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="start_shift">
                    <button type="submit" class="shift-btn shift-btn-start">
                        <i class="fas fa-play-circle"></i>
                        Prendre mon service
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo (int)($userStats['total_shifts'] ?? 0); ?></div>
                <div class="stat-label">Services terminés</div>
            </div>
            <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value" style="font-size:1.6rem"><?php echo formatDuration((int)($userStats['total_minutes'] ?? 0)); ?></div>
                <div class="stat-label">Temps cumulé</div>
            </div>
            <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value" style="font-size:1.6rem"><?php echo formatDuration((int) round($userStats['avg_minutes'] ?? 0)); ?></div>
                <div class="stat-label">Moyenne / service</div>
            </div>
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2 class="lm-card-head"><i class="fas fa-medal"></i> Classement du mois</h2>
        <?php if (empty($leaderboard)): ?>
            <div class="lm-empty-state">
                <i class="fas fa-chart-simple"></i>
                <p>Aucune donnée pour ce mois-ci.</p>
            </div>
        <?php else: ?>
            <?php foreach ($leaderboard as $index => $entry): ?>
                <div class="leaderboard-item">
                    <div class="rank-badge <?php echo $index === 0 ? 'first' : ($index === 1 ? 'second' : ($index === 2 ? 'third' : '')); ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:800;color:var(--lm-text);margin-bottom:.2rem"><?php echo htmlspecialchars($entry['username']); ?></div>
                        <div class="lm-muted" style="font-size:.82rem"><?php echo htmlspecialchars($entry['faction_rank'] ?? '—'); ?></div>
                    </div>
                    <div style="text-align:right">
                        <div class="lm-price"><?php echo formatDuration((int)$entry['total_minutes']); ?></div>
                        <div class="lm-muted" style="font-size:.75rem"><?php echo (int)$entry['shifts_count']; ?> service(s)</div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="lm-card-head"><i class="fas fa-user-clock"></i> En salle (<?php echo count($activeShifts); ?>)</h2>
        <?php if (empty($activeShifts)): ?>
            <div class="lm-empty-state">
                <i class="fas fa-moon"></i>
                <p>Personne n’est actuellement en service.</p>
            </div>
        <?php else: ?>
            <?php foreach ($activeShifts as $shift): ?>
                <div class="active-shift-item">
                    <span class="active-indicator"></span>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:800;color:var(--lm-text)"><?php echo htmlspecialchars($shift['username']); ?></div>
                        <div class="lm-muted" style="font-size:.82rem"><?php echo htmlspecialchars($shift['faction_rank'] ?? '—'); ?></div>
                    </div>
                    <div class="lm-muted" style="font-size:.82rem;white-space:nowrap">Depuis <?php echo date('H:i', strtotime($shift['shift_start'])); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:1.5rem">
    <h2 class="lm-card-head"><i class="fas fa-clock-rotate-left"></i> Mon historique (30 derniers)</h2>
    <?php if (empty($userHistory)): ?>
        <div class="lm-empty-state">
            <i class="fas fa-inbox"></i>
            <p>Aucun historique pour le moment.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
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
                            <td style="font-weight:700;color:var(--lm-text)"><?php echo date('d/m/Y', strtotime($shift['shift_start'])); ?></td>
                            <td><?php echo date('H:i', strtotime($shift['shift_start'])); ?></td>
                            <td><?php echo $shift['shift_end'] ? date('H:i', strtotime($shift['shift_end'])) : '—'; ?></td>
                            <td>
                                <?php if ($shift['duration_minutes']): ?>
                                    <span class="duration-badge"><?php echo formatDuration((int)$shift['duration_minutes']); ?></span>
                                <?php else: ?>
                                    <strong style="color:#4ade80">En cours</strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($shift['shift_end']): ?>
                                    <span class="lm-muted"><i class="fas fa-check" style="color:#4ade80"></i> Terminé</span>
                                <?php else: ?>
                                    <span style="color:#4ade80"><i class="fas fa-circle" style="font-size:.45rem;vertical-align:middle"></i> Actif</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
