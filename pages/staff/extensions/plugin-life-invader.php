<?php
// pages/admin/extensions/plugin-life-invader.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Plugin Life Invader';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Life Invader']];
$success = '';

$settings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'life_invader_%'")->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (Exception $e) {}

function getSetting($k, $d='') { global $settings; return $settings[$k] ?? $d; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['life_invader_site_active','life_invader_site_name','life_invader_tagline','life_invader_primary_color','life_invader_allow_comments','life_invader_public_access'];
    foreach ($fields as $f) {
        $val = isset($_POST[$f]) ? (is_array($_POST[$f]) ? implode(',', $_POST[$f]) : trim($_POST[$f])) : '0';
        if (in_array($f, ['life_invader_site_active','life_invader_allow_comments','life_invader_public_access'])) {
            $val = isset($_POST[$f]) ? '1' : '0';
        }
        $db->prepare("INSERT INTO admin_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$f,$val,$val]);
        $settings[$f] = $val;
    }
    $success = 'Configuration Life Invader sauvegardée.';
}

$isActive = (bool)getSetting('life_invader_site_active', '0');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-clapperboard" style="color:#6366f1;margin-right:10px;"></i> Plugin Life Invader</h1>
        <p>Gérez le site web intégré de Life Invader</p>
    </div>
    <div class="page-header-right">
        <a href="addons.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Retour aux addons</a>
        <?php if ($isActive): ?>
            <a href="<?php echo SITE_URL; ?>/life-invader" target="_blank" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir le site
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<form method="POST">
    <div class="grid-2" style="gap:20px;align-items:start;">

        <!-- Main toggle -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-power-off"></i> Activation</div>
            </div>
            <div class="card-body">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:<?php echo $isActive?'rgba(99,102,241,.08)':'rgba(255,255,255,.03)'; ?>;border:1px solid <?php echo $isActive?'rgba(99,102,241,.3)':'var(--border)'; ?>;border-radius:12px;margin-bottom:16px;">
                    <div>
                        <div style="font-weight:700;margin-bottom:2px;">Site Life Invader</div>
                        <div style="font-size:.78rem;color:var(--text-secondary);">
                            <?php echo $isActive ? 'Le site est actuellement en ligne' : 'Le site est désactivé'; ?>
                        </div>
                    </div>
                    <label class="toggle" style="transform:scale(1.2);">
                        <input type="checkbox" name="life_invader_site_active" <?php echo $isActive?'checked':''; ?>>
                        <span class="toggle-slider" style="background:<?php echo $isActive?'#6366f1':'var(--text-muted)'; ?>;"></span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Accès public</label>
                    <div class="toggle-wrap">
                        <label class="toggle">
                            <input type="checkbox" name="life_invader_public_access" <?php echo getSetting('life_invader_public_access','0')?'checked':''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Accessible sans connexion MDT</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Commentaires</label>
                    <div class="toggle-wrap">
                        <label class="toggle">
                            <input type="checkbox" name="life_invader_allow_comments" <?php echo getSetting('life_invader_allow_comments','1')?'checked':''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Activer les commentaires sur les articles</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customization -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-palette"></i> Personnalisation</div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Nom du site</label>
                    <input type="text" name="life_invader_site_name" class="form-control"
                           value="<?php echo htmlspecialchars(getSetting('life_invader_site_name','Life Invader')); ?>"
                           placeholder="Life Invader">
                </div>
                <div class="form-group">
                    <label class="form-label">Slogan</label>
                    <input type="text" name="life_invader_tagline" class="form-control"
                           value="<?php echo htmlspecialchars(getSetting('life_invader_tagline','Your daily source of information')); ?>"
                           placeholder="Your daily source of information">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Couleur principale</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="life_invader_primary_color" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('life_invader_primary_color','#6366f1')); ?>"
                               style="height:42px;padding:4px;width:56px;flex-shrink:0;">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(getSetting('life_invader_primary_color','#6366f1')); ?>" placeholder="#6366f1">
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:20px;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
