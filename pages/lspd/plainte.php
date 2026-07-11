<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Plaintes'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

try{$db->exec("CREATE TABLE IF NOT EXISTS `lspd_plaintes` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `numero` varchar(30) NOT NULL UNIQUE,
    `plaignant_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
    `mis_en_cause_id` int(11) DEFAULT NULL COMMENT 'FK → profiles.id',
    `categorie` enum('agression','vol','harcelement','escroquerie','degradation','menace','discrimination','autre') NOT NULL DEFAULT 'autre',
    `objet` varchar(300) NOT NULL,
    `description_rich` text DEFAULT NULL,
    `lieu_faits` varchar(200) DEFAULT NULL,
    `date_faits` datetime DEFAULT NULL,
    `statut` enum('deposee','en_enquete','classee_sans_suite','transmise_doj','resolue') DEFAULT 'deposee',
    `priorite` enum('faible','normale','haute','urgente') DEFAULT 'normale',
    `agent_referent` int(11) DEFAULT NULL COMMENT 'FK → users.id',
    `notes_internes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(`plaignant_id`), INDEX(`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $pid=intval($_POST['plaignant_id']);
            if (!$pid) throw new Exception('Le plaignant doit être enregistré dans la base profils.');
            // Vérif existence profil
            $chk=$db->prepare("SELECT id FROM profiles WHERE id=?");$chk->execute([$pid]);
            if(!$chk->fetchColumn()) throw new Exception('Ce citoyen n\'est pas enregistré dans profils.php.');
            $year=date('Y'); $seq=(int)$db->query("SELECT COUNT(*)+1 FROM lspd_plaintes WHERE YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $num='PL-'.$year.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO lspd_plaintes (numero,plaignant_id,mis_en_cause_id,categorie,objet,description_rich,lieu_faits,date_faits,statut,priorite,agent_referent,notes_internes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,$pid,intval($_POST['mis_en_cause_id'])?:null,$_POST['categorie'],trim($_POST['objet']),$_POST['description']??'',trim($_POST['lieu_faits']??''),($_POST['date_faits']??'')?:null,$_POST['statut']??'deposee',$_POST['priorite']??'normale',intval($_POST['agent_referent'])?:$uid,trim($_POST['notes_internes']??''),$uid]);
            $msg="Plainte $num enregistrée.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='update') {
        $id=intval($_POST['id']);
        $db->prepare("UPDATE lspd_plaintes SET statut=?,priorite=?,agent_referent=?,notes_internes=? WHERE id=?")
           ->execute([$_POST['statut'],$_POST['priorite'],intval($_POST['agent_referent'])?:$uid,trim($_POST['notes_internes']??''),$id]);
        $msg='Plainte mise à jour.';$msgType='success';
    }

    if ($action==='delete') {
        $db->prepare("DELETE FROM lspd_plaintes WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Plainte supprimée.';$msgType='success';
    }

    if ($msg) { header('Location: plainte.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ───────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fStat=$_GET['stat']??'all'; $fPrio=$_GET['prio']??'all';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(pl.numero LIKE ? OR pl.objet LIKE ? OR CONCAT(pp.firstname,' ',pp.lastname) LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if($fStat!=='all'){$where[]="pl.statut=?";$params[]=$fStat;}
if($fPrio!=='all'){$where[]="pl.priorite=?";$params[]=$fPrio;}
$wStr='WHERE '.implode(' AND ',$where);

$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lspd_plaintes pl LEFT JOIN profiles pp ON pp.id=pl.plaignant_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));

$plaintes=[];
try{$s=$db->prepare("SELECT pl.*,CONCAT(pp.firstname,' ',pp.lastname) AS plaignant,CONCAT(pm.firstname,' ',pm.lastname) AS mis_en_cause,u.username AS agent FROM lspd_plaintes pl LEFT JOIN profiles pp ON pp.id=pl.plaignant_id LEFT JOIN profiles pm ON pm.id=pl.mis_en_cause_id LEFT JOIN users u ON u.id=pl.agent_referent $wStr ORDER BY FIELD(pl.priorite,'urgente','haute','normale','faible'),pl.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$plaintes=$s->fetchAll();}catch(Exception $e){}

$statsP=[];try{foreach($db->query("SELECT statut,COUNT(*) n FROM lspd_plaintes GROUP BY statut")->fetchAll() as $r)$statsP[$r['statut']]=$r['n'];}catch(Exception $e){}

$profils=[];try{$profils=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,username FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$CATS=['agression'=>'Agression','vol'=>'Vol','harcelement'=>'Harcèlement','escroquerie'=>'Escroquerie','degradation'=>'Dégradation','menace'=>'Menace/Intimidation','discrimination'=>'Discrimination','autre'=>'Autre'];
$STATUTS=['deposee'=>['Déposée','bg-blue'],'en_enquete'=>['En enquête','bg-yellow'],'classee_sans_suite'=>['Classée','bg-gray'],'transmise_doj'=>['Transmise DOJ','bg-purple'],'resolue'=>['Résolue','bg-green']];
$PRIOS=['faible'=>['Faible','bg-gray'],'normale'=>['Normale','bg-blue'],'haute'=>['Haute','bg-yellow'],'urgente'=>['Urgente','bg-red']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <div class="kpi"><div class="kpi-lbl">Total</div><div class="kpi-val blue"><?php echo array_sum($statsP);?></div></div>
    <div class="kpi"><div class="kpi-lbl">Déposées</div><div class="kpi-val"><?php echo $statsP['deposee']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">En enquête</div><div class="kpi-val yellow"><?php echo $statsP['en_enquete']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Transmises DOJ</div><div class="kpi-val purple"><?php echo $statsP['transmise_doj']??0;?></div></div>
    <div class="kpi"><div class="kpi-lbl">Résolues</div><div class="kpi-val green"><?php echo $statsP['resolue']??0;?></div></div>
</div>

<div style="display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="N°, objet, plaignant..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <select name="prio" class="form-select" style="width:110px" onchange="this.form.submit()">
            <option value="all">Priorité</option>
            <?php foreach($PRIOS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fPrio===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fStat!=='all'||$fPrio!=='all'):?><a href="plainte.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSPD.openModal('newPlainteModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle plainte</button>
</div>

<!-- LISTE -->
<div style="display:flex;flex-direction:column;gap:.6rem">
<?php if(empty($plaintes)):?>
<div class="card" style="text-align:center;padding:3rem;color:var(--txt-m)"><i class="fas fa-file-circle-exclamation" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucune plainte</div>
<?php else:foreach($plaintes as $pl):[$sl,$sb]=$STATUTS[$pl['statut']]??[$pl['statut'],'bg-gray'];[$prl,$prb]=$PRIOS[$pl['priorite']]??[$pl['priorite'],'bg-gray'];?>
<div class="card" style="padding:0;overflow:hidden;border-left:4px solid <?php echo in_array($pl['priorite'],['haute','urgente'])?'var(--red)':'var(--bdr)';?>">
    <div style="display:flex;align-items:flex-start;gap:.85rem;padding:1rem 1.1rem;cursor:pointer" onclick="togglePlainte(<?php echo $pl['id'];?>)">
        <div>
            <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.3rem;flex-wrap:wrap">
                <span class="mono" style="font-size:.72rem;color:var(--blue-l)"><?php echo htmlspecialchars($pl['numero']);?></span>
                <span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo $sl;?></span>
                <span class="badge <?php echo $prb;?>" style="font-size:.6rem"><?php echo $prl;?></span>
                <span class="badge bg-gray" style="font-size:.6rem"><?php echo $CATS[$pl['categorie']]??$pl['categorie'];?></span>
            </div>
            <div style="font-weight:700;font-size:.9rem"><?php echo htmlspecialchars($pl['objet']);?></div>
            <div style="font-size:.73rem;color:var(--txt-s);margin-top:.2rem;display:flex;gap:.8rem;flex-wrap:wrap">
                <span><i class="fas fa-user" style="opacity:.5;margin-right:.25rem"></i><?php echo htmlspecialchars($pl['plaignant']??'—');?></span>
                <?php if($pl['mis_en_cause']):?><span><i class="fas fa-user-secret" style="opacity:.5;margin-right:.25rem;color:#fca5a5"></i><?php echo htmlspecialchars($pl['mis_en_cause']);?></span><?php endif;?>
                <?php if($pl['lieu_faits']):?><span><i class="fas fa-location-dot" style="opacity:.5;margin-right:.25rem"></i><?php echo htmlspecialchars($pl['lieu_faits']);?></span><?php endif;?>
                <span class="mono" style="font-size:.68rem"><?php echo date('d/m/Y',strtotime($pl['created_at']));?></span>
            </div>
        </div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:.4rem;flex-shrink:0">
            <span style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($pl['agent']??'—');?></span>
            <i class="fas fa-chevron-down pch-<?php echo $pl['id'];?>" style="color:var(--txt-m);font-size:.75rem;transition:transform .2s"></i>
        </div>
    </div>
    <!-- Détail dépliable -->
    <div id="pld-<?php echo $pl['id'];?>" style="display:none;border-top:1px solid var(--bdr);padding:1rem 1.1rem">
        <?php if($pl['description_rich']):?>
        <div style="font-size:.83rem;line-height:1.7;color:var(--txt);margin-bottom:.85rem"><?php echo $pl['description_rich'];?></div>
        <?php endif;?>
        <form method="POST" style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;align-items:end">
            <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo $pl['id'];?>">
            <div class="form-group"><label class="form-label">Statut</label>
                <select name="statut" class="form-select" style="font-size:.8rem">
                    <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $pl['statut']===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Priorité</label>
                <select name="priorite" class="form-select" style="font-size:.8rem">
                    <?php foreach($PRIOS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $pl['priorite']===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Agent référent</label>
                <select name="agent_referent" class="form-select" style="font-size:.8rem">
                    <?php foreach($agents as $ag):?><option value="<?php echo $ag['id'];?>" <?php echo $pl['agent_referent']==$ag['id']?'selected':'';?>><?php echo htmlspecialchars($ag['username']);?></option><?php endforeach;?>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Notes internes</label>
                <textarea name="notes_internes" class="form-textarea" style="min-height:65px"><?php echo htmlspecialchars($pl['notes_internes']??'');?></textarea>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.5rem">
                <form method="POST" data-confirm data-confirm-title="Supprimer la plainte" data-confirm-target="<?php echo htmlspecialchars(addslashes($pl['numero']));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $pl['id'];?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach;endif;?>
</div>

<!-- PAGINATION -->
<?php if($totalPages>1):?><div style="display:flex;gap:.35rem;justify-content:center;margin-top:.85rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL NOUVELLE PLAINTE -->
<div id="newPlainteModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-file-circle-exclamation"></i> Déposer une plainte</h2><button class="modal-cl" onclick="LSPD.closeModal('newPlainteModal')"><i class="fas fa-times"></i></button></div>
<form method="POST" onsubmit="return LSPD.saveEditor('plEditor','plDesc')"><div class="modal-bd"><input type="hidden" name="action" value="create"><input type="hidden" name="description" id="plDesc">
    <div style="background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.2);border-radius:9px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.81rem;color:var(--blue-xl)">
        <i class="fas fa-circle-info" style="margin-right:.4rem"></i>Le plaignant <strong>doit être enregistré</strong> dans la page Profils pour déposer une plainte.
    </div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Plaignant * <small style="font-weight:400;text-transform:none;letter-spacing:0">(doit être dans profils)</small></label>
            <select name="plaignant_id" class="form-select" required>
                <option value="">Sélectionner...</option>
                <?php foreach($profils as $p):?><option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Mis en cause (optionnel)</label>
            <select name="mis_en_cause_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($profils as $p):?><option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Catégorie *</label>
            <select name="categorie" class="form-select" required>
                <?php foreach($CATS as $k=>$l):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Priorité</label>
            <select name="priorite" class="form-select">
                <?php foreach($PRIOS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $k==='normale'?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Objet de la plainte *</label><input type="text" name="objet" class="form-input" required placeholder="Résumé en une phrase..."></div>
        <div class="form-group"><label class="form-label">Date des faits</label><input type="datetime-local" name="date_faits" class="form-input" value="<?php echo date('Y-m-d\TH:i');?>"></div>
        <div class="form-group"><label class="form-label">Lieu des faits</label><input type="text" name="lieu_faits" class="form-input" placeholder="Adresse, quartier..."></div>
        <div class="form-group"><label class="form-label">Agent référent</label>
            <select name="agent_referent" class="form-select">
                <?php foreach($agents as $ag):?><option value="<?php echo $ag['id'];?>" <?php echo $ag['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($ag['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Description des faits</label>
            <div style="border:1px solid rgba(37,99,235,.2);border-radius:8px;overflow:hidden">
                <div style="display:flex;gap:2px;padding:.4rem .5rem;background:rgba(0,0,0,.15);border-bottom:1px solid rgba(37,99,235,.1)">
                    <button type="button" class="btn btn-ghost btn-xs" onclick="LSPD.formatText('bold')"><i class="fas fa-bold"></i></button>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="LSPD.formatText('italic')"><i class="fas fa-italic"></i></button>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="LSPD.insertList()"><i class="fas fa-list-ul"></i></button>
                </div>
                <div id="plEditor" contenteditable="true" data-ph="Décrivez les faits en détail..." style="min-height:120px;padding:.85rem;background:rgba(0,0,0,.08);color:var(--txt);font-size:.84rem;line-height:1.7;outline:none"></div>
            </div>
        </div>
        <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes_internes" class="form-textarea" style="min-height:65px" placeholder="Notes confidentielles..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newPlainteModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Déposer la plainte</button></div>
</form></div></div>

<script>
function togglePlainte(id){
    const d=document.getElementById('pld-'+id);
    const ch=document.querySelector('.pch-'+id);
    if(!d)return;
    const open=d.style.display==='block';
    d.style.display=open?'none':'block';
    if(ch)ch.style.transform=open?'':'rotate(180deg)';
}
document.getElementById('plEditor')?.addEventListener('focus',function(){if(!this.innerText.trim())this.innerHTML='';});
</script>
<?php include __DIR__ . '/includes/footer.php';?>