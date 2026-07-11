<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// ── Auto-création / migration colonnes ────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS penal_code (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        nom_code VARCHAR(200),
        category ENUM('crime','delit_majeur','delit_mineur','infraction') NOT NULL DEFAULT 'infraction',
        title VARCHAR(300) NOT NULL,
        description_rich TEXT,
        severity TINYINT DEFAULT 3,
        min_fine DECIMAL(10,2) DEFAULT 0,
        max_fine DECIMAL(10,2) DEFAULT 0,
        min_jail_months INT DEFAULT 0,
        max_jail_months INT DEFAULT 0,
        gravity_fines JSON,
        gravity_up JSON,
        is_arrestable TINYINT(1) DEFAULT 0,
        is_warrantable TINYINT(1) DEFAULT 0,
        points INT DEFAULT 0,
        examples TEXT,
        active TINYINT(1) DEFAULT 1,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Migration: ajouter colonnes si manquantes
    foreach(['nom_code'=>'VARCHAR(200) NULL','gravity_fines'=>'JSON NULL','gravity_up'=>'JSON NULL'] as $col=>$def) {
        try { $db->query("SELECT `$col` FROM penal_code LIMIT 0"); } catch(PDOException $e) {
            $db->exec("ALTER TABLE penal_code ADD COLUMN `$col` $def");
        }
    }
} catch(PDOException $e) {}

// ── NIVEAUX DE GRAVITÉ ───────────────────────────────────────────────────────
$GRAVITY_LEVELS = ['min1','min2','min3','nominal','max1','max2','max3'];
$GRAVITY_LABELS = ['min1'=>'Min 1','min2'=>'Min 2','min3'=>'Min 3','nominal'=>'Nominal','max1'=>'Max 1','max2'=>'Max 2','max3'=>'Max 3'];
$GRAVITY_COLORS = ['min1'=>'#22c55e','min2'=>'#84cc16','min3'=>'#eab308','nominal'=>'#3b82f6','max1'=>'#f97316','max2'=>'#ef4444','max3'=>'#7f1d1d'];

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        try {
            // Build gravity JSON
            $gFines = []; $gUP = [];
            foreach ($GRAVITY_LEVELS as $lvl) {
                $gFines[$lvl] = floatval($_POST['fine_'.$lvl] ?? 0);
                $gUP[$lvl]    = floatval($_POST['up_'.$lvl] ?? 0);
            }
            $gravityFinesJson = json_encode($gFines);
            $gravityUpJson    = json_encode($gUP);

            // Compute legacy min/max from gravity for compat
            $allFines   = array_values($gFines);
            $allUP      = array_values($gUP);
            $nonZeroF   = array_filter($allFines);
            $nonZeroUP  = array_filter($allUP);
            $minFine = $nonZeroF  ? min($nonZeroF)  : 0;
            $maxFine = $nonZeroF  ? max($nonZeroF)  : 0;
            $minJail = $nonZeroUP ? min($nonZeroUP) : 0;
            $maxJail = $nonZeroUP ? max($nonZeroUP) : 0;

            if ($_POST['action'] === 'add') {
                $stmt = $db->prepare("INSERT INTO penal_code
                    (code,nom_code,category,title,description_rich,severity,min_fine,max_fine,min_jail_months,max_jail_months,gravity_fines,gravity_up,is_arrestable,is_warrantable,points,examples,active,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
                $stmt->execute([
                    trim($_POST['code']), trim($_POST['nom_code']??''),
                    $_POST['category'], trim($_POST['title']),
                    $_POST['description']??'',
                    intval($_POST['severity']??3),
                    $minFine,$maxFine,$minJail,$maxJail,
                    $gravityFinesJson,$gravityUpJson,
                    isset($_POST['is_arrestable'])?1:0,
                    isset($_POST['is_warrantable'])?1:0,
                    intval($_POST['points']??0),
                    trim($_POST['examples']??''),
                    $_SESSION['user_id']
                ]);
                $msg = "Code <strong>".htmlspecialchars($_POST['code'])."</strong> créé avec succès.";
                $msgType = 'success';
            } else {
                $stmt = $db->prepare("UPDATE penal_code SET
                    code=?,nom_code=?,category=?,title=?,description_rich=?,severity=?,
                    min_fine=?,max_fine=?,min_jail_months=?,max_jail_months=?,
                    gravity_fines=?,gravity_up=?,
                    is_arrestable=?,is_warrantable=?,points=?,examples=?
                    WHERE id=?");
                $stmt->execute([
                    trim($_POST['code']), trim($_POST['nom_code']??''),
                    $_POST['category'], trim($_POST['title']),
                    $_POST['description']??'',
                    intval($_POST['severity']??3),
                    $minFine,$maxFine,$minJail,$maxJail,
                    $gravityFinesJson,$gravityUpJson,
                    isset($_POST['is_arrestable'])?1:0,
                    isset($_POST['is_warrantable'])?1:0,
                    intval($_POST['points']??0),
                    trim($_POST['examples']??''),
                    intval($_POST['code_id'])
                ]);
                $msg = 'Code modifié avec succès.'; $msgType = 'success';
            }
        } catch (PDOException $e) {
            $msg = strpos($e->getMessage(),'Duplicate')!==false ? 'Ce code pénal existe déjà.' : 'Erreur : '.$e->getMessage();
            $msgType = 'error';
        }
    }

    if ($_POST['action'] === 'toggle' && isset($_POST['code_id'])) {
        try { $db->prepare("UPDATE penal_code SET active=NOT active WHERE id=?")->execute([$_POST['code_id']]);
              $msg='Statut mis à jour.'; $msgType='success'; } catch(PDOException $e){}
    }

    if ($_POST['action'] === 'delete' && isset($_POST['code_id'])) {
        try { $db->prepare("DELETE FROM penal_code WHERE id=?")->execute([$_POST['code_id']]);
              $msg='Code supprimé.'; $msgType='success'; } catch(PDOException $e){}
    }
}

// ── DONNÉES ──────────────────────────────────────────────────────────────────
$categoryFilter = $_GET['cat'] ?? '';
$search         = trim($_GET['q'] ?? '');
$activeFilter   = $_GET['active'] ?? '';

$where = "WHERE 1=1"; $params = [];
if ($categoryFilter) { $where .= " AND category=?"; $params[] = $categoryFilter; }
if ($search) { $where .= " AND (code LIKE ? OR nom_code LIKE ? OR title LIKE ?)"; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }
if ($activeFilter !== '') { $where .= " AND active=?"; $params[] = intval($activeFilter); }
$where .= " ORDER BY category, code ASC";

$stmt = $db->prepare("SELECT * FROM penal_code $where"); $stmt->execute($params);
$codes = $stmt->fetchAll();

$kpis = ['total'=>0,'crime'=>0,'delit_majeur'=>0,'delit_mineur'=>0,'infraction'=>0];
try {
    foreach($db->query("SELECT category,COUNT(*) n FROM penal_code WHERE active=1 GROUP BY category")->fetchAll() as $r) {
        $kpis[$r['category']] = (int)$r['n'];
        $kpis['total'] += (int)$r['n'];
    }
} catch(Exception $e){}

$CAT_CFG = [
    'crime'        => ['Crime',       '#ef4444','rgba(239,68,68,.12)','rgba(239,68,68,.3)', 'fas fa-skull-crossbones'],
    'delit_majeur' => ['Délit Majeur','#f97316','rgba(249,115,22,.12)','rgba(249,115,22,.3)','fas fa-triangle-exclamation'],
    'delit_mineur' => ['Délit Mineur','#eab308','rgba(234,179,8,.12)', 'rgba(234,179,8,.3)', 'fas fa-exclamation-circle'],
    'infraction'   => ['Infraction',  '#3b82f6','rgba(59,130,246,.12)','rgba(59,130,246,.3)','fas fa-info-circle'],
];

$pageTitle = 'Code Pénal';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ CORRECTION CRITIQUE — Chaîne flex modale ════════════════════════════════
   Le <form> entre .modal et .modal-body casse la chaîne flexbox si non géré.
   .modal (flex-col, h:90vh) → form (flex-col, flex:1, min-h:0) → .modal-body (flex:1, overflow-y:auto)
   Sans ça, modal-body n'a pas de hauteur contrainte et ne peut pas scroller.
   ═══════════════════════════════════════════════════════════════════════════ */
.modal { display: flex !important; flex-direction: column !important; overflow: hidden !important; }
.modal > form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 !important;
    min-height: 0 !important;
    overflow: hidden !important;
}
.modal-body {
    flex: 1 !important;
    min-height: 0 !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
}
.modal-footer { flex-shrink: 0 !important; }
.modal-header { flex-shrink: 0 !important; }

/* ══ Code Pénal — Design System ════════════════════════════════════════════ */
.cp-kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:.85rem; margin-bottom:1.75rem; }
.cp-kpi {
    background:rgba(30,41,59,.8); border:1px solid rgba(59,130,246,.12);
    border-radius:12px; padding:1.1rem 1.25rem;
    display:flex; align-items:center; gap:.9rem;
    transition:border-color .2s;
}
.cp-kpi:hover { border-color:rgba(59,130,246,.3); }
.cp-kpi-ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
.cp-kpi-val { font-family:'Orbitron',monospace; font-size:1.55rem; font-weight:800; line-height:1; }
.cp-kpi-lbl { font-size:.68rem; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-top:.18rem; }

/* Toolbar */
.cp-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap;
    background:rgba(15,23,42,.6); border:1px solid rgba(59,130,246,.12);
    border-radius:12px; padding:.85rem 1.1rem;
}
.cp-filters { display:flex; gap:.4rem; flex-wrap:wrap; }
.cp-filter-btn {
    padding:.38rem .85rem; border-radius:7px; font-size:.77rem; font-weight:700;
    cursor:pointer; text-decoration:none; transition:all .18s;
    border:1px solid rgba(59,130,246,.15); color:#64748b; background:transparent;
    font-family:'Chakra Petch',sans-serif;
}
.cp-filter-btn:hover { border-color:rgba(59,130,246,.35); color:#94a3b8; }
.cp-filter-btn.active { background:rgba(59,130,246,.15); border-color:rgba(59,130,246,.4); color:#60a5fa; }
.cp-search { position:relative; }
.cp-search input {
    padding:.5rem .9rem .5rem 2.3rem; background:rgba(15,23,42,.8);
    border:1px solid rgba(59,130,246,.18); border-radius:8px;
    color:#f1f5f9; font-family:'Chakra Petch',sans-serif; font-size:.82rem;
    width:240px; transition:all .2s;
}
.cp-search input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.cp-search i { position:absolute; left:.7rem; top:50%; transform:translateY(-50%); color:#475569; font-size:.78rem; }

/* Table */
.cp-table-wrap { background:rgba(15,23,42,.7); border:1px solid rgba(59,130,246,.12); border-radius:14px; overflow:hidden; }
.cp-table { width:100%; border-collapse:collapse; }
.cp-table thead tr { background:linear-gradient(90deg,rgba(59,130,246,.08),transparent); border-bottom:2px solid rgba(59,130,246,.15); }
.cp-table th { padding:.8rem 1.1rem; font-family:'Orbitron',monospace; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:#475569; text-align:left; white-space:nowrap; }
.cp-table td { padding:.8rem 1.1rem; border-bottom:1px solid rgba(59,130,246,.05); vertical-align:middle; font-size:.83rem; }
.cp-table tbody tr { transition:background .12s; }
.cp-table tbody tr:hover td { background:rgba(59,130,246,.04); }
.cp-table tbody tr:last-child td { border-bottom:none; }
.cp-table tbody tr.inactive td { opacity:.45; }

/* Code badge */
.cp-code-badge {
    display:inline-flex; align-items:center; padding:.22rem .65rem;
    border-radius:6px; font-family:'JetBrains Mono',monospace;
    font-size:.75rem; font-weight:700; white-space:nowrap;
    border:1px solid; letter-spacing:.05em;
}
/* Category pill */
.cp-cat { display:inline-flex; align-items:center; gap:.3rem; padding:.22rem .6rem; border-radius:5px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; border:1px solid; white-space:nowrap; }

/* Gravity table inside modal */
.gravity-table { width:100%; border-collapse:collapse; border-radius:10px; overflow:hidden; }
.gravity-table th { background:rgba(59,130,246,.08); padding:.55rem .75rem; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#64748b; text-align:left; border-bottom:1px solid rgba(59,130,246,.15); }
.gravity-table td { padding:.4rem .75rem; border-bottom:1px solid rgba(59,130,246,.06); }
.gravity-table tr:last-child td { border-bottom:none; }
.gravity-table input { width:100%; padding:.35rem .55rem; background:rgba(15,23,42,.9); border:1px solid rgba(59,130,246,.18); border-radius:6px; color:#f1f5f9; font-family:'JetBrains Mono',monospace; font-size:.82rem; transition:border-color .15s; }
.gravity-table input:focus { outline:none; border-color:#3b82f6; }
.gravity-lvl-tag { display:inline-block; padding:.18rem .55rem; border-radius:5px; font-size:.68rem; font-weight:700; font-family:'JetBrains Mono',monospace; }

/* Actions */
.cp-act { display:inline-flex; align-items:center; gap:.28rem; padding:.28rem .65rem; border-radius:6px; font-size:.72rem; font-weight:600; cursor:pointer; border:none; transition:all .15s; white-space:nowrap; font-family:'Chakra Petch',sans-serif; }

/* Empty state */
.cp-empty { text-align:center; padding:4rem 2rem; color:#475569; }
.cp-empty i { font-size:2.5rem; opacity:.2; display:block; margin-bottom:.75rem; }

/* Gravity badge strip */
.grav-strip { display:flex; gap:2px; }
.grav-dot { width:10px; height:10px; border-radius:2px; }

/* Detail modal gravity */
.detail-gravity-row { display:grid; grid-template-columns:90px 1fr 1fr; align-items:center; padding:.5rem .85rem; border-bottom:1px solid rgba(59,130,246,.06); font-size:.8rem; }
.detail-gravity-row:last-child { border-bottom:none; }

/* ══ Custom scrollbar — modal body ═══════════════════════════════════════════
   Scrollbar fine et bleue, activée automatiquement quand le contenu
   dépasse la hauteur de la modale. Aucune barre navigateur native.
   ════════════════════════════════════════════════════════════════════════════ */
.modal-body {
    scrollbar-width: thin !important;
    scrollbar-color: rgba(59,130,246,.5) rgba(15,23,42,.6) !important;
}
.modal-body::-webkit-scrollbar {
    width: 6px !important;
}
.modal-body::-webkit-scrollbar-track {
    background: rgba(15,23,42,.7);
    border-radius: 0 0 0 0;
}
.modal-body::-webkit-scrollbar-track:hover {
    background: rgba(15,23,42,.9);
}
.modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #3b82f6 0%, #1e40af 100%);
    border-radius: 3px;
    border: 1px solid rgba(59,130,246,.2);
}
.modal-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #60a5fa 0%, #3b82f6 100%);
    box-shadow: 0 0 10px rgba(59,130,246,.5);
}
.modal-body::-webkit-scrollbar-corner { background: transparent; }

/* Indicateur scroll disponible */
.modal-scroll-hint {
    position: sticky; bottom: 8px;
    float: right; margin-right: -6px;
    width: 26px; height: 26px;
    background: rgba(59,130,246,.18);
    border: 1px solid rgba(59,130,246,.35);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #60a5fa; font-size: .65rem;
    pointer-events: none;
    opacity: 0; transition: opacity .3s;
    animation: scroll-bounce .85s ease-in-out infinite;
    z-index: 5;
}
.modal-scroll-hint.visible { opacity: 1; }
@keyframes scroll-bounce {
    0%,100% { transform: translateY(0); }
    50%      { transform: translateY(3px); }
}

</style>

<?php if($msg):?>
<div class="doj-alert doj-alert-<?php echo $msgType;?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?>
</div>
<?php endif;?>

<!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
<div class="cp-kpi-row">
    <div class="cp-kpi">
        <div class="cp-kpi-ico" style="background:rgba(59,130,246,.12);color:#60a5fa"><i class="fas fa-book-open"></i></div>
        <div><div class="cp-kpi-val" style="color:#60a5fa"><?php echo $kpis['total'];?></div><div class="cp-kpi-lbl">Total actifs</div></div>
    </div>
    <?php foreach($CAT_CFG as $k=>[$l,$col,$bg,$bdr,$ico]):?>
    <div class="cp-kpi" style="border-color:<?php echo $bdr;?>">
        <div class="cp-kpi-ico" style="background:<?php echo $bg;?>;color:<?php echo $col;?>"><i class="<?php echo $ico;?>"></i></div>
        <div><div class="cp-kpi-val" style="color:<?php echo $col;?>"><?php echo $kpis[$k]??0;?></div><div class="cp-kpi-lbl"><?php echo $l;?></div></div>
    </div>
    <?php endforeach;?>
</div>

<!-- ── TOOLBAR ───────────────────────────────────────────────────────────── -->
<div class="cp-toolbar">
    <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap">
        <!-- Filtres catégorie -->
        <div class="cp-filters">
            <a href="?cat=&q=<?php echo urlencode($search);?>" class="cp-filter-btn <?php echo !$categoryFilter?'active':'';?>">Tous</a>
            <?php foreach($CAT_CFG as $k=>[$l,$col]):?>
            <a href="?cat=<?php echo $k;?>&q=<?php echo urlencode($search);?>" class="cp-filter-btn <?php echo $categoryFilter===$k?'active':'';?>" style="<?php echo $categoryFilter===$k?"border-color:{$col};color:{$col};":'';?>"><?php echo $l;?></a>
            <?php endforeach;?>
        </div>
        <!-- Recherche -->
        <div class="cp-search">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:inline">
                <input type="hidden" name="cat" value="<?php echo htmlspecialchars($categoryFilter);?>">
                <input type="text" name="q" placeholder="Code, nom, titre…" value="<?php echo htmlspecialchars($search);?>">
            </form>
        </div>
    </div>
    <button class="btn btn-primary" onclick="DOJ.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Code Pénal</button>
</div>

<!-- ── TABLE ─────────────────────────────────────────────────────────────── -->
<div class="cp-table-wrap">
    <table class="cp-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Nom / Titre</th>
                <th>Catégorie</th>
                <th>Gravité</th>
                <th>Amendes (Nominal)</th>
                <th>UP (Nominal)</th>
                <th>Options</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($codes)):?>
        <tr><td colspan="8"><div class="cp-empty"><i class="fas fa-book-open"></i><div>Aucun code pénal trouvé</div><?php if($search||$categoryFilter):?><div style="font-size:.8rem;margin-top:.4rem">Essayez de modifier votre recherche ou filtre.</div><?php endif;?></div></td></tr>
        <?php else: foreach($codes as $pc):
            [$cl,$cc,$cbg,$cbdr,$cico] = $CAT_CFG[$pc['category']] ?? ['—','#64748b','rgba(100,116,139,.1)','rgba(100,116,139,.2)','fas fa-circle'];
            $gFines = json_decode($pc['gravity_fines']??'{}',true)?:[];
            $gUP    = json_decode($pc['gravity_up']??'{}',true)?:[];
            $nomFine= $gFines['nominal'] ?? $pc['max_fine'] ?? 0;
            $nomUP  = $gUP['nominal']   ?? $pc['max_jail_months'] ?? 0;
        ?>
        <tr class="<?php echo !$pc['active']?'inactive':'';?>">
            <!-- Code -->
            <td>
                <span class="cp-code-badge" style="background:<?php echo $cbg;?>;border-color:<?php echo $cbdr;?>;color:<?php echo $cc;?>"><?php echo htmlspecialchars($pc['code']);?></span>
            </td>
            <!-- Nom / Titre -->
            <td>
                <div style="font-weight:700;font-size:.85rem"><?php echo htmlspecialchars($pc['title']);?></div>
                <?php if(!empty($pc['nom_code'])):?><div style="font-size:.72rem;color:#64748b;margin-top:.1rem"><?php echo htmlspecialchars($pc['nom_code']);?></div><?php endif;?>
            </td>
            <!-- Catégorie -->
            <td><span class="cp-cat" style="background:<?php echo $cbg;?>;border-color:<?php echo $cbdr;?>;color:<?php echo $cc;?>"><i class="<?php echo $cico;?>" style="font-size:.6rem"></i><?php echo $cl;?></span></td>
            <!-- Gravité (strip) -->
            <td>
                <div class="grav-strip" title="Niveaux de gravité définis">
                    <?php foreach($GRAVITY_LEVELS as $lvl): $hasFine=!empty($gFines[$lvl]); $hasUP=!empty($gUP[$lvl]); ?>
                    <div class="grav-dot" style="background:<?php echo ($hasFine||$hasUP)?$GRAVITY_COLORS[$lvl]:'rgba(255,255,255,.06)';?>" title="<?php echo $GRAVITY_LABELS[$lvl];?>: $<?php echo number_format($gFines[$lvl]??0,0);?> / <?php echo $gUP[$lvl]??0;?>h"></div>
                    <?php endforeach;?>
                </div>
                <div style="font-size:.65rem;color:#475569;margin-top:.25rem"><?php echo count(array_filter($gFines));?>/7 niveaux</div>
            </td>
            <!-- Amende nominal -->
            <td>
                <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:<?php echo $nomFine>0?'#4ade80':'#475569';?>">$<?php echo number_format($nomFine,0,'.',',');?></span>
            </td>
            <!-- UP nominal -->
            <td>
                <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:<?php echo $nomUP>0?'#f97316':'#475569';?>"><?php echo $nomUP>0?$nomUP.'h':'—';?></span>
            </td>
            <!-- Options -->
            <td>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                    <?php if($pc['is_arrestable']):?><span style="padding:.18rem .5rem;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:4px;font-size:.62rem;font-weight:700;color:#ef4444;text-transform:uppercase">ARREST.</span><?php endif;?>
                    <?php if($pc['is_warrantable']):?><span style="padding:.18rem .5rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:4px;font-size:.62rem;font-weight:700;color:#f59e0b;text-transform:uppercase">MANDAT</span><?php endif;?>
                    <?php if(!$pc['active']):?><span style="padding:.18rem .5rem;background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.2);border-radius:4px;font-size:.62rem;font-weight:700;color:#64748b;text-transform:uppercase">INACTIF</span><?php endif;?>
                </div>
            </td>
            <!-- Actions -->
            <td style="text-align:right">
                <div style="display:flex;gap:.35rem;justify-content:flex-end">
                    <button class="cp-act" style="background:rgba(59,130,246,.1);color:#60a5fa;border:1px solid rgba(59,130,246,.22)"
                            onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($pc),ENT_QUOTES); ?>)">
                        <i class="fas fa-eye" style="font-size:.65rem"></i> Voir
                    </button>
                    <button class="cp-act" style="background:rgba(59,130,246,.08);color:#94a3b8;border:1px solid rgba(59,130,246,.15)"
                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($pc),ENT_QUOTES); ?>)">
                        <i class="fas fa-pen" style="font-size:.62rem"></i> Éditer
                    </button>
                    <form method="POST" style="display:inline;margin:0" data-doj-confirm="<?php echo $pc['active']?'Désactiver':'Activer';?> ce code pénal ?" data-doj-confirm-title="<?php echo $pc['active']?'Désactiver':'Activer';?> le code" data-doj-confirm-ok="<?php echo $pc['active']?'Désactiver':'Activer';?>" data-doj-confirm-danger="<?php echo $pc['active']?'true':'false';?>" data-doj-confirm-target="<?php echo htmlspecialchars($pc['code']);?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="code_id" value="<?php echo $pc['id'];?>">
                        <button type="submit" class="cp-act" style="background:<?php echo $pc['active']?'rgba(239,68,68,.08)':'rgba(34,197,94,.08)';?>;color:<?php echo $pc['active']?'#ef4444':'#22c55e';?>;border:1px solid <?php echo $pc['active']?'rgba(239,68,68,.22)':'rgba(34,197,94,.22)';?>">
                            <i class="fas fa-<?php echo $pc['active']?'ban':'check';?>" style="font-size:.62rem"></i>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;margin:0" data-doj-confirm="Supprimer définitivement ce code pénal ? Cette action est irréversible." data-doj-confirm-title="Supprimer le code pénal" data-doj-confirm-ok="Supprimer" data-doj-confirm-target="<?php echo htmlspecialchars($pc['code']);?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="code_id" value="<?php echo $pc['id'];?>">
                        <button type="submit" class="cp-act" style="background:rgba(239,68,68,.06);color:#ef4444;border:1px solid rgba(239,68,68,.15)">
                            <i class="fas fa-trash" style="font-size:.62rem"></i>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; endif;?>
        </tbody>
    </table>
</div>

<!-- ══ MODAL CRÉATION ══════════════════════════════════════════════════════ -->
<div id="addModal" class="modal-overlay hidden">
<div class="modal modal-large">
<div class="modal-header">
    <h2><i class="fas fa-plus-circle" style="color:#60a5fa"></i> Nouveau Code Pénal</h2>
    <button class="modal-close" onclick="DOJ.closeModal('addModal')">×</button>
</div>
<form method="POST" onsubmit="return saveEditorContent('addDescEditor','addDescription')">
<input type="hidden" name="action" value="add">
<div class="modal-body">

    <!-- Section : Informations principales -->
    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-id-card"></i> Informations principales</div>
    <div class="form-grid" style="margin-bottom:1.5rem">
        <div class="form-group">
            <label class="form-label">Code *</label>
            <input type="text" name="code" class="form-input" required placeholder="Ex: PC·487, VC·23152">
        </div>
        <div class="form-group">
            <label class="form-label">Catégorie *</label>
            <select name="category" class="form-select" required>
                <?php foreach($CAT_CFG as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full">
            <label class="form-label">Nom du code</label>
            <input type="text" name="nom_code" class="form-input" placeholder="Ex: Grand Vol, Homicide Involontaire…">
        </div>
        <div class="form-group form-full">
            <label class="form-label">Titre complet *</label>
            <input type="text" name="title" class="form-input" required placeholder="Titre détaillé de l'infraction">
        </div>
    </div>

    <!-- Section : Gravité — Amendes & UP -->
    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-scale-balanced"></i> Niveaux de gravité — Amendes ($) & UP (heures)</div>
    <div style="background:rgba(15,23,42,.6);border:1px solid rgba(59,130,246,.15);border-radius:10px;overflow:hidden;margin-bottom:1.5rem">
        <table class="gravity-table">
            <thead>
                <tr>
                    <th>Niveau</th>
                    <th>💰 Amende ($)</th>
                    <th>⛓️ UP (heures)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($GRAVITY_LEVELS as $lvl):?>
            <tr>
                <td><span class="gravity-lvl-tag" style="background:<?php echo $GRAVITY_COLORS[$lvl];?>22;color:<?php echo $GRAVITY_COLORS[$lvl];?>;border:1px solid <?php echo $GRAVITY_COLORS[$lvl];?>44"><?php echo $GRAVITY_LABELS[$lvl];?></span></td>
                <td><input type="number" name="fine_<?php echo $lvl;?>" value="0" min="0" step="0.01" placeholder="0.00"></td>
                <td><input type="number" name="up_<?php echo $lvl;?>" value="0" min="0" step="0.5" placeholder="0"></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>

    <!-- Section : Options -->
    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-sliders"></i> Options légales</div>
    <div class="form-grid" style="margin-bottom:1.5rem">
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:9px;cursor:pointer">
                <input type="checkbox" name="is_arrestable" style="accent-color:#ef4444;width:16px;height:16px">
                <div>
                    <div class="form-label" style="margin:0;color:#f87171">Arrestation possible</div>
                    <div style="font-size:.72rem;color:#64748b;margin-top:.1rem">Le suspect peut être interpellé</div>
                </div>
            </label>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:9px;cursor:pointer">
                <input type="checkbox" name="is_warrantable" style="accent-color:#f59e0b;width:16px;height:16px">
                <div>
                    <div class="form-label" style="margin:0;color:#fcd34d">Mandat possible</div>
                    <div style="font-size:.72rem;color:#64748b;margin-top:.1rem">Un mandat d'arrêt peut être émis</div>
                </div>
            </label>
        </div>
        <div class="form-group">
            <label class="form-label">Gravité globale (1-5)</label>
            <select name="severity" class="form-select">
                <option value="1">1 — Très mineur</option><option value="2">2 — Mineur</option>
                <option value="3" selected>3 — Modéré</option><option value="4">4 — Grave</option>
                <option value="5">5 — Critique</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Points permis</label>
            <input type="number" name="points" class="form-input" value="0" min="0">
        </div>
    </div>

    <!-- Section : Description -->
    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-file-lines"></i> Description</div>
    <div class="form-group form-full" style="margin-bottom:1.1rem">
        <div class="editor-toolbar">
            <button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button>
            <button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button>
            <button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button>
            <button type="button" class="editor-btn" onclick="insertList()">Liste</button>
        </div>
        <div id="addDescEditor" class="rich-editor" contenteditable="true" placeholder="Description de l'infraction, jurisprudence, notes…" style="min-height:130px"></div>
        <input type="hidden" name="description" id="addDescription">
    </div>
    <div class="form-group form-full">
        <label class="form-label">Exemples concrets</label>
        <textarea name="examples" class="form-textarea" placeholder="Cas typiques, exemples d'application…" style="min-height:80px"></textarea>
    </div>
</div>
<div class="modal-scroll-hint" id="addScrollHint"><i class="fas fa-chevron-down"></i></div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('addModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer le Code Pénal</button>
</div>
</form>
</div>
</div>

<!-- ══ MODAL ÉDITION ═══════════════════════════════════════════════════════ -->
<div id="editModal" class="modal-overlay hidden">
<div class="modal modal-large">
<div class="modal-header">
    <h2><i class="fas fa-pen" style="color:#60a5fa"></i> Modifier le Code Pénal</h2>
    <button class="modal-close" onclick="DOJ.closeModal('editModal')">×</button>
</div>
<form method="POST" onsubmit="return saveEditorContent('editDescEditor','editDescription')">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="code_id" id="editCodeId">
<div class="modal-body">
    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-id-card"></i> Informations principales</div>
    <div class="form-grid" style="margin-bottom:1.5rem">
        <div class="form-group"><label class="form-label">Code *</label><input type="text" name="code" id="eCode" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Catégorie *</label><select name="category" id="eCat" class="form-select" required><?php foreach($CAT_CFG as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group form-full"><label class="form-label">Nom du code</label><input type="text" name="nom_code" id="eNomCode" class="form-input"></div>
        <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="title" id="eTitle" class="form-input" required></div>
    </div>

    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-scale-balanced"></i> Niveaux de gravité</div>
    <div style="background:rgba(15,23,42,.6);border:1px solid rgba(59,130,246,.15);border-radius:10px;overflow:hidden;margin-bottom:1.5rem">
        <table class="gravity-table">
            <thead><tr><th>Niveau</th><th>💰 Amende ($)</th><th>⛓️ UP (heures)</th></tr></thead>
            <tbody>
            <?php foreach($GRAVITY_LEVELS as $lvl):?>
            <tr>
                <td><span class="gravity-lvl-tag" style="background:<?php echo $GRAVITY_COLORS[$lvl];?>22;color:<?php echo $GRAVITY_COLORS[$lvl];?>;border:1px solid <?php echo $GRAVITY_COLORS[$lvl];?>44"><?php echo $GRAVITY_LABELS[$lvl];?></span></td>
                <td><input type="number" name="fine_<?php echo $lvl;?>" id="eFine_<?php echo $lvl;?>" value="0" min="0" step="0.01"></td>
                <td><input type="number" name="up_<?php echo $lvl;?>" id="eUp_<?php echo $lvl;?>" value="0" min="0" step="0.5"></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>

    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-sliders"></i> Options légales</div>
    <div class="form-grid" style="margin-bottom:1.5rem">
        <div class="form-group"><label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:9px;cursor:pointer"><input type="checkbox" name="is_arrestable" id="eArrestable" style="accent-color:#ef4444;width:16px;height:16px"><div><div class="form-label" style="margin:0;color:#f87171">Arrestation possible</div></div></label></div>
        <div class="form-group"><label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:9px;cursor:pointer"><input type="checkbox" name="is_warrantable" id="eWarrantable" style="accent-color:#f59e0b;width:16px;height:16px"><div><div class="form-label" style="margin:0;color:#fcd34d">Mandat possible</div></div></label></div>
        <div class="form-group"><label class="form-label">Gravité globale</label><select name="severity" id="eSeverity" class="form-select"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></div>
        <div class="form-group"><label class="form-label">Points permis</label><input type="number" name="points" id="ePoints" class="form-input" value="0" min="0"></div>
    </div>

    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#3b82f6;margin-bottom:.9rem;display:flex;align-items:center;gap:.4rem"><i class="fas fa-file-lines"></i> Description</div>
    <div class="form-group form-full" style="margin-bottom:1.1rem">
        <div class="editor-toolbar">
            <button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button>
            <button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button>
            <button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button>
            <button type="button" class="editor-btn" onclick="insertList()">Liste</button>
        </div>
        <div id="editDescEditor" class="rich-editor" contenteditable="true" style="min-height:130px"></div>
        <input type="hidden" name="description" id="editDescription">
    </div>
    <div class="form-group form-full">
        <label class="form-label">Exemples</label>
        <textarea name="examples" id="eExamples" class="form-textarea" style="min-height:80px"></textarea>
    </div>
</div>
<div class="modal-scroll-hint" id="editScrollHint"><i class="fas fa-chevron-down"></i></div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('editModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
</div>
</form>
</div>
</div>

<!-- ══ MODAL DÉTAIL ════════════════════════════════════════════════════════ -->
<div id="detailModal" class="modal-overlay hidden">
<div class="modal modal-large">
<div class="modal-header">
    <h2 id="detailTitle"><i class="fas fa-book-open" style="color:#60a5fa"></i> Détail du Code Pénal</h2>
    <button class="modal-close" onclick="DOJ.closeModal('detailModal')">×</button>
</div>
<div class="modal-body" id="detailContent" style="padding:1.75rem 2rem"></div>
<div class="modal-footer">
    <button class="btn btn-secondary" onclick="DOJ.closeModal('detailModal')">Fermer</button>
</div>
</div>
</div>

<script>
const GRAVITY_LEVELS  = <?php echo json_encode($GRAVITY_LEVELS); ?>;
const GRAVITY_LABELS  = <?php echo json_encode($GRAVITY_LABELS); ?>;
const GRAVITY_COLORS  = <?php echo json_encode($GRAVITY_COLORS); ?>;
const CAT_CFG         = <?php echo json_encode(array_map(fn($v)=>[$v[0],$v[1]],$CAT_CFG)); ?>;

// ── Ouvrir modal édition ──────────────────────────────────────────────────
function openEditModal(pc) {
    document.getElementById('editCodeId').value = pc.id;
    document.getElementById('eCode').value      = pc.code;
    document.getElementById('eNomCode').value   = pc.nom_code || '';
    document.getElementById('eCat').value        = pc.category;
    document.getElementById('eTitle').value      = pc.title;
    document.getElementById('eSeverity').value   = pc.severity;
    document.getElementById('ePoints').value     = pc.points || 0;
    document.getElementById('eArrestable').checked  = !!parseInt(pc.is_arrestable);
    document.getElementById('eWarrantable').checked = !!parseInt(pc.is_warrantable);
    document.getElementById('editDescEditor').innerHTML = pc.description_rich || '';
    document.getElementById('eExamples').value   = pc.examples || '';

    const gF = pc.gravity_fines ? (typeof pc.gravity_fines === 'string' ? JSON.parse(pc.gravity_fines) : pc.gravity_fines) : {};
    const gU = pc.gravity_up    ? (typeof pc.gravity_up    === 'string' ? JSON.parse(pc.gravity_up)    : pc.gravity_up)    : {};
    GRAVITY_LEVELS.forEach(lvl => {
        const fi = document.getElementById('eFine_'+lvl);
        const ui = document.getElementById('eUp_'+lvl);
        if (fi) fi.value = gF[lvl] || 0;
        if (ui) ui.value = gU[lvl] || 0;
    });
    DOJ.openModal('editModal');
}

// ── Ouvrir modal détail ───────────────────────────────────────────────────
// ── Scroll hints pour les modals ─────────────────────────────────────────
function initScrollHint(modalId, hintId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    const body  = modal.querySelector('.modal-body');
    const hint  = document.getElementById(hintId);
    if (!body || !hint) return;

    function check() {
        // Affiche le hint si on peut encore scroller vers le bas (>30px restants)
        const canScroll = body.scrollHeight - body.scrollTop - body.clientHeight > 30;
        hint.classList.toggle('visible', canScroll);
    }

    body.addEventListener('scroll', check, { passive: true });
    // Observer pour recalculer quand le contenu change
    const ro = new ResizeObserver(check);
    ro.observe(body);
    // Vérification initiale légèrement retardée (après animation d'ouverture)
    modal.addEventListener('transitionend', check, { once: false });
    check();
    return check;
}

// Activer les hints au premier clic sur les boutons d'ouverture
const _origOpenModal = DOJ.openModal.bind(DOJ);
DOJ.openModal = function(id) {
    _origOpenModal(id);
    requestAnimationFrame(() => {
        if (id === 'addModal')  initScrollHint('addModal',  'addScrollHint');
        if (id === 'editModal') initScrollHint('editModal', 'editScrollHint');
    });
};

function openDetailModal(pc) {
    const gF  = pc.gravity_fines ? (typeof pc.gravity_fines === 'string' ? JSON.parse(pc.gravity_fines) : pc.gravity_fines) : {};
    const gU  = pc.gravity_up    ? (typeof pc.gravity_up    === 'string' ? JSON.parse(pc.gravity_up)    : pc.gravity_up)    : {};
    const catInfo = CAT_CFG[pc.category] || ['—','#64748b'];
    const fmt = n => '$' + parseFloat(n||0).toLocaleString('fr-FR', {minimumFractionDigits:0});

    let gravRows = GRAVITY_LEVELS.map(lvl => {
        const f=parseFloat(gF[lvl]||0), u=parseFloat(gU[lvl]||0);
        if(f===0&&u===0) return '';
        return `<div class="detail-gravity-row">
            <span style="display:inline-block;padding:.18rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;font-family:'JetBrains Mono',monospace;background:${GRAVITY_COLORS[lvl]}22;color:${GRAVITY_COLORS[lvl]};border:1px solid ${GRAVITY_COLORS[lvl]}44">${GRAVITY_LABELS[lvl]}</span>
            <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:#4ade80">${f>0?fmt(f):'—'}</span>
            <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:#f97316">${u>0?u+'h':'—'}</span>
        </div>`;
    }).filter(Boolean).join('');

    const flags = [
        pc.is_arrestable?'<span style="padding:.3rem .75rem;background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.25);border-radius:6px;font-size:.73rem;font-weight:700;text-transform:uppercase"><i class="fas fa-handcuffs" style="margin-right:.35rem"></i>Arrestation possible</span>':'',
        pc.is_warrantable?'<span style="padding:.3rem .75rem;background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.25);border-radius:6px;font-size:.73rem;font-weight:700;text-transform:uppercase"><i class="fas fa-gavel" style="margin-right:.35rem"></i>Mandat possible</span>':'',
    ].filter(Boolean).join('');

    document.getElementById('detailContent').innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap">
            <div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:1.3rem;font-weight:800;color:#60a5fa;margin-bottom:.35rem">${pc.code}</div>
                <div style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em">${pc.nom_code||''}</div>
            </div>
            <div style="flex:1">
                <div style="font-size:1.05rem;font-weight:700;margin-bottom:.5rem;color:#f1f5f9">${pc.title}</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">${flags}</div>
            </div>
        </div>
        ${gravRows?`<div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem"><i class="fas fa-scale-balanced" style="margin-right:.35rem"></i>Barème de sanctions</div>
        <div style="background:rgba(15,23,42,.6);border:1px solid rgba(59,130,246,.15);border-radius:10px;overflow:hidden;margin-bottom:1.25rem">
            <div style="display:grid;grid-template-columns:90px 1fr 1fr;padding:.5rem .85rem;background:rgba(59,130,246,.07);border-bottom:1px solid rgba(59,130,246,.1);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#475569">
                <span>Niveau</span><span>💰 Amende</span><span>⛓️ UP</span>
            </div>${gravRows}</div>`:''}
        ${pc.description_rich?`<div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem"><i class="fas fa-file-lines" style="margin-right:.35rem"></i>Description</div>
        <div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:9px;padding:1rem 1.1rem;font-size:.85rem;line-height:1.7;color:#94a3b8;margin-bottom:1rem">${pc.description_rich}</div>`:''}
        ${pc.examples?`<div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem"><i class="fas fa-lightbulb" style="margin-right:.35rem"></i>Exemples</div>
        <div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:9px;padding:1rem 1.1rem;font-size:.83rem;line-height:1.65;color:#94a3b8;white-space:pre-wrap">${pc.examples}</div>`:''}
    `;
    document.getElementById('detailTitle').innerHTML = `<i class="fas fa-book-open" style="color:#60a5fa"></i> ${pc.code} — ${pc.title}`;
    DOJ.openModal('detailModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>