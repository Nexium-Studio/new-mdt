<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Triads Records';
$companyColor   = '#8b5cf6';
$companyFaction = 'TRIADS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Événements';
$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

/* ══════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_event') {
        try {
            $tickets_sold = intval($_POST['tickets_sold']);
            $ticket_price = floatval($_POST['ticket_price']);
            $revenue = $_POST['revenue'] ? floatval($_POST['revenue']) : ($tickets_sold * $ticket_price);
            $db->prepare("
                INSERT INTO company_events
                    (faction,title,type,artist_id,location,event_date,start_time,end_time,capacity,tickets_sold,ticket_price,revenue,cost,status,description,organizer)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $companyFaction, trim($_POST['title']), $_POST['type'],
                intval($_POST['artist_id']) ?: null,
                trim($_POST['location']), $_POST['event_date'], $_POST['start_time'], $_POST['end_time'] ?: null,
                intval($_POST['capacity']), $tickets_sold, $ticket_price, $revenue,
                floatval($_POST['cost']), $_POST['status'],
                trim($_POST['description']), trim($_POST['organizer']),
            ]);
            $message = "🎉 Événement <strong>".htmlspecialchars(trim($_POST['title']))."</strong> créé !";
            $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'edit_event') {
        try {
            $tickets_sold = intval($_POST['tickets_sold']);
            $ticket_price = floatval($_POST['ticket_price']);
            $revenue = $_POST['revenue'] ? floatval($_POST['revenue']) : ($tickets_sold * $ticket_price);
            $db->prepare("
                UPDATE company_events
                SET title=?,type=?,artist_id=?,location=?,event_date=?,start_time=?,end_time=?,
                    capacity=?,tickets_sold=?,ticket_price=?,revenue=?,cost=?,status=?,description=?,organizer=?
                WHERE id=? AND faction=?
            ")->execute([
                trim($_POST['title']), $_POST['type'], intval($_POST['artist_id']) ?: null,
                trim($_POST['location']), $_POST['event_date'], $_POST['start_time'], $_POST['end_time'] ?: null,
                intval($_POST['capacity']), $tickets_sold, $ticket_price, $revenue,
                floatval($_POST['cost']), $_POST['status'],
                trim($_POST['description']), trim($_POST['organizer']),
                intval($_POST['event_id']), $companyFaction,
            ]);
            $message = 'Événement mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'update_tickets') {
        try {
            $ts = intval($_POST['tickets_sold']);
            $tp = floatval($_POST['ticket_price']);
            $rev = $ts * $tp;
            $db->prepare("UPDATE company_events SET tickets_sold=?,ticket_price=?,revenue=? WHERE id=? AND faction=?")
               ->execute([$ts, $tp, $rev, intval($_POST['event_id']), $companyFaction]);
            $message = 'Billetterie mise à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'update_status') {
        try {
            $db->prepare("UPDATE company_events SET status=? WHERE id=? AND faction=?")
               ->execute([$_POST['status'], intval($_POST['event_id']), $companyFaction]);
            $message = 'Statut mis à jour.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }

    if ($action === 'delete_event') {
        try {
            $db->prepare("DELETE FROM company_events WHERE id=? AND faction=?")->execute([intval($_POST['event_id']), $companyFaction]);
            $message = 'Événement supprimé.'; $messageType = 'success';
        } catch (Exception $e) { $message='Erreur : '.$e->getMessage(); $messageType='error'; }
    }
}

/* ══════════════════════════════════════════════════════
   DONNÉES
══════════════════════════════════════════════════════ */
$filterType   = $_GET['type']   ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterPeriod = $_GET['period'] ?? 'upcoming';
$search       = trim($_GET['q'] ?? '');

$where = ["e.faction=?"]; $params = [$companyFaction];
if ($filterType   !== 'all') { $where[]="e.type=?";   $params[]=$filterType; }
if ($filterStatus !== 'all') { $where[]="e.status=?"; $params[]=$filterStatus; }
if ($filterPeriod === 'upcoming') { $where[]="e.event_date >= CURDATE()"; }
elseif ($filterPeriod === 'past') { $where[]="e.event_date < CURDATE()"; }
if ($search) {
    $where[]="(e.title LIKE ? OR e.location LIKE ? OR a.artist_name LIKE ?)";
    $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%";
}

$orderBy = $filterPeriod === 'past' ? 'e.event_date DESC' : 'e.event_date ASC';

$stmt = $db->prepare("
    SELECT e.*, a.artist_name,
        (e.revenue - e.cost) AS profit,
        DATEDIFF(e.event_date, CURDATE()) AS days_until
    FROM company_events e
    LEFT JOIN company_artists a ON e.artist_id=a.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY $orderBy
    LIMIT 100
");
$stmt->execute($params); $events = $stmt->fetchAll();

// Stats
$stmt = $db->prepare("SELECT
    COUNT(*) t,
    SUM(e.event_date >= CURDATE() AND status NOT IN ('cancelled')) upcoming,
    SUM(status='completed') done,
    COALESCE(SUM(CASE WHEN status='completed' THEN revenue END),0) total_rev,
    COALESCE(SUM(CASE WHEN status='completed' THEN revenue-cost END),0) total_profit,
    COALESCE(SUM(CASE WHEN status='completed' THEN tickets_sold END),0) total_tickets
    FROM company_events e WHERE faction=?");
$stmt->execute([$companyFaction]); $stats = $stmt->fetch();

// Artistes pour select
$stmt = $db->prepare("SELECT id,artist_name FROM company_artists WHERE faction=? ORDER BY artist_name"); $stmt->execute([$companyFaction]); $artists = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
/* Events layout */
.ev-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:1.1rem; }

.ev-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; transition:var(--tr); }
.ev-card:hover { border-color:var(--border-c); transform:translateY(-2px); box-shadow:0 10px 28px rgba(0,0,0,.22); }
.ev-card.past  { opacity:.7; }

.ev-banner {
    height: 5px;
    background: linear-gradient(90deg,var(--c),var(--c-lt));
}
.ev-banner.concert      { background: linear-gradient(90deg, #8b5cf6, #6d28d9); }
.ev-banner.festival     { background: linear-gradient(90deg, #f59e0b, #d97706); }
.ev-banner.showcase     { background: linear-gradient(90deg, #60a5fa, #2563eb); }
.ev-banner.release_party{ background: linear-gradient(90deg, #f472b6, #db2777); }
.ev-banner.listening_party{ background:linear-gradient(90deg, #34d399, #059669); }

.ev-card-body { padding:1.1rem 1.2rem; }

.ev-date-badge {
    min-width:52px;
    background: linear-gradient(135deg, var(--c), var(--c-dk));
    border-radius:11px; padding:.4rem .6rem; text-align:center; flex-shrink:0;
    box-shadow:0 3px 12px var(--c-dim);
}
.ev-date-badge.past-badge { background: rgba(100,116,139,.2); box-shadow:none; }
.ev-date-badge .edb-day { font-family:var(--font-d); font-size:1.3rem; font-weight:800; line-height:1; color:#fff; }
.ev-date-badge.past-badge .edb-day { color:var(--txt-3); }
.ev-date-badge .edb-mon { font-size:.6rem; text-transform:uppercase; letter-spacing:1px; color:rgba(255,255,255,.75); }
.ev-date-badge.past-badge .edb-mon { color:var(--txt-3); }

.ev-title { font-family:var(--font-d); font-size:.98rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ev-sub   { font-size:.72rem; color:var(--txt-3); margin-top:.1rem; }

.ev-countdown { font-size:.72rem; font-weight:700; padding:.18rem .5rem; border-radius:20px; }
.ev-countdown.soon   { background:rgba(245,158,11,.15); color:var(--yellow); }
.ev-countdown.today  { background:rgba(34,197,94,.15);  color:var(--green); }
.ev-countdown.past   { background:rgba(100,116,139,.12); color:var(--txt-3); }
.ev-countdown.future { background:rgba(96,165,250,.12);  color:var(--blue); }

.ev-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:.35rem; margin:.85rem 0; }
.ev-stat { background:rgba(255,255,255,.025); border:1px solid var(--border); border-radius:var(--radius-sm); padding:.45rem .5rem; text-align:center; }
.ev-stat-v { font-family:var(--font-d); font-size:.88rem; font-weight:800; }
.ev-stat-l { font-size:.58rem; text-transform:uppercase; letter-spacing:.7px; color:var(--txt-3); margin-top:.06rem; }

.ev-fill { margin-top:.75rem; }
.ev-fill-label { display:flex; justify-content:space-between; font-size:.7rem; margin-bottom:.3rem; }
.ev-fill-label span { color:var(--txt-3); }
.ev-fill-label strong { color:var(--txt-2); }

.ev-info-row { display:flex; align-items:center; gap:.4rem; font-size:.74rem; color:var(--txt-2); margin-top:.3rem; }
.ev-info-row i { width:13px; text-align:center; color:var(--txt-3); font-size:.7rem; }

.ev-card-foot { padding:.75rem 1.2rem; border-top:1px solid var(--border); background:rgba(0,0,0,.08); display:flex; gap:.4rem; }
</style>

<?php if($message):?>
<div class="alert alert-<?php echo $messageType;?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $message;?></div>
<?php endif;?>

<!-- KPI -->
<div class="kpi-grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));">
    <div class="kpi"><div class="kpi-label">Total Événements</div><div class="kpi-value"><?php echo $stats['t'];?></div></div>
    <div class="kpi"><div class="kpi-label">À Venir</div><div class="kpi-value blue"><?php echo $stats['upcoming'];?></div></div>
    <div class="kpi"><div class="kpi-label">Terminés</div><div class="kpi-value green"><?php echo $stats['done'];?></div></div>
    <div class="kpi"><div class="kpi-label">Tickets Vendus</div><div class="kpi-value yellow"><?php echo number_format($stats['total_tickets'],0,'.',' ');?></div></div>
    <div class="kpi"><div class="kpi-label">Revenus Totaux</div><div class="kpi-value purple" style="font-size:1.2rem;">$<?php echo number_format($stats['total_rev'],0,'.',' ');?></div></div>
    <div class="kpi"><div class="kpi-label">Profit Net</div><div class="kpi-value <?php echo $stats['total_profit']>=0?'green':'red';?>" style="font-size:1.2rem;">$<?php echo number_format($stats['total_profit'],0,'.',' ');?></div></div>
</div>

<!-- Toolbar -->
<div class="toolbar mb-2">
    <div class="f-center gap-2 f-wrap">
        <!-- Période -->
        <div class="filter-bar">
            <?php $periods=['all'=>'Tous','upcoming'=>'📅 À venir','past'=>'🕐 Passés'];
            foreach($periods as $v=>$l){ $q=http_build_query(array_merge($_GET,['period'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterPeriod===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <!-- Types -->
        <div class="filter-bar">
            <?php $types=['all'=>'Tous','concert'=>'🎸 Concerts','festival'=>'🎪 Festivals','showcase'=>'🎤 Showcases','release_party'=>'🎉 Release','listening_party'=>'👂 Listening','other'=>'Autre'];
            foreach($types as $v=>$l){ $q=http_build_query(array_merge($_GET,['type'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterType===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <!-- Statuts -->
        <div class="filter-bar">
            <?php $statuses=['all'=>'Tous statuts','planned'=>'📋 Planifié','confirmed'=>'✅ Confirmé','in_progress'=>'▶️ En cours','completed'=>'✔️ Terminé','cancelled'=>'❌ Annulé'];
            foreach($statuses as $v=>$l){ $q=http_build_query(array_merge($_GET,['status'=>$v])); echo "<a href='?$q' class='filter-btn ".($filterStatus===$v?'active':'')."'>$l</a>"; }?>
        </div>
        <form method="GET" class="f-center gap-1">
            <?php foreach(['period','type','status'] as $k) if($_GET[$k]??'' && $_GET[$k]!=='all') echo "<input type='hidden' name='$k' value='".htmlspecialchars($_GET[$k])."'>";?>
            <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.35rem .7rem;" class="f-center gap-1">
                <i class="fas fa-search" style="color:var(--txt-3);font-size:.75rem;"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search);?>" placeholder="Titre, lieu…" style="background:transparent;border:none;outline:none;color:var(--txt);font-size:.8rem;width:130px;font-family:var(--font-b);">
            </div>
        </form>
    </div>
    <button onclick="Company.openModal('addEventModal')" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvel Événement</button>
</div>
<div class="fs-xs txt-3 mb-3"><?php echo count($events);?> événement(s)</div>

<!-- Grille Événements -->
<?php if(empty($events)):?>
<div class="card" style="text-align:center;padding:3.5rem;color:var(--txt-3);">
    <i class="fas fa-calendar-star" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i>
    <div style="font-weight:600;">Aucun événement trouvé</div>
    <div class="fs-sm mt-1">Planifiez votre prochain concert ou showcase</div>
</div>
<?php else:
$evTypeName=['concert'=>'Concert','festival'=>'Festival','showcase'=>'Showcase','release_party'=>'Release Party','listening_party'=>'Listening Party','signing'=>'Dédicaces','other'=>'Autre'];
$evStatusBadge=['planned'=>['badge-blue','Planifié'],'confirmed'=>['badge-green','Confirmé'],'in_progress'=>['badge-yellow','En cours'],'completed'=>['badge-gray','Terminé'],'cancelled'=>['badge-red','Annulé']];
?>
<div class="ev-grid">
<?php foreach($events as $ev):
    [$sBadgeCss,$sBadgeLbl]=$evStatusBadge[$ev['status']]??['badge-gray',$ev['status']];
    $evDate = new DateTime($ev['event_date']);
    $daysLeft = (int)$ev['days_until'];
    $isPast = $daysLeft < 0;
    $fillPct = $ev['capacity'] > 0 ? min(100, round($ev['tickets_sold']/$ev['capacity']*100)) : 0;
    $profit = floatval($ev['profit']);

    if ($daysLeft === 0) $cdClass = 'today';
    elseif ($daysLeft < 0) $cdClass = 'past';
    elseif ($daysLeft <= 7) $cdClass = 'soon';
    else $cdClass = 'future';

    if ($daysLeft === 0) $cdLabel = "🔥 Aujourd'hui !";
    elseif ($daysLeft < 0) $cdLabel = "il y a ".abs($daysLeft)."j";
    elseif ($daysLeft <= 7) $cdLabel = "dans ".$daysLeft."j";
    else $cdLabel = "dans ".$daysLeft."j";
?>
<div class="ev-card <?php echo $isPast?'past':'';?>">
    <div class="ev-banner <?php echo $ev['type'];?>"></div>
    <div class="ev-card-body">
        <!-- Header -->
        <div class="f-between mb-2">
            <div class="f-center gap-2" style="min-width:0;flex:1;">
                <div class="ev-date-badge <?php echo $isPast?'past-badge':'';?>">
                    <div class="edb-day"><?php echo $evDate->format('d');?></div>
                    <div class="edb-mon"><?php echo $evDate->format('M');?></div>
                </div>
                <div style="min-width:0;flex:1;">
                    <div class="ev-title"><?php echo htmlspecialchars($ev['title']);?></div>
                    <div class="ev-sub">
                        <?php echo $evTypeName[$ev['type']]??$ev['type'];?>
                        <?php if($ev['artist_name']):?> · 🎤 <?php echo htmlspecialchars($ev['artist_name']);?><?php endif;?>
                    </div>
                </div>
            </div>
            <span class="badge <?php echo $sBadgeCss;?> badge-dot" style="flex-shrink:0;"><?php echo $sBadgeLbl;?></span>
        </div>

        <!-- Countdown + type -->
        <div class="f-center gap-2 mb-2">
            <span class="ev-countdown <?php echo $cdClass;?>"><?php echo $cdLabel;?></span>
            <?php if($ev['organizer']):?><span class="fs-xs txt-3">Org. : <?php echo htmlspecialchars($ev['organizer']);?></span><?php endif;?>
        </div>

        <!-- Infos lieu / horaires -->
        <div class="ev-info-row"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($ev['location']);?></div>
        <div class="ev-info-row"><i class="fas fa-clock"></i><?php echo date('H:i',strtotime($ev['start_time']));?><?php if($ev['end_time']):?> → <?php echo date('H:i',strtotime($ev['end_time']));?><?php endif;?></div>

        <!-- Stats financières -->
        <div class="ev-stats">
            <?php if($ev['capacity']>0):?>
            <div class="ev-stat"><div class="ev-stat-v txt-yellow"><?php echo number_format($ev['tickets_sold'],0,'.',' ');?></div><div class="ev-stat-l">Tickets</div></div>
            <?php endif;?>
            <div class="ev-stat"><div class="ev-stat-v txt-money" style="font-size:.82rem;">$<?php echo number_format($ev['revenue'],0,'.',' ');?></div><div class="ev-stat-l">Revenus</div></div>
            <div class="ev-stat"><div class="ev-stat-v <?php echo $profit>=0?'txt-green':'txt-red';?>" style="font-size:.82rem;"><?php echo $profit>=0?'+':'-';?>$<?php echo number_format(abs($profit),0,'.',' ');?></div><div class="ev-stat-l">Profit</div></div>
        </div>

        <!-- Barre remplissage -->
        <?php if($ev['capacity']>0):?>
        <div class="ev-fill">
            <div class="ev-fill-label">
                <span>Remplissage</span>
                <strong><?php echo $fillPct;?>% — <?php echo $ev['tickets_sold'];?>/<?php echo $ev['capacity'];?></strong>
            </div>
            <div class="progress">
                <div class="progress-fill <?php echo $fillPct>=90?'green':($fillPct>=50?'':'yellow');?>" style="width:<?php echo $fillPct;?>%;"></div>
            </div>
        </div>
        <?php endif;?>

        <?php if($ev['description']):?>
        <div style="margin-top:.6rem;font-size:.73rem;color:var(--txt-3);line-height:1.5;border-top:1px solid var(--border);padding-top:.55rem;">
            <?php echo htmlspecialchars(mb_substr($ev['description'],0,100));?><?php echo mb_strlen($ev['description'])>100?'…':'';?>
        </div>
        <?php endif;?>
    </div>

    <div class="ev-card-foot">
        <button onclick="openEditEvent(<?php echo htmlspecialchars(json_encode($ev));?>)" class="btn btn-secondary btn-sm" style="flex:1;"><i class="fas fa-edit"></i> Modifier</button>
        <button onclick="openTickets(<?php echo htmlspecialchars(json_encode(['id'=>$ev['id'],'title'=>$ev['title'],'tickets_sold'=>$ev['tickets_sold'],'ticket_price'=>$ev['ticket_price'],'capacity'=>$ev['capacity']]));?>)" class="btn btn-ghost btn-sm" title="Billetterie"><i class="fas fa-ticket-alt"></i></button>
        <!-- Changement statut rapide -->
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="event_id" value="<?php echo $ev['id'];?>">
            <select name="status" onchange="this.form.submit()" class="form-select" style="padding:.3rem .5rem;font-size:.7rem;width:auto;">
                <option value="planned"     <?php echo $ev['status']==='planned'     ?'selected':'';?>>Planifié</option>
                <option value="confirmed"   <?php echo $ev['status']==='confirmed'   ?'selected':'';?>>Confirmé</option>
                <option value="in_progress" <?php echo $ev['status']==='in_progress' ?'selected':'';?>>En cours</option>
                <option value="completed"   <?php echo $ev['status']==='completed'   ?'selected':'';?>>Terminé</option>
                <option value="cancelled"   <?php echo $ev['status']==='cancelled'   ?'selected':'';?>>Annulé</option>
            </select>
        </form>
        <form method="POST" data-confirm="Supprimer cet événement ?" style="display:inline;">
            <input type="hidden" name="action" value="delete_event">
            <input type="hidden" name="event_id" value="<?php echo $ev['id'];?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- MODAL AJOUTER ÉVÉNEMENT -->
<div id="addEventModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-calendar-plus"></i> Nouvel Événement</h2><button class="modal-close" onclick="Company.closeModal('addEventModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_event">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" class="form-input" required placeholder="Ex : Release Party — Nouvel Album"></div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <option value="concert">🎸 Concert</option><option value="festival">🎪 Festival</option>
                            <option value="showcase">🎤 Showcase</option><option value="release_party">🎉 Release Party</option>
                            <option value="listening_party">👂 Listening Party</option><option value="signing">✍️ Dédicaces</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Artiste lié</label>
                        <select name="artist_id" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Lieu *</label><input type="text" name="location" class="form-input" required placeholder="Nom de la salle, adresse…"></div>
                    <div class="form-group"><label class="form-label">Date *</label><input type="date" name="event_date" class="form-input" required value="<?php echo date('Y-m-d');?>"></div>
                    <div class="form-group"><label class="form-label">Heure début *</label><input type="time" name="start_time" class="form-input" required value="21:00"></div>
                    <div class="form-group"><label class="form-label">Heure fin</label><input type="time" name="end_time" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="status" class="form-select">
                            <option value="planned">Planifié</option><option value="confirmed">Confirmé</option>
                            <option value="in_progress">En cours</option><option value="completed">Terminé</option><option value="cancelled">Annulé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Capacité</label><input type="number" name="capacity" class="form-input" value="0" min="0"></div>
                    <div class="form-group"><label class="form-label">Tickets vendus</label><input type="number" name="tickets_sold" id="add_ts" class="form-input" value="0" min="0" oninput="calcAddRev()"></div>
                    <div class="form-group"><label class="form-label">Prix ticket ($)</label><input type="number" name="ticket_price" id="add_tp" class="form-input" value="0" min="0" step="0.01" oninput="calcAddRev()"></div>
                    <div class="form-group"><label class="form-label">Revenus ($)</label><input type="number" name="revenue" id="add_rev" class="form-input" value="0" min="0" step="0.01"><span class="form-hint">Calculé auto si vide</span></div>
                    <div class="form-group"><label class="form-label">Coût total ($)</label><input type="number" name="cost" class="form-input" value="0" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Organisateur</label><input type="text" name="organizer" class="form-input" placeholder="Nom du responsable"></div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Infos sur l'événement…"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('addEventModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Créer</button></div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER ÉVÉNEMENT -->
<div id="editEventModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-head"><h2><i class="fas fa-edit"></i> Modifier l'Événement</h2><button class="modal-close" onclick="Company.closeModal('editEventModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_event">
            <input type="hidden" name="event_id" id="ee_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" id="ee_title" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" id="ee_type" class="form-select" required>
                            <option value="concert">🎸 Concert</option><option value="festival">🎪 Festival</option>
                            <option value="showcase">🎤 Showcase</option><option value="release_party">🎉 Release Party</option>
                            <option value="listening_party">👂 Listening Party</option><option value="signing">✍️ Dédicaces</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Artiste</label>
                        <select name="artist_id" id="ee_artist" class="form-select"><option value="">— Aucun —</option>
                        <?php foreach($artists as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['artist_name'])."</option>";?>
                        </select>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Lieu *</label><input type="text" name="location" id="ee_location" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Date *</label><input type="date" name="event_date" id="ee_date" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Heure début</label><input type="time" name="start_time" id="ee_start" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Heure fin</label><input type="time" name="end_time" id="ee_end" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="status" id="ee_status" class="form-select">
                            <option value="planned">Planifié</option><option value="confirmed">Confirmé</option>
                            <option value="in_progress">En cours</option><option value="completed">Terminé</option><option value="cancelled">Annulé</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Capacité</label><input type="number" name="capacity" id="ee_cap" class="form-input" min="0"></div>
                    <div class="form-group"><label class="form-label">Tickets vendus</label><input type="number" name="tickets_sold" id="ee_ts" class="form-input" min="0"></div>
                    <div class="form-group"><label class="form-label">Prix ticket ($)</label><input type="number" name="ticket_price" id="ee_tp" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Revenus ($)</label><input type="number" name="revenue" id="ee_rev" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Coût ($)</label><input type="number" name="cost" id="ee_cost" class="form-input" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Organisateur</label><input type="text" name="organizer" id="ee_organizer" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" id="ee_desc" class="form-textarea"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('editEventModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- MODAL TICKETS -->
<div id="ticketsModal" class="modal-overlay hidden">
    <div class="modal modal-sm">
        <div class="modal-head"><h2><i class="fas fa-ticket-alt"></i> Billetterie</h2><button class="modal-close" onclick="Company.closeModal('ticketsModal')">×</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_tickets">
            <input type="hidden" name="event_id" id="tk_id">
            <div class="modal-body">
                <div style="font-weight:700;margin-bottom:1rem;font-family:var(--font-d);" id="tk_title"></div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Capacité</label><div id="tk_cap" style="font-size:.84rem;color:var(--txt-2);padding:.5rem 0;"></div></div>
                    <div class="form-group"></div>
                    <div class="form-group"><label class="form-label">Tickets vendus</label><input type="number" name="tickets_sold" id="tk_ts" class="form-input" min="0" oninput="calcTkRev()"></div>
                    <div class="form-group"><label class="form-label">Prix ($)</label><input type="number" name="ticket_price" id="tk_tp" class="form-input" min="0" step="0.01" oninput="calcTkRev()"></div>
                </div>
                <div class="dist-pill" style="margin-top:.75rem;background:rgba(34,197,94,.06);border-color:rgba(34,197,94,.15);" id="tk_rev_display"></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="Company.closeModal('ticketsModal')">Annuler</button><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Mettre à jour</button></div>
        </form>
    </div>
</div>

<script>
function calcAddRev() {
    const ts = parseFloat(document.getElementById('add_ts').value)||0;
    const tp = parseFloat(document.getElementById('add_tp').value)||0;
    document.getElementById('add_rev').value = (ts*tp).toFixed(2);
}

function calcTkRev() {
    const ts = parseFloat(document.getElementById('tk_ts').value)||0;
    const tp = parseFloat(document.getElementById('tk_tp').value)||0;
    const rev = ts*tp;
    document.getElementById('tk_rev_display').innerHTML = `<span>Revenus calculés</span><strong style="color:var(--green);">$${rev.toLocaleString('fr-FR',{minimumFractionDigits:2})}</strong>`;
}

function openTickets(d) {
    document.getElementById('tk_id').value  = d.id;
    document.getElementById('tk_title').textContent = d.title;
    document.getElementById('tk_cap').textContent   = 'Capacité : ' + (d.capacity||'N/A');
    document.getElementById('tk_ts').value = d.tickets_sold  || 0;
    document.getElementById('tk_tp').value = d.ticket_price  || 0;
    calcTkRev();
    Company.openModal('ticketsModal');
}

function openEditEvent(d) {
    document.getElementById('ee_id').value       = d.id;
    document.getElementById('ee_title').value    = d.title       || '';
    document.getElementById('ee_type').value     = d.type        || 'concert';
    document.getElementById('ee_artist').value   = d.artist_id   || '';
    document.getElementById('ee_location').value = d.location    || '';
    document.getElementById('ee_date').value     = d.event_date  || '';
    document.getElementById('ee_start').value    = d.start_time  ? d.start_time.slice(0,5) : '';
    document.getElementById('ee_end').value      = d.end_time    ? d.end_time.slice(0,5)   : '';
    document.getElementById('ee_status').value   = d.status      || 'planned';
    document.getElementById('ee_cap').value      = d.capacity    || 0;
    document.getElementById('ee_ts').value       = d.tickets_sold|| 0;
    document.getElementById('ee_tp').value       = d.ticket_price|| 0;
    document.getElementById('ee_rev').value      = d.revenue     || 0;
    document.getElementById('ee_cost').value     = d.cost        || 0;
    document.getElementById('ee_organizer').value= d.organizer   || '';
    document.getElementById('ee_desc').value     = d.description || '';
    Company.openModal('editEventModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>