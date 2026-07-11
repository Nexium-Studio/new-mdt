<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '📦 PostOP';
$companyColor   = '#3b82f6';
$companyFaction = 'POSTOP';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Historique des Commandes';
$db        = Database::getInstance()->getConnection();

/* ── Vue détaillée d'une commande ── */
$viewId = intval($_GET['order'] ?? 0);
$order  = null;
$order_items = [];

if ($viewId > 0) {
    $stmt = $db->prepare("
        SELECT o.*, u.username as created_by_username
        FROM postop_orders o
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.id = ? AND o.status = 'delivered'
    ");
    $stmt->execute([$viewId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        redirect(SITE_URL . '/pages/companies/postop/historique.php');
    }

    $stmt = $db->prepare("
        SELECT oi.*, a.name as article_name, a.description as article_description
        FROM postop_order_items oi
        JOIN postop_articles a ON oi.article_id = a.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$viewId]);
    $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ── Liste historique ── */
$orders = $db->query("
    SELECT o.*, COUNT(oi.id) as items_count, COALESCE(SUM(oi.quantity),0) as total_items
    FROM postop_orders o
    LEFT JOIN postop_order_items oi ON o.id = oi.order_id
    WHERE o.status = 'delivered'
    GROUP BY o.id
    ORDER BY o.delivered_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Stats ── */
$totalRevenue  = array_sum(array_column($orders, 'total_amount'));
$totalOrders   = count($orders);
$monthlyRevenue = $db->query("
    SELECT COALESCE(SUM(total_amount),0)
    FROM postop_orders
    WHERE status = 'delivered'
      AND MONTH(delivered_at) = MONTH(CURRENT_DATE)
      AND YEAR(delivered_at)  = YEAR(CURRENT_DATE)
")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?php echo ASSETS_PATH; ?>/css/postop.css">
<style>:root{ --co: <?php echo $companyColor; ?>; --co-dim: rgba(59,130,246,.15); --co-glow: rgba(59,130,246,.4); }</style>

<div class="postop-wrap">

<?php if ($viewId > 0 && $order): ?>
    <!-- ══════════ VUE DÉTAILLÉE ══════════ -->
    <div style="margin-bottom:1.5rem;">
        <a href="historique.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Retour à l'historique
        </a>
    </div>

    <div class="detail-view">

        <!-- En-tête -->
        <div class="detail-header">
            <div>
                <div class="detail-title"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                <div class="detail-id">
                    Commande #<?php echo $order['id']; ?> &nbsp;·&nbsp;
                    Créée le <?php echo date('d/m/Y à H:i', strtotime($order['created_at'])); ?>
                </div>
            </div>
            <span class="badge badge-delivered">
                <i class="fas fa-check-circle"></i> Livrée
            </span>
        </div>

        <!-- Infos principales -->
        <div class="detail-grid">
            <div class="detail-card">
                <div class="detail-card-title">Lieu de livraison</div>
                <div class="detail-card-value"><?php echo htmlspecialchars($order['delivery_location']); ?></div>
            </div>
            <div class="detail-card">
                <div class="detail-card-title">Employé</div>
                <div class="detail-card-value"><?php echo htmlspecialchars($order['employee_name']); ?></div>
            </div>
            <div class="detail-card">
                <div class="detail-card-title">Frais de livraison</div>
                <div class="detail-card-value" style="color:var(--co);">$<?php echo number_format($order['delivery_fee'],2); ?></div>
            </div>
            <div class="detail-card">
                <div class="detail-card-title">Créé par</div>
                <div class="detail-card-value"><?php echo htmlspecialchars($order['created_by_username'] ?? 'Inconnu'); ?></div>
            </div>
        </div>

        <!-- Chronologie -->
        <div class="section-title"><i class="fas fa-clock"></i> Chronologie</div>
        <div class="timeline">
            <div class="timeline-item">
                <span class="timeline-label">Commande reçue</span>
                <span class="timeline-value"><?php echo date('d/m/Y à H:i', strtotime($order['order_time'])); ?></span>
            </div>
            <?php if ($order['delivery_time']): ?>
            <div class="timeline-item">
                <span class="timeline-label">Livraison prévue</span>
                <span class="timeline-value"><?php echo date('d/m/Y à H:i', strtotime($order['delivery_time'])); ?></span>
            </div>
            <?php endif; ?>
            <div class="timeline-item" style="border-left-color:var(--green);">
                <span class="timeline-label">Livrée le</span>
                <span class="timeline-value" style="color:var(--green);font-weight:700;">
                    <?php echo date('d/m/Y à H:i', strtotime($order['delivered_at'])); ?>
                </span>
            </div>
        </div>

        <!-- Articles -->
        <div class="section-title" style="margin-top:1.5rem;"><i class="fas fa-box"></i> Articles livrés</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Article</th>
                    <th style="text-align:center;">Quantité</th>
                    <th style="text-align:right;">Prix unitaire</th>
                    <th style="text-align:right;">Sous-total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_items as $item): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($item['article_name']); ?></div>
                        <?php if ($item['article_description']): ?>
                        <div style="font-size:.8rem;color:var(--tx2);margin-top:.2rem;">
                            <?php echo htmlspecialchars($item['article_description']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;">×<?php echo $item['quantity']; ?></td>
                    <td style="text-align:right;">$<?php echo number_format($item['unit_price'],2); ?></td>
                    <td style="text-align:right;font-weight:700;">$<?php echo number_format($item['subtotal'],2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($order['delivery_fee'] > 0): ?>
                <tr>
                    <td colspan="3" style="font-weight:600;color:var(--co);">Frais de livraison</td>
                    <td style="text-align:right;font-weight:700;color:var(--co);">
                        $<?php echo number_format($order['delivery_fee'],2); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Total -->
        <div class="total-section">
            <div class="total-row-big">
                <span>MONTANT TOTAL</span>
                <span class="total-amount-big">$<?php echo number_format($order['total_amount'],2); ?></span>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($order['notes']): ?>
        <div class="notes-section" style="margin-top:1.5rem;">
            <div style="font-size:.8rem;font-weight:700;color:var(--co);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.6rem;">
                <i class="fas fa-sticky-note"></i> Notes
            </div>
            <div style="color:var(--tx2);line-height:1.6;"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
        </div>
        <?php endif; ?>

    </div>

<?php else: ?>
    <!-- ══════════ LISTE HISTORIQUE ══════════ -->

    <!-- Page header -->
    <div class="page-header">
        <div>
            <div class="page-title"><i class="fas fa-history"></i> Historique</div>
            <div class="page-subtitle">Toutes les commandes livrées · PostOP</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalOrders; ?></div>
                <div class="stat-label">Commandes Livrées</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($totalRevenue,0); ?></div>
                <div class="stat-label">Revenu Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value">$<?php echo number_format($monthlyRevenue,0); ?></div>
                <div class="stat-label">Revenu du Mois</div>
            </div>
        </div>
    </div>

    <!-- Liste -->
    <?php if (empty($orders)): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-history"></i>
            <p>Aucune commande livrée pour le moment.</p>
        </div>
    </div>
    <?php else: ?>
        <?php foreach ($orders as $order): ?>
        <div class="history-card" onclick="window.location.href='historique.php?order=<?php echo $order['id']; ?>'">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
                <div>
                    <div style="font-size:.78rem;color:var(--tx2);font-family:'JetBrains Mono',monospace;">
                        Commande #<?php echo $order['id']; ?> · <?php echo date('d/m/Y', strtotime($order['delivered_at'])); ?>
                    </div>
                    <div style="font-size:1.3rem;font-weight:700;color:var(--tx);margin-top:.2rem;">
                        <?php echo htmlspecialchars($order['customer_name']); ?>
                    </div>
                </div>
                <span class="badge badge-delivered">
                    <i class="fas fa-check-circle"></i> Livrée
                </span>
            </div>
            <div class="history-meta">
                <div class="hmeta-item">
                    <div class="hmeta-label">Livraison</div>
                    <div class="hmeta-value"><?php echo date('d/m/Y H:i', strtotime($order['delivered_at'])); ?></div>
                </div>
                <div class="hmeta-item">
                    <div class="hmeta-label">Lieu</div>
                    <div class="hmeta-value"><?php echo htmlspecialchars($order['delivery_location']); ?></div>
                </div>
                <div class="hmeta-item">
                    <div class="hmeta-label">Articles</div>
                    <div class="hmeta-value">
                        <?php echo $order['total_items']; ?> article<?php echo $order['total_items'] > 1 ? 's' : ''; ?>
                    </div>
                </div>
                <div class="hmeta-item">
                    <div class="hmeta-label">Montant</div>
                    <div class="history-amount">$<?php echo number_format($order['total_amount'],2); ?></div>
                </div>
            </div>
            <div style="text-align:right;margin-top:.6rem;font-size:.8rem;color:var(--co);">
                <i class="fas fa-arrow-right"></i> Voir les détails
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; /* fin vue détail / liste */ ?>

</div><!-- /postop-wrap -->

<?php include __DIR__ . '/includes/footer.php'; ?>