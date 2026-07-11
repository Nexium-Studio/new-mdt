<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Procès & Audiences';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action']??'';
    if ($action === 'create') {
        try {
            $db->prepare("INSERT INTO avocat_proces (dossier_id,titre,date_audience,juge,officier,lieu,statut,strategie,notes,created_by) VALUES (?,?,?,?,?,?,'planifie',?,?,?)")
               ->execute([intval($_POST['dossier_id'])?:null, trim($_POST['titre']), trim($_POST['date_audience']), trim($_POST['juge']??''), trim($_POST['officier']??''), trim($_POST['lieu']??''), trim($_POST['strategie']??''), trim($_POST['notes']??''), $uid]);
            $msg='Procès planifié.'; $msgType='ok';
        } catch(Exception $e){$msg='Erreur : '.$e->getMessage();$msgType='err';}
    }
    if ($action === 'update_statut') {
        $db->prepare("UPDATE avocat_proces SET statut=?,verdict=? WHERE id=?")->execute([$_POST['statut'],$_POST['verdict']??'non_renseigne',intval($_POST['id'])]);
        $msg='Mis à jour.'; $msgType='ok';
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM avocat_proces WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Supprimé.'; $msgType='ok';
    }
}

$filterStatut = $_GET['statut'] ?? 'all';
$preselDossier = intval($_GET['dossier_id'] ?? 0);
$q = "SELECT ap.*, ad.titre AS dossier_titre, ad.numero AS dossier_num, p.firstname, p.lastname
      FROM avocat_proces ap
      LEFT JOIN avocat_dossiers ad ON ad.id=ap.dossier_id
      LEFT JOIN avocat_clients ac ON ac.id=ad.client_id
      LEFT JOIN profiles p ON p.id=ac.profile_id
      WHERE 1=1";
$params=[];
if ($filterStatut!=='all'){$q.=" AND ap.statut=?";$params[]=$filterStatut;}
$q.=" ORDER BY ap.date_audience ASC";
$stmt=$db->prepare($q);$stmt->execute($params);$proceso=$stmt->fetchAll();

$allDossiers=$db->query("SELECT id,numero,titre FROM avocat_dossiers WHERE statut NOT IN ('ferme','gagne','perdu') ORDER BY updated_at DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<style>
.proces-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1.2rem;margin-bottom:.85rem;transition:border-color .2s}
.proces-card:hover{border-color:var(--bdr-h)}
.proces-card.statut-planifie{border-left:3px solid #93c5fd}
.proces-card.statut-en_cours{border-left:3px solid var(--gold)}
.proces-card.statut-termine{border-left:3px solid #4ade80}
.proces-card.statut-reporte,.proces-card.statut-annule{border-left:3px solid #94a3b8;opacity:.7}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div><?php endif;?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php foreach(['all'=>'Tous','planifie'=>'Planifiés','en_cours'=>'En cours','termine'=>'Terminés','reporte'=>'Reportés'] as $s=>$l):?>
        <a href="?statut=<?php echo $s;?>" class="btn btn-sm <?php echo $filterStatut===$s?'btn-primary':'btn-ghost';?>"><?php echo $l;?></a>
        <?php endforeach;?>
    </div>
    <button onclick="Cabinet.openModal('newProcesModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Planifier un procès</button>
</div>

<?php if(empty($proceso)):?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-gavel" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucune audience
</div>
<?php else:?>
<?php foreach($proceso as $pr):
    $stLbl=['planifie'=>'Planifié','en_cours'=>'En cours','termine'=>'Terminé','reporte'=>'Reporté','annule'=>'Annulé'][$pr['statut']]??$pr['statut'];
    $stCls=['planifie'=>'bg-blue','en_cours'=>'bg-gold','termine'=>'bg-green','reporte'=>'bg-yellow','annule'=>'bg-gray'][$pr['statut']]??'bg-gray';
    $vCls=['acquitte'=>'bg-green','condamne'=>'bg-red','non_renseigne'=>'bg-gray'][$pr['verdict']]??'bg-gray';
    $vLbl=['non_renseigne'=>'—','acquitte'=>'Acquitté','condamne'=>'Condamné','reporte'=>'Reporté','abandon'=>'Abandon'][$pr['verdict']]??$pr['verdict'];
    $isPast = strtotime($pr['date_audience']) < time();
?>
<div class="proces-card statut-<?php echo $pr['statut'];?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:.75rem">
        <div>
            <div style="font-weight:700;font-size:.9rem;color:#f5edd6"><?php echo htmlspecialchars($pr['titre']);?></div>
            <?php if($pr['dossier_titre']):?><div style="font-size:.72rem;color:var(--txt-m)"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($pr['dossier_num'].' — '.$pr['dossier_titre']);?></div><?php endif;?>
        </div>
        <div style="display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap">
            <span class="badge <?php echo $stCls;?>"><?php echo $stLbl;?></span>
            <?php if($pr['verdict']!=='non_renseigne'):?><span class="badge <?php echo $vCls;?>"><?php echo $vLbl;?></span><?php endif;?>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.5rem;font-size:.78rem;color:var(--txt-m);margin-bottom:.75rem">
        <span><i class="fas fa-calendar" style="color:var(--gold);margin-right:.3rem"></i><?php echo date('d/m/Y H:i',strtotime($pr['date_audience']));?><?php echo $isPast&&$pr['statut']==='planifie'?' ⚠️':'';?></span>
        <?php if($pr['lieu']):?><span><i class="fas fa-map-marker-alt" style="color:var(--gold);margin-right:.3rem"></i><?php echo htmlspecialchars($pr['lieu']);?></span><?php endif;?>
        <?php if($pr['juge']):?><span><i class="fas fa-user-tie" style="color:var(--gold);margin-right:.3rem"></i>Juge: <?php echo htmlspecialchars($pr['juge']);?></span><?php endif;?>
        <?php if($pr['officier']):?><span><i class="fas fa-shield-halved" style="color:var(--gold);margin-right:.3rem"></i><?php echo htmlspecialchars($pr['officier']);?></span><?php endif;?>
        <?php if($pr['firstname']):?><span><i class="fas fa-user" style="color:var(--gold);margin-right:.3rem"></i><?php echo htmlspecialchars($pr['firstname'].' '.$pr['lastname']);?></span><?php endif;?>
    </div>
    <?php if($pr['strategie']):?>
    <div style="background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:7px;padding:.65rem .85rem;font-size:.78rem;color:var(--txt-s);margin-bottom:.75rem">
        <i class="fas fa-chess" style="color:var(--gold);margin-right:.35rem"></i><?php echo htmlspecialchars(mb_substr($pr['strategie'],0,200));?></div>
    <?php endif;?>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if($pr['statut']==='planifie'):?>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $pr['id'];?>"><input type="hidden" name="statut" value="en_cours"><input type="hidden" name="verdict" value="non_renseigne"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-play"></i> Commencer</button></form>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $pr['id'];?>"><input type="hidden" name="statut" value="reporte"><input type="hidden" name="verdict" value="non_renseigne"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-pause"></i> Reporter</button></form>
        <?php elseif($pr['statut']==='en_cours'):?>
        <?php foreach(['acquitte'=>['bg-green','Acquitté'],'condamne'=>['bg-red','Condamné']] as $v=>[$vc,$vl]):?>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $pr['id'];?>"><input type="hidden" name="statut" value="termine"><input type="hidden" name="verdict" value="<?php echo $v;?>"><button type="submit" class="btn btn-sm <?php echo $vc;?>"><?php echo $vl;?></button></form>
        <?php endforeach;?>
        <?php endif;?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ce procès ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $pr['id'];?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button></form>
    </div>
</div>
<?php endforeach;?>
<?php endif;?>

<div id="newProcesModal" class="modal-ov hidden">
    <div class="modal"><div class="modal-hd"><h2><i class="fas fa-gavel"></i> Planifier un procès</h2><button class="modal-cl" onclick="Cabinet.closeModal('newProcesModal')">×</button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group form-full"><label class="form-label">Titre de l'audience *</label><input type="text" name="titre" class="form-input" required placeholder="Ex: Audience préliminaire — Affaire Martin"></div>
            <div class="form-group"><label class="form-label">Date & heure *</label><input type="datetime-local" name="date_audience" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Dossier lié</label><select name="dossier_id" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach($allDossiers as $d):?><option value="<?php echo $d['id'];?>" <?php echo $preselDossier==$d['id']?'selected':'';?>><?php echo htmlspecialchars($d['numero'].' — '.$d['titre']);?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Juge</label><input type="text" name="juge" class="form-input" placeholder="Nom du juge"></div>
            <div class="form-group"><label class="form-label">Officier impliqué</label><input type="text" name="officier" class="form-input" placeholder="Ex: Officer Smith — LSPD"></div>
            <div class="form-group form-full"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" placeholder="Ex: Tribunal de Los Santos, Salle 3"></div>
            <div class="form-group form-full"><label class="form-label">Stratégie de défense</label><textarea name="strategie" class="form-textarea"></textarea></div>
            <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea"></textarea></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Cabinet.closeModal('newProcesModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Planifier</button></div>
    </form></div>
</div>

<?php include __DIR__ . '/includes/footer.php';?>