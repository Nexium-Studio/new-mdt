<?php
// pages/staff/content/lssd/dispatch-config.php
define('STAFF_PANEL', true);
require_once __DIR__ . '/../../../../config/config.php';
requireLogin();
if (!hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/staff/dashboard.php');
}

$db = Database::getInstance()->getConnection();

$lssdName = 'LSSD';
try {
    $r = $db->prepare("SELECT custom_name FROM admin_panel_config WHERE panel_key = 'LSSD' LIMIT 1");
    $r->execute();
    $row = $r->fetch();
    if ($row && $row['custom_name']) $lssdName = $row['custom_name'];
} catch (Exception $e) {}

$pageTitle  = 'Custom Dispatch ' . $lssdName;
$breadcrumb = [['label' => 'Panels', 'url' => 'panels.php'], ['label' => 'Custom Dispatch ' . $lssdName]];
$success = $error = '';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS lssd_dispatch_config (config_key VARCHAR(64) PRIMARY KEY, config_value TEXT)");
} catch (Exception $e) {}

$CODE_TEN_DEFAULT = [
    '10-03'=>'gray','10-06'=>'gray','10-07'=>'gray','10-08'=>'gray','10-10'=>'gray','10-23'=>'gray','10-98'=>'gray',
    '10-38'=>'green','10-50'=>'green','10-51'=>'green','10-52'=>'green','10-56'=>'green','10-57'=>'green',
    '10-14'=>'blue','10-15'=>'blue','10-19'=>'blue',
    '10-31'=>'red','10-35'=>'red','10-91'=>'red',
    '10-40'=>'orange','10-59'=>'orange','10-60'=>'orange','10-74'=>'orange','10-75'=>'orange',
];
$OTHER_TAGS_DEFAULT = [
    'En Pause'=>'gray','En Formation'=>'gray','En Déplacement'=>'green',
    'En Attente'=>'purple','En Meeting'=>'yellow','Code 5'=>'red',
];

$codeTen   = $CODE_TEN_DEFAULT;
$otherTags = $OTHER_TAGS_DEFAULT;
try {
    $cfg = $db->query("SELECT config_key, config_value FROM lssd_dispatch_config WHERE config_key IN ('code_ten','other_tags')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($cfg['code_ten']))   $codeTen   = json_decode($cfg['code_ten'],   true) ?: $codeTen;
    if (!empty($cfg['other_tags'])) $otherTags = json_decode($cfg['other_tags'], true) ?: $otherTags;
} catch (Exception $e) {}

$COLORS = [
    'gray'   => ['label'=>'Gris',   'hex'=>'#64748b'],
    'green'  => ['label'=>'Vert',   'hex'=>'#10b981'],
    'red'    => ['label'=>'Rouge',  'hex'=>'#ef4444'],
    'orange' => ['label'=>'Orange', 'hex'=>'#f97316'],
    'blue'   => ['label'=>'Bleu',   'hex'=>'#10b981'],
    'purple' => ['label'=>'Violet', 'hex'=>'#8b5cf6'],
    'yellow' => ['label'=>'Jaune',  'hex'=>'#eab308'],
    'cyan'   => ['label'=>'Cyan',   'hex'=>'#06b6d4'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_grade') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nom requis.'; }
        else { try { $pos = $db->query("SELECT COALESCE(MAX(position),0)+1 FROM lssd_dispatch_grades")->fetchColumn(); $db->prepare("INSERT INTO lssd_dispatch_grades (name,position) VALUES (?,?)")->execute([$name,$pos]); $success='Grade ajouté.'; } catch(Exception $e){$error=$e->getMessage();} }
    }
    if ($action === 'edit_grade') {
        $id=(int)$_POST['id']; $name=trim($_POST['name']??'');
        if($name===''){$error='Nom requis.';}else{$db->prepare("UPDATE lssd_dispatch_grades SET name=? WHERE id=?")->execute([$name,$id]);$success='Grade mis à jour.';}
    }
    if ($action === 'delete_grade') {
        $db->prepare("DELETE FROM lssd_dispatch_grades WHERE id=?")->execute([(int)$_POST['id']]); $success='Grade supprimé.';
    }

    if ($action === 'add_vehicle') {
        $name=trim($_POST['name']??'');
        if($name===''){$error='Nom requis.';}else{try{$pos=$db->query("SELECT COALESCE(MAX(position),0)+1 FROM lssd_dispatch_patrol_vehicles")->fetchColumn();$db->prepare("INSERT INTO lssd_dispatch_patrol_vehicles (name,position) VALUES (?,?)")->execute([$name,$pos]);$success='Véhicule ajouté.';}catch(Exception $e){$error=$e->getMessage();}}
    }
    if ($action === 'edit_vehicle') {
        $id=(int)$_POST['id'];$name=trim($_POST['name']??'');
        if($name===''){$error='Nom requis.';}else{$db->prepare("UPDATE lssd_dispatch_patrol_vehicles SET name=? WHERE id=?")->execute([$name,$id]);$success='Véhicule mis à jour.';}
    }
    if ($action === 'delete_vehicle') {
        $db->prepare("DELETE FROM lssd_dispatch_patrol_vehicles WHERE id=?")->execute([(int)$_POST['id']]); $success='Véhicule supprimé.';
    }

    if ($action === 'add_label') {
        $name=trim($_POST['name']??'');$color=trim($_POST['color']??'#10b981');
        if($name===''){$error='Nom requis.';}else{try{$db->prepare("INSERT INTO lssd_dispatch_labels (name,color) VALUES (?,?)")->execute([$name,$color]);$success='Label ajouté.';}catch(Exception $e){$error=$e->getMessage();}}
    }
    if ($action === 'edit_label') {
        $id=(int)$_POST['id'];$name=trim($_POST['name']??'');$color=trim($_POST['color']??'#10b981');
        if($name===''){$error='Nom requis.';}else{$db->prepare("UPDATE lssd_dispatch_labels SET name=?,color=? WHERE id=?")->execute([$name,$color,$id]);$success='Label mis à jour.';}
    }
    if ($action === 'delete_label') {
        $id=(int)$_POST['id'];
        $db->prepare("DELETE FROM lssd_dispatch_card_labels WHERE label_id=?")->execute([$id]);
        $db->prepare("DELETE FROM lssd_dispatch_labels WHERE id=?")->execute([$id]);
        $success='Label supprimé.';
    }

    if ($action === 'add_other_tag') {
        $name=trim($_POST['name']??'');$color=trim($_POST['color']??'gray');
        if($name===''){$error='Nom requis.';}elseif(isset($otherTags[$name])){$error='Tag déjà existant.';}
        else{$otherTags[$name]=$color;$json=json_encode($otherTags);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);$success='Tag ajouté.';}
    }
    if ($action === 'edit_other_tag') {
        $old=$_POST['old_name']??'';$name=trim($_POST['name']??'');$color=trim($_POST['color']??'gray');
        if($name===''){$error='Nom requis.';}else{if(isset($otherTags[$old]))unset($otherTags[$old]);$otherTags[$name]=$color;$json=json_encode($otherTags);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);$success='Tag mis à jour.';}
    }
    if ($action === 'delete_other_tag') {
        $name=$_POST['name']??'';if(isset($otherTags[$name])){unset($otherTags[$name]);$json=json_encode($otherTags);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('other_tags',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);}$success='Tag supprimé.';
    }

    if ($action === 'add_code_ten') {
        $code=strtoupper(trim($_POST['code']??''));$color=trim($_POST['color']??'gray');
        if($code===''){$error='Code requis.';}elseif(isset($codeTen[$code])){$error='Code déjà existant.';}
        else{$codeTen[$code]=$color;ksort($codeTen);$json=json_encode($codeTen);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);$success='Code ajouté.';}
    }
    if ($action === 'edit_code_ten') {
        $old=$_POST['old_code']??'';$code=strtoupper(trim($_POST['code']??''));$color=trim($_POST['color']??'gray');
        if($code===''){$error='Code requis.';}else{if(isset($codeTen[$old]))unset($codeTen[$old]);$codeTen[$code]=$color;ksort($codeTen);$json=json_encode($codeTen);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);$success='Code mis à jour.';}
    }
    if ($action === 'delete_code_ten') {
        $code=$_POST['code']??'';if(isset($codeTen[$code])){unset($codeTen[$code]);$json=json_encode($codeTen);$db->prepare("INSERT INTO lssd_dispatch_config (config_key,config_value) VALUES ('code_ten',?) ON DUPLICATE KEY UPDATE config_value=?")->execute([$json,$json]);}$success='Code supprimé.';
    }

    if ($success) {
        $tab = $_POST['current_tab'] ?? 'grades';
        header("Location: dispatch-config.php?tab={$tab}&ok=" . urlencode($success));
        exit;
    }
}

if (!empty($_GET['ok'])) $success = $_GET['ok'];

$grades   = $db->query("SELECT * FROM lssd_dispatch_grades ORDER BY position")->fetchAll();
$vehicles = $db->query("SELECT * FROM lssd_dispatch_patrol_vehicles ORDER BY position")->fetchAll();
$labels   = $db->query("SELECT * FROM lssd_dispatch_labels ORDER BY name")->fetchAll();
$activeTab = $_GET['tab'] ?? 'grades';

function chex(string $k, array $C): string { return $C[$k]['hex'] ?? '#64748b'; }
function cswatches(string $fn, string $sel, array $C): void {
    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;" class="dc-swatches">';
    foreach ($C as $k => $m) {
        $ch = $sel===$k ? 'checked' : '';
        $border = $sel===$k ? '#fff' : 'transparent';
        echo "<label title='{$m['label']}' style='cursor:pointer;'>
            <input type='radio' name='{$fn}' value='{$k}' {$ch} class='dc-color-r' style='display:none;'>
            <span class='dc-sw' data-val='{$k}' style='display:inline-block;width:26px;height:26px;border-radius:6px;background:{$m['hex']};border:2.5px solid {$border};transition:all .15s;'></span>
        </label>";
    }
    echo '</div>';
}

include __DIR__ . '/../../includes/header.php';
?>
<style>
.dc-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;}
@media(max-width:1100px){.dc-grid{grid-template-columns:1fr;}}
.dc-list{display:flex;flex-direction:column;gap:6px;}
.dc-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg-elevated);transition:border-color .2s;}
.dc-row:hover{border-color:var(--border-hover);}
.dc-row-ico{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;flex-shrink:0;font-size:.82rem;}
.dc-row-name{flex:1;font-weight:600;font-size:.875rem;}
.dc-row-sub{font-size:.7rem;color:var(--text-secondary);margin-top:2px;}
.dc-row-acts{display:flex;gap:6px;flex-shrink:0;}
/* !! Clé du fix : display:none par défaut en CSS inline, flex via JS */
.dc-ef{flex-direction:column;gap:10px;background:var(--bg-elevated);border:1px solid var(--blue);border-radius:10px;padding:14px 16px;margin-top:4px;}
.dc-empty{text-align:center;padding:32px 20px;color:var(--text-secondary);font-size:.85rem;}
.dc-count{font-size:.65rem;font-weight:700;background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-secondary);padding:1px 7px;border-radius:8px;margin-left:6px;}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h1><i class="fa-solid fa-radio" style="color:var(--cyan);margin-right:10px;"></i>Custom Dispatch <span style="color:var(--cyan);"><?php echo htmlspecialchars($lssdName); ?></span></h1>
        <p>Grades, véhicules, labels, tags et codes 10 des cartes dispatch</p>
    </div>
    <div class="page-header-right">
        <a href="<?php echo SITE_URL; ?>/pages/lssd/dispatch.php" target="_blank" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir le Dispatch
        </a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="tabs" data-tab-group="dispatch">
    <button class="tab-btn <?php echo $activeTab==='grades'?'active':''; ?>" data-tab="grades"><i class="fa-solid fa-shield-halved"></i> Grades<span class="dc-count"><?php echo count($grades); ?></span></button>
    <button class="tab-btn <?php echo $activeTab==='vehicles'?'active':''; ?>" data-tab="vehicles"><i class="fa-solid fa-car"></i> Véhicules<span class="dc-count"><?php echo count($vehicles); ?></span></button>
    <button class="tab-btn <?php echo $activeTab==='labels'?'active':''; ?>" data-tab="labels"><i class="fa-solid fa-tag"></i> Labels<span class="dc-count"><?php echo count($labels); ?></span></button>
    <button class="tab-btn <?php echo $activeTab==='other_tags'?'active':''; ?>" data-tab="other_tags"><i class="fa-solid fa-hashtag"></i> Tags<span class="dc-count"><?php echo count($otherTags); ?></span></button>
    <button class="tab-btn <?php echo $activeTab==='code_ten'?'active':''; ?>" data-tab="code_ten"><i class="fa-solid fa-walkie-talkie"></i> Codes 10<span class="dc-count"><?php echo count($codeTen); ?></span></button>
</div>

<?php
/* ─── Macro : render item row ─── */
function dcItem(string $safeId, string $name, string $iconClass, string $iconStyle, string $sub = ''): void { ?>
<div>
    <div class="dc-row">
        <div class="dc-row-ico" style="<?php echo $iconStyle; ?>"><?php echo $iconClass; ?></div>
        <div>
            <div class="dc-row-name"><?php echo htmlspecialchars($name); ?></div>
            <?php if ($sub): ?><div class="dc-row-sub"><?php echo htmlspecialchars($sub); ?></div><?php endif; ?>
        </div>
        <div class="dc-row-acts">
            <button type="button" class="btn btn-secondary btn-sm btn-icon-only"
                    onclick="dcEdit('<?php echo $safeId; ?>')" title="Modifier">
                <i class="fa-solid fa-pen" style="font-size:10px;"></i>
            </button>
<?php } ?>
<?php function dcItemEnd(): void { ?></div></div><?php } ?>

<!-- ══ GRADES ══ -->
<div class="tab-pane <?php echo $activeTab==='grades'?'active':''; ?>" id="tab-grades">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-shield-halved"></i> Grades</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if(empty($grades)): ?><div class="dc-empty">Aucun grade configuré</div>
    <?php else: foreach($grades as $g): $sid='gef'.$g['id']; ?>
    <?php dcItem($sid,$g['name'],'<i class="fa-solid fa-shield-halved"></i>','background:rgba(16,185,129,.12);color:var(--blue);'); ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_grade">
            <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
            <input type="hidden" name="current_tab" value="grades">
            <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
        </form>
    <?php dcItemEnd(); ?>
    <!-- Formulaire édition -->
    <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
        <input type="hidden" name="action" value="edit_grade">
        <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
        <input type="hidden" name="current_tab" value="grades">
        <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($g['name']); ?>" required style="flex:1;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i></button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_grade">
            <input type="hidden" name="current_tab" value="grades">
            <label class="form-label">Nom du grade</label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="name" class="form-control" placeholder="Ex: Lieutenant..." required>
                <button type="submit" class="btn btn-primary" style="flex-shrink:0;"><i class="fa-solid fa-plus"></i></button>
            </div>
        </form>
    </div>
</div>
</div></div>

<!-- ══ VÉHICULES ══ -->
<div class="tab-pane <?php echo $activeTab==='vehicles'?'active':''; ?>" id="tab-vehicles">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-car"></i> Véhicules</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if(empty($vehicles)): ?><div class="dc-empty">Aucun véhicule configuré</div>
    <?php else: foreach($vehicles as $v): $sid='vef'.$v['id']; ?>
    <?php dcItem($sid,$v['name'],'<i class="fa-solid fa-car"></i>','background:rgba(16,185,129,.12);color:var(--green);'); ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete_vehicle">
            <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
            <input type="hidden" name="current_tab" value="vehicles">
            <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
        </form>
    <?php dcItemEnd(); ?>
    <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
        <input type="hidden" name="action" value="edit_vehicle">
        <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
        <input type="hidden" name="current_tab" value="vehicles">
        <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($v['name']); ?>" required style="flex:1;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i></button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_vehicle">
            <input type="hidden" name="current_tab" value="vehicles">
            <label class="form-label">Nom du véhicule</label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="name" class="form-control" placeholder="Ex: Police Cruiser..." required>
                <button type="submit" class="btn btn-primary" style="flex-shrink:0;"><i class="fa-solid fa-plus"></i></button>
            </div>
        </form>
    </div>
</div>
</div></div>

<!-- ══ LABELS ══ -->
<div class="tab-pane <?php echo $activeTab==='labels'?'active':''; ?>" id="tab-labels">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-tag"></i> Labels</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if(empty($labels)): ?><div class="dc-empty">Aucun label configuré</div>
    <?php else: foreach($labels as $lb): $sid='lef'.$lb['id']; ?>
    <div>
        <div class="dc-row">
            <div style="width:32px;height:32px;border-radius:8px;background:<?php echo htmlspecialchars($lb['color']); ?>22;display:grid;place-items:center;flex-shrink:0;">
                <div style="width:12px;height:12px;border-radius:50%;background:<?php echo htmlspecialchars($lb['color']); ?>;"></div>
            </div>
            <div>
                <div class="dc-row-name"><?php echo htmlspecialchars($lb['name']); ?></div>
                <div class="dc-row-sub" style="font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($lb['color']); ?></div>
            </div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_label">
                    <input type="hidden" name="id" value="<?php echo $lb['id']; ?>">
                    <input type="hidden" name="current_tab" value="labels">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_label">
            <input type="hidden" name="id" value="<?php echo $lb['id']; ?>">
            <input type="hidden" name="current_tab" value="labels">
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" style="font-size:.75rem;">Nom</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($lb['name']); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label" style="font-size:.75rem;">Couleur</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" name="color" class="form-control" value="<?php echo htmlspecialchars($lb['color']); ?>" style="height:38px;padding:4px;width:52px;flex-shrink:0;">
                    <span style="font-size:.78rem;color:var(--text-secondary);">Cliquez pour choisir</span>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Sauvegarder</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_label">
            <input type="hidden" name="current_tab" value="labels">
            <div class="form-group"><label class="form-label">Nom</label><input type="text" name="name" class="form-control" placeholder="Ex: Priorité haute..." required></div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Couleur</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" name="color" class="form-control" value="#10b981" style="height:38px;padding:4px;width:52px;flex-shrink:0;">
                    <span style="font-size:.78rem;color:var(--text-secondary);">Sélectionnez une couleur</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<!-- ══ OTHER TAGS ══ -->
<div class="tab-pane <?php echo $activeTab==='other_tags'?'active':''; ?>" id="tab-other_tags">
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-hashtag"></i> Tags de statut</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if(empty($otherTags)): ?><div class="dc-empty">Aucun tag configuré</div>
    <?php else: foreach($otherTags as $tn=>$tc): $hex=chex($tc,$COLORS); $sid='otef_'.preg_replace('/[^a-z0-9]/i','_',$tn); ?>
    <div>
        <div class="dc-row">
            <div style="width:12px;height:12px;border-radius:50%;background:<?php echo $hex; ?>;flex-shrink:0;box-shadow:0 0 6px <?php echo $hex; ?>66;"></div>
            <div>
                <div class="dc-row-name"><?php echo htmlspecialchars($tn); ?></div>
                <div class="dc-row-sub"><?php echo $COLORS[$tc]['label']??$tc; ?></div>
            </div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_other_tag">
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($tn); ?>">
                    <input type="hidden" name="current_tab" value="other_tags">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_other_tag">
            <input type="hidden" name="old_name" value="<?php echo htmlspecialchars($tn); ?>">
            <input type="hidden" name="current_tab" value="other_tags">
            <div class="form-group" style="margin-bottom:8px;"><label class="form-label" style="font-size:.75rem;">Nom</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($tn); ?>" required></div>
            <div class="form-group" style="margin-bottom:10px;"><label class="form-label" style="font-size:.75rem;">Couleur</label><?php cswatches('color',$tc,$COLORS); ?></div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> OK</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_other_tag">
            <input type="hidden" name="current_tab" value="other_tags">
            <div class="form-group"><label class="form-label">Nom</label><input type="text" name="name" class="form-control" placeholder="Ex: En Pause, Code 5..." required></div>
            <div class="form-group" style="margin-bottom:16px;"><label class="form-label">Couleur</label><?php cswatches('color','gray',$COLORS); ?></div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<!-- ══ CODES 10 ══ -->
<div class="tab-pane <?php echo $activeTab==='code_ten'?'active':''; ?>" id="tab-code_ten">
<div class="alert alert-info" style="margin-bottom:20px;"><i class="fa-solid fa-circle-info"></i> <span>Codes radio des cartes dispatch (ex: <strong>10-38</strong>). La couleur définit le badge.</span></div>
<div class="dc-grid">
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-walkie-talkie"></i> Codes 10</div></div>
    <div class="card-body"><div class="dc-list">
    <?php if(empty($codeTen)): ?><div class="dc-empty">Aucun code configuré</div>
    <?php else: foreach($codeTen as $code=>$cColor): $hex=chex($cColor,$COLORS); $sid='c10ef_'.preg_replace('/[^a-z0-9]/i','_',$code); ?>
    <div>
        <div class="dc-row">
            <div style="min-width:62px;padding:4px 10px;border-radius:6px;background:<?php echo $hex; ?>22;border:1px solid <?php echo $hex; ?>44;font-family:'DM Mono',monospace;font-size:.82rem;font-weight:700;color:<?php echo $hex; ?>;text-align:center;flex-shrink:0;"><?php echo htmlspecialchars($code); ?></div>
            <div class="dc-row-sub"><?php echo $COLORS[$cColor]['label']??$cColor; ?></div>
            <div class="dc-row-acts">
                <button type="button" class="btn btn-secondary btn-sm btn-icon-only" onclick="dcEdit('<?php echo $sid; ?>')" title="Modifier"><i class="fa-solid fa-pen" style="font-size:10px;"></i></button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_code_ten">
                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
                    <input type="hidden" name="current_tab" value="code_ten">
                    <button type="submit" class="btn btn-danger btn-sm btn-icon-only" title="Supprimer"><i class="fa-solid fa-trash" style="font-size:10px;"></i></button>
                </form>
            </div>
        </div>
        <form method="POST" id="<?php echo $sid; ?>" class="dc-ef" style="display:none;">
            <input type="hidden" name="action" value="edit_code_ten">
            <input type="hidden" name="old_code" value="<?php echo htmlspecialchars($code); ?>">
            <input type="hidden" name="current_tab" value="code_ten">
            <div class="form-group" style="margin-bottom:8px;"><label class="form-label" style="font-size:.75rem;">Code</label><input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($code); ?>" style="font-family:'DM Mono',monospace;text-transform:uppercase;" required></div>
            <div class="form-group" style="margin-bottom:10px;"><label class="form-label" style="font-size:.75rem;">Couleur</label><?php cswatches('color',$cColor,$COLORS); ?></div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> OK</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="dcClose('<?php echo $sid; ?>')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
    </div></div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-plus"></i> Ajouter</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_code_ten">
            <input type="hidden" name="current_tab" value="code_ten">
            <div class="form-group"><label class="form-label">Code</label><input type="text" name="code" class="form-control" placeholder="Ex: 10-38..." style="font-family:'DM Mono',monospace;text-transform:uppercase;" required></div>
            <div class="form-group" style="margin-bottom:16px;"><label class="form-label">Couleur</label><?php cswatches('color','gray',$COLORS); ?></div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;"><i class="fa-solid fa-plus"></i> Ajouter</button>
        </form>
    </div>
</div>
</div></div>

<script>
/* ═══ EDIT TOGGLE — 100% JS style, pas de conflit CSS ═══ */
function dcEdit(id) {
    var form = document.getElementById(id);
    if (!form) return;
    var isOpen = (form.style.display === 'flex');
    // Ferme tous les formulaires d'édition ouverts
    document.querySelectorAll('.dc-ef').forEach(function(f){ f.style.display='none'; });
    // Ouvre celui-ci s'il était fermé
    if (!isOpen) {
        form.style.display = 'flex';
        var inp = form.querySelector('input[type="text"], input[type="email"]');
        if (inp) { setTimeout(function(){ inp.focus(); inp.select(); }, 30); }
    }
}
function dcClose(id) {
    var form = document.getElementById(id);
    if (form) form.style.display = 'none';
}

/* Highlight des swatches couleur (Other Tags / Code 10) */
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('dc-color-r')) {
        var form = e.target.closest('form');
        if (!form) return;
        var name = e.target.name;
        form.querySelectorAll('input[name="' + name + '"] + .dc-sw').forEach(function(sw){
            sw.style.borderColor = 'transparent';
            sw.style.transform = 'scale(1)';
        });
        var sel = e.target.nextElementSibling;
        if (sel) { sel.style.borderColor = '#fff'; sel.style.transform = 'scale(1.25)'; }
    }
});

/* ESC ferme les formulaires */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.dc-ef').forEach(function(f){ f.style.display='none'; });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>