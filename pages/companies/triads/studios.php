<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Triads Records';
$companyColor   = '#8b5cf6';
$companyFaction = 'TRIADS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Studios';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

/* ══════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Ajouter studio
    if ($action === 'add_studio') {
        try {
            $db->prepare("INSERT INTO company_studios (faction,name,description,hourly_rate,capacity,equipment,status) VALUES (?,?,?,?,?,?,?)")
               ->execute([$companyFaction, trim($_POST['name']), trim($_POST['description']), floatval($_POST['hourly_rate']), intval($_POST['capacity']), trim($_POST['equipment']), $_POST['status']]);
            $message = "🎚 Studio <strong>".htmlspecialchars(trim($_POST['name']))."</strong> créé !";
            $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    // Modifier studio
    if ($action === 'edit_studio') {
        try {
            $db->prepare("UPDATE company_studios SET name=?,description=?,hourly_rate=?,capacity=?,equipment=?,status=? WHERE id=? AND faction=?")
               ->execute([trim($_POST['name']), trim($_POST['description']), floatval($_POST['hourly_rate']), intval($_POST['capacity']), trim($_POST['equipment']), $_POST['status'], intval($_POST['studio_id']), $companyFaction]);
            $message = 'Studio mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    // Supprimer studio
    if ($action === 'delete_studio') {
        try {
            $db->prepare("DELETE FROM company_studios WHERE id=? AND faction=?")->execute([intval($_POST['studio_id']), $companyFaction]);
            $message = 'Studio supprimé.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    // Créer réservation
    if ($action === 'add_booking') {
        try {
            $start = $_POST['start_datetime'];
            $end   = $_POST['end_datetime'];
            // Calcul coût auto
            $stmtRate = $db->prepare("SELECT hourly_rate FROM company_studios WHERE id=? AND faction=?");
            $stmtRate->execute([intval($_POST['studio_id']), $companyFaction]);
            $rate = (float)($stmtRate->fetchColumn() ?: 0);
            $hours = (strtotime($end) - strtotime($start)) / 3600;
            $cost  = $_POST['cost'] ? floatval($_POST['cost']) : $rate * max(0, $hours);

            // Vérif conflit
            $stmtConf = $db->prepare("
                SELECT COUNT(*) FROM company_studio_bookings
                WHERE studio_id=? AND status NOT IN ('cancelled')
                AND NOT (end_datetime <= ? OR start_datetime >= ?)
            ");
            $stmtConf->execute([intval($_POST['studio_id']), $start, $end]);
            if ($stmtConf->fetchColumn() > 0) {
                $message = '⚠️ Conflit de réservation : le studio est déjà occupé sur ce créneau.'; $messageType = 'error';
            } else {
                $db->prepare("INSERT INTO company_studio_bookings (studio_id,faction,artist_id,title,start_datetime,end_datetime,engineer,cost,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([intval($_POST['studio_id']), $companyFaction, intval($_POST['artist_id']) ?: null, trim($_POST['title']), $start, $end, trim($_POST['engineer']), $cost, $_POST['booking_status'], trim($_POST['notes'])]);
                $message = '📅 Réservation créée !'; $messageType = 'success';
            }
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    // Changer statut réservation
    if ($action === 'update_booking_status') {
        try {
            $db->prepare("UPDATE company_studio_bookings SET status=? WHERE id=? AND faction=?")
               ->execute([$_POST['booking_status'], intval($_POST['booking_id']), $companyFaction]);
            $message = 'Statut mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    // Supprimer réservation
    if ($action === 'delete_booking') {
        try {
            $db->prepare("DELETE FROM company_studio_bookings WHERE id=? AND faction=?")->execute([intval($_POST['booking_id']), $companyFaction]);
            $message = 'Réservation supprimée.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }
}

/* ══════════════════════════════════════════════════════
   DONNÉES
══════════════════════════════════════════════════════ */
$filterStatus = $_GET['status'] ?? 'all';

// Studios
$stmt = $db->prepare("
    SELECT s.*,
        (SELECT COUNT(*) FROM company_studio_bookings b WHERE b.studio_id=s.id AND b.status='confirmed' AND b.start_datetime >= NOW()) AS upcoming,
        (SELECT COUNT(*) FROM company_studio_bookings b WHERE b.studio_id=s.id AND b.status='in_progress') AS active_now,
        (SELECT COALESCE(SUM(b.cost),0) FROM company_studio_bookings b WHERE b.studio_id=s.id AND b.status='completed') AS total_revenue
    FROM company_studios s WHERE s.faction=? ORDER BY s.name
");
$stmt->execute([$companyFaction]); $studios = $stmt->fetchAll();

// Réservations
$bWhere = ["b.faction=?"]; $bParams = [$companyFaction];
if ($filterStatus !== 'all') { $bWhere[]="b.status=?"; $bParams[]=$filterStatus; }

$stmt = $db->prepare("
    SELECT b.*, s.name AS studio_name, s.hourly_rate, a.artist_name
    FROM company_studio_bookings b
    LEFT JOIN company_studios s ON b.studio_id=s.id
    LEFT JOIN company_artists a ON b.artist_id=a.id
    WHERE ".implode(' AND ',$bWhere)."
    ORDER BY b.start_datetime DESC
    LIMIT 80
");
$stmt->execute($bParams); $bookings = $stmt->fetchAll();

// Stats réservations
$stmt = $db->prepare("SELECT COUNT(*) t, SUM(status='confirmed') c, SUM(status='in_progress') ip, SUM(status='completed') done, COALESCE(SUM(CASE WHEN status='completed' THEN cost END),0) rev FROM company_studio_bookings WHERE faction=?");
$stmt->execute([$companyFaction]); $bStats = $stmt->fetch();

// Artistes pour select
$stmt = $db->prepare("SELECT id,artist_name FROM company_artists WHERE faction=? ORDER BY artist_name"); $stmt->execute([$companyFaction]); $artists = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* Studios grid */
.studio-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1.1rem; margin-bottom:1.5rem; }

.scard { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; transition:var(--tr); }
.scard:hover { border-color:var(--border-c); transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.2); }

.scard-head { padding:1.1rem 1.2rem .85rem; }
.scard-icon { width:44px;height:44px; border-radius:12px; display:flex;align-items:center;justify-content:center; font-size:1.25rem; flex-shrink:0; }
.scard-icon.available  { background:rgba(34,197,94,.15); }
.scard-icon.occupied   { background:rgba(245,158,11,.15); }
.scard-icon.maintenance{ background:rgba(239,68,68,.15); }

.scard-name { font-family:var(--font-d); font-size:1rem; font-weight:800; }
.scard-desc { font-size:.74rem; color:var(--txt-3); margin-top:.15rem; }

.scard-stats { display:grid; grid-template-columns:repeat(3,1fr); border-top:1px solid var(--border); }
.ss-item { padding:.6rem; text-align:center; border-right:1px solid var(--border); }
.ss-item:last-child { border-right:none; }
.ss-val { font-family:var(--font-d); font-size:.95rem; font-weight:800; }
.ss-lbl { font-size:.58rem; text-transform:uppercase; letter-spacing:.8px; color:var(--txt-3); margin-top:.06rem; }

.scard-foot { padding:.75rem 1.2rem; border-top:1px solid var(--border); background:rgba(0,0,0,.08); display:flex; gap:.4rem; }

/* Booking list */
.bk-row { display:grid; grid-template-columns:140px 1fr 120px 90px auto; align-items:center; gap:1rem; padding:.75rem 1.1rem; border-bottom:1px solid var(--border); font-size:.83rem; }
.bk-row:last-child { border-bottom:none; }
.bk-row:hover { background:rgba(255,255,255,.015); }

.bk-date { font-size:.78rem; }
.bk-date .bk-d1 { font-weight:700; color:var(--txt); }
.bk-date .bk-d2 { color:var(--txt-3); margin-top:.1rem; }

.bk-info .bk-title  { font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bk-info .bk-sub    { font-size:.72rem; color:var(--txt-3); margin-top:.1rem; }

.bk-cost { font-weight:700; color:var(--green); text-align:right; }
.bk-actions { display:flex; gap:.3rem; }
</style>

<?php if($message):?>
<div class="alert alert-<?php echo $messageType;?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $message;?></div>
<?php endif;?>

<!-- KPI -->
<div class="kpi-grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));">
    <div class="kpi"><div class="kpi-label">Studios</div><div class="kpi-value"><?php echo count($studios);?></div></div>
    <div class="kpi"><div class="kpi-label">Réservations</div><div class="kpi-value"><?php echo $bStats['t'];?></div></div>
    <div class="kpi"><div class="kpi-label">Confirmées</div><div class="kpi-value blue"><?php echo $bStats['c'];?></div></div>
    <div class="kpi"><div class="kpi-label">En cours</div><div class="kpi-value yellow"><?php echo $bStats['ip'];?></div></div>
    <div class="kpi"><div class="kpi-label">Terminées</div><div class="kpi-value green"><?php echo $bStats['done'];?></div></div>
    <div class="kpi"><div class="kpi-label">Revenus Studios</div><div class="kpi-value purple" style="font-size:1.25rem;">$<?php echo number_format($bStats['rev'],0,'.',' ');?></div></div>
</div>

<!-- Toolbar Studios -->
<div class="toolbar mb-3">
    <div class="card-title"><i class="fas fa-headphones"></i> Nos Studios</div>
    <button onclick="Company.openModal('addStudioModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau Studio</button>
</div>

<!-- Grille Studios -->
<?php if(empty($studios)):?>
<div class="card mb-3" style="text-align:center;padding:2.5rem;color:var(--txt-3);">
    <i class="fas fa-headphones" style="font-size:2rem;margin-bottom:.8rem;display:block;"></i>
    <div style="font-weight:600;">Aucun studio enregistré</div>
</div>
<?php else:
$statusCfg=[
    'available'  =>['Disponible','badge-green','🟢'],
    'occupied'   =>['Occupé',   'badge-yellow','🟡'],
    'maintenance'=>['Maintenance','badge-red', '🔴'],
];
?>
<div class="studio-grid">
<?php foreach($studios as $s):
    [$slbl,$scss,$sEmoji]=$statusCfg[$s['status']]??[$s['status'],'badge-gray','⚪'];
    $iconClass=$s['status']==='available'?'available':($s['status']==='occupied'?'occupied':'maintenance');
?>
<div class="scard">
    <div class="scard-head">
        <div class="f-between mb-2">
            <div class="f-center gap-2" style="min-width:0;">
                <div class="scard-icon <?php echo $iconClass;?>">🎚️</div>
                <div style="min-width:0;">
                    <div class="scard-name"><?php echo htmlspecialchars($s['name']);?></div>
                    <div class="scard-desc"><?php echo htmlspecialchars(mb_substr($s['description']?:'',0,60));?></div>
                </div>
            </div>
            <span class="badge <?php echo $scss;?> badge-dot" style="flex-shrink:0;"><?php echo $slbl;?></span>
        </div>
        <div class="f-center gap-2 mt-1" style="font-size:.76rem; color:var(--txt-2);">
            <span><i class="fas fa-dollar-sign txt-3"></i> <strong>$<?php echo number_format($s['hourly_rate'],0);?>/h</strong></span>
            <span>·</span>
            <span><i class="fas fa-users txt-3"></i> <?php echo $s['capacity'];?> pers. max</span>
            <?php if($s['active_now']>0):?><span>·</span><span style="color:var(--yellow);">🎙️ En session</span><?php endif;?>
        </div>
        <?php if($s['equipment']):?>
        <div style="margin-top:.55rem;font-size:.72rem;color:var(--txt-3);padding:.5rem .65rem;background:rgba(255,255,255,.03);border-radius:var(--radius-sm);border:1px solid var(--border);">
            🎛️ <?php echo htmlspecialchars(mb_substr($s['equipment'],0,100));?>
        </div>
        <?php endif;?>
    </div>
    <div class="scard-stats">
        <div class="ss-item"><div class="ss-val txt-blue"><?php echo $s['upcoming'];?></div><div class="ss-lbl">À venir</div></div>
        <div class="ss-item"><div class="ss-val txt-yellow"><?php echo $s['active_now'];?></div><div class="ss-lbl">En cours</div></div>
        <div class="ss-item"><div class="ss-val txt-money" style="font-size:.8rem;">$<?php echo number_format($s['total_revenue'],0);?></div><div class="ss-lbl">CA Total</div></div>
    </div>
    <div class="scard-foot">
        <button onclick="openReserve(<?php echo $s['id'];?>, <?php echo htmlspecialchars(json_encode($s['name']));?>, <?php echo $s['hourly_rate'];?>)" class="btn btn-primary btn-sm" style="flex:1;"><i class="fas fa-calendar-plus"></i> Réserver</button>
        <button onclick="openEditStudio(<?php echo htmlspecialchars(json_encode($s));?>)" class="btn btn-ghost btn-sm"><i class="fas fa-edit"></i></button>
        <form method="POST" data-confirm="Supprimer ce studio ?" style="display:inline;">
            <input type="hidden" name="action" value="delete_studio">
            <input type="hidden" name="studio_id" value="<?php echo $s['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- Réservations -->
<div class="toolbar mb-2">
    <div class="f-center gap-2">
        <div class="card-title"><i class="fas fa-calendar-alt"></i> Réservations</div>
        <div class="filter-bar">
            <?php $bStatuses=['all'=>'Toutes','confirmed'=>'✅ Confirmées','in_progress'=>'🎙️ En cours','completed'=>'✔️ Terminées','cancelled'=>'❌ Annulées'];
            foreach($bStatuses as $v=>$l){ $q=http_build_query(array_merge($_GET,['status'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterStatus===$v?'active':'')."'>$l</a>"; }?>
        </div>
    </div>
    <span class="fs-xs txt-3"><?php echo count($bookings);?> réservation(s)</span>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <?php if(empty($bookings)):?>
    <div style="text-align:center;color:var(--txt-3);padding:2.5rem;">
        <i class="fas fa-calendar" style="font-size:2rem;margin-bottom:.8rem;display:block;"></i>
        <div style="font-weight:600;">Aucune réservation</div>
    </div>
    <?php else:
    $bkBadge=[
        'confirmed'  =>['badge-blue',  'Confirmée'],
        'in_progress'=>['badge-yellow','En cours'],
        'completed'  =>['badge-green', 'Terminée'],
        'cancelled'  =>['badge-red',   'Annulée'],
    ];
    foreach($bookings as $b):
        [$bCss,$bLbl]=$bkBadge[$b['status']]??['badge-gray',$b['status']];
        $start=new DateTime($b['start_datetime']); $end=new DateTime($b['end_datetime']);
        $hours=round(($end->getTimestamp()-$start->getTimestamp())/3600,1);
    ?>
    <div class="bk-row">
        <div class="bk-date">
            <div class="bk-d1"><?php echo $start->format('d/m/Y H:i');?></div>
            <div class="bk-d2">→ <?php echo $end->format('H:i');?> (<?php echo $hours;?>h)</div>
        </div>
        <div class="bk-info">
            <div class="bk-title"><?php echo htmlspecialchars($b['title']);?></div>
            <div class="bk-sub">
                🎚️ <?php echo htmlspecialchars($b['studio_name']??'—');?>
                <?php if($b['artist_name']):?> · 🎤 <?php echo htmlspecialchars($b['artist_name']);?><?php endif;?>
                <?php if($b['engineer']):?> · 🎛️ <?php echo htmlspecialchars($b['engineer']);?><?php endif;?>
            </div>
        </div>
        <div class="bk-cost">$<?php echo number_format($b['cost'],2,'.',' ');?></div>
        <span class="badge <?php echo $bCss;?> badge-dot"><?php echo $bLbl;?></span>
        <div class="bk-actions">
            <form method="POST" style="display:inline;" class="f-center gap-1">
                <input type="hidden" name="action" value="update_booking_status">
                <input type="hidden" name="booking_id" value="<?php echo $b['id'];?>">
                <select name="booking_status" onchange="this.form.submit()" class="form-select" style="padding:.28rem .55rem;font-size:.72rem;width:auto;">
                    <option value="confirmed"   <?php echo $b['status']==='confirmed'   ?'selected':'';?>>Confirmée</option>
                    <option value="in_progress" <?php echo $b['status']==='in_progress' ?'selected':'';?>>En cours</option>
                    <option value="completed"   <?php echo $b['status']==='completed'   ?'selected':'';?>>Terminée</option>
                    <option value="cancelled"   <?php echo $b['status']==='cancelled'   ?'selected':'';?>>Annulée</option>
                </select>
            </form>
            <form method="POST" data-confirm="Supprimer cette réservation ?" style="display:inline;">
                <input type="hidden" name="action" value="delete_booking">
                <input type="hidden" name="booking_id" value="<?php echo $b['id'];?>">
                <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach;?>
    <?php endif;?>
</div>

<!-- ════ MODAL AJOUTER STUDIO ════ -->
<div id="addStudioModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-head"><h2><i class="fas fa-headphones"></i> Nouveau Studio</h2><button class="modal-close" onclick="Company.closeModal('addStudioModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_studio">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Nom du Studio *</label><input type="text" name="name" class="form-input" required placeholder="Ex : Studio A — Red Room"></div>
                    <div class="form-group"><label class="form-label">Tarif horaire ($) *</label><input type="number" name="hourly_rate" class="form-input" value="150" min="0" step="0.01" required></div>
                    <div class="form-group"><label class="form-label">Capacité (personnes)</label><input type="number" name="capacity" class="form-input" value="5" min="1"></div>
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="status" class="form-select"><option value="available">Disponible</option><option value="occupied">Occupé</option><option value="maintenance">Maintenance</option></select>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Description du studio…"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Équipements</label><textarea name="equipment" class="form-textarea" placeholder="Liste des équipements, consoles, microphones…" style="min-height:65px;"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('addStudioModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</button></div>
        </form>
    </div>
</div>

<!-- ════ MODAL MODIFIER STUDIO ════ -->
<div id="editStudioModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-head"><h2><i class="fas fa-edit"></i> Modifier le Studio</h2><button class="modal-close" onclick="Company.closeModal('editStudioModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_studio">
            <input type="hidden" name="studio_id" id="es_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Nom *</label><input type="text" name="name" id="es_name" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Tarif horaire ($)</label><input type="number" name="hourly_rate" id="es_rate" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Capacité</label><input type="number" name="capacity" id="es_cap" class="form-input" min="1"></div>
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="status" id="es_status" class="form-select"><option value="available">Disponible</option><option value="occupied">Occupé</option><option value="maintenance">Maintenance</option></select>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" id="es_desc" class="form-textarea"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Équipements</label><textarea name="equipment" id="es_equip" class="form-textarea" style="min-height:65px;"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('editStudioModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- ════ MODAL RÉSERVATION ════ -->
<div id="reserveModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-calendar-plus"></i> Nouvelle Réservation</h2><button class="modal-close" onclick="Company.closeModal('reserveModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_booking">
            <input type="hidden" name="studio_id" id="rv_studio_id">
            <div class="modal-body">
                <div id="rv_studio_name" style="font-family:var(--font-d);font-weight:800;font-size:1rem;margin-bottom:1rem;color:var(--c-lt);display:flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-headphones"></i>
                </div>
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre de la session *</label><input type="text" name="title" class="form-input" required placeholder="Ex : Enregistrement Album XYZ"></div>
                    <div class="form-group"><label class="form-label">Artiste</label>
                        <select name="artist_id" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Ingénieur du son</label><input type="text" name="engineer" class="form-input" placeholder="Nom de l'ingénieur"></div>
                    <div class="form-group"><label class="form-label">Début *</label><input type="datetime-local" name="start_datetime" id="rv_start" class="form-input" required onchange="calcCost()"></div>
                    <div class="form-group"><label class="form-label">Fin *</label><input type="datetime-local" name="end_datetime" id="rv_end" class="form-input" required onchange="calcCost()"></div>
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="booking_status" class="form-select"><option value="confirmed">Confirmée</option><option value="in_progress">En cours</option></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Coût estimé ($)</label>
                        <input type="number" name="cost" id="rv_cost" class="form-input" min="0" step="0.01" placeholder="Calculé auto">
                        <span class="form-hint" id="rv_cost_hint"></span>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" style="min-height:60px;" placeholder="Notes sur la session…"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('reserveModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Réserver</button></div>
        </form>
    </div>
</div>

<script>
let _rvRate = 0;

function openReserve(id, name, rate) {
    _rvRate = parseFloat(rate) || 0;
    document.getElementById('rv_studio_id').value = id;
    document.getElementById('rv_studio_name').innerHTML = '<i class="fas fa-headphones"></i> ' + name;
    document.getElementById('rv_cost').value = '';
    document.getElementById('rv_cost_hint').textContent = 'Tarif : $' + rate + '/h';
    Company.openModal('reserveModal');
}

function calcCost() {
    const s = document.getElementById('rv_start').value;
    const e = document.getElementById('rv_end').value;
    if (!s || !e || !_rvRate) return;
    const hours = (new Date(e) - new Date(s)) / 3600000;
    if (hours > 0) {
        const cost = (hours * _rvRate).toFixed(2);
        document.getElementById('rv_cost').value = cost;
        document.getElementById('rv_cost_hint').textContent = hours.toFixed(1) + 'h × $' + _rvRate + '/h = $' + cost;
    }
}

function openEditStudio(d) {
    document.getElementById('es_id').value     = d.id;
    document.getElementById('es_name').value   = d.name        || '';
    document.getElementById('es_rate').value   = d.hourly_rate || 0;
    document.getElementById('es_cap').value    = d.capacity    || 1;
    document.getElementById('es_status').value = d.status      || 'available';
    document.getElementById('es_desc').value   = d.description || '';
    document.getElementById('es_equip').value  = d.equipment   || '';
    Company.openModal('editStudioModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>