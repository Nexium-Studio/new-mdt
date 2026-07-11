<?php
/**
 * Shared stock page template.
 * Required variables (set in each company's stock.php wrapper):
 *   $companyName    — display name (e.g. '🔫 Ammunation')
 *   $companyColor   — hex color   (e.g. '#dc2626')
 *   $companyFaction — faction key (e.g. 'AMMUNATION')
 *   $__dir          — __DIR__ of the calling stock.php (for header/footer includes)
 */

if (empty($companyFaction) || empty($__dir)) {
    die('stock_page.php: variables manquantes.');
}

// Access control — staff & admin bypass faction check
if (
    ($_SESSION['user_faction'] ?? '') !== $companyFaction
    && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])
    && !is_demo_mode()
) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion du Stock';
$db  = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// RBAC
require_once ROOT_PATH . '/includes/faction_acl.php';
require_once ROOT_PATH . '/includes/stock_central.php';
$uid       = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'stock.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'stock.delete');

// Categories (centralized)
$CATEGORIES = stock_get_categories($db, $companyFaction);
if (empty($CATEGORIES)) $CATEGORIES = stock_fallback_category();

// Settings
$settings = stock_get_settings($db, $companyFaction);
$maxItems = (int)$settings['max_items'];

// ── AJOUTER ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add' && $canManage) {
    $totalRefs = (int)$db->prepare("SELECT COUNT(*) FROM company_stock WHERE faction=?")->execute([$companyFaction]) ? 0 : 0;
    $stmt = $db->prepare("SELECT COUNT(*) FROM company_stock WHERE faction=?");
    $stmt->execute([$companyFaction]);
    $totalRefs = (int)$stmt->fetchColumn();

    if ($maxItems > 0 && $totalRefs >= $maxItems) {
        $msg = "Limite de {$maxItems} articles atteinte pour ce catalogue."; $msgType = 'error';
    } else {
        try {
            $db->prepare("
                INSERT INTO company_stock (faction, item_name, item_category, item_price, stock_quantity)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    item_price=VALUES(item_price),
                    stock_quantity=stock_quantity+VALUES(stock_quantity)
            ")->execute([
                $companyFaction,
                trim($_POST['item_name']),
                trim($_POST['item_category']),
                floatval($_POST['item_price']),
                intval($_POST['stock_quantity']),
            ]);
            $msg = 'Article ajouté au stock.'; $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
        }
    }
}

// ── MODIFIER ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update' && ($canManage || $canEdit)) {
    try {
        $db->prepare("
            UPDATE company_stock
            SET item_name=?, item_category=?, item_price=?, stock_quantity=?
            WHERE id=? AND faction=?
        ")->execute([
            trim($_POST['item_name']),
            trim($_POST['item_category']),
            floatval($_POST['item_price']),
            intval($_POST['stock_quantity']),
            intval($_POST['item_id']),
            $companyFaction,
        ]);
        $msg = 'Article mis à jour.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
    }
}

// ── RÉAPPRO ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restock' && ($canManage || $canEdit)) {
    try {
        $db->prepare("UPDATE company_stock SET stock_quantity=stock_quantity+? WHERE id=? AND faction=?")
           ->execute([intval($_POST['qty']), intval($_POST['item_id']), $companyFaction]);
        $msg = 'Stock réapprovisionné.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
    }
}

// ── SUPPRIMER ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && ($canManage || $canDelete)) {
    try {
        $db->prepare("DELETE FROM company_stock WHERE id=? AND faction=?")
           ->execute([intval($_POST['item_id']), $companyFaction]);
        $msg = 'Article supprimé.'; $msgType = 'success';
    } catch (Exception $e) {
        $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$filter = $_GET['cat'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$query  = "SELECT * FROM company_stock WHERE faction=?";
$params = [$companyFaction];
if ($filter !== 'all') { $query .= " AND item_category=?"; $params[] = $filter; }
if ($search)           { $query .= " AND item_name LIKE ?"; $params[] = "%{$search}%"; }
$query .= " ORDER BY item_category, item_name";
$stmt = $db->prepare($query); $stmt->execute($params);
$items = $stmt->fetchAll();

// Stats globales
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_refs,
        COALESCE(SUM(stock_quantity),0) as total_units,
        COALESCE(SUM(item_price*stock_quantity),0) as total_value,
        SUM(CASE WHEN stock_quantity=0 THEN 1 ELSE 0 END) as rupture,
        SUM(CASE WHEN stock_quantity>0 AND stock_quantity<10 THEN 1 ELSE 0 END) as low_stock
    FROM company_stock WHERE faction=?
");
$stmt->execute([$companyFaction]); $stats = $stmt->fetch();

// Par catégorie
$stmt = $db->prepare("SELECT item_category, COUNT(*) as refs, SUM(stock_quantity) as units FROM company_stock WHERE faction=? GROUP BY item_category");
$stmt->execute([$companyFaction]); $byCat = $stmt->fetchAll(PDO::FETCH_ASSOC);
$catCounts = [];
foreach ($byCat as $r) $catCounts[$r['item_category']] = ['refs' => $r['refs'], 'units' => $r['units']];

include $__dir . '/includes/header.php';
?>
<style>
:root{--cc:<?php echo $companyColor; ?>}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1.1rem;margin-bottom:1.75rem}
.kpi{background:rgba(13,21,38,.8);border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:1.25rem 1.4rem;position:relative;overflow:hidden}
.kpi::after{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;background:radial-gradient(circle,color-mix(in srgb,var(--cc) 20%,transparent) 0%,transparent 70%);pointer-events:none}
.kpi-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:.5rem}
.kpi-val{font-size:1.75rem;font-weight:900;color:#f1f5f9;line-height:1}
.kpi-val.green{color:#4ade80}.kpi-val.red{color:#f87171}.kpi-val.yellow{color:#fcd34d}.kpi-val.blue{color:#93c5fd}
.kpi-sub{font-size:.75rem;color:#475569;margin-top:.35rem}
.kpi-limit{font-size:.7rem;color:#64748b;margin-top:.3rem}
.kpi-limit span{color:var(--cc);font-weight:700}

.toolbar{display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap}
.toolbar-search{flex:1;min-width:220px;position:relative}
.toolbar-search input{width:100%;padding:.65rem 1rem .65rem 2.5rem;background:#0a0f1e;border:1px solid rgba(255,255,255,.1);border-radius:9px;color:#e2e8f0;font-size:.875rem;font-family:inherit}
.toolbar-search input:focus{outline:none;border-color:var(--cc);box-shadow:0 0 0 3px color-mix(in srgb,var(--cc) 15%,transparent)}
.toolbar-search .si{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:#475569;font-size:.8rem}

.cat-pills{display:flex;gap:.5rem;flex-wrap:wrap}
.cat-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:20px;font-size:.78rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.08);background:transparent;color:#64748b;transition:all .2s;text-decoration:none}
.cat-pill:hover{border-color:rgba(255,255,255,.2);color:#f1f5f9}
.cat-pill.active{background:var(--cc);border-color:var(--cc);color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--cc) 35%,transparent)}

.stock-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1.1rem}
.sc{background:rgba(13,21,38,.8);border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:1.25rem;transition:all .25s;position:relative}
.sc:hover{border-color:rgba(255,255,255,.15);transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.3)}
.sc.rupture{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.04)}
.sc.low{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.04)}
.sc-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.9rem}
.sc-cat{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .65rem;border-radius:5px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.sc-name{font-size:1rem;font-weight:800;color:#f1f5f9;margin-bottom:.25rem;line-height:1.3}
.sc-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem;margin:.9rem 0}
.sc-stat-lbl{font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.2rem}
.sc-stat-val{font-size:1.3rem;font-weight:900}
.sc-actions{display:flex;gap:.45rem;margin-top:.9rem;padding-top:.9rem;border-top:1px solid rgba(255,255,255,.05)}
.no-cats-notice{background:rgba(234,179,8,.06);border:1px solid rgba(234,179,8,.2);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.25rem;font-size:.875rem;color:#fde047;display:flex;align-items:center;gap:.75rem}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if (count($CATEGORIES) === 1 && array_key_exists('Autre', $CATEGORIES) && $CATEGORIES['Autre']['id'] === 0): ?>
<div class="no-cats-notice">
    <i class="fas fa-exclamation-triangle"></i>
    Aucune catégorie configurée pour ce catalogue.
    <?php if (hasRole(['*','Administrateur','Développeur','Staff'])): ?>
        <a href="<?php echo SITE_URL; ?>/pages/staff/content/custom_stock.php?company=<?php echo urlencode($companyFaction); ?>" style="color:#fde047;font-weight:700;margin-left:.25rem">→ Configurer</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-lbl">Références</div>
        <div class="kpi-val blue"><?php echo number_format($stats['total_refs']); ?></div>
        <?php if ($maxItems > 0): ?>
        <div class="kpi-limit">/ <span><?php echo $maxItems; ?></span> max autorisés</div>
        <?php else: ?>
        <div class="kpi-sub">articles catalogués</div>
        <?php endif; ?>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Unités en stock</div>
        <div class="kpi-val"><?php echo number_format($stats['total_units']); ?></div>
        <div class="kpi-sub">toutes catégories</div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Valeur du stock</div>
        <div class="kpi-val green">$<?php echo number_format($stats['total_value'], 0); ?></div>
        <div class="kpi-sub">valeur catalogue</div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Stock faible</div>
        <div class="kpi-val yellow"><?php echo $stats['low_stock']; ?></div>
        <div class="kpi-sub">articles &lt; 10 unités</div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Ruptures</div>
        <div class="kpi-val red"><?php echo $stats['rupture']; ?></div>
        <div class="kpi-sub">articles à 0 unité</div>
    </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-search">
        <i class="fas fa-search si"></i>
        <form method="GET" id="searchForm">
            <?php if ($filter !== 'all'): ?>
            <input type="hidden" name="cat" value="<?php echo htmlspecialchars($filter); ?>">
            <?php endif; ?>
            <input type="text" name="q" placeholder="Rechercher un article…" value="<?php echo htmlspecialchars($search); ?>" oninput="this.form.submit()">
        </form>
    </div>

    <div class="cat-pills">
        <a href="?cat=all<?php echo $search ? '&q='.urlencode($search) : ''; ?>" class="cat-pill <?php echo $filter==='all'?'active':''; ?>">
            <i class="fas fa-list"></i> Tout (<?php echo $stats['total_refs']; ?>)
        </a>
        <?php foreach ($CATEGORIES as $cat => $ci): ?>
        <?php $cnt = $catCounts[$cat]['refs'] ?? 0; if (!$cnt && $filter !== $cat) continue; ?>
        <a href="?cat=<?php echo urlencode($cat); ?><?php echo $search ? '&q='.urlencode($search) : ''; ?>"
           class="cat-pill <?php echo $filter===$cat?'active':''; ?>">
            <i class="<?php echo $ci['icon']; ?>"></i> <?php echo htmlspecialchars($cat); ?> (<?php echo $cnt; ?>)
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($canManage): ?>
    <button onclick="Company.openModal('addModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvel Article
    </button>
    <?php endif; ?>
</div>

<!-- Stock Grid -->
<?php if (empty($items)): ?>
<div class="card" style="text-align:center;padding:4rem 2rem;color:#475569">
    <i class="fas fa-box-open" style="font-size:3.5rem;opacity:.25;display:block;margin-bottom:1rem"></i>
    <p>Aucun article trouvé</p>
</div>
<?php else: ?>
<div class="stock-grid">
<?php foreach ($items as $it):
    $cat       = $it['item_category'] ?: 'Autre';
    $ci        = $CATEGORIES[$cat] ?? stock_fallback_category()['Autre'];
    $isLow     = $it['stock_quantity'] > 0 && $it['stock_quantity'] < 10;
    $isRupture = (int)$it['stock_quantity'] === 0;
    $cls       = $isRupture ? ' rupture' : ($isLow ? ' low' : '');
?>
<div class="sc<?php echo $cls; ?>">
    <div class="sc-head">
        <div>
            <span class="sc-cat" style="background:<?php echo $ci['color']; ?>22;color:<?php echo $ci['color']; ?>;border:1px solid <?php echo $ci['color']; ?>44">
                <i class="<?php echo $ci['icon']; ?>"></i><?php echo htmlspecialchars($cat); ?>
            </span>
            <div class="sc-name" style="margin-top:.5rem"><?php echo htmlspecialchars($it['item_name']); ?></div>
        </div>
        <?php if ($isRupture): ?>
        <span class="badge badge-red">Rupture</span>
        <?php elseif ($isLow): ?>
        <span class="badge badge-yellow">Faible</span>
        <?php endif; ?>
    </div>

    <div class="sc-stats">
        <div>
            <div class="sc-stat-lbl">Prix unitaire</div>
            <div class="sc-stat-val text-green">$<?php echo number_format($it['item_price'], 2); ?></div>
        </div>
        <div>
            <div class="sc-stat-lbl">Quantité</div>
            <div class="sc-stat-val <?php echo $isRupture?'text-red':($isLow?'text-yellow':'text-blue'); ?>">
                <?php echo number_format($it['stock_quantity']); ?>
            </div>
        </div>
        <div>
            <div class="sc-stat-lbl">Valeur totale</div>
            <div class="sc-stat-val" style="font-size:1rem;color:#94a3b8">
                $<?php echo number_format($it['item_price'] * $it['stock_quantity'], 0); ?>
            </div>
        </div>
        <div>
            <div class="sc-stat-lbl">ID</div>
            <div class="sc-stat-val mono" style="font-size:.9rem;color:#475569">#<?php echo $it['id']; ?></div>
        </div>
    </div>

    <?php if ($canManage || $canEdit || $canDelete): ?>
    <div class="sc-actions">
        <?php if ($canManage || $canEdit): ?>
        <button onclick='openEdit(<?php echo json_encode($it); ?>)' class="btn btn-secondary btn-sm" style="flex:1">
            <i class="fas fa-pen"></i> Modifier
        </button>
        <button onclick='openRestock(<?php echo $it['id']; ?>, "<?php echo addslashes($it['item_name']); ?>")' class="btn btn-ghost btn-sm">
            <i class="fas fa-plus"></i> Réappro
        </button>
        <?php endif; ?>
        <?php if ($canManage || $canDelete): ?>
        <form method="POST" style="margin:0" data-confirm="<?php echo htmlspecialchars('Supprimer «'.$it['item_name'].'» ?', ENT_QUOTES); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canManage || $canEdit): ?>
<!-- MODAL AJOUTER -->
<div id="addModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Nouvel Article</h2>
            <button class="modal-close" onclick="Company.closeModal('addModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de l'Article *</label>
                        <input type="text" name="item_name" class="form-input" placeholder="Ex: Burger Classic, Glock 17…" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="item_category" class="form-select" required>
                            <?php foreach (array_keys($CATEGORIES) as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix Unitaire ($) *</label>
                        <input type="number" name="item_price" class="form-input" step="0.01" min="0" placeholder="0.00" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Quantité initiale *</label>
                        <input type="number" name="stock_quantity" class="form-input" min="0" placeholder="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="Company.closeModal('addModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-pen"></i> Modifier l'Article</h2>
            <button class="modal-close" onclick="Company.closeModal('editModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="item_id" id="edit_id">
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de l'Article *</label>
                        <input type="text" name="item_name" id="edit_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="item_category" id="edit_cat" class="form-select" required>
                            <?php foreach (array_keys($CATEGORIES) as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prix Unitaire ($) *</label>
                        <input type="number" name="item_price" id="edit_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Quantité en stock *</label>
                        <input type="number" name="stock_quantity" id="edit_qty" class="form-input" min="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="Company.closeModal('editModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RÉAPPRO -->
<div id="restockModal" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2><i class="fas fa-boxes"></i> Réapprovisionnement</h2>
            <button class="modal-close" onclick="Company.closeModal('restockModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="item_id" id="rs_id">
                <p style="margin-bottom:1.25rem;color:#94a3b8">Article : <strong id="rs_name" style="color:#f1f5f9"></strong></p>
                <div class="form-group">
                    <label class="form-label">Quantité à ajouter *</label>
                    <input type="number" name="qty" class="form-input" min="1" placeholder="0" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="Company.closeModal('restockModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openEdit(it) {
    document.getElementById('edit_id').value    = it.id;
    document.getElementById('edit_name').value  = it.item_name;
    document.getElementById('edit_cat').value   = it.item_category || 'Autre';
    document.getElementById('edit_price').value = it.item_price;
    document.getElementById('edit_qty').value   = it.stock_quantity;
    Company.openModal('editModal');
}
function openRestock(id, name) {
    document.getElementById('rs_id').value           = id;
    document.getElementById('rs_name').textContent   = name;
    Company.openModal('restockModal');
}
</script>

<?php include $__dir . '/includes/footer.php'; ?>
