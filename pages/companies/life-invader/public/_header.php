<?php
require_once __DIR__ . '/_bootstrap.php';

$LI_PAGE_TITLE   = $LI_PAGE_TITLE   ?? 'Life Invader · Le journal de Lysoria';
$LI_PAGE_DESC    = $LI_PAGE_DESC    ?? "Toute l'actualité de Lysoria — politique, faits divers, économie, justice, sport, culture.";
$LI_PAGE_OG_IMG  = $LI_PAGE_OG_IMG  ?? '';

// On exclut la catégorie "communiques" du menu (un lien dédié existe à côté)
$LI_NAV_CATS = array_values(array_filter(li_get_categories(), function ($c) {
    return ($c['slug'] ?? '') !== 'communiques';
}));

$LI_BREAKING_RAW = li_db()->query("
    SELECT a.id, a.titre, c.nom AS cat_nom, c.couleur AS cat_col
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    WHERE a.statut='publie' AND (a.priorite IN ('urgente','flash') OR a.a_la_une=1)
    ORDER BY a.date_publication DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$LI_SEARCH_Q = $_GET['q'] ?? '';
$LI_CURRENT_CAT = $_GET['cat'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($LI_PAGE_TITLE); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($LI_PAGE_DESC); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($LI_PAGE_TITLE); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($LI_PAGE_DESC); ?>">
    <?php if ($LI_PAGE_OG_IMG): ?><meta property="og:image" content="<?php echo htmlspecialchars($LI_PAGE_OG_IMG); ?>"><?php endif; ?>
    <meta property="og:type" content="article">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%234f7cff' rx='14'/><text x='50' y='65' font-family='serif' font-size='52' fill='white' text-anchor='middle' font-weight='900'>LI</text></svg>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,500;0,600;0,700;0,800;0,900;1,500;1,700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo li_pub_url('assets/style.css'); ?>?v=2">
</head>
<body>

<?php if (!empty($LI_BREAKING_RAW)): ?>
<div class="li-breaking">
    <div class="li-container li-breaking-inner">
        <div class="li-breaking-label"><i class="fas fa-bolt"></i> Breaking</div>
        <div class="li-breaking-track">
            <div class="li-breaking-ticker">
                <?php foreach ($LI_BREAKING_RAW as $b): ?>
                <a href="<?php echo li_pub_url('article.php?id='.$b['id']); ?>" class="li-breaking-item">
                    <span class="li-breaking-dot" style="background:<?php echo htmlspecialchars($b['cat_col'] ?? '#ef4444'); ?>"></span>
                    <strong><?php echo htmlspecialchars($b['titre']); ?></strong>
                    <?php if (!empty($b['cat_nom'])): ?><span class="li-breaking-cat"><?php echo htmlspecialchars($b['cat_nom']); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php foreach ($LI_BREAKING_RAW as $b): ?>
                <a href="<?php echo li_pub_url('article.php?id='.$b['id']); ?>" class="li-breaking-item">
                    <span class="li-breaking-dot" style="background:<?php echo htmlspecialchars($b['cat_col'] ?? '#ef4444'); ?>"></span>
                    <strong><?php echo htmlspecialchars($b['titre']); ?></strong>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<header class="li-masthead">
    <div class="li-container li-masthead-top">
        <div class="li-masthead-date">
            <i class="far fa-calendar"></i> <?php
                $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
                $mois  = ['January'=>'janvier','February'=>'février','March'=>'mars','April'=>'avril','May'=>'mai','June'=>'juin','July'=>'juillet','August'=>'août','September'=>'septembre','October'=>'octobre','November'=>'novembre','December'=>'décembre'];
                $j = $jours[date('l')] ?? date('l');
                $m = $mois[date('F')]  ?? date('F');
                echo "$j " . date('j') . " $m " . date('Y');
            ?>
        </div>
        <a href="<?php echo li_pub_url(); ?>" class="li-masthead-wordmark">
            <span class="li-wm-life">Life</span><span class="li-wm-invader">Invader</span>
        </a>
        <div class="li-masthead-actions">
            <form method="GET" action="<?php echo li_pub_url('search.php'); ?>" class="li-search">
                <input type="text" name="q" placeholder="Rechercher un article…" value="<?php echo htmlspecialchars($LI_SEARCH_Q); ?>">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
            <button class="li-theme-toggle" id="liThemeToggle" aria-label="Changer de thème" title="Mode clair / sombre">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>
    <nav class="li-masthead-nav">
        <div class="li-container li-nav-inner">
            <a href="<?php echo li_pub_url(); ?>" class="li-nav-item<?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : ''; ?>"><i class="fas fa-home"></i> Accueil</a>
            <?php foreach ($LI_NAV_CATS as $c): ?>
            <a href="<?php echo li_pub_url('category.php?slug='.urlencode($c['slug'])); ?>" class="li-nav-item<?php echo ($LI_CURRENT_CAT===$c['slug'])?' active':''; ?>" style="--ncol:<?php echo htmlspecialchars($c['couleur']); ?>">
                <i class="fas <?php echo htmlspecialchars($c['icone']); ?>"></i> <?php echo htmlspecialchars($c['nom']); ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo li_pub_url('communiques.php'); ?>" class="li-nav-item<?php echo basename($_SERVER['PHP_SELF']) === 'communiques.php' ? ' active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Communiqués</a>
        </div>
    </nav>
</header>

<main class="li-main">
    <div class="li-container">
