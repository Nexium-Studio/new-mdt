<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Affaires Internes'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';
// Grade lu depuis la DB (faction_rank)
$__uid = (int)($_SESSION['user_id'] ?? 0);
try { $__grS=$db->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__grS->execute([$__uid]);$__grRow=$__grS->fetch();$__myRank=$__grRow['faction_rank']??''; } catch(Exception $e){ $__myRank=$_SESSION['user_faction_rank']??''; }
// Grades LSSD dynamiques
try { $__gS=$db->prepare("SELECT fg.name FROM faction_grades fg JOIN factions f ON f.id=fg.faction_id WHERE f.faction_key='LSSD' ORDER BY fg.rank_order ASC");$__gS->execute();$__gRanks=array_column($__gS->fetchAll(),'name'); } catch(Exception $e){ $__gRanks=['Sheriff','Undersheriff','Assistant Sheriff','Captain','Lieutenant','Lieutenant','Sergeant','Sergeant','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff','Deputy Sheriff Trainee']; }
$__gLevel=[];foreach($__gRanks as $__gi=>$__gn){$__gLevel[$__gn]=count($__gRanks)-$__gi;}
$__myLvl = $__gLevel[$__myRank] ?? 0;
$__maxLvl = count($__gRanks);
// Seuil Sergeant I = 40% de la hiérarchie
$__sgtThreshold = $__maxLvl > 0 ? (int)ceil($__maxLvl * 0.40) : 5;
$canIA = hasRole(['*','Administrateur','Développeur','Staff']) || $__myLvl >= $__sgtThreshold;
if(!$canIA){redirect(SITE_URL.'/pages/lssd/dashboard.php');}

$section=$_GET['section']??'overview';

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    // Notes rapides (existant)
    if ($action==='add_note') {
        try{$db->prepare("INSERT INTO lssd_ia_notes (officer_id,author_id,type,content,is_private) VALUES (?,?,?,?,?)")->execute([intval($_POST['officer_id']),$uid,$_POST['note_type'],trim($_POST['content']),isset($_POST['is_private'])?1:0]);$msg='Note ajoutée.';$msgType='success';}catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='delete_note') {
        $db->prepare("DELETE FROM lssd_ia_notes WHERE id=? AND author_id=?")->execute([intval($_POST['id']),$uid]);
        $msg='Note supprimée.';$msgType='success';
    }

    // Dossiers IA
    if ($action==='create_dossier') {
        try {
            $year=date('Y');$seq=(int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM lssd_ia_dossiers")->fetchColumn();
            $num='IA-'.$year.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO lssd_ia_dossiers (numero,officer_id,type,statut,priorite,titre,description,plaignant_nom,plaignant_contact,opened_by,date_ouverture) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,intval($_POST['officer_id']),$_POST['type'],'ouvert',$_POST['priorite'],trim($_POST['titre']),trim($_POST['description']??''),trim($_POST['plaignant_nom']??''),trim($_POST['plaignant_contact']??''),$uid,date('Y-m-d')]);
            $dossierId=$db->lastInsertId();
            $db->prepare("INSERT INTO lssd_ia_events (dossier_id,author_id,type,content) VALUES (?,?,?,?)")->execute([$dossierId,$uid,'note','Dossier ouvert par '.(mdt_session_display_name() ?: '—').' — '.$_POST['type']]);
            $msg="Dossier $num ouvert.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update_dossier') {
        $did=intval($_POST['dossier_id']);
        $db->prepare("UPDATE lssd_ia_dossiers SET statut=?,priorite=?,assigned_to=?,conclusion=?,date_cloture=? WHERE id=?")
           ->execute([$_POST['statut'],$_POST['priorite'],intval($_POST['assigned_to'])?:null,trim($_POST['conclusion']??''),in_array($_POST['statut'],['clos_exonere','clos_sanctionne','clos_archive'])?date('Y-m-d'):null,$did]);
        if (!empty(trim($_POST['event_content']??''))) {
            $db->prepare("INSERT INTO lssd_ia_events (dossier_id,author_id,type,content,is_private) VALUES (?,?,?,?,?)")->execute([$did,$uid,$_POST['event_type'],trim($_POST['event_content']),isset($_POST['event_private'])?1:0]);
        }
        $msg='Dossier mis à jour.';$msgType='success';
    }

    // Reconnaissances
    if ($action==='add_commendation') {
        try{$db->prepare("INSERT INTO lssd_commendations (officer_id,type,titre,description,awarded_by,award_date,is_public) VALUES (?,?,?,?,?,?,?)")->execute([intval($_POST['officer_id']),$_POST['type'],trim($_POST['titre']),trim($_POST['description']),$uid,date('Y-m-d'),isset($_POST['is_public'])?1:0]);$msg='Reconnaissance ajoutée.';$msgType='success';}catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='delete_commendation') {
        $db->prepare("DELETE FROM lssd_commendations WHERE id=? AND awarded_by=?")->execute([intval($_POST['id']),$uid]);
        $msg='Supprimée.';$msgType='success';
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────
$allOfficers=[];try{$allOfficers=$db->query("SELECT id,username,faction_rank FROM users WHERE faction='LSSD' AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

// Stats globales IA
$stats=[];
try{$stats['dossiers_ouverts']=(int)$db->query("SELECT COUNT(*) FROM lssd_ia_dossiers WHERE statut IN ('ouvert','enquete','audience')")->fetchColumn();}catch(Exception $e){$stats['dossiers_ouverts']=0;}
try{$stats['notes_total']=(int)$db->query("SELECT COUNT(*) FROM lssd_ia_notes")->fetchColumn();}catch(Exception $e){$stats['notes_total']=0;}
try{$stats['commendations']=(int)$db->query("SELECT COUNT(*) FROM lssd_commendations")->fetchColumn();}catch(Exception $e){$stats['commendations']=0;}
try{$stats['sanctions_actives']=(int)$db->query("SELECT COUNT(*) FROM lssd_sanctions WHERE status='active'")->fetchColumn();}catch(Exception $e){$stats['sanctions_actives']=0;}
try{$stats['clos_exo']=(int)$db->query("SELECT COUNT(*) FROM lssd_ia_dossiers WHERE statut='clos_exonere'")->fetchColumn();}catch(Exception $e){$stats['clos_exo']=0;}
try{$stats['clos_sanc']=(int)$db->query("SELECT COUNT(*) FROM lssd_ia_dossiers WHERE statut='clos_sanctionne'")->fetchColumn();}catch(Exception $e){$stats['clos_sanc']=0;}

$NTYPES=['commendation'=>['Félicitation','bg-green','fa-star'],'note'=>['Note','bg-gray','fa-note-sticky'],'warning'=>['Avertissement','bg-yellow','fa-exclamation-triangle'],'investigation'=>['Investigation','bg-red','fa-magnifying-glass']];
$DTYPES=['misconduct'=>['Faute professionnelle','bg-red'],'excessive_force'=>['Force excessive','bg-orange'],'corruption'=>['Corruption','bg-red'],'insubordination'=>['Insubordination','bg-yellow'],'negligence'=>['Négligence','bg-yellow'],'other'=>['Autre','bg-gray']];
$DSTATUTS=['ouvert'=>['Ouvert','bg-red'],'enquete'=>['En enquête','bg-yellow'],'audience'=>['Audience','bg-blue'],'clos_exonere'=>['Clôturé — Exonéré','bg-green'],'clos_sanctionne'=>['Clôturé — Sanctionné','bg-orange'],'clos_archive'=>['Archivé','bg-gray']];
$CTYPES=['medal_bravery'=>['Médaille de Bravoure','fas fa-medal'],'medal_service'=>['Médaille de Service','fas fa-award'],'citation'=>['Citation','fas fa-certificate'],'commendation_letter'=>['Lettre de félicitation','fas fa-envelope-open-text'],'officer_month'=>['Officier du mois','fas fa-trophy'],'other'=>['Autre','fas fa-star']];

include __DIR__ . '/includes/header.php';
?>
<style>
.ia-nav{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;border-bottom:1px solid var(--bdr);padding-bottom:.85rem}
.ia-nav-btn{padding:.48rem 1rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .17s;text-decoration:none;color:var(--txt-s);border:1px solid transparent;display:flex;align-items:center;gap:.45rem}
.ia-nav-btn:hover,.ia-nav-btn.active{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25);color:var(--blue-xl)}
.dossier-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;margin-bottom:.85rem;cursor:pointer;transition:all .2s}
.dossier-card:hover{border-color:var(--bdr-h)}
.dossier-card.ouvert{border-left:4px solid #ef4444}
.dossier-card.enquete{border-left:4px solid #f59e0b}
.dossier-card.audience{border-left:4px solid #10b981}
.dossier-card.clos_exonere,.dossier-card.clos_archive{border-left:4px solid #6b7280}
.dossier-card.clos_sanctionne{border-left:4px solid #f97316}
.timeline-event{display:flex;gap:.85rem;padding:.75rem 0;border-bottom:1px solid rgba(255,255,255,.04)}
.timeline-event:last-child{border-bottom:none}
.timeline-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:.25rem}
.commend-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem;margin-bottom:.65rem;transition:all .2s}
.commend-card:hover{border-color:var(--bdr-h)}
.stat-box{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;padding:1.1rem}
.progress-bar{height:8px;background:rgba(16,185,129,.1);border-radius:4px;overflow:hidden;margin-top:.5rem}
.progress-fill{height:100%;border-radius:4px;transition:width .5s}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<!-- IA NAVIGATION -->
<nav class="ia-nav">
    <a href="?section=overview" class="ia-nav-btn <?php echo $section==='overview'?'active':'';?>"><i class="fas fa-chart-pie"></i> Vue d'ensemble</a>
    <a href="?section=dossiers" class="ia-nav-btn <?php echo $section==='dossiers'?'active':'';?>"><i class="fas fa-folder-open"></i> Dossiers IA <span class="badge bg-red" style="font-size:.62rem"><?php echo $stats['dossiers_ouverts'];?></span></a>
    <a href="?section=notes" class="ia-nav-btn <?php echo $section==='notes'?'active':'';?>"><i class="fas fa-note-sticky"></i> Notes & Avertissements</a>
    <a href="?section=commendations" class="ia-nav-btn <?php echo $section==='commendations'?'active':'';?>"><i class="fas fa-medal"></i> Reconnaissances</a>
    <a href="?section=stats" class="ia-nav-btn <?php echo $section==='stats'?'active':'';?>"><i class="fas fa-chart-bar"></i> Statistiques IA</a>
</nav>

<?php // ── OVERVIEW ────────────────────────────────────────────────────────
if ($section==='overview'):?>
<div class="kpi-row" style="grid-template-columns:repeat(6,1fr);margin-bottom:1.5rem">
    <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.15);color:#fca5a5"><i class="fas fa-folder-open"></i></div><div class="kpi-lbl">Dossiers actifs</div><div class="kpi-val red"><?php echo $stats['dossiers_ouverts'];?></div></div>
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-note-sticky"></i></div><div class="kpi-lbl">Notes totales</div><div class="kpi-val"><?php echo $stats['notes_total'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.15);color:#fde68a"><i class="fas fa-clipboard-list"></i></div><div class="kpi-lbl">Sanctions actives</div><div class="kpi-val yellow"><?php echo $stats['sanctions_actives'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(16,185,129,.15);color:#6ee7b7"><i class="fas fa-medal"></i></div><div class="kpi-lbl">Reconnaissances</div><div class="kpi-val green"><?php echo $stats['commendations'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Dossiers exonérés</div><div class="kpi-val green"><?php echo $stats['clos_exo'];?></div></div>
    <div class="kpi"><div class="kpi-lbl">Dossiers sanctionnés</div><div class="kpi-val red"><?php echo $stats['clos_sanc'];?></div></div>
</div>
<div class="grid2">
  <div>
    <?php // Recent dossiers
    $recentDos=[];try{$recentDos=$db->query("SELECT d.*,u.username AS officer_name,u.faction_rank FROM lssd_ia_dossiers d LEFT JOIN users u ON u.id=d.officer_id WHERE d.statut NOT IN ('clos_archive') ORDER BY d.created_at DESC LIMIT 5")->fetchAll();}catch(Exception $e){}?>
    <div class="card">
        <div class="card-title"><i class="fas fa-folder-open"></i> Dossiers récents <a href="?section=dossiers" class="btn btn-xs btn-secondary" style="margin-left:auto">Tous</a></div>
        <?php if(empty($recentDos)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucun dossier</div>
        <?php else:foreach($recentDos as $d):[$dl,$db2]=$DSTATUTS[$d['statut']]??[$d['statut'],'bg-gray'];?>
        <div style="padding:.65rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.4rem">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.65rem">
                <div><div style="font-weight:700;font-size:.835rem"><?php echo htmlspecialchars($d['titre']);?></div><div style="font-size:.7rem;color:var(--txt-s)"><?php echo htmlspecialchars($d['numero']);?> · <?php echo htmlspecialchars($d['officer_name']??'—');?> · <?php echo date('d/m/Y',strtotime($d['date_ouverture']));?></div></div>
                <span class="badge <?php echo $db2;?>" style="font-size:.62rem"><?php echo $dl;?></span>
            </div>
        </div>
        <?php endforeach;endif;?>
    </div>
  </div>
  <div>
    <?php // Recent notes
    $recentNotes=[];try{$recentNotes=$db->query("SELECT n.*,u1.username AS officer_name,u2.username AS author_name FROM lssd_ia_notes n LEFT JOIN users u1 ON u1.id=n.officer_id LEFT JOIN users u2 ON u2.id=n.author_id ORDER BY n.created_at DESC LIMIT 6")->fetchAll();}catch(Exception $e){}?>
    <div class="card">
        <div class="card-title"><i class="fas fa-note-sticky"></i> Notes récentes <a href="?section=notes" class="btn btn-xs btn-secondary" style="margin-left:auto">Toutes</a></div>
        <?php if(empty($recentNotes)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucune note</div>
        <?php else:foreach($recentNotes as $n):[$tl,$tb,$ti]=$NTYPES[$n['type']]??[$n['type'],'bg-gray','fa-file'];?>
        <div style="display:flex;align-items:flex-start;gap:.65rem;padding:.6rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.4rem">
            <span class="badge <?php echo $tb;?>" style="font-size:.62rem;flex-shrink:0"><i class="fas <?php echo $ti;?>"></i></span>
            <div style="flex:1;min-width:0"><div style="font-weight:600;font-size:.82rem"><?php echo htmlspecialchars($n['officer_name']??'—');?></div><div style="font-size:.7rem;color:var(--txt-s);overflow:hidden;white-space:nowrap;text-overflow:ellipsis"><?php echo htmlspecialchars(mb_substr($n['content'],0,70));?></div></div>
            <div style="font-size:.68rem;color:var(--txt-m);flex-shrink:0"><?php echo date('d/m',strtotime($n['created_at']));?></div>
        </div>
        <?php endforeach;endif;?>
    </div>
  </div>
</div>

<?php // ── DOSSIERS IA ──────────────────────────────────────────────────────
elseif($section==='dossiers'):
    $viewDos=intval($_GET['dossier']??0);
    
    if($viewDos>0):
        // VIEW SINGLE DOSSIER
        $dos=null;$events=[];$dosOfficer=null;
        try{$s=$db->prepare("SELECT d.*,u.username AS officer_name,u.faction_rank,u2.username AS opener_name,u3.username AS investigator_name FROM lssd_ia_dossiers d LEFT JOIN users u ON u.id=d.officer_id LEFT JOIN users u2 ON u2.id=d.opened_by LEFT JOIN users u3 ON u3.id=d.assigned_to WHERE d.id=?");$s->execute([$viewDos]);$dos=$s->fetch();}catch(Exception $e){}
        try{$s=$db->prepare("SELECT e.*,u.username AS author FROM lssd_ia_events e LEFT JOIN users u ON u.id=e.author_id WHERE e.dossier_id=? ORDER BY e.created_at ASC");$s->execute([$viewDos]);$events=$s->fetchAll();}catch(Exception $e){}
        
        if($dos):[$dl,$db2]=$DSTATUTS[$dos['statut']]??[$dos['statut'],'bg-gray'];[$dtl,$dtb]=$DTYPES[$dos['type']]??[$dos['type'],'bg-gray'];
?>
    <a href="?section=dossiers" class="btn btn-ghost btn-sm" style="margin-bottom:1.1rem"><i class="fas fa-arrow-left"></i> Dossiers</a>
    <div class="grid2">
      <div>
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
                <div><div style="font-family:'IBM Plex Mono',monospace;font-size:.82rem;color:var(--blue-xl);margin-bottom:.25rem"><?php echo htmlspecialchars($dos['numero']);?></div><div style="font-size:1rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($dos['titre']);?></div></div>
                <span class="badge <?php echo $db2;?>"><?php echo $dl;?></span>
            </div>
            <?php foreach([['Officier mis en cause',$dos['officer_name'].' — '.($dos['faction_rank']??'')],['Type de faute',$dtl],['Enquêteur assigné',$dos['investigator_name']??'Non assigné'],['Ouvert par',$dos['opener_name']??'—'],['Date d\'ouverture',date('d/m/Y',strtotime($dos['date_ouverture']))],['Plaignant',$dos['plaignant_nom']??'—'],['Contact',$dos['plaignant_contact']??'—']] as [$l,$v]):if(empty($v))continue;?>
            <div style="display:flex;justify-content:space-between;padding:.42rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem"><span style="color:var(--txt-s)"><?php echo $l;?></span><span style="text-align:right"><?php echo htmlspecialchars($v);?></span></div>
            <?php endforeach;?>
            <?php if(!empty($dos['description'])):?><div style="margin-top:.85rem;padding:.75rem;background:var(--bg-ov);border-radius:8px;font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo nl2br(htmlspecialchars($dos['description']));?></div><?php endif;?>
        </div>
        <?php if(!empty($dos['conclusion'])):?>
        <div class="card" style="border-color:<?php echo $dos['statut']==='clos_exonere'?'rgba(16,185,129,.3)':'rgba(249,115,22,.3)';?>">
            <div class="card-title"><i class="fas fa-gavel"></i> Conclusion</div>
            <div style="font-size:.855rem;color:var(--txt);line-height:1.7"><?php echo nl2br(htmlspecialchars($dos['conclusion']));?></div>
        </div>
        <?php endif;?>
        <!-- UPDATE FORM -->
        <div class="card">
            <div class="card-title"><i class="fas fa-pen"></i> Mettre à jour le dossier</div>
            <form method="POST"><input type="hidden" name="action" value="update_dossier"><input type="hidden" name="dossier_id" value="<?php echo $dos['id'];?>">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Statut</label>
                        <select name="statut" class="form-select"><?php foreach($DSTATUTS as $k=>[$l,$b]):?><option value="<?php echo $k;?>" <?php echo $dos['statut']===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
                    </div>
                    <div class="form-group"><label class="form-label">Priorité</label>
                        <select name="priorite" class="form-select"><option value="faible" <?php echo $dos['priorite']==='faible'?'selected':'';?>>Faible</option><option value="normale" <?php echo $dos['priorite']==='normale'?'selected':'';?>>Normale</option><option value="haute" <?php echo $dos['priorite']==='haute'?'selected':'';?>>Haute</option><option value="critique" <?php echo $dos['priorite']==='critique'?'selected':'';?>>Critique</option></select>
                    </div>
                    <div class="form-group"><label class="form-label">Enquêteur assigné</label>
                        <select name="assigned_to" class="form-select"><option value="">Non assigné</option><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>" <?php echo ($dos['assigned_to']??0)==$o['id']?'selected':'';?>><?php echo htmlspecialchars($o['username']);?></option><?php endforeach;?></select>
                    </div>
                    <div class="form-group"><label class="form-label">Type d'événement</label>
                        <select name="event_type" class="form-select"><option value="note">Note</option><option value="convocation">Convocation</option><option value="audition">Audition</option><option value="preuve">Preuve ajoutée</option><option value="decision">Décision</option><option value="clôture">Clôture</option></select>
                    </div>
                    <div class="form-group form-full"><label class="form-label">Ajouter un événement / note</label><textarea name="event_content" class="form-textarea" placeholder="Décrivez l'événement, l'audition, la décision..."></textarea></div>
                    <div class="form-group"><label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;font-size:.82rem;color:var(--txt-s)"><input type="checkbox" name="event_private" value="1" style="accent-color:var(--blue)"> Privé (superviseurs)</label></div>
                    <div class="form-group form-full"><label class="form-label">Conclusion / Décision finale</label><textarea name="conclusion" class="form-textarea" placeholder="Résultat de l'enquête, sanction prononcée..."><?php echo htmlspecialchars($dos['conclusion']??'');?></textarea></div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.75rem"><i class="fas fa-save"></i> Mettre à jour</button>
            </form>
        </div>
      </div>
      <div>
        <!-- TIMELINE -->
        <div class="card">
            <div class="card-title"><i class="fas fa-timeline"></i> Chronologie du dossier (<?php echo count($events);?> événements)</div>
            <?php $EVCOLORS=['note'=>'#94a3b8','convocation'=>'#f59e0b','audition'=>'#10b981','preuve'=>'#8b5cf6','decision'=>'#ef4444','clôture'=>'#10b981'];
            foreach($events as $e):$ec=$EVCOLORS[$e['type']]??'#94a3b8';?>
            <div class="timeline-event">
                <div class="timeline-dot" style="background:<?php echo $ec;?>"></div>
                <div style="flex:1">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
                        <span style="font-size:.72rem;font-weight:700;color:<?php echo $ec;?>"><?php echo ucfirst($e['type']);?></span>
                        <?php if($e['is_private']):?><span class="badge bg-yellow" style="font-size:.6rem"><i class="fas fa-lock"></i> Privé</span><?php endif;?>
                        <span style="font-size:.68rem;color:var(--txt-m);margin-left:auto"><?php echo date('d/m/Y H:i',strtotime($e['created_at']));?></span>
                    </div>
                    <div style="font-size:.8rem;color:var(--txt-s);line-height:1.6"><?php echo nl2br(htmlspecialchars($e['content']));?></div>
                    <div style="font-size:.68rem;color:var(--txt-m);margin-top:.2rem">Par <?php echo htmlspecialchars($e['author']??'—');?></div>
                </div>
            </div>
            <?php endforeach;if(empty($events)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucun événement</div><?php endif;?>
        </div>
      </div>
    </div>
    <?php else:?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Dossier introuvable</div><?php endif;?>

<?php else:
    // LIST DOSSIERS
    $fStatut=$_GET['s']??'actif';$fType=$_GET['t']??'all';
    $q="SELECT d.*,u.username AS officer_name,u.faction_rank FROM lssd_ia_dossiers d LEFT JOIN users u ON u.id=d.officer_id WHERE 1=1";
    $params=[];
    if($fStatut==='actif')$q.=" AND d.statut IN ('ouvert','enquete','audience')";
    elseif($fStatut!=='all'){$q.=" AND d.statut=?";$params[]=$fStatut;}
    if($fType!=='all'){$q.=" AND d.type=?";$params[]=$fType;}
    $q.=" ORDER BY FIELD(d.priorite,'critique','haute','normale','faible'),d.created_at DESC LIMIT 100";
    $stmt=$db->prepare($q);$stmt->execute($params);$dossiers=$stmt->fetchAll();
?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
            <input type="hidden" name="section" value="dossiers">
            <select name="s" class="form-select" style="width:160px" onchange="this.form.submit()"><option value="actif">Dossiers actifs</option><option value="all" <?php echo $fStatut==='all'?'selected':'';?>>Tous</option><?php foreach($DSTATUTS as $k=>[$l,$b]):?><option value="<?php echo $k;?>" <?php echo $fStatut===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
            <select name="t" class="form-select" style="width:180px" onchange="this.form.submit()"><option value="all">Tous types</option><?php foreach($DTYPES as $k=>[$l,$b]):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
        </form>
        <button onclick="LSSD.openModal('newDossierModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ouvrir un dossier IA</button>
    </div>
    <?php if(empty($dossiers)):?><div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)"><i class="fas fa-folder-open" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun dossier</div>
    <?php else:foreach($dossiers as $d):[$dl,$db2]=$DSTATUTS[$d['statut']]??[$d['statut'],'bg-gray'];[$dtl,$dtb]=$DTYPES[$d['type']]??[$d['type'],'bg-gray'];$prioColor=['critique'=>'#ef4444','haute'=>'#f59e0b','normale'=>'#10b981','faible'=>'#10b981'][$d['priorite']??'normale']??'#10b981';?>
    <div class="dossier-card <?php echo $d['statut'];?>" onclick="location.href='?section=dossiers&dossier=<?php echo $d['id'];?>'">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.85rem">
            <div style="flex:1">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem">
                    <span class="mono" style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($d['numero']);?></span>
                    <span class="badge <?php echo $dtb;?>" style="font-size:.62rem"><?php echo $dtl;?></span>
                    <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $prioColor;?>" title="<?php echo $d['priorite'];?>"></div>
                </div>
                <div style="font-weight:700;font-size:.88rem;margin-bottom:.2rem"><?php echo htmlspecialchars($d['titre']);?></div>
                <div style="font-size:.72rem;color:var(--txt-s)">Officier: <strong><?php echo htmlspecialchars($d['officer_name']??'—');?></strong><?php if($d['faction_rank']):?> · <?php echo htmlspecialchars($d['faction_rank']);?><?php endif;?> · <?php echo date('d/m/Y',strtotime($d['date_ouverture']));?></div>
            </div>
            <div style="text-align:right"><span class="badge <?php echo $db2;?>" style="font-size:.65rem;display:block;margin-bottom:.3rem"><?php echo $dl;?></span><div style="font-size:.68rem;color:var(--txt-m)">P: <?php echo ucfirst($d['priorite']??'—');?></div></div>
        </div>
    </div>
    <?php endforeach;endif;
    endif; // end dossiers section ?>

<!-- MODAL NOUVEAU DOSSIER -->
<div id="newDossierModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-folder-plus"></i> Ouvrir un dossier IA</h2><button class="modal-cl" onclick="LSSD.closeModal('newDossierModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_dossier">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Officier mis en cause *</label><select name="officer_id" class="form-select" required><option value="">Sélectionner...</option><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username'].' — '.$o['faction_rank']);?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Type de faute *</label><select name="type" class="form-select" required><?php foreach($DTYPES as $k=>[$l,$b]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select"><option value="faible">Faible</option><option value="normale" selected>Normale</option><option value="haute">Haute</option><option value="critique">Critique</option></select></div>
        <div class="form-group"><label class="form-label">Plaignant (optionnel)</label><input type="text" name="plaignant_nom" class="form-input" placeholder="Nom du plaignant"></div>
        <div class="form-group"><label class="form-label">Contact plaignant</label><input type="text" name="plaignant_contact" class="form-input" placeholder="Téléphone, email..."></div>
        <div class="form-group form-full"><label class="form-label">Titre du dossier *</label><input type="text" name="titre" class="form-input" required placeholder="Ex: Usage excessif de la force — Incident du..."></div>
        <div class="form-group form-full"><label class="form-label">Description des faits *</label><textarea name="description" class="form-textarea" required style="min-height:120px" placeholder="Circonstances détaillées, témoignages recueillis..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newDossierModal')">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-folder-plus"></i> Ouvrir le dossier</button></div>
</form></div></div>

<?php // ── NOTES ────────────────────────────────────────────────────────────
elseif($section==='notes'):
    $fType=$_GET['type']??'all';$search=trim($_GET['q']??'');
    $q="SELECT n.*,u1.username AS officer_name,u1.faction_rank AS officer_rank,u2.username AS author_name FROM lssd_ia_notes n LEFT JOIN users u1 ON u1.id=n.officer_id LEFT JOIN users u2 ON u2.id=n.author_id WHERE 1=1";
    $params=[];
    if($fType!=='all'){$q.=" AND n.type=?";$params[]=$fType;}
    if($search){$q.=" AND (u1.username LIKE ? OR n.content LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;}
    if(!hasRole(['*','Administrateur','Développeur','Staff'])&&$__myLvl < (int)ceil($__maxLvl * 0.75))$q.=" AND (n.is_private=0 OR n.author_id=$uid)";
    $q.=" ORDER BY n.created_at DESC LIMIT 100";
    $stmt=$db->prepare($q);$stmt->execute($params);$notes=$stmt->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="section" value="notes">
        <input type="text" name="q" class="form-input" placeholder="Officier, contenu..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="type" class="form-select" style="width:155px" onchange="this.form.submit()"><option value="all">Tous types</option><?php foreach($NTYPES as $k=>[$l,$b,$i]):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?></select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <button onclick="LSSD.openModal('newNoteModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle note</button>
</div>
<?php if(empty($notes)):?><div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">Aucune note</div>
<?php else:foreach($notes as $n):[$tl,$tb,$ti]=$NTYPES[$n['type']]??[$n['type'],'bg-gray','fa-file'];?>
<div class="card" style="margin-bottom:.75rem;<?php echo $n['is_private']?'border-color:rgba(245,158,11,.3)':'';?>">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.65rem">
        <div style="display:flex;align-items:center;gap:.65rem">
            <span class="badge <?php echo $tb;?>" style="font-size:.72rem"><i class="fas <?php echo $ti;?>"></i> <?php echo $tl;?></span>
            <div><span style="font-weight:700"><?php echo htmlspecialchars($n['officer_name']??'—');?></span> <span style="color:var(--txt-m);font-size:.75rem"><?php echo htmlspecialchars($n['officer_rank']??'');?></span></div>
            <?php if($n['is_private']):?><span class="badge bg-yellow" style="font-size:.6rem"><i class="fas fa-lock"></i> Confidentiel</span><?php endif;?>
        </div>
        <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:.3rem">
            <div style="font-size:.7rem;color:var(--txt-m)">Par <?php echo htmlspecialchars($n['author_name']??'—');?> · <?php echo date('d/m/Y H:i',strtotime($n['created_at']));?></div>
            <?php if($n['author_id']==$uid||hasRole(['*','Administrateur','Développeur','Staff'])):?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="delete_note"><input type="hidden" name="id" value="<?php echo $n['id'];?>"><button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('Supprimer ?')"><i class="fas fa-trash"></i></button></form>
            <?php endif;?>
        </div>
    </div>
    <div style="font-size:.845rem;color:var(--txt);line-height:1.7;background:var(--bg-ov);border-radius:8px;padding:.75rem"><?php echo nl2br(htmlspecialchars($n['content']));?></div>
</div>
<?php endforeach;endif;?>

<!-- NOTE MODAL -->
<div id="newNoteModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-note-sticky"></i> Nouvelle note IA</h2><button class="modal-cl" onclick="LSSD.closeModal('newNoteModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_note">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Officier *</label><select name="officer_id" class="form-select" required><option value="">Sélectionner...</option><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username'].' — '.$o['faction_rank']);?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Type *</label><select name="note_type" class="form-select" required><?php foreach($NTYPES as $k=>[$l,$b,$i]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group form-full"><label class="form-label">Contenu *</label><textarea name="content" class="form-textarea" required style="min-height:110px"></textarea></div>
        <div class="form-group form-full"><label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem;color:var(--txt-s)"><input type="checkbox" name="is_private" value="1" style="accent-color:var(--blue)"> Confidentiel (superviseurs+)</label></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newNoteModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<?php // ── RECONNAISSANCES ────────────────────────────────────────────────
elseif($section==='commendations'):
    $comms=[];try{$comms=$db->query("SELECT c.*,u1.username AS officer_name,u1.faction_rank,u2.username AS awarded_by_name FROM lssd_commendations c LEFT JOIN users u1 ON u1.id=c.officer_id LEFT JOIN users u2 ON u2.id=c.awarded_by ORDER BY c.award_date DESC LIMIT 100")->fetchAll();}catch(Exception $e){}
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <span style="font-size:.78rem;color:var(--txt-m)"><?php echo count($comms);?> reconnaissance(s)</span>
    <button onclick="LSSD.openModal('newCommModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter une reconnaissance</button>
</div>
<?php if(empty($comms)):?><div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">Aucune reconnaissance</div>
<?php else:foreach($comms as $c):[$cl,$ci]=$CTYPES[$c['type']]??[$c['type'],'fas fa-star'];?>
<div class="commend-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.85rem">
        <div style="display:flex;align-items:center;gap:.85rem;flex:1">
            <div style="width:44px;height:44px;border-radius:11px;background:rgba(255,215,0,.12);color:#fbbf24;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0"><i class="<?php echo $ci;?>"></i></div>
            <div style="flex:1">
                <div style="font-weight:700;font-size:.88rem;margin-bottom:.2rem"><?php echo htmlspecialchars($c['titre']);?></div>
                <div style="font-size:.72rem;color:var(--txt-s)"><?php echo htmlspecialchars($cl);?> · <?php echo htmlspecialchars($c['officer_name']??'—');?><?php if($c['faction_rank']):?> (<?php echo $c['faction_rank'];?>)<?php endif;?> · <?php echo date('d/m/Y',strtotime($c['award_date']));?></div>
                <div style="font-size:.78rem;color:var(--txt-s);margin-top:.35rem;line-height:1.55"><?php echo htmlspecialchars($c['description']);?></div>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <div style="font-size:.68rem;color:var(--txt-m)">Par <?php echo htmlspecialchars($c['awarded_by_name']??'—');?></div>
            <?php if($c['awarded_by']==$uid||hasRole(['*','Administrateur','Développeur','Staff'])):?>
            <form method="POST" style="margin:0;margin-top:.35rem"><input type="hidden" name="action" value="delete_commendation"><input type="hidden" name="id" value="<?php echo $c['id'];?>"><button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('Supprimer ?')"><i class="fas fa-trash"></i></button></form>
            <?php endif;?>
        </div>
    </div>
</div>
<?php endforeach;endif;?>

<div id="newCommModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-medal"></i> Ajouter une reconnaissance</h2><button class="modal-cl" onclick="LSSD.closeModal('newCommModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add_commendation">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Officier *</label><select name="officer_id" class="form-select" required><option value="">Sélectionner...</option><?php foreach($allOfficers as $o):?><option value="<?php echo $o['id'];?>"><?php echo htmlspecialchars($o['username'].' — '.$o['faction_rank']);?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select" required><?php foreach($CTYPES as $k=>[$l,$i]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required placeholder="Ex: Médaille de bravoure pour..."></div>
        <div class="form-group form-full"><label class="form-label">Description *</label><textarea name="description" class="form-textarea" required placeholder="Motif de la distinction..."></textarea></div>
        <div class="form-group form-full"><label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem;color:var(--txt-s)"><input type="checkbox" name="is_public" value="1" checked style="accent-color:var(--blue)"> Publique (visible par tous)</label></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newCommModal')">Annuler</button><button type="submit" class="btn btn-success"><i class="fas fa-medal"></i> Décerner</button></div>
</form></div></div>

<?php // ── STATISTIQUES IA ──────────────────────────────────────────────────
elseif($section==='stats'):
    $period=$_GET['p']??'year';
    $dateFrom=$period==='month'?date('Y-m-01'):date('Y-01-01');
    $statsIA=[];
    try{$__s=$db->prepare("SELECT type,COUNT(*) as n FROM lssd_ia_dossiers WHERE DATE(created_at)>=? GROUP BY type");$__s->execute([$dateFrom]);foreach($__s->fetchAll() as $r)$statsIA[$r['type']]=$r['n'];}catch(Exception $e){}
    $topIssues=[];try{$topIssues=$db->prepare("SELECT u.username,u.faction_rank,COUNT(*) as nb FROM lssd_ia_dossiers d JOIN users u ON u.id=d.officer_id WHERE DATE(d.created_at)>=? GROUP BY d.officer_id ORDER BY nb DESC LIMIT 8");$topIssues->execute([$dateFrom]);$topIssues=$topIssues->fetchAll();}catch(Exception $e){}
    $topCommends=[];try{$topCommends=$db->prepare("SELECT u.username,u.faction_rank,COUNT(*) as nb FROM lssd_commendations c JOIN users u ON u.id=c.officer_id WHERE DATE(c.created_at)>=? GROUP BY c.officer_id ORDER BY nb DESC LIMIT 8");$topCommends->execute([$dateFrom]);$topCommends=$topCommends->fetchAll();}catch(Exception $e){}
?>
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem">
    <a href="?section=stats&p=month" class="btn btn-sm <?php echo $period==='month'?'btn-primary':'btn-ghost';?>">Ce mois</a>
    <a href="?section=stats&p=year" class="btn btn-sm <?php echo $period==='year'?'btn-primary':'btn-ghost';?>">Cette année</a>
</div>
<div class="grid2">
  <div>
    <div class="card"><div class="card-title"><i class="fas fa-folder-open"></i> Dossiers par type de faute</div>
        <?php $maxD=max(array_values($statsIA)+[1]);foreach($DTYPES as $k=>[$l,$b]):$n=$statsIA[$k]??0;$p=round($n/$maxD*100);?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.8rem"><div style="min-width:160px;color:var(--txt-s)"><?php echo $l;?></div><div style="flex:1;height:18px;background:rgba(16,185,129,.08);border-radius:3px;overflow:hidden"><div style="height:100%;background:var(--blue);border-radius:3px;width:<?php echo $p;?>%"></div></div><div class="mono" style="min-width:25px;text-align:right;font-weight:700"><?php echo $n;?></div></div>
        <?php endforeach;?>
    </div>
    <div class="card"><div class="card-title"><i class="fas fa-exclamation-triangle"></i> Officiers avec le plus de dossiers</div>
        <?php if(empty($topIssues)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucune donnée</div>
        <?php else:$maxI=max(array_column($topIssues,'nb')+[1]);foreach($topIssues as $o):$p=round($o['nb']/$maxI*100);?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.8rem"><div style="flex:1"><div style="font-weight:600"><?php echo htmlspecialchars($o['username']);?></div><div style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($o['faction_rank']??'');?></div></div><div style="width:100px;height:8px;background:rgba(239,68,68,.1);border-radius:3px;overflow:hidden"><div style="height:100%;background:#ef4444;border-radius:3px;width:<?php echo $p;?>%"></div></div><div class="mono" style="min-width:20px;text-align:right;color:#fca5a5;font-weight:700"><?php echo $o['nb'];?></div></div>
        <?php endforeach;endif;?>
    </div>
  </div>
  <div>
    <div class="card"><div class="card-title"><i class="fas fa-medal"></i> Officiers les plus décorés</div>
        <?php if(empty($topCommends)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucune donnée</div>
        <?php else:$maxC=max(array_column($topCommends,'nb')+[1]);foreach($topCommends as $o):$p=round($o['nb']/$maxC*100);?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.8rem"><div style="flex:1"><div style="font-weight:600"><?php echo htmlspecialchars($o['username']);?></div><div style="font-size:.7rem;color:var(--txt-m)"><?php echo htmlspecialchars($o['faction_rank']??'');?></div></div><div style="width:100px;height:8px;background:rgba(255,215,0,.1);border-radius:3px;overflow:hidden"><div style="height:100%;background:#fbbf24;border-radius:3px;width:<?php echo $p;?>%"></div></div><div class="mono" style="min-width:20px;text-align:right;color:#fbbf24;font-weight:700"><?php echo $o['nb'];?></div></div>
        <?php endforeach;endif;?>
    </div>
    <div class="card"><div class="card-title"><i class="fas fa-chart-pie"></i> Résultats des dossiers IA</div>
        <?php
        $totalD=0;$resStats=[];
        try{foreach($db->query("SELECT statut,COUNT(*) as n FROM lssd_ia_dossiers GROUP BY statut")->fetchAll() as $r){$resStats[$r['statut']]=$r['n'];$totalD+=$r['n'];}}catch(Exception $e){}
        $rstats=[['Ouverts',$resStats['ouvert']??0,'#ef4444'],['En enquête',$resStats['enquete']??0,'#f59e0b'],['Audience',$resStats['audience']??0,'#10b981'],['Exonérés',$resStats['clos_exonere']??0,'#10b981'],['Sanctionnés',$resStats['clos_sanctionne']??0,'#f97316'],['Archivés',$resStats['clos_archive']??0,'#6b7280']];
        foreach($rstats as [$l,$n,$col]):$p=$totalD>0?round($n/$totalD*100):0;?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.8rem"><div style="min-width:100px;color:var(--txt-s)"><?php echo $l;?></div><div style="flex:1;height:16px;background:rgba(255,255,255,.04);border-radius:3px;overflow:hidden"><div style="height:100%;background:<?php echo $col;?>;border-radius:3px;width:<?php echo $p;?>%"></div></div><div class="mono" style="font-weight:700"><?php echo $n;?> <span style="color:var(--txt-m);font-size:.7rem">(<?php echo $p;?>%)</span></div></div>
        <?php endforeach;?>
    </div>
  </div>
</div>

<?php endif;?>
<?php include __DIR__ . '/includes/footer.php';?>