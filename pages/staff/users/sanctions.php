<?php
// pages/admin/users/sanctions.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur'])) redirect(SITE_URL . '/pages/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Sanctions';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => 'index.php'], ['label' => 'Sanctions']];
$success = $error = '';

$sanctionTypes = [
    'Avertissement'       => ['color' => 'badge-yellow', 'icon' => 'triangle-exclamation'],
    'Bannissement Temp'   => ['color' => 'badge-orange', 'icon' => 'clock'],
    'Bannissement Def'    => ['color' => 'badge-red',    'icon' => 'ban'],
    'Révocation'          => ['color' => 'badge-red',    'icon' => 'user-slash'],
    'Suspension'          => ['color' => 'badge-orange', 'icon' => 'pause'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_sanction') {
        $uid    = (int)$_POST['user_id'];
        $type   = $_POST['type'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $durée  = trim($_POST['duration'] ?? '');
        if ($uid && $type && $reason) {
            try {
                $db->prepare("INSERT INTO sanctions (user_id, type, reason, notes, duration, issued_by, created_at) VALUES (?,?,?,?,?,?,NOW())")
                   ->execute([$uid, $type, $reason, $notes, $durée, $_SESSION['user_id']]);
                mdt_audit_log((int) $_SESSION['user_id'], 'STAFF', 'Sanction', 'Sanction ' . $type . ' — UID ' . $uid . ' : ' . $reason, 'security', null);
                $success = 'Sanction ajoutée.';
            } catch (Exception $e) { $error = 'Erreur: ' . $e->getMessage(); }
        } else { $error = 'Tous les champs obligatoires doivent être remplis.'; }
    }

    if ($action === 'delete_sanction') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM sanctions WHERE id = ?")->execute([$id]);
        mdt_audit_log((int) $_SESSION['user_id'], 'STAFF', 'Suppression sanction', 'Sanction ID ' . $id . ' supprimée', 'security', null);
        $success = 'Sanction supprimée.';
    }
}

// Filtres
$filterType = $_GET['type'] ?? '';
$filterUser = trim($_GET['user'] ?? '');
$preUserId  = (int)($_GET['user_id'] ?? 0);

$where = []; $params = [];
if ($filterType) { $where[] = 's.type = ?'; $params[] = $filterType; }
if ($filterUser) { $where[] = 'u.username LIKE ?'; $params[] = "%$filterUser%"; }
$wStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $sanctions = $db->prepare("
        SELECT s.*, u.username, u.avatar, staff.username as staff_name
        FROM sanctions s
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN users staff ON s.issued_by = staff.id
        $wStr
        ORDER BY s.created_at DESC
        LIMIT 100
    ");
    $sanctions->execute($params);
    $sanctions = $sanctions->fetchAll();
} catch (Exception $e) { $sanctions = []; }

$allUsers = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

// Pre-fill
$preUser = null;
if ($preUserId) {
    foreach ($allUsers as $u) if ($u['id'] == $preUserId) { $preUser = $u; break; }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Sanctions</h1>
        <p>Gérez les avertissements, suspensions et révocations</p>
    </div>
    <div class="page-header-right">
        <button class="btn btn-primary" onclick="openModal('modal-sanction')">
            <i class="fa-solid fa-gavel"></i> Ajouter une sanction
        </button>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- FILTERS -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="user" value="<?php echo htmlspecialchars($filterUser); ?>" placeholder="Rechercher joueur...">
            </div>
            <select name="type" class="form-control" style="width:auto;">
                <option value="">Tous les types</option>
                <?php foreach (array_keys($sanctionTypes) as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $filterType===$t?'selected':''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filtrer</button>
            <a href="sanctions.php" class="btn btn-secondary">Reset</a>
        </form>
    </div>
</div>

<!-- Stats par type -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <?php foreach ($sanctionTypes as $type => $meta):
        try { $cnt = $db->prepare("SELECT COUNT(*) FROM sanctions WHERE type=?"); $cnt->execute([$type]); $cnt = $cnt->fetchColumn(); } catch(Exception $e){ $cnt=0; }
    ?>
    <div class="card" style="flex:1;min-width:120px;padding:14px;display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-<?php echo $meta['icon']; ?>" style="font-size:1.1rem;color:var(--text-secondary);"></i>
        <div>
            <div style="font-size:.7rem;color:var(--text-secondary);"><?php echo $type; ?></div>
            <div style="font-weight:800;font-family:'Syne',sans-serif;font-size:1.2rem;"><?php echo $cnt; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Joueur</th>
                    <th>Type</th>
                    <th>Raison</th>
                    <th>Durée</th>
                    <th>Infligé par</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sanctions)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-secondary);">Aucune sanction</td></tr>
                <?php else: ?>
                <?php foreach ($sanctions as $s):
                    $meta = $sanctionTypes[$s['type']] ?? ['color' => 'badge-slate', 'icon' => 'gavel'];
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="u-ava" style="width:30px;height:30px;font-size:.75rem;">
                                <?php echo strtoupper(substr($s['username'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span style="font-weight:600;font-size:.875rem;"><?php echo htmlspecialchars($s['username'] ?? '—'); ?></span>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $meta['color']; ?>">
                            <i class="fa-solid fa-<?php echo $meta['icon']; ?>"></i>
                            <?php echo htmlspecialchars($s['type']); ?>
                        </span>
                    </td>
                    <td style="max-width:240px;color:var(--text-secondary);font-size:.83rem;"><?php echo htmlspecialchars($s['reason']); ?></td>
                    <td style="font-size:.78rem;color:var(--text-secondary);"><?php echo htmlspecialchars($s['duration'] ?: '—'); ?></td>
                    <td style="font-size:.83rem;"><?php echo htmlspecialchars($s['staff_name'] ?? 'Système'); ?></td>
                    <td style="font-size:.78rem;font-family:'DM Mono',monospace;color:var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($s['created_at'])); ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Supprimer cette sanction ?');">
                            <input type="hidden" name="action" value="delete_sanction">
                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger btn-icon-only">
                                <i class="fa-solid fa-trash" style="font-size:10px;"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: Sanction -->
<div class="modal-overlay" id="modal-sanction" <?php if ($preUser) echo 'style="display:grid;"'; ?>>
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-gavel" style="color:var(--orange);margin-right:8px;"></i> Ajouter une sanction</h3>
            <button class="modal-close" onclick="closeModal('modal-sanction')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_sanction">
            <div class="form-group">
                <label class="form-label">Joueur</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Sélectionner un joueur...</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($preUser && $preUser['id']==$u['id'])?'selected':''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Type de sanction</label>
                <select name="type" class="form-control" required>
                    <?php foreach (array_keys($sanctionTypes) as $t): ?>
                        <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Raison <span style="color:var(--red);">*</span></label>
                <textarea name="reason" class="form-control" rows="3" placeholder="Décrivez le motif de la sanction..." required></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Durée (si applicable)</label>
                <input type="text" name="duration" class="form-control" placeholder="Ex: 7 jours, 1 mois, Indéfinie...">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Notes internes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Notes visibles uniquement par le staff..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-sanction')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-gavel"></i> Appliquer</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
