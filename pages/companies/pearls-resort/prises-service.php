<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$companyName = "🏨 Pearl's Resort"; $companyColor = '#d4a853'; $companyFaction = 'PEARLS_RESORT';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Prises de Service'; $db = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'prises-service.view')) faction_acl_deny();
$canStart  = faction_acl_can($db, $uid, $companyFaction, 'prises-service.start');
$canStop   = faction_acl_can($db, $uid, $companyFaction, 'prises-service.stop');
$canManage = faction_acl_can($db, $uid, $companyFaction, 'prises-service.manage');
$message = ''; $messageType = '';

// Colonnes réelles company_shifts : id, user_id, faction, shift_start, shift_end, duration_minutes, created_at, patrol_vehicle, patrol_zone, partner_id, notes

// PRISE DE SERVICE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    $stmt = $db->prepare("SELECT id FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NULL");
    $stmt->execute([$_SESSION['user_id'], $companyFaction]);
    if ($stmt->fetch()) { $message = 'Vous êtes déjà en service.'; $messageType = 'error'; }
    else {
        $db->prepare("INSERT INTO company_shifts (user_id, faction, shift_start, notes) VALUES (?,?,NOW(),?)")
           ->execute([$_SESSION['user_id'], $companyFaction, trim($_POST['notes'] ?? '')]);
        $message = 'Prise de service enregistrée.'; $messageType = 'success';
    }
}

// FIN DE SERVICE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    $stmt = $db->prepare("SELECT id, shift_start FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NULL");
    $stmt->execute([$_SESSION['user_id'], $companyFaction]); $shift = $stmt->fetch();
    if ($shift) {
        $mins = (int)round((time() - strtotime($shift['shift_start'])) / 60);
        $db->prepare("UPDATE company_shifts SET shift_end=NOW(), duration_minutes=? WHERE id=?")->execute([$mins, $shift['id']]);
        $message = 'Fin de service enregistrée.'; $messageType = 'success';
    } else { $message = 'Aucune prise de service active.'; $messageType = 'error'; }
}

// Service actif de l'utilisateur
$activeShift = $db->prepare("SELECT * FROM company_shifts WHERE user_id=? AND faction=? AND shift_end IS NULL");
$activeShift->execute([$_SESSION['user_id'], $companyFaction]); $activeShift = $activeShift->fetch();

// Historique
$shifts = $db->prepare("
    SELECT s.*, u.username FROM company_shifts s JOIN users u ON s.user_id=u.id
    WHERE s.faction=? ORDER BY s.shift_start DESC LIMIT 50
"); $shifts->execute([$companyFaction]); $shifts = $shifts->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><div class="card-title"><i class="fas fa-clock"></i> Mon service</div></div>
    <?php if ($activeShift): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div style="display:flex;align-items:center;gap:.75rem;">
            <div style="width:10px;height:10px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;animation:pulse 2s infinite;"></div>
            <div>
                <div style="font-weight:700;">En service depuis <?php echo date('H:i', strtotime($activeShift['shift_start'])); ?></div>
                <div style="font-size:.78rem;color:var(--text-2);"><?php echo date('d/m/Y', strtotime($activeShift['shift_start'])); ?></div>
            </div>
        </div>
        <form method="POST"><input type="hidden" name="action" value="checkout"><button type="submit" class="btn btn-danger"><i class="fas fa-stop-circle"></i> Fin de service</button></form>
    </div>
    <?php else: ?>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div style="color:var(--text-2);font-size:.875rem;display:flex;align-items:center;gap:.5rem;">
            <div style="width:10px;height:10px;border-radius:50%;background:var(--text-3);flex-shrink:0;"></div>
            Vous n'êtes pas en service
        </div>
        <form method="POST" style="display:flex;align-items:center;gap:.75rem;">
            <input type="hidden" name="action" value="checkin">
            <input type="text" name="notes" class="form-input" placeholder="Notes (optionnel)" style="width:220px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-play-circle"></i> Prendre le service</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}50%{box-shadow:0 0 0 5px transparent}}</style>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-history"></i> Historique des services</div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Employé</th><th>Début</th><th>Fin</th><th>Durée</th><th>Notes</th></tr></thead>
            <tbody>
                <?php if (empty($shifts)): ?>
                <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-2);">Aucun service</td></tr>
                <?php else: foreach ($shifts as $s):
                    $duration = '—';
                    if ($s['duration_minutes']) {
                        $duration = floor($s['duration_minutes']/60) . 'h' . sprintf('%02d', $s['duration_minutes'] % 60);
                    } elseif ($s['shift_end']) {
                        $mins = (int)round((strtotime($s['shift_end']) - strtotime($s['shift_start'])) / 60);
                        $duration = floor($mins/60) . 'h' . sprintf('%02d', $mins % 60);
                    } ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($s['username']); ?></td>
                    <td style="font-family:'DM Mono',monospace;font-size:.8rem;"><?php echo date('d/m/Y H:i', strtotime($s['shift_start'])); ?></td>
                    <td style="font-family:'DM Mono',monospace;font-size:.8rem;"><?php echo $s['shift_end'] ? date('d/m/Y H:i', strtotime($s['shift_end'])) : '<span class="badge badge-green">En cours</span>'; ?></td>
                    <td style="font-weight:700;color:var(--gold);"><?php echo $duration; ?></td>
                    <td style="font-size:.78rem;color:var(--text-2);"><?php echo htmlspecialchars($s['notes'] ?? '—'); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>