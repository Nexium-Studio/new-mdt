<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Triads Records';
$companyColor   = '#8b5cf6';
$companyFaction = 'TRIADS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Productions';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

/* ══════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_prod') {
        try {
            $db->prepare("
                INSERT INTO company_productions
                    (faction,artist_id,studio_id,title,type,genre,status,streams,revenue,release_date,producer,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $companyFaction,
                intval($_POST['artist_id']) ?: null,
                intval($_POST['studio_id']) ?: null,
                trim($_POST['title']),
                $_POST['type'],
                trim($_POST['genre']),
                $_POST['status'],
                intval($_POST['streams']),
                floatval($_POST['revenue']),
                $_POST['release_date'] ?: null,
                trim($_POST['producer']),
                trim($_POST['notes']),
            ]);
            $message = "🎵 <strong>".htmlspecialchars(trim($_POST['title']))."</strong> ajouté !";
            $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'edit_prod') {
        try {
            $db->prepare("
                UPDATE company_productions
                SET artist_id=?,studio_id=?,title=?,type=?,genre=?,status=?,
                    streams=?,revenue=?,release_date=?,producer=?,notes=?
                WHERE id=? AND faction=?
            ")->execute([
                intval($_POST['artist_id']) ?: null,
                intval($_POST['studio_id']) ?: null,
                trim($_POST['title']),
                $_POST['type'],
                trim($_POST['genre']),
                $_POST['status'],
                intval($_POST['streams']),
                floatval($_POST['revenue']),
                $_POST['release_date'] ?: null,
                trim($_POST['producer']),
                trim($_POST['notes']),
                intval($_POST['prod_id']),
                $companyFaction,
            ]);
            $message = 'Production mise à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'delete_prod') {
        try {
            $db->prepare("DELETE FROM company_productions WHERE id=? AND faction=?")
               ->execute([intval($_POST['prod_id']), $companyFaction]);
            $message = 'Production supprimée.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'update_streams') {
        try {
            $db->prepare("UPDATE company_productions SET streams=?, revenue=? WHERE id=? AND faction=?")
               ->execute([intval($_POST['streams']), floatval($_POST['revenue']), intval($_POST['prod_id']), $companyFaction]);
            $message = 'Stats mises à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }
}

/* ══════════════════════════════════════════════════════
   FILTRES & DONNÉES
══════════════════════════════════════════════════════ */
$filterType   = $_GET['type']      ?? 'all';
$filterStatus = $_GET['status']    ?? 'all';
$filterArtist = intval($_GET['artist_id'] ?? 0);
$search       = trim($_GET['q']    ?? '');

$where = ["cp.faction=?"]; $params = [$companyFaction];
if ($filterType   !== 'all') { $where[]="cp.type=?";   $params[]=$filterType; }
if ($filterStatus !== 'all') { $where[]="cp.status=?"; $params[]=$filterStatus; }
if ($filterArtist)            { $where[]="cp.artist_id=?"; $params[]=$filterArtist; }
if ($search) { $where[]="(cp.title LIKE ? OR a.artist_name LIKE ? OR cp.producer LIKE ?)";
               $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }

$stmt = $db->prepare("
    SELECT cp.*, a.artist_name, s.name AS studio_name
    FROM company_productions cp
    LEFT JOIN company_artists a ON cp.artist_id=a.id
    LEFT JOIN company_studios s ON cp.studio_id=s.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY cp.created_at DESC
");
$stmt->execute($params); $prods = $stmt->fetchAll();

/* Stats */
$stmt = $db->prepare("SELECT COUNT(*) t, SUM(status='released') r, SUM(status='in_progress') ip, COALESCE(SUM(revenue),0) rev, COALESCE(SUM(streams),0) str FROM company_productions WHERE faction=?");
$stmt->execute([$companyFaction]); $stats = $stmt->fetch();

/* Listes pour selects */
$stmt=$db->prepare("SELECT id,artist_name FROM company_artists WHERE faction=? ORDER BY artist_name"); $stmt->execute([$companyFaction]); $artists=$stmt->fetchAll();
$stmt=$db->prepare("SELECT id,name FROM company_studios WHERE faction=? ORDER BY name");              $stmt->execute([$companyFaction]); $studios=$stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
.prod-list-item {
    display:grid;
    grid-template-columns: 48px 1fr auto auto auto;
    align-items:center; gap:1rem;
    padding:.85rem 1.2rem;
    border-bottom:1px solid var(--border);
    transition:background .14s;
}
.prod-list-item:last-child { border-bottom:none; }
.prod-list-item:hover { background:rgba(255,255,255,.016); }

.prod-thumb {
    width:48px;height:48px;border-radius:11px;flex-shrink:0;
    background:rgba(255,255,255,.04);border:1px solid var(--border);
    display:flex;align-items:center;justify-content:center;font-size:1.3rem;
}
.prod-info .prod-title { font-weight:700; font-size:.88rem; }
.prod-info .prod-meta  { font-size:.73rem; color:var(--txt-3); margin-top:.1rem; }

.prod-nums { text-align:right; }
.prod-nums .pn-val { font-weight:700; font-size:.85rem; }
.prod-nums .pn-lbl { font-size:.64rem; color:var(--txt-3); text-transform:uppercase; letter-spacing:.7px; }

.prod-actions { display:flex; gap:.3rem; }

/* Pipeline visuel */
.pipeline { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.25rem; margin-bottom:1.4rem; }
.pipe-col { min-width:200px; }
.pipe-header { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; padding:.45rem .7rem; border-radius:var(--radius-sm); margin-bottom:.5rem; }
.pipe-item { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-sm); padding:.7rem; margin-bottom:.4rem; font-size:.78rem; }
.pipe-item:hover { border-color:var(--border-c); }
.pipe-item-title { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pipe-item-sub { font-size:.68rem; color:var(--txt-3); margin-top:.1rem; }
</style>

<?php if($message):?>
<div class="alert alert-<?php echo $messageType;?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $message;?></div>
<?php endif;?>

<!-- KPI -->
<div class="kpi-grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));">
    <div class="kpi"><div class="kpi-label">Total Productions</div><div class="kpi-value"><?php echo $stats['t'];?></div></div>
    <div class="kpi"><div class="kpi-label">Sorties</div><div class="kpi-value green"><?php echo $stats['r'];?></div></div>
    <div class="kpi"><div class="kpi-label">En Cours</div><div class="kpi-value yellow"><?php echo $stats['ip'];?></div></div>
    <div class="kpi"><div class="kpi-label">Streams Totaux</div><div class="kpi-value blue" style="font-size:1.25rem;"><?php echo number_format($stats['str']/1000,1);?>K</div></div>
    <div class="kpi"><div class="kpi-label">Revenus Totaux</div><div class="kpi-value purple" style="font-size:1.25rem;">$<?php echo number_format($stats['rev'],0,'.',' ');?></div></div>
</div>

<!-- Toolbar -->
<div class="toolbar mb-2">
    <div class="f-center gap-2 f-wrap">
        <div class="filter-bar">
            <?php $types=['all'=>'Tous','single'=>'🎵 Singles','ep'=>'💿 EPs','album'=>'📀 Albums','mixtape'=>'🎚 Mixtapes','feature'=>'🎤 Features','instrumental'=>'🎹 Instru.'];
            foreach($types as $v=>$l){ $q=http_build_query(array_merge($_GET,['type'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterType===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <div class="filter-bar">
            <?php $statuses=['all'=>'Tous statuts','in_progress'=>'⚙️ En cours','mixing'=>'🎛 Mixage','mastering'=>'🔊 Mastering','pending_release'=>'📅 À sortir','released'=>'✅ Sortis','cancelled'=>'❌ Annulés'];
            foreach($statuses as $v=>$l){ $q=http_build_query(array_merge($_GET,['status'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterStatus===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <form method="GET" class="f-center gap-1">
            <?php if($filterType!=='all'):?><input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType);?>"><?php endif;?>
            <?php if($filterStatus!=='all'):?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus);?>"><?php endif;?>
            <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.35rem .7rem;" class="f-center gap-1">
                <i class="fas fa-search" style="color:var(--txt-3);font-size:.75rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search);?>" placeholder="Titre, artiste…" style="background:transparent;border:none;outline:none;color:var(--txt);font-size:.8rem;width:140px;font-family:var(--font-b);">
            </div>
        </form>
    </div>
    <button onclick="Company.openModal('addProdModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle Production</button>
</div>

<!-- Liste Productions -->
<div class="card" style="padding:0;overflow:hidden;">
    <?php if(empty($prods)):?>
    <div style="text-align:center;color:var(--txt-3);padding:3rem;">
        <i class="fas fa-compact-disc" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i>
        <div style="font-weight:600;">Aucune production trouvée</div>
    </div>
    <?php else:
    $typeIcon=['single'=>'🎵','ep'=>'💿','album'=>'📀','mixtape'=>'🎚','feature'=>'🎤','instrumental'=>'🎹'];
    $sBadge=[
        'in_progress'    =>['⚙️ En cours',   'badge-yellow'],
        'mixing'         =>['🎛 Mixage',      'badge-blue'],
        'mastering'      =>['🔊 Mastering',   'badge-purple'],
        'pending_release'=>['📅 À sortir',    'badge-pink'],
        'released'       =>['✅ Sorti',        'badge-green'],
        'cancelled'      =>['❌ Annulé',       'badge-gray'],
    ];
    foreach($prods as $p):
        $ic=$typeIcon[$p['type']]??'🎵';
        [$slbl,$scss]=$sBadge[$p['status']]??[$p['status'],'badge-gray'];
    ?>
    <div class="prod-list-item">
        <div class="prod-thumb"><?php echo $ic;?></div>
        <div class="prod-info">
            <div class="prod-title"><?php echo htmlspecialchars($p['title']);?></div>
            <div class="prod-meta">
                🎤 <?php echo htmlspecialchars($p['artist_name']??'Artiste inconnu');?>
                <?php if($p['producer']): ?> · Prod : <?php echo htmlspecialchars($p['producer']);?><?php endif;?>
                <?php if($p['release_date']): ?> · <?php echo date('d/m/Y',strtotime($p['release_date']));?><?php endif;?>
                <?php if($p['studio_name']): ?> · 🎚 <?php echo htmlspecialchars($p['studio_name']);?><?php endif;?>
            </div>
        </div>
        <div class="prod-nums">
            <div class="pn-val txt-blue"><?php echo number_format($p['streams']/1000,1);?>K</div>
            <div class="pn-lbl">Streams</div>
        </div>
        <div class="prod-nums">
            <div class="pn-val txt-money">$<?php echo number_format($p['revenue'],0,'.',' ');?></div>
            <div class="pn-lbl">Revenus</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;">
            <span class="badge <?php echo $scss;?>"><?php echo $slbl;?></span>
            <div class="prod-actions">
                <button onclick="openStats(<?php echo htmlspecialchars(json_encode(['id'=>$p['id'],'title'=>$p['title'],'streams'=>$p['streams'],'revenue'=>$p['revenue']]));?>)" class="btn btn-secondary btn-xs" title="Maj stats"><i class="fas fa-chart-bar"></i></button>
                <button onclick="openEditProd(<?php echo htmlspecialchars(json_encode($p));?>)" class="btn btn-ghost btn-xs"><i class="fas fa-edit"></i></button>
                <form method="POST" data-confirm="Supprimer ?" style="display:inline;">
                    <input type="hidden" name="action" value="delete_prod">
                    <input type="hidden" name="prod_id" value="<?php echo $p['id'];?>">
                    <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach;?>
    <?php endif;?>
</div>

<!-- MODAL AJOUTER PRODUCTION -->
<div id="addProdModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-plus"></i> Nouvelle Production</h2><button class="modal-close" onclick="Company.closeModal('addProdModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_prod">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" class="form-input" required placeholder="Titre de la production…"></div>
                    <div class="form-group"><label class="form-label">Artiste</label>
                        <select name="artist_id" class="form-select"><option value="">— Sélectionner —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <option value="single">🎵 Single</option><option value="ep">💿 EP</option>
                            <option value="album">📀 Album</option><option value="mixtape">🎚 Mixtape</option>
                            <option value="feature">🎤 Feature</option><option value="instrumental">🎹 Instrumental</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Genre</label>
                        <select name="genre" class="form-select">
                            <?php foreach(['Hip-Hop','Trap','Drill','R&B','Pop','Afro','Electronic','Reggaeton','Autre'] as $g) echo "<option>$g</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" class="form-select" required>
                            <option value="in_progress">En cours</option><option value="mixing">Mixage</option>
                            <option value="mastering">Mastering</option><option value="pending_release">En attente sortie</option>
                            <option value="released">Sorti</option><option value="cancelled">Annulé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Date de sortie</label><input type="date" name="release_date" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Producteur</label><input type="text" name="producer" class="form-input" placeholder="Nom du producteur"></div>
                    <div class="form-group"><label class="form-label">Studio</label>
                        <select name="studio_id" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($studios as $s) echo "<option value='{$s['id']}'>".htmlspecialchars($s['name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Streams</label><input type="number" name="streams" class="form-input" value="0" min="0"></div>
                    <div class="form-group"><label class="form-label">Revenus ($)</label><input type="number" name="revenue" class="form-input" value="0" min="0" step="0.01"></div>
                    <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Notes sur la production…"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('addProdModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</button></div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER -->
<div id="editProdModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-edit"></i> Modifier la Production</h2><button class="modal-close" onclick="Company.closeModal('editProdModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_prod">
            <input type="hidden" name="prod_id" id="ep_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" id="ep_title" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Artiste</label>
                        <select name="artist_id" id="ep_artist" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" id="ep_type" class="form-select" required>
                            <option value="single">🎵 Single</option><option value="ep">💿 EP</option>
                            <option value="album">📀 Album</option><option value="mixtape">🎚 Mixtape</option>
                            <option value="feature">🎤 Feature</option><option value="instrumental">🎹 Instrumental</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Genre</label>
                        <select name="genre" id="ep_genre" class="form-select">
                            <?php foreach(['Hip-Hop','Trap','Drill','R&B','Pop','Afro','Electronic','Reggaeton','Autre'] as $g) echo "<option>$g</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" id="ep_status" class="form-select" required>
                            <option value="in_progress">En cours</option><option value="mixing">Mixage</option>
                            <option value="mastering">Mastering</option><option value="pending_release">En attente sortie</option>
                            <option value="released">Sorti</option><option value="cancelled">Annulé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Date de sortie</label><input type="date" name="release_date" id="ep_release" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Producteur</label><input type="text" name="producer" id="ep_producer" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Studio</label>
                        <select name="studio_id" id="ep_studio" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($studios as $s) echo "<option value='{$s['id']}'>".htmlspecialchars($s['name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Streams</label><input type="number" name="streams" id="ep_streams" class="form-input" min="0"></div>
                    <div class="form-group"><label class="form-label">Revenus ($)</label><input type="number" name="revenue" id="ep_revenue" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" id="ep_notes" class="form-textarea"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('editProdModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- MODAL MAJ STATS (streams/revenue) -->
<div id="statsModal" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-head"><h2><i class="fas fa-chart-bar"></i> Mettre à jour les Stats</h2><button class="modal-close" onclick="Company.closeModal('statsModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_streams">
            <input type="hidden" name="prod_id" id="sm_id">
            <div class="modal-body">
                <div style="font-weight:700;margin-bottom:1rem;color:var(--txt);" id="sm_title"></div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Streams</label><input type="number" name="streams" id="sm_streams" class="form-input" min="0"></div>
                    <div class="form-group"><label class="form-label">Revenus ($)</label><input type="number" name="revenue" id="sm_revenue" class="form-input" min="0" step="0.01"></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('statsModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<script>
function openEditProd(d) {
    document.getElementById('ep_id').value       = d.id;
    document.getElementById('ep_title').value    = d.title       || '';
    document.getElementById('ep_artist').value   = d.artist_id   || '';
    document.getElementById('ep_type').value     = d.type        || 'single';
    document.getElementById('ep_genre').value    = d.genre       || '';
    document.getElementById('ep_status').value   = d.status      || 'in_progress';
    document.getElementById('ep_release').value  = d.release_date|| '';
    document.getElementById('ep_producer').value = d.producer    || '';
    document.getElementById('ep_studio').value   = d.studio_id   || '';
    document.getElementById('ep_streams').value  = d.streams     || 0;
    document.getElementById('ep_revenue').value  = d.revenue     || 0;
    document.getElementById('ep_notes').value    = d.notes       || '';
    Company.openModal('editProdModal');
}
function openStats(d) {
    document.getElementById('sm_id').value      = d.id;
    document.getElementById('sm_title').textContent = d.title;
    document.getElementById('sm_streams').value = d.streams  || 0;
    document.getElementById('sm_revenue').value = d.revenue  || 0;
    Company.openModal('statsModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>