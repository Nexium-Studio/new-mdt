<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

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
$msg = ''; $msgType = '';

// ACTIONS
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if ($_POST['action']==='start_shift') {
            $stmt=$db->prepare("SELECT id FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NULL");
            $stmt->execute([$_SESSION['user_id'],$companyFaction]);
            if ($stmt->fetch()) throw new Exception("Vous êtes déjà en service");
            $db->prepare("INSERT INTO company_shifts (user_id,faction,shift_start) VALUES (?,?,NOW())")
               ->execute([$_SESSION['user_id'],$companyFaction]);
            $msg='Prise de service enregistrée.'; $msgType='success';
        } elseif ($_POST['action']==='end_shift') {
            $r=$db->prepare("UPDATE company_shifts SET shift_end=NOW(),duration_minutes=TIMESTAMPDIFF(MINUTE,shift_start,NOW()) WHERE user_id=? AND faction=? AND shift_end IS NULL");
            $r->execute([$_SESSION['user_id'],$companyFaction]);
            if ($r->rowCount()===0) throw new Exception("Aucune prise de service active");
            $msg='Fin de service enregistrée.'; $msgType='success';
        } elseif ($_POST['action']==='end_other' && hasRole(['*','Administrateur','Développeur','Staff'])) {
            $db->prepare("UPDATE company_shifts SET shift_end=NOW(),duration_minutes=TIMESTAMPDIFF(MINUTE,shift_start,NOW()) WHERE id=? AND faction=?")
               ->execute([intval($_POST['shift_id']),$companyFaction]);
            $msg='Service terminé.'; $msgType='success';
        }
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// Mon service actif
$stmt=$db->prepare("SELECT *, UNIX_TIMESTAMP(shift_start) AS shift_start_unix FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NULL");
$stmt->execute([$_SESSION['user_id'],$companyFaction]); $myShift=$stmt->fetch();

// Mes stats
$stmt=$db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(duration_minutes),0) as minutes, COALESCE(AVG(duration_minutes),0) as avg FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NOT NULL");
$stmt->execute([$_SESSION['user_id'],$companyFaction]); $myStats=$stmt->fetch();

// Mon historique
$stmt=$db->prepare("SELECT * FROM company_shifts WHERE user_id=? AND faction=? ORDER BY shift_start DESC LIMIT 20");
$stmt->execute([$_SESSION['user_id'],$companyFaction]); $myHistory=$stmt->fetchAll();

// Actifs en ce moment
$stmt=$db->prepare("SELECT cs.*, u.username FROM company_shifts cs JOIN users u ON cs.user_id=u.id WHERE cs.faction=? AND cs.shift_end IS NULL ORDER BY cs.shift_start");
$stmt->execute([$companyFaction]); $activeShifts=$stmt->fetchAll();

// Classement du mois
$stmt=$db->prepare("
    SELECT u.username, SUM(cs.duration_minutes) as total_min, COUNT(*) as shifts
    FROM company_shifts cs
    JOIN users u ON cs.user_id=u.id
    WHERE cs.faction=? AND cs.shift_end IS NOT NULL
    AND MONTH(cs.shift_start)=MONTH(CURDATE()) AND YEAR(cs.shift_start)=YEAR(CURDATE())
    GROUP BY cs.user_id ORDER BY total_min DESC LIMIT 10
");
$stmt->execute([$companyFaction]); $ranking=$stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<style>
.ps-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.service-btn-wrap{text-align:center;padding:1.75rem}
.timer-display{font-size:2.5rem;font-weight:900;font-family:'Courier New',monospace;color:#f1f5f9;margin:.75rem 0}
.shift-item{display:flex;align-items:center;gap:.75rem;padding:.75rem;border-bottom:1px solid rgba(255,255,255,.05)}
.shift-item:last-child{border-bottom:none}
.shift-dot{width:9px;height:9px;border-radius:50%;background:var(--cc);box-shadow:0 0 6px var(--cc);flex-shrink:0}
.rank-item{display:flex;align-items:center;gap:.75rem;padding:.7rem 0;border-bottom:1px solid rgba(255,255,255,.05)}
.rank-item:last-child{border-bottom:none}
.rank-num{width:28px;height:28px;border-radius:7px;background:rgba(220,38,38,.15);color:var(--cc);font-weight:700;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0}

@media(max-width:900px){.ps-grid{grid-template-columns:1fr}}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="ps-grid">
    <!-- Mon service -->
    <div class="card">
        <div class="card-title"><i class="fas fa-user-clock"></i> Mon Service</div>
        <div class="service-btn-wrap">
            <?php if($myShift): ?>
            <div class="badge badge-green" style="margin-bottom:.75rem"><i class="fas fa-circle"></i> En service</div>
            <div class="timer-display" id="timer" data-shift-start-ms="<?php echo (int)($myShift['shift_start_unix'] ?? 0) * 1000; ?>">00:00:00</div>
            <p style="color:#64748b;font-size:.8rem;margin-bottom:1.25rem">
                Depuis <?php echo date('H:i',strtotime($myShift['shift_start'])); ?>
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="end_shift">
                <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center">
                    <i class="fas fa-stop-circle"></i> Terminer le Service
                </button>
            </form>
            <?php else: ?>
            <div class="badge badge-gray" style="margin-bottom:.75rem"><i class="fas fa-moon"></i> Hors service</div>
            <div style="color:#475569;font-size:2rem;margin:.75rem 0">——</div>
            <p style="color:#64748b;font-size:.8rem;margin-bottom:1.25rem">Vous n'êtes pas en service</p>
            <form method="POST">
                <input type="hidden" name="action" value="start_shift">
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-play-circle"></i> Prendre Service
                </button>
            </form>
            <?php endif; ?>
        </div>
        <!-- Mes stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.05)">
            <div style="text-align:center">
                <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.25rem">Services</div>
                <div style="font-size:1.35rem;font-weight:900;color:#f1f5f9"><?php echo $myStats['total']; ?></div>
            </div>
            <div style="text-align:center">
                <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.25rem">Total heures</div>
                <div style="font-size:1.35rem;font-weight:900;color:#93c5fd"><?php echo round($myStats['minutes']/60,1); ?>h</div>
            </div>
            <div style="text-align:center">
                <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.25rem">Moy/service</div>
                <div style="font-size:1.35rem;font-weight:900;color:#fcd34d"><?php echo round($myStats['avg']); ?>min</div>
            </div>
        </div>
    </div>

    <!-- Actuellement en service -->
    <div class="card">
        <div class="card-title"><i class="fas fa-broadcast-tower"></i> En service maintenant (<?php echo count($activeShifts); ?>)</div>
        <?php if(empty($activeShifts)): ?>
        <p style="color:#475569;text-align:center;padding:2rem">Personne en service</p>
        <?php else: ?>
        <?php foreach($activeShifts as $s): ?>
        <div class="shift-item">
            <div class="shift-dot"></div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:.875rem"><?php echo htmlspecialchars($s['username']); ?></div>
                <div style="font-size:.75rem;color:#64748b">Depuis <?php echo date('H:i',strtotime($s['shift_start'])); ?></div>
            </div>
            <?php if(hasRole(['*','Administrateur','Développeur','Staff']) && $s['user_id']!=$_SESSION['user_id']): ?>
            <form method="POST" style="margin:0" data-confirm="<?php echo htmlspecialchars('Terminer le service de '.$s['username'].' ?', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="end_other">
                <input type="hidden" name="shift_id" value="<?php echo $s['id']; ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-stop"></i></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem">
    <!-- Mon historique -->
    <div class="card">
        <div class="card-title"><i class="fas fa-history"></i> Mon Historique (20 derniers)</div>
        <table class="data-table">
            <thead><tr><th>Début</th><th>Fin</th><th>Durée</th></tr></thead>
            <tbody>
            <?php foreach($myHistory as $s): ?>
            <tr>
                <td><?php echo date('d/m/y H:i',strtotime($s['shift_start'])); ?></td>
                <td><?php echo $s['shift_end']?date('d/m/y H:i',strtotime($s['shift_end'])):'<span class="badge badge-green">En cours</span>'; ?></td>
                <td style="font-weight:600;color:#93c5fd"><?php echo $s['duration_minutes']?round($s['duration_minutes']/60,1).'h':'—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Classement -->
    <div class="card">
        <div class="card-title"><i class="fas fa-medal"></i> Classement du mois</div>
        <?php foreach($ranking as $i=>$r): ?>
        <div class="rank-item">
            <div class="rank-num"><?php echo $i+1; ?></div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:.875rem"><?php echo htmlspecialchars($r['username']); ?></div>
                <div style="font-size:.75rem;color:#64748b"><?php echo $r['shifts']; ?> service(s)</div>
            </div>
            <div style="font-weight:700;color:#4ade80;font-size:.875rem"><?php echo round($r['total_min']/60,1); ?>h</div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($ranking)): ?><p style="text-align:center;color:#475569;padding:2rem">Aucune donnée ce mois</p><?php endif; ?>
    </div>
</div>

<?php if($myShift): ?>
<script>
(function () {
    function updateTimer() {
        const el = document.getElementById('timer');
        if (!el) return;
        const startMs = parseInt(el.getAttribute('data-shift-start-ms'), 10);
        const diff = Math.max(0, Date.now() - startMs);
        const h = Math.floor(diff/3600000).toString().padStart(2,'0');
        const m = Math.floor((diff%3600000)/60000).toString().padStart(2,'0');
        const s = Math.floor((diff%60000)/1000).toString().padStart(2,'0');
        el.textContent = h+':'+m+':'+s;
    }
    setInterval(updateTimer, 1000);
    updateTimer();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>