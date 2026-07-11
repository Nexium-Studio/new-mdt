<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Triads Records';
$companyColor   = '#8b5cf6';
$companyFaction = 'TRIADS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Contrats';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

/* ══════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contract') {
        try {
            $db->prepare("
                INSERT INTO company_contracts
                    (faction,artist_id,type,title,description,terms,start_date,end_date,value,royalty_percentage,advance,status,signed_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $companyFaction,
                intval($_POST['artist_id']) ?: null,
                $_POST['type'],
                trim($_POST['title']),
                trim($_POST['description']),
                trim($_POST['terms']),
                $_POST['start_date'],
                $_POST['end_date'] ?: null,
                floatval($_POST['value']),
                floatval($_POST['royalty_percentage']),
                floatval($_POST['advance']),
                $_POST['status'],
                trim($_POST['signed_by']),
            ]);
            $message = "📄 Contrat <strong>".htmlspecialchars(trim($_POST['title']))."</strong> créé !";
            $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'edit_contract') {
        try {
            $db->prepare("
                UPDATE company_contracts
                SET artist_id=?,type=?,title=?,description=?,terms=?,start_date=?,end_date=?,
                    value=?,royalty_percentage=?,advance=?,status=?,signed_by=?
                WHERE id=? AND faction=?
            ")->execute([
                intval($_POST['artist_id']) ?: null,
                $_POST['type'],
                trim($_POST['title']),
                trim($_POST['description']),
                trim($_POST['terms']),
                $_POST['start_date'],
                $_POST['end_date'] ?: null,
                floatval($_POST['value']),
                floatval($_POST['royalty_percentage']),
                floatval($_POST['advance']),
                $_POST['status'],
                trim($_POST['signed_by']),
                intval($_POST['contract_id']),
                $companyFaction,
            ]);
            $message = 'Contrat mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'update_status') {
        try {
            $db->prepare("UPDATE company_contracts SET status=? WHERE id=? AND faction=?")
               ->execute([$_POST['status'], intval($_POST['contract_id']), $companyFaction]);
            $message = 'Statut mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'delete_contract') {
        try {
            $db->prepare("DELETE FROM company_contracts WHERE id=? AND faction=?")->execute([intval($_POST['contract_id']), $companyFaction]);
            $message = 'Contrat supprimé.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }
}

/* ══════════════════════════════════════════════════════
   DONNÉES
══════════════════════════════════════════════════════ */
$filterType   = $_GET['type']      ?? 'all';
$filterStatus = $_GET['status']    ?? 'all';
$filterArtist = intval($_GET['artist_id'] ?? 0);
$search       = trim($_GET['q']    ?? '');

$where = ["cc.faction=?"]; $params = [$companyFaction];
if ($filterType   !== 'all') { $where[]="cc.type=?";      $params[]=$filterType; }
if ($filterStatus !== 'all') { $where[]="cc.status=?";    $params[]=$filterStatus; }
if ($filterArtist)            { $where[]="cc.artist_id=?"; $params[]=$filterArtist; }
if ($search) {
    $where[]="(cc.title LIKE ? OR a.artist_name LIKE ? OR cc.signed_by LIKE ?)";
    $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%";
}

$stmt = $db->prepare("
    SELECT cc.*, a.artist_name,
        DATEDIFF(cc.end_date, NOW()) AS days_until_expiry
    FROM company_contracts cc
    LEFT JOIN company_artists a ON cc.artist_id=a.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY cc.start_date DESC
");
$stmt->execute($params); $contracts = $stmt->fetchAll();

// Stats
$stmt = $db->prepare("SELECT
    COUNT(*) t,
    SUM(status='active') a,
    SUM(status='draft') d,
    SUM(status='pending_signature') ps,
    SUM(status='expired') ex,
    SUM(status='terminated') ter,
    COALESCE(SUM(CASE WHEN status='active' THEN value END),0) active_val,
    COALESCE(SUM(CASE WHEN status='active' THEN advance END),0) active_adv
    FROM company_contracts WHERE faction=?");
$stmt->execute([$companyFaction]); $stats = $stmt->fetch();

// Expiration dans 30j
$stmt = $db->prepare("SELECT COUNT(*) FROM company_contracts WHERE faction=? AND status='active' AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)");
$stmt->execute([$companyFaction]); $expiringSoon = (int)$stmt->fetchColumn();

// Artistes pour select
$stmt = $db->prepare("SELECT id,artist_name FROM company_artists WHERE faction=? ORDER BY artist_name"); $stmt->execute([$companyFaction]); $artists = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
.contract-table .ct-row { display:grid; grid-template-columns:44px 1fr 110px 100px 110px 100px auto; align-items:center; gap:.85rem; padding:.8rem 1.1rem; border-bottom:1px solid var(--border); font-size:.83rem; transition:background .13s; }
.contract-table .ct-row:hover { background:rgba(255,255,255,.015); }
.contract-table .ct-row:last-child { border-bottom:none; }
.contract-table .ct-head { padding:.5rem 1.1rem; background:rgba(255,255,255,.022); border-bottom:1px solid var(--border); }
.contract-table .ct-head span { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:1.1px; color:var(--txt-3); }

.ct-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.ct-recording    { background:rgba(139,92,246,.15); }
.ct-management   { background:rgba(96,165,250,.15); }
.ct-distribution { background:rgba(34,197,94,.15);  }
.ct-publishing   { background:rgba(244,114,182,.15); }
.ct-licensing    { background:rgba(245,158,11,.15);  }
.ct-exclusivity  { background:rgba(239,68,68,.15);   }
.ct-production   { background:rgba(255,255,255,.07); }

.ct-title-name { font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ct-title-sub  { font-size:.7rem; color:var(--txt-3); margin-top:.08rem; }

.expiry-pill { font-size:.7rem; font-weight:700; white-space:nowrap; }
.expiry-ok   { color:var(--green); }
.expiry-warn { color:var(--yellow); }
.expiry-crit { color:var(--red); }

.ct-actions { display:flex; gap:.3rem; align-items:center; }

/* Detail panel */
.contract-detail { background:var(--bg-1); border:1px solid var(--border-c); border-radius:var(--radius); padding:1.3rem; margin-top:.3rem; display:none; }
</style>

<?php if($message):?>
<div class="alert alert-<?php echo $messageType;?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $message;?></div>
<?php endif;?>

<!-- Alerte expiration -->
<?php if($expiringSoon>0):?>
<div class="alert alert-warning mb-3">
    <i class="fas fa-exclamation-triangle"></i>
    <strong><?php echo $expiringSoon;?> contrat(s)</strong> expirent dans les 30 prochains jours. Pensez à les renouveler.
</div>
<?php endif;?>

<!-- KPI -->
<div class="kpi-grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));">
    <div class="kpi"><div class="kpi-label">Total Contrats</div><div class="kpi-value"><?php echo $stats['t'];?></div></div>
    <div class="kpi"><div class="kpi-label">Actifs</div><div class="kpi-value green"><?php echo $stats['a'];?></div></div>
    <div class="kpi"><div class="kpi-label">À signer</div><div class="kpi-value yellow"><?php echo $stats['ps'];?></div></div>
    <div class="kpi"><div class="kpi-label">Brouillons</div><div class="kpi-value blue"><?php echo $stats['d'];?></div></div>
    <div class="kpi"><div class="kpi-label">Valeur active</div><div class="kpi-value purple" style="font-size:1.2rem;">$<?php echo number_format($stats['active_val'],0,'.',' ');?></div></div>
    <div class="kpi"><div class="kpi-label">Avances en cours</div><div class="kpi-value yellow" style="font-size:1.2rem;">$<?php echo number_format($stats['active_adv'],0,'.',' ');?></div></div>
</div>

<!-- Toolbar -->
<div class="toolbar mb-2">
    <div class="f-center gap-2 f-wrap">
        <div class="filter-bar">
            <?php $types=['all'=>'Tous types','recording'=>'🎙 Enregistrement','management'=>'👔 Management','distribution'=>'📦 Distribution','publishing'=>'📝 Publishing','licensing'=>'🔑 Licensing','exclusivity'=>'🔒 Exclusivité','production'=>'🎚 Production'];
            foreach($types as $v=>$l){ $q=http_build_query(array_merge($_GET,['type'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterType===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <div class="filter-bar">
            <?php $statuses=['all'=>'Tous statuts','draft'=>'📝 Brouillon','pending_signature'=>'✍️ À signer','active'=>'✅ Actif','expired'=>'⏰ Expiré','terminated'=>'❌ Résilié','renewed'=>'♻️ Renouvelé'];
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
    <button onclick="Company.openModal('addContractModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau Contrat</button>
</div>
<div class="fs-xs txt-3 mb-2"><?php echo count($contracts);?> contrat(s) affiché(s)</div>

<!-- Table Contrats -->
<div class="card" style="padding:0;overflow:hidden;">
    <?php
    $ctIcons=['recording'=>['🎙','ct-recording'],'management'=>['👔','ct-management'],'distribution'=>['📦','ct-distribution'],'publishing'=>['📝','ct-publishing'],'licensing'=>['🔑','ct-licensing'],'exclusivity'=>['🔒','ct-exclusivity'],'production'=>['🎚','ct-production']];
    $ctTypeName=['recording'=>'Enregistrement','management'=>'Management','distribution'=>'Distribution','publishing'=>'Publishing','licensing'=>'Licensing','exclusivity'=>'Exclusivité','production'=>'Production'];
    $ctStatusBadge=['draft'=>['badge-gray','Brouillon'],'pending_signature'=>['badge-yellow','À signer'],'active'=>['badge-green','Actif'],'expired'=>['badge-red','Expiré'],'terminated'=>['badge-red','Résilié'],'renewed'=>['badge-blue','Renouvelé']];
    ?>

    <!-- En-tête -->
    <div class="ct-head" style="display:grid;grid-template-columns:44px 1fr 110px 100px 110px 100px auto;gap:.85rem;padding:.5rem 1.1rem;">
        <span></span><span>Contrat</span><span>Type</span><span>Valeur</span><span>Période</span><span>Statut</span><span></span>
    </div>

    <?php if(empty($contracts)):?>
    <div style="text-align:center;color:var(--txt-3);padding:3rem;">
        <i class="fas fa-file-contract" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i>
        <div style="font-weight:600;">Aucun contrat trouvé</div>
    </div>
    <?php else: foreach($contracts as $c):
        [$cIcon,$cIconCss]=$ctIcons[$c['type']]??['📄',''];
        [$cBadgeCss,$cBadgeLbl]=$ctStatusBadge[$c['status']]??['badge-gray',$c['status']];
        $cTypeName=$ctTypeName[$c['type']]??$c['type'];
        $daysLeft = $c['end_date'] ? (int)$c['days_until_expiry'] : null;
        $expiryClass = $daysLeft === null ? '' : ($daysLeft < 0 ? 'expiry-crit' : ($daysLeft <= 30 ? 'expiry-warn' : 'expiry-ok'));
    ?>
    <div class="ct-row">
        <div class="ct-icon <?php echo $cIconCss;?>"><?php echo $cIcon;?></div>
        <div>
            <div class="ct-title-name"><?php echo htmlspecialchars($c['title']);?></div>
            <div class="ct-title-sub">
                <?php echo htmlspecialchars($c['artist_name']??'—');?>
                <?php if($c['signed_by']):?> · Signé par : <?php echo htmlspecialchars($c['signed_by']);?><?php endif;?>
                <?php if($c['royalty_percentage']>0):?> · <?php echo $c['royalty_percentage'];?>% royalties<?php endif;?>
            </div>
        </div>
        <div>
            <span class="badge badge-purple"><?php echo $cTypeName;?></span>
        </div>
        <div>
            <div style="font-weight:700;font-size:.84rem;" class="txt-money">$<?php echo number_format($c['value'],0,'.',' ');?></div>
            <?php if($c['advance']>0):?><div style="font-size:.68rem;" class="txt-yellow">Avance : $<?php echo number_format($c['advance'],0);?></div><?php endif;?>
        </div>
        <div>
            <div style="font-size:.74rem;color:var(--txt-2);"><?php echo date('d/m/Y',strtotime($c['start_date']));?></div>
            <?php if($c['end_date']):?>
            <div class="expiry-pill <?php echo $expiryClass;?>">
                <?php if($daysLeft < 0): ?>⚠️ Expiré il y a <?php echo abs($daysLeft);?>j
                <?php elseif($daysLeft <= 30): ?>⚠️ <?php echo $daysLeft;?>j restants
                <?php else: ?>✓ <?php echo date('d/m/Y',strtotime($c['end_date']));?>
                <?php endif;?>
            </div>
            <?php else:?><div style="font-size:.7rem;color:var(--txt-3);">Sans fin</div><?php endif;?>
        </div>
        <div><span class="badge <?php echo $cBadgeCss;?> badge-dot"><?php echo $cBadgeLbl;?></span></div>
        <div class="ct-actions">
            <!-- Changement statut rapide -->
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="contract_id" value="<?php echo $c['id'];?>">
                <select name="status" onchange="this.form.submit()" class="form-select" style="padding:.26rem .5rem;font-size:.7rem;width:auto;">
                    <option value="draft"             <?php echo $c['status']==='draft'             ?'selected':'';?>>Brouillon</option>
                    <option value="pending_signature" <?php echo $c['status']==='pending_signature' ?'selected':'';?>>À signer</option>
                    <option value="active"            <?php echo $c['status']==='active'            ?'selected':'';?>>Actif</option>
                    <option value="expired"           <?php echo $c['status']==='expired'           ?'selected':'';?>>Expiré</option>
                    <option value="terminated"        <?php echo $c['status']==='terminated'        ?'selected':'';?>>Résilié</option>
                    <option value="renewed"           <?php echo $c['status']==='renewed'           ?'selected':'';?>>Renouvelé</option>
                </select>
            </form>
            <button onclick="openEditContract(<?php echo htmlspecialchars(json_encode($c));?>)" class="btn btn-ghost btn-xs"><i class="fas fa-edit"></i></button>
            <form method="POST" data-confirm="Supprimer ce contrat ?" style="display:inline;">
                <input type="hidden" name="action" value="delete_contract">
                <input type="hidden" name="contract_id" value="<?php echo $c['id'];?>">
                <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; endif;?>
</div>

<!-- MODAL AJOUTER CONTRAT -->
<div id="addContractModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-file-contract"></i> Nouveau Contrat</h2><button class="modal-close" onclick="Company.closeModal('addContractModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_contract">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre du contrat *</label><input type="text" name="title" class="form-input" required placeholder="Ex : Contrat d'enregistrement exclusif — Artiste X"></div>
                    <div class="form-group"><label class="form-label">Artiste lié</label>
                        <select name="artist_id" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Type de contrat *</label>
                        <select name="type" class="form-select" required>
                            <option value="recording">🎙 Enregistrement</option><option value="management">👔 Management</option>
                            <option value="distribution">📦 Distribution</option><option value="publishing">📝 Publishing</option>
                            <option value="licensing">🔑 Licensing</option><option value="exclusivity">🔒 Exclusivité</option>
                            <option value="production">🎚 Production</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" class="form-select" required>
                            <option value="draft">Brouillon</option><option value="pending_signature">En attente signature</option>
                            <option value="active">Actif</option><option value="expired">Expiré</option>
                            <option value="terminated">Résilié</option><option value="renewed">Renouvelé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Date de début *</label><input type="date" name="start_date" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
                    <div class="form-group"><label class="form-label">Date de fin</label><input type="date" name="end_date" class="form-input"><span class="form-hint">Laisser vide pour sans durée déterminée</span></div>
                    <div class="form-group"><label class="form-label">Valeur ($)</label><input type="number" name="value" class="form-input" value="0" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">% Royalties</label><input type="number" name="royalty_percentage" class="form-input" value="0" min="0" max="100" step="0.5"></div>
                    <div class="form-group"><label class="form-label">Avance ($)</label><input type="number" name="advance" class="form-input" value="0" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Signé par</label><input type="text" name="signed_by" class="form-input" placeholder="Nom du responsable"></div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Résumé du contrat…"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Termes & Conditions</label><textarea name="terms" class="form-textarea" style="min-height:100px;" placeholder="Détails des clauses contractuelles…"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('addContractModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</button></div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER CONTRAT -->
<div id="editContractModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-edit"></i> Modifier le Contrat</h2><button class="modal-close" onclick="Company.closeModal('editContractModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_contract">
            <input type="hidden" name="contract_id" id="ec_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" id="ec_title" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Artiste</label>
                        <select name="artist_id" id="ec_artist" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" id="ec_type" class="form-select" required>
                            <option value="recording">🎙 Enregistrement</option><option value="management">👔 Management</option>
                            <option value="distribution">📦 Distribution</option><option value="publishing">📝 Publishing</option>
                            <option value="licensing">🔑 Licensing</option><option value="exclusivity">🔒 Exclusivité</option>
                            <option value="production">🎚 Production</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Statut *</label>
                        <select name="status" id="ec_status" class="form-select" required>
                            <option value="draft">Brouillon</option><option value="pending_signature">En attente signature</option>
                            <option value="active">Actif</option><option value="expired">Expiré</option>
                            <option value="terminated">Résilié</option><option value="renewed">Renouvelé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Début *</label><input type="date" name="start_date" id="ec_start" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Fin</label><input type="date" name="end_date" id="ec_end" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Valeur ($)</label><input type="number" name="value" id="ec_value" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">% Royalties</label><input type="number" name="royalty_percentage" id="ec_royalty" class="form-input" min="0" max="100" step="0.5"></div>
                    <div class="form-group"><label class="form-label">Avance ($)</label><input type="number" name="advance" id="ec_advance" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Signé par</label><input type="text" name="signed_by" id="ec_signed" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" id="ec_desc" class="form-textarea"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Termes & Conditions</label><textarea name="terms" id="ec_terms" class="form-textarea" style="min-height:100px;"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('editContractModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<script>
function openEditContract(d) {
    document.getElementById('ec_id').value      = d.id;
    document.getElementById('ec_title').value   = d.title              || '';
    document.getElementById('ec_artist').value  = d.artist_id          || '';
    document.getElementById('ec_type').value    = d.type               || 'recording';
    document.getElementById('ec_status').value  = d.status             || 'draft';
    document.getElementById('ec_start').value   = d.start_date         || '';
    document.getElementById('ec_end').value     = d.end_date           || '';
    document.getElementById('ec_value').value   = d.value              || 0;
    document.getElementById('ec_royalty').value = d.royalty_percentage || 0;
    document.getElementById('ec_advance').value = d.advance            || 0;
    document.getElementById('ec_signed').value  = d.signed_by          || '';
    document.getElementById('ec_desc').value    = d.description        || '';
    document.getElementById('ec_terms').value   = d.terms              || '';
    Company.openModal('editContractModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>