<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$companyName = "🏨 Pearl's Resort"; $companyColor = '#d4a853'; $companyFaction = 'PEARLS_RESORT';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Sanctions'; $db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// PAS de can_manage_sanctions — on utilise is_owner/is_ceo
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'sanctions.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'sanctions.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'sanctions.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'sanctions.delete');

// Colonnes réelles company_sanctions : faction, employee_id, issued_by, sanction_type, reason, date_issued, date_expires, status
$sanctionTypes = ['Avertissement','Blâme','Suspension','Rétrogradation','Licenciement'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $canManage) {
    try {
        $db->prepare("INSERT INTO company_sanctions (faction, employee_id, issued_by, sanction_type, reason, date_issued, status) VALUES (?,?,?,?,?,CURDATE(),'active')")
           ->execute([$companyFaction, (int)$_POST['user_id'], $_SESSION['user_id'], $_POST['type'], trim($_POST['reason'])]);
        $message = 'Sanction enregistrée.'; $messageType = 'success';
    } catch (Exception $e) { $message = 'Erreur: '.$e->getMessage(); $messageType = 'error'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $canManage) {
    $db->prepare("DELETE FROM company_sanctions WHERE id=? AND faction=?")->execute([(int)$_POST['sid'], $companyFaction]);
    $message = 'Sanction supprimée.'; $messageType = 'success';
}

// Récupérer les sanctions — employee_id joint à users, issued_by joint à users
$sanctions = $db->prepare("
    SELECT cs.*, u.username, s.username as staff_name
    FROM company_sanctions cs
    LEFT JOIN users u ON cs.employee_id = u.id
    LEFT JOIN users s ON cs.issued_by = s.id
    WHERE cs.faction=? ORDER BY cs.created_at DESC LIMIT 80
"); $sanctions->execute([$companyFaction]); $sanctions = $sanctions->fetchAll();

$employees = $db->query("SELECT u.id, u.username FROM company_employees ce JOIN users u ON ce.user_id=u.id WHERE ce.faction='$companyFaction' ORDER BY u.username")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <p style="color:var(--text-2);"><?php echo count($sanctions); ?> sanction(s)</p>
    <?php if ($canManage): ?><button onclick="Pearl.openModal('modal-sanction')" class="btn btn-primary"><i class="fas fa-gavel"></i> Ajouter une sanction</button><?php endif; ?>
</div>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Employé</th><th>Type</th><th>Raison</th><th>Par</th><th>Date</th><th>Statut</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php if (empty($sanctions)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-2);">Aucune sanction</td></tr>
                <?php else: foreach ($sanctions as $s):
                    $sm = ['Avertissement'=>'badge-yellow','Blâme'=>'badge-orange','Suspension'=>'badge-orange','Rétrogradation'=>'badge-purple','Licenciement'=>'badge-red'][$s['sanction_type']] ?? 'badge-slate';
                    $statusBadge = ['active'=>'badge-red','completed'=>'badge-slate','cancelled'=>'badge-green'][$s['status']] ?? 'badge-slate'; ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($s['username'] ?? '—'); ?></td>
                    <td><span class="badge <?php echo $sm; ?>"><?php echo htmlspecialchars($s['sanction_type']); ?></span></td>
                    <td style="max-width:250px;font-size:.83rem;color:var(--text-2);"><?php echo htmlspecialchars($s['reason']); ?></td>
                    <td style="font-size:.83rem;"><?php echo htmlspecialchars($s['staff_name'] ?? 'Système'); ?></td>
                    <td style="font-size:.78rem;font-family:'DM Mono',monospace;color:var(--text-2);"><?php echo $s['date_issued'] ? date('d/m/Y', strtotime($s['date_issued'])) : '—'; ?></td>
                    <td><span class="badge <?php echo $statusBadge; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                    <?php if ($canManage): ?><td>
                        <form method="POST" data-confirm="Supprimer ?" style="display:inline;">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="sid" value="<?php echo $s['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:.35rem .6rem;"><i class="fas fa-trash" style="font-size:.7rem;"></i></button>
                        </form>
                    </td><?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($canManage): ?>
<div id="modal-sanction" class="modal-overlay hidden">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header"><h2><i class="fas fa-gavel"></i> Ajouter une sanction</h2><button class="modal-close" onclick="Pearl.closeModal('modal-sanction')">×</button></div>
        <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="add">
            <div class="form-group"><label class="form-label">Employé *</label>
                <select name="user_id" class="form-select" required><option value="">Sélectionner…</option>
                    <?php foreach ($employees as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['username']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Type *</label>
                <select name="type" class="form-select" required><?php foreach ($sanctionTypes as $t): ?><option value="<?php echo $t; ?>"><?php echo $t; ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group" style="margin-bottom:0;"><label class="form-label">Raison *</label>
                <textarea name="reason" class="form-textarea" rows="3" placeholder="Motif de la sanction…" required></textarea>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Pearl.closeModal('modal-sanction')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Appliquer</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>