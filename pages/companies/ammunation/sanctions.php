<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Sanctions';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'sanctions.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'sanctions.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'sanctions.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'sanctions.delete');

$SANCTION_TYPES=['Avertissement','Blâme','Mise à pied','Rétrogradation','Licenciement','Amende'];

// AJOUTER
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add' && $canManage) {
    try {
        $db->prepare("INSERT INTO company_sanctions (faction,employee_id,issued_by,sanction_type,reason,date_issued,date_expires,status) VALUES (?,?,?,?,?,?,?,'active')")
           ->execute([
               $companyFaction, intval($_POST['employee_id']), $_SESSION['user_id'],
               trim($_POST['sanction_type']), trim($_POST['reason']),
               $_POST['date_issued'],
               !empty($_POST['date_expires'])?$_POST['date_expires']:null
           ]);
        $msg='Sanction enregistrée.'; $msgType='success';
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// MODIFIER STATUT
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_status' && $canManage) {
    try {
        $db->prepare("UPDATE company_sanctions SET status=? WHERE id=? AND faction=?")
           ->execute([$_POST['status'],intval($_POST['sanction_id']),$companyFaction]);
        $msg='Statut mis à jour.'; $msgType='success';
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// SUPPRIMER
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete' && $canManage) {
    try {
        $db->prepare("DELETE FROM company_sanctions WHERE id=? AND faction=?")->execute([intval($_POST['sanction_id']),$companyFaction]);
        $msg='Sanction supprimée.'; $msgType='success';
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// DATA
$stmt=$db->prepare("
    SELECT cs.*, emp.username as emp_name, emp.faction_rank as emp_rank,
           iss.username as issuer_name
    FROM company_sanctions cs
    JOIN users emp ON cs.employee_id=emp.id
    JOIN users iss ON cs.issued_by=iss.id
    WHERE cs.faction=? ORDER BY cs.date_issued DESC
");
$stmt->execute([$companyFaction]); $sanctions=$stmt->fetchAll();

$stmt=$db->prepare("SELECT ce.id, u.username FROM company_employees ce JOIN users u ON ce.user_id=u.id WHERE ce.faction=? ORDER BY u.username");
$stmt->execute([$companyFaction]); $employees=$stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<style>
.san-type-avertissement { background:rgba(245,158,11,.1); color:#fcd34d; border:1px solid rgba(245,158,11,.2); }
.san-type-blame         { background:rgba(249,115,22,.1); color:#fb923c; border:1px solid rgba(249,115,22,.2); }
.san-type-miseapied     { background:rgba(239,68,68,.1);  color:#f87171; border:1px solid rgba(239,68,68,.2); }
.san-type-retrogradation{ background:rgba(147,51,234,.1); color:#c084fc; border:1px solid rgba(147,51,234,.2); }
.san-type-licenciement  { background:rgba(220,38,38,.15); color:#fca5a5; border:1px solid rgba(220,38,38,.3); }
.san-type-amende        { background:rgba(16,185,129,.1); color:#6ee7b7; border:1px solid rgba(16,185,129,.2); }
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
    <div style="color:#64748b;font-size:.875rem"><strong style="color:#f1f5f9"><?php echo count($sanctions); ?></strong> sanction(s)</div>
    <?php if($canManage && !empty($employees)): ?>
    <button onclick="Company.openModal('addModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvelle Sanction
    </button>
    <?php endif; ?>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <table class="data-table">
        <thead><tr><th>Date</th><th>Employé</th><th>Type</th><th>Motif</th><th>Par</th><th>Expire</th><th>Statut</th><?php if($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if(empty($sanctions)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:#475569">Aucune sanction</td></tr>
        <?php else: ?>
        <?php foreach($sanctions as $s):
            $typeKey = strtolower(str_replace([' ','é','à'], ['','e','a'], $s['sanction_type']));
            $statusLabel=['active'=>'Active','lifted'=>'Levée','expired'=>'Expirée'][$s['status']]??$s['status'];
            $statusCls=['active'=>'badge-red','lifted'=>'badge-green','expired'=>'badge-gray'][$s['status']]??'badge-gray';
        ?>
        <tr>
            <td style="color:#94a3b8;font-size:.8rem"><?php echo date('d/m/Y',strtotime($s['date_issued'])); ?></td>
            <td>
                <div style="font-weight:600"><?php echo htmlspecialchars($s['emp_name']); ?></div>
                <div style="font-size:.75rem;color:#64748b"><?php echo htmlspecialchars($s['emp_rank']??''); ?></div>
            </td>
            <td><span class="badge san-type-<?php echo $typeKey; ?>"><?php echo htmlspecialchars($s['sanction_type']); ?></span></td>
            <td style="font-size:.82rem;max-width:220px;line-height:1.4"><?php echo htmlspecialchars($s['reason']); ?></td>
            <td style="color:#94a3b8;font-size:.82rem"><?php echo htmlspecialchars($s['issuer_name']); ?></td>
            <td style="color:#64748b;font-size:.8rem"><?php echo $s['date_expires']?date('d/m/Y',strtotime($s['date_expires'])):'—'; ?></td>
            <td><span class="badge <?php echo $statusCls; ?>"><?php echo $statusLabel; ?></span></td>
            <?php if($canManage): ?>
            <td>
                <div style="display:flex;gap:.35rem">
                    <?php if($s['status']==='active'): ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="sanction_id" value="<?php echo $s['id']; ?>">
                        <input type="hidden" name="status" value="lifted">
                        <button type="submit" class="btn btn-sm btn-secondary" title="Lever la sanction"><i class="fas fa-unlock"></i></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" data-confirm="Supprimer cette sanction ?">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sanction_id" value="<?php echo $s['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if($canManage && !empty($employees)): ?>
<div id="addModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-gavel"></i> Nouvelle Sanction</h2>
            <button class="modal-close" onclick="Company.closeModal('addModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Employé *</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">Sélectionner…</option>
                            <?php foreach($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="sanction_type" class="form-select" required>
                            <?php foreach($SANCTION_TYPES as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date_issued" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date d'expiration</label>
                        <input type="date" name="date_expires" class="form-input">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Motif *</label>
                        <textarea name="reason" class="form-textarea" required placeholder="Expliquez la raison de la sanction…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="Company.closeModal('addModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-gavel"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>