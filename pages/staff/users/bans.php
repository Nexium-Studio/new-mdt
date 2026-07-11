<?php
// pages/admin/users/bans.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur'])) redirect(SITE_URL . '/pages/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Bannissements';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => 'index.php'], ['label' => 'Bannissements']];
$success = $error = '';

// ── ACTION: BAN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'ban') {
        $uid      = (int)$_POST['user_id'];
        $reason   = trim($_POST['reason'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $expires  = null;
        if ($duration && $duration !== 'permanent') {
            $expires = date('Y-m-d H:i:s', strtotime('+' . $duration));
        }
        $db->prepare("UPDATE users SET is_banned=1, ban_reason=?, ban_expires=?, banned_by=?, banned_at=NOW() WHERE id=?")
           ->execute([$reason, $expires, $_SESSION['user_id'], $uid]);
        mdt_audit_log((int) $_SESSION['user_id'], 'STAFF', 'Bannissement', 'Utilisateur ID ' . $uid . ' banni : ' . $reason, 'security', null);
        $success = 'Utilisateur banni.';
    }

    if ($action === 'unban') {
        $uid = (int)$_POST['user_id'];
        $db->prepare("UPDATE users SET is_banned=0, ban_reason=NULL, ban_expires=NULL, banned_by=NULL, banned_at=NULL WHERE id=?")->execute([$uid]);
        mdt_audit_log((int) $_SESSION['user_id'], 'STAFF', 'Débannissement', 'Utilisateur ID ' . $uid . ' — ban levé', 'security', null);
        $success = 'Bannissement levé.';
    }
}

// Pré-remplir si ban_user passé en GET
$preBanUser = null;
if (isset($_GET['ban_user'])) {
    $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['ban_user']]);
    $preBanUser = $stmt->fetch();
    if ($preBanUser) $openModal_js = true;
}

// ── LOAD BANNED ──
$bannedUsers = $db->query("
    SELECT u.*, b.username as banner_name
    FROM users u
    LEFT JOIN users b ON u.banned_by = b.id
    WHERE u.is_banned = 1
    ORDER BY u.banned_at DESC
")->fetchAll();

// Non-banned users for ban form
$nonBanned = $db->query("SELECT id, username FROM users WHERE is_banned = 0 ORDER BY username")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Bannissements</h1>
        <p><?php echo count($bannedUsers); ?> utilisateur(s) banni(s)</p>
    </div>
    <div class="page-header-right">
        <button class="btn btn-danger" onclick="openModal('modal-ban')">
            <i class="fa-solid fa-ban"></i> Bannir un utilisateur
        </button>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<?php if (empty($bannedUsers)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fa-solid fa-ban"></i>
            <p>Aucun utilisateur banni pour le moment.</p>
        </div>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Raison</th>
                    <th>Durée</th>
                    <th>Expiration</th>
                    <th>Banni par</th>
                    <th>Date du ban</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bannedUsers as $u): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="u-ava" style="background:var(--red);">
                                <?php echo strtoupper(substr($u['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($u['username']); ?></div>
                                <div style="font-size:.72rem;color:var(--text-muted);">#<?php echo $u['id']; ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="max-width:220px;">
                        <span style="color:var(--text-secondary);font-size:.83rem;"><?php echo htmlspecialchars($u['ban_reason'] ?? '—'); ?></span>
                    </td>
                    <td>
                        <?php if (!$u['ban_expires']): ?>
                            <span class="badge badge-red">Permanent</span>
                        <?php else: ?>
                            <span class="badge badge-orange">Temporaire</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;font-family:'DM Mono',monospace;color:var(--text-secondary);">
                        <?php echo $u['ban_expires'] ? date('d/m/Y H:i', strtotime($u['ban_expires'])) : '∞'; ?>
                    </td>
                    <td style="font-size:.83rem;">
                        <?php echo htmlspecialchars($u['banner_name'] ?? 'Système'); ?>
                    </td>
                    <td style="font-size:.78rem;font-family:'DM Mono',monospace;color:var(--text-secondary);">
                        <?php echo $u['banned_at'] ? date('d/m/Y H:i', strtotime($u['banned_at'])) : '—'; ?>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="action" value="unban">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="fa-solid fa-unlock"></i> Lever le ban
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: Bannir -->
<div class="modal-overlay" id="modal-ban" <?php if (!empty($openModal_js)) echo 'style="display:grid;"'; ?>>
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-ban" style="color:var(--red);margin-right:8px;"></i> Bannir un utilisateur</h3>
            <button class="modal-close" onclick="closeModal('modal-ban')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="ban">
            <div class="form-group">
                <label class="form-label">Utilisateur</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Sélectionner...</option>
                    <?php foreach ($nonBanned as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo (isset($preBanUser) && $preBanUser['id']==$u['id']) ? 'selected':''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?> (#<?php echo $u['id']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Raison</label>
                <textarea name="reason" class="form-control" rows="3" placeholder="Motif du bannissement..." required></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Durée</label>
                <select name="duration" class="form-control">
                    <option value="permanent">Permanent</option>
                    <option value="1 day">1 jour</option>
                    <option value="3 days">3 jours</option>
                    <option value="7 days">7 jours</option>
                    <option value="14 days">14 jours</option>
                    <option value="30 days">30 jours</option>
                </select>
            </div>
            <div class="alert alert-danger" style="margin-bottom:0;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Le joueur sera immédiatement déconnecté et ne pourra plus se connecter.</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-ban')">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban"></i> Bannir</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>