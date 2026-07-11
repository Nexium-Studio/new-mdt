<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '📦 PostOP';
$companyColor   = '#3b82f6';
$companyFaction = 'POSTOP';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Commandes Actives';
$db        = Database::getInstance()->getConnection();
$message   = '';
$msgType   = '';

/* ── Permissions ── */
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'vente.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'vente.create');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'vente.delete');

/* ═══ ACTIONS POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    /* ── Créer commande ── */
    if ($action === 'create_order') {
        try {
            $db->beginTransaction();

            $customer_name     = sanitize($_POST['customer_name']);
            $order_time        = $_POST['order_time'];
            $delivery_time     = !empty($_POST['delivery_time']) ? $_POST['delivery_time'] : null;
            $delivery_location = sanitize($_POST['delivery_location']);
            $delivery_fee      = floatval($_POST['delivery_fee'] ?? 0);
            $employee_name     = sanitize($_POST['employee_name']);
            $notes             = sanitize($_POST['notes'] ?? '');
            $article_ids       = $_POST['articles']   ?? [];
            $quantities        = $_POST['quantities']  ?? [];

            /* Calculer le total */
            $total      = $delivery_fee;
            $stmtPrice  = $db->prepare("SELECT price FROM postop_articles WHERE id = ?");
            $prices     = [];
            foreach ($article_ids as $i => $aid) {
                if (empty($aid)) continue;
                $stmtPrice->execute([$aid]);
                $art = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                if ($art) {
                    $qty = max(1, intval($quantities[$i] ?? 1));
                    $prices[$aid] = $art['price'];
                    $total += $art['price'] * $qty;
                }
            }

            /* Insérer la commande */
            $stmtOrd = $db->prepare("
                INSERT INTO postop_orders
                    (customer_name, order_time, delivery_time, delivery_location,
                     delivery_fee, employee_name, status, total_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,'preparation',?,?,?)
            ");
            $stmtOrd->execute([
                $customer_name, $order_time, $delivery_time, $delivery_location,
                $delivery_fee, $employee_name, $total, $notes, $_SESSION['user_id']
            ]);
            $order_id = $db->lastInsertId();

            /* Insérer les articles */
            $stmtItem = $db->prepare("
                INSERT INTO postop_order_items (order_id, article_id, quantity, unit_price, subtotal)
                VALUES (?,?,?,?,?)
            ");
            foreach ($article_ids as $i => $aid) {
                if (empty($aid) || !isset($prices[$aid])) continue;
                $qty = max(1, intval($quantities[$i] ?? 1));
                $stmtItem->execute([$order_id, $aid, $qty, $prices[$aid], $prices[$aid] * $qty]);
            }

            $db->commit();
            $message = '✅ Commande #' . $order_id . ' créée avec succès.';
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    /* ── Changer statut ── */
    if ($action === 'change_status') {
        try {
            $order_id   = intval($_POST['order_id']);
            $new_status = $_POST['new_status'];
            $allowed    = ['preparation','waiting','delivered'];
            if (!in_array($new_status, $allowed)) throw new Exception('Statut invalide.');

            if ($new_status === 'delivered') {
                $db->prepare("UPDATE postop_orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?")
                   ->execute([$order_id]);
                $message = '✅ Commande marquée comme livrée.';
            } else {
                $db->prepare("UPDATE postop_orders SET status = ? WHERE id = ?")
                   ->execute([$new_status, $order_id]);
                $message = '✅ Statut mis à jour.';
            }
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ Erreur : ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

/* ── Données ── */
$orders = $db->query("
    SELECT o.*, COUNT(oi.id) as items_count, COALESCE(SUM(oi.quantity),0) as total_items
    FROM postop_orders o
    LEFT JOIN postop_order_items oi ON o.id = oi.order_id
    WHERE o.status IN ('preparation','waiting')
    GROUP BY o.id
    ORDER BY o.order_time DESC
")->fetchAll(PDO::FETCH_ASSOC);

$articles = $db->query("SELECT * FROM postop_articles WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'preparation' => '<i class="fas fa-tools"></i> En préparation',
    'waiting'     => '<i class="fas fa-box"></i> En attente',
    'delivered'   => '<i class="fas fa-check"></i> Livré',
];

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
            <div class="page-title"><i class="fas fa-clipboard-list"></i> Commandes Actives</div>
            <div class="page-subtitle">Gestion des commandes en cours · PostOP</div>
        </div>
        <?php if ($canManage): ?>
        <button class="btn btn-primary" onclick="openModal('createOrderModal')">
            <i class="fas fa-plus"></i> Nouvelle commande
        </button>
        <?php endif; ?>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count(array_filter($orders, fn($o)=>$o['status']==='preparation')); ?></div>
                <div class="stat-label">En Préparation</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                <i class="fas fa-box"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count(array_filter($orders, fn($o)=>$o['status']==='waiting')); ?></div>
                <div class="stat-label">En Attente</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a)">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format(array_sum(array_column($orders,'total_amount')),0); ?></div>
                <div class="stat-label">Montant Total</div>
            </div>
        </div>
    </div>

    <!-- LISTE DES COMMANDES -->
    <?php if (empty($orders)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <p>Aucune commande active pour le moment.</p>
            <?php if ($canManage): ?>
            <button class="btn btn-primary" onclick="openModal('createOrderModal')">
                <i class="fas fa-plus"></i> Créer la première commande
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <?php foreach ($orders as $order):
            $items = $db->prepare("
                SELECT oi.*, a.name as article_name
                FROM postop_order_items oi
                JOIN postop_articles a ON oi.article_id = a.id
                WHERE oi.order_id = ?
            ");
            $items->execute([$order['id']]);
            $order_items = $items->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="order-card status-<?php echo $order['status']; ?>">

            <!-- En-tête -->
            <div class="order-hdr">
                <div>
                    <div class="order-num">Commande #<?php echo $order['id']; ?></div>
                    <div class="order-customer"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                </div>
                <span class="badge badge-<?php echo $order['status']; ?>">
                    <?php echo $statusLabels[$order['status']]; ?>
                </span>
            </div>

            <!-- Méta -->
            <div class="order-meta">
                <div class="meta-item">
                    <div class="meta-label">Heure commande</div>
                    <div class="meta-value"><?php echo date('d/m H:i', strtotime($order['order_time'])); ?></div>
                </div>
                <?php if ($order['delivery_time']): ?>
                <div class="meta-item">
                    <div class="meta-label">Livraison prévue</div>
                    <div class="meta-value"><?php echo date('d/m H:i', strtotime($order['delivery_time'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <div class="meta-label">Lieu livraison</div>
                    <div class="meta-value"><?php echo htmlspecialchars($order['delivery_location']); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Employé</div>
                    <div class="meta-value"><?php echo htmlspecialchars($order['employee_name']); ?></div>
                </div>
            </div>

            <!-- Articles -->
            <?php if (!empty($order_items)): ?>
            <div class="items-section">
                <div class="items-title"><i class="fas fa-list"></i> Articles commandés</div>
                <?php foreach ($order_items as $item): ?>
                <div class="item-row">
                    <span><strong><?php echo htmlspecialchars($item['article_name']); ?></strong> × <?php echo $item['quantity']; ?></span>
                    <span>$<?php echo number_format($item['subtotal'], 2); ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($order['delivery_fee'] > 0): ?>
                <div class="item-row">
                    <span style="color:var(--co)">Frais de livraison</span>
                    <span style="color:var(--co)">$<?php echo number_format($order['delivery_fee'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Total -->
            <div class="total-row">
                <span>TOTAL</span>
                <span class="total-amount">$<?php echo number_format($order['total_amount'], 2); ?></span>
            </div>

            <!-- Notes -->
            <?php if ($order['notes']): ?>
            <div class="notes-box">
                <div class="notes-label"><i class="fas fa-sticky-note"></i> Notes</div>
                <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <?php if ($canManage): ?>
            <div class="order-actions">
                <?php if ($order['status'] === 'preparation'): ?>
                <button class="btn btn-primary btn-sm" onclick="changeStatus(<?php echo $order['id']; ?>,'waiting')">
                    <i class="fas fa-box"></i> Mettre en attente
                </button>
                <?php endif; ?>
                <?php if ($order['status'] === 'waiting'): ?>
                <button class="btn btn-secondary btn-sm" onclick="changeStatus(<?php echo $order['id']; ?>,'preparation')">
                    <i class="fas fa-undo"></i> Remettre en préparation
                </button>
                <?php endif; ?>
                <button class="btn btn-success btn-sm" onclick="changeStatus(<?php echo $order['id']; ?>,'delivered')">
                    <i class="fas fa-check"></i> Marquer livré
                </button>
                <button class="btn btn-secondary btn-sm" onclick="viewOrder(<?php echo $order['id']; ?>)">
                    <i class="fas fa-eye"></i> Détails
                </button>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div><!-- /postop-wrap -->

<!-- ══════════ MODAL CRÉER COMMANDE ══════════ -->
<?php if ($canManage): ?>
<div id="createOrderModal" class="modal-overlay hidden">
    <div class="modal" style="max-width:820px;">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle commande</h2>
            <button class="modal-close" onclick="closeModal('createOrderModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_order">

                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Client / Entreprise *</label>
                        <input type="text" name="customer_name" class="form-input" placeholder="Nom du client" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Heure de commande *</label>
                        <input type="datetime-local" name="order_time" class="form-input"
                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Livraison prévue</label>
                        <input type="datetime-local" name="delivery_time" class="form-input">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Lieu de livraison *</label>
                        <input type="text" name="delivery_location" class="form-input" placeholder="Adresse ou lieu" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Frais de livraison ($)</label>
                        <input type="number" name="delivery_fee" id="delivery_fee"
                               class="form-input" min="0" step="0.01" value="0" oninput="updateTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employé *</label>
                        <input type="text" name="employee_name" class="form-input"
                               value="<?php echo htmlspecialchars(mdt_session_display_name()); ?>" required>
                    </div>
                </div>

                <!-- Articles -->
                <div class="section-title"><i class="fas fa-box"></i> Articles</div>
                <div id="articlesContainer">
                    <div class="article-row-form">
                        <select name="articles[]" class="form-select" onchange="updateTotal()">
                            <option value="">— Sélectionner un article —</option>
                            <?php foreach ($articles as $a): ?>
                            <option value="<?php echo $a['id']; ?>" data-price="<?php echo $a['price']; ?>">
                                <?php echo htmlspecialchars($a['name']); ?> — $<?php echo number_format($a['price'],2); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="quantities[]" class="form-input" min="1" value="1" oninput="updateTotal()">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeArticleRow(this)"
                                style="padding:.4rem .5rem;"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addArticleRow()" style="margin-top:.5rem;">
                    <i class="fas fa-plus"></i> Ajouter un article
                </button>

                <!-- Notes -->
                <div class="form-group form-full" style="margin-top:1.2rem;">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-textarea" rows="3" placeholder="Informations supplémentaires..."></textarea>
                </div>

                <!-- Total -->
                <div class="total-row" style="margin-top:1rem;">
                    <span>TOTAL ESTIMÉ</span>
                    <span class="total-amount" id="orderTotal">$0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createOrderModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Créer la commande
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Modal helpers ── */
function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

/* Fermer en cliquant sur le fond */
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

/* ── Articles dans le modal ── */
const ARTICLES_JSON = <?php echo json_encode(array_column($articles, null, 'id')); ?>;

function addArticleRow() {
    const container  = document.getElementById('articlesContainer');
    const firstRow   = container.querySelector('.article-row-form');
    const clone      = firstRow.cloneNode(true);
    clone.querySelector('select').value = '';
    clone.querySelector('input[type="number"]').value = 1;
    clone.querySelector('select').addEventListener('change', updateTotal);
    clone.querySelector('input[type="number"]').addEventListener('input', updateTotal);
    container.appendChild(clone);
}

function removeArticleRow(btn) {
    const container = document.getElementById('articlesContainer');
    if (container.querySelectorAll('.article-row-form').length <= 1) return;
    btn.closest('.article-row-form').remove();
    updateTotal();
}

function updateTotal() {
    let total = parseFloat(document.getElementById('delivery_fee')?.value) || 0;
    document.querySelectorAll('.article-row-form').forEach(row => {
        const sel = row.querySelector('select');
        const qty = parseInt(row.querySelector('input[type="number"]').value) || 0;
        if (sel.value && ARTICLES_JSON[sel.value]) {
            total += parseFloat(ARTICLES_JSON[sel.value].price) * qty;
        }
    });
    document.getElementById('orderTotal').textContent = '$' + total.toFixed(2);
}

/* ── Changer statut ── */
function changeStatus(orderId, newStatus) {
    const msgs = {
        delivered:   'Marquer cette commande comme livrée ?\nElle sera déplacée dans l\'historique.',
        waiting:     'Mettre cette commande en attente de livraison ?',
        preparation: 'Remettre cette commande en préparation ?',
    };
    (async () => {
        const C = window.Company || window.Pearl;
        if (!await C.confirm({ message: msgs[newStatus] || 'Confirmer ?' })) return;

        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `
        <input type="hidden" name="action"     value="change_status">
        <input type="hidden" name="order_id"   value="${orderId}">
        <input type="hidden" name="new_status" value="${newStatus}">
    `;
        document.body.appendChild(f);
        f.submit();
    })();
}

function viewOrder(id) { window.location.href = 'historique.php?order=' + id; }

/* ── Init ── */
document.addEventListener('DOMContentLoaded', updateTotal);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>