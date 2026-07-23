<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');

// ── Accès restreint : Lieutenant I minimum (>= 55% de la hiérarchie) ──────
if (!hasRole(['*','Administrateur','Développeur','Staff'])) {
    $__logRanks = [];
    try {
        $__logDb = Database::getInstance()->getConnection();
        $__logS  = $__logDb->prepare("SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSSD' ORDER BY fg.rank_order ASC");
        $__logS->execute(); $__logRanks = array_column($__logS->fetchAll(),'name');
        if (empty($__logRanks)) {
            $__fid = $__logDb->prepare("SELECT id FROM factions WHERE faction_key='LSSD' LIMIT 1");
            $__fid->execute(); $__fid2=$__fid->fetchColumn();
            if ($__fid2) { $__s2=$__logDb->prepare("SELECT name FROM faction_grades WHERE faction_id=? ORDER BY rank_order ASC"); $__s2->execute([$__fid2]); $__logRanks=array_column($__s2->fetchAll(),'name'); }
        }
    } catch(Exception $e){}
    if (empty($__logRanks)) $__logRanks = ['Sheriff','Undersheriff','Assistant Sheriff','Captain','Lieutenant','Lieutenant','Sergeant','Sergeant','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff Trainee'];
    $__logLevel = []; foreach($__logRanks as $__i=>$__rn) $__logLevel[$__rn] = count($__logRanks)-$__i;
    try { $__logGrS=$__logDb->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__logGrS->execute([(int)($_SESSION['user_id']??0)]);$__logGrRow=$__logGrS->fetch();$__logRankVal=$__logGrRow['faction_rank']??''; } catch(Exception $e){ $__logRankVal=$_SESSION['user_faction_rank']??''; }
    $__myLvl = $__logLevel[$__logRankVal] ?? 0;
    // Seuil Sergeant I = 40%
    $__ltThreshold = count($__logRanks) > 0 ? (int)ceil(count($__logRanks)*0.40) : 5;
    if ($__myLvl < $__ltThreshold) redirect(SITE_URL.'/pages/dashboard.php');
}
$pageTitle="Logs d'activité";
$db = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id']??0);

// ── Création silencieuse de la table si elle n'existe pas ─────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `lssd_activity_logs` (
        `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT(11) NOT NULL,
        `username`    VARCHAR(100) NOT NULL DEFAULT '',
        `faction_rank`VARCHAR(100) NOT NULL DEFAULT '',
        `action`      VARCHAR(80)  NOT NULL COMMENT 'ex: recruit, promote, fire, add_report...',
        `page`        VARCHAR(60)  NOT NULL COMMENT 'ex: effectifs, reports, casiers...',
        `target_id`   INT(11) DEFAULT NULL  COMMENT 'id de la ressource affectée',
        `target_label`VARCHAR(200) DEFAULT NULL COMMENT 'ex: nom de l officier, n rapport...',
        `details`     TEXT         DEFAULT NULL COMMENT 'JSON ou texte libre',
        `ip`          VARCHAR(45)  DEFAULT NULL,
        `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user`    (`user_id`),
        KEY `idx_page`    (`page`),
        KEY `idx_action`  (`action`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch(Exception $e){}

// ── Filtres ────────────────────────────────────────────────────────────────
$fUser   = trim($_GET['user']??'');
$fPage   = $_GET['page_filter']??'all';
$fAction = $_GET['action_filter']??'all';
$fDate   = $_GET['date']??'all';
$search  = trim($_GET['q']??'');
$perPage = 50;
$pageNum = max(1, intval($_GET['p']??1));
$offset  = ($pageNum-1)*$perPage;

$where = ['1=1']; $params = [];
if ($fUser)           { $where[] = '(l.username LIKE ? OR l.faction_rank LIKE ?)'; $params[] = "%$fUser%"; $params[] = "%$fUser%"; }
if ($fPage!=='all')   { $where[] = 'l.page=?';   $params[] = $fPage; }
if ($fAction!=='all') { $where[] = 'l.action=?'; $params[] = $fAction; }
if ($search)          { $where[] = '(l.target_label LIKE ? OR l.details LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
switch($fDate) {
    case 'today': $where[] = 'DATE(l.created_at)=CURDATE()'; break;
    case 'week':  $where[] = 'l.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)'; break;
    case 'month': $where[] = 'l.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)'; break;
}
$whereStr = implode(' AND ', $where);

// Total pour pagination
$totalRows = 0;
try { $cs=$db->prepare("SELECT COUNT(*) FROM lssd_activity_logs l WHERE $whereStr"); $cs->execute($params); $totalRows=(int)$cs->fetchColumn(); } catch(Exception $e){}

// Logs
$logs = [];
try {
    $ls=$db->prepare("SELECT l.* FROM lssd_activity_logs l WHERE $whereStr ORDER BY l.created_at DESC LIMIT $perPage OFFSET $offset");
    $ls->execute($params); $logs=$ls->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// Listes pour les filtres (pages et actions distinctes)
$pages   = []; try { $pages  =array_column($db->query("SELECT DISTINCT page   FROM lssd_activity_logs ORDER BY page")->fetchAll(),'page'); } catch(Exception $e){}
$actions = []; try { $actions=array_column($db->query("SELECT DISTINCT action FROM lssd_activity_logs ORDER BY action")->fetchAll(),'action'); } catch(Exception $e){}

$totalPages = ceil($totalRows / $perPage);

// ── Libellés humains ───────────────────────────────────────────────────────
$ACTION_LABELS = [
    // Effectifs
    'recruit'         => ['Recrutement',        'fas fa-user-plus',    '#22c55e'],
    'fire'            => ['Licenciement',        'fas fa-user-slash',   '#ef4444'],
    'promote'         => ['Changement de grade', 'fas fa-arrow-up',     '#10b981'],
    'update_agent'    => ['Modif. agent',        'fas fa-pen',          '#64748b'],
    'add_note'        => ['Note IA',             'fas fa-note-sticky',  '#f59e0b'],
    // Rapports
    'submit_report'   => ['Rapport créé',        'fas fa-file-alt',     '#10b981'],
    'edit_report'     => ['Rapport modifié',     'fas fa-pen-to-square','#f59e0b'],
    'delete_report'   => ['Rapport supprimé',    'fas fa-trash',        '#ef4444'],
    // Casiers
    'add_record'      => ['Ajout casier',        'fas fa-plus',         '#10b981'],
    'edit_record'     => ['Modif. casier',       'fas fa-pen',          '#f59e0b'],
    'delete_record'   => ['Supp. casier',        'fas fa-trash',        '#ef4444'],
    // Sanctions
    'add_sanction'    => ['Sanction',            'fas fa-gavel',        '#f97316'],
    'remove_sanction' => ['Levée sanction',      'fas fa-gavel',        '#22c55e'],
    // Véhicules
    'add_vehicle'     => ['Véhicule enregistré', 'fas fa-car',          '#10b981'],
    'edit_vehicle'    => ['Véhicule modifié',    'fas fa-pen',          '#f59e0b'],
    'flag_vehicle'    => ['Véhicule signalé',    'fas fa-flag',         '#f97316'],
    'unflag_vehicle'  => ['Véhicule désignalé',  'fas fa-flag-checkered','#22c55e'],
    // Divisions
    'add_division'    => ['Division créée',      'fas fa-plus',         '#10b981'],
    'edit_division'   => ['Division modifiée',   'fas fa-pen',          '#f59e0b'],
    'delete_division' => ['Division supprimée',  'fas fa-trash',        '#ef4444'],
    'add_member'      => ['Membre ajouté div.',  'fas fa-user-plus',    '#22c55e'],
    'remove_member'   => ['Membre retiré div.',  'fas fa-user-minus',   '#ef4444'],
    'update_salary'   => ['Salaire/perms modifié','fas fa-coins',       '#f59e0b'],
    // PPA
    'ppa_approve'     => ['PPA approuvée',       'fas fa-check',        '#22c55e'],
    'ppa_reject'      => ['PPA refusée',         'fas fa-times',        '#ef4444'],
    // Saisies
    'add_saisie'      => ['Saisie créée',        'fas fa-box',          '#8b5cf6'],
    'edit_saisie'     => ['Saisie modifiée',     'fas fa-pen',          '#f59e0b'],
    'delete_saisie'   => ['Saisie supprimée',    'fas fa-trash',        '#ef4444'],
    // Défaut
    'action'          => ['Action',              'fas fa-bolt',         '#64748b'],
];

$PAGE_LABELS = [
    'effectifs'=>'Effectifs','reports'=>'Rapports','casiers'=>'Casiers',
    'sanctions'=>'Sanctions','vehicles'=>'Véhicules','divisions'=>'Divisions',
    'ppa'=>'PPA','saisies'=>'Saisies','ia'=>'Affaires Internes',
    'profils'=>'Profils','plainte'=>'Plaintes','dispatch'=>'Dispatch',
    'service'=>'Service','convocation'=>'Convocations',
];

include __DIR__ . '/includes/header.php';
?>
<style>
.log-row-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .52rem;border-radius:5px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.log-page-tag{padding:.15rem .45rem;border-radius:4px;font-size:.62rem;font-weight:700;background:rgba(16,185,129,.1);color:var(--blue-xl);border:1px solid rgba(16,185,129,.2);letter-spacing:.04em}
.filter-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1.1rem}
.filter-row select,.filter-row input{font-size:.78rem}
.log-tbl td{padding:.6rem .85rem;font-size:.8rem;vertical-align:middle}
.log-tbl th{padding:.55rem .85rem;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);background:rgba(16,185,129,.05);border-bottom:1px solid var(--bdr)}
.log-details{font-size:.72rem;color:var(--txt-m);font-style:italic;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
<?php
$kpiData = [
    ['label'=>'Total actions', 'q'=>"SELECT COUNT(*) FROM lssd_activity_logs",                                        'color'=>'blue'],
    ['label'=>'Aujourd\'hui',  'q'=>"SELECT COUNT(*) FROM lssd_activity_logs WHERE DATE(created_at)=CURDATE()",       'color'=>'green'],
    ['label'=>'7 derniers j.', 'q'=>"SELECT COUNT(*) FROM lssd_activity_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",'color'=>'yellow'],
    ['label'=>'Agents actifs', 'q'=>"SELECT COUNT(DISTINCT user_id) FROM lssd_activity_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",'color'=>'purple'],
];
foreach($kpiData as $k):
    $val=0; try{$val=(int)$db->query($k['q'])->fetchColumn();}catch(Exception $e){}
?>
<div class="kpi"><div class="kpi-lbl"><?php echo $k['label'];?></div><div class="kpi-val <?php echo $k['color'];?>"><?php echo number_format($val);?></div></div>
<?php endforeach;?>
</div>

<!-- Filtres -->
<form method="GET" class="filter-row">
    <input type="text" name="q" class="form-input" placeholder="Rechercher cible, détails..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:160px">
    <input type="text" name="user" class="form-input" placeholder="Agent..." value="<?php echo htmlspecialchars($fUser);?>" style="width:140px">
    <select name="page_filter" class="form-select" style="width:140px">
        <option value="all">Toutes les pages</option>
        <?php foreach($pages as $pg):?><option value="<?php echo $pg;?>" <?php echo $fPage===$pg?'selected':'';?>><?php echo $PAGE_LABELS[$pg]??$pg;?></option><?php endforeach;?>
    </select>
    <select name="action_filter" class="form-select" style="width:160px">
        <option value="all">Toutes les actions</option>
        <?php foreach($actions as $ac):$aLbl=$ACTION_LABELS[$ac][0]??$ac;?><option value="<?php echo $ac;?>" <?php echo $fAction===$ac?'selected':'';?>><?php echo $aLbl;?></option><?php endforeach;?>
    </select>
    <select name="date" class="form-select" style="width:130px">
        <?php foreach(['all'=>'Toutes dates','today'=>"Aujourd'hui",'week'=>'7 jours','month'=>'30 jours'] as $k=>$l):?><option value="<?php echo $k;?>" <?php echo $fDate===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    <a href="logs.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
</form>

<!-- Tableau -->
<div class="tbl-wrap">
    <table class="tbl log-tbl">
        <thead><tr><th>Date & heure</th><th>Agent</th><th>Page</th><th>Action</th><th>Cible</th><th>Détails</th></tr></thead>
        <tbody>
        <?php if(empty($logs)):?>
        <tr><td colspan="6" style="text-align:center;padding:3.5rem;color:var(--txt-m)">
            <i class="fas fa-clipboard-list" style="font-size:2.5rem;opacity:.18;display:block;margin-bottom:.6rem"></i>
            Aucun log trouvé<?php echo ($search||$fPage!=='all'||$fAction!=='all'||$fUser||$fDate!=='all')?' pour ces filtres':'';?>.
            <?php if(empty($pages)):?><br><span style="font-size:.78rem">Les logs s'enregistreront automatiquement dès la première action effectuée sur le panel.</span><?php endif;?>
        </td></tr>
        <?php else:foreach($logs as $l):
            [$aLbl,$aIcon,$aColor]=$ACTION_LABELS[$l['action']]??['Action','fas fa-bolt','#64748b'];
        ?>
        <tr>
            <!-- Date -->
            <td class="mono" style="font-size:.72rem;color:var(--txt-m);white-space:nowrap">
                <?php echo date('d/m/Y'  ,strtotime($l['created_at']));?><br>
                <span style="color:var(--txt-s)"><?php echo date('H:i:s',strtotime($l['created_at']));?></span>
            </td>
            <!-- Agent -->
            <td>
                <div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($l['username']);?></div>
                <?php if($l['faction_rank']):?><div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($l['faction_rank']);?></div><?php endif;?>
            </td>
            <!-- Page -->
            <td><span class="log-page-tag"><?php echo htmlspecialchars($PAGE_LABELS[$l['page']]??$l['page']);?></span></td>
            <!-- Action -->
            <td>
                <span class="log-row-badge" style="background:<?php echo $aColor;?>18;color:<?php echo $aColor;?>;border:1px solid <?php echo $aColor;?>33">
                    <i class="<?php echo $aIcon;?>" style="font-size:.55rem"></i>
                    <?php echo $aLbl;?>
                </span>
            </td>
            <!-- Cible -->
            <td style="font-size:.8rem"><?php echo htmlspecialchars($l['target_label']??'—');?></td>
            <!-- Détails -->
            <td><div class="log-details" title="<?php echo htmlspecialchars($l['details']??'');?>"><?php echo htmlspecialchars($l['details']??'—');?></div></td>

        </tr>
        <?php endforeach;endif;?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if($totalPages>1):?>
<div style="display:flex;align-items:center;gap:.4rem;padding:.75rem 0;justify-content:center;flex-wrap:wrap">
    <?php
    $qStr=http_build_query(array_filter(['q'=>$search,'user'=>$fUser,'page_filter'=>$fPage!=='all'?$fPage:'','action_filter'=>$fAction!=='all'?$fAction:'','date'=>$fDate!=='all'?$fDate:'']));
    for($i=1;$i<=$totalPages;$i++):
        $isAct=($i===$pageNum);
    ?>
    <a href="?<?php echo $qStr;?>&p=<?php echo $i;?>" style="display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid <?php echo $isAct?'var(--blue)':'var(--bdr)';?>;background:<?php echo $isAct?'rgba(16,185,129,.18)':'transparent';?>;color:<?php echo $isAct?'var(--blue-xl)':'var(--txt-s)';?>;text-decoration:none;font-size:.78rem;font-weight:<?php echo $isAct?'700':'400';?>"><?php echo $i;?></a>
    <?php endfor;?>
    <span style="font-size:.72rem;color:var(--txt-m);margin-left:.5rem"><?php echo $totalRows;?> entrée(s)</span>
</div>
<?php endif;?>

<?php include __DIR__ . '/includes/footer.php';?>