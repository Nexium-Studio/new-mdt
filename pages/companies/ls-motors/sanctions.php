<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'LS Motors';
$companyColor = '#3b82f6';
$companyFaction = 'LS_MOTORS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Sanctions';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'sanctions.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'sanctions.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'sanctions.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'sanctions.delete');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $stmt = $db->prepare('
            INSERT INTO company_sanctions (
                faction, employee_id, issued_by, sanction_type, reason, date_issued, date_expires, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')
        ');
        $stmt->execute([
            $companyFaction,
            (int) $_POST['employee_id'],
            $_SESSION['user_id'],
            trim($_POST['sanction_type']),
            trim($_POST['reason']),
            $_POST['date_issued'],
            !empty($_POST['date_expires']) ? $_POST['date_expires'] : null,
        ]);

        $message = 'Sanction ajoutée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status' && $canManage) {
    try {
        $stmt = $db->prepare('
            UPDATE company_sanctions
            SET status = ?
            WHERE id = ? AND faction = ?
        ');
        $stmt->execute([$_POST['status'], (int) $_POST['sanction_id'], $companyFaction]);

        $message = 'Statut mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $stmt = $db->prepare('DELETE FROM company_sanctions WHERE id = ? AND faction = ?');
        $stmt->execute([(int) $_POST['sanction_id'], $companyFaction]);

        $message = 'Sanction supprimée';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$stmt = $db->prepare('
    SELECT cs.*,
           emp.username as employee_name,
           emp.faction_rank as employee_rank,
           issuer.username as issuer_name
    FROM company_sanctions cs
    JOIN users emp ON cs.employee_id = emp.id
    JOIN users issuer ON cs.issued_by = issuer.id
    WHERE cs.faction = ?
    ORDER BY cs.date_issued DESC
');
$stmt->execute([$companyFaction]);
$sanctions = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT u.id, u.username, u.faction_rank
    FROM users u
    WHERE u.faction = ?
    ORDER BY u.username
');
$stmt->execute([$companyFaction]);
$employees = $stmt->fetchAll();

$activeCount = count(array_filter($sanctions, fn($s) => $s['status'] === 'active'));
$completedCount = count(array_filter($sanctions, fn($s) => $s['status'] === 'completed'));
$totalCount = count($sanctions);

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="stats-bar" aria-label="Synthèse sanctions">
    <div class="stat-item">
        <div class="stat-value active"><?php echo $activeCount; ?></div>
        <div class="stat-label">En cours</div>
    </div>
    <div class="stat-item">
        <div class="stat-value completed"><?php echo $completedCount; ?></div>
        <div class="stat-label">Terminées</div>
    </div>
    <div class="stat-item">
        <div class="stat-value total"><?php echo $totalCount; ?></div>
        <div class="stat-label">Total</div>
    </div>
</section>

<?php if ($canManage): ?>
<div class="lm-toolbar" style="display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem">
    <button type="button" onclick="Company.openModal('addSanctionModal')" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle sanction
    </button>
</div>
<?php endif; ?>

<div class="filter-tabs" role="tablist">
    <button type="button" class="filter-tab active" data-filter="all" onclick="filterSanctions('all', this)">
        <i class="fas fa-list"></i> Toutes
    </button>
    <button type="button" class="filter-tab" data-filter="active" onclick="filterSanctions('active', this)">
        <i class="fas fa-exclamation-triangle"></i> En cours
    </button>
    <button type="button" class="filter-tab" data-filter="completed" onclick="filterSanctions('completed', this)">
        <i class="fas fa-check-circle"></i> Terminées
    </button>
    <button type="button" class="filter-tab" data-filter="cancelled" onclick="filterSanctions('cancelled', this)">
        <i class="fas fa-ban"></i> Annulées
    </button>
</div>

<?php if (empty($sanctions)): ?>
    <div class="card">
        <div class="lm-empty-state">
            <i class="fas fa-gavel"></i>
            <p>Aucune sanction enregistrée pour LS Motors.</p>
        </div>
    </div>
<?php else: ?>
    <div class="sanctions-grid">
        <?php foreach ($sanctions as $sanction): ?>
            <article class="sanction-card <?php echo htmlspecialchars($sanction['status']); ?>" data-status="<?php echo htmlspecialchars($sanction['status']); ?>">
                <div class="sanction-header">
                    <div class="sanction-employee">
                        <div class="employee-avatar" aria-hidden="true">
                            <?php echo strtoupper(substr($sanction['employee_name'], 0, 1)); ?>
                        </div>
                        <div class="employee-info">
                            <h3><?php echo htmlspecialchars($sanction['employee_name']); ?></h3>
                            <div class="employee-rank"><?php echo htmlspecialchars($sanction['employee_rank'] ?? '—'); ?></div>
                        </div>
                    </div>
                    <?php
                    $statusNames = [
                        'active' => 'En cours',
                        'completed' => 'Terminée',
                        'cancelled' => 'Annulée',
                    ];
                    ?>
                    <span class="status-badge status-<?php echo htmlspecialchars($sanction['status']); ?>">
                        <?php echo $statusNames[$sanction['status']] ?? $sanction['status']; ?>
                    </span>
                </div>

                <div class="sanction-type">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($sanction['sanction_type']); ?>
                </div>

                <div class="sanction-reason">
                    <strong>Motif</strong>
                    <?php echo nl2br(htmlspecialchars($sanction['reason'])); ?>
                </div>

                <div class="sanction-meta">
                    <div class="meta-item">
                        <div class="meta-icon"><i class="fas fa-calendar"></i></div>
                        <div>
                            <div class="meta-label">Émise le</div>
                            <div class="meta-value"><?php echo date('d/m/Y', strtotime($sanction['date_issued'])); ?></div>
                        </div>
                    </div>
                    <?php if ($sanction['date_expires']): ?>
                    <div class="meta-item">
                        <div class="meta-icon"><i class="fas fa-hourglass-end"></i></div>
                        <div>
                            <div class="meta-label">Expire</div>
                            <div class="meta-value"><?php echo date('d/m/Y', strtotime($sanction['date_expires'])); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <div class="meta-icon"><i class="fas fa-user-tie"></i></div>
                        <div>
                            <div class="meta-label">Par</div>
                            <div class="meta-value"><?php echo htmlspecialchars($sanction['issuer_name']); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($canManage): ?>
                <div class="sanction-actions">
                    <?php if ($sanction['status'] === 'active'): ?>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="sanction_id" value="<?php echo (int)$sanction['id']; ?>">
                            <input type="hidden" name="status" value="completed">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-check"></i> Clôturer
                            </button>
                        </form>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="sanction_id" value="<?php echo (int)$sanction['id']; ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-ban"></i> Annuler
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" data-confirm="Supprimer définitivement cette sanction ?">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sanction_id" value="<?php echo (int)$sanction['id']; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div id="addSanctionModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-gavel"></i> Nouvelle sanction</h2>
            <button type="button" class="modal-close" onclick="Company.closeModal('addSanctionModal')" aria-label="Fermer">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label" for="sanction_employee">Employé</label>
                        <select id="sanction_employee" name="employee_id" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['username']); ?> — <?php echo htmlspecialchars($emp['faction_rank'] ?? '—'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group form-full">
                        <label class="form-label" for="sanction_type">Type</label>
                        <select id="sanction_type" name="sanction_type" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <option value="Avertissement">Avertissement</option>
                            <option value="Blâme">Blâme</option>
                            <option value="Mise à pied">Mise à pied</option>
                            <option value="Rétrogradation">Rétrogradation</option>
                            <option value="Licenciement">Licenciement</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="date_issued">Date d’émission</label>
                        <input id="date_issued" type="date" name="date_issued" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="date_expires">Expiration</label>
                        <input id="date_expires" type="date" name="date_expires" class="form-input">
                        <small class="lm-muted" style="font-size:.78rem;margin-top:.35rem;display:block">Laisser vide si la sanction est permanente.</small>
                    </div>

                    <div class="form-group form-full">
                        <label class="form-label" for="sanction_reason">Motif détaillé</label>
                        <textarea id="sanction_reason" name="reason" class="form-textarea" rows="5" required placeholder="Contexte, faits constatés, décision…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addSanctionModal')">Fermer</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function filterSanctions(status, btn) {
    document.querySelectorAll('.filter-tab').forEach(function (tab) {
        tab.classList.toggle('active', tab === btn);
    });
    document.querySelectorAll('.sanction-card').forEach(function (card) {
        var ok = status === 'all' || card.getAttribute('data-status') === status;
        card.style.display = ok ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
