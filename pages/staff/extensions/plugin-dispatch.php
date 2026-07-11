<?php
// pages/admin/extensions/plugin-dispatch.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Plugin Dispatch';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Dispatch']];
$success = '';

$dispatchPanels = [
    ['key' => 'USSS', 'name' => 'USSS',   'icon' => 'user-police', 'color' => '#334155'],
    ['key' => 'LSPD', 'name' => 'LSPD',   'icon' => 'shield-halved','color' => '#2563eb'],
    ['key' => 'LSSD', 'name' => 'LSSD',   'icon' => 'star',         'color' => '#10b981'],
    ['key' => 'SAMS', 'name' => 'SAMS',   'icon' => 'kit-medical',  'color' => '#f43f5e'],
    ['key' => 'GRUPP6','name'=> 'Grupp 6','icon' => 'truck-shield', 'color' => '#64748b'],
];

// Load current dispatch activations
$dispatchActive = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'dispatch_panel_%'")->fetchAll();
    foreach ($rows as $r) $dispatchActive[$r['setting_key']] = (bool)$r['setting_value'];
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($dispatchPanels as $p) {
        $k     = 'dispatch_panel_' . strtolower($p['key']);
        $state = isset($_POST['panel_' . $p['key']]) ? 1 : 0;
        $db->prepare("INSERT INTO admin_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$state,$state]);
        $dispatchActive[$k] = (bool)$state;
    }
    $success = 'Configuration Dispatch sauvegardée.';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-radio" style="color:var(--cyan);margin-right:10px;"></i> Plugin Dispatch</h1>
        <p>Activez le module Dispatch sur les panels de service public</p>
    </div>
    <div class="page-header-right">
        <a href="addons.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Retour aux addons</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="alert alert-info" style="margin-bottom:24px;">
    <i class="fa-solid fa-circle-info"></i>
    <span>Activer le Dispatch sur un panel ajoute une page dédiée dans le menu de ce panel. Les agents peuvent alors gérer les appels 911, suivre les unités sur carte, etc.</span>
</div>

<form method="POST">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;margin-bottom:24px;">
        <?php foreach ($dispatchPanels as $p):
            $k = 'dispatch_panel_' . strtolower($p['key']);
            $isOn = $dispatchActive[$k] ?? false;
        ?>
        <div class="maintenance-card <?php echo $isOn ? 'on' : ''; ?>" style="<?php echo $isOn ? "border-color:{$p['color']}44;" : ''; ?>">
            <div class="mc-info">
                <div class="mc-icon" style="color:<?php echo $p['color']; ?>;background:<?php echo $p['color']; ?>18;">
                    <i class="fa-solid fa-<?php echo $p['icon']; ?>"></i>
                </div>
                <div>
                    <div class="mc-name"><?php echo htmlspecialchars($p['name']); ?></div>
                    <div class="mc-desc"><?php echo $isOn ? 'Dispatch activé' : 'Dispatch inactif'; ?></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <?php if ($isOn): ?>
                    <span class="badge badge-cyan"><i class="fa-solid fa-radio"></i> ON</span>
                <?php endif; ?>
                <label class="toggle">
                    <input type="checkbox" name="panel_<?php echo $p['key']; ?>" <?php echo $isOn ? 'checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
