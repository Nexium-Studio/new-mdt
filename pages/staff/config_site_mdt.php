<?php
declare(strict_types=1);

define('STAFF_PANEL', true);
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'Configuration MDT';
$breadcrumb = [
    ['label' => 'Configuration', 'url' => SITE_URL . '/pages/staff/settings.php'],
    ['label' => 'Config site MDT'],
];
$success = '';
$error = '';

if (!isset($_SESSION['staff_mdt_config_csrf'])) {
    $_SESSION['staff_mdt_config_csrf'] = bin2hex(random_bytes(16));
}

$defaults = [
    'site_name' => defined('SITE_NAME') ? (string) SITE_NAME : 'MDT',
    'site_url' => defined('SITE_URL') ? (string) SITE_URL : '',
    'site_description' => 'Plateforme MDT',
    'site_tagline' => 'Plateforme MDT nouvelle generation',
    'timezone' => defined('MDT_TIMEZONE') ? (string) MDT_TIMEZONE : 'Europe/Paris',
    'admin_email' => defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '',
    'bot_api_url' => defined('BOT_API_URL') ? (string) BOT_API_URL : '',
    'nexium_updates_api_base' => defined('NEXIUM_UPDATES_API_BASE') ? (string) NEXIUM_UPDATES_API_BASE : 'https://nexium-studio.com',
];

$keys = array_keys($defaults);
$settings = array_merge($defaults, mdt_admin_settings_fetch($db, $keys));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config_site') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['staff_mdt_config_csrf'] ?? ''), $csrf)) {
        $error = 'Session expirée. Rechargez la page.';
    } else {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $siteUrl = mdt_validate_site_url((string) ($_POST['site_url'] ?? ''), $defaults['site_url']);
        $siteDescription = trim((string) ($_POST['site_description'] ?? ''));
        $siteTagline = trim((string) ($_POST['site_tagline'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? $defaults['timezone']));
        $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
        $botApiUrl = mdt_validate_site_url((string) ($_POST['bot_api_url'] ?? ''), '');
        $updatesBase = mdt_validate_site_url((string) ($_POST['nexium_updates_api_base'] ?? ''), $defaults['nexium_updates_api_base']);

        if ($siteName === '' || mb_strlen($siteName) > 100) {
            $error = 'Le nom du site est obligatoire (max 100 caractères).';
        } elseif ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email administrateur invalide.';
        } elseif (!in_array($timezone, timezone_identifiers_list(), true)) {
            $error = 'Fuseau horaire invalide.';
        } else {
            $payload = [
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'site_description' => mb_substr($siteDescription, 0, 255),
                'site_tagline' => mb_substr($siteTagline, 0, 160),
                'timezone' => $timezone,
                'admin_email' => mb_substr($adminEmail, 0, 190),
                'bot_api_url' => $botApiUrl,
                'nexium_updates_api_base' => $updatesBase,
            ];

            try {
                foreach ($payload as $k => $v) {
                    mdt_admin_settings_upsert($db, $k, $v);
                    $settings[$k] = $v;
                }
                $success = 'Configuration du site MDT mise à jour.';
                mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'STAFF', 'Configuration site MDT', 'Paramètres généraux modifiés', 'system', null);
            } catch (Throwable $e) {
                $error = 'Erreur lors de la sauvegarde : ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Configuration du site MDT</h1>
        <p>Gérez les paramètres globaux sans modifier les fichiers manuellement.</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="post">
    <input type="hidden" name="action" value="save_config_site">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string) ($_SESSION['staff_mdt_config_csrf'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-globe"></i> Informations du site</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Nom du site</label>
                    <input class="form-control" type="text" name="site_name" maxlength="100" required value="<?php echo htmlspecialchars((string) $settings['site_name']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">URL du site</label>
                    <input class="form-control" type="url" name="site_url" placeholder="https://mdt.example.com" value="<?php echo htmlspecialchars((string) $settings['site_url']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="site_description" rows="3" maxlength="255"><?php echo htmlspecialchars((string) $settings['site_description']); ?></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Slogan / baseline</label>
                    <input class="form-control" type="text" name="site_tagline" maxlength="160" value="<?php echo htmlspecialchars((string) $settings['site_tagline']); ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-gears"></i> Services & API</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Fuseau horaire</label>
                    <select class="form-control" name="timezone">
                        <?php foreach (['Europe/Paris', 'Europe/London', 'UTC', 'America/New_York'] as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo ((string) $settings['timezone'] === $tz) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tz); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Email administrateur</label>
                    <input class="form-control" type="email" name="admin_email" maxlength="190" value="<?php echo htmlspecialchars((string) $settings['admin_email']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">URL API Bot</label>
                    <input class="form-control" type="url" name="bot_api_url" placeholder="https://api.example.com/bot" value="<?php echo htmlspecialchars((string) $settings['bot_api_url']); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">URL API Nexium (updates)</label>
                    <input class="form-control" type="url" name="nexium_updates_api_base" placeholder="https://nexium-studio.com" value="<?php echo htmlspecialchars((string) $settings['nexium_updates_api_base']); ?>">
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end;">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder la configuration</button>
    </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
