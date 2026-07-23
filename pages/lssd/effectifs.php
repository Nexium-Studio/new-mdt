<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/lssd_log.php';
require_once __DIR__ . '/includes/service_api.php';
require_once __DIR__ . '/../../includes/matricule_helper.php';
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Effectifs'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

// Grades LSSD depuis la DB (roles.php) ─────────────────────────────────────
try {
    $__lssdGrStmt = $db->prepare(
        "SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id
         WHERE f.faction_key='LSSD' ORDER BY fg.rank_order ASC"
    );
    $__lssdGrStmt->execute();
    $RANKS = array_column($__lssdGrStmt->fetchAll(), 'name');
} catch(Exception $e) {
    $RANKS = ['Sheriff','Undersheriff','Assistant Sheriff','Division Chief','Area Commander',
              'Captain','Lieutenant','Sergeant','Deputy Sheriff','Deputy Sheriff Trainee'];
}
// rank_order=1 = grade le plus haut → niveau le plus élevé
$RLEVEL = [];
foreach ($RANKS as $idx => $name) { $RLEVEL[$name] = count($RANKS) - $idx; }
try { $__grS=$db->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__grS->execute([$uid]);$__grRow=$__grS->fetch();$__myRankEff=$__grRow['faction_rank']??''; } catch(Exception $e){ $__myRankEff=$_SESSION['user_faction_rank']??''; }
$myLvl = $RLEVEL[$__myRankEff] ?? 0;
$__effMaxLvl = count($RANKS);
$isStaff    = hasRole(['*','Administrateur','Développeur','Staff']);
$canManage  = $isStaff || ($__effMaxLvl > 0 && $myLvl >= (int)ceil($__effMaxLvl * 0.40));
$canPromote = $canManage;

$__LSSD_FIRE_RANKS = ['Sergeant','Lieutenant','Captain','Area Commander','Division Chief','Assistant Sheriff','Undersheriff','Sheriff'];
$canFire = $isStaff || in_array($__myRankEff, $__LSSD_FIRE_RANKS, true);

// On ne peut agir que sur un agent de grade STRICTEMENT inférieur au sien (staff bypass).
function __lssdCanActOn(int $targetId, array $RLEVEL, int $myLvl, $db): bool {
    if (hasRole(['*','Administrateur','Développeur','Staff'])) return true;
    $s = $db->prepare("SELECT faction_rank FROM users WHERE id=? AND faction='LSSD' LIMIT 1");
    $s->execute([$targetId]);
    $targetLvl = $RLEVEL[$s->fetchColumn() ?: ''] ?? 0;
    return $myLvl > $targetLvl;
}

// Pour rester sur le profil après une action (POST vers ?agent=ID)
$__redirAgent = (int)($_GET['agent'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST'&&$canManage) {
    $action=$_POST['action']??'';
    if ($action==='recruit') {
        $newId = intval($_POST['user_id']);
        $rank  = in_array($_POST['rank']??'', $RANKS, true) ? $_POST['rank'] : 'Deputy Sheriff Trainee';
        // Hiérarchie : un non-staff ne peut recruter à un grade >= au sien
        if (!$isStaff && ($RLEVEL[$rank] ?? 0) >= $myLvl) $rank = 'Deputy Sheriff Trainee';
        try {
            $db->prepare("UPDATE users SET faction='LSSD',faction_rank=?,is_active=1 WHERE id=?")->execute([$rank,$newId]);
            $msg='Officier recruté au grade '.$rank.'.';$msgType='success';
            lssd_log($db,$uid,'recruit','effectifs',$newId,'Recrutement #'.$newId,'Grade: '.$rank);
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='promote'&&$canPromote) {
        $targetId = intval($_POST['user_id']);
        $rank = $_POST['new_rank'] ?? '';
        if (!__lssdCanActOn($targetId,$RLEVEL,$myLvl,$db) || (!$isStaff && ($RLEVEL[$rank]??0) >= $myLvl)) {
            $msg='Action refusée : grade supérieur ou égal au vôtre.'; $msgType='error';
        } else {
            $db->prepare("UPDATE users SET faction_rank=? WHERE id=? AND faction='LSSD'")->execute([$rank,$targetId]);
            $msg='Grade mis à jour.';$msgType='success';
            lssd_log($db,$uid,'promote','effectifs',$targetId,'Agent #'.$targetId,' → '.$rank);
        }
    }
    if ($action==='fire') {
        $targetId = intval($_POST['user_id']);
        if (!$canFire) { $msg="Action refusée : votre grade n'autorise pas le licenciement."; $msgType='error'; }
        elseif (!__lssdCanActOn($targetId,$RLEVEL,$myLvl,$db)) { $msg='Action refusée : grade supérieur ou égal au vôtre.'; $msgType='error'; }
        else {
            $db->prepare("UPDATE users SET faction='NONE',faction_rank=NULL,is_active=0 WHERE id=? AND faction='LSSD'")->execute([$targetId]);
            $msg='Officier retiré du service.';$msgType='success';
            lssd_log($db,$uid,'fire','effectifs',$targetId,'Agent #'.$targetId,'Retiré du service');
            $__redirAgent = 0; // l'agent n'est plus LSSD : retour à la liste
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * VUE PROFIL AGENT  (effectifs.php?agent=ID)
 * ═════════════════════════════════════════════════════════════════════════ */
$viewAgentId = (int)($_GET['agent'] ?? 0);
if ($viewAgentId > 0) {
    $ag = null;
    try {
        $s=$db->prepare("SELECT u.*, p.firstname AS pf, p.lastname AS pl, p.avatar AS pavatar
                         FROM users u LEFT JOIN profiles p ON p.id=u.profile_id
                         WHERE u.id=? AND u.faction='LSSD' AND u.is_active=1 LIMIT 1");
        $s->execute([$viewAgentId]); $ag=$s->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
    if (!$ag) { redirect(SITE_URL.'/pages/lssd/effectifs.php'); }

    $agName = mdt_rp_display_name($ag['pf']??null,$ag['pl']??null,(string)$ag['username']);
    $agInit = mdt_rp_initials($ag['pf']??null,$ag['pl']??null,(string)$ag['username']);
    $agRank = $ag['faction_rank'] ?? '';
    $agLvl  = $RLEVEL[$agRank] ?? 0;
    $agMat  = lspd_matricule_for_user($db, $viewAgentId, 'LSSD');
    $canActOnThis = $isStaff || ($myLvl > $agLvl);

    // Données sections (défensives)
    $sanctions=[]; try{$s=$db->prepare("SELECT * FROM lssd_sanctions WHERE officer_id=? ORDER BY date_issued DESC LIMIT 30");$s->execute([$viewAgentId]);$sanctions=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $reports=[]; try{$s=$db->prepare("SELECT id,title,created_at FROM reports WHERE created_by=? AND faction='LSSD' ORDER BY created_at DESC LIMIT 20");$s->execute([$viewAgentId]);$reports=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $season = lssd_service_season($db, $viewAgentId);
    $divs=[]; try{$s=$db->prepare("SELECT d.name,d.abbreviation,d.color,ldm.role FROM lssd_division_members ldm JOIN lssd_divisions d ON d.id=ldm.division_id WHERE ldm.user_id=? ORDER BY d.name");$s->execute([$viewAgentId]);$divs=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $equip=[]; try{$s=$db->prepare("SELECT equipment_type,serial_number,condition_status FROM lssd_equipment WHERE officer_id=? AND status='assigned' ORDER BY date_assigned DESC");$s->execute([$viewAgentId]);$equip=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $formations=[]; try{$s=$db->prepare("SELECT f.name, uf.status FROM academy_user_formations uf JOIN academy_formations f ON f.id=uf.formation_id WHERE uf.user_id=? ORDER BY uf.id DESC LIMIT 30");$s->execute([$viewAgentId]);$formations=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $absences=[]; try{$s=$db->prepare("SELECT * FROM lssd_absences WHERE user_id=? ORDER BY date_debut DESC LIMIT 60");$s->execute([$viewAgentId]);$absences=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

    $fmtMin = fn($m)=>intdiv((int)$m,60).'h '.((int)$m%60).'min';
    $nbActiveSanc = 0; foreach($sanctions as $sc){ if(($sc['status']??'')==='active') $nbActiveSanc++; }

    $pageTitle = 'Profil — '.$agName;
    include __DIR__ . '/includes/header.php';
    ?>
    <style>
    .ap-grid{display:grid;grid-template-columns:340px 1fr;gap:1.2rem;align-items:start}
    @media(max-width:980px){.ap-grid{grid-template-columns:1fr}}
    .ap-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:14px;padding:1.2rem;margin-bottom:1.2rem}
    .ap-sec-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem}
    .ap-sec-title i{color:var(--blue-l)}
    .ap-line{display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid rgba(16,185,129,.07);font-size:.82rem}
    .ap-line:last-child{border-bottom:none}
    .ap-list-item{padding:.6rem .8rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.5rem;font-size:.82rem}
    .ap-empty{color:var(--txt-m);font-size:.8rem;font-style:italic;padding:.4rem 0}
    </style>

    <a href="effectifs.php" class="btn btn-ghost btn-sm" style="margin-bottom:1rem"><i class="fas fa-arrow-left"></i> Retour aux effectifs</a>
    <?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

    <div class="ap-grid">
      <!-- Colonne identité -->
      <div>
        <div class="ap-card" style="text-align:center;<?php echo $nbActiveSanc>0?'border-color:rgba(245,158,11,.5)':'';?>">
            <?php if(!empty($ag['pavatar'])):?>
            <img src="<?php echo htmlspecialchars(preg_match('#^https?://#',$ag['pavatar'])?$ag['pavatar']:SITE_URL.$ag['pavatar']);?>" alt="" style="width:96px;height:96px;border-radius:24px;object-fit:cover;border:2px solid rgba(16,185,129,.3);margin-bottom:.8rem">
            <?php else:?>
            <div style="width:96px;height:96px;border-radius:24px;background:linear-gradient(135deg,#10b981,#047857);display:grid;place-items:center;font-size:2.2rem;font-weight:800;color:#fff;margin:0 auto .8rem"><?php echo htmlspecialchars($agInit);?></div>
            <?php endif;?>
            <div style="font-family:'Syne',serif;font-size:1.2rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($agName);?></div>
            <div style="margin-top:.4rem"><span class="badge bg-blue"><i class="fas fa-shield-halved"></i> <?php echo htmlspecialchars($agRank?:'—');?></span></div>
            <div style="margin-top:.5rem;font-family:'IBM Plex Mono',monospace;font-size:.8rem;color:var(--blue-xl)"><i class="fas fa-hashtag" style="opacity:.6"></i> <?php echo $agMat?htmlspecialchars($agMat):'<span style="color:var(--txt-m)">Aucun matricule</span>';?></div>
            <?php if($nbActiveSanc>0):?><div style="margin-top:.6rem"><span class="badge bg-yellow"><i class="fas fa-triangle-exclamation"></i> <?php echo $nbActiveSanc;?> sanction(s) active(s)</span></div><?php endif;?>
        </div>

        <!-- Modifier le grade (hiérarchie stricte) -->
        <?php if($canPromote && $canActOnThis):?>
        <div class="ap-card">
            <div class="ap-sec-title"><i class="fas fa-arrow-up-right-dots"></i> Modifier le grade</div>
            <form method="POST" action="effectifs.php?agent=<?php echo $viewAgentId;?>" style="display:flex;gap:.5rem">
                <input type="hidden" name="action" value="promote">
                <input type="hidden" name="user_id" value="<?php echo $viewAgentId;?>">
                <select name="new_rank" id="ap_rank" class="form-select" style="flex:1">
                    <?php foreach(array_reverse($RANKS) as $r): $disabled = (!$isStaff && ($RLEVEL[$r]??0) >= $myLvl); ?>
                    <option value="<?php echo htmlspecialchars($r);?>" <?php echo $r===$agRank?'selected':'';?> <?php echo $disabled?'disabled':'';?>><?php echo htmlspecialchars($r);?></option>
                    <?php endforeach;?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i></button>
            </form>
            <?php if($canFire):?>
            <form method="POST" action="effectifs.php?agent=<?php echo $viewAgentId;?>" style="margin-top:.7rem"
                  data-confirm data-confirm-title="Retirer du service"
                  data-confirm-msg="Retirer <strong><?php echo htmlspecialchars($agName);?></strong> du service ? Action irréversible."
                  data-confirm-icon="fas fa-user-slash" data-confirm-ok="Confirmer" data-confirm-class="btn-danger">
                <input type="hidden" name="action" value="fire"><input type="hidden" name="user_id" value="<?php echo $viewAgentId;?>">
                <button type="submit" class="btn btn-danger btn-sm" style="width:100%"><i class="fas fa-user-slash"></i> Retirer du service</button>
            </form>
            <?php endif;?>
        </div>
        <?php endif;?>

        <!-- Temps de service -->
        <div class="ap-card">
            <div class="ap-sec-title"><i class="fas fa-business-time"></i> Temps de service</div>
            <div class="ap-line"><span style="color:var(--txt-s)">Saison en cours</span><span class="mono" style="font-weight:700;color:#6ee7b7"><?php echo $fmtMin($season['season']);?></span></div>
            <div class="ap-line"><span style="color:var(--txt-s)">Total global</span><span class="mono" style="font-weight:700"><?php echo $fmtMin($season['lifetime']);?></span></div>
        </div>
      </div>

      <!-- Colonne sections -->
      <div>
        <div class="ap-card">
            <div class="ap-sec-title"><i class="fas fa-file-lines"></i> Rapports (<?php echo count($reports);?>)</div>
            <?php if($reports):foreach($reports as $rp):?>
            <a href="reports.php?id=<?php echo (int)$rp['id'];?>" class="ap-list-item" style="display:flex;justify-content:space-between;text-decoration:none;color:var(--txt)">
                <span><?php echo htmlspecialchars($rp['title']?:('Rapport #'.$rp['id']));?></span>
                <span style="color:var(--txt-m);font-size:.72rem"><?php echo !empty($rp['created_at'])?date('d/m/Y',strtotime($rp['created_at'])):'';?></span>
            </a>
            <?php endforeach;else:?><div class="ap-empty">Aucun rapport.</div><?php endif;?>
        </div>

        <div class="ap-card">
            <div class="ap-sec-title"><i class="fas fa-gavel"></i> Sanctions (<?php echo count($sanctions);?>)</div>
            <?php if($sanctions):foreach($sanctions as $sc):
                $active=($sc['status']??'')==='active';?>
            <div class="ap-list-item" style="border-left:3px solid <?php echo $active?'#f59e0b':'#475569';?>">
                <div style="display:flex;justify-content:space-between"><strong><?php echo htmlspecialchars($sc['sanction_type']??'Sanction');?></strong>
                    <span class="badge <?php echo $active?'bg-yellow':'bg-gray';?>" style="font-size:.6rem"><?php echo htmlspecialchars($sc['status']??'');?></span></div>
                <div style="color:var(--txt-s);font-size:.78rem;margin-top:.2rem"><?php echo htmlspecialchars($sc['reason']??'');?></div>
                <div style="color:var(--txt-m);font-size:.7rem;margin-top:.2rem">
                    <?php echo !empty($sc['date_issued'])?date('d/m/Y',strtotime($sc['date_issued'])):'';?>
                    <?php if(!empty($sc['duration_days'])):?> · <?php echo (int)$sc['duration_days'];?> j<?php endif;?>
                </div>
            </div>
            <?php endforeach;else:?><div class="ap-empty">Aucune sanction.</div><?php endif;?>
        </div>

        <div class="ap-card">
            <div class="ap-sec-title"><i class="fas fa-calendar-xmark"></i> Historique des absences (<?php echo count($absences);?>)</div>
            <?php if($absences):
                $ABS_ST=['en_attente'=>['En attente','bg-yellow','#f59e0b'],'validee'=>['Validée','bg-green','#10b981'],'refusee'=>['Refusée','bg-red','#ef4444']];
                foreach($absences as $ab):
                    $st=$ABS_ST[$ab['statut']]??['—','bg-gray','#475569'];
                    try{$d1=new DateTime($ab['date_debut']);$d2=new DateTime($ab['date_fin']);$nbj=max(1,$d1->diff($d2)->days+1);}catch(Exception $e){$nbj=1;}
                ?>
            <div class="ap-list-item" style="border-left:3px solid <?php echo $st[2];?>">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                    <strong><?php echo htmlspecialchars($ab['motif']?:'Absence');?></strong>
                    <span class="badge <?php echo $st[1];?>" style="font-size:.6rem"><?php echo $st[0];?></span>
                </div>
                <div style="color:var(--txt-s);font-size:.78rem;margin-top:.25rem">Du <?php echo date('d/m/Y',strtotime($ab['date_debut']));?> au <?php echo date('d/m/Y',strtotime($ab['date_fin']));?></div>
                <div style="color:var(--txt-m);font-size:.7rem;margin-top:.2rem"><i class="fas fa-hourglass" style="opacity:.6;margin-right:.25rem"></i>Durée : <?php echo $nbj;?> jour<?php echo $nbj>1?'s':'';?></div>
            </div>
            <?php endforeach;else:?><div class="ap-empty">Aucune absence enregistrée.</div><?php endif;?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
            <div class="ap-card" style="margin-bottom:0">
                <div class="ap-sec-title"><i class="fas fa-layer-group"></i> Divisions</div>
                <?php if($divs):foreach($divs as $dv):?>
                <div class="ap-list-item" style="border-left:3px solid <?php echo htmlspecialchars($dv['color']?:'#10b981');?>">
                    <strong><?php echo htmlspecialchars($dv['abbreviation']);?></strong> — <?php echo htmlspecialchars($dv['name']);?>
                    <?php if(!empty($dv['role'])):?><span style="color:var(--blue-l);font-size:.72rem"> (<?php echo htmlspecialchars($dv['role']);?>)</span><?php endif;?>
                </div>
                <?php endforeach;else:?><div class="ap-empty">Aucune division.</div><?php endif;?>
            </div>
            <div class="ap-card" style="margin-bottom:0">
                <div class="ap-sec-title"><i class="fas fa-graduation-cap"></i> Formations</div>
                <?php if($formations):foreach($formations as $fo):?>
                <div class="ap-list-item" style="display:flex;justify-content:space-between">
                    <span><?php echo htmlspecialchars($fo['name']);?></span>
                    <span class="badge <?php echo ($fo['status']??'')==='termine'?'bg-green':'bg-blue';?>" style="font-size:.6rem"><?php echo htmlspecialchars($fo['status']??'');?></span>
                </div>
                <?php endforeach;else:?><div class="ap-empty">Aucune formation.</div><?php endif;?>
            </div>
        </div>

        <div class="ap-card" style="margin-top:1.2rem">
            <div class="ap-sec-title"><i class="fas fa-gun"></i> Équipement assigné (<?php echo count($equip);?>)</div>
            <?php if($equip):?>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                <?php foreach($equip as $eq):?>
                <span class="badge bg-blue" style="font-size:.72rem"><?php echo htmlspecialchars($eq['equipment_type']);?><?php if(!empty($eq['serial_number'])):?> · <?php echo htmlspecialchars($eq['serial_number']);?><?php endif;?></span>
                <?php endforeach;?>
            </div>
            <?php else:?><div class="ap-empty">Aucun équipement assigné.</div><?php endif;?>
        </div>
      </div>
    </div>

    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

/* ═══════════════════════════════════════════════════════════════════════════
 * VUE LISTE (cards MDT)
 * ═════════════════════════════════════════════════════════════════════════ */
$fRank=$_GET['rank']??'all'; $search=trim($_GET['q']??'');
$q="SELECT u.*,p.firstname AS profile_firstname,p.lastname AS profile_lastname,p.avatar AS pavatar,
    (SELECT COUNT(*) FROM lssd_sanctions WHERE officer_id=u.id AND status='active') AS nb_sanctions,
    (SELECT COUNT(*) FROM reports WHERE created_by=u.id AND faction='LSSD') AS nb_reports,
    (SELECT COUNT(*) FROM company_shifts WHERE user_id=u.id AND faction='LSSD') AS nb_shifts
    FROM users u LEFT JOIN profiles p ON p.id=u.profile_id WHERE u.faction='LSSD' AND u.is_active=1";
$params=[];
if ($fRank!=='all'){$q.=" AND u.faction_rank=?";$params[]=$fRank;}
if ($search){$q.=" AND (u.username LIKE ? OR IFNULL(p.firstname,'') LIKE ? OR IFNULL(p.lastname,'') LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;$params[]=$s;}
$q.=" ORDER BY COALESCE(NULLIF(u.faction_rank,''),'_'), u.username";
$stmt=$db->prepare($q);$stmt->execute($params);$officers=$stmt->fetchAll();
$offMatricules = lspd_matricules_map($db, array_column($officers,'id'), 'LSSD');

// ── Statut d'absence par agent (absences validées) : absent aujourd'hui / à venir ──
$offAbsence=[]; // user_id => 'absent' | 'upcoming'
try{
    $__today=date('Y-m-d');
    foreach($db->query("SELECT user_id,date_debut,date_fin FROM lssd_absences WHERE statut='validee'")->fetchAll(PDO::FETCH_ASSOC) as $__a){
        $__u=(int)$__a['user_id'];
        if($__today>=$__a['date_debut'] && $__today<=$__a['date_fin']){ $offAbsence[$__u]='absent'; }
        elseif($__a['date_debut']>$__today && ($offAbsence[$__u]??'')!=='absent'){ $offAbsence[$__u]='upcoming'; }
    }
}catch(Exception $e){}

$rankCounts=[];
try{foreach($db->query("SELECT faction_rank,COUNT(*) as n FROM users WHERE faction='LSSD' AND is_active=1 GROUP BY faction_rank")->fetchAll() as $r)$rankCounts[$r['faction_rank']]=$r['n'];}catch(Exception $e){}
$allUsers=[];try{$allUsers=$db->query("SELECT u.id,u.username,COALESCE(NULLIF(u.faction,''),'Sans faction') AS faction,p.firstname AS profile_firstname,p.lastname AS profile_lastname,p.avatar AS pavatar FROM users u LEFT JOIN profiles p ON p.id=u.profile_id WHERE COALESCE(u.faction,'') NOT IN ('LSSD') AND u.is_active=1 ORDER BY u.username LIMIT 1000")->fetchAll();}catch(Exception $e){}
$totalOff=array_sum($rankCounts);

include __DIR__ . '/includes/header.php';
?>
<style>
.officer-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;padding:1.1rem;transition:all .2s;cursor:pointer;display:block;text-decoration:none;color:inherit}
.officer-card:hover{border-color:var(--bdr-h);transform:translateY(-2px)}
.officer-card.has-sanction{border-color:rgba(245,158,11,.5)}
.officer-card.absent{border-color:rgba(150,95,45,.6);background:linear-gradient(135deg,rgba(120,72,30,.18),var(--bg-c))}
.badge-absent{background:rgba(120,72,30,.22);color:#d8b48a;border:1px solid rgba(150,95,45,.45)}
.badge-upcoming{background:rgba(245,158,11,.1);color:#fde68a;border:1px solid rgba(245,158,11,.28)}
.oc-ava{width:52px;height:52px;border-radius:13px;object-fit:cover;flex-shrink:0;border:1px solid rgba(16,185,129,.25)}
.oc-ava-ph{width:52px;height:52px;border-radius:13px;background:linear-gradient(135deg,#10b981,#047857);color:#fff;display:grid;place-items:center;font-weight:800;font-size:1.2rem;flex-shrink:0}
</style>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <div class="kpi"><div class="kpi-lbl">Total effectifs</div><div class="kpi-val blue"><?php echo $totalOff;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Command Staff</div><div class="kpi-val"><?php echo array_sum(array_intersect_key($rankCounts,array_flip(['Sheriff','Undersheriff','Assistant Sheriff','Division Chief','Area Commander'])));?></div></div>
    <div class="kpi"><div class="kpi-lbl">Superviseurs</div><div class="kpi-val"><?php echo array_sum(array_intersect_key($rankCounts,array_flip(['Captain','Lieutenant','Sergeant'])));?></div></div>
    <div class="kpi"><div class="kpi-lbl">Deputies</div><div class="kpi-val"><?php echo array_sum(array_intersect_key($rankCounts,array_flip(['Deputy Sheriff','Deputy Sheriff Trainee'])));?></div></div>
    <div class="kpi"><div class="kpi-lbl">Cadets</div><div class="kpi-val yellow"><?php echo $rankCounts['Deputy Sheriff Trainee']??0;?></div></div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <input type="text" name="q" class="form-input" placeholder="Rechercher un officier..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="rank" class="form-select" style="width:165px" onchange="this.form.submit()">
            <option value="all">Tous les grades</option>
            <?php foreach(array_reverse($RANKS) as $r):?><option value="<?php echo $r;?>" <?php echo $fRank===$r?'selected':'';?>><?php echo $r;?> (<?php echo $rankCounts[$r]??0;?>)</option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <a href="effectifs.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <?php if($canManage):?><button onclick="LSSD.openModal('recruitModal')" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Recruter</button><?php endif;?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1rem">
<?php foreach($officers as $o):
    $oDisp=mdt_rp_display_name($o['profile_firstname']??null,$o['profile_lastname']??null,(string)$o['username']);
    $init=mdt_rp_initials($o['profile_firstname']??null,$o['profile_lastname']??null,(string)$o['username']);
    $mat=$offMatricules[(int)$o['id']]??null;
    $absSt=$offAbsence[(int)$o['id']]??'';
?>
<a href="effectifs.php?agent=<?php echo (int)$o['id'];?>" class="officer-card <?php echo $o['nb_sanctions']>0?'has-sanction':'';?> <?php echo $absSt==='absent'?'absent':'';?>">
    <div style="display:flex;align-items:center;gap:.85rem;margin-bottom:.85rem">
        <?php if(!empty($o['pavatar'])):?>
        <img class="oc-ava" src="<?php echo htmlspecialchars(preg_match('#^https?://#',$o['pavatar'])?$o['pavatar']:SITE_URL.$o['pavatar']);?>" alt="">
        <?php else:?><div class="oc-ava-ph"><?php echo htmlspecialchars($init);?></div><?php endif;?>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($oDisp);?></div>
            <div style="font-size:.74rem;color:var(--blue-l);font-weight:600"><?php echo htmlspecialchars($o['faction_rank']??'—');?></div>
            <div style="font-size:.7rem;color:var(--txt-m);font-family:'IBM Plex Mono',monospace;margin-top:.15rem"><i class="fas fa-hashtag" style="opacity:.5"></i> <?php echo $mat?htmlspecialchars($mat):'—';?></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.25rem;align-items:flex-end;flex-shrink:0">
            <?php if($absSt==='absent'):?><span class="badge badge-absent" style="font-size:.6rem"><i class="fas fa-plane-departure" style="font-size:.55rem"></i> Absent</span>
            <?php elseif($absSt==='upcoming'):?><span class="badge badge-upcoming" style="font-size:.6rem"><i class="fas fa-clock" style="font-size:.55rem"></i> À venir</span><?php endif;?>
            <?php if($o['nb_sanctions']>0):?><span class="badge bg-yellow" style="font-size:.62rem" title="Sanctions actives"><?php echo $o['nb_sanctions'];?> ⚠</span><?php endif;?>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem">
        <div style="background:var(--bg-ov);border-radius:7px;padding:.45rem;text-align:center"><div style="font-size:.58rem;color:var(--txt-m)">Rapports</div><div class="mono" style="font-weight:700;font-size:.85rem"><?php echo $o['nb_reports'];?></div></div>
        <div style="background:var(--bg-ov);border-radius:7px;padding:.45rem;text-align:center"><div style="font-size:.58rem;color:var(--txt-m)">Services</div><div class="mono" style="font-weight:700;font-size:.85rem"><?php echo $o['nb_shifts'];?></div></div>
        <div style="background:var(--bg-ov);border-radius:7px;padding:.45rem;text-align:center"><div style="font-size:.58rem;color:var(--txt-m)">Sanctions</div><div class="mono" style="font-weight:700;font-size:.85rem;color:<?php echo $o['nb_sanctions']>0?'#fde68a':'#6ee7b7';?>"><?php echo $o['nb_sanctions'];?></div></div>
    </div>
</a>
<?php endforeach; if(empty($officers)):?>
<div class="card" style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-users" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>Aucun officier</div>
<?php endif;?>
</div>

<!-- ══ MODAL RECRUTER (2 étapes) ══ -->
<?php if($canManage):?>
<div id="recruitModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-user-plus"></i> Recruter un officier</h2><button class="modal-cl" onclick="LSSD.closeModal('recruitModal')">×</button></div>
<form method="POST" id="recruitForm"><div class="modal-bd">
    <input type="hidden" name="action" value="recruit">
    <input type="hidden" name="user_id" id="rc_uid">
    <input type="hidden" name="rank" id="rc_rank" value="Cadet">

    <!-- Étape 1 -->
    <div id="rcStep1">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:.7rem">Étape 1 — Sélectionner un utilisateur</div>
        <div class="mdt-search" style="display:flex;align-items:center;gap:.5rem;padding:.55rem .85rem;background:#020508;border:1px solid rgba(16,185,129,.2);border-radius:8px;margin-bottom:.8rem">
            <i class="fas fa-search" style="color:var(--txt-s)"></i>
            <input type="text" id="rcSearch" placeholder="Rechercher..." style="flex:1;background:none;border:none;outline:none;color:var(--txt);font-family:inherit;font-size:.84rem" oninput="rcFilter(this.value)">
        </div>
        <div id="rcUserGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.55rem;max-height:46vh;overflow-y:auto">
            <?php foreach($allUsers as $u): $uDisp=mdt_rp_display_name($u['profile_firstname']??null,$u['profile_lastname']??null,(string)$u['username']); $uInit=mdt_rp_initials($u['profile_firstname']??null,$u['profile_lastname']??null,(string)$u['username']); ?>
            <div class="rc-user" data-id="<?php echo (int)$u['id'];?>" data-name="<?php echo htmlspecialchars($uDisp,ENT_QUOTES);?>" data-search="<?php echo htmlspecialchars(strtolower($u['username'].' '.$uDisp.' '.$u['faction']),ENT_QUOTES);?>"
                 onclick="rcPick(this)" style="display:flex;align-items:center;gap:.6rem;padding:.55rem;border:1px solid var(--bdr);border-radius:10px;cursor:pointer;transition:all .15s">
                <?php if(!empty($u['pavatar'])):?><img src="<?php echo htmlspecialchars(preg_match('#^https?://#',$u['pavatar'])?$u['pavatar']:SITE_URL.$u['pavatar']);?>" style="width:34px;height:34px;border-radius:9px;object-fit:cover;flex-shrink:0">
                <?php else:?><div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#10b981,#047857);color:#fff;display:grid;place-items:center;font-weight:700;font-size:.85rem;flex-shrink:0"><?php echo htmlspecialchars($uInit);?></div><?php endif;?>
                <div style="min-width:0"><div style="font-weight:600;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($uDisp);?></div>
                    <div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($u['faction']);?></div></div>
            </div>
            <?php endforeach;?>
        </div>
        <?php if(empty($allUsers)):?><div style="color:var(--txt-m);font-size:.82rem;padding:1rem;text-align:center">Aucun utilisateur libre disponible.</div><?php endif;?>
    </div>

    <!-- Étape 2 -->
    <div id="rcStep2" style="display:none">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:.7rem">Étape 2 — Grade d'arrivée</div>
        <div style="display:flex;align-items:center;gap:.6rem;padding:.6rem .8rem;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:9px;margin-bottom:.9rem">
            <i class="fas fa-user" style="color:var(--blue-l)"></i><span>Recrue : <strong id="rcPickedName">—</strong></span>
        </div>
        <div id="rcRankBtns" style="display:flex;flex-wrap:wrap;gap:.45rem">
            <?php foreach(array_reverse($RANKS) as $r): $disabled=(!$isStaff && ($RLEVEL[$r]??0) >= $myLvl); if($disabled) continue; ?>
            <button type="button" class="btn btn-ghost btn-sm rc-rank" data-rank="<?php echo htmlspecialchars($r,ENT_QUOTES);?>" onclick="rcRank(this)"><?php echo htmlspecialchars($r);?></button>
            <?php endforeach;?>
        </div>
    </div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" id="rcBack" onclick="rcGoStep(1)" style="display:none"><i class="fas fa-arrow-left"></i> Retour</button>
    <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('recruitModal')">Annuler</button>
    <button type="button" class="btn btn-primary" id="rcNext" onclick="rcGoStep(2)" disabled>Continuer</button>
    <button type="submit" class="btn btn-success" id="rcSubmit" style="display:none"><i class="fas fa-check"></i> Recruter</button>
</div>
</form></div></div>
<?php endif;?>

<script>
function rcPick(el){
    document.querySelectorAll('.rc-user').forEach(x=>x.style.borderColor='var(--bdr)');
    el.style.borderColor='var(--blue)';
    document.getElementById('rc_uid').value=el.dataset.id;
    document.getElementById('rcPickedName').textContent=el.dataset.name;
    document.getElementById('rcNext').disabled=false;
}
function rcFilter(q){
    q=(q||'').toLowerCase().trim();
    document.querySelectorAll('.rc-user').forEach(el=>{ el.style.display=(!q||el.dataset.search.includes(q))?'':'none'; });
}
function rcGoStep(n){
    document.getElementById('rcStep1').style.display=n===1?'':'none';
    document.getElementById('rcStep2').style.display=n===2?'':'none';
    document.getElementById('rcBack').style.display=n===2?'':'none';
    document.getElementById('rcNext').style.display=n===1?'':'none';
    document.getElementById('rcSubmit').style.display=n===2?'':'none';
}
function rcRank(btn){
    document.querySelectorAll('.rc-rank').forEach(b=>b.classList.replace('btn-primary','btn-ghost'));
    btn.classList.replace('btn-ghost','btn-primary');
    document.getElementById('rc_rank').value=btn.dataset.rank;
}
// Sélection grade par défaut = le premier bouton (plus bas grade dispo)
document.addEventListener('DOMContentLoaded',function(){
    const first=document.querySelector('.rc-rank'); if(first){ first.classList.replace('btn-ghost','btn-primary'); document.getElementById('rc_rank').value=first.dataset.rank; }
});
</script>
<?php include __DIR__ . '/includes/footer.php';?>
