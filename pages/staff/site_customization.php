<?php
declare(strict_types=1);

define('STAFF_PANEL', true);
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'Personnalisation';
$breadcrumb = [
    ['label' => 'Configuration', 'url' => SITE_URL . '/pages/staff/settings.php'],
    ['label' => 'Personnalisation du site'],
];
$success = '';
$error = '';

if (!isset($_SESSION['staff_mdt_custom_csrf'])) {
    $_SESSION['staff_mdt_custom_csrf'] = bin2hex(random_bytes(16));
}

$defaults = [
    'theme_mode' => 'dark',
    'primary_color' => '#3B82F6',
    'secondary_color' => '#6366F1',
    'logo_url' => '',
    'favicon_url' => '',
    'home_title' => 'Le MDT nouvelle generation',
    'home_message' => 'Une plateforme centralisee pour vos services.',
    'login_message' => 'Connectez-vous pour acceder au panel.',
];
$keys = array_keys($defaults);
$settings = array_merge($defaults, mdt_admin_settings_fetch($db, $keys));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_customization') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['staff_mdt_custom_csrf'] ?? ''), $csrf)) {
        $error = 'Session expirée. Rechargez la page.';
    } else {
        $themeMode = mdt_validate_theme_mode((string) ($_POST['theme_mode'] ?? 'dark'), 'dark');
        $primaryColor = mdt_validate_hex_color((string) ($_POST['primary_color'] ?? ''), '#3B82F6');
        $secondaryColor = mdt_validate_hex_color((string) ($_POST['secondary_color'] ?? ''), '#6366F1');
        $logoUrl = mdt_validate_site_url((string) ($_POST['logo_url'] ?? ''), '');
        $faviconUrl = mdt_validate_site_url((string) ($_POST['favicon_url'] ?? ''), '');
        $homeTitle = trim((string) ($_POST['home_title'] ?? ''));
        $homeMessage = trim((string) ($_POST['home_message'] ?? ''));
        $loginMessage = trim((string) ($_POST['login_message'] ?? ''));

        if ($homeTitle !== '' && mb_strlen($homeTitle) > 120) {
            $error = 'Le titre de la page d’accueil est trop long (120 caractères max).';
        } elseif ($homeMessage !== '' && mb_strlen($homeMessage) > 280) {
            $error = 'Le message d’accueil est trop long (280 caractères max).';
        } elseif ($loginMessage !== '' && mb_strlen($loginMessage) > 200) {
            $error = 'Le message de connexion est trop long (200 caractères max).';
        } else {
            $payload = [
                'theme_mode' => $themeMode,
                'primary_color' => $primaryColor,
                'secondary_color' => $secondaryColor,
                'logo_url' => $logoUrl,
                'favicon_url' => $faviconUrl,
                'home_title' => mb_substr($homeTitle, 0, 120),
                'home_message' => mb_substr($homeMessage, 0, 280),
                'login_message' => mb_substr($loginMessage, 0, 200),
            ];

            try {
                foreach ($payload as $k => $v) {
                    mdt_admin_settings_upsert($db, $k, $v);
                    $settings[$k] = $v;
                }
                $success = 'Personnalisation du site enregistrée.';
                mdt_audit_log((int) ($_SESSION['user_id'] ?? 0), 'STAFF', 'Personnalisation MDT', 'Theme, branding et textes modifiés', 'system', null);
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
        <h1>Personnalisation du site MDT</h1>
        <p>Couleurs, logo, favicon, thème et textes pilotés depuis le panel staff.</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="post">
    <input type="hidden" name="action" value="save_customization">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string) ($_SESSION['staff_mdt_custom_csrf'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-palette"></i> Design</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Thème</label>
                    <select class="form-control" name="theme_mode">
                        <option value="dark" <?php echo ((string) $settings['theme_mode'] === 'dark') ? 'selected' : ''; ?>>Sombre</option>
                        <option value="light" <?php echo ((string) $settings['theme_mode'] === 'light') ? 'selected' : ''; ?>>Clair</option>
                        <option value="auto" <?php echo ((string) $settings['theme_mode'] === 'auto') ? 'selected' : ''; ?>>Auto (OS utilisateur)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur principale</label>
                    <input class="form-control" type="text" name="primary_color" maxlength="7" placeholder="#3B82F6" value="<?php echo htmlspecialchars((string) $settings['primary_color']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur secondaire</label>
                    <input class="form-control" type="text" name="secondary_color" maxlength="7" placeholder="#6366F1" value="<?php echo htmlspecialchars((string) $settings['secondary_color']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Logo (URL)</label>
                    <input class="form-control" type="url" name="logo_url" placeholder="https://cdn.example.com/logo.png" value="<?php echo htmlspecialchars((string) $settings['logo_url']); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Favicon (URL)</label>
                    <input class="form-control" type="url" name="favicon_url" placeholder="https://cdn.example.com/favicon.ico" value="<?php echo htmlspecialchars((string) $settings['favicon_url']); ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-pen-to-square"></i> Contenu</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Titre d'accueil</label>
                    <input class="form-control" type="text" name="home_title" maxlength="120" value="<?php echo htmlspecialchars((string) $settings['home_title']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Message d'accueil</label>
                    <textarea class="form-control" name="home_message" rows="4" maxlength="280"><?php echo htmlspecialchars((string) $settings['home_message']); ?></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Message page de connexion</label>
                    <textarea class="form-control" name="login_message" rows="3" maxlength="200"><?php echo htmlspecialchars((string) $settings['login_message']); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end;">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder la personnalisation</button>
    </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
