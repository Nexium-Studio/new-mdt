<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Convocations'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

try{$db->exec("CREATE TABLE IF NOT EXISTS `lspd_convocations` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `numero` varchar(30) NOT NULL UNIQUE,
    `citoyen_id` int(11) NOT NULL COMMENT 'FK → profiles.id — Doit exister',
    `motif` enum('audition','garde_a_vue','comparution','entretien','notification','autre') NOT NULL DEFAULT 'audition',
    `objet` varchar(300) NOT NULL,
    `date_convocation` datetime NOT NULL,
    `lieu` varchar(200) DEFAULT 'Commissariat LSPD — Mission Row',
    `agent_convoquant` int(11) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `statut` enum('envoyee','presentee','absente','annulee','reportee') DEFAULT 'envoyee',
    `date_presentation` datetime DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='create') {
        try {
            $cid=intval($_POST['citoyen_id']);
            $chk=$db->prepare("SELECT id FROM profiles WHERE id=?");$chk->execute([$cid]);
            if(!$chk->fetchColumn()) throw new Exception('Ce citoyen n\'est pas enregistré dans profils.php.');
            $num='CV-'.date('Y').'-'.str_pad((int)$db->query("SELECT COUNT(*)+1 FROM lspd_convocations WHERE YEAR(created_at)=YEAR(NOW())")->fetchColumn(),4,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO lspd_convocations (numero,citoyen_id,motif,objet,date_convocation,lieu,agent_convoquant,description,statut,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$num,$cid,$_POST['motif'],trim($_POST['objet']),$_POST['date_convocation'],trim($_POST['lieu']??'Commissariat LSPD — Mission Row'),intval($_POST['agent_convoquant'])?:$uid,trim($_POST['description']??''),$_POST['statut']??'envoyee',trim($_POST['notes']??''),$uid]);
            $msg="Convocation $num émise.";$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='update_statut') {
        $db->prepare("UPDATE lspd_convocations SET statut=?,date_presentation=?,notes=? WHERE id=?")->execute([$_POST['statut'],$_POST['date_presentation']?:null,trim($_POST['notes']??''),intval($_POST['id'])]);
        $msg='Statut mis à jour.';$msgType='success';
    }
    if ($action==='delete') {
        $db->prepare("DELETE FROM lspd_convocations WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Convocation supprimée.';$msgType='success';
    }
    if ($msg) { header('Location: convocation.php?msg='.urlencode($msg).'&type='.$msgType); exit; }
}
if (isset($_GET['msg'])) { $msg=htmlspecialchars($_GET['msg']); $msgType=$_GET['type']??'success'; }

$search=$_GET['q']??''; $fStat=$_GET['stat']??'all';
$page=max(1,(int)($_GET['page']??1)); $perPage=20; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];
if($search){$where[]="(c.numero LIKE ? OR c.objet LIKE ? OR CONCAT(p.firstname,' ',p.lastname) LIKE ?)";$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";}
if($fStat!=='all'){$where[]="c.statut=?";$params[]=$fStat;}
$wStr='WHERE '.implode(' AND ',$where);
$total=0;try{$tc=$db->prepare("SELECT COUNT(*) FROM lspd_convocations c LEFT JOIN profiles p ON p.id=c.citoyen_id $wStr");$tc->execute($params);$total=(int)$tc->fetchColumn();}catch(Exception $e){}
$totalPages=max(1,(int)ceil($total/$perPage));
$convocations=[];
try{$s=$db->prepare("SELECT c.*,CONCAT(p.firstname,' ',p.lastname) AS citoyen,u.username AS agent FROM lspd_convocations c LEFT JOIN profiles p ON p.id=c.citoyen_id LEFT JOIN users u ON u.id=c.agent_convoquant $wStr ORDER BY c.date_convocation ASC LIMIT $perPage OFFSET $offset");$s->execute($params);$convocations=$s->fetchAll();}catch(Exception $e){}

$profils=[];try{$profils=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 300")->fetchAll();}catch(Exception $e){}
$agents=[];try{$agents=$db->query("SELECT id,username FROM users WHERE faction IN ('LSPD','LSSD') AND is_active=1 ORDER BY username")->fetchAll();}catch(Exception $e){}

$MOTIFS=['audition'=>'Audition','garde_a_vue'=>'Garde à vue','comparution'=>'Comparution','entretien'=>'Entretien','notification'=>'Notification','autre'=>'Autre'];
$STATUTS=['envoyee'=>['Envoyée','bg-blue'],'presentee'=>['Présenté(e)','bg-green'],'absente'=>['Absent(e)','bg-red'],'annulee'=>['Annulée','bg-gray'],'reportee'=>['Reportée','bg-yellow']];

include __DIR__ . '/includes/header.php';
?>
<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div style="display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:.4rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="N°, objet, citoyen..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="stat" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>" <?php echo $fStat===$k?'selected':'';?>><?php echo $l;?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fStat!=='all'):?><a href="convocation.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <button onclick="LSPD.openModal('newConvocModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle convocation</button>
</div>

<div class="tbl-wrap"><table class="tbl">
<thead><tr><th>N°</th><th>Citoyen</th><th>Motif</th><th>Objet</th><th>Date convocation</th><th>Lieu</th><th>Agent</th><th>Statut</th><th></th></tr></thead>
<tbody>
<?php if(empty($convocations)):?><tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune convocation</td></tr>
<?php else:foreach($convocations as $cv):[$sl,$sb]=$STATUTS[$cv['statut']]??[$cv['statut'],'bg-gray'];?>
<tr>
    <td class="mono" style="font-size:.72rem;color:var(--blue-l)"><?php echo htmlspecialchars($cv['numero']);?></td>
    <td style="font-weight:700"><?php echo htmlspecialchars($cv['citoyen']??'—');?></td>
    <td><span class="badge bg-blue" style="font-size:.6rem"><?php echo $MOTIFS[$cv['motif']]??$cv['motif'];?></span></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem"><?php echo htmlspecialchars($cv['objet']);?></td>
    <td style="font-family:'IBM Plex Mono',monospace;font-size:.75rem;color:<?php echo strtotime($cv['date_convocation'])<time()&&$cv['statut']==='envoyee'?'#fca5a5':'var(--txt-m)';?>"><?php echo date('d/m/Y H:i',strtotime($cv['date_convocation']));?></td>
    <td style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($cv['lieu']??'—');?></td>
    <td style="font-size:.75rem"><?php echo htmlspecialchars($cv['agent']??'—');?></td>
    <td>
        <button class="btn btn-ghost btn-xs" onclick="openUpdate(<?php echo htmlspecialchars(json_encode($cv,JSON_HEX_APOS|JSON_UNESCAPED_UNICODE),ENT_QUOTES);?>)">
            <span class="badge <?php echo $sb;?>" style="font-size:.6rem"><?php echo $sl;?></span>
        </button>
    </td>
    <td>
        <form method="POST" data-confirm data-confirm-title="Supprimer la convocation" data-confirm-target="<?php echo htmlspecialchars(addslashes($cv['numero']));?>" data-confirm-ok="Supprimer" style="margin:0;display:inline">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $cv['id'];?>">
            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div>

<!-- MODAL CRÉER -->
<div id="newConvocModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-envelope-open-text"></i> Émettre une convocation</h2><button class="modal-cl" onclick="LSPD.closeModal('newConvocModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div style="background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.2);border-radius:9px;padding:.65rem 1rem;margin-bottom:.85rem;font-size:.8rem;color:var(--blue-xl)">
        <i class="fas fa-circle-info" style="margin-right:.4rem"></i>Le citoyen doit être <strong>enregistré dans profils.php</strong> pour être convoqué.
    </div>
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Citoyen convoqué *</label>
            <select name="citoyen_id" class="form-select" required>
                <option value="">Sélectionner...</option>
                <?php foreach($profils as $p):?><option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['firstname'].' '.$p['lastname']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Motif *</label>
            <select name="motif" class="form-select" required>
                <?php foreach($MOTIFS as $k=>$l):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Objet de la convocation *</label><input type="text" name="objet" class="form-input" required placeholder="Motif détaillé en une phrase..."></div>
        <div class="form-group"><label class="form-label">Date et heure *</label><input type="datetime-local" name="date_convocation" class="form-input" required value="<?php echo date('Y-m-d\TH:i');?>"></div>
        <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" value="Commissariat LSPD — Mission Row"></div>
        <div class="form-group"><label class="form-label">Agent convoquant</label>
            <select name="agent_convoquant" class="form-select">
                <?php foreach($agents as $a):?><option value="<?php echo $a['id'];?>" <?php echo $a['id']==$uid?'selected':'';?>><?php echo htmlspecialchars($a['username']);?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group form-full"><label class="form-label">Description / Informations complémentaires</label><textarea name="description" class="form-textarea" style="min-height:80px" placeholder="Détails sur le motif de convocation..."></textarea></div>
        <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" style="min-height:55px" placeholder="Confidentielles..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newConvocModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Émettre</button></div>
</form></div></div>

<!-- MODAL UPDATE STATUT -->
<div id="updateConvocModal" class="modal-ov hidden" style="display:none">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Mettre à jour</h2><button class="modal-cl" onclick="LSPD.closeModal('updateConvocModal')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" id="uc-id">
    <div style="font-weight:700;font-size:.85rem;margin-bottom:.85rem" id="uc-info"></div>
    <div class="form-group"><label class="form-label">Nouveau statut</label>
        <select name="statut" id="uc-stat" class="form-select">
            <?php foreach($STATUTS as $k=>[$l]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?>
        </select>
    </div>
    <div class="form-group"><label class="form-label">Date de présentation effective</label><input type="datetime-local" name="date_presentation" id="uc-date" class="form-input"></div>
    <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="uc-notes" class="form-textarea" style="min-height:65px"></textarea></div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('updateConvocModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<script>
function openUpdate(cv){
    document.getElementById('uc-id').value=cv.id;
    document.getElementById('uc-info').textContent=cv.numero+' — '+cv.citoyen;
    document.getElementById('uc-stat').value=cv.statut;
    document.getElementById('uc-notes').value=cv.notes||'';
    LSPD.openModal('updateConvocModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>