<?php
// pages/staff/extensions/addons.php — activation selon la licence Nexium (cache API)
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Extensions & Addons';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Addons']];
$success = $error = '';

if (empty($_SESSION['staff_addons_csrf'])) {
    $_SESSION['staff_addons_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['staff_addons_csrf'];

/** Droit d’activation : produit actif sur la licence (+ MDT core pour les addons « enfants » de mdt). */
function mdt_addon_entitled(string $productSlug): bool
{
    if (License::isMdtDependentAddon($productSlug)) {
        return License::isProductActive($productSlug) && License::isMDTActive();
    }
    return License::isProductActive($productSlug);
}

$addonsCatalog = [
    [
        'id'            => 'addon_dispatch',
        'product_slug'  => 'addon_dispatch',
        'name'          => 'Dispatch',
        'desc'          => 'Console de dispatch en temps réel pour les services (appels, unités, carte).',
        'icon'          => 'radio',
        'color'         => '#10b981',
        'version'       => '2.1',
        'price'         => 59.00,
        'active_key'    => 'addon_dispatch',
        'features'      => ['Carte interactive', 'Gestion des appels', 'Suivi des unités', 'Historique'],
    ],
    [
        'id'            => 'addon_mail',
        'product_slug'  => 'addon_mail',
        'name'          => 'Gestionnaire Mail',
        'desc'          => 'Messagerie interne : boîtes factions, comptes RP, modération staff.',
        'icon'          => 'envelope-open-text',
        'color'         => '#f59e0b',
        'version'       => '1.0',
        'price'         => 39.00,
        'active_key'    => 'mail_enabled',
        'features'      => ['Boîtes par faction', 'Comptes personnels', 'Pièces jointes', 'Logs staff'],
    ],
    [
        'id'            => 'addon_hack_mdt',
        'product_slug'  => 'addon_hack_mdt',
        'name'          => 'Hack MDT',
        'desc'          => 'Module hacking avancé (état du MDT, logs, gestion côté staff).',
        'icon'          => 'skull-crossbones',
        'color'         => '#a855f7',
        'version'       => '1.0',
        'price'         => 49.00,
        'active_key'    => 'addon_hack_mdt',
        'features'      => ['Écran hack joueur', 'Gestion staff', 'Journal des événements'],
    ],
    [
        'id'            => 'life_invader',
        'product_slug'  => 'life_invader',
        'name'          => 'Life Invader',
        'desc'          => 'Site web intégré Life Invader : articles, communiqués, espace journalistes.',
        'icon'          => 'clapperboard',
        'color'         => '#6366f1',
        'version'       => '1.3',
        'price'         => 0,
        'active_key'    => 'addon_life_invader',
        'features'      => ['Site public', 'Éditeur d’articles', 'Communiqués', 'Statistiques'],
    ],
    [
        'id'            => 'bot_911',
        'product_slug'  => 'bot_911',
        'name'          => 'Bot 911',
        'desc'          => 'Produit licence « Bot 911 » (intégration FiveM / téléphonie selon votre offre Nexium).',
        'icon'          => 'robot',
        'color'         => '#ef4444',
        'version'       => '1.0',
        'price'         => 0,
        'active_key'    => 'addon_bot_911',
        'features'      => ['Indicateur licence', 'Prêt pour branchement serveur'],
    ],
];

$settings = [];
try {
    $keys = array_map(static fn ($a) => $a['active_key'], $addonsCatalog);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {
}

function addons_get_setting(array $settings, string $k, string $d = ''): string
{
    return $settings[$k] ?? $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $tok)) {
        $error = 'Session expirée ou jeton invalide. Réessayez.';
    } else {
        $action  = $_POST['action'] ?? '';
        $addonId = $_POST['addon_id'] ?? '';

        if ($action === 'refresh_license') {
            License::refresh();
            $success = 'Statut de la licence mis à jour depuis l’API Nexium.';
            $_SESSION['staff_addons_csrf'] = bin2hex(random_bytes(16));
            $csrf = $_SESSION['staff_addons_csrf'];
        }

        $addon = null;
        foreach ($addonsCatalog as $a) {
            if ($a['id'] === $addonId) {
                $addon = $a;
                break;
            }
        }

        if ($action === 'activate' && $addon) {
            if (!mdt_addon_entitled($addon['product_slug'])) {
                $error = 'Ce module n’est pas inclus dans votre licence Nexium (ou votre MDT principal n’est pas actif). Mettez à jour votre licence ou contactez le support.';
            } else {
                $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,1) ON DUPLICATE KEY UPDATE setting_value=1');
                $stmt->execute([$addon['active_key']]);
                $settings[$addon['active_key']] = '1';
                $success = $addon['name'] . ' a été activé.';
                $_SESSION['staff_addons_csrf'] = bin2hex(random_bytes(16));
                $csrf = $_SESSION['staff_addons_csrf'];
            }
        }

        if ($action === 'deactivate' && $addon) {
            $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,0) ON DUPLICATE KEY UPDATE setting_value=0');
            $stmt->execute([$addon['active_key']]);
            $settings[$addon['active_key']] = '0';
            $success = $addon['name'] . ' a été désactivé.';
            $_SESSION['staff_addons_csrf'] = bin2hex(random_bytes(16));
            $csrf = $_SESSION['staff_addons_csrf'];
        }
    }
}

$licenseStatus = License::getStatus(false);
$ls = $licenseStatus['license_status'] ?? 'unknown';
$licenseBanner = null;
if (in_array($ls, ['misconfigured_hmac', 'invalid_signature'], true)) {
    $licenseBanner = [
        'type' => 'danger',
        'text' => 'La vérification signée de la licence a échoué. Vérifiez <code>NEXIUM_API_HMAC_KEY</code> dans <code>config.local.php</code> (même valeur que <code>API_SECRET_KEY</code> côté API Nexium), puis enregistrez votre clé dans Paramètres.',
    ];
} elseif ($ls === 'no_key') {
    $licenseBanner = [
        'type' => 'warning',
        'text' => 'Aucune clé de licence enregistrée. Ajoutez-la dans Paramètres &gt; Général pour que les droits produits soient vérifiés.',
    ];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Extensions & Addons</h1>
        <p>Activez les modules présents sur votre licence Nexium Studio</p>
    </div>
    <div class="page-header-right">
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="refresh_license">
            <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-arrows-rotate"></i> Rafraîchir la licence</button>
        </form>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($licenseBanner): ?>
    <div class="alert alert-<?php echo $licenseBanner['type'] === 'danger' ? 'danger' : 'warning'; ?>" style="margin-bottom:24px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><?php echo $licenseBanner['text']; ?></span>
    </div>
<?php endif; ?>

<div class="alert alert-info" style="margin-bottom:24px;">
    <i class="fa-solid fa-circle-info"></i>
    <span>L’activation est autorisée uniquement si le produit figure sur votre <strong>licence master</strong> (vérifiée via l’API). Les addons Dispatch, Mail et Hack nécessitent en plus un <strong>abonnement MDT</strong> actif sur la même clé.</span>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(480px,1fr));gap:20px;">
    <?php foreach ($addonsCatalog as $addon):
        $isActive    = (bool) addons_get_setting($settings, $addon['active_key'], '0');
        $entitled    = mdt_addon_entitled($addon['product_slug']);
        $needsMdt    = License::isMdtDependentAddon($addon['product_slug']);
        $mdtOk       = License::isMDTActive();
        $productOnly = License::isProductActive($addon['product_slug']);
        ?>
    <div class="card" style="border-color:<?php echo $isActive ? 'rgba(16,185,129,.25)' : 'var(--border)'; ?>; <?php echo $isActive ? 'background:rgba(16,185,129,.03);' : ''; ?>">
        <div class="card-body">
            <div style="display:flex;align-items:flex-start;gap:16px;margin-bottom:18px;">
                <div style="width:56px;height:56px;border-radius:14px;background:<?php echo htmlspecialchars($addon['color']); ?>22;display:grid;place-items:center;font-size:1.4rem;color:<?php echo htmlspecialchars($addon['color']); ?>;flex-shrink:0;border:1px solid <?php echo htmlspecialchars($addon['color']); ?>44;">
                    <i class="fa-solid fa-<?php echo htmlspecialchars($addon['icon']); ?>"></i>
                </div>
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap;">
                        <h3 style="font-size:1.05rem;"><?php echo htmlspecialchars($addon['name']); ?></h3>
                        <span class="badge badge-slate" style="font-size:.6rem;">v<?php echo htmlspecialchars($addon['version']); ?></span>
                        <?php if ($isActive): ?>
                            <span class="badge badge-green"><i class="fa-solid fa-circle" style="font-size:6px;"></i> Actif</span>
                        <?php else: ?>
                            <span class="badge badge-slate">Inactif</span>
                        <?php endif; ?>
                    </div>
                    <p style="color:var(--text-secondary);font-size:.82rem;line-height:1.5;"><?php echo htmlspecialchars($addon['desc']); ?></p>
                    <p style="margin-top:8px;font-size:.72rem;color:var(--text-muted);font-family:monospace;"><?php echo htmlspecialchars($addon['product_slug']); ?></p>
                </div>
            </div>

            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="fa-solid fa-key" style="color:<?php echo $entitled ? 'var(--green)' : 'var(--red)'; ?>;"></i>
                    <div style="flex:1;">
                        <div style="font-size:.78rem;font-weight:600;">Licence Nexium</div>
                        <?php if ($entitled): ?>
                            <div style="font-size:.72rem;color:var(--green);">Produit autorisé sur votre clé</div>
                        <?php else: ?>
                            <div style="font-size:.72rem;color:var(--red);">
                                Non inclus ou inactif sur la licence
                                <?php if ($needsMdt && $productOnly && !$mdtOk): ?>
                                    <span style="display:block;margin-top:4px;color:var(--text-secondary);">Le produit est sur la licence, mais le <strong>MDT principal</strong> doit être actif pour activer cet addon.</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$entitled && $addon['price'] > 0): ?>
                        <div style="font-size:1.1rem;font-weight:800;color:var(--text-primary);">
                            <?php echo number_format($addon['price'], 2); ?> €
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-bottom:18px;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary);margin-bottom:8px;">Fonctionnalités</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach ($addon['features'] as $feat): ?>
                        <span style="font-size:.73rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;padding:3px 9px;color:var(--text-secondary);">
                            <i class="fa-solid fa-check" style="font-size:.6rem;color:var(--green);margin-right:4px;"></i><?php echo htmlspecialchars($feat); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="addon_id" value="<?php echo htmlspecialchars($addon['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($isActive): ?>
                    <input type="hidden" name="action" value="deactivate">
                    <button type="submit" class="btn btn-danger w-full" style="justify-content:center;">
                        <i class="fa-solid fa-power-off"></i> Désactiver
                    </button>
                <?php elseif ($entitled): ?>
                    <input type="hidden" name="action" value="activate">
                    <button type="submit" class="btn btn-success w-full" style="justify-content:center;">
                        <i class="fa-solid fa-download"></i> Activer l’addon
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-secondary w-full" style="justify-content:center;opacity:.65;cursor:not-allowed;" disabled title="Non disponible sur votre licence">
                        <i class="fa-solid fa-lock"></i> Non disponible sur la licence
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
