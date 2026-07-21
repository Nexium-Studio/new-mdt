<?php
// pages/staff/settings.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/license_client.php';
require_once __DIR__ . '/../../includes/mdt_updater.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Paramètres';
$breadcrumb = [['label' => 'Paramètres']];
$success = $error = '';

if (empty($_SESSION['staff_settings_csrf'])) {
    $_SESSION['staff_settings_csrf'] = bin2hex(random_bytes(16));
}

$allPanels = [
    ['key'=>'DOJ','name'=>'DOJ','icon'=>'scale-balanced','color'=>'#ef4444'],
    ['key'=>'USSS','name'=>'USSS','icon'=>'user-police','color'=>'#334155'],
    ['key'=>'LSPD','name'=>'LSPD','icon'=>'shield-halved','color'=>'#2563eb'],
    ['key'=>'LSSD','name'=>'LSSD','icon'=>'star','color'=>'#10b981'],
    ['key'=>'SAMS','name'=>'SAMS','icon'=>'kit-medical','color'=>'#f43f5e'],
    ['key'=>'GRUPP6','name'=>'Grupp 6','icon'=>'truck-shield','color'=>'#64748b'],
    ['key'=>'HAUTE_LUXURY','name'=>'Haute Luxury','icon'=>'gem','color'=>'#f472b6'],
    ['key'=>'BAHAMAS','name'=>'Bahamas','icon'=>'martini-glass-citrus','color'=>'#06b6d4'],
    ['key'=>'BENNYS','name'=>"Benny's",'icon'=>'screwdrivers-wrench','color'=>'#f59e0b'],
    ['key'=>'LS_CUSTOM','name'=>'LS Custom','icon'=>'car-wrench','color'=>'#8b5cf6'],
    ['key'=>'LS_MOTORS','name'=>'LS Motors','icon'=>'car-rear','color'=>'#3b82f6'],
    ['key'=>'HEAVY_IMPOUND','name'=>'Heavy Impound','icon'=>'truck-ramp-box','color'=>'#94a3b8'],
    ['key'=>'DYNASTY','name'=>'Dynasty','icon'=>'city','color'=>'#22c55e'],
    ['key'=>'LTD','name'=>'LTD Mirror Park','icon'=>'basket-shopping','color'=>'#0ea5e9'],
    ['key'=>'AMMUNATION','name'=>'Ammunation','icon'=>'gun','color'=>'#ef4444'],
    ['key'=>'LIFE_INVADER','name'=>'Life Invader','icon'=>'clapperboard','color'=>'#6366f1'],
    ['key'=>'POSTOP','name'=>'PostOP','icon'=>'truck-fast','color'=>'#10b981'],
    ['key'=>'TAXI','name'=>'Taxi','icon'=>'taxi','color'=>'#eab308'],
    ['key'=>'TRIADS','name'=>'Triads Records','icon'=>'compact-disc','color'=>'#a855f7'],
    ['key'=>'UWU_COFFEE','name'=>'UwU Coffee','icon'=>'mug-hot','color'=>'#f472b6'],
    ['key'=>'RESTAURANT_KOI','name'=>'Restaurant KOI','icon'=>'shrimp','color'=>'#f87171'],
    ['key'=>'UP_ATOM','name'=>'UP & Atom','icon'=>'burger','color'=>'#fbbf24'],
];

$settings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM admin_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (Exception $e) {}

function getSetting($key, $default = '') { global $settings; return $settings[$key] ?? $default; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $fields = ['site_name','site_url','site_description','timezone','licence_master_key'];
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            $db->prepare("INSERT INTO admin_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$f,$val,$val]);
            $settings[$f] = $val;
        }

        // Clé HMAC : persistée dans config/config.local.php (constante PHP)
        $hmacWarn = '';
        if (isset($_POST['nexium_api_hmac_key'])) {
            $newHmac     = trim((string)$_POST['nexium_api_hmac_key']);
            $currentHmac = defined('NEXIUM_API_HMAC_KEY') ? (string) NEXIUM_API_HMAC_KEY : '';
            // Ignore le placeholder de saisie (bullets uniquement) — l'utilisateur n'a pas modifié le champ.
            $isPlaceholder = $newHmac !== '' && rtrim($newHmac, "•") === '';
            if (!$isPlaceholder && $newHmac !== '' && $newHmac !== $currentHmac) {
                require_once __DIR__ . '/../../includes/config_local_writer.php';
                [$okHmac, $msgHmac] = mdt_config_local_set_constant('NEXIUM_API_HMAC_KEY', $newHmac);
                if (!$okHmac) {
                    $hmacWarn = ' Clé HMAC non sauvegardée : ' . $msgHmac;
                } else {
                    // Recharge immédiate du cache licence avec la nouvelle clé
                    if (!defined('NEXIUM_API_HMAC_KEY')) define('NEXIUM_API_HMAC_KEY', $newHmac);
                }
            }
        }

        // Lien du serveur Discord — https:// obligatoire (ou vide pour effacer)
        $discordWarn = '';
        $discordUrl  = trim((string) ($_POST['discord_server_url'] ?? ''));
        if ($discordUrl === '' || (filter_var($discordUrl, FILTER_VALIDATE_URL) && stripos($discordUrl, 'https://') === 0)) {
            $db->prepare("INSERT INTO admin_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute(['discord_server_url', $discordUrl, $discordUrl]);
            $settings['discord_server_url'] = $discordUrl;
        } else {
            $discordWarn = ' Lien Discord ignoré : renseignez un lien complet commençant par https://.';
        }

        // Refresh immediat du cache produits via l'API centrale
        $cacheResult = License::refresh($settings['licence_master_key'] ?? '');
        $success = 'Parametres sauvegardes. ' . count($cacheResult['products'] ?? []) . ' produit(s) detecte(s).' . $hmacWarn . $discordWarn;
        if (!empty($cacheResult['last_error'])) {
            $error = 'Avertissement licence : ' . $cacheResult['last_error'];
        }
    }

    if ($action === 'recheck_license') {
        $cacheResult = License::refresh();
        $success = 'Statut licence rafraichi. ' . count($cacheResult['products'] ?? []) . ' produit(s) detecte(s).';
        if (!empty($cacheResult['last_error'])) {
            $error = 'Erreur : ' . $cacheResult['last_error'];
        }
    }

    if ($action === 'toggle_maintenance') {
        $panel = $_POST['panel'] ?? '';
        $state = ($_POST['state'] ?? '0') === '1' ? '0' : '1';
        $key   = 'maintenance_' . strtolower($panel);
        $db->prepare("INSERT INTO admin_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$key,$state,$state]);
        $settings[$key] = $state;
        $success = 'Maintenance ' . ($state === '1' ? 'activée' : 'désactivée') . ' pour ' . $panel . '.';
    }

    if ($action === 'apply_mdt_update') {
        if (!hasRole(['*', 'Administrateur', 'Développeur'])) {
            $error = 'Action réservée aux administrateurs.';
        } else {
            $tok = $_POST['csrf'] ?? '';
            if (!hash_equals($_SESSION['staff_settings_csrf'] ?? '', $tok)) {
                $error = 'Session expirée. Rechargez la page.';
            } else {
                [$okUp, $msgUp] = MdtUpdater::installLatestFromApi();
                if ($okUp) {
                    $success = $msgUp;
                } else {
                    $error = $msgUp;
                }
            }
        }
    }

    if ($action === 'reset_database_data') {
        if (!hasRole(['*', 'Administrateur'])) {
            $error = 'Action réservée aux administrateurs.';
        } else {
            $tok = $_POST['csrf'] ?? '';
            if (!hash_equals($_SESSION['staff_settings_csrf'] ?? '', $tok)) {
                $error = 'Session expirée. Rechargez la page.';
            } else {
                $phrase = strtoupper(trim($_POST['reset_confirm'] ?? ''));
                if ($phrase !== 'RESET') {
                    $error = 'Tapez exactement RESET pour confirmer.';
                } else {
                    require_once __DIR__ . '/../../includes/staff_db_reset.php';
                    [$ok, $msg] = mdt_perform_database_data_reset($db);
                    if ($ok) {
                        $_SESSION['staff_settings_csrf'] = bin2hex(random_bytes(16));
                        $success = $msg;
                    } else {
                        $error = $msg;
                    }
                }
            }
        }
    }
}

$activeTab = $_GET['tab'] ?? 'general';
if (!in_array($activeTab, ['general', 'maintenance', 'updates'], true)) {
    $activeTab = 'general';
}

// Toujours interroger Nexium ici : l’URL peut être ?tab=general#updates — le hash n’est jamais vu par PHP,
// alors que le JS active quand même l’onglet « Mises à jour » (footer.js). Sans ce préchargement, la zone MAJ serait vide.
$updatePayload = null;
$updateFetchErr = null;
try {
    [$updatePayload, $updateFetchErr] = MdtUpdater::fetchCheckPayload(false);
} catch (Throwable $e) {
    $updateFetchErr = 'Erreur lors de la vérification : ' . $e->getMessage();
    $updatePayload = null;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1>Paramètres</h1>
        <p>Configuration générale de la plateforme LysoriaMDT</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-sliders"></i> Configuration avancée</div></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="config_site_mdt.php" class="btn btn-secondary"><i class="fa-solid fa-gears"></i> Config site MDT</a>
        <a href="site_customization.php" class="btn btn-secondary"><i class="fa-solid fa-palette"></i> Personnalisation complète</a>
    </div>
</div>

<div class="tabs" data-tab-group="settings">
    <button class="tab-btn <?php echo $activeTab==='general'?'active':''; ?>" data-tab="general">
        <i class="fa-solid fa-gear"></i> Général
    </button>
    <button class="tab-btn <?php echo $activeTab==='maintenance'?'active':''; ?>" data-tab="maintenance">
        <i class="fa-solid fa-triangle-exclamation"></i> Maintenance
    </button>
    <button class="tab-btn <?php echo $activeTab==='updates'?'active':''; ?>" data-tab="updates">
        <i class="fa-solid fa-arrows-rotate"></i> Mises à jour
    </button>
</div>

<!-- GÉNÉRAL -->
<div class="tab-pane <?php echo $activeTab==='general'?'active':''; ?>" id="tab-general">
    <form method="POST">
        <input type="hidden" name="action" value="save_general">
        <div class="grid-2" style="gap:24px;align-items:start;">
            <div class="card">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-globe"></i> Informations du site</div></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Nom du site</label>
                        <input type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars(getSetting('site_name','LysoriaMDT')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">URL du site</label>
                        <input type="url" name="site_url" class="form-control" value="<?php echo htmlspecialchars(getSetting('site_url', SITE_URL)); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="site_description" class="form-control" rows="3"><?php echo htmlspecialchars(getSetting('site_description','')); ?></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Fuseau horaire</label>
                        <select name="timezone" class="form-control">
                            <?php foreach (['Europe/Paris','Europe/London','America/New_York','America/Los_Angeles','UTC'] as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php echo getSetting('timezone','Europe/Paris')===$tz?'selected':''; ?>><?php echo $tz; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-key"></i> Licence Nexium Studio</div></div>
                <div class="card-body">
                    <div class="alert alert-info" style="margin-bottom:16px;">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Une seule cle suffit. Tous vos produits achetes sur <strong>nexium-studio.com</strong> sont attaches automatiquement.</span>
                    </div>

                    <?php
                    $master      = getSetting('licence_master_key','');
                    $hmacDefined = defined('NEXIUM_API_HMAC_KEY') && NEXIUM_API_HMAC_KEY !== '';
                    $hmacFile    = dirname(__DIR__, 2) . '/config/config.local.php';
                    $hmacWritable= is_writable($hmacFile) || (!file_exists($hmacFile) && is_writable(dirname($hmacFile)));
                    ?>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-key" style="color:var(--blue);"></i>
                            Cle de licence master
                            <?php if ($master): ?>
                                <span class="badge badge-green" style="font-size:.6rem;">Definie</span>
                            <?php else: ?>
                                <span class="badge badge-red" style="font-size:.6rem;">Non definie</span>
                            <?php endif; ?>
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="licence_master_key" class="form-control" value="<?php echo htmlspecialchars($master); ?>" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" style="padding-right:40px;font-family:'DM Mono',monospace;letter-spacing:1px;">
                            <button type="button" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password'" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-secondary);cursor:pointer;">
                                <i class="fa-solid fa-eye" style="font-size:13px;"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--purple,#a855f7);"></i>
                            Cle HMAC API Nexium
                            <?php if ($hmacDefined): ?>
                                <span class="badge badge-green" style="font-size:.6rem;">Definie</span>
                            <?php else: ?>
                                <span class="badge badge-red" style="font-size:.6rem;">Manquante</span>
                            <?php endif; ?>
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="nexium_api_hmac_key" class="form-control"
                                   value="<?php echo $hmacDefined ? str_repeat('•', 32) : ''; ?>"
                                   placeholder="Identique à API_SECRET_KEY côté api.nexium-studio.com"
                                   style="padding-right:40px;font-family:'DM Mono',monospace;letter-spacing:1px;"
                                   <?php echo $hmacWritable ? '' : 'disabled'; ?>>
                            <button type="button" onclick="const i=this.previousElementSibling; if(i.value.indexOf('•')===0){i.value='';} i.type=i.type==='password'?'text':'password';" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-secondary);cursor:pointer;">
                                <i class="fa-solid fa-eye" style="font-size:13px;"></i>
                            </button>
                        </div>
                        <small style="display:block;margin-top:6px;color:var(--text-secondary);font-size:.75rem;">
                            <?php if (!$hmacWritable): ?>
                                <i class="fa-solid fa-lock"></i>
                                <code>config/config.local.php</code> n'est pas inscriptible — modifiez les permissions ou éditez manuellement la constante.
                            <?php else: ?>
                                Stockée comme constante PHP dans <code>config/config.local.php</code>.
                                Laissez vide pour conserver la valeur actuelle.
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <div class="card" style="margin-top:24px;">
            <div class="card-header"><div class="card-title"><i class="fa-brands fa-discord" style="color:#5865F2;"></i> Serveur Discord du serveur</div></div>
            <div class="card-body">
                <div class="alert alert-info" style="margin-bottom:16px;">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Veuillez renseigner le lien complet du serveur Discord, incluant obligatoirement <strong>https://</strong>. Ce lien est utilisé automatiquement par les pages qui en ont besoin (support « Mot de passe oublié », etc.).</span>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;">
                        <i class="fa-brands fa-discord" style="color:#5865F2;"></i> Lien d'invitation Discord
                    </label>
                    <input type="url" name="discord_server_url" class="form-control"
                           value="<?php echo htmlspecialchars(getSetting('discord_server_url','')); ?>"
                           placeholder="https://discord.gg/votre-invitation" pattern="https://.*"
                           title="Le lien doit commencer par https://">
                    <small style="display:block;margin-top:6px;color:var(--text-secondary);font-size:.75rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>
                        Le lien doit commencer par <code>https://</code> (ex : <code>https://discord.gg/votre-invitation</code>).
                    </small>
                </div>
            </div>
        </div>
        <div style="margin-top:20px;display:flex;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
        </div>
    </form>

    <?php
    // Affichage de l'etat des produits associes a la licence master
    $licenseStatus = License::getStatus(false);
    $knownProducts = [
        'mdt'             => ['label' => 'MDT (principal)',     'icon' => 'microchip',          'color' => '#3b82f6'],
        'addon_hack_mdt'  => ['label' => 'Hack MDT',            'icon' => 'user-secret',        'color' => '#a855f7'],
        'addon_mail'      => ['label' => 'Gestionnaire Mail',   'icon' => 'envelope-open-text', 'color' => '#f59e0b'],
        'addon_dispatch'  => ['label' => 'Dispatch',            'icon' => 'radio',              'color' => '#10b981'],
        'bot_911'         => ['label' => 'Bot 911',             'icon' => 'robot',              'color' => '#ef4444'],
        'life_invader'    => ['label' => 'Life Invader',        'icon' => 'clapperboard',       'color' => '#6366f1'],
    ];
    $ownedBySlug = [];
    foreach ($licenseStatus['products'] ?? [] as $prod) {
        $ownedBySlug[$prod['slug']] = $prod;
    }
    foreach ($licenseStatus['products'] ?? [] as $prod) {
        if (!isset($knownProducts[$prod['slug']])) {
            $knownProducts[$prod['slug']] = ['label' => $prod['name'] ?? $prod['slug'], 'icon' => 'box', 'color' => '#64748b'];
        }
    }
    ?>
    <div class="card" style="margin-top:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div class="card-title"><i class="fa-solid fa-cubes"></i> Produits associes a la licence</div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="recheck_license">
                <button type="submit" class="btn btn-secondary" style="font-size:.8rem;">
                    <i class="fa-solid fa-arrows-rotate"></i> Verifier maintenant
                </button>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($licenseStatus['fetched_at'])): ?>
                <div class="alert alert-warning" style="margin-bottom:0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Aucune verification effectuee. Sauvegarde une cle de licence ou clique sur <strong>Verifier maintenant</strong>.</span>
                </div>
            <?php else: ?>
                <p style="color:var(--text-secondary);font-size:.85rem;margin-bottom:14px;">
                    Derniere verification : <?php echo htmlspecialchars(date('d/m/Y H:i:s', (int)$licenseStatus['fetched_at'])); ?>
                    &middot; Statut licence master :
                    <strong style="color:<?php echo $licenseStatus['license_status']==='active'?'#10b981':'#ef4444'; ?>"><?php echo htmlspecialchars($licenseStatus['license_status']); ?></strong>
                </p>
                <p style="color:var(--text-secondary);font-size:.82rem;margin-bottom:14px;">
                    <strong>Site actuel :</strong> <code style="color:var(--text-primary);"><?php echo htmlspecialchars(License::installationHost()); ?></code>
                    <?php if (!empty($licenseStatus['license_domain'])): ?>
                        &middot; <strong>Domaine enregistre (Nexium) :</strong>
                        <code style="color:var(--text-primary);"><?php echo htmlspecialchars((string) $licenseStatus['license_domain']); ?></code>
                        <?php if (!empty($licenseStatus['license_domain_activated_at'])): ?>
                            <span style="color:var(--text-muted);">depuis <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime((string) $licenseStatus['license_domain_activated_at']))); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted);"> &mdash; en attente de premiere verification avec ce domaine</span>
                    <?php endif; ?>
                </p>
                <?php if (!empty($licenseStatus['last_error']) && ($licenseStatus['license_status'] ?? '') !== 'active'): ?>
                    <div class="alert alert-danger" style="margin-bottom:14px;font-size:.85rem;">
                        <?php echo htmlspecialchars((string) $licenseStatus['last_error']); ?>
                    </div>
                <?php endif; ?>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
                    <?php foreach ($knownProducts as $slug => $info):
                        $owned = $ownedBySlug[$slug] ?? null;
                        if ($owned) {
                            $stat = $owned['status'] ?? 'unknown';
                            if ($stat === 'active') {
                                $badgeColor = '#10b981'; $badgeLabel = 'Actif';
                            } elseif ($stat === 'expired') {
                                $badgeColor = '#ef4444'; $badgeLabel = 'Expire';
                            } elseif ($stat === 'suspended') {
                                $badgeColor = '#eab308'; $badgeLabel = 'Suspendu';
                            } else {
                                $badgeColor = '#94a3b8'; $badgeLabel = htmlspecialchars($stat);
                            }
                            $expiresHtml = !empty($owned['expires_at'])
                                ? 'Expire le ' . htmlspecialchars(date('d/m/Y', strtotime($owned['expires_at'])))
                                : 'A vie';
                        } else {
                            $badgeColor  = '#475569';
                            $badgeLabel  = 'Non possede';
                            $expiresHtml = '-';
                        }
                    ?>
                        <div style="display:flex;gap:12px;padding:14px;background:var(--bg-secondary,#1a1f2e);border:1px solid var(--border-color,rgba(255,255,255,.06));border-radius:12px;">
                            <div style="width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:<?php echo $info['color']; ?>22;color:<?php echo $info['color']; ?>;flex-shrink:0;">
                                <i class="fa-solid fa-<?php echo $info['icon']; ?>"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;color:var(--text-primary);"><?php echo htmlspecialchars($info['label']); ?></div>
                                <div style="font-size:.75rem;color:var(--text-secondary);font-family:monospace;"><?php echo htmlspecialchars($slug); ?></div>
                                <div style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="background:<?php echo $badgeColor; ?>22;color:<?php echo $badgeColor; ?>;padding:2px 9px;border-radius:99px;font-size:.7rem;font-weight:700;"><?php echo $badgeLabel; ?></span>
                                    <span style="font-size:.72rem;color:var(--text-secondary);"><?php echo $expiresHtml; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (hasRole(['*', 'Administrateur'])): ?>
    <div class="card" style="margin-top:24px;border-color:rgba(239,68,68,.35);">
        <div class="card-header">
            <div class="card-title" style="color:#fca5a5;"><i class="fa-solid fa-database"></i> Réinitialisation des données</div>
        </div>
        <div class="card-body">
            <div class="alert alert-warning" style="margin-bottom:16px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Supprime les données de <strong>toutes les tables</strong> sauf <code>penal_code</code> (code pénal). Conserve uniquement les comptes dont le rôle est <strong>*</strong>, <strong>Administrateur</strong> ou <strong>Développeur</strong>. Action irréversible.</span>
            </div>
            <form method="POST" onsubmit="return confirm('Confirmer la réinitialisation complète des données ?');">
                <input type="hidden" name="action" value="reset_database_data">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['staff_settings_csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label class="form-label">Tapez <strong>RESET</strong> pour confirmer</label>
                    <input type="text" name="reset_confirm" class="form-control" placeholder="RESET" autocomplete="off" style="max-width:280px;font-family:monospace;">
                </div>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-bomb"></i> Vider la base (sauf code pénal & admins)</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MISES À JOUR MDT -->
<div class="tab-pane <?php echo $activeTab==='updates'?'active':''; ?>" id="tab-updates">
    <div class="alert alert-warning" style="margin-bottom:20px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><strong>Sauvegardez votre base de données</strong> (export SQL complet) avant toute mise à jour. En cas de problème, une archive partielle du code est créée à la racine du MDT : <code>.mdt_code_backup.zip</code> (includes, pages, classes, etc.).</span>
    </div>

    <?php
    $installed = MdtUpdater::getInstalledVersion();
    $hasKey = License::getMasterKey() !== '';
    ?>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-code-branch"></i> Version installée</div></div>
        <div class="card-body">
            <p style="margin:0;font-size:1.1rem;font-weight:700;font-family:'DM Mono',monospace;">v<?php echo htmlspecialchars($installed); ?></p>
            <p style="margin:10px 0 0;color:var(--text-secondary);font-size:.85rem;">
                Source : paramètre <code>mdt_code_version</code> dans <code>admin_settings</code> (mis à jour automatiquement après installation depuis Nexium Studio).
            </p>
        </div>
    </div>

    <?php if (!$hasKey): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-key"></i> Définissez la clé de licence master (onglet Général) pour vérifier les mises à jour.</div>
    <?php elseif ($updateFetchErr): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-plug-circle-xmark"></i> <?php echo htmlspecialchars($updateFetchErr); ?></div>
        <p style="color:var(--text-secondary);font-size:.85rem;">URL utilisée : <code><?php echo htmlspecialchars(rtrim(NEXIUM_UPDATES_API_BASE, '/') . '/api/mdt/check-updates.php'); ?></code> — vérifiez <code>NEXIUM_UPDATES_API_BASE</code> dans <code>config.local.php</code> si le domaine diffère.</p>
    <?php elseif (is_array($updatePayload)): ?>
        <?php
        $upd   = !empty($updatePayload['update_available']);
        $lat   = $updatePayload['latest'] ?? null;
        $noInc = !empty($updatePayload['no_incremental_update']);
        $hint  = (string) ($updatePayload['hint'] ?? '');
        // Sécurité : recalcul local si l’API ou le JSON a mal typé update_available
        if (is_array($lat) && !empty($lat['version'])) {
            $rnorm = preg_replace('/^v/i', '', trim((string) $lat['version']));
            $inorm = preg_replace('/^v/i', '', trim($installed));
            if ($rnorm !== '' && $inorm !== '' && version_compare($rnorm, $inorm, '>')) {
                $upd = true;
            }
        }
        ?>
        <?php if ($noInc || ($lat === null && $hint !== '')): ?>
        <div class="alert alert-info" style="margin-bottom:20px;">
            <i class="fa-solid fa-circle-info"></i>
            <span>
                <?php if ($hint !== ''): ?>
                    <?php echo htmlspecialchars($hint); ?>
                <?php else: ?>
                    Aucune <strong>mise à jour incrémentale</strong> (ZIP « Nouvelle mise à jour ») n’est publiée sur Nexium Studio pour le MDT. Le fichier de base seul ne suffit pas : ajoutez au moins une version dans l’admin Nexium, section mises à jour.
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>
        <?php if ($upd && is_array($lat) && !empty($lat['version'])): ?>
        <div class="card" style="margin-bottom:20px;border:1px solid rgba(239,68,68,.45);background:rgba(239,68,68,.08);">
            <div class="card-header">
                <div class="card-title" style="color:#fecaca;"><i class="fa-solid fa-circle-exclamation"></i> Nouvelle version disponible</div>
            </div>
            <div class="card-body">
                <p style="margin:0 0 8px;font-size:1.05rem;font-weight:700;">v<?php echo htmlspecialchars((string) ($lat['version'] ?? '')); ?>
                    <span style="font-weight:500;color:var(--text-secondary);font-size:.85rem;">· sortie <?php echo htmlspecialchars((string) ($lat['release_date'] ?? '')); ?></span>
                </p>
                <?php if (!empty($lat['changelog'])): ?>
                    <div style="white-space:pre-wrap;background:rgba(0,0,0,.25);border-radius:10px;padding:12px 14px;font-size:.88rem;color:var(--text-secondary);margin-bottom:16px;"><?php echo htmlspecialchars((string) $lat['changelog']); ?></div>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Lancer la mise à jour maintenant ? La base doit être sauvegardée.');">
                    <input type="hidden" name="action" value="apply_mdt_update">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['staff_settings_csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-primary" style="font-size:1rem;padding:12px 22px;">
                        <i class="fa-solid fa-download"></i> Mettre à jour
                    </button>
                </form>
            </div>
        </div>
        <?php elseif (is_array($lat) && !empty($lat['version'])): ?>
        <div class="alert alert-success" style="margin-bottom:20px;">
            <i class="fa-solid fa-circle-check"></i> Vous êtes à jour (dernière version publiée : v<?php echo htmlspecialchars((string) $lat['version']); ?>).
        </div>
        <?php else: ?>
        <div class="alert alert-secondary" style="margin-bottom:20px;">
            <i class="fa-solid fa-circle-question"></i>
            Réponse Nexium reçue mais sans détail de version exploitable. Vérifiez sur le site Nexium que la mise à jour est en <strong>stable</strong> ou <strong>critique</strong> et que le produit est bien le canal <code>mdt</code> (ou variante <code>mdt_*</code>).
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Historique (aperçu)</div></div>
            <div class="card-body" style="padding-top:0;">
                <?php
                $hist = $updatePayload['history'] ?? [];
                if (!is_array($hist) || $hist === []): ?>
                    <p style="color:var(--text-secondary);font-size:.88rem;">Aucun historique renvoyé.</p>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ($hist as $h):
                            if (!is_array($h)) {
                                continue;
                            }
                        ?>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 12px;border-radius:10px;background:var(--bg-secondary,#1a1f2e);border:1px solid var(--border-color,rgba(255,255,255,.06));">
                            <strong>v<?php echo htmlspecialchars((string) ($h['version'] ?? '')); ?></strong>
                            <span style="font-size:.75rem;color:var(--text-secondary);"><?php echo htmlspecialchars((string) ($h['release_date'] ?? '')); ?></span>
                            <span class="badge badge-slate" style="font-size:.65rem;"><?php echo htmlspecialchars((string) ($h['status'] ?? '')); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary" style="margin-bottom:20px;">
            <i class="fa-solid fa-plug-circle-xmark"></i>
            Impossible d’analyser la réponse du serveur Nexium. Ouvrez la console réseau ou vérifiez <code>NEXIUM_UPDATES_API_BASE</code> et que l’URL <code>/api/mdt/check-updates.php</code> répond en JSON.
        </div>
    <?php endif; ?>
</div>

<!-- MAINTENANCE -->
<div class="tab-pane <?php echo $activeTab==='maintenance'?'active':''; ?>" id="tab-maintenance">
    <div class="alert alert-warning" style="margin-bottom:24px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Activer la maintenance d'un panel le rend inaccessible aux utilisateurs (sauf Administrateurs).</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;">
        <?php foreach ($allPanels as $p):
            $mKey = 'maintenance_' . strtolower($p['key']);
            $isOn = (bool)getSetting($mKey, '0');
        ?>
        <div class="maintenance-card <?php echo $isOn?'on':''; ?>" id="mc-<?php echo $p['key']; ?>">
            <div class="mc-info">
                <div class="mc-icon" style="color:<?php echo $p['color']; ?>;"><i class="fa-solid fa-<?php echo $p['icon']; ?>"></i></div>
                <div>
                    <div class="mc-name"><?php echo htmlspecialchars($p['name']); ?></div>
                    <div class="mc-desc"><?php echo $p['key']; ?></div>
                </div>
            </div>
            <form method="POST" style="display:flex;align-items:center;gap:10px;">
                <input type="hidden" name="action" value="toggle_maintenance">
                <input type="hidden" name="panel" value="<?php echo $p['key']; ?>">
                <input type="hidden" name="state" value="<?php echo $isOn?'1':'0'; ?>">
                <?php if ($isOn): ?><span class="badge badge-orange"><i class="fa-solid fa-wrench"></i> ON</span><?php endif; ?>
                <label class="toggle">
                    <input type="checkbox" onchange="this.closest('form').submit()" <?php echo $isOn?'checked':''; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    try {
        if (location.hash === '#updates') {
            var p = new URLSearchParams(location.search);
            if (p.get('tab') !== 'updates') {
                p.set('tab', 'updates');
                var q = p.toString();
                history.replaceState(null, '', location.pathname + (q ? '?' + q : ''));
            }
        }
    } catch (e) {}
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
