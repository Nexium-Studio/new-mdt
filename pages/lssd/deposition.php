<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Dépositions'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

try{$db->exec("CREATE TABLE IF NOT EXISTS `lssd_depositions` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `numero` varchar(30) NOT NULL UNIQUE,
    `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id',
    `type_deposition` enum('entretien','arrestation','audition','temoignage','confrontation','autre') NOT NULL DEFAULT 'entretien',
    `contexte` varchar(300) NOT NULL COMMENT 'Contexte / motif',
    `contenu_rich` text DEFAULT NULL COMMENT 'Contenu HTML',
    `rapport_id` int(11) DEFAULT NULL COMMENT 'FK → reports.id',
    `convocation_id` int(11) DEFAULT NULL COMMENT 'FK → lssd_convocations.id',
    `agent_id` int(11) DEFAULT NULL COMMENT 'FK → users.id — Agent réceptionnant',
    `temoin_id` int(11) DEFAULT NULL COMMENT 'FK → users.id — Agent témoin',
    `lieu` varchar(200) DEFAULT NULL,
    `date_deposition` datetime DEFAULT NULL,
    `miranda_lu` tinyint(1) DEFAULT 0,
    `heure_miranda` time DEFAULT NULL,
    `avocat_present` tinyint(1) DEFAULT 0,
    `signe_par_citoyen` tinyint(1) DEFAULT 0,
    `statut` enum('brouillon','finalisee','transmise','archivee') DEFAULT 'finalisee',
    `notes_internes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(`citoyen_id`), INDEX(`rapport_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

// ── ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';

    if ($action==='create') {
        try {
            $cid=intval($_POST['citoyen_id']);
            $chk=$db->prepare("SELECT id FROM profiles WHERE id=?");$chk->execute([$cid]);
            if(!$chk->fetchColumn()) throw new Exception('Ce citoyen n\'est pas enregistré dans profils.php.');
            $num='DP-'.date('Y').'-'.str_pad((int)$db->query("SELECT COUNT(*)+1 FROM lssd_depositions")->fetchColumn(),5,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO lssd_depositions (numero,citoyen_id,type_deposition,contexte,contenu_rich,rapport_id,convocation_id,agent_id,temoin_id,lieu,date_deposition,miranda_lu,heure_miranda,avocat_present,signe_par_citoyen,statut,notes_internes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,$cid,$_POST['type_deposition'],trim($_POST['contexte']),$_POST['contenu_rich']??'',intval($_POST['rapport_id'])?:null,intval($_POST['convocation_id'])?:null,intval($_POST['agent_id'])?:$uid,intval($_POST['temoin_id'])?:null,trim($_POST['lieu']??''),($_POST['date_deposition']??'')?:null,(int)isset($_POST['miranda_lu']),($_POST['heure_miranda']??'')?:null,(int)isset($_POST['avocat_present']),(int)isset($_POST['signe_par_citoyen']),$_POST['statut']??'finalisee',trim($_POST['notes_internes']??''),$uid]);
            $msg="Déposition $num enregistrée.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }

    if ($action==='delete') {
        $db->prepare("DELETE FROM lssd_depositions WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Déposition supprimée.';$msgType='success';
    }

    if ($action==='update_statut') {
        $db->prepare("UPDATE lssd_depositions SET statut=? WHERE id=?")->execute([$_POST['statut'],intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }

    if ($msg) { header('Location: deposition.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

// ── DATA ───────────────────────────────────────────────────────────────────
$search=$_GET['q']??''; $fType=$_GET['type_f']??'all'; $fStat=$_GET['stat']??'all';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(d.numero LIKE ? OR d.contexte LIKE ? OR CONCAT(p.firstname,' ',p.lastname) LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if($fType!=='all'){$where[]="d.type_deposition=?";$params[]=$fType;}
if($fStat!=='all'){$where[]="d.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lssd_depositions d LEFT JOIN profiles p ON p.id=d.citoyen_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$depositions=[];
try{$s=$db->prepare("SELECT d.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen,ua.username AS agent_name FROM lssd_depositions d LEFT JOIN profiles p ON p.id=d.citoyen_id LEFT JOIN users ua ON ua.id=d.agent_id $wStr ORDER BY d.created_at DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$depositions=$s->fetchAll();}catch(Exception $e){}

$profils=[];try{$profils=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,username FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}
$rapports=[];try{$rapports=$db->query("SELECT id,title FROM reports WHERE faction='LSSD' ORDER BY created_at DESC LIMIT 100")->fetchAll();}catch(Exception $e){}
$convocations=[];try{$convocations=$db->query("SELECT c.id,c.numero,CONCAT(p.firstname,' ',p.lastname) AS citoyen FROM lssd_convocations c LEFT JOIN profiles p ON p.id=c.citoyen_id ORDER BY c.created_at DESC LIMIT 100")->fetchAll();}catch(Exception $e){}

$TYPES=['entretien'=>['Entretien','bg-blue'],'arrestation'=>['Arrestation','bg-red'],'audition'=>['Audition','bg-yellow'],'temoignage'=>['Témoignage','bg-green'],'confrontation'=>['Confrontation','bg-orange'],'autre'=>['Autre','bg-gray']];
$STATUTS=['brouillon'=>['Brouillon','bg-gray'],'finalisee'=>['Finalisée','bg-blue'],'transmise'=>['Transmise','bg-purple'],'archivee'=>['Archivée','bg-gray']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div style="display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="N°, contexte, citoyen..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="type_f" class="form-select" style="width:140px" onchange="this.form.submit()">
            <option value="all">Tous types</option>
            <?php foreach($TYPES as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fType===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <select name="stat" class="form-select" style="width:120px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fType!=='all'||$fStat!=='all'):?><a href="deposition.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSSD.openModal('newDepModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle déposition</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>N°</th><th>Type</th><th>Citoyen</th><th>Contexte</th><th>Agent</th><th>Date</th><th>Droits Miranda</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($depositions)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune déposition</td></tr>
<?php else:foreach($depositions as $d):[$tl,$tb]=$TYPES[$d['type_deposition']]??[$d['type_deposition'],'bg-gray'];[$sl,$sb]=$STATUTS[$d['statut']]??[$d['statut'],'bg-gray'];?>
<tr>
    <td class="mono" style="font-size:.72rem;color:var(--blue-l)"><?php echo htmlspecialchars($d['numero']);?></td>
    <td><span class="badge <?php echo $tb;?>" style="font-size:.62rem"><?php echo $tl;?></span></td>
    <td style="font-weight:700"><?php echo htmlspecialchars($d['citoyen']??'—');?></td>
    <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem"><?php echo htmlspecialchars($d['contexte']);?></td>
    <td style="font-size:.77rem"><?php echo htmlspecialchars($d['agent_name']??'—');?></td>
    <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo $d['date_deposition']?date('d/m/Y H:i',strtotime($d['date_deposition'])):'—';?></td>
    <td>
        <?php if($d['miranda_lu']):?>
        <span class="badge bg-green" style="font-size:.6rem"><i class="fas fa-check"></i> Lu <?php echo $d['heure_miranda']?'à '.substr($d['heure_miranda'],0,5):'';?></span>
        <?php else:?><span class="badge bg-gray" style="font-size:.6rem">Non lu</span><?php endif;?>
    </td>
    <td>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $d['id'];?>">
            <select name="statut" class="form-select" style="width:110px;font-size:.7rem;padding:.2rem .35rem" onchange="this.form.submit()">
                <?php foreach($STATUTS as $k=>[$sl2]):?><option value="<?php echo $k;?>" <?php echo $d['statut']===$k?'selected':'';?>><?php echo $sl2;?></option><?php endforeach;?>
            </select>
        </form>
    </td>
    <td style="display:flex;gap:.3rem;align-items:center">
        <button class="btn btn-ghost btn-xs" onclick="viewDep(<?php echo htmlspecialchars(json_encode($d,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE),ENT_QUOTES);?>)" title="Voir"><i class="fas fa-eye"></i></button>
        <form method="POST" data-confirm data-confirm-title="Supprimer la déposition" data-confirm-target="<?php echo htmlspecialchars(addslashes($d['numero']));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $d['id'];?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div>

<?php if($totalPages>1):?><div style="display:flex;gap:.35rem;justify-content:center;margin-top:.65rem">
    <?php if($page>1):?><a href="?page=<?php echo $page-1;?>&q=<?php echo urlencode($search);?>&type_f=<?php echo $fType;?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">← Préc.</a><?php endif;?>
    <span style="display:flex;align-items:center;font-size:.78rem;color:var(--txt-m)">Page <?php echo $page;?>/<?php echo $totalPages;?> — <?php echo $total;?> résultat(s)</span>
    <?php if($page<$totalPages):?><a href="?page=<?php echo $page+1;?>&q=<?php echo urlencode($search);?>&type_f=<?php echo $fType;?>&stat=<?php echo $fStat;?>" class="btn btn-ghost btn-sm">Suiv. →</a><?php endif;?>
</div><?php endif;?>

<!-- MODAL CRÉER DÉPOSITION -->
<div id="newDepModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-xl"><div class="modal-hd"><h2><i class="fas fa-scroll"></i> Enregistrer une déposition</h2><button class="modal-cl" onclick="LSSD.closeModal('newDepModal')"><i class="fas fa-times"></i></button></div>
<form method="POST" onsubmit="return LSSD.saveEditor('depEditor','dep_contenu')"><div class="modal-bd"><input type="hidden" name="action" value="create"><input type="hidden" name="contenu_rich" id="dep_contenu">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Citoyen * <small style="font-weight:400;text-transform:none;letter-spacing:0">(doit être dans profils)</small></label>
            <select name="citoyen_id" class="form-select" required>
                <option value="">Sélectionner...</option>
                <?php foreach($profils as $p):?><option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Type de déposition *</label>
            <select name="type_deposition" class="form-select" required>
                <?php foreach($TYPES as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Contexte / Motif *</label><input type="text" name="contexte" class="form-input" required placeholder="Arrestation pour vol à main armée, Audition suite au rapport #000012..."></div>
        <div class="form-group"><label class="form-label">Agent réceptionnant</label>
            <select name="agent_id" class="form-select">
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>" <?php echo $a['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($a['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Agent témoin</label>
            <select name="temoin_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Date et heure</label><input type="datetime-local" name="date_deposition" class="form-input" value="<?php echo date('Y-m-d\TH:i');?>"></div>
        <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" placeholder="Salle d'audition n°1, Commissariat..."></div>
        <div class="form-group"><label class="form-label">Rapport lié</label>
            <select name="rapport_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($rapports as $r):?><option value="<?php echo $r['id'];?>">#<?php echo str_pad($r['id'],6,'0',STR_PAD_LEFT);?> — <?php echo htmlspecialchars(mb_substr($r['title']??'',0,45));?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Convocation liée</label>
            <select name="convocation_id" class="form-select">
                <option value="">— Aucune —</option>
                <?php foreach($convocations as $c):?><option value="<?php echo $c['id'];?>"><?php echo htmlspecialchars($c['numero'].' — '.$c['citoyen']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Statut</label>
            <select name="statut" class="form-select">
                <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $k==='finalisee'?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
    </div>

    <!-- DROITS MIRANDA -->
    <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.15);border-radius:10px;padding:.85rem 1rem;margin:1rem 0">
        <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-m);margin-bottom:.65rem"><i class="fas fa-scale-balanced" style="margin-right:.3rem;color:var(--blue-l)"></i>Droits Miranda & Procédure</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem">
                <input type="checkbox" name="miranda_lu" value="1" id="chkMiranda" style="accent-color:var(--blue);width:15px;height:15px">
                Droits Miranda lus
            </label>
            <div class="form-group" style="margin:0" id="heureBlock" style="display:none">
                <label class="form-label">Heure Miranda</label>
                <input type="time" name="heure_miranda" class="form-input" style="font-size:.82rem">
            </div>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem">
                <input type="checkbox" name="avocat_present" value="1" style="accent-color:var(--blue);width:15px;height:15px">
                Avocat présent
            </label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.82rem">
                <input type="checkbox" name="signe_par_citoyen" value="1" style="accent-color:var(--blue);width:15px;height:15px">
                Signé par le citoyen
            </label>
        </div>
    </div>

    <!-- CONTENU DÉPOSITION -->
    <div class="form-group" style="margin-bottom:.75rem">
        <label class="form-label">Contenu de la déposition</label>
        <div style="border:1px solid rgba(16,185,129,.2);border-radius:9px;overflow:hidden">
            <div style="display:flex;flex-wrap:wrap;gap:2px;padding:.4rem .55rem;background:rgba(0,0,0,.15);border-bottom:1px solid rgba(16,185,129,.1)">
                <button type="button" class="btn btn-ghost btn-xs" onclick="LSSD.formatText('bold')"><i class="fas fa-bold"></i></button>
                <button type="button" class="btn btn-ghost btn-xs" onclick="LSSD.formatText('italic')"><i class="fas fa-italic"></i></button>
                <button type="button" class="btn btn-ghost btn-xs" onclick="LSSD.formatText('underline')"><i class="fas fa-underline"></i></button>
                <span style="width:1px;background:rgba(255,255,255,.06);margin:3px 2px"></span>
                <button type="button" class="btn btn-ghost btn-xs" onclick="LSSD.insertList()"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="btn btn-ghost btn-xs" onclick="document.execCommand('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                <span style="width:1px;background:rgba(255,255,255,.06);margin:3px 2px"></span>
                <button type="button" class="btn btn-ghost btn-xs" onclick="document.execCommand('insertHTML',false,'<hr style=&quot;border-color:rgba(16,185,129,.3)&quot;>')">—</button>
            </div>
            <div id="depEditor" contenteditable="true" style="min-height:180px;padding:1rem;background:rgba(0,0,0,.08);color:var(--txt);font-size:.85rem;line-height:1.7;outline:none" data-ph="Transcription de la déposition..."></div>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Notes internes <small style="font-weight:400;text-transform:none;letter-spacing:0">(non visible dans l'officiel)</small></label>
        <textarea name="notes_internes" class="form-textarea" style="min-height:60px" placeholder="Observations confidentielles..."></textarea>
    </div>
</div>
<div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('newDepModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer la déposition</button></div>
</form></div></div>

<!-- MODAL VOIR DÉPOSITION -->
<div id="viewDepModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-xl"><div class="modal-hd"><h2><i class="fas fa-scroll"></i> <span id="vd-num"></span></h2><button class="modal-cl" onclick="LSSD.closeModal('viewDepModal')"><i class="fas fa-times"></i></button></div>
<div class="modal-bd" id="vd-body"></div>
<div class="modal-ft"><button class="btn btn-ghost" onclick="LSSD.closeModal('viewDepModal')">Fermer</button><button class="btn btn-secondary" onclick="printDep()"><i class="fas fa-print"></i> Imprimer</button></div>
</div></div>

<script>
document.getElementById('chkMiranda')?.addEventListener('change',function(){
    document.getElementById('heureBlock').style.display=this.checked?'block':'none';
});

function viewDep(d) {
    document.getElementById('vd-num').textContent = d.numero;
    document.getElementById('vd-body').innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;margin-bottom:1rem">
            <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.65rem .85rem"><div class="form-label">Citoyen</div><div style="font-weight:700;margin-top:.2rem">${d.citoyen||'—'}</div></div>
            <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.65rem .85rem"><div class="form-label">Agent</div><div style="font-weight:700;margin-top:.2rem">${d.agent_name||'—'}</div></div>
            <div style="background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:.65rem .85rem"><div class="form-label">Date</div><div style="font-weight:700;margin-top:.2rem;font-family:'IBM Plex Mono',monospace;font-size:.82rem">${d.date_deposition?d.date_deposition.replace('T',' ').substring(0,16):'—'}</div></div>
        </div>
        <div style="margin-bottom:.75rem;display:flex;gap:.4rem;flex-wrap:wrap">
            ${d.miranda_lu?`<span class="badge bg-green" style="font-size:.65rem"><i class="fas fa-scale-balanced"></i> Miranda lu ${d.heure_miranda?'à '+d.heure_miranda.substring(0,5):''}</span>`:'<span class="badge bg-red" style="font-size:.65rem">Miranda non lu</span>'}
            ${d.avocat_present?'<span class="badge bg-blue" style="font-size:.65rem"><i class="fas fa-briefcase"></i> Avocat présent</span>':''}
            ${d.signe_par_citoyen?'<span class="badge bg-green" style="font-size:.65rem"><i class="fas fa-signature"></i> Signé</span>':''}
        </div>
        <div style="margin-bottom:.85rem"><div class="form-label" style="margin-bottom:.35rem">Contexte</div><div style="font-size:.84rem;font-weight:600">${d.contexte}</div></div>
        ${d.contenu_rich?`<div style="font-size:.85rem;line-height:1.7;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;padding:1rem">${d.contenu_rich}</div>`:'<div style="color:var(--txt-m);font-size:.82rem;font-style:italic">Aucun contenu enregistré.</div>'}
    `;
    LSSD.openModal('viewDepModal');
}
function printDep(){
    const c=document.getElementById('vd-body')?.innerHTML||'';
    const w=window.open('','_blank');
    w.document.write(`<html><head><title>Déposition</title><style>body{font-family:sans-serif;font-size:13px;padding:2rem}h2{border-bottom:1px solid #ccc;padding-bottom:.5rem}</style></head><body><h2>${document.getElementById('vd-num')?.textContent||''}</h2>${c}</body></html>`);
    w.document.close();w.print();
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>