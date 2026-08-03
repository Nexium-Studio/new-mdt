<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$pageTitle = 'Rapports SASP';
$db = Database::getInstance()->getConnection();

// ── Colonnes workflow DOJ (idempotent, aligné sur reports.php) ──────────────
try {
    $__rc = array_column($db->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_ASSOC),'Field');
    if(!in_array('doj_alert',$__rc))   $db->exec("ALTER TABLE reports ADD COLUMN doj_alert TINYINT(1) NOT NULL DEFAULT 0");
    if(!in_array('doj_treated',$__rc)) $db->exec("ALTER TABLE reports ADD COLUMN doj_treated TINYINT(1) NOT NULL DEFAULT 0");
} catch(Throwable $e){}

// ── Action : marquer un rapport LSPD comme traité / non traité par le DOJ ────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='mark_treated') {
    header('Content-Type: application/json');
    $rid = intval($_POST['id'] ?? 0);
    $val = !empty($_POST['treated']) ? 1 : 0;
    try { $db->prepare("UPDATE reports SET doj_treated=? WHERE id=? AND faction='LSPD'")->execute([$val,$rid]); echo json_encode(['success'=>true,'treated'=>$val]); }
    catch(Throwable $e){ echo json_encode(['success'=>false,'error'=>'Échec de la mise à jour']); }
    exit;
}

// ── AJAX : détail d'un rapport LSPD (JSON) — DOIT rester avant tout HTML ──────
if (isset($_GET['ajax']) && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rid = intval($_GET['id']);
        $s = $db->prepare("SELECT r.*, u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.id=? AND r.faction='LSPD'");
        $s->execute([$rid]); $rpt = $s->fetch(PDO::FETCH_ASSOC);
        if (!$rpt) { echo json_encode(['error'=>'Rapport introuvable']); exit; }

        $jd = function($v){ $a=json_decode((string)$v,true); return is_array($a)?$a:[]; };
        $profNames = function(array $ids) use ($db){
            $ids=array_values(array_filter(array_map('intval',$ids))); if(!$ids)return[];
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $st=$db->prepare("SELECT id,firstname,lastname FROM profiles WHERE id IN ($ph)"); $st->execute($ids);
            $m=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$m[(int)$r['id']]=trim($r['firstname'].' '.$r['lastname']);
            return array_values(array_map(fn($i)=>$m[$i]??('#'.$i),$ids));
        };
        // Officiers (users + profil)
        $offIds=array_map('intval',$jd($rpt['officers'] ?? ($rpt['officers_involved']??''))); $offNames=[];
        if($offIds){ $ph=implode(',',array_fill(0,count($offIds),'?')); $st=$db->prepare("SELECT u.id,u.username,pr.firstname,pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.id IN ($ph)"); $st->execute($offIds); $m=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $nm=trim(($r['firstname']??'').' '.($r['lastname']??'')); $m[(int)$r['id']]=$nm!==''?$nm:($r['username']??('#'.$r['id'])); } $offNames=array_values(array_map(fn($i)=>$m[$i]??('#'.$i),$offIds)); }
        // Véhicules
        $vehIds=array_map('intval',$jd($rpt['vehicles']??'')); $vehNames=[];
        if($vehIds){ $ph=implode(',',array_fill(0,count($vehIds),'?')); $st=$db->prepare("SELECT id,plate,model FROM vehicles WHERE id IN ($ph)"); $st->execute($vehIds); $m=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$m[(int)$r['id']]=trim($r['plate'].' — '.$r['model']); $vehNames=array_values(array_map(fn($i)=>$m[$i]??('#'.$i),$vehIds)); }
        $rpt['officers_names']  = $offNames;
        $rpt['suspects_names']  = $profNames($jd($rpt['suspects']??''));
        $rpt['civilians_names'] = $profNames($jd($rpt['civilians']??''));
        $rpt['witnesses_names'] = $profNames($jd($rpt['witnesses']??''));
        $rpt['vehicles_names']  = $vehNames;
        // Saisies liées
        $saisies=[]; try{ $st=$db->prepare("SELECT categorie,description,quantite,valeur_estimee,numero_evidence FROM lspd_saisies WHERE report_id=? ORDER BY id"); $st->execute([$rid]); $saisies=$st->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e){}
        $rpt['saisies'] = $saisies;
        // Document officiel : le générer s'il manque (réutilise le générateur LSPD)
        if (empty($rpt['document_url'])) {
            try {
                require_once ROOT_PATH.'/pages/lspd/includes/report_document.php';
                $du = lspd_build_report_document($db,$rpt);
                if($du){ $db->prepare("UPDATE reports SET document_url=? WHERE id=?")->execute([$du,$rid]); $rpt['document_url']=$du; }
            } catch(Throwable $e){}
        }
        echo json_encode(['report'=>$rpt], JSON_UNESCAPED_UNICODE);
    } catch(Throwable $e){ echo json_encode(['error'=>'Erreur lors du chargement : '.$e->getMessage()]); }
    exit;
}

// ── Filtres ───────────────────────────────────────────────────────────────
$search   = trim($_GET['q']  ?? '');
$typeF    = trim($_GET['t']  ?? '');
$statusF  = trim($_GET['s']  ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;

$pendingF = !empty($_GET['pending']);   // rapports en attente de traitement DOJ
$where = ["r.faction='LSPD'"]; $params = [];
if ($search)  { $where[] = "(r.title LIKE ? OR u.username LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($typeF)   { $where[] = "r.type=?"; $params[] = $typeF; }
if ($statusF) { $where[] = "r.status=?"; $params[] = $statusF; }
if ($pendingF){ $where[] = "r.doj_alert=1 AND r.doj_treated=0"; }
$whereStr = implode(' AND ', $where);

$totalRows = 0;
try {
    $cs = $db->prepare("SELECT COUNT(*) FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE $whereStr");
    $cs->execute($params); $totalRows = (int)$cs->fetchColumn();
} catch(Exception $e){}
$totalPages = max(1, (int)ceil($totalRows/$perPage));
$offset = ($page-1)*$perPage;

$reports = [];
try {
    $stmt = $db->prepare("
        SELECT r.id, r.type, r.title, r.status, r.priority, r.location, r.incident_at,
               r.tags, r.officers, r.civilians, r.suspects, r.created_at, r.faction,
               r.doj_alert, r.doj_treated,
               u.username AS auteur
        FROM reports r
        LEFT JOIN users u ON u.id = r.created_by
        WHERE $whereStr
        ORDER BY r.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
} catch(Exception $e){}

// Pour le détail d'un rapport
$viewId     = intval($_GET['id'] ?? 0);
$viewReport = null;
if ($viewId > 0) {
    try {
        $s = $db->prepare("SELECT r.*, u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.id=? AND r.faction='LSPD'");
        $s->execute([$viewId]); $viewReport = $s->fetch();
    } catch(Exception $e){}
}

// KPIs rapides
$kTotal=0; $kSemaine=0; $kOuverts=0;
try { $kTotal   = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='LSPD'")->fetchColumn(); } catch(Exception $e){}
try { $kSemaine = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='LSPD' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(); } catch(Exception $e){}
try { $kOuverts = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='LSPD' AND status IN ('submitted','under_review','open')")->fetchColumn(); } catch(Exception $e){}

// Types disponibles
$typesList = [];
try { $typesList = array_column($db->query("SELECT DISTINCT type FROM reports WHERE faction='LSPD' ORDER BY type")->fetchAll(), 'type'); } catch(Exception $e){}

$TYPE_LABELS = ['incident'=>'Incident','arrest'=>'Arrestation','investigation'=>'Enquête','traffic'=>'Circulation','use_of_force'=>'Usage de la force','missing_person'=>'Personne disparue','property'=>'Biens','other'=>'Autre'];
$STATUS_CFG  = [
    'submitted'    => ['Soumis',       '#3b82f6','rgba(59,130,246,.1)','rgba(59,130,246,.25)'],
    'under_review' => ['En révision',  '#f59e0b','rgba(245,158,11,.1)','rgba(245,158,11,.25)'],
    'approved'     => ['Approuvé',     '#22c55e','rgba(34,197,94,.1)', 'rgba(34,197,94,.25)'],
    'closed'       => ['Clôturé',      '#64748b','rgba(100,116,139,.1)','rgba(100,116,139,.2)'],
    'open'         => ['Ouvert',       '#3b82f6','rgba(59,130,246,.1)','rgba(59,130,246,.25)'],
];
$PRIORITY_CFG = ['low'=>['#22c55e','Faible'],'normal'=>['#3b82f6','Normal'],'high'=>['#f59e0b','Élevé'],'critical'=>['#ef4444','Critique']];

include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ Rapports DOJ (lecture seule) ══════════════════════════════════════════ */
.rpt-kpi-row { display:grid; grid-template-columns:repeat(3,1fr); gap:.85rem; margin-bottom:1.5rem; }
.rpt-kpi { background:rgba(30,41,59,.8); border:1px solid rgba(59,130,246,.12); border-radius:12px; padding:1rem 1.25rem; display:flex; align-items:center; gap:.85rem; }
.rpt-kpi-ico { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.rpt-kpi-val { font-family:'Orbitron',monospace; font-size:1.45rem; font-weight:800; line-height:1; }
.rpt-kpi-lbl { font-size:.67rem; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-top:.15rem; }

/* Toolbar */
.rpt-toolbar { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:1.1rem; flex-wrap:wrap; background:rgba(15,23,42,.6); border:1px solid rgba(59,130,246,.12); border-radius:12px; padding:.75rem 1rem; }
.rpt-search { position:relative; }
.rpt-search input { padding:.48rem .9rem .48rem 2.2rem; background:rgba(15,23,42,.8); border:1px solid rgba(59,130,246,.18); border-radius:8px; color:#f1f5f9; font-family:'Chakra Petch',sans-serif; font-size:.82rem; width:250px; transition:all .2s; }
.rpt-search input:focus { outline:none; border-color:#3b82f6; }
.rpt-search i { position:absolute; left:.72rem; top:50%; transform:translateY(-50%); color:#475569; font-size:.75rem; pointer-events:none; }

/* Table */
.rpt-table-wrap { background:rgba(15,23,42,.7); border:1px solid rgba(59,130,246,.12); border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.rpt-table { width:100%; border-collapse:collapse; }
.rpt-table thead tr { background:linear-gradient(90deg,rgba(59,130,246,.08),transparent); border-bottom:2px solid rgba(59,130,246,.15); }
.rpt-table th { padding:.75rem 1rem; font-family:'Orbitron',monospace; font-size:.58rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:#475569; text-align:left; white-space:nowrap; }
.rpt-table td { padding:.72rem 1rem; border-bottom:1px solid rgba(59,130,246,.05); font-size:.82rem; vertical-align:middle; }
.rpt-table tbody tr { transition:background .12s; cursor:pointer; }
.rpt-table tbody tr:hover td { background:rgba(59,130,246,.06); }
.rpt-table tbody tr:last-child td { border-bottom:none; }

/* Readonly banner */
.readonly-banner { display:flex; align-items:center; gap:.65rem; padding:.65rem 1rem; background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.2); border-radius:9px; margin-bottom:1.25rem; font-size:.8rem; color:#60a5fa; }

/* Detail modal */
.rpt-section-title { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:#3b82f6; margin-bottom:.65rem; display:flex; align-items:center; gap:.4rem; }
.rpt-detail-field { display:grid; grid-template-columns:140px 1fr; gap:.5rem; padding:.5rem 0; border-bottom:1px solid rgba(59,130,246,.06); font-size:.82rem; }
.rpt-detail-field:last-child { border-bottom:none; }
.rpt-detail-lbl { color:#475569; font-size:.75rem; }

/* Pagination */
.rpt-pages { display:flex; align-items:center; justify-content:space-between; padding:.5rem 0; font-size:.78rem; }
.rpt-page-btn { width:30px; height:30px; border-radius:6px; border:1px solid rgba(59,130,246,.2); color:#64748b; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; font-size:.78rem; transition:all .14s; }
.rpt-page-btn:hover { border-color:rgba(59,130,246,.4); color:#60a5fa; }
.rpt-page-btn.active { background:rgba(59,130,246,.15); border-color:#3b82f6; color:#60a5fa; font-weight:700; }
</style>

<!-- Bandeau lecture seule -->
<div class="readonly-banner">
    <i class="fas fa-eye"></i>
    <span>Accès <strong>lecture seule</strong> — Les rapports SASP/LSPD ne peuvent pas être modifiés depuis le panel DOJ.</span>
</div>

<!-- KPIs -->
<div class="rpt-kpi-row">
    <div class="rpt-kpi">
        <div class="rpt-kpi-ico" style="background:rgba(59,130,246,.12);color:#60a5fa"><i class="fas fa-file-alt"></i></div>
        <div><div class="rpt-kpi-val" style="color:#60a5fa"><?php echo $kTotal;?></div><div class="rpt-kpi-lbl">Total rapports</div></div>
    </div>
    <div class="rpt-kpi">
        <div class="rpt-kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-calendar-week"></i></div>
        <div><div class="rpt-kpi-val" style="color:#4ade80"><?php echo $kSemaine;?></div><div class="rpt-kpi-lbl">Cette semaine</div></div>
    </div>
    <div class="rpt-kpi">
        <div class="rpt-kpi-ico" style="background:rgba(245,158,11,.12);color:#fcd34d"><i class="fas fa-folder-open"></i></div>
        <div><div class="rpt-kpi-val" style="color:#fcd34d"><?php echo $kOuverts;?></div><div class="rpt-kpi-lbl">En cours</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="rpt-toolbar">
    <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap">
        <div class="rpt-search">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline">
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($typeF);?>">
                <input type="hidden" name="s" value="<?php echo htmlspecialchars($statusF);?>">
                <input type="text" name="q" placeholder="Titre, auteur…" value="<?php echo htmlspecialchars($search);?>">
            </form>
        </div>
        <form method="GET" style="display:flex;gap:.4rem">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search);?>">
            <select name="t" class="form-select" style="padding:.45rem .8rem;font-size:.78rem;width:auto" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <?php foreach($typesList as $t): $tl=$TYPE_LABELS[$t]??$t;?>
                <option value="<?php echo $t;?>" <?php echo $typeF===$t?'selected':'';?>><?php echo $tl;?></option>
                <?php endforeach;?>
            </select>
            <select name="s" class="form-select" style="padding:.45rem .8rem;font-size:.78rem;width:auto" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <?php foreach($STATUS_CFG as $k=>[$sl]):?>
                <option value="<?php echo $k;?>" <?php echo $statusF===$k?'selected':'';?>><?php echo $sl;?></option>
                <?php endforeach;?>
            </select>
            <?php if($search||$typeF||$statusF):?><a href="rapports.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
        </form>
    </div>
    <span style="font-size:.78rem;color:#475569"><?php echo $totalRows;?> rapport(s)</span>
</div>

<!-- Table -->
<div class="rpt-table-wrap">
    <table class="rpt-table">
        <thead>
            <tr>
                <th>#</th><th>Type</th><th>Titre</th><th>Auteur</th><th>Lieu</th><th>Priorité</th><th>Statut</th><th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($reports)):?>
        <tr><td colspan="8" style="text-align:center;padding:3.5rem;color:#475569">
            <i class="fas fa-file-slash" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun rapport trouvé
        </td></tr>
        <?php else: foreach($reports as $r):
            [$sc,$sbg,$sbdr] = array_slice($STATUS_CFG[$r['status']??'submitted'] ?? ['—','#64748b','rgba(100,116,139,.1)','rgba(100,116,139,.2)'],0,3);
            [$pc,$pl] = $PRIORITY_CFG[$r['priority']??'normal'] ?? ['#64748b','—'];
            $tl = $TYPE_LABELS[$r['type']??''] ?? $r['type'];
        ?>
        <tr onclick="viewReport(<?php echo $r['id'];?>)" title="Cliquer pour voir le détail">
            <td><span style="font-family:'JetBrains Mono',monospace;font-size:.75rem;color:#475569">#<?php echo str_pad($r['id'],5,'0',STR_PAD_LEFT);?></span></td>
            <td><span style="font-size:.72rem;padding:.2rem .5rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);border-radius:5px;color:#60a5fa;font-weight:600"><?php echo htmlspecialchars($tl);?></span></td>
            <td>
                <div style="font-weight:600;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($r['title']);?></div>
                <?php if((int)($r['doj_alert']??0)===1 && (int)($r['doj_treated']??0)===0):?><span style="display:inline-block;margin-top:.25rem;font-size:.62rem;font-weight:700;padding:.12rem .45rem;border-radius:4px;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.3);color:#a78bfa"><i class="fas fa-scale-balanced" style="font-size:.55rem"></i> En attente DOJ</span>
                <?php elseif((int)($r['doj_alert']??0)===1):?><span style="display:inline-block;margin-top:.25rem;font-size:.62rem;font-weight:700;padding:.12rem .45rem;border-radius:4px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.28);color:#10b981">✓ Traité DOJ</span><?php endif;?>
            </td>
            <td style="font-size:.8rem;color:#94a3b8"><?php echo htmlspecialchars($r['auteur']??'—');?></td>
            <td style="font-size:.78rem;color:#64748b"><?php echo htmlspecialchars(mb_substr($r['location']??'—',0,25));?></td>
            <td><span style="font-size:.7rem;padding:.18rem .5rem;border-radius:4px;font-weight:700;color:<?php echo $pc;?>;background:<?php echo $pc;?>18;border:1px solid <?php echo $pc;?>33"><?php echo $pl;?></span></td>
            <td><span style="font-size:.7rem;padding:.2rem .55rem;border-radius:5px;font-weight:700;color:<?php echo $sc;?>;background:<?php echo $sbg;?>;border:1px solid <?php echo $sbdr;?>"><?php echo htmlspecialchars(is_array($STATUS_CFG[$r['status']??'']) ? $STATUS_CFG[$r['status']??''][0] : '—');?></span></td>
            <td style="font-size:.75rem;color:#64748b;white-space:nowrap"><?php echo date('d/m/Y H:i',strtotime($r['created_at']));?></td>
        </tr>
        <?php endforeach; endif;?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if($totalPages>1):?>
<div class="rpt-pages">
    <span style="color:#475569"><?php echo $totalRows;?> rapport(s) · Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <div style="display:flex;gap:.3rem">
        <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&t=<?php echo urlencode($typeF);?>&s=<?php echo urlencode($statusF);?>&pending=<?php echo $pendingF?'1':'';?>" class="rpt-page-btn">←</a><?php endif;?>
        <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++):?>
        <a href="?page=<?php echo $i;?>&q=<?php echo urlencode($search);?>&t=<?php echo urlencode($typeF);?>&s=<?php echo urlencode($statusF);?>&pending=<?php echo $pendingF?'1':'';?>" class="rpt-page-btn <?php echo $i===$page?'active':'';?>"><?php echo $i;?></a>
        <?php endfor;?>
        <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&t=<?php echo urlencode($typeF);?>&s=<?php echo urlencode($statusF);?>&pending=<?php echo $pendingF?'1':'';?>" class="rpt-page-btn">→</a><?php endif;?>
    </div>
</div>
<?php endif;?>

<!-- ══ MODAL DÉTAIL RAPPORT (lecture seule) ════════════════════════════════ -->
<div id="reportDetailModal" class="modal-overlay hidden">
<div class="modal modal-large">
<div class="modal-header">
    <h2><i class="fas fa-file-alt" style="color:#60a5fa"></i> Détail du Rapport <span id="rdTitle" style="font-size:.85rem;opacity:.7;margin-left:.5rem"></span></h2>
    <button class="modal-close" onclick="DOJ.closeModal('reportDetailModal')">×</button>
</div>
<div class="modal-body" id="rdContent" style="padding:1.75rem 2rem">
    <div style="text-align:center;padding:2rem;color:#475569"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i><div style="margin-top:.75rem">Chargement…</div></div>
</div>
<div class="modal-footer">
    <div style="font-size:.75rem;color:#475569;margin-right:auto"><i class="fas fa-lock" style="margin-right:.3rem"></i>Lecture seule — Accès DOJ</div>
    <button class="btn btn-secondary" onclick="DOJ.closeModal('reportDetailModal')">Fermer</button>
</div>
</div>
</div>

<script>
function viewReport(id) {
    document.getElementById('rdTitle').textContent = '#' + String(id).padStart(5,'0');
    document.getElementById('rdContent').innerHTML = '<div style="text-align:center;padding:2rem;color:#475569"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i><div style="margin-top:.75rem">Chargement…</div></div>';
    DOJ.openModal('reportDetailModal');
    fetch('<?php echo SITE_URL;?>/pages/doj/rapports.php?id='+id+'&ajax=1', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            if (d.error) { document.getElementById('rdContent').innerHTML='<p style="color:#ef4444;padding:1rem">'+d.error+'</p>'; return; }
            const r=d.report;
            const esc = s => { const el=document.createElement('div'); el.textContent = s==null?'':s; return el.innerHTML; };
            const TYPE_LABELS = <?php echo json_encode($TYPE_LABELS);?>;
            const STATUS_CFG  = <?php echo json_encode(array_map(fn($v)=>[$v[0],$v[1]],$STATUS_CFG));?>;
            const PRIORITY_CFG= <?php echo json_encode($PRIORITY_CFG);?>;
            const tl = TYPE_LABELS[r.type] || r.type || '—';
            const [sc,scolor] = STATUS_CFG[r.status] || ['—','#64748b'];
            const [pc,pl] = PRIORITY_CFG[r.priority] || ['#64748b','—'];
            const chips = (arr,color) => (arr&&arr.length) ? '<div style="display:flex;gap:.4rem;flex-wrap:wrap">'+arr.map(x=>`<span style="padding:.2rem .6rem;background:${color}14;border:1px solid ${color}33;border-radius:5px;font-size:.75rem;color:${color}">${esc(x)}</span>`).join('')+'</div>' : '';
            const section = (icon,title,body) => body ? `<div class="rpt-section-title"><i class="fas fa-${icon}"></i>${title}</div><div style="margin-bottom:1rem">${body}</div>` : '';
            let tags=[]; try{ tags=JSON.parse(r.tags||'[]')||[]; }catch(e){}
            let evid=[]; try{ evid=JSON.parse(r.evidence||'[]')||[]; }catch(e){}
            const evidHtml = evid.length ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.5rem">'+evid.map(u=>{ const img=/\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(u); return img?`<a href="${esc(u)}" target="_blank" onclick="return mdtOpenDoc('${esc(u)}','img','Preuve')"><img src="${esc(u)}" style="width:100%;height:88px;object-fit:cover;border-radius:7px;border:1px solid rgba(59,130,246,.2)"></a>`:`<a href="${esc(u)}" target="_blank" style="font-size:.72rem;color:#60a5fa">${esc(u.slice(0,32))}</a>`; }).join('')+'</div>' : '';
            const saisiesHtml = (r.saisies&&r.saisies.length) ? r.saisies.map(s=>`<div style="display:flex;justify-content:space-between;gap:.5rem;padding:.4rem .6rem;background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:7px;margin-bottom:.3rem;font-size:.78rem"><span>${esc(s.description)}${s.quantite?(' · '+esc(s.quantite)):''}</span><span style="color:#60a5fa">${esc(s.numero_evidence||'')}</span></div>`).join('') : '';
            const treated = parseInt(r.doj_treated||0,10)===1;
            const docBtn = r.document_url ? `<a href="${esc(r.document_url)}" target="_blank" onclick="return mdtOpenDoc('${esc(r.document_url)}','doc','Rapport #'+String(r.id).padStart(6,'0'))" class="btn btn-primary" style="text-decoration:none"><i class="fas fa-file-lines"></i> Voir le document officiel</a>` : '';
            const treatBtn = `<button type="button" onclick="markTreated(${r.id},${treated?0:1})" class="btn ${treated?'btn-secondary':'btn-primary'}"><i class="fas fa-${treated?'rotate-left':'check'}"></i> ${treated?'Rouvrir (non traité)':'Marquer comme traité'}</button>`;

            document.getElementById('rdContent').innerHTML = `
                <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
                    <span style="padding:.28rem .75rem;border-radius:6px;font-size:.73rem;font-weight:700;background:${scolor}18;border:1px solid ${scolor}33;color:${scolor}">${sc}</span>
                    <span style="padding:.28rem .75rem;border-radius:6px;font-size:.73rem;font-weight:700;background:${pc}18;border:1px solid ${pc}33;color:${pc}">${pl}</span>
                    <span style="padding:.28rem .75rem;border-radius:6px;font-size:.73rem;font-weight:700;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:#60a5fa">${esc(tl)}</span>
                    ${parseInt(r.doj_alert||0,10)===1?'<span style="padding:.28rem .75rem;border-radius:6px;font-size:.73rem;font-weight:700;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.3);color:#a78bfa">⚖ Alerte DOJ</span>':''}
                    ${treated?'<span style="padding:.28rem .75rem;border-radius:6px;font-size:.73rem;font-weight:700;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#10b981">✓ Traité DOJ</span>':''}
                </div>
                <div style="font-size:1.1rem;font-weight:700;margin-bottom:1rem">${esc(r.title)}</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">${docBtn}${treatBtn}</div>
                <div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:10px;padding:1rem;margin-bottom:1.25rem">
                    ${[['Auteur',r.auteur||'—'],['Date',r.created_at?new Date(r.created_at).toLocaleString('fr-FR'):'—'],['Lieu',r.location||'—']].map(([l,v])=>`<div style="display:grid;grid-template-columns:120px 1fr;gap:.5rem;padding:.4rem 0;border-bottom:1px solid rgba(59,130,246,.06);font-size:.82rem"><span style="color:#475569;font-size:.75rem">${l}</span><span style="color:#f1f5f9;font-weight:500">${esc(v)}</span></div>`).join('')}
                </div>
                ${section('user-shield','Officiers impliqués',chips(r.officers_names,'#3b82f6'))}
                ${section('user-slash','Suspects',chips(r.suspects_names,'#ef4444'))}
                ${section('user','Civils impliqués',chips(r.civilians_names,'#22c55e'))}
                ${section('eye','Témoins',chips(r.witnesses_names,'#f59e0b'))}
                ${section('car','Véhicules',chips(r.vehicles_names,'#60a5fa'))}
                ${section('tags','Tags',chips(tags,'#8b5cf6'))}
                ${section('box-archive','Objets saisis',saisiesHtml)}
                ${r.description_rich?section('file-lines','Description',`<div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:9px;padding:1rem;font-size:.85rem;line-height:1.7;color:#cbd5e1">${r.description_rich}</div>`):''}
                ${section('camera','Preuves & pièces jointes',evidHtml)}
            `;
        })
        .catch(()=>{ document.getElementById('rdContent').innerHTML='<p style="color:#ef4444;padding:1rem">Erreur lors du chargement du rapport.</p>'; });
}

function markTreated(id, val){
    const fd=new FormData(); fd.append('action','mark_treated'); fd.append('id',id); fd.append('treated',val);
    fetch('<?php echo SITE_URL;?>/pages/doj/rapports.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(j=>{ if(j&&j.success){ viewReport(id); if(window.DOJ&&DOJ.toast) DOJ.toast(val?'Rapport marqué comme traité.':'Rapport rouvert.','success'); }
                   else { if(window.DOJ&&DOJ.toast) DOJ.toast('Échec de la mise à jour.','error'); } })
        .catch(()=>{ if(window.DOJ&&DOJ.toast) DOJ.toast('Erreur réseau.','error'); });
}
</script>

<?php require_once ROOT_PATH . '/includes/doc_viewer.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>