<?php
// V1 MDT/includes/license_guard.php
//
// Garde-fous pour bloquer l'acces si la licence master ou un produit specifique
// n'est pas actif. Inclus automatiquement par config.php.

require_once __DIR__ . '/license_client.php';

/**
 * Verifie globalement la licence MDT principale. Si elle est inactive,
 * affiche une page de blocage (sauf si on est sur les pages d'exception).
 */
function mdt_license_global_guard(): void
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    // Pages toujours autorisees, meme si la licence est invalide.
    $allowList = [
        '/login.php',
        '/logout.php',
        '/install.php',
        '/pages/staff/settings.php',
        '/auth/discord-callback.php',
    ];
    foreach ($allowList as $allowed) {
        if (str_ends_with($script, $allowed)) {
            return;
        }
    }

    // Assets statiques (rare via PHP, mais on protege quand meme)
    $ext = strtolower(pathinfo($script, PATHINFO_EXTENSION));
    if (in_array($ext, ['css','js','png','jpg','jpeg','gif','svg','webp','ico','woff','woff2','ttf','eot'], true)) {
        return;
    }

    // CLI / cron : ne pas bloquer
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (License::isMDTActive()) {
        return;
    }

    $st = License::getStatus(false);
    $ls = $st['license_status'] ?? '';

    if ($ls === 'DOMAIN_MISMATCH') {
        mdt_render_license_block_page(
            'Domaine non autorise',
            'Cette cle de licence est deja liee a un <strong>autre domaine</strong> sur Nexium Studio. '
            . 'Tu ne peux pas utiliser la meme cle sur plusieurs sites. Contacte le support pour un transfert ou utilise la bonne installation.',
            SITE_URL . '/pages/staff/settings.php'
        );
    }

    if ($ls === 'MISSING_DOMAIN') {
        mdt_render_license_block_page(
            'Mise a jour requise',
            'Le serveur Nexium exige le domaine du site pour verifier la licence. '
            . 'Met a jour ton MDT vers la derniere version ou contacte le support.',
            SITE_URL . '/pages/staff/settings.php'
        );
    }

    mdt_render_license_block_page(
        'MDT desactive',
        'La licence MDT principale est expiree, suspendue ou non configuree. '
        . 'Configure ta cle de licence dans <strong>Parametres &gt; General</strong>.',
        SITE_URL . '/pages/staff/settings.php'
    );
}

/**
 * Garde par addon : bloque l'acces si le produit specifie n'est pas actif.
 * Les addons MDT (Hack, Mail, Dispatch) exigent aussi une licence MDT core active.
 * Usage : mdt_require_product('addon_hack_mdt');
 */
function mdt_require_product(string $slug, ?string $label = null): void
{
    if (License::isMdtDependentAddon($slug) && !License::isMDTActive()) {
        $label = $label ?: $slug;
        http_response_code(403);
        mdt_render_license_block_page(
            'Licence MDT requise',
            "L'addon <strong>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</strong> necessite d'abord une licence MDT active (abonnement ou a vie) sur la meme cle. Active ton MDT ou contacte le support.",
            SITE_URL . '/pages/staff/settings.php'
        );
    }

    if (License::isProductActive($slug)) {
        return;
    }
    $label = $label ?: $slug;
    http_response_code(403);
    mdt_render_license_block_page(
        'Addon « ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' » non actif',
        "Cet addon n'est pas inclus dans ta licence ou est expire. Verifie dans le panel admin Nexium Studio ou contacte le support.",
        SITE_URL . '/pages/staff/settings.php'
    );
}

/**
 * Page de blocage minimaliste, sans dependance sur le layout MDT.
 * Termine le script.
 */
function mdt_render_license_block_page(string $title, string $message, string $settingsUrl): void
{
    if (headers_sent() === false) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>$title - MDT</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:radial-gradient(circle at top,#1e1b4b,#020617);color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .box{max-width:520px;background:#0f172a;border:1px solid rgba(239,68,68,.3);border-radius:18px;padding:40px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.5)}
    .icon{width:72px;height:72px;border-radius:50%;background:rgba(239,68,68,.15);color:#fca5a5;display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:18px}
    h1{font-size:1.4rem;font-weight:700;margin-bottom:12px}
    p{color:#94a3b8;line-height:1.6;margin-bottom:24px;font-size:.95rem}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border-radius:10px;text-decoration:none;font-weight:600}
    .footer{margin-top:24px;font-size:.78rem;color:#64748b}
    .footer a{color:#93c5fd;text-decoration:none}
</style>
</head>
<body>
<div class="box">
    <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h1>$title</h1>
    <p>$message</p>
    <a class="btn" href="$settingsUrl"><i class="fa-solid fa-gear"></i> Ouvrir les parametres</a>
    <div class="footer">
        Besoin d'aide ? <a href="mailto:support@nexium-studio.com">support@nexium-studio.com</a>
    </div>
</div>
</body>
</html>
HTML;
    exit;
}
