<?php
require_once __DIR__ . '/_bootstrap.php';

$db = li_db();
$hasImg = li_has_column('li_articles', 'image_principale');
$imgSel = $hasImg ? 'a.image_principale' : 'NULL AS image_principale';

/* ── À la Une — l'article le plus récent à la une OU le plus récent ── */
$une = $db->query("
    SELECT a.id, a.reference, a.titre, a.chapeau, a.contenu, a.tags, a.priorite,
           a.a_la_une, a.exclusif, a.nb_vues, a.nb_likes,
           a.date_publication, $imgSel,
           c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone, c.slug AS cat_slug,
           u.username AS author_username,
           p.firstname AS author_firstname, p.lastname AS author_lastname
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    JOIN users u ON u.id = a.auteur_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE a.statut='publie'
    ORDER BY a.a_la_une DESC, FIELD(a.priorite,'flash','urgente','importante','normale'), a.date_publication DESC
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$uneId = $une['id'] ?? 0;

/* ── Featured side (3 articles secondaires) ── */
$featured = $db->prepare("
    SELECT a.id, a.titre, a.chapeau, a.priorite, a.a_la_une, a.exclusif, a.nb_vues,
           a.date_publication, $imgSel,
           c.nom AS cat_nom, c.couleur AS cat_col
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    WHERE a.statut='publie' AND a.id <> ?
    ORDER BY (a.a_la_une=1) DESC, FIELD(a.priorite,'flash','urgente','importante','normale'), a.date_publication DESC
    LIMIT 4");
$featured->execute([$uneId]);
$featured = $featured->fetchAll(PDO::FETCH_ASSOC);

$excludeIds = array_values(array_filter(array_merge([$uneId], array_column($featured, 'id'))));

/* ── Derniers articles ── */
if (!empty($excludeIds)) {
    $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $latestSt = $db->prepare("
        SELECT a.id, a.titre, a.chapeau, a.priorite, a.a_la_une, a.exclusif, a.nb_vues,
               a.date_publication, $imgSel,
               c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone, c.slug AS cat_slug,
               u.username AS author_username,
               p.firstname AS author_firstname, p.lastname AS author_lastname
        FROM li_articles a
        LEFT JOIN li_categories c ON c.id = a.categorie_id
        JOIN users u ON u.id = a.auteur_id
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE a.statut='publie' AND a.id NOT IN ($excludePlaceholders)
        ORDER BY a.date_publication DESC
        LIMIT 9");
    $latestSt->execute($excludeIds);
    $latest = $latestSt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $latest = [];
}

/* ── Plus lus du mois ── */
$mostRead = $db->query("
    SELECT a.id, a.titre, a.date_publication, a.nb_vues, c.nom AS cat_nom, c.couleur AS cat_col
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    WHERE a.statut='publie' AND a.date_publication >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY a.nb_vues DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

/* ── Derniers communiqués publiés ── */
$communiques = $db->query("
    SELECT id, reference, titre, contenu, source, priorite, recu_le
    FROM li_communiques
    WHERE statut='publie'
    ORDER BY COALESCE(recu_le, created_at) DESC
    LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

$LI_PAGE_TITLE = 'Life Invader · L\'actualité de Lysoria en direct';
$LI_PAGE_DESC = "Toute l'actualité de Lysoria — politique, faits divers, économie, justice, sport, culture. Life Invader, le journal de référence.";

include __DIR__ . '/_header.php';
?>

<?php if ($une): ?>
<!-- À la Une -->
<section class="li-hero">
    <a href="<?php echo li_pub_url('article.php?id=' . $une['id']); ?>" class="li-hero-main">
        <div class="li-hero-image" <?php if (li_image_for($une)): ?>style="background-image:url('<?php echo htmlspecialchars(li_image_for($une)); ?>')"<?php endif; ?>>
            <?php if (!li_image_for($une)): ?>
            <div class="li-hero-image-placeholder"><i class="fas fa-newspaper"></i></div>
            <?php endif; ?>
            <?php if ($une['priorite'] === 'flash'): ?>
            <span class="li-hero-badge">Flash info</span>
            <?php elseif ($une['priorite'] === 'urgente'): ?>
            <span class="li-hero-badge">Urgent</span>
            <?php elseif ($une['a_la_une']): ?>
            <span class="li-hero-badge" style="background:#d97706">À la une</span>
            <?php endif; ?>
        </div>
        <div class="li-hero-body">
            <?php if (!empty($une['cat_nom'])): ?>
            <span class="li-hero-cat" style="color:<?php echo htmlspecialchars($une['cat_col']); ?>;background:<?php echo htmlspecialchars($une['cat_col']); ?>14;border:1px solid <?php echo htmlspecialchars($une['cat_col']); ?>33">
                <i class="fas <?php echo htmlspecialchars($une['cat_icone']); ?>"></i> <?php echo htmlspecialchars($une['cat_nom']); ?>
            </span>
            <?php endif; ?>
            <h2 class="li-hero-title"><?php echo htmlspecialchars($une['titre']); ?></h2>
            <p class="li-hero-excerpt"><?php echo htmlspecialchars($une['chapeau'] ? li_plain($une['chapeau']) : li_excerpt($une['contenu'], 220)); ?></p>
            <div class="li-hero-meta">
                <span><i class="fas fa-user-edit"></i> Par <strong><?php echo htmlspecialchars(li_pub_author_name($une)); ?></strong></span>
                <span><i class="far fa-clock"></i> <?php echo li_time_ago($une['date_publication']); ?></span>
                <span><i class="fas fa-eye"></i> <?php echo number_format($une['nb_vues']); ?> lecture<?php echo $une['nb_vues']>1?'s':''; ?></span>
            </div>
        </div>
    </a>

    <aside class="li-hero-side">
        <?php foreach ($featured as $f): ?>
        <a href="<?php echo li_pub_url('article.php?id=' . $f['id']); ?>" class="li-hero-side-card">
            <div class="li-hero-side-thumb"<?php if (li_image_for($f)): ?> style="background-image:url('<?php echo htmlspecialchars(li_image_for($f)); ?>')"<?php endif; ?>><?php if (!li_image_for($f)): ?><i class="fas fa-newspaper"></i><?php endif; ?></div>
            <div class="li-hero-side-body">
                <?php if ($f['cat_nom']): ?>
                <span class="li-hero-side-cat" style="color:<?php echo htmlspecialchars($f['cat_col']); ?>"><?php echo htmlspecialchars($f['cat_nom']); ?></span>
                <?php endif; ?>
                <div class="li-hero-side-title"><?php echo htmlspecialchars($f['titre']); ?></div>
                <div class="li-hero-side-meta"><i class="far fa-clock"></i> <?php echo li_time_ago($f['date_publication']); ?> · <i class="fas fa-eye"></i> <?php echo number_format($f['nb_vues']); ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </aside>
</section>
<?php endif; ?>

<div class="li-two-col">
<div>

<?php if (!empty($latest)): ?>
<!-- Section : Derniers articles -->
<section class="li-section">
    <div class="li-section-head">
        <h2 class="li-section-title"><span class="li-section-accent"></span>À la rédaction</h2>
        <a href="<?php echo li_pub_url('category.php'); ?>" class="li-section-more">Tout voir <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="li-grid li-grid-3">
        <?php foreach ($latest as $a): ?>
        <a href="<?php echo li_pub_url('article.php?id=' . $a['id']); ?>" class="li-card">
            <div class="li-card-image" <?php if (li_image_for($a)): ?>style="background-image:url('<?php echo htmlspecialchars(li_image_for($a)); ?>')"<?php endif; ?>>
                <?php if (!li_image_for($a)): ?>
                <div class="li-card-image-ph"><i class="fas <?php echo htmlspecialchars($a['cat_icone'] ?? 'fa-newspaper'); ?>"></i></div>
                <?php endif; ?>
                <div class="li-card-flags">
                    <?php if ($a['a_la_une']): ?><span class="li-card-flag flag-une">Une</span><?php endif; ?>
                    <?php if ($a['exclusif']): ?><span class="li-card-flag flag-excl">Excl.</span><?php endif; ?>
                    <?php if ($a['priorite'] === 'urgente' || $a['priorite'] === 'flash'): ?><span class="li-card-flag flag-urg"><?php echo $a['priorite'] === 'flash' ? 'Flash' : 'Urg.'; ?></span><?php endif; ?>
                </div>
            </div>
            <div class="li-card-body">
                <?php if ($a['cat_nom']): ?>
                <span class="li-card-cat" style="color:<?php echo htmlspecialchars($a['cat_col']); ?>"><i class="fas <?php echo htmlspecialchars($a['cat_icone']); ?>"></i> <?php echo htmlspecialchars($a['cat_nom']); ?></span>
                <?php endif; ?>
                <h3 class="li-card-title"><?php echo htmlspecialchars($a['titre']); ?></h3>
                <p class="li-card-excerpt"><?php echo htmlspecialchars(li_plain($a['chapeau'] ?? '')); ?></p>
                <div class="li-card-meta">
                    <span><i class="fas fa-user"></i> <strong><?php echo htmlspecialchars(li_pub_author_name($a)); ?></strong></span>
                    <span><i class="far fa-clock"></i> <?php echo li_time_ago($a['date_publication']); ?></span>
                    <span><i class="fas fa-eye"></i> <?php echo number_format($a['nb_vues']); ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php elseif (!$une): ?>
<!-- Aucun article du tout publié -->
<div class="li-empty">
    <i class="fas fa-newspaper"></i>
    <h3>Aucun article pour l'instant</h3>
    <p>La rédaction prépare la prochaine édition. Revenez plus tard !</p>
</div>
<?php else: ?>
<!-- Hero présent mais pas d'autres articles : afficher un message de transition -->
<section class="li-section">
    <div class="li-section-head">
        <h2 class="li-section-title"><span class="li-section-accent"></span>À la rédaction</h2>
    </div>
    <div class="li-empty" style="padding: 2rem 1.5rem">
        <i class="fas fa-pen-nib"></i>
        <h3>Première édition</h3>
        <p>L'article ci-dessus est le premier de la rédaction. D'autres publications arrivent bientôt !</p>
    </div>
</section>
<?php endif; ?>

</div>

<!-- ── SIDEBAR ───────────────────────────────────────────── -->
<aside class="li-sidebar">

    <!-- Rubriques -->
    <?php if (!empty($LI_NAV_CATS)): ?>
    <div class="li-side-widget">
        <h3 class="li-side-title">Rubriques</h3>
        <div class="li-cat-chips">
            <?php foreach ($LI_NAV_CATS as $c): ?>
            <a href="<?php echo li_pub_url('category.php?slug=' . urlencode($c['slug'])); ?>" class="li-cat-chip">
                <i class="fas <?php echo htmlspecialchars($c['icone']); ?>" style="color:<?php echo htmlspecialchars($c['couleur']); ?>"></i>
                <?php echo htmlspecialchars($c['nom']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Plus lus -->
    <?php if (!empty($mostRead)): ?>
    <div class="li-side-widget">
        <h3 class="li-side-title">Les plus lus</h3>
        <div class="li-list">
            <?php foreach ($mostRead as $i => $m): ?>
            <a href="<?php echo li_pub_url('article.php?id=' . $m['id']); ?>" class="li-list-item">
                <div class="li-list-rank"><?php echo $i + 1; ?></div>
                <div class="li-list-body">
                    <div class="li-list-title"><?php echo htmlspecialchars($m['titre']); ?></div>
                    <div class="li-list-meta">
                        <?php if ($m['cat_nom']): ?>
                        <span style="color:<?php echo htmlspecialchars($m['cat_col']); ?>;font-weight:600"><?php echo htmlspecialchars($m['cat_nom']); ?></span> ·
                        <?php endif; ?>
                        <i class="fas fa-eye"></i> <?php echo number_format($m['nb_vues']); ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Communiqués -->
    <?php if (!empty($communiques)): ?>
    <div class="li-side-widget">
        <h3 class="li-side-title">Communiqués officiels</h3>
        <?php foreach ($communiques as $cp): ?>
        <a href="<?php echo li_pub_url('communique.php?id=' . $cp['id']); ?>" class="li-cp-card prio-<?php echo htmlspecialchars($cp['priorite']); ?>" style="padding:.85rem 1rem;margin-bottom:.6rem">
            <?php if ($cp['priorite'] !== 'normale'): ?><span class="li-cp-tag"><?php echo $cp['priorite'] === 'urgente' ? 'Urgent' : 'Important'; ?></span><?php endif; ?>
            <div class="li-cp-title" style="font-size:.95rem"><?php echo htmlspecialchars($cp['titre']); ?></div>
            <div class="li-cp-meta"><i class="fas fa-building"></i> <strong><?php echo htmlspecialchars($cp['source']); ?></strong> · <i class="far fa-clock"></i> <?php echo li_time_ago($cp['recu_le']); ?></div>
        </a>
        <?php endforeach; ?>
        <a href="<?php echo li_pub_url('communiques.php'); ?>" class="li-section-more" style="margin-top:.4rem">Tous les communiqués <i class="fas fa-arrow-right"></i></a>
    </div>
    <?php endif; ?>

    <!-- Newsletter / footer card -->
    <div class="li-side-widget" style="background:linear-gradient(135deg, var(--li-softer), transparent); border-color:var(--li-bd)">
        <h3 class="li-side-title" style="border-color:var(--li);color:var(--li)">Lettre du soir</h3>
        <p style="font-size:.85rem;color:var(--tx-3);line-height:1.55;margin-bottom:1rem">Recevez chaque soir l'essentiel de l'actualité Life Invader directement dans votre boîte mail.</p>
        <form id="liNewsletter" onsubmit="liNewsletterSubmit(event)" style="display:flex;gap:.4rem">
            <input type="email" required placeholder="votre@email.lys" style="flex:1;padding:.55rem .75rem;border-radius:var(--r-sm);border:1px solid var(--bd);background:var(--paper-3);color:var(--tx);font-size:.85rem">
            <button type="submit" style="padding:.55rem 1rem;background:var(--li);color:#fff;border:0;border-radius:var(--r-sm);font-weight:700;font-size:.78rem;cursor:pointer"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>

</aside>
</div>

<script>
function liNewsletterSubmit(e) {
    e.preventDefault();
    const f = e.currentTarget;
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;background:var(--paper);border:1px solid var(--li-bd);border-left:3px solid var(--li);border-radius:10px;padding:.85rem 1.1rem;color:var(--tx);font-family:Inter,sans-serif;font-size:.88rem;font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,.18);display:flex;align-items:center;gap:.55rem;opacity:0;transform:translateY(14px);transition:all .25s';
    t.innerHTML = '<i class="fas fa-check-circle" style="color:var(--li)"></i> Inscription enregistrée (démo RP).';
    document.body.appendChild(t);
    requestAnimationFrame(() => { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; });
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(14px)'; setTimeout(() => t.remove(), 280); }, 3200);
    f.reset();
    return false;
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
