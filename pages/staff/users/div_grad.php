<?php
/**
 * Catalogue mdt_ref : unités de division + grades (mdt_ref_division_unit / mdt_ref_division_grade).
 */
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    redirect(SITE_URL . '/pages/staff/users/index.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Division & Grade';
$breadcrumb = [['label' => 'Utilisateurs', 'url' => SITE_URL . '/pages/staff/users/index.php'], ['label' => 'Division & Grade']];
$success = '';
$error   = '';

if (empty($_SESSION['staff_div_grad_csrf'])) {
    $_SESSION['staff_div_grad_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['staff_div_grad_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $tok)) {
        $error = 'Session expirée. Réessayez.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'mdt_add_division_unit') {
            $scopeId   = (int) ($_POST['scope_id'] ?? 0);
            $unitKey   = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', (string) ($_POST['unit_key'] ?? '')));
            $unitName  = trim((string) ($_POST['unit_name'] ?? ''));
            $sortOrder = (int) ($_POST['unit_sort'] ?? 99);

            if ($scopeId <= 0 || $unitKey === '' || $unitName === '') {
                $error = 'Clé technique, nom et périmètre requis.';
            } else {
                $dupStmt = $db->prepare(
                    'SELECT u.id, u.name, s.label FROM mdt_ref_division_unit u
                     INNER JOIN mdt_ref_division_scope s ON s.id = u.scope_id
                     WHERE u.scope_id = ? AND u.unit_key = ? LIMIT 1'
                );
                $dupStmt->execute([$scopeId, $unitKey]);
                $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dupRow) {
                    $error = 'La clé « ' . htmlspecialchars($unitKey, ENT_QUOTES, 'UTF-8')
                        . ' » existe déjà pour ce périmètre (contrainte unique « périmètre + clé »). '
                        . 'Référence : « ' . htmlspecialchars((string) $dupRow['name'], ENT_QUOTES, 'UTF-8') . ' » ('
                        . htmlspecialchars((string) $dupRow['label'], ENT_QUOTES, 'UTF-8') . '). '
                        . 'Choisissez une autre clé ou modifiez l’unité existante en base.';
                } else {
                    try {
                        $db->beginTransaction();

                        $db->prepare(
                            'INSERT INTO mdt_ref_division_unit (scope_id, unit_key, name, sort_order) VALUES (?,?,?,?)'
                        )->execute([$scopeId, $unitKey, $unitName, $sortOrder]);

                        $skStmt = $db->prepare('SELECT scope_key FROM mdt_ref_division_scope WHERE id = ?');
                        $skStmt->execute([$scopeId]);
                        $sk = (string) $skStmt->fetchColumn();

                        if ($sk === 'LSPD_DIV') {
                            $db->prepare(
                                'INSERT INTO lspd_divisions (name, abbreviation, description, min_rank, color, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())'
                            )->execute([$unitName, $unitKey, 'Division ' . $unitName, 'Police Officer I', '#2563eb', 1]);
                        }
                        if ($sk === 'USSS_DIV') {
                            $db->prepare(
                                "INSERT INTO `usss_divisions` (nom, code, description, chef_division, budget_alloue, niveau_securite, effectif_actuel, ordre, actif, created_at, updated_at) VALUES (?,?,?,NULL,0.00,'CONFIDENTIEL',0,?,1,NOW(),NOW())"
                            )->execute([$unitName, $unitKey, 'Division ' . $unitName, $sortOrder]);
                        }
                        $pfx = [
                            'LSPD_DIV'   => 'DIV_LSPD_',
                            'SAMS_DIV'   => 'DIV_SAMS_',
                            'USSS_DIV'   => 'DIV_USSS_',
                            'LSSD_DIV'   => 'DIV_LSSD_',
                            'GRUPP6_DIV' => 'DIV_GRUPP6_',
                        ][$sk] ?? 'DIV_';
                        try {
                            $db->prepare('INSERT INTO admin_panels (panel_key, is_active, updated_at) VALUES (?,?,NOW())')
                                ->execute([$pfx . $unitKey, 1]);
                        } catch (Exception $e) {
                            /* clé déjà présente */
                        }

                        $db->commit();
                        $success = 'Unité de division ajoutée (catalogue mdt_ref + panneau admin).';
                        $_SESSION['staff_div_grad_csrf'] = bin2hex(random_bytes(16));
                        $csrf = $_SESSION['staff_div_grad_csrf'];
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Division : ' . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'mdt_add_division_grade') {
            $unitId = (int) ($_POST['division_unit_id'] ?? 0);
            $gname  = trim((string) ($_POST['div_grade_name'] ?? ''));
            $gRank  = (int) ($_POST['div_grade_rank'] ?? 99);
            $track  = trim((string) ($_POST['div_grade_track'] ?? ''));
            $track  = $track === '' ? null : substr($track, 0, 16);

            if ($unitId <= 0 || $gname === '') {
                $error = 'Unité et nom de grade requis.';
            } else {
                try {
                    $db->prepare(
                        'INSERT INTO mdt_ref_division_grade (division_unit_id, grade_name, rank_order, track_code) VALUES (?,?,?,?)'
                    )->execute([$unitId, $gname, $gRank, $track]);
                    $success = 'Grade de division ajouté au catalogue (mdt_ref_division_grade).';
                    $_SESSION['staff_div_grad_csrf'] = bin2hex(random_bytes(16));
                    $csrf = $_SESSION['staff_div_grad_csrf'];
                } catch (Exception $e) {
                    $error = 'Grade division : ' . $e->getMessage();
                }
            }
        }
    }
}

$mdtDivisionScopes = [];
$mdtDivisionUnits  = [];
try {
    $mdtDivisionScopes = $db->query('SELECT id, scope_key, label FROM mdt_ref_division_scope ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $mdtDivisionUnits = $db->query('
        SELECT u.id, u.unit_key, u.name, u.sort_order, s.scope_key, s.label AS scope_label
        FROM mdt_ref_division_unit u
        JOIN mdt_ref_division_scope s ON s.id = u.scope_id
        ORDER BY s.sort_order, u.sort_order
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-layer-group" style="color:var(--blue);"></i> Division & Grade</h1>
        <p>Catalogue <code>mdt_ref</code> : unités par organisation (LSPD, SAMS, USSS, LSSD, Grupp 6) et grades internes.</p>
    </div>
    <div class="page-header-right">
        <a href="<?php echo SITE_URL; ?>/pages/staff/users/index.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Utilisateurs</a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($mdtDivisionScopes)): ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-database"></i> Tables <code>mdt_ref_*</code> introuvables. Importez <code>database/mdt_ref_v2_full.sql</code>.
</div>
<?php else: ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-body">
        <p style="margin:0 0 16px;font-size:.85rem;color:var(--text-secondary);line-height:1.55;">
            Pour réinitialiser tout le référentiel + <code>faction_grades</code>, exécutez
            <code>database/mdt_ref_v2_full.sql</code> puis <code>database/migration_nexium_default_grades_divisions.sql</code>.
            Les <strong>panels opérationnels</strong> (liens dashboard) se gèrent sous
            <a href="<?php echo SITE_URL; ?>/pages/staff/content/division.php" style="color:var(--blue);">Contenu → Divisions</a>.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
            <form method="post" style="background:var(--bg-overlay);border:1px solid var(--border);border-radius:12px;padding:14px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="mdt_add_division_unit">
                <div style="font-weight:700;font-size:.85rem;margin-bottom:10px;">Nouvelle unité de division</div>
                <label class="form-label">Périmètre</label>
                <select name="scope_id" class="form-control" required style="margin-bottom:10px;">
                    <?php foreach ($mdtDivisionScopes as $sc): ?>
                        <option value="<?php echo (int) $sc['id']; ?>"><?php echo htmlspecialchars($sc['label'] . ' (' . $sc['scope_key'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label">Clé technique (ex. SWAT)</label>
                <input type="text" name="unit_key" class="form-control" required placeholder="SWAT" style="margin-bottom:10px;" maxlength="32" autocomplete="off">
                <label class="form-label">Nom affiché</label>
                <input type="text" name="unit_name" class="form-control" required placeholder="Special Weapons and Tactics" style="margin-bottom:10px;">
                <label class="form-label">Ordre de tri</label>
                <input type="number" name="unit_sort" class="form-control" value="99" style="margin-bottom:12px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Ajouter l’unité</button>
            </form>
            <form method="post" style="background:var(--bg-overlay);border:1px solid var(--border);border-radius:12px;padding:14px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="mdt_add_division_grade">
                <div style="font-weight:700;font-size:.85rem;margin-bottom:10px;">Nouveau grade de division</div>
                <label class="form-label">Unité</label>
                <select name="division_unit_id" class="form-control" required style="margin-bottom:10px;">
                    <option value="">— Choisir —</option>
                    <?php foreach ($mdtDivisionUnits as $u): ?>
                        <option value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars($u['scope_label'] . ' · ' . $u['unit_key'] . ' — ' . $u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label">Nom du grade</label>
                <input type="text" name="div_grade_name" class="form-control" required placeholder="Commanding Officer" style="margin-bottom:10px;">
                <label class="form-label">Rang (ordre)</label>
                <input type="number" name="div_grade_rank" class="form-control" value="1" style="margin-bottom:10px;">
                <label class="form-label">Piste (GND / MCD / BOTH — optionnel)</label>
                <input type="text" name="div_grade_track" class="form-control" placeholder="" style="margin-bottom:12px;" maxlength="16">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Ajouter le grade</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
