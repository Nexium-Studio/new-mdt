<?php
// pages/staff/extensions/academy/forum.php — gestion catégories + tags + activité du forum
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$pageTitle  = 'Académie — Forum';
$breadcrumb = [['label' => 'Extensions'], ['label' => 'Académie'], ['label' => 'Forum']];
$success = $error = '';

if (empty($_SESSION['staff_academy_csrf'])) $_SESSION['staff_academy_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['staff_academy_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        // ── Catégories ───────────────────────────────────────────────────
        if ($action === 'cat_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $fk   = trim((string)($_POST['faction_key'] ?? ''));
            $fk   = $fk === '' ? null : $fk;
            if ($fk !== null && !academy_faction_exists($fk)) throw new Exception('Faction invalide');
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            $slug = $slug === '' ? preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) : $slug;
            $desc = trim((string)($_POST['description'] ?? ''));
            $icon = trim((string)($_POST['icon'] ?? 'comments'));
            $color = trim((string)($_POST['color'] ?? '#3b82f6'));
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $req   = (int)($_POST['required_grade_id'] ?? 0); $req = $req > 0 ? $req : null;
            $active= !empty($_POST['is_active']) ? 1 : 0;
            $locked= !empty($_POST['is_locked']) ? 1 : 0;
            if ($name === '') throw new Exception('Nom requis');
            if ($id > 0) {
                $db->prepare('UPDATE academy_forum_categories SET faction_key=?, name=?, slug=?, description=?, icon=?, color=?, sort_order=?, required_grade_id=?, is_active=?, is_locked=? WHERE id=?')
                   ->execute([$fk, $name, $slug, $desc, $icon, $color, $sort, $req, $active, $locked, $id]);
            } else {
                $db->prepare('INSERT INTO academy_forum_categories (faction_key, name, slug, description, icon, color, sort_order, required_grade_id, is_active, is_locked) VALUES (?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$fk, $name, $slug, $desc, $icon, $color, $sort, $req, $active, $locked]);
            }
            $success = 'Catégorie enregistrée.';
        }
        if ($action === 'cat_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_forum_categories WHERE id = ?')->execute([$id]);
            $success = 'Catégorie supprimée (et ses sujets).';
        }
        // ── Tags ─────────────────────────────────────────────────────────
        if ($action === 'tag_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $fk   = trim((string)($_POST['faction_key'] ?? ''));
            $fk   = $fk === '' ? null : $fk;
            if ($fk !== null && !academy_faction_exists($fk)) throw new Exception('Faction invalide');
            $label= trim((string)($_POST['label'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            $slug = $slug === '' ? preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)) : $slug;
            $color= trim((string)($_POST['color'] ?? '#8b5cf6'));
            $desc = trim((string)($_POST['description'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($label === '') throw new Exception('Libellé requis');
            if ($id > 0) {
                $db->prepare('UPDATE academy_forum_tags SET faction_key=?, label=?, slug=?, color=?, description=?, sort_order=? WHERE id=?')
                   ->execute([$fk, $label, $slug, $color, $desc, $sort, $id]);
            } else {
                $db->prepare('INSERT INTO academy_forum_tags (faction_key, label, slug, color, description, sort_order) VALUES (?,?,?,?,?,?)')
                   ->execute([$fk, $label, $slug, $color, $desc, $sort]);
            }
            $success = 'Tag enregistré.';
        }
        if ($action === 'tag_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM academy_forum_tags WHERE id = ?')->execute([$id]);
            $success = 'Tag supprimé.';
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// Données
$cats = $db->query('SELECT c.*, g.name AS grade_name FROM academy_forum_categories c LEFT JOIN academy_grades g ON g.id = c.required_grade_id ORDER BY c.faction_key IS NULL DESC, c.faction_key, c.sort_order, c.id')->fetchAll(PDO::FETCH_ASSOC);
$tags = $db->query('SELECT * FROM academy_forum_tags ORDER BY faction_key IS NULL DESC, faction_key, sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
$grades = $db->query('SELECT id, name, faction_key FROM academy_grades ORDER BY faction_key, name')->fetchAll(PDO::FETCH_ASSOC);

// Stats globales
$stats = [
    'cats'    => count($cats),
    'tags'    => count($tags),
    'threads' => (int)$db->query('SELECT COUNT(*) FROM academy_forum_threads')->fetchColumn(),
    'posts'   => (int)$db->query('SELECT COUNT(*) FROM academy_forum_posts WHERE deleted_at IS NULL')->fetchColumn(),
];

// Activité récente
$recent = $db->query(
    'SELECT t.id, t.title, t.created_at, c.name AS cat_name, c.faction_key, u.username
     FROM academy_forum_threads t
     LEFT JOIN academy_forum_categories c ON c.id = t.category_id
     LEFT JOIN users u ON u.id = t.author_id
     ORDER BY t.created_at DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

$factions = academy_factions();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:0}
.form-row .form-group{margin-bottom:14px}
.btn-sm{padding:5px 10px;font-size:.78rem;border-radius:7px}
.gforum-tabs{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:18px;flex-wrap:wrap}
.gforum-tabs button{background:transparent;border:0;padding:10px 18px;font-family:'Syne',sans-serif;font-weight:600;color:var(--text-secondary);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;font-size:.88rem}
.gforum-tabs button:hover{color:#fff}
.gforum-tabs button.on{color:#fff;border-bottom-color:var(--purple)}
.gforum-pane{display:none}
.gforum-pane.on{display:block}

.cat-tile,.tag-tile{display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--bg-card);border:1px solid var(--border);border-radius:11px;margin-bottom:8px}
.cat-tile .ic,.tag-tile .ic{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;color:#fff;flex-shrink:0}
.cat-tile .info,.tag-tile .info{flex:1;min-width:0}
.cat-tile .nm,.tag-tile .nm{font-family:'Syne',sans-serif;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.cat-tile .ds,.tag-tile .ds{font-size:.78rem;color:var(--text-secondary);margin-top:3px}
.cat-tile .acts,.tag-tile .acts{display:flex;gap:6px}

.fac-group{margin-bottom:22px}
.fac-group-head{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:10px 14px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px}
.fac-group-head .fg-ic{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;color:#fff;font-size:.85rem}
.fac-group-head .fg-name{font-family:'Syne',sans-serif;font-weight:700;color:#fff;font-size:.95rem;flex:1}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>Gestion du forum académique</h1>
    <p>Configurez les sections, les tags et la modération du forum par faction.</p>
  </div>
  <div class="page-header-right">
    <a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php" class="btn btn-secondary"><i class="fa-solid fa-up-right-from-square"></i> Voir le forum</a>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="stats-grid">
  <div class="stat-card" style="--accent-color:#3b82f6"><div class="stat-icon"><i class="fa-solid fa-folder-tree"></i></div><div class="stat-info"><div class="stat-label">Catégories</div><div class="stat-value"><?php echo $stats['cats']; ?></div><div class="stat-meta">configurées</div></div></div>
  <div class="stat-card" style="--accent-color:#8b5cf6"><div class="stat-icon"><i class="fa-solid fa-tag"></i></div><div class="stat-info"><div class="stat-label">Tags</div><div class="stat-value"><?php echo $stats['tags']; ?></div><div class="stat-meta">disponibles</div></div></div>
  <div class="stat-card" style="--accent-color:#06b6d4"><div class="stat-icon"><i class="fa-solid fa-comments"></i></div><div class="stat-info"><div class="stat-label">Sujets</div><div class="stat-value"><?php echo $stats['threads']; ?></div><div class="stat-meta">tous forums</div></div></div>
  <div class="stat-card" style="--accent-color:#f97316"><div class="stat-icon"><i class="fa-solid fa-message"></i></div><div class="stat-info"><div class="stat-label">Messages</div><div class="stat-value"><?php echo $stats['posts']; ?></div><div class="stat-meta">échangés</div></div></div>
</div>

<div class="gforum-tabs">
  <button class="on" data-tab="cats"><i class="fa-solid fa-folder"></i> Catégories</button>
  <button data-tab="tags"><i class="fa-solid fa-tag"></i> Tags</button>
  <button data-tab="activity"><i class="fa-solid fa-bolt"></i> Activité récente</button>
</div>

<!-- ─── Catégories ─── -->
<div class="gforum-pane on" id="pane-cats">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="font-size:1.05rem;color:#fff">Sections par faction</h3>
    <button class="btn btn-purple" onclick="openCatModal()"><i class="fa-solid fa-plus"></i> Nouvelle catégorie</button>
  </div>

  <?php
    $catsByFac = [];
    foreach ($cats as $c) { $catsByFac[$c['faction_key'] ?? '__GLOBAL__'][] = $c; }
    foreach ($catsByFac as $fk => $list):
      if ($fk === '__GLOBAL__') {
        $facMeta = ['name' => 'Académie inter-factions', 'icon' => 'graduation-cap', 'color' => '#8b5cf6'];
      } else {
        $facMeta = academy_faction_meta($fk);
      }
  ?>
  <div class="fac-group">
    <div class="fac-group-head">
      <div class="fg-ic" style="background:<?php echo $facMeta['color']; ?>"><i class="fa-solid fa-<?php echo $facMeta['icon']; ?>"></i></div>
      <div class="fg-name"><?php echo htmlspecialchars($facMeta['name']); ?></div>
      <span class="badge badge-purple"><?php echo count($list); ?> section<?php echo count($list) > 1 ? 's' : ''; ?></span>
    </div>
    <?php foreach ($list as $c): ?>
    <div class="cat-tile">
      <div class="ic" style="background:<?php echo $c['color']; ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($c['icon']); ?>"></i></div>
      <div class="info">
        <div class="nm">
          <?php echo htmlspecialchars($c['name']); ?>
          <?php if (empty($c['is_active'])): ?><span class="badge badge-slate">Inactive</span><?php endif; ?>
          <?php if (!empty($c['is_locked'])): ?><span class="badge badge-slate"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
          <?php if (!empty($c['grade_name'])): ?><span class="badge badge-purple"><i class="fa-solid fa-shield-halved"></i> <?php echo htmlspecialchars($c['grade_name']); ?></span><?php endif; ?>
        </div>
        <div class="ds"><?php echo htmlspecialchars($c['description'] ?? '—'); ?></div>
      </div>
      <div class="acts">
        <button class="btn btn-sm btn-secondary" onclick='openCatModal(<?php echo json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-pen"></i></button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette catégorie et ses sujets ?')">
          <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
          <input type="hidden" name="action" value="cat_delete">
          <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
          <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php if (empty($cats)): ?>
  <div class="empty-state"><i class="fa-solid fa-folder-open"></i><div class="es-title">Aucune catégorie</div><p>Importez la migration ou créez votre première catégorie.</p></div>
  <?php endif; ?>
</div>

<!-- ─── Tags ─── -->
<div class="gforum-pane" id="pane-tags">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h3 style="font-size:1.05rem;color:#fff">Tags disponibles</h3>
    <button class="btn btn-purple" onclick="openTagModal()"><i class="fa-solid fa-plus"></i> Nouveau tag</button>
  </div>
  <?php
    $tagsByFac = [];
    foreach ($tags as $t) { $tagsByFac[$t['faction_key'] ?? '__GLOBAL__'][] = $t; }
    foreach ($tagsByFac as $fk => $list):
      if ($fk === '__GLOBAL__') $facMeta = ['name' => 'Tags globaux (toutes factions)', 'icon' => 'globe', 'color' => '#8b5cf6'];
      else                       $facMeta = academy_faction_meta($fk);
  ?>
  <div class="fac-group">
    <div class="fac-group-head">
      <div class="fg-ic" style="background:<?php echo $facMeta['color']; ?>"><i class="fa-solid fa-<?php echo $facMeta['icon']; ?>"></i></div>
      <div class="fg-name"><?php echo htmlspecialchars($facMeta['name']); ?></div>
      <span class="badge badge-purple"><?php echo count($list); ?></span>
    </div>
    <?php foreach ($list as $t):
      [$tr,$tg,$tb] = sscanf($t['color'], "#%02x%02x%02x");
    ?>
    <div class="tag-tile">
      <div class="ic" style="background:rgba(<?php echo "$tr,$tg,$tb"; ?>,.2);color:<?php echo $t['color']; ?>"><i class="fa-solid fa-tag"></i></div>
      <div class="info">
        <div class="nm">
          <?php echo htmlspecialchars($t['label']); ?>
          <span class="badge" style="background:rgba(<?php echo "$tr,$tg,$tb"; ?>,.15);color:<?php echo $t['color']; ?>;border:1px solid rgba(<?php echo "$tr,$tg,$tb"; ?>,.3)"><?php echo htmlspecialchars($t['slug']); ?></span>
        </div>
        <div class="ds"><?php echo htmlspecialchars($t['description'] ?? '—'); ?></div>
      </div>
      <div class="acts">
        <button class="btn btn-sm btn-secondary" onclick='openTagModal(<?php echo json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fa-solid fa-pen"></i></button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce tag ?')">
          <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
          <input type="hidden" name="action" value="tag_delete">
          <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
          <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php if (empty($tags)): ?>
  <div class="empty-state"><i class="fa-solid fa-tag"></i><div class="es-title">Aucun tag</div></div>
  <?php endif; ?>
</div>

<!-- ─── Activité ─── -->
<div class="gforum-pane" id="pane-activity">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-bolt"></i> 10 derniers sujets créés</div></div>
    <div class="card-body">
      <?php if (empty($recent)): ?>
      <div class="empty-state"><i class="fa-solid fa-comments"></i><div class="es-title">Aucune activité</div></div>
      <?php else: ?>
      <div class="table-wrapper">
      <table>
        <thead><tr><th>Sujet</th><th>Faction</th><th>Catégorie</th><th>Auteur</th><th>Créé</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td><a href="<?php echo SITE_URL; ?>/pages/academy-central/forums.php?view=thread&thread=<?php echo (int)$r['id']; ?>" style="color:#fff;font-weight:600"><?php echo htmlspecialchars($r['title']); ?></a></td>
          <td><?php echo $r['faction_key'] ? htmlspecialchars(academy_faction_meta($r['faction_key'])['name']) : '<span class="badge badge-purple">Académie</span>'; ?></td>
          <td><?php echo htmlspecialchars($r['cat_name'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($r['username'] ?? '—'); ?></td>
          <td><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── Modal Catégorie ─── -->
<div class="modal-overlay" id="catModal">
  <form class="modal" method="POST" style="max-width:620px">
    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
    <input type="hidden" name="action" value="cat_save">
    <input type="hidden" name="id" id="cm_id">
    <div class="modal-header"><h3 id="cm_title"><i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie</h3><button type="button" class="modal-close" onclick="document.getElementById('catModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Faction (vide = catégorie globale Académie)</label>
        <select class="form-control" name="faction_key" id="cm_fk">
          <option value="">— Globale (Académie) —</option>
          <?php foreach ($factions as $key => $f): ?>
          <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Ordre</label>
        <input type="number" name="sort_order" id="cm_sort" value="0" class="form-control">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="name" id="cm_name" class="form-control" required maxlength="128"></div>
      <div class="form-group"><label class="form-label">Slug (auto)</label><input type="text" name="slug" id="cm_slug" class="form-control" maxlength="64"></div>
    </div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="cm_desc" class="form-control" rows="2"></textarea></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Icône (Font Awesome)</label><input type="text" name="icon" id="cm_icon" value="comments" class="form-control"></div>
      <div class="form-group"><label class="form-label">Couleur</label><input type="color" name="color" id="cm_color" value="#3b82f6" class="form-control" style="height:38px;padding:2px"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Grade requis (vide = ouvert à tous les membres)</label>
      <select name="required_grade_id" id="cm_grade" class="form-control">
        <option value="0">— Aucun —</option>
        <?php foreach ($grades as $g): ?>
        <option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars(($g['faction_key'] ?? 'Global') . ' — ' . $g['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:0 0 auto"><label class="form-label" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" id="cm_active" value="1" checked> Catégorie active</label></div>
      <div class="form-group" style="flex:0 0 auto"><label class="form-label" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_locked" id="cm_locked" value="1"> Verrouillée (lecture seule)</label></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('catModal').classList.remove('open')">Annuler</button>
      <button type="submit" class="btn btn-purple"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </form>
</div>

<!-- ─── Modal Tag ─── -->
<div class="modal-overlay" id="tagModal">
  <form class="modal" method="POST" style="max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
    <input type="hidden" name="action" value="tag_save">
    <input type="hidden" name="id" id="tm_id">
    <div class="modal-header"><h3 id="tm_title"><i class="fa-solid fa-tag"></i> Nouveau tag</h3><button type="button" class="modal-close" onclick="document.getElementById('tagModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Faction (vide = global)</label>
        <select class="form-control" name="faction_key" id="tm_fk">
          <option value="">— Tag global —</option>
          <?php foreach ($factions as $key => $f): ?>
          <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Ordre</label><input type="number" name="sort_order" id="tm_sort" value="0" class="form-control"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Libellé *</label><input type="text" name="label" id="tm_label" class="form-control" required maxlength="64"></div>
      <div class="form-group"><label class="form-label">Slug (auto)</label><input type="text" name="slug" id="tm_slug" class="form-control" maxlength="64"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Couleur</label><input type="color" name="color" id="tm_color" value="#8b5cf6" class="form-control" style="height:38px;padding:2px"></div>
      <div class="form-group" style="flex:2"><label class="form-label">Description (optionnel)</label><input type="text" name="description" id="tm_desc" class="form-control" maxlength="255"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('tagModal').classList.remove('open')">Annuler</button>
      <button type="submit" class="btn btn-purple"><i class="fa-solid fa-check"></i> Enregistrer</button>
    </div>
  </form>
</div>

<script>
document.querySelectorAll('.gforum-tabs button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.gforum-tabs button').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  document.querySelectorAll('.gforum-pane').forEach(p => p.classList.remove('on'));
  document.getElementById('pane-' + b.dataset.tab).classList.add('on');
}));

function openCatModal(c){
  c = c || {};
  document.getElementById('cm_title').innerHTML = c.id ? '<i class="fa-solid fa-pen"></i> Modifier la catégorie' : '<i class="fa-solid fa-folder-plus"></i> Nouvelle catégorie';
  document.getElementById('cm_id').value    = c.id || '';
  document.getElementById('cm_fk').value    = c.faction_key || '';
  document.getElementById('cm_name').value  = c.name || '';
  document.getElementById('cm_slug').value  = c.slug || '';
  document.getElementById('cm_desc').value  = c.description || '';
  document.getElementById('cm_icon').value  = c.icon || 'comments';
  document.getElementById('cm_color').value = c.color || '#3b82f6';
  document.getElementById('cm_sort').value  = c.sort_order || 0;
  document.getElementById('cm_grade').value = c.required_grade_id || 0;
  document.getElementById('cm_active').checked = c.id ? c.is_active == 1 : true;
  document.getElementById('cm_locked').checked = c.is_locked == 1;
  document.getElementById('catModal').classList.add('open');
}
function openTagModal(t){
  t = t || {};
  document.getElementById('tm_title').innerHTML = t.id ? '<i class="fa-solid fa-pen"></i> Modifier le tag' : '<i class="fa-solid fa-tag"></i> Nouveau tag';
  document.getElementById('tm_id').value    = t.id || '';
  document.getElementById('tm_fk').value    = t.faction_key || '';
  document.getElementById('tm_label').value = t.label || '';
  document.getElementById('tm_slug').value  = t.slug || '';
  document.getElementById('tm_color').value = t.color || '#8b5cf6';
  document.getElementById('tm_desc').value  = t.description || '';
  document.getElementById('tm_sort').value  = t.sort_order || 0;
  document.getElementById('tagModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => o.classList.remove('open')); });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
