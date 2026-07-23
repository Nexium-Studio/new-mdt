<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Rapports Rookie'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

try{$db->exec("CREATE TABLE IF NOT EXISTS `lssd_rapports_rookie` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `numero` varchar(30) NOT NULL,
    `agent_referent_id` int(11) NOT NULL COMMENT 'FK → users.id — Agent qui évalue',
    `referent_patrouille_id` int(11) DEFAULT NULL COMMENT 'FK → users.id',
    `rookie_id` int(11) NOT NULL COMMENT 'FK → users.id — Le rookie évalué',
    `date_patrouille` date NOT NULL,
    `gestion_radio` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
    `connaissance_procedure` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
    `gestion_conduite` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
    `communication_civils` enum('acquis','non_acquis','en_cours') DEFAULT 'en_cours',
    `observation` text NOT NULL COMMENT 'Min 50 caractères',
    `conclusion` text DEFAULT NULL,
    `recommandation` enum('continuer','attente','retirer','promouvoir') DEFAULT 'continuer',
    `note_globale` tinyint(1) DEFAULT NULL COMMENT '1-5',
    `statut` enum('brouillon','soumis','valide','archive') DEFAULT 'soumis',
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

require_once __DIR__ . '/../../includes/faction_acl.php';
$canEditForm = faction_acl_can($db, $uid, 'LSSD', 'rapport-rookie.edit_form');

// Builder : questions personnalisées + colonne réponses custom
try {$db->exec("CREATE TABLE IF NOT EXISTS `lssd_rookie_form_questions` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `label` varchar(255) NOT NULL,
    `qtype` enum('texte','boutons') NOT NULL DEFAULT 'texte',
    `options` text DEFAULT NULL,
    `required` tinyint(1) NOT NULL DEFAULT 0,
    `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}
try{$db->exec("ALTER TABLE lssd_rapports_rookie ADD COLUMN IF NOT EXISTS custom_answers TEXT NULL");}catch(Exception $e){}

// ── AJAX builder (gated rapport-rookie.edit_form) ─────────────────────────────
$__rkAction = $_POST['rk_action'] ?? $_GET['rk_action'] ?? '';
if ($__rkAction !== '') {
    header('Content-Type: application/json');
    if ($__rkAction === 'form_list') {
        $qs=[]; try{$qs=$db->query("SELECT * FROM lssd_rookie_form_questions ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
        echo json_encode(['success'=>true,'questions'=>$qs]); exit;
    }
    if (!$canEditForm) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Permission requise']); exit; }
    if ($__rkAction === 'form_save') {
        $id=(int)($_POST['id']??0); $label=trim($_POST['label']??'');
        $qtype=in_array($_POST['qtype']??'',['texte','boutons'],true)?$_POST['qtype']:'texte';
        $opts=trim($_POST['options']??''); $optsJson=null;
        if($qtype==='boutons'){ $arr=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',$opts)))); $optsJson=$arr?json_encode($arr):null; }
        $req=!empty($_POST['required'])?1:0;
        if($label===''){ echo json_encode(['success'=>false,'error'=>'Libellé requis']); exit; }
        try{
            if($id>0){ $db->prepare("UPDATE lssd_rookie_form_questions SET label=?,qtype=?,options=?,required=? WHERE id=?")->execute([$label,$qtype,$optsJson,$req,$id]); }
            else { $ord=(int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM lssd_rookie_form_questions")->fetchColumn();
                   $db->prepare("INSERT INTO lssd_rookie_form_questions (label,qtype,options,required,sort_order) VALUES (?,?,?,?,?)")->execute([$label,$qtype,$optsJson,$req,$ord]); }
            echo json_encode(['success'=>true]);
        }catch(Exception $e){ echo json_encode(['success'=>false,'error'=>'Erreur DB']); }
        exit;
    }
    if ($__rkAction === 'form_delete') {
        $db->prepare("DELETE FROM lssd_rookie_form_questions WHERE id=?")->execute([(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($__rkAction === 'form_reorder') {
        $ids=json_decode($_POST['ids']??'[]',true)?:[]; $st=$db->prepare("UPDATE lssd_rookie_form_questions SET sort_order=? WHERE id=?");
        foreach($ids as $i=>$qid){ $st->execute([$i,(int)$qid]); }
        echo json_encode(['success'=>true]); exit;
    }
    echo json_encode(['success'=>false,'error'=>'Action inconnue']); exit;
}

// Questions personnalisées (pour le formulaire de création et la vue)
$customQuestions=[]; try{$customQuestions=$db->query("SELECT * FROM lssd_rookie_form_questions ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $obs=trim($_POST['observation']??'');
            if (mb_strlen(strip_tags($obs))<50) throw new Exception('L\'observation doit faire au minimum 50 caractères.');
            $num='RK-'.date('Y').'-'.str_pad((int)$db->query("SELECT COUNT(*)+1 FROM lssd_rapports_rookie")->fetchColumn(),4,'0',STR_PAD_LEFT);
            // Réponses aux questions personnalisées (cq_<id>)
            $custom=[]; foreach($customQuestions as $cq){ $v=trim($_POST['cq_'.$cq['id']]??''); if($v!=='') $custom[$cq['id']]=$v; }
            $customJson = $custom ? json_encode($custom) : null;
            $db->prepare("INSERT INTO lssd_rapports_rookie (numero,agent_referent_id,referent_patrouille_id,rookie_id,date_patrouille,gestion_radio,connaissance_procedure,gestion_conduite,communication_civils,observation,conclusion,recommandation,note_globale,statut,created_by,custom_answers) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,$uid,intval($_POST['referent_patrouille_id'])?:null,intval($_POST['rookie_id']),$_POST['date_patrouille'],$_POST['gestion_radio'],$_POST['connaissance_procedure'],$_POST['gestion_conduite'],$_POST['communication_civils'],$obs,trim($_POST['conclusion']??''),$_POST['recommandation'],intval($_POST['note_globale'])?:null,$_POST['statut']??'soumis',$uid,$customJson]);
            $msg="Rapport $num créé.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='delete') {
        $db->prepare("DELETE FROM lssd_rapports_rookie WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Rapport supprimé.';$msgType='success';
    }
    if ($msg) { header('Location: rapport-rookie.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ───────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(rk.numero LIKE ? OR ur.username LIKE ? OR ua.username LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lssd_rapports_rookie rk LEFT JOIN users ur ON ur.id=rk.rookie_id LEFT JOIN users ua ON ua.id=rk.agent_referent_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));

$rapports=[];
try{$s=$db->prepare("SELECT rk.*,ur.username AS rookie_name,ua.username AS agent_name,up.username AS patrouille_name FROM lssd_rapports_rookie rk LEFT JOIN users ur ON ur.id=rk.rookie_id LEFT JOIN users ua ON ua.id=rk.agent_referent_id LEFT JOIN users up ON up.id=rk.referent_patrouille_id $wStr ORDER BY rk.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$rapports=$s->fetchAll();}catch(Exception $e){}

$officiers=[];try{$officiers=$db->query("SELECT id,username,faction_rank FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$ACQS=['acquis'=>['Acquis','bg-green'],'non_acquis'=>['Non acquis','bg-red'],'en_cours'=>['En cours','bg-yellow']];
$RECS=['continuer'=>['Continuer','bg-blue'],'attente'=>['En attente','bg-yellow'],'retirer'=>['Retirer','bg-red'],'promouvoir'=>['Promouvoir','bg-green']];
$CRITERIA=[
    ['key'=>'gestion_radio','label'=>'Gestion radio','icon'=>'fas fa-radio'],
    ['key'=>'connaissance_procedure','label'=>'Connaissance des procédures','icon'=>'fas fa-book'],
    ['key'=>'gestion_conduite','label'=>'Gestion de la conduite','icon'=>'fas fa-car'],
    ['key'=>'communication_civils','label'=>'Communication avec les civils','icon'=>'fas fa-comments'],
];

/* ═══════════════════════════════ VUE RAPPORT (page longue, sans scroll modal) ═══════════════════════════════ */
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $rp=null;
    try{$s=$db->prepare("SELECT rk.*, ur.username AS rookie_name, urp.firstname AS rf, urp.lastname AS rl,
                                ua.username AS agent_name, uap.firstname AS af, uap.lastname AS al,
                                up.username AS patrouille_name
                         FROM lssd_rapports_rookie rk
                         LEFT JOIN users ur ON ur.id=rk.rookie_id LEFT JOIN profiles urp ON urp.id=ur.profile_id
                         LEFT JOIN users ua ON ua.id=rk.agent_referent_id LEFT JOIN profiles uap ON uap.id=ua.profile_id
                         LEFT JOIN users up ON up.id=rk.referent_patrouille_id
                         WHERE rk.id=? LIMIT 1");$s->execute([$viewId]);$rp=$s->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){}
    if(!$rp) redirect(SITE_URL.'/pages/lssd/rapport-rookie.php');

    $rookieDisp=mdt_rp_display_name($rp['rf']??null,$rp['rl']??null,(string)($rp['rookie_name']??''));
    $agentDisp =mdt_rp_display_name($rp['af']??null,$rp['al']??null,(string)($rp['agent_name']??''));
    [$recL,$recB]=$RECS[$rp['recommandation']]??[$rp['recommandation'],'bg-gray'];
    $customAns=json_decode($rp['custom_answers']??'[]',true)?:[];
    $note=(int)($rp['note_globale']??0);

    $pageTitle='Rapport '.$rp['numero'];
    include __DIR__ . '/includes/header.php';
    ?>
    <style>
    .rkv-wrap{max-width:900px;margin:0 auto}
    .rkv-header{position:sticky;top:64px;z-index:50;background:linear-gradient(140deg,var(--bg-e),var(--bg-c));border:1px solid rgba(16,185,129,.28);border-radius:14px;padding:1.1rem 1.3rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap;box-shadow:0 8px 30px rgba(0,0,0,.35)}
    .rkv-id{font-family:'IBM Plex Mono',monospace;font-size:1.05rem;font-weight:800;color:var(--blue-l)}
    .rkv-who{display:flex;flex-direction:column;gap:.1rem}
    .rkv-who .lbl{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m)}
    .rkv-who .val{font-weight:700;font-size:.88rem}
    .rkv-block{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;padding:1.2rem 1.35rem;margin-bottom:1.1rem}
    .rkv-block h3{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin:0 0 .9rem;display:flex;align-items:center;gap:.5rem}
    .rkv-block h3 i{color:var(--blue-l)}
    .rkv-crit{display:flex;align-items:center;justify-content:space-between;padding:.7rem .9rem;border-radius:10px;background:var(--bg-ov);border:1px solid var(--bdr);margin-bottom:.55rem}
    .rkv-crit .ck-status{display:inline-flex;align-items:center;gap:.4rem;font-weight:700;font-size:.78rem}
    .rkv-text{font-size:.9rem;line-height:1.75;white-space:pre-wrap}
    .rkv-stars{font-size:1.5rem;color:#f59e0b;letter-spacing:.15rem}
    </style>
    <div class="rkv-wrap">
      <a href="rapport-rookie.php" class="btn btn-ghost btn-sm" style="margin-bottom:1rem"><i class="fas fa-arrow-left"></i> Retour à la liste</a>

      <div class="rkv-header">
        <div class="rkv-id"><?php echo htmlspecialchars($rp['numero']);?></div>
        <div class="rkv-who"><span class="lbl">Rookie</span><span class="val"><?php echo htmlspecialchars($rookieDisp);?></span></div>
        <div class="rkv-who"><span class="lbl">Agent référent</span><span class="val"><?php echo htmlspecialchars($agentDisp);?></span></div>
        <div class="rkv-who"><span class="lbl">Date patrouille</span><span class="val"><?php echo $rp['date_patrouille']?date('d/m/Y',strtotime($rp['date_patrouille'])):'—';?></span></div>
        <div style="margin-left:auto"><span class="badge <?php echo $recB;?>"><?php echo $recL;?></span></div>
      </div>

      <div class="rkv-block">
        <h3><i class="fas fa-clipboard-check"></i> Évaluation</h3>
        <?php foreach($CRITERIA as $c): $val=$rp[$c['key']]??'en_cours';
            $ic=$val==='acquis'?'fa-circle-check':($val==='non_acquis'?'fa-circle-xmark':'fa-clock');
            $col=$val==='acquis'?'#6ee7b7':($val==='non_acquis'?'#fca5a5':'#fde68a');
            [$al]=$ACQS[$val]??[$val];?>
        <div class="rkv-crit">
            <span style="font-size:.85rem;font-weight:600"><i class="<?php echo $c['icon'];?>" style="color:var(--blue-l);margin-right:.5rem;width:16px;text-align:center"></i><?php echo htmlspecialchars($c['label']);?></span>
            <span class="ck-status" style="color:<?php echo $col;?>"><i class="fas <?php echo $ic;?>"></i> <?php echo $al;?></span>
        </div>
        <?php endforeach;?>
      </div>

      <?php if($customAns): ?>
      <div class="rkv-block">
        <h3><i class="fas fa-list-check"></i> Questions complémentaires</h3>
        <?php foreach($customQuestions as $cq): if(!isset($customAns[$cq['id']])) continue; ?>
        <div class="rkv-crit" style="flex-direction:column;align-items:flex-start;gap:.3rem">
            <span style="font-size:.78rem;color:var(--txt-s);font-weight:600"><?php echo htmlspecialchars($cq['label']);?></span>
            <span style="font-size:.85rem"><?php echo htmlspecialchars($customAns[$cq['id']]);?></span>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>

      <div class="rkv-block">
        <h3><i class="fas fa-star"></i> Note globale</h3>
        <div class="rkv-stars"><?php echo $note?str_repeat('★',$note).str_repeat('☆',5-$note):'<span style="color:var(--txt-m);font-size:.9rem">Non notée</span>';?></div>
      </div>

      <div class="rkv-block">
        <h3><i class="fas fa-pen"></i> Observation</h3>
        <div class="rkv-text"><?php echo htmlspecialchars($rp['observation']??'');?></div>
      </div>

      <?php if(!empty($rp['conclusion'])): ?>
      <div class="rkv-block">
        <h3><i class="fas fa-flag-checkered"></i> Conclusion</h3>
        <div class="rkv-text"><?php echo htmlspecialchars($rp['conclusion']);?></div>
      </div>
      <?php endif;?>

      <div class="rkv-block">
        <h3><i class="fas fa-thumbs-up"></i> Recommandation</h3>
        <span class="badge <?php echo $recB;?>" style="font-size:.8rem"><?php echo $recL;?></span>
      </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

include __DIR__ . '/includes/header.php';
?>
<style>
.crit-btn{padding:.45rem .8rem;border-radius:8px;border:1.5px solid var(--bdr);background:transparent;cursor:pointer;font-size:.78rem;font-weight:600;font-family:inherit;transition:all .15s;color:var(--txt-s)}
.crit-btn:hover{border-color:var(--bdr-h)}
.crit-btn.sel-acquis{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.4);color:#6ee7b7}
.crit-btn.sel-non_acquis{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.4);color:#fca5a5}
.crit-btn.sel-en_cours{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.4);color:#fde68a}
.star-btn{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--txt-m);transition:color .1s;padding:0 2px}
.star-btn.active{color:#f59e0b}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.4rem;flex:1">
        <input type="text" name="q" class="form-input" placeholder="N° rapport, rookie, agent..." value="<?php echo htmlspecialchars($search);?>" style="flex:1">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search):?><a href="rapport-rookie.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <div style="display:flex;gap:.5rem">
        <?php if($canEditForm):?><button onclick="rkBuilderOpen()" class="btn btn-secondary btn-sm"><i class="fas fa-sliders"></i> Configurer le formulaire</button><?php endif;?>
        <button onclick="rkOpenCreate()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouveau rapport</button>
    </div>
</div>

<!-- LISTE -->
<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>N°</th><th>Rookie</th><th>Agent référent</th><th>Date patrouille</th><th>Évaluations</th><th>Note</th><th>Recommandation</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($rapports)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun rapport</td></tr>
<?php else:foreach($rapports as $r):[$rl,$rb]=$RECS[$r['recommandation']]??[$r['recommandation'],'bg-gray'];?>
<tr>
    <td class="mono" style="font-size:.72rem;color:var(--blue-l)"><?php echo htmlspecialchars($r['numero']);?></td>
    <td style="font-weight:700"><?php echo htmlspecialchars($r['rookie_name']??'—');?></td>
    <td style="font-size:.78rem"><?php echo htmlspecialchars($r['agent_name']??'—');?></td>
    <td style="font-size:.75rem;color:var(--txt-m)"><?php echo $r['date_patrouille']?date('d/m/Y',strtotime($r['date_patrouille'])):'—';?></td>
    <td>
        <?php foreach($CRITERIA as $c):[$al,$ab]=$ACQS[$r[$c['key']]]??[$r[$c['key']],'bg-gray'];?>
        <span class="badge <?php echo $ab;?>" style="font-size:.55rem;margin:.1rem" title="<?php echo $c['label'];?>"><?php echo mb_substr($c['label'],0,4);?> : <?php echo $al;?></span>
        <?php endforeach;?>
    </td>
    <td>
        <?php if($r['note_globale']):?>
        <span style="color:#f59e0b"><?php echo str_repeat('★',$r['note_globale']).str_repeat('☆',5-$r['note_globale']);?></span>
        <?php else:?><span style="color:var(--txt-m)">—</span><?php endif;?>
    </td>
    <td><span class="badge <?php echo $rb;?>" style="font-size:.62rem"><?php echo $rl;?></span></td>
    <td><span class="badge <?php echo $r['statut']==='valide'?'bg-green':($r['statut']==='soumis'?'bg-blue':'bg-gray');?>" style="font-size:.62rem"><?php echo ucfirst($r['statut']);?></span></td>
    <td>
        <a href="rapport-rookie.php?view=<?php echo (int)$r['id'];?>" class="btn btn-ghost btn-xs" title="Voir"><i class="fas fa-eye"></i></a>
        <form method="POST" data-confirm data-confirm-title="Supprimer le rapport" data-confirm-target="<?php echo htmlspecialchars(addslashes($r['numero']));?> — <?php echo htmlspecialchars(addslashes($r['rookie_name']??''));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id'];?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div>

<?php if($totalPages>1):?><div style="display:flex;gap:.35rem;justify-content:center;margin-top:.65rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL CRÉER -->
<div id="newRookieModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-user-graduate"></i> Rapport de patrouille rookie</h2><button class="modal-cl" onclick="LSSD.closeModal('newRookieModal')"><i class="fas fa-times"></i></button></div>
<form method="POST" id="rkForm"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Rookie évalué *</label>
            <select name="rookie_id" class="form-select" required>
                <option value="">Sélectionner...</option>
                <?php foreach($officiers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username']);?> <?php if($o['faction_rank']):?>(<?php echo htmlspecialchars($o['faction_rank']);?>)<?php endif;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Référent de patrouille</label>
            <select name="referent_patrouille_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($officiers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Date de patrouille *</label><input type="date" name="date_patrouille" class="form-input" value="<?php echo date('Y-m-d');?>" required></div>
        <div class="form-group"><label class="form-label">Statut du rapport</label>
            <select name="statut" class="form-select"><option value="soumis">Soumis</option><option value="brouillon">Brouillon</option><option value="valide">Validé</option></select>
        </div>
    </div>

    <!-- CRITÈRES D'ÉVALUATION -->
    <div style="margin-top:1rem">
        <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.7rem"><i class="fas fa-clipboard-check" style="margin-right:.3rem;color:var(--blue-l)"></i>Critères d'évaluation</div>
        <?php foreach($CRITERIA as $c):?>
        <div style="margin-bottom:.75rem">
            <div style="font-size:.8rem;font-weight:600;margin-bottom:.4rem"><i class="<?php echo $c['icon'];?>" style="color:var(--blue-l);margin-right:.35rem;width:14px;text-align:center"></i><?php echo $c['label'];?></div>
            <input type="hidden" name="<?php echo $c['key'];?>" id="<?php echo $c['key'];?>_val" value="en_cours">
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <?php foreach($ACQS as $av=>[$al]):?>
                <button type="button" class="crit-btn <?php echo $av==='en_cours'?'sel-en_cours':'';?>" id="<?php echo $c['key'];?>_<?php echo $av;?>" onclick="selCrit('<?php echo $c['key'];?>','<?php echo $av;?>')"><?php echo $al;?></button>
                <?php endforeach;?>
            </div>
        </div>
        <?php endforeach;?>
    </div>

    <!-- QUESTIONS PERSONNALISÉES (chargées dynamiquement à l'ouverture) -->
    <div id="rkCustomQuestions" style="margin-top:1rem"></div>

    <!-- NOTE GLOBALE -->
    <div style="margin-top:.85rem;margin-bottom:.75rem">
        <div style="font-size:.8rem;font-weight:600;margin-bottom:.4rem"><i class="fas fa-star" style="color:#f59e0b;margin-right:.35rem"></i>Note globale</div>
        <input type="hidden" name="note_globale" id="note_val" value="">
        <div id="stars">
            <?php for($i=1;$i<=5;$i++):?><button type="button" class="star-btn" data-v="<?php echo $i;?>" onclick="setNote(<?php echo $i;?>)">☆</button><?php endfor;?>
        </div>
    </div>

    <div class="form-grid">
        <div class="form-group form-full">
            <label class="form-label">Observation * <small style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--txt-m)">(50 caractères minimum)</small></label>
            <textarea name="observation" id="rkObs" class="form-textarea" rows="4" required placeholder="Décrivez le comportement du rookie, ses points forts, axes d'amélioration..." oninput="updObsCount(this)"></textarea>
            <div style="font-size:.67rem;color:var(--txt-m);text-align:right;margin-top:.2rem"><span id="obsCount">0</span>/50 min</div>
        </div>
        <div class="form-group form-full">
            <label class="form-label">Conclusion</label>
            <textarea name="conclusion" class="form-textarea" rows="2" placeholder="Conclusion et recommandations..."></textarea>
        </div>
        <div class="form-group"><label class="form-label">Recommandation</label>
            <select name="recommandation" class="form-select">
                <?php foreach($RECS as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
    </div>
</div><div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newRookieModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Soumettre le rapport</button>
</div></form></div></div>

<?php if($canEditForm):?>
<!-- ══ MODAL BUILDER ══ -->
<div id="rkBuilderModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-sliders"></i> Configurer le formulaire rookie</h2><button class="modal-cl" onclick="LSSD.closeModal('rkBuilderModal')">×</button></div>
<div class="modal-bd">
    <div style="font-size:.76rem;color:var(--txt-s);margin-bottom:.8rem">Questions complémentaires ajoutées au formulaire de rapport (en plus des 4 critères standards). Glissez-déposez pour réordonner.</div>
    <div id="rkQList" style="margin-bottom:1rem"></div>
    <div style="border:1px solid var(--bdr);border-radius:11px;padding:1rem;background:var(--bg-ov)">
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.7rem" id="rkFormTitle">Ajouter une question</div>
        <input type="hidden" id="rkqId" value="0">
        <div class="form-grid">
            <div class="form-group form-full"><label class="form-label">Libellé *</label><input type="text" id="rkqLabel" class="form-input" placeholder="Ex: Maîtrise du code 10..."></div>
            <div class="form-group"><label class="form-label">Type</label>
                <select id="rkqType" class="form-select" onchange="rkTypeChange()"><option value="texte">Texte libre</option><option value="boutons">Boutons (choix)</option></select>
            </div>
            <div class="form-group"><label class="form-label">Obligatoire</label>
                <select id="rkqReq" class="form-select"><option value="0">Non</option><option value="1">Oui</option></select>
            </div>
            <div class="form-group form-full" id="rkqOptsWrap" style="display:none"><label class="form-label">Choix (un par ligne)</label><textarea id="rkqOpts" class="form-textarea" rows="3" placeholder="Acquis&#10;En cours&#10;Non acquis"></textarea></div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.7rem">
            <button type="button" class="btn btn-ghost btn-sm" onclick="rkResetForm()">Réinitialiser</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="rkSaveQuestion()"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </div>
</div>
<div class="modal-ft"><button class="btn btn-ghost" onclick="LSSD.closeModal('rkBuilderModal')">Fermer</button></div>
</div></div>
<?php endif;?>

<script>
function cqPick(qid, btn){
    const wrap=btn.parentNode; wrap.querySelectorAll('.crit-btn').forEach(b=>b.classList.remove('sel-acquis'));
    btn.classList.add('sel-acquis');
    document.getElementById('cq_'+qid+'_val').value=btn.textContent.trim();
}
function rkCqEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
// Charge dynamiquement les questions personnalisées (config la plus récente) puis ouvre la modale
async function rkOpenCreate(){
    const box=document.getElementById('rkCustomQuestions'); if(box) box.innerHTML='';
    try{
        const r=await fetch('rapport-rookie.php?rk_action=form_list',{headers:{'X-Requested-With':'XMLHttpRequest'}});
        const d=await r.json(); const qs=d.questions||[];
        if(box && qs.length){
            let h='<div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.7rem"><i class="fas fa-list-check" style="margin-right:.3rem;color:var(--blue-l)"></i>Questions complémentaires</div>';
            qs.forEach(q=>{
                const req=String(q.required)==='1';
                h+='<div style="margin-bottom:.75rem"><div style="font-size:.8rem;font-weight:600;margin-bottom:.4rem">'+rkCqEsc(q.label)+(req?' <span style="color:var(--red)">*</span>':'')+'</div>';
                if(q.qtype==='boutons'){
                    let opts=[]; try{opts=JSON.parse(q.options||'[]')||[];}catch(e){}
                    h+='<input type="hidden" name="cq_'+q.id+'" id="cq_'+q.id+'_val"'+(req?' data-required="1"':'')+'>';
                    h+='<div style="display:flex;gap:.4rem;flex-wrap:wrap">'+opts.map(o=>'<button type="button" class="crit-btn" onclick="cqPick('+q.id+',this)">'+rkCqEsc(o)+'</button>').join('')+'</div>';
                } else {
                    h+='<textarea name="cq_'+q.id+'" class="form-textarea" rows="2"'+(req?' required':'')+'></textarea>';
                }
                h+='</div>';
            });
            box.innerHTML=h;
        }
    }catch(e){}
    LSSD.openModal('newRookieModal');
}
</script>

<script>
function selCrit(key, val) {
    document.getElementById(key+'_val').value = val;
    ['acquis','non_acquis','en_cours'].forEach(v => {
        const b = document.getElementById(key+'_'+v);
        if (!b) return;
        b.className = 'crit-btn' + (v === val ? ' sel-'+v : '');
    });
}
function setNote(v) {
    document.getElementById('note_val').value = v;
    document.querySelectorAll('#stars .star-btn').forEach(b => {
        const bv = parseInt(b.dataset.v);
        b.textContent = bv <= v ? '★' : '☆';
        b.classList.toggle('active', bv <= v);
    });
}
function updObsCount(ta) {
    const n = ta.value.replace(/<[^>]*>/g,'').length;
    const el = document.getElementById('obsCount');
    if (el) { el.textContent = n; el.style.color = n >= 50 ? '#6ee7b7' : '#fca5a5'; }
}
document.getElementById('rkForm')?.addEventListener('submit', function(e) {
    const n = document.getElementById('rkObs')?.value.length || 0;
    if (n < 50) { e.preventDefault(); LSSD.toast('L\'observation doit faire au minimum 50 caractères.','error'); return; }
    // Questions custom obligatoires (boutons)
    let missing=false;
    document.querySelectorAll('[data-required="1"]').forEach(h=>{ if(!h.value){ missing=true; } });
    if(missing){ e.preventDefault(); LSSD.toast('Veuillez répondre aux questions obligatoires.','error'); }
});

/* ══ Builder ══ */
function rkEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
let RK_QS=[];
function rkBuilderOpen(){ LSSD.openModal('rkBuilderModal'); rkLoad(); }
async function rkLoad(){
    const r=await fetch('rapport-rookie.php?rk_action=form_list',{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json(); RK_QS=d.questions||[]; rkRender();
}
function rkRender(){
    const box=document.getElementById('rkQList');
    if(!RK_QS.length){ box.innerHTML='<div style="color:var(--txt-m);font-size:.8rem;padding:.5rem 0">Aucune question personnalisée.</div>'; return; }
    box.innerHTML=RK_QS.map(q=>`
      <div class="rkq-row" draggable="true" data-id="${q.id}" style="display:flex;align-items:center;gap:.6rem;padding:.6rem .8rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.45rem;cursor:grab">
        <i class="fas fa-grip-vertical" style="color:var(--txt-m)"></i>
        <div style="flex:1"><div style="font-weight:600;font-size:.82rem">${rkEsc(q.label)} ${q.required==1?'<span style=\"color:var(--red)\">*</span>':''}</div>
          <div style="font-size:.68rem;color:var(--txt-m)">${q.qtype==='boutons'?'Boutons : '+(JSON.parse(q.options||'[]').join(', ')):'Texte libre'}</div></div>
        <button class="btn btn-ghost btn-xs" onclick='rkEdit(${JSON.stringify(q)})'><i class="fas fa-pen"></i></button>
        <button class="btn btn-danger btn-xs" onclick="rkDelete(${q.id})"><i class="fas fa-trash"></i></button>
      </div>`).join('');
    rkBindDrag();
}
function rkTypeChange(){ document.getElementById('rkqOptsWrap').style.display=document.getElementById('rkqType').value==='boutons'?'':'none'; }
function rkSyncSel(){ ['rkqType','rkqReq'].forEach(function(id){ var e=document.getElementById(id); if(e) e.dispatchEvent(new Event('change',{bubbles:true})); }); }
function rkResetForm(){ document.getElementById('rkqId').value=0; document.getElementById('rkqLabel').value=''; document.getElementById('rkqType').value='texte'; document.getElementById('rkqReq').value='0'; document.getElementById('rkqOpts').value=''; document.getElementById('rkFormTitle').textContent='Ajouter une question'; rkSyncSel(); rkTypeChange(); }
function rkEdit(q){ document.getElementById('rkqId').value=q.id; document.getElementById('rkqLabel').value=q.label; document.getElementById('rkqType').value=q.qtype; document.getElementById('rkqReq').value=q.required; document.getElementById('rkqOpts').value=(JSON.parse(q.options||'[]')).join('\n'); document.getElementById('rkFormTitle').textContent='Modifier la question'; rkSyncSel(); rkTypeChange(); }
async function rkSaveQuestion(){
    const fd=new FormData();
    fd.append('rk_action','form_save');
    fd.append('id',document.getElementById('rkqId').value);
    fd.append('label',document.getElementById('rkqLabel').value);
    fd.append('qtype',document.getElementById('rkqType').value);
    fd.append('required',document.getElementById('rkqReq').value);
    fd.append('options',document.getElementById('rkqOpts').value);
    const r=await fetch('rapport-rookie.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    if(d.success){ LSSD.toast('Question enregistrée','success'); rkResetForm(); rkLoad(); } else LSSD.toast(d.error||'Erreur','error');
}
function rkDelete(id){
    LSSD.confirm({title:'Supprimer la question',okLabel:'Supprimer',callback:async()=>{
        const fd=new FormData(); fd.append('rk_action','form_delete'); fd.append('id',id);
        const r=await fetch('rapport-rookie.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
        if((await r.json()).success){ LSSD.toast('Supprimée','success'); rkLoad(); }
    }});
}
let rkDragEl=null;
function rkBindDrag(){
    document.querySelectorAll('.rkq-row').forEach(row=>{
        row.addEventListener('dragstart',()=>{ rkDragEl=row; row.style.opacity='.4'; });
        row.addEventListener('dragend',()=>{ row.style.opacity='1'; rkSaveOrder(); });
        row.addEventListener('dragover',e=>{ e.preventDefault(); const box=document.getElementById('rkQList'); const after=rkAfter(box,e.clientY); if(after==null)box.appendChild(rkDragEl); else box.insertBefore(rkDragEl,after); });
    });
}
function rkAfter(box,y){ const els=[...box.querySelectorAll('.rkq-row:not([style*="opacity: 0.4"])')]; return els.reduce((c,ch)=>{const b=ch.getBoundingClientRect();const off=y-b.top-b.height/2;return off<0&&off>c.offset?{offset:off,element:ch}:c;},{offset:-Infinity}).element; }
async function rkSaveOrder(){
    const ids=[...document.querySelectorAll('.rkq-row')].map(r=>r.dataset.id);
    const fd=new FormData(); fd.append('rk_action','form_reorder'); fd.append('ids',JSON.stringify(ids));
    await fetch('rapport-rookie.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>