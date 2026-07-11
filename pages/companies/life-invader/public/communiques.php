<?php
require_once __DIR__ . '/_bootstrap.php';

$db = li_db();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;
$fPrio   = $_GET['prio'] ?? '';

$where  = ["statut='publie'"];
$params = [];
if (in_array($fPrio, ['normale','importante','urgente'], true)) {
    $where[] = 'priorite=?';
    $params[] = $fPrio;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM li_communiques $whereSQL");
$total->execute($params);
$total = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$st = $db->prepare("
    SELECT id, reference, titre, contenu, source, contact, priorite, recu_le, updated_at
    FROM li_communiques
    $whereSQL
    ORDER BY FIELD(priorite,'urgente','importante','normale'), COALESCE(recu_le, created_at) DESC
    LIMIT $perPage OFFSET $offset");
$st->execute($params);
$communiques = $st->fetchAll(PDO::FETCH_ASSOC);

$LI_PAGE_TITLE = "Communiqués officiels — Life Invader";
$LI_PAGE_DESC = "Tous les communiqués officiels reçus par la rédaction Life Invader.";

include __DIR__ . '/_header.php';
?>

<header class="li-cat-header">
    <div class="li-cat-header-icon" style="background:var(--li-softer);color:var(--li);border:1px solid var(--li-bd)"><i class="fas fa-envelope-open-text"></i></div>
    <h1>Communiqués officiels</h1>
    <p>L'ensemble des communiqués officiels publiés par les institutions et les organisations de Lysoria, relayés par la rédaction Life Invader.</p>
</header>

<div class="li-filter-bar">
    <span class="li-filter-lbl">Filtrer par priorité</span>
    <a href="<?php echo li_pub_url('communiques.php'); ?>" class="li-cat-chip<?php echo $fPrio===''?' active':''; ?>">Tous</a>
    <a href="<?php echo li_pub_url('communiques.php?prio=urgente'); ?>" class="li-cat-chip<?php echo $fPrio==='urgente'?' active':''; ?>" style="<?php echo $fPrio==='urgente'?'background:#dc2626;border-color:#dc2626':''; ?>"><i class="fas fa-bolt"></i> Urgents</a>
    <a href="<?php echo li_pub_url('communiques.php?prio=importante'); ?>" class="li-cat-chip<?php echo $fPrio==='importante'?' active':''; ?>" style="<?php echo $fPrio==='importante'?'background:#d97706;border-color:#d97706':''; ?>"><i class="fas fa-exclamation"></i> Importants</a>
    <a href="<?php echo li_pub_url('communiques.php?prio=normale'); ?>" class="li-cat-chip<?php echo $fPrio==='normale'?' active':''; ?>">Normaux</a>
    <span class="li-filter-result"><strong><?php echo number_format($total); ?></strong> communiqué<?php echo $total>1?'s':''; ?></span>
</div>

<?php if (empty($communiques)): ?>
<div class="li-empty">
    <i class="far fa-envelope"></i>
    <h3>Aucun communiqué pour l'instant</h3>
    <p>Aucun communiqué officiel n'a été publié ici.</p>
</div>
<?php else: ?>
<div>
    <?php foreach ($communiques as $cp): ?>
    <a href="<?php echo li_pub_url('communique.php?id=' . $cp['id']); ?>" class="li-cp-card prio-<?php echo htmlspecialchars($cp['priorite']); ?>">
        <?php if ($cp['priorite'] !== 'normale'): ?>
        <span class="li-cp-tag"><?php echo $cp['priorite'] === 'urgente' ? 'Urgent' : 'Important'; ?></span>
        <?php else: ?>
        <span class="li-cp-tag" style="opacity:.85">Communiqué</span>
        <?php endif; ?>
        <h3 class="li-cp-title"><?php echo htmlspecialchars($cp['titre']); ?></h3>
        <p class="li-cp-excerpt"><?php echo htmlspecialchars(li_excerpt($cp['contenu'], 200)); ?></p>
        <div class="li-cp-meta">
            <span><i class="fas fa-building"></i> <strong><?php echo htmlspecialchars($cp['source']); ?></strong></span>
            <span><i class="far fa-clock"></i> <?php echo li_time_ago($cp['recu_le']); ?></span>
            <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--tx-faint)"><?php echo htmlspecialchars($cp['reference']); ?></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($totalPages > 1):
    $baseUrl = li_pub_url('communiques.php') . '?' . ($fPrio ? 'prio=' . urlencode($fPrio) . '&' : '');
?>
<nav class="li-pagi">
    <?php if ($page > 1): ?><a href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>"><i class="fas fa-chevron-left"></i></a><?php else: ?><span class="disabled"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <?php if ($i == $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo $baseUrl . 'page=' . $i; ?>"><?php echo $i; ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>"><i class="fas fa-chevron-right"></i></a><?php else: ?><span class="disabled"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
