<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
require_once __DIR__ . '/lspd_log.php';
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Véhicules'; $db=Database::getInstance()->getConnection();
$uid=(int)($_SESSION['user_id']??0); $msg='';$msgType='';

// ── Migrations silencieuses ─────────────────────────────────────────────────
try { $db->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) DEFAULT NULL"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status ENUM('normal','vole','recherche','suspect','epave','autre') NOT NULL DEFAULT 'normal'"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status_note VARCHAR(200) DEFAULT NULL"); } catch(Exception $e){}

// ── Colonnes existantes ─────────────────────────────────────────────────────
$existingCols = array_column($db->query("SHOW COLUMNS FROM vehicles")->fetchAll(PDO::FETCH_ASSOC),'Field');
$hasImage     = in_array('image_url',  $existingCols);
$hasStatus    = in_array('status',     $existingCols);
$hasNote      = in_array('status_note',$existingCols);

// ── POST ACTIONS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $a = $_POST['action'] ?? '';

    // ─ Ajouter ──────────────────────────────────────────────────────────────
    if ($a === 'add') {
        try {
            $cols = ['model','plate','owner_id','color','created_by'];
            $vals = [trim($_POST['model']), strtoupper(trim($_POST['plate'])), intval($_POST['owner_id'])?:null, trim($_POST['color']??''), $uid];
            if ($hasImage) { $cols[] = 'image_url'; $vals[] = trim($_POST['image_url']??'')?:null; }
            if ($hasStatus) {
                $cols[] = 'status'; $vals[] = $_POST['status']??'normal';
                if ($hasNote) { $cols[] = 'status_note'; $vals[] = trim($_POST['status_note']??'')?:null; }
            }
            $db->prepare("INSERT INTO vehicles (".implode(',',$cols).") VALUES (".implode(',',array_fill(0,count($cols),'?')).")")->execute($vals);
            $msg='Véhicule enregistré.'; $msgType='success';
        lspd_log($db,$uid,'add_vehicle','vehicles',null,strtoupper(trim($_POST['plate']??'')),'Modèle: '.(trim($_POST['model']??'')));
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ─ Modifier ─────────────────────────────────────────────────────────────
    if ($a === 'edit') {
        try {
            $sets = ['model=?','plate=?','owner_id=?','color=?'];
            $vals = [trim($_POST['model']), strtoupper(trim($_POST['plate'])), intval($_POST['owner_id'])?:null, trim($_POST['color']??'')];
            if ($hasImage) { $sets[] = 'image_url=?'; $vals[] = trim($_POST['image_url']??'')?:null; }
            if ($hasStatus) {
                $sets[] = 'status=?'; $vals[] = $_POST['status']??'normal';
                if ($hasNote) { $sets[] = 'status_note=?'; $vals[] = trim($_POST['status_note']??'')?:null; }
            }
            $vals[] = intval($_POST['veh_id']);
            $db->prepare("UPDATE vehicles SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
            $msg='Véhicule mis à jour.'; $msgType='success';
        lspd_log($db,$uid,'edit_vehicle','vehicles',intval($_POST['veh_id']),strtoupper(trim($_POST['plate']??'')));
        } catch(Exception $e){ $msg='Erreur: '.$e->getMessage(); $msgType='error'; }
    }

    // ─ Signaler / désignaler ─────────────────────────────────────────────────
    if ($a === 'flag') {
        $newStatus = $_POST['new_status'] ?? 'vole';
        $note      = trim($_POST['flag_note'] ?? '');
        $vid       = intval($_POST['id']);
        if ($hasStatus) {
            $db->prepare("UPDATE vehicles SET status=?, status_note=? WHERE id=?")->execute([$newStatus, $note?:null, $vid]);
        } else {
            $label = match($newStatus) { 'vole'=>'VOLÉ','recherche'=>'RECHERCHÉ','suspect'=>'SUSPECT','epave'=>'ÉPAVE','autre'=>'SIGNALÉ', default=>'SIGNALÉ' };
            $db->prepare("UPDATE vehicles SET color=CONCAT(COALESCE(NULLIF(TRIM(REPLACE(color,'[SIGNALÉ]','')),''),''),' ['.$label.']') WHERE id=?")->execute([$vid]);
        }
        $msg='Statut mis à jour.'; $msgType='success';
        lspd_log($db,$uid,'flag_vehicle','vehicles',intval($_POST['id']),'Véhicule #'.intval($_POST['id']),'Statut → '.($_POST['new_status']??'').' | '.trim($_POST['flag_note']??''));
    }

    // ─ Désignaler ────────────────────────────────────────────────────────────
    if ($a === 'unflag') {
        $vid = intval($_POST['id']);
        if ($hasStatus) {
            $db->prepare("UPDATE vehicles SET status='normal', status_note=NULL WHERE id=?")->execute([$vid]);
        } else {
            $db->prepare("UPDATE vehicles SET color=TRIM(REGEXP_REPLACE(color,'\\\\[.*?\\\\]','')) WHERE id=?")->execute([$vid]);
        }
        $msg='Véhicule désignalé.'; $msgType='success';
        lspd_log($db,$uid,'unflag_vehicle','vehicles',intval($_POST['id']),'Véhicule #'.intval($_POST['id']));
    }
}

// ── DATA ─────────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']??'');
$fStatus = $_GET['status']??'all';

$selImg = $hasImage  ? ',v.image_url'  : ",''" ;
$selSt  = $hasStatus ? ',v.status,v.status_note' : ",'normal',''";
$q = "SELECT v.*{$selImg}{$selSt},CONCAT(p.firstname,' ',p.lastname) AS owner_name,p.phone
      FROM vehicles v LEFT JOIN profiles p ON p.id=v.owner_id WHERE 1=1";
$params = [];
if ($search) {
    $q .= " AND (v.plate LIKE ? OR v.model LIKE ? OR p.firstname LIKE ? OR p.lastname LIKE ?)";
    $s = "%$search%"; $params = [$s,$s,$s,$s];
}
if ($fStatus !== 'all' && $hasStatus) { $q .= " AND v.status=?"; $params[] = $fStatus; }
elseif ($fStatus === 'flagged' && !$hasStatus) { $q .= " AND v.color LIKE '%[%'"; }
$q .= " ORDER BY v.created_at DESC LIMIT 200";
$stmt = $db->prepare($q); $stmt->execute($params); $vehicles = $stmt->fetchAll();

$total = 0; try { $total=(int)$db->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(); } catch(Exception $e){}
$allOwners = []; try { $allOwners=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname LIMIT 500")->fetchAll(); } catch(Exception $e){}

// Statuts disponibles
$STATUSES = [
    'normal'    => ['label'=>'Normal',     'color'=>'#64748b','badge'=>'bg-gray',   'icon'=>'fa-check'],
    'vole'      => ['label'=>'Volé',       'color'=>'#ef4444','badge'=>'bg-red',    'icon'=>'fa-hand-holding'],
    'recherche' => ['label'=>'Recherché',  'color'=>'#f97316','badge'=>'bg-orange', 'icon'=>'fa-crosshairs'],
    'suspect'   => ['label'=>'Suspect',    'color'=>'#f59e0b','badge'=>'bg-yellow', 'icon'=>'fa-triangle-exclamation'],
    'epave'     => ['label'=>'Épave',      'color'=>'#6b7280','badge'=>'bg-gray',   'icon'=>'fa-car-burst'],
    'autre'     => ['label'=>'Signalé',    'color'=>'#8b5cf6','badge'=>'bg-purple', 'icon'=>'fa-flag'],
];

include __DIR__ . '/includes/header.php';
?>
<style>
.veh-img{width:52px;height:40px;border-radius:7px;object-fit:cover;border:1px solid var(--bdr);cursor:pointer;transition:transform .15s}
.veh-img:hover{transform:scale(1.08)}
.veh-img-placeholder{width:52px;height:40px;border-radius:7px;background:var(--bg-ov);border:1px solid var(--bdr);display:flex;align-items:center;justify-content:center;color:var(--txt-m);font-size:.72rem;flex-shrink:0}
.flag-btn{padding:.22rem .55rem;border-radius:5px;font-size:.68rem;font-weight:700;cursor:pointer;transition:all .15s;border:1px solid;text-transform:uppercase;letter-spacing:.04em}
/* Lightbox */
#imgLightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center;z-index:99999;cursor:zoom-out}
#imgLightbox img{max-width:90vw;max-height:85vh;border-radius:10px;box-shadow:0 0 60px rgba(0,0,0,.8)}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i><?php echo htmlspecialchars($msg);?></div><?php endif;?>

<!-- Toolbar -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.5rem;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" class="form-input" placeholder="Plaque, modèle, propriétaire..." value="<?php echo htmlspecialchars($search);?>" style="flex:1;min-width:200px">
        <select name="status" class="form-select" style="width:150px" onchange="this.form.submit()">
            <option value="all" <?php echo $fStatus==='all'?'selected':'';?>>Tous statuts</option>
            <?php foreach($STATUSES as $k=>$s):?>
            <option value="<?php echo $k;?>" <?php echo $fStatus===$k?'selected':'';?>><?php echo $s['label'];?></option>
            <?php endforeach;?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fStatus!=='all'):?><a href="vehicles.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
    <div style="display:flex;gap:.5rem;align-items:center">
        <span style="font-size:.78rem;color:var(--txt-m)"><?php echo $total;?> véhicule(s)</span>
        <button onclick="LSPD.openModal('newVehModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Enregistrer</button>
    </div>
</div>

<!-- Table -->
<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th style="width:60px"></th><th>Plaque</th><th>Modèle</th><th>Couleur</th><th>Statut</th><th>Propriétaire</th><th>Tél.</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($vehicles)):?>
    <tr><td colspan="9" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun véhicule trouvé</td></tr>
    <?php else:foreach($vehicles as $v):
        $status  = $v['status'] ?? 'normal';
        $stDef   = $STATUSES[$status] ?? $STATUSES['autre'];
        $flagged = $status !== 'normal';
        $imgUrl  = $hasImage ? ($v['image_url']??'') : '';
    ?>
    <tr style="<?php echo $flagged ? 'background:rgba(239,68,68,.03)' : ''; ?>">
        <!-- Image -->
        <td style="padding:.45rem .65rem">
            <?php if($imgUrl):?>
            <img src="<?php echo htmlspecialchars($imgUrl);?>" class="veh-img" onclick="openLightbox(this.src)" onerror="this.style.display='none';this.nextSibling.style.display='flex'">
            <div class="veh-img-placeholder" style="display:none"><i class="fas fa-car"></i></div>
            <?php else:?>
            <div class="veh-img-placeholder"><i class="fas fa-car"></i></div>
            <?php endif;?>
        </td>
        <!-- Plaque -->
        <td><span class="mono" style="font-weight:700;color:var(--blue-xl);font-size:.88rem"><?php echo htmlspecialchars($v['plate']);?></span></td>
        <!-- Modèle -->
        <td style="font-weight:600"><?php echo htmlspecialchars($v['model']);?></td>
        <!-- Couleur -->
        <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars(preg_replace('/\[.*?\]/','',trim($v['color']??'—')));?></td>
        <!-- Statut -->
        <td>
            <span class="badge <?php echo $stDef['badge'];?>" style="font-size:.62rem">
                <i class="fas <?php echo $stDef['icon'];?>" style="font-size:.55rem"></i>
                <?php echo $stDef['label'];?>
            </span>
            <?php if(!empty($v['status_note']??'')):?>
            <div style="font-size:.65rem;color:var(--txt-m);margin-top:.18rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($v['status_note']);?>"><?php echo htmlspecialchars($v['status_note']);?></div>
            <?php endif;?>
        </td>
        <!-- Propriétaire -->
        <td><?php if($v['owner_name']&&trim($v['owner_name'])!=''):?><a href="profils.php?id=<?php echo $v['owner_id'];?>" style="color:var(--blue-xl);text-decoration:none"><?php echo htmlspecialchars($v['owner_name']);?></a><?php else:?><span style="color:var(--txt-m)">Inconnu</span><?php endif;?></td>
        <!-- Téléphone -->
        <td class="mono" style="font-size:.75rem;color:var(--txt-s)"><?php echo htmlspecialchars($v['phone']??'—');?></td>
        <!-- Date -->
        <td style="font-size:.72rem;color:var(--txt-m)"><?php echo date('d/m/Y',strtotime($v['created_at']));?></td>
        <!-- Actions -->
        <td>
            <div style="display:flex;gap:.3rem;align-items:center;flex-wrap:nowrap">
                <!-- Modifier -->
                <button onclick="openEditVeh(<?php echo htmlspecialchars(json_encode(['id'=>$v['id'],'plate'=>$v['plate'],'model'=>$v['model'],'color'=>preg_replace('/\[.*?\]/','',trim($v['color']??'')),'owner_id'=>$v['owner_id']??'','image_url'=>$imgUrl,'status'=>$status,'status_note'=>$v['status_note']??'']),ENT_QUOTES);?>)"
                        class="btn btn-ghost btn-xs" title="Modifier">
                    <i class="fas fa-pen"></i>
                </button>
                <!-- Signaler / Désignaler -->
                <?php if(!$flagged):?>
                <button onclick="openFlagModal(<?php echo $v['id'];?>,'<?php echo addslashes($v['plate']);?>')"
                        class="btn btn-ghost btn-xs" title="Signaler" style="color:#f97316;border-color:rgba(249,115,22,.3)">
                    <i class="fas fa-flag"></i>
                </button>
                <?php else:?>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="action" value="unflag">
                    <input type="hidden" name="id" value="<?php echo $v['id'];?>">
                    <button type="submit" class="btn btn-ghost btn-xs" title="Désignaler" style="color:#6ee7b7;border-color:rgba(16,185,129,.3)">
                        <i class="fas fa-flag-checkered"></i>
                    </button>
                </form>
                <?php endif;?>
                <!-- Profil propriétaire -->
                <?php if($v['owner_id']):?><a href="profils.php?id=<?php echo $v['owner_id'];?>" class="btn btn-ghost btn-xs" title="Profil"><i class="fas fa-user"></i></a><?php endif;?>
            </div>
        </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
</table></div>

<!-- ─── MODAL NOUVEAU VÉHICULE ─────────────────────────────────────────────── -->
<div id="newVehModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-car"></i> Enregistrer un véhicule</h2><button class="modal-cl" onclick="LSPD.closeModal('newVehModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="add">
    <?php echo __vehFormFields($allOwners, $STATUSES, $hasImage, $hasStatus, $hasNote); ?>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('newVehModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button></div>
</form></div></div>

<!-- ─── MODAL MODIFIER VÉHICULE ──────────────────────────────────────────────── -->
<div id="editVehModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier le véhicule</h2><button class="modal-cl" onclick="LSPD.closeModal('editVehModal')">×</button></div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="veh_id" id="ev_id">
    <?php echo __vehFormFields($allOwners, $STATUSES, $hasImage, $hasStatus, $hasNote, 'ev_'); ?>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('editVehModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<!-- ─── MODAL SIGNALEMENT ─────────────────────────────────────────────────────── -->
<div id="flagModal" class="modal-ov hidden">
<div class="modal modal-sm"><div class="modal-hd"><h2><i class="fas fa-flag" style="color:#f97316"></i> Signaler le véhicule</h2><button class="modal-cl" onclick="LSPD.closeModal('flagModal')">×</button></div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="flag">
    <input type="hidden" name="id" id="fl_id">
    <div style="font-size:.88rem;font-weight:700;color:#f9fafb;margin-bottom:.9rem;padding:.5rem .75rem;background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25);border-radius:8px;display:flex;align-items:center;gap:.5rem">
        <i class="fas fa-car" style="color:#fdba74"></i>
        <span id="fl_plate">—</span>
    </div>
    <div class="form-group" style="margin-bottom:.75rem">
        <label class="form-label">Type de signalement *</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <?php foreach($STATUSES as $k=>$s): if($k==='normal') continue; ?>
            <label style="display:flex;align-items:center;gap:.55rem;padding:.55rem .75rem;border:1.5px solid rgba(255,255,255,.08);border-radius:9px;cursor:pointer;transition:all .15s;background:var(--bg-ov)"
                   onclick="this.closest('.form-group').querySelectorAll('label').forEach(l=>l.style.borderColor='rgba(255,255,255,.08)');this.style.borderColor='<?php echo $s['color'];?>88'">
                <input type="radio" name="new_status" value="<?php echo $k;?>" style="accent-color:<?php echo $s['color'];?>;cursor:pointer" <?php echo $k==='vole'?'checked':'';?>>
                <span class="badge <?php echo $s['badge'];?>" style="font-size:.62rem;pointer-events:none">
                    <i class="fas <?php echo $s['icon'];?>" style="font-size:.55rem"></i>
                    <?php echo $s['label'];?>
                </span>
            </label>
            <?php endforeach;?>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">Note (optionnel)</label>
        <input type="text" name="flag_note" class="form-input" placeholder="Ex: Vu quartier Strawberry, plaques cachées...">
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('flagModal')">Annuler</button><button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#f97316,#dc2626)"><i class="fas fa-flag"></i> Signaler</button></div>
</form></div></div>

<!-- Lightbox -->
<div id="imgLightbox" style="display:none" onclick="document.getElementById('imgLightbox').style.display='none'">
    <img id="imgLightboxSrc" src="">
</div>

<?php
// ── Fonction helper : champs communs aux deux modals ───────────────────────
function __vehFormFields(array $owners, array $statuses, bool $hasImage, bool $hasStatus, bool $hasNote, string $pfx=''): string {
    $ownerOpts = '<option value="">— Inconnu —</option>';
    foreach ($owners as $p) {
        $ownerOpts .= '<option value="'.$p['id'].'">'.htmlspecialchars($p['firstname'].' '.$p['lastname']).'</option>';
    }
    $statusOpts = '';
    foreach ($statuses as $k=>$s) {
        $statusOpts .= '<option value="'.$k.'">'.$s['label'].'</option>';
    }
    $imgField = $hasImage ? '
        <div class="form-group form-full">
            <label class="form-label"><i class="fas fa-image" style="color:var(--blue-l)"></i> Photo (URL directe)</label>
            <input type="url" name="image_url" id="'.$pfx.'image_url" class="form-input" placeholder="https://i.imgur.com/abc123.jpg"
                   oninput="previewVehImg(this,\''.$pfx.'\')">
            <div id="'.$pfx.'img_preview" style="margin-top:.45rem;display:none">
                <img id="'.$pfx.'img_thumb" style="height:70px;border-radius:7px;border:1px solid var(--bdr);object-fit:cover" onerror="document.getElementById(\''.$pfx.'img_preview\').style.display=\'none\'">
            </div>
        </div>' : '';
    $statusField = $hasStatus ? '
        <div class="form-group">
            <label class="form-label">Statut</label>
            <select name="status" id="'.$pfx.'status" class="form-select">'.$statusOpts.'</select>
        </div>' : '';
    $noteField = ($hasStatus && $hasNote) ? '
        <div class="form-group">
            <label class="form-label">Note de statut</label>
            <input type="text" name="status_note" id="'.$pfx.'status_note" class="form-input" placeholder="Ex: Volé rue Davis...">
        </div>' : '';
    return '
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Plaque *</label><input type="text" name="plate" id="'.$pfx.'plate" class="form-input" required placeholder="ABC123" style="text-transform:uppercase"></div>
        <div class="form-group"><label class="form-label">Modèle *</label><input type="text" name="model" id="'.$pfx.'model" class="form-input" required placeholder="Sultan, Insurgent..."></div>
        <div class="form-group"><label class="form-label">Couleur</label><input type="text" name="color" id="'.$pfx.'color" class="form-input" placeholder="Noir, Blanc..."></div>
        <div class="form-group"><label class="form-label">Propriétaire</label>
            <select name="owner_id" id="'.$pfx.'owner_id" class="form-select">'.$ownerOpts.'</select>
        </div>
        '.$statusField.$noteField.'
    </div>'.$imgField;
}
?>

<script>
function openLightbox(src) {
    document.getElementById('imgLightboxSrc').src = src;
    document.getElementById('imgLightbox').style.display = 'flex';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') document.getElementById('imgLightbox').style.display='none'; });

function previewVehImg(inp, pfx) {
    const prev  = document.getElementById(pfx+'img_preview');
    const thumb = document.getElementById(pfx+'img_thumb');
    if (!prev || !thumb) return;
    const val = inp.value.trim();
    if (val) { thumb.src = val; prev.style.display = 'block'; }
    else { prev.style.display = 'none'; }
}

function openEditVeh(data) {
    document.getElementById('ev_id').value          = data.id;
    document.getElementById('ev_plate').value       = data.plate   || '';
    document.getElementById('ev_model').value       = data.model   || '';
    document.getElementById('ev_color').value       = data.color   || '';
    const ownerSel = document.getElementById('ev_owner_id');
    if (ownerSel) ownerSel.value = data.owner_id || '';
    const imgInp = document.getElementById('ev_image_url');
    if (imgInp) { imgInp.value = data.image_url || ''; previewVehImg(imgInp,'ev_'); }
    const stSel = document.getElementById('ev_status');
    if (stSel) stSel.value = data.status || 'normal';
    const noteInp = document.getElementById('ev_status_note');
    if (noteInp) noteInp.value = data.status_note || '';
    LSPD.openModal('editVehModal');
}

function openFlagModal(id, plate) {
    document.getElementById('fl_id').value = id;
    document.getElementById('fl_plate').textContent = plate;
    LSPD.openModal('flagModal');
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>