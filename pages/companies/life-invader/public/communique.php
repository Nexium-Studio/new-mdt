<?php
require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . li_pub_url('communiques.php'));
    exit;
}

$db = li_db();

$cp = $db->prepare("
    SELECT id, reference, titre, contenu, source, contact, priorite, recu_le, updated_at, article_id
    FROM li_communiques
    WHERE id=? AND statut='publie'");
$cp->execute([$id]);
$cp = $cp->fetch(PDO::FETCH_ASSOC);

if (!$cp) {
    http_response_code(404);
    $LI_PAGE_TITLE = "Communiqué introuvable — Life Invader";
    include __DIR__ . '/_header.php';
    ?>
    <div class="li-empty">
        <i class="fas fa-circle-question"></i>
        <h3>Communiqué introuvable</h3>
        <p>Ce communiqué a peut-être été retiré ou n'a jamais existé.</p>
        <a href="<?php echo li_pub_url('communiques.php'); ?>" class="li-section-more" style="margin-top:1rem;display:inline-flex"><i class="fas fa-arrow-left"></i> Voir tous les communiqués</a>
    </div>
    <?php
    include __DIR__ . '/_footer.php';
    exit;
}

$linkedArticle = null;
if (!empty($cp['article_id'])) {
    $la = $db->prepare("SELECT id, titre FROM li_articles WHERE id=? AND statut='publie'");
    $la->execute([$cp['article_id']]);
    $linkedArticle = $la->fetch(PDO::FETCH_ASSOC);
}

$LI_PAGE_TITLE = $cp['titre'] . " — Communiqué officiel · Life Invader";
$LI_PAGE_DESC = li_excerpt($cp['contenu'], 200);

include __DIR__ . '/_header.php';
?>

<div class="li-back-bar">
    <a href="<?php echo li_pub_url('communiques.php'); ?>" class="li-back-link"><i class="fas fa-arrow-left"></i> Tous les communiqués</a>
</div>

<article class="li-article-page">
    <div class="li-article-body" style="padding-top:2.5rem">
        <span class="li-article-cat" style="<?php
            if ($cp['priorite']==='urgente') echo 'background:#fef2f2;color:#dc2626;border-color:#fecaca';
            elseif ($cp['priorite']==='importante') echo 'background:#fff7ed;color:#d97706;border-color:#fed7aa';
        ?>">
            <i class="fas fa-envelope-open-text"></i>
            Communiqué officiel<?php if($cp['priorite']!=='normale') echo ' · ' . ($cp['priorite']==='urgente'?'Urgent':'Important'); ?>
        </span>
        <h1 class="li-article-title"><?php echo htmlspecialchars($cp['titre']); ?></h1>

        <div class="li-article-meta">
            <div class="li-article-author">
                <div class="li-article-author-avatar" style="background:linear-gradient(135deg,#64748b,#475569)"><i class="fas fa-building"></i></div>
                <div>
                    <div class="li-article-author-name"><?php echo htmlspecialchars($cp['source']); ?></div>
                    <div class="li-article-author-role">Source officielle</div>
                </div>
            </div>
            <span class="li-article-meta-item"><i class="far fa-calendar"></i> Reçu le <?php echo $cp['recu_le'] ? date('d F Y', strtotime($cp['recu_le'])) : '—'; ?></span>
            <?php if (!empty($cp['contact'])): ?>
            <span class="li-article-meta-item"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($cp['contact']); ?></span>
            <?php endif; ?>
            <span class="li-article-meta-item" style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:.72rem;color:var(--tx-mute)"><?php echo htmlspecialchars($cp['reference']); ?></span>
        </div>

        <div class="li-article-content"><?php echo li_pub_sanitize_html($cp['contenu']); ?></div>

        <?php if ($linkedArticle): ?>
        <div style="background:var(--li-softer);border:1px solid var(--li-bd);border-radius:var(--r-md);padding:1.2rem 1.4rem;margin-top:2.5rem">
            <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--li);margin-bottom:.5rem"><i class="fas fa-link"></i> Article lié</div>
            <a href="<?php echo li_pub_url('article.php?id=' . $linkedArticle['id']); ?>" style="font-family:'Playfair Display',serif;font-size:1.18rem;font-weight:700;color:var(--tx);text-decoration:none">
                <?php echo htmlspecialchars($linkedArticle['titre']); ?>
                <i class="fas fa-arrow-right" style="font-size:.85rem;margin-left:.4rem;color:var(--li)"></i>
            </a>
        </div>
        <?php endif; ?>

        <div class="li-article-share">
            <span class="li-article-share-lbl">Partager</span>
            <button class="li-share-btn" onclick="liShareCpy()" title="Copier le lien"><i class="fas fa-link"></i></button>
        </div>
    </div>
</article>

<script>
function liShareCpy() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        const btn = event.currentTarget;
        const o = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.style.background = 'var(--success)'; btn.style.color = '#fff';
        setTimeout(() => { btn.innerHTML = o; btn.style.background=''; btn.style.color=''; }, 1500);
    });
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
