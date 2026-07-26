<?php
require_once __DIR__ . '/_bootstrap.php';

$DY_PAGE_TITLE = $DY_PAGE_TITLE ?? 'Dynasty · Immobilier de Nexium Studio';
$DY_PAGE_DESC  = $DY_PAGE_DESC  ?? "Estimez un loyer ou un prix d'achat et explorez les quartiers de Nexium Studio.";
$DY_ACTIVE     = $DY_ACTIVE     ?? 'calc';   // calc | catalogue
$DY_LOGO       = dy_pub_logo();
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo dy_esc($DY_PAGE_TITLE); ?></title>
    <meta name="description" content="<?php echo dy_esc($DY_PAGE_DESC); ?>">
    <meta property="og:title" content="<?php echo dy_esc($DY_PAGE_TITLE); ?>">
    <meta property="og:description" content="<?php echo dy_esc($DY_PAGE_DESC); ?>">
    <meta property="og:type" content="website">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%2322C55E' rx='22'/><path d='M50 22 L78 44 L68 44 L68 74 L32 74 L32 44 L22 44 Z' fill='%2304140e'/></svg>">

    <script>
    /* Applique le thème mémorisé avant le rendu pour éviter tout flash. */
    (function () {
        try {
            var t = localStorage.getItem('dy-theme');
            if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo dy_pub_url('assets/style.css'); ?>?v=<?php echo @filemtime(__DIR__ . '/assets/style.css') ?: '1'; ?>">
</head>
<body>

<header class="dy-head">
    <div class="dy-container dy-head-inner">
        <a href="<?php echo dy_pub_url('index.php'); ?>" class="dy-brand">
            <span class="dy-brand-logo">
                <?php if ($DY_LOGO): ?>
                    <img src="<?php echo dy_esc($DY_LOGO); ?>" alt="Dynasty" referrerpolicy="no-referrer"
                         onerror="this.replaceWith(document.createTextNode(''));this.parentNode.innerHTML='<i class=\'fa-solid fa-city\'></i>'">
                <?php else: ?>
                    <i class="fa-solid fa-city"></i>
                <?php endif; ?>
            </span>
            <span>Dynasty <small>Immobilier de Nexium Studio</small></span>
        </a>

        <nav class="dy-nav-pill">
            <a href="<?php echo dy_pub_url('index.php'); ?>" class="<?php echo $DY_ACTIVE === 'calc' ? 'active' : ''; ?>">
                <i class="fa-solid fa-calculator"></i> Calculateur
            </a>
            <a href="<?php echo dy_pub_url('catalogue.php'); ?>" class="<?php echo $DY_ACTIVE === 'catalogue' ? 'active' : ''; ?>">
                <i class="fa-solid fa-building"></i> Catalogue
            </a>
        </nav>

        <button class="dy-theme" id="dyTheme" type="button" title="Changer de thème" aria-label="Changer de thème">
            <i class="fa-solid fa-moon"></i>
        </button>
    </div>
</header>
