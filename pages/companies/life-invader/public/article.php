<?php
require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . li_pub_url());
    exit;
}

$db = li_db();
$hasImg = li_has_column('li_articles', 'image_principale');
$imgSel = $hasImg ? 'a.image_principale' : 'NULL AS image_principale';

$art = $db->prepare("
    SELECT a.id, a.reference, a.titre, a.chapeau, a.contenu, a.tags, a.priorite,
           a.a_la_une, a.exclusif, a.nb_vues, a.nb_likes, a.nb_partages,
           a.date_publication, a.updated_at, $imgSel,
           c.id AS cat_id, c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone, c.slug AS cat_slug,
           u.username AS author_username, u.faction_rank AS author_rank,
           p.firstname AS author_firstname, p.lastname AS author_lastname
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    JOIN users u ON u.id = a.auteur_id
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE a.id = ? AND a.statut='publie'");
$art->execute([$id]);
$art = $art->fetch(PDO::FETCH_ASSOC);

if (!$art) {
    http_response_code(404);
    $LI_PAGE_TITLE = 'Article introuvable — Life Invader';
    include __DIR__ . '/_header.php';
    ?>
    <div class="li-empty">
        <i class="fas fa-circle-question"></i>
        <h3>Article introuvable</h3>
        <p>Cet article a peut-être été retiré ou n'a jamais existé.</p>
        <a href="<?php echo li_pub_url(); ?>" class="li-section-more" style="margin-top:1rem;display:inline-flex">
            <i class="fas fa-arrow-left"></i> Retour à l'accueil
        </a>
    </div>
    <?php
    include __DIR__ . '/_footer.php';
    exit;
}

li_track_view($id);

$relatedSt = $db->prepare("
    SELECT a.id, a.titre, a.chapeau, a.nb_vues, a.date_publication, $imgSel,
           c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_icone
    FROM li_articles a
    LEFT JOIN li_categories c ON c.id = a.categorie_id
    WHERE a.statut='publie' AND a.id <> ?
      AND (a.categorie_id = ? OR ? IS NULL)
    ORDER BY a.date_publication DESC LIMIT 4");
$relatedSt->execute([$id, $art['cat_id'], $art['cat_id']]);
$related = $relatedSt->fetchAll(PDO::FETCH_ASSOC);

$LI_PAGE_TITLE  = $art['titre'] . ' — Life Invader';
$LI_PAGE_DESC   = $art['chapeau'] ?: li_excerpt($art['contenu'], 200);
$LI_PAGE_OG_IMG = li_image_for($art) ?: '';

$authorName = li_pub_author_name($art);
$authorInit = mb_strtoupper(mb_substr($authorName, 0, 1));
$tags = array_filter(array_map('trim', explode(',', $art['tags'] ?? '')));

include __DIR__ . '/_header.php';
?>

<div class="li-back-bar">
    <a href="<?php echo li_pub_url(); ?>" class="li-back-link"><i class="fas fa-arrow-left"></i> Retour à l'édition</a>
</div>

<article class="li-article-page">
    <?php if (li_image_for($art)): ?>
    <header class="li-article-hero" style="background-image:url('<?php echo htmlspecialchars(li_image_for($art)); ?>')">
        <div class="li-article-hero-overlay">
            <div class="li-article-hero-content">
                <?php if (!empty($art['cat_nom'])): ?>
                <span class="li-article-cat"><i class="fas <?php echo htmlspecialchars($art['cat_icone']); ?>"></i> <?php echo htmlspecialchars($art['cat_nom']); ?></span>
                <?php endif; ?>
                <h1 class="li-article-title" style="font-size:2.4rem"><?php echo htmlspecialchars($art['titre']); ?></h1>
                <?php if ($art['chapeau']): ?>
                <p class="li-article-chapeau" style="color:rgba(255,255,255,.92);border-color:#fff"><?php echo htmlspecialchars(li_plain($art['chapeau'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <?php endif; ?>

    <div class="li-article-body">
        <?php if (!li_image_for($art)): ?>
            <?php if (!empty($art['cat_nom'])): ?>
            <span class="li-article-cat" style="color:<?php echo htmlspecialchars($art['cat_col']); ?>;background:<?php echo htmlspecialchars($art['cat_col']); ?>14;border-color:<?php echo htmlspecialchars($art['cat_col']); ?>33"><i class="fas <?php echo htmlspecialchars($art['cat_icone']); ?>"></i> <?php echo htmlspecialchars($art['cat_nom']); ?></span>
            <?php endif; ?>
            <h1 class="li-article-title"><?php echo htmlspecialchars($art['titre']); ?></h1>
            <?php if ($art['chapeau']): ?><p class="li-article-chapeau"><?php echo htmlspecialchars(li_plain($art['chapeau'])); ?></p><?php endif; ?>
        <?php endif; ?>

        <div class="li-article-meta">
            <div class="li-article-author">
                <div class="li-article-author-avatar"><?php echo htmlspecialchars($authorInit); ?></div>
                <div>
                    <div class="li-article-author-name"><?php echo htmlspecialchars($authorName); ?></div>
                    <?php if (!empty($art['author_rank'])): ?><div class="li-article-author-role"><?php echo htmlspecialchars($art['author_rank']); ?></div><?php endif; ?>
                </div>
            </div>
            <span class="li-article-meta-item"><i class="far fa-calendar"></i> Publié <?php echo $art['date_publication'] ? date('d F Y', strtotime($art['date_publication'])) : '—'; ?></span>
            <?php if ($art['date_publication'] && strtotime($art['updated_at']) - strtotime($art['date_publication']) > 60): ?>
            <span class="li-article-meta-item" style="color:var(--tx-mute);font-style:italic"><i class="fas fa-pen"></i> Mis à jour <?php echo li_time_ago($art['updated_at']); ?></span>
            <?php endif; ?>
            <span class="li-article-meta-item"><i class="fas fa-eye"></i> <?php echo number_format($art['nb_vues']); ?> lecture<?php echo $art['nb_vues']>1?'s':''; ?></span>
            <span class="li-article-meta-item"><i class="fas fa-heart"></i> <?php echo number_format($art['nb_likes']); ?></span>
            <?php if ($art['exclusif']): ?>
            <span class="li-article-meta-item" style="background:rgba(124,58,237,.1);color:#7c3aed;padding:.2rem .55rem;border-radius:4px;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.1em">Exclusif</span>
            <?php endif; ?>
        </div>

        <div class="li-article-content"><?php echo li_pub_sanitize_html($art['contenu']); ?></div>

        <?php if (!empty($tags)): ?>
        <div class="li-article-tags">
            <?php foreach ($tags as $t): ?>
            <a href="<?php echo li_pub_url('search.php?q=' . urlencode($t)); ?>" class="li-cat-chip">#<?php echo htmlspecialchars($t); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="li-article-share">
            <span class="li-article-share-lbl">Partager</span>
            <button class="li-share-btn" onclick="liShare('twitter')" title="Twitter"><i class="fab fa-twitter"></i></button>
            <button class="li-share-btn" onclick="liShare('facebook')" title="Facebook"><i class="fab fa-facebook-f"></i></button>
            <button class="li-share-btn" onclick="liShare('linkedin')" title="LinkedIn"><i class="fab fa-linkedin-in"></i></button>
            <button class="li-share-btn" onclick="liShareCopy()" title="Copier le lien"><i class="fas fa-link"></i></button>
        </div>
    </div>
</article>

<?php if (!empty($related)): ?>
<section class="li-section" style="margin-top:3rem">
    <div class="li-section-head">
        <h2 class="li-section-title"><span class="li-section-accent"></span>À lire aussi</h2>
    </div>
    <div class="li-grid li-grid-<?php echo min(4, count($related)); ?>">
        <?php foreach ($related as $r): ?>
        <a href="<?php echo li_pub_url('article.php?id=' . $r['id']); ?>" class="li-card">
            <div class="li-card-image" <?php if (li_image_for($r)): ?>style="background-image:url('<?php echo htmlspecialchars(li_image_for($r)); ?>')"<?php endif; ?>>
                <?php if (!li_image_for($r)): ?>
                <div class="li-card-image-ph"><i class="fas <?php echo htmlspecialchars($r['cat_icone'] ?? 'fa-newspaper'); ?>"></i></div>
                <?php endif; ?>
            </div>
            <div class="li-card-body">
                <?php if ($r['cat_nom']): ?>
                <span class="li-card-cat" style="color:<?php echo htmlspecialchars($r['cat_col']); ?>"><i class="fas <?php echo htmlspecialchars($r['cat_icone']); ?>"></i> <?php echo htmlspecialchars($r['cat_nom']); ?></span>
                <?php endif; ?>
                <h3 class="li-card-title"><?php echo htmlspecialchars($r['titre']); ?></h3>
                <div class="li-card-meta">
                    <span><i class="far fa-clock"></i> <?php echo li_time_ago($r['date_publication']); ?></span>
                    <span><i class="fas fa-eye"></i> <?php echo number_format($r['nb_vues']); ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script>
function liShare(net) {
    const url = encodeURIComponent(window.location.href);
    const txt = encodeURIComponent(<?php echo json_encode($art['titre']); ?>);
    let u = '';
    if (net === 'twitter') u = `https://twitter.com/intent/tweet?text=${txt}&url=${url}`;
    if (net === 'facebook') u = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
    if (net === 'linkedin') u = `https://www.linkedin.com/sharing/share-offsite/?url=${url}`;
    if (u) window.open(u, '_blank', 'noopener,width=600,height=480');
}
function liShareCopy() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        const btn = event.currentTarget;
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.style.background = 'var(--success)';
        btn.style.color = '#fff';
        setTimeout(() => { btn.innerHTML = orig; btn.style.background=''; btn.style.color=''; }, 1500);
    });
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
