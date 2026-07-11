<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '📦 PostOP';
$companyColor   = '#3b82f6';
$companyFaction = 'POSTOP';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion des Articles';
$db        = Database::getInstance()->getConnection();
$message   = '';
$msgType   = '';

/* ── Permissions ── */
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'stock.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'stock.delete');

/* ═══ ACTIONS POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    /* ── Ajouter ── */
    if ($action === 'add_article') {
        try {
            $name        = sanitize($_POST['name']);
            $price       = floatval($_POST['price']);
            $description = sanitize($_POST['description'] ?? '');
            $db->prepare("INSERT INTO postop_articles (name, price, description) VALUES (?,?,?)")
               ->execute([$name, $price, $description]);
            $message = '✅ Article ajouté avec succès.';
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    /* ── Modifier ── */
    if ($action === 'update_article') {
        try {
            $id          = intval($_POST['article_id']);
            $name        = sanitize($_POST['name']);
            $price       = floatval($_POST['price']);
            $description = sanitize($_POST['description'] ?? '');
            $db->prepare("UPDATE postop_articles SET name=?, price=?, description=? WHERE id=?")
               ->execute([$name, $price, $description, $id]);
            $message = '✅ Article modifié.';
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    /* ── Toggle actif ── */
    if ($action === 'toggle_active') {
        try {
            $id        = intval($_POST['article_id']);
            $is_active = intval($_POST['is_active']);
            $db->prepare("UPDATE postop_articles SET is_active=? WHERE id=?")->execute([$is_active, $id]);
            $message = '✅ Statut mis à jour.';
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    /* ── Supprimer ── */
    if ($action === 'delete_article') {
        try {
            $id = intval($_POST['article_id']);
            /* Vérifier s'il y a des commandes actives avec cet article */
            $used = $db->prepare("
                SELECT COUNT(*) FROM postop_order_items oi
                JOIN postop_orders o ON oi.order_id = o.id
                WHERE oi.article_id = ? AND o.status IN ('preparation','waiting')
            ");
            $used->execute([$id]);
            if ($used->fetchColumn() > 0) {
                $message = '⚠️ Impossible : cet article est utilisé dans une commande active.';
                $msgType = 'error';
            } else {
                $db->prepare("DELETE FROM postop_articles WHERE id=?")->execute([$id]);
                $message = '✅ Article supprimé.';
                $msgType = 'success';
            }
        } catch (Exception $e) {
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

/* ── Données ── */
$articles      = $db->query("SELECT * FROM postop_articles ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalArticles = count($articles);
$activeCount   = count(array_filter($articles, fn($a) => $a['is_active']));
$totalValue    = array_sum(array_column($articles, 'price'));

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/postop.css">
<style>:root{ --co: <?php echo $companyColor; ?>; --co-dim: rgba(59,130,246,.15); --co-glow: rgba(59,130,246,.4); }</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $msgType; ?>" style="margin:1rem 1.5rem 0;">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<div class="postop-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <div class="page-title"><i class="fas fa-boxes"></i> Articles</div>
            <div class="page-subtitle">Catalogue des articles disponibles · PostOP</div>
        </div>
        <?php if ($canManage): ?>
        <button class="btn btn-primary" onclick="openModal('addArticleModal')">
            <i class="fas fa-plus"></i> Ajouter un article
        </button>
        <?php endif; ?>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalArticles; ?></div>
                <div class="stat-label">Articles Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $activeCount; ?></div>
                <div class="stat-label">Articles Actifs</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($totalValue,0); ?></div>
                <div class="stat-label">Valeur Catalogue</div>
            </div>
        </div>
    </div>

    <!-- GRILLE ARTICLES -->
    <?php if (empty($articles)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <p>Aucun article disponible pour le moment.</p>
            <?php if ($canManage): ?>
            <button class="btn btn-primary" onclick="openModal('addArticleModal')">
                <i class="fas fa-plus"></i> Ajouter le premier article
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="articles-grid">
        <?php foreach ($articles as $article): ?>
        <div class="article-card <?php echo $article['is_active'] ? '' : 'inactive'; ?>" id="acard-<?php echo $article['id']; ?>">

            <div class="article-hdr">
                <div>
                    <div class="article-name"><?php echo htmlspecialchars($article['name']); ?></div>
                    <div class="article-price">$<?php echo number_format($article['price'],2); ?></div>
                </div>
                <?php if ($canManage): ?>
                <label class="toggle-switch" title="<?php echo $article['is_active'] ? 'Désactiver' : 'Activer'; ?>">
                    <input
                        type="checkbox"
                        <?php echo $article['is_active'] ? 'checked' : ''; ?>
                        onchange="toggleArticle(<?php echo $article['id']; ?>, this.checked)"
                    >
                    <span class="toggle-slider"></span>
                </label>
                <?php endif; ?>
            </div>

            <?php if ($article['description']): ?>
            <p class="article-desc"><?php echo htmlspecialchars($article['description']); ?></p>
            <?php endif; ?>

            <span class="article-status <?php echo $article['is_active'] ? 'active' : 'inactive'; ?>" id="astatus-<?php echo $article['id']; ?>">
                <i class="fas fa-<?php echo $article['is_active'] ? 'check-circle' : 'times-circle'; ?>"></i>
                <?php echo $article['is_active'] ? 'Disponible' : 'Indisponible'; ?>
            </span>

            <?php if ($canManage): ?>
            <div class="article-actions">
                <button
                    class="btn btn-secondary btn-sm"
                    style="flex:1;"
                    onclick='editArticle(<?php echo json_encode($article, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'
                >
                    <i class="fas fa-edit"></i> Modifier
                </button>
                <button
                    class="btn btn-danger btn-sm"
                    onclick="deleteArticle(<?php echo $article['id']; ?>, '<?php echo htmlspecialchars(addslashes($article['name'])); ?>')"
                >
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /postop-wrap -->

<!-- ══════════ MODAL AJOUTER ══════════ -->
<?php if ($canManage): ?>
<div id="addArticleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter un article</h2>
            <button class="modal-close" onclick="closeModal('addArticleModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_article">
                <div class="form-group" style="margin-bottom:.9rem;">
                    <label class="form-label">Nom de l'article *</label>
                    <input type="text" name="name" class="form-input" placeholder="Ex: Colis Express" required>
                </div>
                <div class="form-group" style="margin-bottom:.9rem;">
                    <label class="form-label">Prix ($) *</label>
                    <input type="number" name="price" class="form-input" min="0" step="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" rows="3" placeholder="Description optionnelle..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addArticleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ajouter</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ MODAL MODIFIER ══════════ -->
<div id="editArticleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier l'article</h2>
            <button class="modal-close" onclick="closeModal('editArticleModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_article">
                <input type="hidden" name="article_id" id="edit_id">
                <div class="form-group" style="margin-bottom:.9rem;">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="name" id="edit_name" class="form-input" required>
                </div>
                <div class="form-group" style="margin-bottom:.9rem;">
                    <label class="form-label">Prix ($) *</label>
                    <input type="number" name="price" id="edit_price" class="form-input" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-textarea" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editArticleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Modal helpers ── */
function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

/* ── Edit article ── */
function editArticle(article) {
    document.getElementById('edit_id').value          = article.id;
    document.getElementById('edit_name').value        = article.name;
    document.getElementById('edit_price').value       = article.price;
    document.getElementById('edit_description').value = article.description || '';
    openModal('editArticleModal');
}

/* ── Toggle actif (form POST) ── */
function toggleArticle(id, isActive) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `
        <input type="hidden" name="action"     value="toggle_active">
        <input type="hidden" name="article_id" value="${id}">
        <input type="hidden" name="is_active"  value="${isActive ? 1 : 0}">
    `;
    document.body.appendChild(f);
    f.submit();
}

/* ── Supprimer ── */
async function deleteArticle(id, name) {
    const C = window.Company || window.Pearl;
    if (!await C.confirm({ message: `Supprimer "${name}" ?\n\nAttention : irréversible.`, tone: 'danger', title: 'Suppression' })) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `
        <input type="hidden" name="action"     value="delete_article">
        <input type="hidden" name="article_id" value="${id}">
    `;
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>