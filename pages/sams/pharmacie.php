<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Pharmacie & Stock';
$db = Database::getInstance()->getConnection();
$msg='';$msgType='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='add_med' || $action==='edit_med') {
        try {
            $args=[trim($_POST['nom']),$_POST['categorie'],trim($_POST['description']??''),intval($_POST['stock_actuel']),intval($_POST['stock_min']),intval($_POST['stock_max']),trim($_POST['unite']??'unite'),floatval($_POST['prix']??0),isset($_POST['prescriptible'])?1:0];
            if ($action==='add_med') {
                $db->prepare("INSERT INTO sams_pharmacie (nom,categorie,description,stock_actuel,stock_min,stock_max,unite,prix_unitaire,prescriptible,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([...$args,$_SESSION['user_id']]);
                $msg='Médicament ajouté.';$msgType='success';
            } else {
                $db->prepare("UPDATE sams_pharmacie SET nom=?,categorie=?,description=?,stock_actuel=?,stock_min=?,stock_max=?,unite=?,prix_unitaire=?,prescriptible=? WHERE id=?")
                   ->execute([...$args,intval($_POST['id'])]);
                $msg='Médicament mis à jour.';$msgType='success';
            }
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='mouvement') {
        try {
            $mid=intval($_POST['med_id']);$qty=intval($_POST['quantite']);$type=$_POST['type_mvt'];
            $delta=$type==='entree'?$qty:-$qty;
            $db->prepare("UPDATE sams_pharmacie SET stock_actuel=GREATEST(0,stock_actuel+?) WHERE id=?")->execute([$delta,$mid]);
            $db->prepare("INSERT INTO sams_pharmacie_mouvements (medicament_id,type,quantite,motif,user_id) VALUES (?,?,?,?,?)")->execute([$mid,$type,$qty,trim($_POST['motif']??''),$_SESSION['user_id']]);
            $msg='Mouvement enregistré.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
    if ($action==='toggle_actif') {
        $db->prepare("UPDATE sams_pharmacie SET actif=1-actif WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Statut modifié.';$msgType='success';
    }
    if ($action==='delete_med') {
        try {
            $mid=intval($_POST['id']);
            $db->prepare("DELETE FROM sams_pharmacie_mouvements WHERE medicament_id=?")->execute([$mid]);
            $db->prepare("DELETE FROM sams_pharmacie WHERE id=?")->execute([$mid]);
            $msg='Article supprimé du stock.';$msgType='success';
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage();$msgType='error';}
    }
}

$filterCat=$_GET['cat']??'all';$filterAlerte=$_GET['alerte']??'';$search=trim($_GET['q']??'');
$q="SELECT * FROM sams_pharmacie WHERE 1=1";$params=[];
if($filterCat!=='all'){$q.=" AND categorie=?";$params[]=$filterCat;}
if($filterAlerte){$q.=" AND stock_actuel<=stock_min";}
if($search){$q.=" AND nom LIKE ?";$params[]="%$search%";}
$q.=" ORDER BY actif DESC, CASE WHEN stock_actuel<=stock_min THEN 0 ELSE 1 END, nom";
$stmt=$db->prepare($q);$stmt->execute($params);$meds=$stmt->fetchAll();

$alerteCount=0;try{$alerteCount=(int)$db->query("SELECT COUNT(*) FROM sams_pharmacie WHERE stock_actuel<=stock_min AND actif=1")->fetchColumn();}catch(Exception $e){}
$totalValeur=0;try{$totalValeur=(float)$db->query("SELECT COALESCE(SUM(stock_actuel*prix_unitaire),0) FROM sams_pharmacie WHERE actif=1")->fetchColumn();}catch(Exception $e){}

$CATS=['analgesique'=>'Analgésique','antibiotique'=>'Antibiotique','cardiovasculaire'=>'Cardiovasculaire','neurologique'=>'Neurologique','urgence'=>'Urgence','materiel'=>'Matériel','autre'=>'Autre'];
$CAT_COLORS=['analgesique'=>'badge-blue','antibiotique'=>'badge-green','cardiovasculaire'=>'badge-red','neurologique'=>'badge-purple','urgence'=>'badge-orange','materiel'=>'badge-gray','autre'=>'badge-gray'];

// Mouvements récents (auteur en Nom + Prénom, jamais le pseudo)
$mouvements=[];try{$mouvements=$db->query("SELECT m.*,p.nom AS med_nom,cp.firstname AS auth_fn,cp.lastname AS auth_ln FROM sams_pharmacie_mouvements m LEFT JOIN sams_pharmacie p ON p.id=m.medicament_id LEFT JOIN users u ON u.id=m.user_id LEFT JOIN profiles cp ON cp.id=u.profile_id ORDER BY m.created_at DESC LIMIT 20")->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
?>
<style>
.pharma-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1.05rem;transition:all .2s}
.pharma-card:hover{border-color:var(--bdr-h)}
.pharma-card.alerte{border-color:rgba(220,38,38,.4);background:rgba(220,38,38,.04)}
.stock-bar{height:8px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;margin-top:.5rem}
.stock-bar-fill{height:100%;border-radius:4px;transition:width .4s ease}
.pharma-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:.85rem}
/* filtre alerte (toggle custom, pas de checkbox native) */
.alerte-toggle{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .85rem;border-radius:8px;text-decoration:none;
  border:1px solid var(--bdr);color:var(--txt-s);font-size:.8rem;font-weight:600;transition:all .15s}
.alerte-toggle:hover{border-color:var(--bdr-h);color:var(--txt)}
.alerte-toggle.on{background:rgba(225,29,72,.12);border-color:var(--red);color:var(--red-l)}
.alerte-toggle .dot{width:9px;height:9px;border-radius:50%;background:var(--txt-m)}
.alerte-toggle.on .dot{background:var(--red);box-shadow:0 0 6px var(--red)}
/* switch custom (sur ordonnance) */
.rx-switch{display:flex;align-items:center;gap:.6rem;cursor:pointer;user-select:none;margin-top:1.1rem}
.rx-track{width:42px;height:24px;border-radius:24px;background:rgba(255,255,255,.12);border:1px solid var(--bdr);position:relative;flex-shrink:0;transition:all .2s}
.rx-track::before{content:'';position:absolute;left:3px;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s}
.rx-switch.on .rx-track{background:var(--red);border-color:var(--red)}
.rx-switch.on .rx-track::before{transform:translateX(18px)}
.rx-switch .lbl{font-size:.82rem;color:var(--txt-s)}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-pills"></i></div><div class="kpi-lbl">Médicaments actifs</div><div class="kpi-val"><?php echo count(array_filter($meds,fn($m)=>$m['actif']));?></div></div>
    <div class="kpi" data-color="<?php echo $alerteCount>0?'orange':'green';?>"><div class="kpi-ico"><i class="fas fa-triangle-exclamation"></i></div><div class="kpi-lbl">Alertes stock</div><div class="kpi-val"><?php echo $alerteCount;?></div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-sack-dollar"></i></div><div class="kpi-lbl">Valeur stock</div><div class="kpi-val">$<?php echo number_format($totalValeur,0,'.',',');?></div></div>
    <div class="kpi" data-color="purple"><div class="kpi-ico"><i class="fas fa-layer-group"></i></div><div class="kpi-lbl">Catégories</div><div class="kpi-val"><?php echo count($CATS);?></div></div>
</div>

<div class="doc-toolbar">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;flex:1">
        <div class="search-wrap"><i class="fas fa-search"></i><input type="text" name="q" placeholder="Rechercher un médicament..." value="<?php echo htmlspecialchars($search);?>"></div>
        <select name="cat" class="form-select" style="width:160px" onchange="this.form.submit()">
            <option value="all">Toutes catégories</option>
            <?php foreach($CATS as $k=>$v):?><option value="<?php echo $k;?>" <?php echo $filterCat===$k?'selected':'';?>><?php echo $v;?></option><?php endforeach;?>
        </select>
        <a href="?<?php echo http_build_query(array_merge($_GET,['alerte'=>$filterAlerte?'':'1']));?>" class="alerte-toggle <?php echo $filterAlerte?'on':'';?>"><span class="dot"></span> Alertes seulement</a>
    </form>
    <button onclick="openAddMed()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvel article</button>
</div>

<div class="grid2" style="gap:1.5rem">
  <div>
    <?php if(empty($meds)):?><div class="empty-state"><i class="fas fa-pills empty-icon"></i><p>Aucun médicament.</p></div><?php else:?>
    <div class="pharma-grid">
    <?php foreach($meds as $m):
        $pct=$m['stock_max']>0?min(100,round($m['stock_actuel']/$m['stock_max']*100)):0;
        $isAlerte=$m['stock_actuel']<=$m['stock_min'];
        $barColor=$isAlerte?'#dc2626':($pct<40?'#f59e0b':'#10b981');
    ?>
    <div class="pharma-card <?php echo $isAlerte&&$m['actif']?'alerte':'';?>" style="<?php echo !$m['actif']?'opacity:.5':'';?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.6rem;margin-bottom:.5rem">
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.865rem;margin-bottom:.25rem"><?php echo htmlspecialchars($m['nom']);?></div>
                <span class="badge <?php echo $CAT_COLORS[$m['categorie']]??'badge-gray';?>"><?php echo $CATS[$m['categorie']]??$m['categorie'];?></span>
                <?php if($m['prescriptible']):?><span class="badge badge-blue" style="margin-left:.3rem">Rx</span><?php endif;?>
            </div>
            <?php if($isAlerte&&$m['actif']):?><span style="color:var(--red-l);font-size:1rem;flex-shrink:0"><i class="fas fa-triangle-exclamation"></i></span><?php endif;?>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--txt-s);margin-bottom:.35rem">
            <span>Stock : <strong class="mono" style="color:<?php echo $isAlerte?'var(--red-l)':'#f9fafb';?>"><?php echo $m['stock_actuel'];?></strong>/<?php echo $m['stock_max'];?> <?php echo htmlspecialchars($m['unite']);?></span>
            <span class="mono">$<?php echo number_format($m['prix_unitaire'],2);?></span>
        </div>
        <div class="stock-bar"><div class="stock-bar-fill" style="width:<?php echo $pct;?>%;background:<?php echo $barColor;?>"></div></div>
        <div style="display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap">
            <button onclick="openMvtModal(<?php echo $m['id'];?>,<?php echo htmlspecialchars(json_encode($m['nom']),ENT_QUOTES);?>,'entree')" class="btn btn-success btn-xs"><i class="fas fa-plus"></i> Entrée</button>
            <button onclick="openMvtModal(<?php echo $m['id'];?>,<?php echo htmlspecialchars(json_encode($m['nom']),ENT_QUOTES);?>,'sortie')" class="btn btn-warning btn-xs"><i class="fas fa-minus"></i> Sortie</button>
            <button onclick='openEditMed(<?php echo htmlspecialchars(json_encode([
                "id"=>(int)$m["id"],"nom"=>$m["nom"],"categorie"=>$m["categorie"],"description"=>$m["description"],
                "stock_actuel"=>(int)$m["stock_actuel"],"stock_min"=>(int)$m["stock_min"],"stock_max"=>(int)$m["stock_max"],
                "unite"=>$m["unite"],"prix"=>(float)$m["prix_unitaire"],"prescriptible"=>(int)$m["prescriptible"],
            ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES);?>)' class="btn btn-secondary btn-xs" title="Modifier"><i class="fas fa-pen"></i></button>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="toggle_actif"><input type="hidden" name="id" value="<?php echo $m['id'];?>"><button type="submit" class="btn btn-ghost btn-xs" title="<?php echo $m['actif']?'Désactiver':'Activer';?>"><?php echo $m['actif']?'<i class="fas fa-eye-slash"></i>':'<i class="fas fa-eye"></i>';?></button></form>
            <button onclick="deleteMed(<?php echo $m['id'];?>,<?php echo htmlspecialchars(json_encode($m['nom']),ENT_QUOTES);?>)" class="btn btn-danger btn-xs" title="Supprimer"><i class="fas fa-trash"></i></button>
        </div>
    </div>
    <?php endforeach;?>
    </div>
    <?php endif;?>
  </div>
  <div>
    <div class="card">
        <div class="card-title"><i class="fas fa-history"></i> Mouvements récents</div>
        <?php if(empty($mouvements)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucun mouvement</div>
        <?php else: foreach($mouvements as $mv):
            $auth=mdt_rp_display_name($mv['auth_fn']??null,$mv['auth_ln']??null,''); if($auth===''||$auth==='—') $auth='—';
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:9px;margin-bottom:.4rem">
            <div style="width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:<?php echo $mv['type']==='entree'?'rgba(16,185,129,.15)':'rgba(220,38,38,.15)';?>;color:<?php echo $mv['type']==='entree'?'#6ee7b7':'var(--red-l)';?>">
                <i class="fas <?php echo $mv['type']==='entree'?'fa-plus':'fa-minus';?>" style="font-size:.75rem"></i>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:.81rem;font-weight:600"><?php echo htmlspecialchars($mv['med_nom']??'—');?></div>
                <div style="font-size:.7rem;color:var(--txt-s)"><?php echo htmlspecialchars(trim(($mv['motif']??'').($mv['motif']?' · ':'').$auth));?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div class="mono" style="font-weight:700;font-size:.85rem;color:<?php echo $mv['type']==='entree'?'#6ee7b7':'var(--red-l)';?>"><?php echo $mv['type']==='entree'?'+':'−';?><?php echo $mv['quantite'];?></div>
                <div style="font-size:.67rem;color:var(--txt-m)"><?php echo date('d/m H:i',strtotime($mv['created_at']));?></div>
            </div>
        </div>
        <?php endforeach;endif;?>
    </div>
  </div>
</div>

<!-- MODAL AJOUT / MODIF MÉDICAMENT -->
<div id="addMedModal" class="modal-ov hidden">
    <div class="modal"><div class="modal-hd"><h2><i class="fas fa-pills"></i> <span id="medModalTitle">Nouvel article</span></h2><button class="modal-close" onclick="Sams.closeModal('addMedModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" id="medAction" value="add_med"><input type="hidden" name="id" id="medId">
        <div class="form-grid">
            <div class="form-group form-full"><label class="form-label required">Nom</label><input type="text" name="nom" id="medNom" class="form-input" required placeholder="Ex: Morphine 10mg"></div>
            <div class="form-group"><label class="form-label required">Catégorie</label><select name="categorie" id="medCat" class="form-select" required><?php foreach($CATS as $k=>$v):?><option value="<?php echo $k;?>"><?php echo $v;?></option><?php endforeach;?></select></div>
            <div class="form-group"><label class="form-label">Unité</label><input type="text" name="unite" id="medUnite" class="form-input" placeholder="ampoule, comprimé..." value="unite"></div>
            <div class="form-group"><label class="form-label">Stock actuel</label><input type="number" name="stock_actuel" id="medStock" class="form-input" value="0" min="0"></div>
            <div class="form-group"><label class="form-label">Seuil alerte</label><input type="number" name="stock_min" id="medMin" class="form-input" value="5" min="0"></div>
            <div class="form-group"><label class="form-label">Stock max</label><input type="number" name="stock_max" id="medMax" class="form-input" value="100" min="1"></div>
            <div class="form-group"><label class="form-label">Prix unitaire ($)</label><input type="number" name="prix" id="medPrix" class="form-input" step="0.01" min="0" value="0"></div>
            <div class="form-group">
                <input type="hidden" name="prescriptible" id="medRxInput" value="1">
                <div class="rx-switch on" id="medRxSwitch" onclick="toggleRx()"><div class="rx-track"></div><div class="lbl">Sur ordonnance</div></div>
            </div>
            <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" id="medDesc" class="form-textarea"></textarea></div>
        </div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('addMedModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
    </form></div>
</div>

<!-- MODAL MOUVEMENT -->
<div id="mvtModal" class="modal-ov hidden">
    <div class="modal modal-sm"><div class="modal-hd"><h2 id="mvtTitle"><i class="fas fa-right-left"></i> Mouvement de stock</h2><button class="modal-close" onclick="Sams.closeModal('mvtModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="mouvement"><input type="hidden" name="med_id" id="mvtMedId"><input type="hidden" name="type_mvt" id="mvtType">
        <p style="margin-bottom:1rem;font-size:.875rem;color:var(--txt-s)">Médicament : <strong id="mvtNom" style="color:#f9fafb"></strong></p>
        <div class="form-group" style="margin-bottom:1rem"><label class="form-label required">Quantité</label><input type="number" name="quantite" class="form-input" min="1" required placeholder="1"></div>
        <div class="form-group"><label class="form-label">Motif</label><input type="text" name="motif" class="form-input" placeholder="Raison du mouvement..."></div>
    </div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Sams.closeModal('mvtModal')">Annuler</button><button type="submit" class="btn btn-primary" id="mvtBtn">Confirmer</button></div>
    </form></div>
</div>

<?php include __DIR__ . '/includes/components.php'; ?>
<script>
function toggleRx(){const s=document.getElementById('medRxSwitch');const on=s.classList.toggle('on');document.getElementById('medRxInput').value=on?'1':'';}
function setRx(on){const s=document.getElementById('medRxSwitch');s.classList.toggle('on',!!on);document.getElementById('medRxInput').value=on?'1':'';}

function openAddMed(){
    document.getElementById('medAction').value='add_med';
    document.getElementById('medModalTitle').textContent='Nouvel article';
    document.getElementById('medId').value='';
    document.getElementById('medNom').value='';document.getElementById('medCat').value='analgesique';
    document.getElementById('medUnite').value='unite';document.getElementById('medStock').value='0';
    document.getElementById('medMin').value='5';document.getElementById('medMax').value='100';
    document.getElementById('medPrix').value='0';document.getElementById('medDesc').value='';setRx(true);
    Sams.openModal('addMedModal');
}
function openEditMed(m){
    document.getElementById('medAction').value='edit_med';
    document.getElementById('medModalTitle').textContent='Modifier : '+m.nom;
    document.getElementById('medId').value=m.id;
    document.getElementById('medNom').value=m.nom;document.getElementById('medCat').value=m.categorie;
    document.getElementById('medUnite').value=m.unite||'unite';document.getElementById('medStock').value=m.stock_actuel;
    document.getElementById('medMin').value=m.stock_min;document.getElementById('medMax').value=m.stock_max;
    document.getElementById('medPrix').value=m.prix;document.getElementById('medDesc').value=m.description||'';setRx(!!m.prescriptible);
    Sams.openModal('addMedModal');
}
function openMvtModal(id,nom,type){
    document.getElementById('mvtMedId').value=id;
    document.getElementById('mvtNom').textContent=nom;
    document.getElementById('mvtType').value=type;
    document.getElementById('mvtTitle').innerHTML='<i class="fas '+(type==='entree'?'fa-plus-circle':'fa-minus-circle')+'"></i> '+(type==='entree'?'Entrée stock':'Sortie stock');
    document.getElementById('mvtBtn').textContent=type==='entree'?'Ajouter au stock':'Retirer du stock';
    Sams.openModal('mvtModal');
}
function deleteMed(id,nom){
    SamsConfirm({title:"Supprimer l'article",target:nom,message:"L'article et son historique de mouvements seront supprimés.",okText:'Supprimer',onConfirm:function(){
        const f=document.createElement('form');f.method='POST';f.style.display='none';
        f.innerHTML='<input type="hidden" name="action" value="delete_med"><input type="hidden" name="id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }});
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>
