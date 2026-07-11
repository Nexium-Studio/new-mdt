<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Triads Records';
$companyColor   = '#8b5cf6';
$companyFaction = 'TRIADS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Artistes';
$db        = Database::getInstance()->getConnection();
$message   = '';
$messageType = '';

/* ══════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_artist') {
        try {
            $db->prepare("
                INSERT INTO company_artists
                    (faction,artist_name,real_name,genre,status,royalty_percentage,advance,bio,phone,email,signed_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $companyFaction,
                trim($_POST['artist_name']),
                trim($_POST['real_name']),
                trim($_POST['genre']),
                $_POST['status'],
                floatval($_POST['royalty_percentage']),
                floatval($_POST['advance']),
                trim($_POST['bio']),
                trim($_POST['phone']),
                trim($_POST['email']),
                $_POST['signed_date'] ?: null,
            ]);
            $message = "🎤 <strong>" . htmlspecialchars(trim($_POST['artist_name'])) . "</strong> ajouté avec succès !";
            $messageType = 'success';
        } catch (Exception $e) { $message = 'Erreur : '.$e->getMessage(); $messageType = 'error'; }
    }

    if ($action === 'edit_artist') {
        try {
            $db->prepare("
                UPDATE company_artists
                SET artist_name=?,real_name=?,genre=?,status=?,
                    royalty_percentage=?,advance=?,bio=?,phone=?,email=?
                WHERE id=? AND faction=?
            ")->execute([
                trim($_POST['artist_name']),trim($_POST['real_name']),trim($_POST['genre']),
                $_POST['status'],floatval($_POST['royalty_percentage']),floatval($_POST['advance']),
                trim($_POST['bio']),trim($_POST['phone']),trim($_POST['email']),
                intval($_POST['artist_id']),$companyFaction,
            ]);
            $message = 'Artiste mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message = 'Erreur : '.$e->getMessage(); $messageType = 'error'; }
    }

    if ($action === 'delete_artist') {
        try {
            $db->prepare("DELETE FROM company_artists WHERE id=? AND faction=?")
               ->execute([intval($_POST['artist_id']), $companyFaction]);
            $message = 'Artiste supprimé.'; $messageType = 'success';
        } catch (Exception $e) { $message = 'Erreur : '.$e->getMessage(); $messageType = 'error'; }
    }
}

/* ══════════════════════════════════════════════════════
   FILTRES
══════════════════════════════════════════════════════ */
$filterStatus = $_GET['status'] ?? 'all';
$filterGenre  = $_GET['genre']  ?? 'all';
$search       = trim($_GET['q'] ?? '');

$where  = ["ca.faction=?"]; $params = [$companyFaction];
if ($filterStatus !== 'all') { $where[] = "ca.status=?";   $params[] = $filterStatus; }
if ($filterGenre  !== 'all') { $where[] = "ca.genre=?";    $params[] = $filterGenre; }
if ($search) {
    $where[] = "(ca.artist_name LIKE ? OR ca.real_name LIKE ? OR ca.genre LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $db->prepare("
    SELECT ca.*,
        (SELECT COUNT(*)                    FROM company_productions p WHERE p.artist_id=ca.id)                       AS total_prods,
        (SELECT COUNT(*)                    FROM company_productions p WHERE p.artist_id=ca.id AND p.status='released') AS released_prods,
        (SELECT COALESCE(SUM(p.streams),0)  FROM company_productions p WHERE p.artist_id=ca.id)                       AS total_streams,
        (SELECT COALESCE(SUM(p.revenue),0)  FROM company_productions p WHERE p.artist_id=ca.id)                       AS total_revenue,
        (SELECT COUNT(*)                    FROM company_contracts   c WHERE c.artist_id=ca.id AND c.status='active')  AS active_contracts
    FROM company_artists ca
    WHERE ".implode(' AND ',$where)."
    ORDER BY ca.artist_name
");
$stmt->execute($params); $artists = $stmt->fetchAll();

/* Stats globales */
$stmt = $db->prepare("SELECT COUNT(*) t,SUM(status='active') a,SUM(status='prospect') p,SUM(status='negotiation') n FROM company_artists WHERE faction=?");
$stmt->execute([$companyFaction]); $stats = $stmt->fetch();

$stmt = $db->prepare("SELECT COALESCE(SUM(p.revenue),0),COALESCE(SUM(p.streams),0) FROM company_productions p JOIN company_artists a ON p.artist_id=a.id WHERE a.faction=?");
$stmt->execute([$companyFaction]); [$totalRev,$totalStr] = $stmt->fetch(PDO::FETCH_NUM);

$stmt = $db->prepare("SELECT DISTINCT genre FROM company_artists WHERE faction=? AND genre IS NOT NULL AND genre!='' ORDER BY genre");
$stmt->execute([$companyFaction]); $genres = $stmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/includes/header.php';
?>

<style>
.artist-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:1.1rem; }

.acard { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; transition:var(--tr); }
.acard:hover { border-color:var(--border-c); transform:translateY(-2px); box-shadow:0 10px 28px rgba(0,0,0,.22); }
.acard-stripe { height:3px; background:linear-gradient(90deg,var(--c),var(--c-lt)); opacity:0; transition:opacity .25s; }
.acard:hover .acard-stripe { opacity:1; }
.acard-body { padding:1.2rem; }
.acard-foot { padding:.8rem 1.2rem; border-top:1px solid var(--border); display:flex; gap:.4rem; background:rgba(0,0,0,.08); }

.a-avatar { width:50px;height:50px; border-radius:13px; background:linear-gradient(135deg,var(--c),var(--c-dk)); display:flex;align-items:center;justify-content:center; font-size:1.3rem; box-shadow:0 4px 14px var(--c-dim); flex-shrink:0; }
.a-name { font-family:var(--font-d); font-size:1.02rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.a-real { font-size:.73rem; color:var(--txt-3); margin-top:.08rem; }
.a-genre { display:inline-block; padding:.13rem .5rem; background:rgba(139,92,246,.12); border:1px solid rgba(139,92,246,.2); border-radius:20px; font-size:.63rem; font-weight:700; color:var(--c-lt); margin-top:.4rem; }

.a-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:.35rem; margin:.9rem 0; }
.a-stat { background:rgba(255,255,255,.025); border:1px solid var(--border); border-radius:var(--radius-sm); padding:.45rem; text-align:center; }
.a-stat-v { font-family:var(--font-d); font-size:.92rem; font-weight:800; }
.a-stat-l { font-size:.58rem; text-transform:uppercase; letter-spacing:.8px; color:var(--txt-3); margin-top:.08rem; }

.a-info { display:flex; align-items:center; gap:.45rem; font-size:.75rem; color:var(--txt-2); margin-top:.3rem; }
.a-info i { width:13px; text-align:center; color:var(--txt-3); font-size:.7rem; }
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>">
    <i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $message; ?>
</div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi-grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));">
    <div class="kpi"><div class="kpi-label">Total Artistes</div><div class="kpi-value"><?php echo $stats['t']; ?></div></div>
    <div class="kpi"><div class="kpi-label">Sous Contrat</div><div class="kpi-value green"><?php echo $stats['a']; ?></div></div>
    <div class="kpi"><div class="kpi-label">Négociation</div><div class="kpi-value yellow"><?php echo $stats['n']; ?></div></div>
    <div class="kpi"><div class="kpi-label">Prospects</div><div class="kpi-value blue"><?php echo $stats['p']; ?></div></div>
    <div class="kpi"><div class="kpi-label">Revenus Totaux</div><div class="kpi-value purple" style="font-size:1.25rem;">$<?php echo number_format($totalRev,0,'.',' '); ?></div></div>
    <div class="kpi"><div class="kpi-label">Streams Totaux</div><div class="kpi-value blue" style="font-size:1.25rem;"><?php echo number_format($totalStr/1000,1); ?>K</div></div>
</div>

<!-- Toolbar -->
<div class="toolbar mb-2">
    <div class="f-center gap-2 f-wrap">
        <div class="filter-bar">
            <?php $statusOpts=['all'=>'Tous','active'=>'✅ Contrat','negotiation'=>'🤝 Négociation','prospect'=>'🔍 Prospect','inactive'=>'⏸ Inactif'];
            foreach($statusOpts as $v=>$l){ $q=http_build_query(array_merge($_GET,['status'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterStatus===$v?'active':'')."'>$l</a>"; } ?>
        </div>
        <?php if($genres): ?>
        <div class="filter-bar">
            <?php $q=http_build_query(array_merge($_GET,['genre'=>'all'])); echo "<a href='?$q' class='filter-btn ".($filterGenre==='all'?'active':'')."'>Tous genres</a>";
            foreach($genres as $g){ $q=http_build_query(array_merge($_GET,['genre'=>$g])); echo "<a href='?$q' class='filter-btn ".($filterGenre===$g?'active':'')."'>".htmlspecialchars($g)."</a>"; } ?>
        </div>
        <?php endif; ?>
        <form method="GET" class="f-center gap-1">
            <?php if($filterStatus!=='all'):?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus);?>"><?php endif;?>
            <?php if($filterGenre!=='all'):?><input type="hidden" name="genre" value="<?php echo htmlspecialchars($filterGenre);?>"><?php endif;?>
            <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.35rem .7rem;" class="f-center gap-1">
                <i class="fas fa-search" style="color:var(--txt-3);font-size:.75rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search);?>" placeholder="Rechercher…" style="background:transparent;border:none;outline:none;color:var(--txt);font-size:.8rem;width:145px;font-family:var(--font-b);">
            </div>
            <button type="submit" class="btn btn-ghost btn-sm">OK</button>
        </form>
    </div>
    <button onclick="Company.openModal('addModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvel Artiste</button>
</div>
<div class="fs-xs txt-3 mb-3"><?php echo count($artists); ?> artiste(s)<?php if($search): ?> — "<strong style="color:var(--txt-2);"><?php echo htmlspecialchars($search);?></strong>"<?php endif;?></div>

<!-- Grille -->
<?php if(empty($artists)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-3);">
    <i class="fas fa-microphone" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i>
    <div style="font-weight:600;">Aucun artiste trouvé</div>
    <div class="fs-sm mt-1">Utilisez le bouton "Nouvel Artiste" pour commencer</div>
</div>
<?php else:
$sCfg=['active'=>['Sous contrat','badge-green'],'prospect'=>['Prospect','badge-blue'],'negotiation'=>['Négociation','badge-yellow'],'inactive'=>['Inactif','badge-gray']];
?>
<div class="artist-grid">
<?php foreach($artists as $a): [$slabel,$scss]=$sCfg[$a['status']]??[$a['status'],'badge-gray']; ?>
<div class="acard">
    <div class="acard-stripe"></div>
    <div class="acard-body">
        <div class="f-between mb-2">
            <div class="f-center gap-2" style="min-width:0;">
                <div class="a-avatar">🎤</div>
                <div style="min-width:0;">
                    <div class="a-name"><?php echo htmlspecialchars($a['artist_name']);?></div>
                    <div class="a-real"><?php echo htmlspecialchars($a['real_name']?:'—');?></div>
                </div>
            </div>
            <span class="badge <?php echo $scss;?> badge-dot" style="flex-shrink:0;"><?php echo $slabel;?></span>
        </div>
        <?php if($a['genre']):?><span class="a-genre"><?php echo htmlspecialchars($a['genre']);?></span><?php endif;?>
        <div class="a-stats">
            <div class="a-stat"><div class="a-stat-v"><?php echo $a['total_prods'];?></div><div class="a-stat-l">Prod.</div></div>
            <div class="a-stat"><div class="a-stat-v txt-blue"><?php echo number_format($a['total_streams']/1000,1);?>K</div><div class="a-stat-l">Streams</div></div>
            <div class="a-stat"><div class="a-stat-v txt-money" style="font-size:.8rem;">$<?php echo number_format($a['total_revenue'],0);?></div><div class="a-stat-l">Revenus</div></div>
        </div>
        <div class="a-info"><i class="fas fa-percentage"></i>Royalties : <strong><?php echo $a['royalty_percentage'];?>%</strong><?php if($a['advance']>0):?>&nbsp;·&nbsp;Avance : <strong class="txt-yellow">$<?php echo number_format($a['advance'],0);?></strong><?php endif;?></div>
        <?php if($a['phone']):?><div class="a-info"><i class="fas fa-phone"></i><?php echo htmlspecialchars($a['phone']);?></div><?php endif;?>
        <?php if($a['email']):?><div class="a-info"><i class="fas fa-envelope"></i><?php echo htmlspecialchars($a['email']);?></div><?php endif;?>
        <?php if($a['signed_date']):?><div class="a-info"><i class="fas fa-file-signature"></i>Signé le <?php echo date('d/m/Y',strtotime($a['signed_date']));?></div><?php endif;?>
        <?php if($a['active_contracts']>0):?><div class="a-info"><i class="fas fa-file-contract" style="color:var(--c);"></i><span style="color:var(--c-lt);"><?php echo $a['active_contracts'];?> contrat(s) actif(s)</span></div><?php endif;?>
        <?php if($a['bio']):?><div style="margin-top:.6rem;font-size:.74rem;color:var(--txt-3);line-height:1.5;border-top:1px solid var(--border);padding-top:.55rem;"><?php echo htmlspecialchars(mb_substr($a['bio'],0,110));?><?php echo mb_strlen($a['bio'])>110?'…':'';?></div><?php endif;?>
    </div>
    <div class="acard-foot">
        <button onclick="openEdit(<?php echo htmlspecialchars(json_encode($a));?>)" class="btn btn-secondary btn-sm" style="flex:1;"><i class="fas fa-edit"></i> Modifier</button>
        <a href="productions.php?artist_id=<?php echo $a['id'];?>" class="btn btn-ghost btn-sm" title="Productions"><i class="fas fa-compact-disc"></i></a>
        <a href="contrats.php?artist_id=<?php echo $a['id'];?>"    class="btn btn-ghost btn-sm" title="Contrats"><i class="fas fa-file-contract"></i></a>
        <form method="POST" data-confirm="Supprimer cet artiste ?" style="display:inline;">
            <input type="hidden" name="action" value="delete_artist">
            <input type="hidden" name="artist_id" value="<?php echo $a['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- MODAL AJOUTER -->
<div id="addModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-microphone"></i> Nouvel Artiste</h2><button class="modal-close" onclick="Company.closeModal('addModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_artist">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Nom d'artiste *</label><input type="text" name="artist_name" class="form-input" required placeholder="Ex : Lil Ghost"></div>
                    <div class="form-group"><label class="form-label">Nom réel</label><input type="text" name="real_name" class="form-input" placeholder="Ex : James Brown"></div>
                    <div class="form-group"><label class="form-label">Genre *</label>
                        <select name="genre" class="form-select" required><option value="">— Sélectionner —</option>
                        <?php foreach(['Hip-Hop','Trap','Drill','R&B','Pop','Afro','Electronic','Reggaeton','Jazz','Rock','Soul','Autre'] as $g) echo "<option>$g</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" class="form-select" required>
                            <option value="prospect">Prospect</option><option value="negotiation">En négociation</option>
                            <option value="active">Sous contrat</option><option value="inactive">Inactif</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">% Royalties *</label><input type="number" name="royalty_percentage" class="form-input" value="15" min="0" max="100" step="0.5" required><span class="form-hint">Part reversée à l'artiste</span></div>
                    <div class="form-group"><label class="form-label">Avance ($)</label><input type="number" name="advance" class="form-input" value="0" min="0" step="0.01"><span class="form-hint">À déduire des futures royalties</span></div>
                    <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="phone" class="form-input" placeholder="555-XXXX"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-input" placeholder="artiste@mail.com"></div>
                    <div class="form-group"><label class="form-label">Date de signature</label><input type="date" name="signed_date" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Biographie</label><textarea name="bio" class="form-textarea" placeholder="Description, parcours, style…"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Ajouter</button></div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-edit"></i> Modifier l'Artiste</h2><button class="modal-close" onclick="Company.closeModal('editModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_artist">
            <input type="hidden" name="artist_id" id="ea_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Nom d'artiste *</label><input type="text" name="artist_name" id="ea_name" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Nom réel</label><input type="text" name="real_name" id="ea_real" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Genre *</label>
                        <select name="genre" id="ea_genre" class="form-select" required><option value="">—</option>
                        <?php foreach(['Hip-Hop','Trap','Drill','R&B','Pop','Afro','Electronic','Reggaeton','Jazz','Rock','Soul','Autre'] as $g) echo "<option>$g</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" id="ea_status" class="form-select" required>
                            <option value="prospect">Prospect</option><option value="negotiation">En négociation</option>
                            <option value="active">Sous contrat</option><option value="inactive">Inactif</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">% Royalties *</label><input type="number" name="royalty_percentage" id="ea_royalty" class="form-input" min="0" max="100" step="0.5" required></div>
                    <div class="form-group"><label class="form-label">Avance ($)</label><input type="number" name="advance" id="ea_advance" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="phone" id="ea_phone" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="ea_email" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Biographie</label><textarea name="bio" id="ea_bio" class="form-textarea"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('editModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<script>
function openEdit(d) {
    document.getElementById('ea_id').value      = d.id;
    document.getElementById('ea_name').value    = d.artist_name        || '';
    document.getElementById('ea_real').value    = d.real_name          || '';
    document.getElementById('ea_genre').value   = d.genre              || '';
    document.getElementById('ea_status').value  = d.status             || 'prospect';
    document.getElementById('ea_royalty').value = d.royalty_percentage || 15;
    document.getElementById('ea_advance').value = d.advance            || 0;
    document.getElementById('ea_phone').value   = d.phone              || '';
    document.getElementById('ea_email').value   = d.email              || '';
    document.getElementById('ea_bio').value     = d.bio                || '';
    Company.openModal('editModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>