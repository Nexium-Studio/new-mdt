<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Gestion des Clients';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_client') {
        try {
            $db->prepare("INSERT INTO avocat_clients (profile_id,added_by,notes,statut) VALUES (?,?,'','actif')")
               ->execute([intval($_POST['profile_id']),$_SESSION['user_id']]);
            $msg='Client ajouté au cabinet.'; $msgType='ok';
        } catch(Exception $e) {
            $msg=str_contains($e->getMessage(),'Duplicate')?'Ce citoyen est déjà client.':'Erreur : '.$e->getMessage(); $msgType='err';
        }
    }
    if ($action === 'update_notes') {
        $db->prepare("UPDATE avocat_clients SET notes=? WHERE id=?")->execute([trim($_POST['notes']),intval($_POST['client_id'])]);
        $msg='Notes mises à jour.'; $msgType='ok';
    }
    if ($action === 'update_statut') {
        $db->prepare("UPDATE avocat_clients SET statut=? WHERE id=?")->execute([$_POST['statut'],intval($_POST['client_id'])]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }
    if ($action === 'remove' && ($__isPartner || ($__isStaff ?? false))) {
        $db->prepare("DELETE FROM avocat_clients WHERE id=?")->execute([intval($_POST['client_id'])]);
        $msg='Client retiré du cabinet.'; $msgType='ok';
    }
}

$profileSearch = trim($_GET['ps'] ?? '');
$profileResults = [];
if ($profileSearch) {
    $stmt=$db->prepare("
        SELECT p.*, (SELECT 1 FROM avocat_clients ac WHERE ac.profile_id=p.id LIMIT 1) AS is_client
        FROM profiles p WHERE p.firstname LIKE ? OR p.lastname LIKE ? OR p.phone LIKE ?
        ORDER BY p.lastname LIMIT 20
    ");
    $stmt->execute(["%{$profileSearch}%","%{$profileSearch}%","%{$profileSearch}%"]);
    $profileResults=$stmt->fetchAll();
}

$search=trim($_GET['q']??''); $filterStatut=$_GET['statut']??'actif';
$q="SELECT ac.*,p.firstname,p.lastname,p.phone,p.age,p.job,p.nationality,p.license_car,p.license_ppa,p.blood_type,u.username AS added_by_name,
           (SELECT COUNT(*) FROM avocat_dossiers d WHERE d.client_id=ac.id) AS nb_dossiers,
           (SELECT COUNT(*) FROM avocat_invoices i WHERE i.client_id=ac.id AND i.statut='pending') AS nb_impayees
    FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id LEFT JOIN users u ON u.id=ac.added_by WHERE 1=1";
$p2=[];
if($filterStatut!=='all'){$q.=" AND ac.statut=?";$p2[]=$filterStatut;}
if($search){$q.=" AND (p.firstname LIKE ? OR p.lastname LIKE ?)";$p2[]="%$search%";$p2[]="%$search%";}
$q.=" ORDER BY p.lastname,p.firstname";
$stmt=$db->prepare($q);$stmt->execute($p2);$clients=$stmt->fetchAll();

$viewClient=null;
if(isset($_GET['id'])){
    $stmt=$db->prepare("SELECT ac.*,p.*,u.username AS added_by_name FROM avocat_clients ac JOIN profiles p ON p.id=ac.profile_id LEFT JOIN users u ON u.id=ac.added_by WHERE ac.id=?");
    $stmt->execute([intval($_GET['id'])]); $viewClient=$stmt->fetch();
    if($viewClient){
        $cDos=$db->prepare("SELECT * FROM avocat_dossiers WHERE client_id=? ORDER BY created_at DESC");$cDos->execute([$viewClient['id']]);$cDos=$cDos->fetchAll();
        $cInv=$db->prepare("SELECT * FROM avocat_invoices WHERE client_id=? ORDER BY created_at DESC");$cInv->execute([$viewClient['id']]);$cInv=$cInv->fetchAll();
        $cDoc=$db->prepare("SELECT * FROM avocat_documents WHERE client_id=? ORDER BY created_at DESC");$cDoc->execute([$viewClient['id']]);$cDoc=$cDoc->fetchAll();
    }
}
include __DIR__ . '/includes/header.php';
?>
<style>
.client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:.85rem;margin-bottom:1.4rem}
.client-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1.1rem;cursor:pointer;transition:all .2s;display:flex;gap:.9rem;align-items:flex-start}
.client-card:hover{border-color:var(--bdr-h);transform:translateY(-1px)}
.client-avatar{width:42px;height:42px;border-radius:10px;background:rgba(217,119,6,.15);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.95rem;flex-shrink:0}
.pfield{display:flex;gap:.5rem;margin-bottom:.35rem;font-size:.82rem}
.pfield .lbl{color:var(--txt-m);min-width:110px;flex-shrink:0;font-size:.75rem}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle';?>"></i><?php echo $msg;?></div>
<?php endif;?>

<?php if($viewClient): ?>
<div style="margin-bottom:1.1rem"><a href="clients.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a></div>
<div class="grid2">
  <div>
    <div class="card">
        <div class="card-title"><i class="fas fa-id-card"></i> Fiche civile — Recensement LSPD</div>
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;padding:1rem;background:var(--bg-ov);border-radius:10px">
            <div style="width:52px;height:52px;border-radius:12px;background:rgba(217,119,6,.18);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;flex-shrink:0">
                <?php echo strtoupper(substr($viewClient['firstname'],0,1));?>
            </div>
            <div>
                <div style="font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;color:#f5edd6"><?php echo htmlspecialchars($viewClient['firstname'].' '.$viewClient['lastname']);?></div>
                <div style="margin-top:.25rem;display:flex;gap:.4rem;flex-wrap:wrap">
                    <span class="badge <?php echo $viewClient['statut']==='actif'?'bg-green':($viewClient['statut']==='blackliste'?'bg-red':'bg-gray');?>"><?php echo $viewClient['statut'];?></span>
                    <?php if($viewClient['blood_type']): ?><span class="badge bg-red"><?php echo $viewClient['blood_type'];?></span><?php endif;?>
                </div>
            </div>
        </div>
        <?php foreach([['Âge',$viewClient['age'].' ans'],['Nationalité',$viewClient['nationality']??'—'],['Téléphone',$viewClient['phone']??'—'],['Emploi',$viewClient['job']??'—'],['IBAN',$viewClient['iban']??'—'],['Groupe sanguin',$viewClient['blood_type']??'—']] as [$l,$v]):?>
        <div class="pfield"><span class="lbl"><?php echo $l;?></span><span><?php echo htmlspecialchars($v);?></span></div>
        <?php endforeach;?>
        <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <?php foreach([['license_car','Voiture','#4ade80'],['license_ppa','PPA','#93c5fd'],['license_boat','Bateau','#06b6d4'],['license_plane','Avion','#a855f7']] as [$k,$l,$c]):?>
            <span class="badge" style="background:<?php echo $viewClient[$k]?$c.'18':'rgba(100,116,139,.1)';?>;color:<?php echo $viewClient[$k]?$c:'#64748b';?>;border:1px solid <?php echo $viewClient[$k]?$c.'33':'rgba(100,116,139,.2)';?>">
                <?php echo $viewClient[$k]?'✓':'✗';?> <?php echo $l;?>
            </span>
            <?php endforeach;?>
        </div>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-lock"></i> Notes internes confidentielles</div>
        <form method="POST">
            <input type="hidden" name="action" value="update_notes">
            <input type="hidden" name="client_id" value="<?php echo $viewClient['id'];?>">
            <textarea name="notes" class="form-textarea" style="min-height:110px;margin-bottom:.75rem"><?php echo htmlspecialchars($viewClient['notes']??'');?></textarea>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save"></i> Sauvegarder</button>
                <select name="statut" class="form-select" style="width:140px;font-size:.78rem">
                    <option value="actif" <?php echo $viewClient['statut']==='actif'?'selected':'';?>>Actif</option>
                    <option value="inactif" <?php echo $viewClient['statut']==='inactif'?'selected':'';?>>Inactif</option>
                    <option value="blackliste" <?php echo $viewClient['statut']==='blackliste'?'selected':'';?>>Blacklisté</option>
                </select>
                <button type="submit" name="action" value="update_statut" class="btn btn-ghost btn-sm">Changer statut</button>
                <?php if($__isPartner || ($__isStaff ?? false)):?>
                <form method="POST" style="margin:0" onsubmit="return confirm('Retirer ce client ?')">
                    <input type="hidden" name="action" value="remove"><input type="hidden" name="client_id" value="<?php echo $viewClient['id'];?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-user-minus"></i></button>
                </form>
                <?php endif;?>
            </div>
        </form>
    </div>
  </div>
  <div>
    <div class="card">
        <div class="card-title"><i class="fas fa-folder-open"></i> Dossiers (<?php echo count($cDos);?>)</div>
        <?php foreach($cDos as $d): $st=['ouvert'=>'bg-blue','en_cours'=>'bg-yellow','tribunal'=>'bg-purple','ferme'=>'bg-gray','gagne'=>'bg-green','perdu'=>'bg-red'][$d['statut']]??'bg-gray'; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem .75rem;background:var(--bg-ov);border-radius:7px;margin-bottom:.4rem">
            <div><div style="font-size:.82rem;font-weight:600"><?php echo htmlspecialchars(mb_substr($d['titre'],0,40));?></div><div style="font-size:.7rem;color:var(--txt-m)"><?php echo $d['numero']??'';?></div></div>
            <span class="badge <?php echo $st;?>"><?php echo $d['statut'];?></span>
        </div>
        <?php endforeach; if(empty($cDos)):?><p style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucun dossier</p><?php endif;?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-file-invoice-dollar"></i> Factures (<?php echo count($cInv);?>)</div>
        <?php foreach($cInv as $inv):?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem .75rem;background:var(--bg-ov);border-radius:7px;margin-bottom:.4rem">
            <div style="font-size:.82rem"><?php echo htmlspecialchars(mb_substr($inv['service'],0,45));?></div>
            <div style="display:flex;gap:.4rem;align-items:center">
                <span class="mono" style="font-weight:700;font-size:.82rem;color:<?php echo $inv['statut']==='paid'?'#4ade80':'#f87171';?>">$<?php echo number_format($inv['amount'],0,'.',',');?></span>
                <span class="badge <?php echo $inv['statut']==='paid'?'bg-green':'bg-red';?>"><?php echo $inv['statut']==='paid'?'Payée':'Impayée';?></span>
            </div>
        </div>
        <?php endforeach; if(empty($cInv)):?><p style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucune facture</p><?php endif;?>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-paperclip"></i> Documents (<?php echo count($cDoc);?>)</div>
        <?php foreach($cDoc as $doc): $tc=['preuve'=>'bg-red','contrat'=>'bg-blue','temoignage'=>'bg-yellow','jugement'=>'bg-gold','plaidoirie'=>'bg-purple','autre'=>'bg-gray'][$doc['type']]??'bg-gray';?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem .75rem;background:var(--bg-ov);border-radius:7px;margin-bottom:.4rem">
            <div style="font-size:.82rem"><?php echo htmlspecialchars($doc['titre']);?></div>
            <span class="badge <?php echo $tc;?>"><?php echo $doc['type'];?></span>
        </div>
        <?php endforeach; if(empty($cDoc)):?><p style="color:var(--txt-m);font-size:.82rem;text-align:center;padding:.75rem">Aucun document</p><?php endif;?>
    </div>
  </div>
</div>

<?php else:?>
<!-- LISTE CLIENTS -->
<div class="card">
    <div class="card-title"><i class="fas fa-search"></i> Rechercher dans le Recensement LSPD</div>
    <form method="GET" style="display:flex;gap:.75rem">
        <input type="text" name="ps" class="form-input" placeholder="Nom, prénom, téléphone…" value="<?php echo htmlspecialchars($profileSearch);?>" style="flex:1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Rechercher</button>
    </form>
    <?php if($profileSearch && !empty($profileResults)):?>
    <div style="margin-top:1rem">
        <table class="tbl"><thead><tr><th>Nom</th><th>Âge</th><th>Emploi</th><th>Téléphone</th><th>Action</th></tr></thead><tbody>
        <?php foreach($profileResults as $pr):?>
        <tr>
            <td style="font-weight:600"><?php echo htmlspecialchars($pr['firstname'].' '.$pr['lastname']);?></td>
            <td><?php echo $pr['age'];?> ans</td>
            <td style="font-size:.78rem;color:var(--txt-m)"><?php echo htmlspecialchars($pr['job']??'—');?></td>
            <td class="mono" style="font-size:.75rem"><?php echo htmlspecialchars($pr['phone']??'—');?></td>
            <td><?php if($pr['is_client']):?><span class="badge bg-green">✓ Client</span><?php else:?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="add_client"><input type="hidden" name="profile_id" value="<?php echo $pr['id'];?>">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter</button></form><?php endif;?></td>
        </tr>
        <?php endforeach;?></tbody></table>
    </div>
    <?php elseif($profileSearch):?><p style="margin-top:.75rem;color:var(--txt-m);font-size:.82rem">Aucun résultat pour "<?php echo htmlspecialchars($profileSearch);?>".</p><?php endif;?>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.6rem;flex:1;flex-wrap:wrap">
        <input type="text" name="q" class="form-input" placeholder="Chercher un client…" value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:180px">
        <select name="statut" class="form-select" style="width:130px" onchange="this.form.submit()">
            <option value="actif" <?php echo $filterStatut==='actif'?'selected':'';?>>Actifs</option>
            <option value="inactif" <?php echo $filterStatut==='inactif'?'selected':'';?>>Inactifs</option>
            <option value="blackliste" <?php echo $filterStatut==='blackliste'?'selected':'';?>>Blacklistés</option>
            <option value="all" <?php echo $filterStatut==='all'?'selected':'';?>>Tous</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <span style="font-size:.78rem;color:var(--txt-m)"><?php echo count($clients);?> client(s)</span>
</div>

<?php if(empty($clients)):?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-users" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>Aucun client — utilisez la recherche LSPD ci-dessus
</div>
<?php else:?>
<div class="client-grid">
<?php foreach($clients as $c):?>
<div class="client-card" onclick="location.href='clients.php?id=<?php echo $c['id'];?>'">
    <div class="client-avatar"><?php echo strtoupper(substr($c['firstname'],0,1));?></div>
    <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.875rem;color:#f5edd6"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></div>
        <div style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($c['job']??'—');?> · <?php echo htmlspecialchars($c['phone']??'—');?></div>
        <div style="display:flex;gap:.4rem;margin-top:.35rem;flex-wrap:wrap">
            <?php if($c['nb_dossiers']>0):?><span class="badge bg-blue" style="font-size:.6rem"><?php echo $c['nb_dossiers'];?> dossier(s)</span><?php endif;?>
            <?php if($c['nb_impayees']>0):?><span class="badge bg-red" style="font-size:.6rem"><?php echo $c['nb_impayees'];?> impayée(s)</span><?php endif;?>
        </div>
    </div>
    <span class="badge <?php echo $c['statut']==='actif'?'bg-green':($c['statut']==='blackliste'?'bg-red':'bg-gray');?>" style="flex-shrink:0"><?php echo $c['statut'];?></span>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
<?php endif;?>

<?php include __DIR__ . '/includes/footer.php';?>