<?php
// pages/staff/content/custom_stock.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) redirect(SITE_URL . '/pages/staff/dashboard.php');

require_once ROOT_PATH . '/includes/stock_central.php';

$db          = Database::getInstance()->getConnection();
$companyKey  = trim($_GET['company'] ?? '');
$msg         = ''; $msgType = '';

stock_ensure_tables($db);

// ═══════════════════════════════════════════════════════════════════════════════
// ACTIONS (POST)
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyKey) {
    $action = $_POST['action'] ?? '';

    // ── Paramètres généraux ────────────────────────────────────────────────────
    if ($action === 'save_settings') {
        $maxItems = max(0, intval($_POST['max_items'] ?? 0));
        $db->prepare("INSERT INTO stock_settings (company_key, max_items) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE max_items=VALUES(max_items), updated_at=NOW()")
           ->execute([$companyKey, $maxItems]);
        $msg = 'Paramètres enregistrés.'; $msgType = 'success';
    }

    // ── Ajouter catégorie ──────────────────────────────────────────────────────
    if ($action === 'add_category') {
        $name  = trim($_POST['cat_name'] ?? '');
        $icon  = trim($_POST['cat_icon'] ?? 'fas fa-box');
        $color = trim($_POST['cat_color'] ?? '#64748b');
        $order = intval($_POST['cat_order'] ?? 0);
        if ($name) {
            try {
                $db->prepare("INSERT INTO stock_categories (company_key, name, icon, color, display_order) VALUES (?,?,?,?,?)")
                   ->execute([$companyKey, $name, $icon, $color, $order]);
                $msg = "Catégorie «{$name}» créée."; $msgType = 'success';
            } catch (Exception $e) {
                $msg = "Erreur : catégorie déjà existante ou données invalides."; $msgType = 'error';
            }
        } else {
            $msg = 'Le nom de la catégorie est requis.'; $msgType = 'error';
        }
    }

    // ── Modifier catégorie ─────────────────────────────────────────────────────
    if ($action === 'edit_category') {
        $id       = intval($_POST['cat_id'] ?? 0);
        $oldName  = trim($_POST['old_name'] ?? '');
        $newName  = trim($_POST['cat_name'] ?? '');
        $icon     = trim($_POST['cat_icon'] ?? 'fas fa-box');
        $color    = trim($_POST['cat_color'] ?? '#64748b');
        $order    = intval($_POST['cat_order'] ?? 0);
        $isActive = isset($_POST['cat_active']) ? 1 : 0;
        if ($id && $newName) {
            $db->prepare("UPDATE stock_categories SET name=?, icon=?, color=?, display_order=?, is_active=? WHERE id=? AND company_key=?")
               ->execute([$newName, $icon, $color, $order, $isActive, $id, $companyKey]);
            // Sync existing stock items if name changed
            if ($oldName && $oldName !== $newName) {
                $db->prepare("UPDATE company_stock SET item_category=? WHERE faction=? AND item_category=?")
                   ->execute([$newName, $companyKey, $oldName]);
            }
            $msg = "Catégorie mise à jour."; $msgType = 'success';
        }
    }

    // ── Supprimer catégorie ────────────────────────────────────────────────────
    if ($action === 'delete_category') {
        $id   = intval($_POST['cat_id'] ?? 0);
        $name = trim($_POST['cat_name'] ?? '');
        if ($id) {
            // Move orphaned items to 'Autre'
            $db->prepare("UPDATE company_stock SET item_category='Autre' WHERE faction=? AND item_category=?")
               ->execute([$companyKey, $name]);
            $db->prepare("DELETE FROM stock_categories WHERE id=? AND company_key=?")
               ->execute([$id, $companyKey]);
            $msg = "Catégorie supprimée. Les articles ont été déplacés vers «Autre»."; $msgType = 'success';
        }
    }

    // ── Ajouter article (depuis staff) ─────────────────────────────────────────
    if ($action === 'add_item') {
        $name  = trim($_POST['item_name'] ?? '');
        $cat   = trim($_POST['item_category'] ?? '');
        $price = floatval($_POST['item_price'] ?? 0);
        $qty   = intval($_POST['item_quantity'] ?? 0);
        if ($name && $cat) {
            try {
                $db->prepare("INSERT INTO company_stock (faction, item_name, item_category, item_price, stock_quantity)
                              VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE item_price=VALUES(item_price), stock_quantity=stock_quantity+VALUES(stock_quantity)")
                   ->execute([$companyKey, $name, $cat, $price, $qty]);
                $msg = "Article «{$name}» ajouté."; $msgType = 'success';
            } catch (Exception $e) {
                $msg = 'Erreur : ' . $e->getMessage(); $msgType = 'error';
            }
        }
    }

    // ── Modifier article (depuis staff) ────────────────────────────────────────
    if ($action === 'edit_item') {
        $id    = intval($_POST['item_id'] ?? 0);
        $name  = trim($_POST['item_name'] ?? '');
        $cat   = trim($_POST['item_category'] ?? '');
        $price = floatval($_POST['item_price'] ?? 0);
        $qty   = intval($_POST['item_quantity'] ?? 0);
        if ($id && $name) {
            $db->prepare("UPDATE company_stock SET item_name=?, item_category=?, item_price=?, stock_quantity=? WHERE id=? AND faction=?")
               ->execute([$name, $cat, $price, $qty, $id, $companyKey]);
            $msg = 'Article mis à jour.'; $msgType = 'success';
        }
    }

    // ── Supprimer article (depuis staff) ───────────────────────────────────────
    if ($action === 'delete_item') {
        $id = intval($_POST['item_id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM company_stock WHERE id=? AND faction=?")->execute([$id, $companyKey]);
            $msg = 'Article supprimé.'; $msgType = 'success';
        }
    }

    // ── Réordonner catégories (AJAX) ───────────────────────────────────────────
    if ($action === 'reorder_categories') {
        $orders = json_decode($_POST['orders'] ?? '[]', true);
        $stmt   = $db->prepare("UPDATE stock_categories SET display_order=? WHERE id=? AND company_key=?");
        foreach ($orders as $o) {
            $stmt->execute([(int)$o['order'], (int)$o['id'], $companyKey]);
        }
        echo json_encode(['ok' => true]); exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// DONNÉES
// ═══════════════════════════════════════════════════════════════════════════════
$entities = stock_scan_entities(ROOT_PATH);

// Config logos/noms depuis admin_panel_config
$panelConfigs = [];
try {
    $rows = $db->query("SELECT panel_key, custom_name, custom_color, custom_logo_url FROM admin_panel_config")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $panelConfigs[$r['panel_key']] = $r;
} catch (Exception $e) {}

// Enrichir chaque entité avec stats
foreach ($entities as &$e) {
    $k = $e['key'];
    // Custom name/logo override
    if (isset($panelConfigs[$k])) {
        $pc = $panelConfigs[$k];
        if (!empty($pc['custom_name']))     $e['name']     = $pc['custom_name'];
        if (!empty($pc['custom_color']))    $e['color']    = $pc['custom_color'];
        if (!empty($pc['custom_logo_url'])) $e['logo_url'] = $pc['custom_logo_url'];
    }
    // Stats
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM company_stock WHERE faction=?");
        $stmt->execute([$k]);
        $e['item_count'] = (int)$stmt->fetchColumn();
    } catch (Exception $ex) { $e['item_count'] = 0; }

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM stock_categories WHERE company_key=? AND is_active=1");
        $stmt->execute([$k]);
        $e['cat_count'] = (int)$stmt->fetchColumn();
    } catch (Exception $ex) { $e['cat_count'] = 0; }

    $s = stock_get_settings($db, $k);
    $e['max_items'] = $s['max_items'];
}
unset($e);

// ── Si mode config d'une entreprise ───────────────────────────────────────────
$configEntity = null;
if ($companyKey) {
    foreach ($entities as $e) {
        if ($e['key'] === $companyKey) { $configEntity = $e; break; }
    }
    // Si la clé n'est pas trouvée dans le scan, on garde une config minimale
    if (!$configEntity) {
        $configEntity = ['key' => $companyKey, 'name' => $companyKey, 'type' => 'unknown', 'slug' => ''];
    }
}

// Données de config
$configCategories = $companyKey ? stock_get_all_categories($db, $companyKey) : [];
$configSettings   = $companyKey ? stock_get_settings($db, $companyKey) : [];
$configItems      = [];
if ($companyKey) {
    try {
        $stmt = $db->prepare("SELECT * FROM company_stock WHERE faction=? ORDER BY item_category, item_name");
        $stmt->execute([$companyKey]);
        $configItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
$configCats = $companyKey ? stock_get_categories($db, $companyKey) : [];

// ── Filtres liste ─────────────────────────────────────────────────────────────
$filterType   = $_GET['type'] ?? 'all';
$searchQuery  = strtolower(trim($_GET['q'] ?? ''));
$filteredEntities = array_filter($entities, function($e) use ($filterType, $searchQuery) {
    if ($filterType !== 'all' && $e['type'] !== $filterType) return false;
    if ($searchQuery && strpos(strtolower($e['name']), $searchQuery) === false && strpos(strtolower($e['key']), $searchQuery) === false) return false;
    return true;
});

$pageTitle  = $companyKey ? 'Catalogues — ' . ($configEntity['name'] ?? $companyKey) : 'Catalogues & Stock';
$breadcrumb = $companyKey
    ? [['label' => 'Contenu'], ['label' => 'Catalogues', 'url' => SITE_URL . '/pages/staff/content/custom_stock.php'], ['label' => $configEntity['name'] ?? $companyKey]]
    : [['label' => 'Contenu'], ['label' => 'Catalogues & Stock']];

include __DIR__ . '/../includes/header.php';
?>
<style>
/* ── ICON PREVIEW ───── */
.icon-preview{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:1rem}

/* ── COMPANY CARDS ───── */
.companies-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1.25rem}
.company-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:0;overflow:hidden;transition:border-color .2s,transform .2s}
.company-card:hover{border-color:var(--border-hover);transform:translateY(-2px)}
.cc-header{display:flex;align-items:center;gap:14px;padding:18px 20px;border-bottom:1px solid var(--border)}
.cc-logo{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:1.3rem;flex-shrink:0;overflow:hidden}
.cc-logo img{width:100%;height:100%;object-fit:cover}
.cc-info{flex:1;min-width:0}
.cc-name{font-weight:800;font-family:'Syne',sans-serif;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cc-type{font-size:.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.07em;margin-top:2px}
.cc-body{padding:14px 20px}
.cc-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.cc-stat{text-align:center;background:rgba(255,255,255,.03);border-radius:8px;padding:8px 4px}
.cc-stat-val{font-weight:800;font-size:1.15rem;line-height:1}
.cc-stat-lbl{font-size:.65rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.cc-stat-lbl.warn{color:var(--yellow)}

/* ── CONFIG VIEW ───── */
.config-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--text-secondary);text-decoration:none;font-size:.875rem;margin-bottom:20px;transition:color .2s}
.config-back:hover{color:var(--text-primary)}

/* ── CATEGORIES TABLE ───── */
.cat-table tbody td{vertical-align:middle}
.cat-icon-cell{display:flex;align-items:center;gap:10px}
.drag-handle{color:var(--text-muted);cursor:grab;font-size:14px;opacity:.5;transition:opacity .2s}
.drag-handle:hover{opacity:1}
.cat-color-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
.toggle-active{cursor:pointer}

/* ── ITEMS TABLE ───── */
.items-section{margin-top:2rem}
.items-search{display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}
.limit-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:4px}
.limit-fill{height:100%;border-radius:3px;transition:width .4s}

/* ── ICON PICKER ───── */
.icon-option{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:7px;cursor:pointer;border:2px solid transparent;transition:all .18s}
.icon-option:hover,.icon-option.selected{border-color:var(--blue);background:var(--bg-active)}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType === 'success' ? 'success' : 'danger'; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if ($companyKey && $configEntity): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- VUE CONFIGURATION D'UNE ENTREPRISE                                        -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<a href="<?php echo SITE_URL; ?>/pages/staff/content/custom_stock.php" class="config-back">
    <i class="fas fa-arrow-left"></i> Retour aux catalogues
</a>

<div class="page-header">
    <div class="page-header-left">
        <h1>
            <?php
            if (!empty($configEntity['logo_url'])): ?>
            <img src="<?php echo htmlspecialchars($configEntity['logo_url']); ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;vertical-align:middle;margin-right:8px">
            <?php endif; ?>
            <?php echo htmlspecialchars($configEntity['name'] ?? $companyKey); ?>
        </h1>
        <p>Gestion du catalogue — catégories, articles, paramètres</p>
    </div>
    <div class="page-header-right">
        <span class="badge badge-<?php echo ($configEntity['type'] ?? '') === 'company' ? 'blue' : 'purple'; ?>">
            <i class="fas fa-<?php echo ($configEntity['type'] ?? '') === 'company' ? 'building' : 'grid-2'; ?>"></i>
            <?php echo ($configEntity['type'] ?? '') === 'company' ? 'Entreprise' : 'Panel'; ?>
        </span>
        <code class="badge badge-slate"><?php echo htmlspecialchars($companyKey); ?></code>
    </div>
</div>

<!-- TABS -->
<div class="tabs">
    <button class="tab-btn active" onclick="showTab('tab-settings',this)"><i class="fas fa-sliders"></i> Paramètres</button>
    <button class="tab-btn" onclick="showTab('tab-cats',this)"><i class="fas fa-tags"></i> Catégories (<?php echo count($configCategories); ?>)</button>
    <button class="tab-btn" onclick="showTab('tab-items',this)"><i class="fas fa-boxes"></i> Articles (<?php echo count($configItems); ?>)</button>
</div>

<!-- ── TAB PARAMÈTRES ──────────────────────────────────────────────────────── -->
<div id="tab-settings" class="tab-pane active">
    <div class="card" style="max-width:560px">
        <div class="card-header"><div class="card-title"><i class="fas fa-sliders"></i> Paramètres généraux</div></div>
        <div class="card-body">
            <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>">
                <input type="hidden" name="action" value="save_settings">
                <div class="form-group">
                    <label class="form-label">Limite d'articles</label>
                    <input type="number" name="max_items" class="form-control" min="0"
                           value="<?php echo (int)($configSettings['max_items'] ?? 0); ?>"
                           placeholder="0 = illimité">
                    <div class="form-hint">Nombre maximum d'articles autorisés dans ce catalogue. <strong>0</strong> = illimité.</div>
                </div>
                <?php
                $used  = count($configItems);
                $max   = (int)($configSettings['max_items'] ?? 0);
                $pct   = $max > 0 ? min(100, round($used / $max * 100)) : 0;
                $color = $pct > 85 ? 'var(--red)' : ($pct > 60 ? 'var(--yellow)' : 'var(--green)');
                ?>
                <?php if ($max > 0): ?>
                <div style="margin-bottom:1.25rem">
                    <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--text-secondary);margin-bottom:4px">
                        <span>Utilisation actuelle</span>
                        <span style="color:<?php echo $color; ?>;font-weight:700"><?php echo $used; ?> / <?php echo $max; ?></span>
                    </div>
                    <div class="limit-bar">
                        <div class="limit-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
    </div>
</div>

<!-- ── TAB CATÉGORIES ─────────────────────────────────────────────────────── -->
<div id="tab-cats" class="tab-pane">
    <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
        <button class="btn btn-primary" onclick="document.getElementById('addCatModal').classList.add('open')">
            <i class="fas fa-plus"></i> Nouvelle Catégorie
        </button>
    </div>

    <?php if (empty($configCategories)): ?>
    <div class="empty-state">
        <i class="fas fa-tags"></i>
        <p>Aucune catégorie configurée</p>
        <p style="font-size:.8rem;margin-top:.5rem">Créez des catégories pour organiser le stock de cette entreprise.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrapper">
            <table class="cat-table">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Catégorie</th>
                        <th>Icône</th>
                        <th>Couleur</th>
                        <th>Ordre</th>
                        <th>Articles</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="catTableBody">
                <?php foreach ($configCategories as $cat):
                    $usageStmt = $db->prepare("SELECT COUNT(*) FROM company_stock WHERE faction=? AND item_category=?");
                    $usageStmt->execute([$companyKey, $cat['name']]);
                    $usage = (int)$usageStmt->fetchColumn();
                ?>
                <tr data-cat-id="<?php echo $cat['id']; ?>">
                    <td><span class="drag-handle" title="Réordonner"><i class="fas fa-grip-vertical"></i></span></td>
                    <td>
                        <div class="cat-icon-cell">
                            <div class="icon-preview" style="background:<?php echo $cat['color']; ?>22;color:<?php echo $cat['color']; ?>">
                                <i class="<?php echo htmlspecialchars($cat['icon']); ?>"></i>
                            </div>
                            <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                        </div>
                    </td>
                    <td><code style="font-size:.75rem;color:var(--text-secondary)"><?php echo htmlspecialchars($cat['icon']); ?></code></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div class="cat-color-dot" style="background:<?php echo htmlspecialchars($cat['color']); ?>"></div>
                            <code style="font-size:.75rem;color:var(--text-secondary)"><?php echo htmlspecialchars($cat['color']); ?></code>
                        </div>
                    </td>
                    <td><span class="badge badge-slate"><?php echo $cat['display_order']; ?></span></td>
                    <td><span class="badge badge-blue"><?php echo $usage; ?></span></td>
                    <td>
                        <span class="badge badge-<?php echo $cat['is_active'] ? 'green' : 'slate'; ?>">
                            <?php echo $cat['is_active'] ? 'Actif' : 'Inactif'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:.4rem">
                            <button class="btn btn-secondary btn-sm btn-icon-only"
                                    onclick="openEditCat(<?php echo htmlspecialchars(json_encode($cat), ENT_QUOTES); ?>)"
                                    title="Modifier">
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php if ($usage === 0): ?>
                            <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>" style="margin:0"
                                  onsubmit="return confirm('Supprimer la catégorie «<?php echo addslashes($cat['name']); ?>» ?')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                                <input type="hidden" name="cat_name" value="<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>" style="margin:0"
                                  onsubmit="return confirm('Supprimer «<?php echo addslashes($cat['name']); ?>» ? Les <?php echo $usage; ?> articles seront déplacés vers Autre.')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                                <input type="hidden" name="cat_name" value="<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer (<?php echo $usage; ?> articles déplacés)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── TAB ARTICLES ───────────────────────────────────────────────────────── -->
<div id="tab-items" class="tab-pane items-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem">
        <input type="text" id="itemSearch" class="form-control" placeholder="Rechercher un article…" style="max-width:280px" oninput="filterItems()">
        <?php if (!empty($configCats)): ?>
        <button class="btn btn-primary" onclick="document.getElementById('addItemModal').classList.add('open')">
            <i class="fas fa-plus"></i> Nouvel Article
        </button>
        <?php else: ?>
        <span class="badge badge-yellow" style="padding:.5rem .75rem">
            <i class="fas fa-exclamation-triangle"></i> Créez d'abord des catégories
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($configItems)): ?>
    <div class="empty-state">
        <i class="fas fa-boxes"></i>
        <p>Aucun article dans ce catalogue</p>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrapper">
            <table id="itemsTable">
                <thead>
                    <tr>
                        <th>Article</th>
                        <th>Catégorie</th>
                        <th>Prix</th>
                        <th>Quantité</th>
                        <th>Valeur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($configItems as $it):
                    $ci = $configCats[$it['item_category']] ?? ['icon'=>'fas fa-box','color'=>'#64748b'];
                ?>
                <tr class="item-row" data-name="<?php echo strtolower(htmlspecialchars($it['item_name'], ENT_QUOTES)); ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div class="icon-preview" style="background:<?php echo $ci['color']; ?>22;color:<?php echo $ci['color']; ?>">
                                <i class="<?php echo $ci['icon']; ?>"></i>
                            </div>
                            <strong><?php echo htmlspecialchars($it['item_name']); ?></strong>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($it['item_category'] ?: '—'); ?></td>
                    <td class="text-green">$<?php echo number_format($it['item_price'], 2); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $it['stock_quantity'] == 0 ? 'red' : ($it['stock_quantity'] < 10 ? 'yellow' : 'blue'); ?>">
                            <?php echo $it['stock_quantity']; ?>
                        </span>
                    </td>
                    <td style="color:var(--text-secondary)">$<?php echo number_format($it['item_price'] * $it['stock_quantity'], 0); ?></td>
                    <td>
                        <div style="display:flex;gap:.4rem">
                            <button class="btn btn-secondary btn-sm btn-icon-only"
                                    onclick="openEditItem(<?php echo htmlspecialchars(json_encode($it), ENT_QUOTES); ?>)" title="Modifier">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>" style="margin:0"
                                  onsubmit="return confirm('Supprimer «<?php echo addslashes($it['item_name']); ?>» ?')">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── MODAL AJOUTER CATÉGORIE ──────────────────────────────────────────────── -->
<div id="addCatModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouvelle Catégorie</h3>
            <button class="modal-close" onclick="document.getElementById('addCatModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group">
                <label class="form-label">Nom *</label>
                <input type="text" name="cat_name" class="form-control" placeholder="Ex: Nourriture, Armes, Boissons…" required>
            </div>
            <div class="form-group">
                <label class="form-label">Icône Font Awesome</label>
                <input type="text" name="cat_icon" class="form-control" value="fas fa-box" id="addIconInput" oninput="updateIconPreview('addIcon','addIconInput')">
                <div style="margin-top:.6rem;display:flex;gap:.4rem;flex-wrap:wrap" id="iconPickerAdd">
                    <?php
                    $quickIcons = ['fas fa-box','fas fa-utensils','fas fa-gun','fas fa-car','fas fa-shirt','fas fa-gem','fas fa-beer-mug-empty','fas fa-burger','fas fa-pills','fas fa-shield-halved','fas fa-wrench','fas fa-star','fas fa-music','fas fa-dice','fas fa-fire','fas fa-leaf','fas fa-fish','fas fa-cake-candles'];
                    foreach ($quickIcons as $ic): ?>
                    <span class="icon-option" onclick="pickIcon('addIconInput','addIconPreview','<?php echo $ic; ?>')" title="<?php echo $ic; ?>">
                        <i class="<?php echo $ic; ?>"></i>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:.5rem;display:flex;align-items:center;gap:.5rem">
                    <span>Aperçu :</span>
                    <span id="addIcon" class="icon-preview" style="background:#64748b22;color:#64748b"><i class="fas fa-box"></i></span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Couleur</label>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <input type="color" name="cat_color" value="#64748b" class="form-control" style="width:60px;height:38px;padding:2px">
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <?php
                        $quickColors = ['#ef4444','#f97316','#eab308','#22c55e','#06b6d4','#3b82f6','#8b5cf6','#ec4899','#64748b','#dc2626','#7c3aed','#0f766e'];
                        foreach ($quickColors as $qc): ?>
                        <span style="width:24px;height:24px;border-radius:50%;background:<?php echo $qc; ?>;cursor:pointer;border:2px solid transparent;transition:border-color .15s"
                              onclick="document.querySelector('#addCatModal [name=cat_color]').value='<?php echo $qc; ?>'"
                              onmouseover="this.style.borderColor='#fff'" onmouseout="this.style.borderColor='transparent'"></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Ordre d'affichage</label>
                <input type="number" name="cat_order" class="form-control" value="<?php echo count($configCategories) * 10; ?>" min="0" step="10">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addCatModal').classList.remove('open')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL MODIFIER CATÉGORIE ─────────────────────────────────────────────── -->
<div id="editCatModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> Modifier la Catégorie</h3>
            <button class="modal-close" onclick="document.getElementById('editCatModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="cat_id" id="editCatId">
            <input type="hidden" name="old_name" id="editCatOldName">
            <div class="form-group">
                <label class="form-label">Nom *</label>
                <input type="text" name="cat_name" id="editCatName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Icône Font Awesome</label>
                <input type="text" name="cat_icon" id="editCatIcon" class="form-control" oninput="updateIconPreview('editIcon','editCatIcon')">
                <div style="margin-top:.6rem;display:flex;gap:.4rem;flex-wrap:wrap">
                    <?php foreach ($quickIcons as $ic): ?>
                    <span class="icon-option" onclick="pickIcon('editCatIcon','editIconPreview','<?php echo $ic; ?>')" title="<?php echo $ic; ?>">
                        <i class="<?php echo $ic; ?>"></i>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:.5rem;display:flex;align-items:center;gap:.5rem">
                    <span>Aperçu :</span>
                    <span id="editIcon" class="icon-preview" style="background:#64748b22;color:#64748b"><i class="fas fa-box"></i></span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Couleur</label>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <input type="color" name="cat_color" id="editCatColor" class="form-control" style="width:60px;height:38px;padding:2px">
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <?php foreach ($quickColors as $qc): ?>
                        <span style="width:24px;height:24px;border-radius:50%;background:<?php echo $qc; ?>;cursor:pointer;border:2px solid transparent;transition:border-color .15s"
                              onclick="document.getElementById('editCatColor').value='<?php echo $qc; ?>'"
                              onmouseover="this.style.borderColor='#fff'" onmouseout="this.style.borderColor='transparent'"></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Ordre d'affichage</label>
                <input type="number" name="cat_order" id="editCatOrder" class="form-control" min="0" step="10">
            </div>
            <div class="form-group">
                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" name="cat_active" id="editCatActive" value="1">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label">Catégorie active</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editCatModal').classList.remove('open')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL AJOUTER ARTICLE ────────────────────────────────────────────────── -->
<?php if (!empty($configCats)): ?>
<div id="addItemModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouvel Article</h3>
            <button class="modal-close" onclick="document.getElementById('addItemModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>">
            <input type="hidden" name="action" value="add_item">
            <div class="form-group">
                <label class="form-label">Nom de l'article *</label>
                <input type="text" name="item_name" class="form-control" placeholder="Burger Classic, Glock 17…" required>
            </div>
            <div class="form-group">
                <label class="form-label">Catégorie *</label>
                <select name="item_category" class="form-control" required>
                    <?php foreach (array_keys($configCats) as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Prix ($) *</label>
                <input type="number" name="item_price" class="form-control" step="0.01" min="0" placeholder="0.00" required>
            </div>
            <div class="form-group">
                <label class="form-label">Quantité en stock *</label>
                <input type="number" name="item_quantity" class="form-control" min="0" placeholder="0" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('addItemModal').classList.remove('open')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER ARTICLE -->
<div id="editItemModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> Modifier l'Article</h3>
            <button class="modal-close" onclick="document.getElementById('editItemModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" action="?company=<?php echo urlencode($companyKey); ?>">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="editItemId">
            <div class="form-group">
                <label class="form-label">Nom *</label>
                <input type="text" name="item_name" id="editItemName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Catégorie *</label>
                <select name="item_category" id="editItemCat" class="form-control" required>
                    <?php foreach (array_keys($configCats) as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Prix ($) *</label>
                <input type="number" name="item_price" id="editItemPrice" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label class="form-label">Quantité *</label>
                <input type="number" name="item_quantity" id="editItemQty" class="form-control" min="0" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editItemModal').classList.remove('open')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- VUE LISTE DES ENTREPRISES                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fas fa-boxes-stacking" style="color:var(--blue)"></i> Catalogues & Stock</h1>
        <p>Gestion centralisée des catégories et articles pour toutes les entreprises et panels.</p>
    </div>
</div>

<!-- Toolbar -->
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <form method="GET" style="flex:1;min-width:220px;position:relative">
        <i class="fas fa-search" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:.8rem;pointer-events:none"></i>
        <input type="text" name="q" class="form-control" placeholder="Rechercher une entreprise…"
               value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>"
               style="padding-left:2.2rem" oninput="this.form.submit()">
        <?php if ($filterType !== 'all'): ?>
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <?php endif; ?>
    </form>
    <div class="tabs" style="margin-bottom:0">
        <a href="?type=all<?php echo !empty($_GET['q'])? '&q='.urlencode($_GET['q']):''; ?>" class="tab-btn <?php echo $filterType==='all'?'active':''; ?>">
            Tous (<?php echo count($entities); ?>)
        </a>
        <a href="?type=company<?php echo !empty($_GET['q'])? '&q='.urlencode($_GET['q']):''; ?>" class="tab-btn <?php echo $filterType==='company'?'active':''; ?>">
            <i class="fas fa-building"></i> Entreprises
        </a>
        <a href="?type=panel<?php echo !empty($_GET['q'])? '&q='.urlencode($_GET['q']):''; ?>" class="tab-btn <?php echo $filterType==='panel'?'active':''; ?>">
            <i class="fas fa-grid-2"></i> Panels
        </a>
    </div>
</div>

<?php if (empty($filteredEntities)): ?>
<div class="empty-state">
    <i class="fas fa-search"></i>
    <p>Aucune entreprise trouvée</p>
</div>
<?php else: ?>
<div class="companies-grid">
<?php foreach ($filteredEntities as $e):
    $eColor  = $e['color'] ?? '#3b82f6';
    $hasLogo = !empty($e['logo_url']);
    $maxE    = (int)$e['max_items'];
    $usedE   = (int)$e['item_count'];
    $pctE    = $maxE > 0 ? min(100, round($usedE / $maxE * 100)) : 0;
    $colE    = $pctE > 85 ? 'var(--red)' : ($pctE > 60 ? 'var(--yellow)' : 'var(--green)');
?>
<div class="company-card">
    <div class="cc-header">
        <div class="cc-logo" style="background:<?php echo $eColor; ?>22;color:<?php echo $eColor; ?>">
            <?php if ($hasLogo): ?>
            <img src="<?php echo htmlspecialchars($e['logo_url']); ?>" alt="">
            <?php else: ?>
            <i class="fas fa-<?php echo $e['type']==='company'?'building':'grid-2'; ?>"></i>
            <?php endif; ?>
        </div>
        <div class="cc-info">
            <div class="cc-name"><?php echo htmlspecialchars($e['name']); ?></div>
            <div class="cc-type">
                <?php echo $e['type']==='company'?'Entreprise':'Panel'; ?> · <code style="font-size:.65rem"><?php echo htmlspecialchars($e['key']); ?></code>
            </div>
        </div>
    </div>
    <div class="cc-body">
        <div class="cc-stats">
            <div class="cc-stat">
                <div class="cc-stat-val" style="color:var(--blue)"><?php echo $e['item_count']; ?></div>
                <div class="cc-stat-lbl">Articles</div>
            </div>
            <div class="cc-stat">
                <div class="cc-stat-val" style="color:var(--purple)"><?php echo $e['cat_count']; ?></div>
                <div class="cc-stat-lbl">Catégories</div>
            </div>
            <div class="cc-stat">
                <?php if ($maxE > 0): ?>
                <div class="cc-stat-val" style="color:<?php echo $colE; ?>"><?php echo $usedE; ?>/<?php echo $maxE; ?></div>
                <div class="cc-stat-lbl <?php echo $pctE > 85 ? 'warn' : ''; ?>">
                    <?php echo $pctE > 85 ? '⚠ Limite' : 'Limite'; ?>
                </div>
                <?php else: ?>
                <div class="cc-stat-val" style="color:var(--text-muted)">∞</div>
                <div class="cc-stat-lbl">Limite</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($maxE > 0 && $pctE > 0): ?>
        <div class="limit-bar" style="margin-bottom:.85rem">
            <div class="limit-fill" style="width:<?php echo $pctE; ?>%;background:<?php echo $colE; ?>"></div>
        </div>
        <?php endif; ?>

        <a href="?company=<?php echo urlencode($e['key']); ?>" class="btn btn-primary w-full" style="justify-content:center">
            <i class="fas fa-sliders"></i> Configurer
        </a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// Tab switching
function showTab(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}

// Category modals
function openEditCat(cat) {
    document.getElementById('editCatId').value      = cat.id;
    document.getElementById('editCatOldName').value  = cat.name;
    document.getElementById('editCatName').value     = cat.name;
    document.getElementById('editCatIcon').value     = cat.icon;
    document.getElementById('editCatColor').value    = cat.color;
    document.getElementById('editCatOrder').value    = cat.display_order;
    document.getElementById('editCatActive').checked = cat.is_active == 1;
    updateIconPreview('editIcon','editCatIcon');
    document.getElementById('editCatModal').classList.add('open');
}

// Item modals
function openEditItem(it) {
    document.getElementById('editItemId').value    = it.id;
    document.getElementById('editItemName').value  = it.item_name;
    document.getElementById('editItemCat').value   = it.item_category;
    document.getElementById('editItemPrice').value = it.item_price;
    document.getElementById('editItemQty').value   = it.stock_quantity;
    document.getElementById('editItemModal').classList.add('open');
}

// Icon preview
function updateIconPreview(previewId, inputId) {
    const ic  = document.getElementById(inputId).value.trim();
    const el  = document.getElementById(previewId);
    if (!el) return;
    el.innerHTML = `<i class="${ic}"></i>`;
}
function pickIcon(inputId, previewId, icon) {
    document.getElementById(inputId).value = icon;
    updateIconPreview(previewId, inputId);
}

// Item search filter
function filterItems() {
    const q = document.getElementById('itemSearch')?.value.toLowerCase() ?? '';
    document.querySelectorAll('.item-row').forEach(r => {
        r.style.display = r.dataset.name.includes(q) ? '' : 'none';
    });
}

// Close modals on backdrop click
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
