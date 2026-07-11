<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Demandes PPA';
$db = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id']??0);
$msg = ''; $msgType = '';

// ── Auto-création table ───────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS `lspd_ppa_demandes` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `citizen_id`       INT UNSIGNED NOT NULL,
  `officer_id`       INT UNSIGNED,
  `statut`           ENUM('demande','en_cours','valide','refuse') NOT NULL DEFAULT 'demande',
  `type_arme`        VARCHAR(100),
  `motif_demande`    TEXT,
  `motif_refus`      TEXT,
  `date_demande`     DATE NOT NULL,
  `date_traitement`  DATE,
  `notes_officier`   TEXT,
  `created_by`       INT UNSIGNED,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_citizen` (`citizen_id`),
  KEY `idx_statut`  (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $cid = intval($_POST['citizen_id']);
            if (!$cid) throw new Exception('Sélectionnez un citoyen.');
            $db->prepare("INSERT INTO lspd_ppa_demandes (citizen_id,type_arme,motif_demande,date_demande,statut,created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$cid, trim($_POST['type_arme']??''), trim($_POST['motif_demande']??''), $_POST['date_demande']??date('Y-m-d'), 'demande', $uid]);
            $msg = 'Demande PPA enregistrée avec succès.'; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'update_statut') {
        try {
            $id  = intval($_POST['id']);
            $new = $_POST['statut'];
            if (!in_array($new, ['demande','en_cours','valide','refuse'])) throw new Exception('Statut invalide.');
            $sets = ['statut=?','officer_id=?','notes_officier=?'];
            $vals = [$new, $uid, trim($_POST['notes_officier']??'')];
            if ($new === 'refuse')                     { $sets[] = 'motif_refus=?';    $vals[] = trim($_POST['motif_refus']??''); }
            if (in_array($new, ['valide','refuse']))   { $sets[] = 'date_traitement=?'; $vals[] = date('Y-m-d'); }
            $vals[] = $id;
            $db->prepare("UPDATE lspd_ppa_demandes SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
            // Sync license_ppa sur le profil citoyen
            $row = $db->prepare("SELECT citizen_id FROM lspd_ppa_demandes WHERE id=?"); $row->execute([$id]); $r=$row->fetch();
            if ($r) {
                if ($new === 'valide') try{$db->prepare("UPDATE profiles SET license_ppa=1 WHERE id=?")->execute([$r['citizen_id']]);}catch(Exception $e){}
                if ($new === 'refuse') try{$db->prepare("UPDATE profiles SET license_ppa=0 WHERE id=?")->execute([$r['citizen_id']]);}catch(Exception $e){}
            }
            $msg = 'Statut mis à jour.'; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM lspd_ppa_demandes WHERE id=?")->execute([intval($_POST['id'])]);
        $msg = 'Demande supprimée.'; $msgType = 'success';
    }
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$filterStatut = $_GET['s'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$cFilter      = intval($_GET['cid'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where = ['1=1']; $params = [];
if ($filterStatut !== 'all') { $where[] = 'd.statut=?'; $params[] = $filterStatut; }
if ($search)  { $where[] = "(p.firstname LIKE ? OR p.lastname LIKE ? OR d.type_arme LIKE ?)"; $pa="%$search%"; $params=array_merge($params,[$pa,$pa,$pa]); }
if ($cFilter) { $where[] = 'd.citizen_id=?'; $params[] = $cFilter; }
$whereStr = implode(' AND ', $where);

$totalRows = 0;
try { $cs=$db->prepare("SELECT COUNT(*) FROM lspd_ppa_demandes d LEFT JOIN profiles p ON p.id=d.citizen_id WHERE $whereStr"); $cs->execute($params); $totalRows=(int)$cs->fetchColumn(); } catch(Exception $e){}
$totalPages = max(1,(int)ceil($totalRows/$perPage));
$offset = ($page-1)*$perPage;

$demandes = [];
try {
    $stmt = $db->prepare("
        SELECT d.*, p.firstname, p.lastname, p.phone, p.avatar, p.license_ppa,
               CONCAT(p.firstname,' ',p.lastname) AS citizen_name,
               u.username AS officer_name
        FROM lspd_ppa_demandes d
        LEFT JOIN profiles p ON p.id=d.citizen_id
        LEFT JOIN users u    ON u.id=d.officer_id
        WHERE $whereStr
        ORDER BY FIELD(d.statut,'en_cours','demande','valide','refuse'), d.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params); $demandes = $stmt->fetchAll();
} catch(Exception $e){}

$citizens = []; try { $citizens=$db->query("SELECT id,firstname,lastname FROM profiles ORDER BY lastname,firstname LIMIT 500")->fetchAll(); } catch(Exception $e){}

// KPIs globaux
$kpis = ['demande'=>0,'en_cours'=>0,'valide'=>0,'refuse'=>0];
try { foreach($db->query("SELECT statut,COUNT(*) n FROM lspd_ppa_demandes GROUP BY statut")->fetchAll() as $r) $kpis[$r['statut']]=(int)$r['n']; } catch(Exception $e){}

$preselCitizen = null;
if ($cFilter) { try { $s=$db->prepare("SELECT * FROM profiles WHERE id=?"); $s->execute([$cFilter]); $preselCitizen=$s->fetch(); } catch(Exception $e){} }

$TYPES_ARME = ['Pistolet','Revolver','Fusil à pompe','Fusil d\'assaut','Carabine','Pistolet mitrailleur','Arme de défense','Autre'];

$STATUT_CFG = [
    'demande'  => ['Demande',  'bg-yellow', 'fas fa-clock',         '#f59e0b','#fde68a','rgba(245,158,11,.08)','rgba(245,158,11,.25)'],
    'en_cours' => ['En cours', 'bg-blue',   'fas fa-hourglass-half','#3b82f6','#93c5fd','rgba(37,99,235,.08)', 'rgba(37,99,235,.25)'],
    'valide'   => ['Validé',   'bg-green',  'fas fa-check-circle',  '#10b981','#6ee7b7','rgba(16,185,129,.08)','rgba(16,185,129,.25)'],
    'refuse'   => ['Refusé',   'bg-red',    'fas fa-times-circle',  '#ef4444','#fca5a5','rgba(239,68,68,.08)', 'rgba(239,68,68,.25)'],
];

include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ PPA Styles ══════════════════════════════════════════════════════ */
.ppa-row {
    display:grid;
    grid-template-columns: 44px 1fr 130px 120px 120px 110px;
    align-items:center; gap:0;
    padding:.75rem 1.1rem;
    border-bottom:1px solid rgba(37,99,235,.06);
    transition:background .12s; font-size:.82rem;
    cursor:default;
}
.ppa-row:hover { background:rgba(37,99,235,.04); }
.ppa-row:last-child { border-bottom:none; }
.ppa-row-head {
    font-family:'IBM Plex Mono',monospace;
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
    color:var(--txt-s);
    background:linear-gradient(90deg,rgba(37,99,235,.06),transparent);
    border-bottom:2px solid rgba(37,99,235,.15);
    padding:.65rem 1.1rem;
}

/* Citizen avatar */
.ppa-av {
    width:36px; height:36px; border-radius:9px;
    overflow:hidden; display:flex; align-items:center; justify-content:center;
    font-family:'Syne',serif; font-weight:800; font-size:.9rem;
    flex-shrink:0;
}
.ppa-av img { width:100%; height:100%; object-fit:cover; }

/* Statut selector buttons */
.statut-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; }
.statut-opt  {
    text-align:center; padding:.7rem .4rem; border-radius:9px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.03);
    cursor:pointer; transition:all .17s;
    opacity:.5;
}
.statut-opt.active   { opacity:1; transform:scale(1.04); box-shadow:0 0 18px rgba(0,0,0,.4); }
.statut-opt i        { display:block; font-size:1.1rem; margin-bottom:.3rem; }
.statut-opt .s-label { font-size:.7rem; font-weight:700; }

/* Quick action button in row */
.ppa-action-btn {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.28rem .6rem; border-radius:5px; font-size:.7rem; font-weight:600;
    cursor:pointer; border:none; transition:all .15s; text-decoration:none;
}

/* Filter tabs */
.filter-tabs { display:flex; background:rgba(0,0,0,.3); border:1px solid var(--bdr); border-radius:9px; overflow:hidden; }
.filter-tab  {
    padding:.38rem .85rem; font-size:.76rem; font-weight:600; cursor:pointer;
    text-decoration:none; transition:all .15s; white-space:nowrap;
    color:var(--txt-s); border-right:1px solid var(--bdr);
}
.filter-tab:last-child { border-right:none; }
.filter-tab.active { background:rgba(37,99,235,.18); color:var(--blue-xl); }
.filter-tab .tab-count { font-family:'IBM Plex Mono',monospace; font-size:.65rem; opacity:.7; margin-left:.25rem; }
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?></div><?php endif;?>

<!-- Retour profil citoyen si filtré -->
<?php if($cFilter&&$preselCitizen):?>
<div style="margin-bottom:1.1rem">
    <a href="profils.php?id=<?php echo $cFilter;?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Profil — <?php echo htmlspecialchars($preselCitizen['firstname'].' '.$preselCitizen['lastname']);?></a>
</div>
<?php endif;?>

<!-- ── KPIs ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.4rem">
    <?php foreach($STATUT_CFG as $k=>[$l,$badge,$ico,$col,$lc,$bg,$bdr]):?>
    <a href="?s=<?php echo $k;?>&q=<?php echo urlencode($search);?>" style="text-decoration:none">
    <div style="background:<?php echo $bg;?>;border:1px solid <?php echo $bdr;?>;border-radius:12px;padding:1rem 1.1rem;transition:all .2s;<?php echo $filterStatut===$k?'border-width:2px;transform:translateY(-1px)':'';?>">
        <div style="display:flex;align-items:center;gap:.75rem">
            <div style="width:36px;height:36px;border-radius:9px;background:<?php echo $bg;?>;border:1px solid <?php echo $bdr;?>;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:<?php echo $lc;?>"><i class="<?php echo $ico;?>"></i></div>
            <div>
                <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.45)"><?php echo $l;?></div>
                <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:<?php echo $lc;?>;line-height:1"><?php echo $kpis[$k]??0;?></div>
            </div>
        </div>
    </div>
    </a>
    <?php endforeach;?>
</div>

<!-- ── TOOLBAR ── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap">
        <!-- Onglets filtres -->
        <div class="filter-tabs">
            <a href="?s=all&q=<?php echo urlencode($search);?>" class="filter-tab <?php echo $filterStatut==='all'?'active':'';?>">Tous<span class="tab-count">(<?php echo array_sum($kpis);?>)</span></a>
            <?php foreach($STATUT_CFG as $k=>[$l]):?>
            <a href="?s=<?php echo $k;?>&q=<?php echo urlencode($search);?>" class="filter-tab <?php echo $filterStatut===$k?'active':'';?>"><?php echo $l;?><span class="tab-count">(<?php echo $kpis[$k]??0;?>)</span></a>
            <?php endforeach;?>
        </div>
        <!-- Search -->
        <form method="GET" style="display:flex;align-items:center;gap:.4rem">
            <input type="hidden" name="s" value="<?php echo $filterStatut;?>">
            <?php if($cFilter):?><input type="hidden" name="cid" value="<?php echo $cFilter;?>"><?php endif;?>
            <div style="position:relative">
                <i class="fas fa-search" style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.72rem;pointer-events:none"></i>
                <input type="text" name="q" class="form-input" placeholder="Chercher par citoyen ou type d'arme…" value="<?php echo htmlspecialchars($search);?>" style="padding-left:2rem;width:250px;font-size:.81rem">
            </div>
            <?php if($search):?><a href="?s=<?php echo $filterStatut;?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
        </form>
    </div>
    <button onclick="LSPD.openModal('createPPAModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle demande</button>
</div>

<!-- ── TABLE ── -->
<div style="background:rgba(6,13,26,.72);border:1px solid rgba(37,99,235,.15);border-radius:13px;overflow:hidden;margin-bottom:1.3rem">

    <!-- Header -->
    <div class="ppa-row ppa-row-head">
        <div></div>
        <div>Citoyen</div>
        <div>Type d'arme</div>
        <div>Date</div>
        <div>Officier</div>
        <div>Statut / Actions</div>
    </div>

    <?php if(empty($demandes)):?>
    <div style="text-align:center;padding:4rem 2rem;color:var(--txt-s)">
        <i class="fas fa-gun" style="font-size:2.5rem;opacity:.12;display:block;margin-bottom:.9rem"></i>
        <div style="font-weight:600;margin-bottom:.3rem">Aucune demande PPA</div>
        <div style="font-size:.78rem">Les demandes apparaîtront ici</div>
    </div>
    <?php else: foreach($demandes as $d):
        [$sl,$sbadge,$sico,$scol,$slc,$sbg,$sbdr] = $STATUT_CFG[$d['statut']];
        $pInit  = strtoupper(substr($d['firstname']??'?',0,1));
        $gradMap = ['A'=>'#2563eb','B'=>'#10b981','C'=>'#8b5cf6','D'=>'#f59e0b','E'=>'#ec4899','F'=>'#06b6d4'];
        $pColor = $gradMap[$pInit]??'#475569';
    ?>
    <div class="ppa-row" style="border-left:3px solid <?php echo $scol;?>22">
        <!-- Avatar -->
        <div>
            <div class="ppa-av" style="background:<?php echo $pColor;?>22;border:1.5px solid <?php echo $pColor;?>44;color:<?php echo $pColor;?>">
                <?php if(!empty($d['avatar'])):?><img src="<?php echo htmlspecialchars(preg_match('/^https?:\/\//',$d['avatar'])?$d['avatar']:SITE_URL.$d['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit"><?php else:?><?php echo $pInit;?><?php endif;?>
            </div>
        </div>

        <!-- Citoyen + détails -->
        <div>
            <a href="profils.php?id=<?php echo $d['citizen_id'];?>" style="font-weight:700;font-size:.85rem;color:var(--txt);text-decoration:none;display:block"><?php echo htmlspecialchars($d['citizen_name']);?></a>
            <div style="font-size:.7rem;color:var(--txt-m);margin-top:.12rem">
                <span class="mono">#<?php echo str_pad($d['id'],5,'0',STR_PAD_LEFT);?></span>
                <?php if($d['phone']??''):?> · <?php echo htmlspecialchars($d['phone']);?><?php endif;?>
            </div>
            <?php if(!empty($d['motif_demande'])):?>
            <div style="font-size:.71rem;color:var(--txt-s);margin-top:.22rem;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($d['motif_demande']);?>">
                <i class="fas fa-quote-left" style="font-size:.55rem;opacity:.5;margin-right:.25rem"></i><?php echo htmlspecialchars($d['motif_demande']);?>
            </div>
            <?php endif;?>
            <?php if($d['statut']==='refuse'&&!empty($d['motif_refus'])):?>
            <div style="margin-top:.25rem;font-size:.7rem;color:#fca5a5;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);border-radius:5px;padding:.2rem .5rem;display:inline-block">
                <i class="fas fa-times-circle" style="font-size:.6rem;margin-right:.22rem"></i><?php echo htmlspecialchars(mb_substr($d['motif_refus'],0,60));?><?php if(mb_strlen($d['motif_refus'])>60) echo '…';?>
            </div>
            <?php endif;?>
        </div>

        <!-- Type d'arme -->
        <div>
            <?php if(!empty($d['type_arme'])):?>
            <div style="display:inline-flex;align-items:center;gap:.35rem;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);border-radius:6px;padding:.28rem .6rem;font-size:.73rem;font-weight:600;color:var(--blue-xl)">
                <i class="fas fa-gun" style="font-size:.65rem;opacity:.7"></i><?php echo htmlspecialchars($d['type_arme']);?>
            </div>
            <?php else:?><span style="color:var(--txt-m);font-size:.75rem">—</span><?php endif;?>
        </div>

        <!-- Date -->
        <div>
            <div style="font-size:.78rem"><?php echo date('d/m/Y',strtotime($d['date_demande']));?></div>
            <?php if($d['date_traitement']):?>
            <div style="font-size:.68rem;color:var(--txt-m);margin-top:.1rem"><i class="fas fa-check" style="color:#6ee7b7;font-size:.6rem;margin-right:.2rem"></i><?php echo date('d/m/Y',strtotime($d['date_traitement']));?></div>
            <?php endif;?>
        </div>

        <!-- Officier -->
        <div>
            <?php if($d['officer_name']):?>
            <div style="font-size:.78rem;font-weight:600"><?php echo htmlspecialchars($d['officer_name']);?></div>
            <?php if(!empty($d['notes_officier'])):?>
            <div style="font-size:.67rem;color:var(--txt-m);margin-top:.1rem;font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px" title="<?php echo htmlspecialchars($d['notes_officier']);?>"><?php echo htmlspecialchars($d['notes_officier']);?></div>
            <?php endif;?>
            <?php else:?><span style="font-size:.75rem;color:var(--txt-m)">Non traité</span><?php endif;?>
        </div>

        <!-- Statut + Actions -->
        <div style="display:flex;flex-direction:column;gap:.3rem;align-items:flex-start">
            <span class="badge <?php echo $sbadge;?>" style="font-size:.62rem"><i class="<?php echo $sico;?>" style="font-size:.55rem"></i> <?php echo $sl;?></span>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                <button onclick="openTraitModal(<?php echo $d['id'];?>,'<?php echo $d['statut'];?>','<?php echo addslashes($d['notes_officier']??'');?>','<?php echo addslashes($d['motif_refus']??'');?>')"
                        class="ppa-action-btn" style="background:rgba(37,99,235,.12);color:var(--blue-xl);border:1px solid rgba(37,99,235,.22)">
                    <i class="fas fa-pen-to-square" style="font-size:.65rem"></i> Traiter
                </button>
                <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Supprimer cette demande ?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $d['id'];?>">
                    <button type="submit" class="ppa-action-btn" style="background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.22)"><i class="fas fa-trash" style="font-size:.62rem"></i></button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; endif;?>
</div>

<!-- Pagination -->
<?php if($totalPages > 1):?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:.65rem 0;font-size:.78rem">
    <span style="color:var(--txt-m)"><?php echo $totalRows;?> demande(s) · Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <div style="display:flex;gap:.35rem">
        <?php if($page>1):?><a href="?s=<?php echo $filterStatut;?>&q=<?php echo urlencode($search);?>&page=<?php echo $page-1;?>" class="btn btn-ghost btn-xs">← Préc.</a><?php endif;?>
        <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++):?>
        <a href="?s=<?php echo $filterStatut;?>&q=<?php echo urlencode($search);?>&page=<?php echo $i;?>" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid <?php echo $i===$page?'var(--blue)':'var(--bdr)';?>;background:<?php echo $i===$page?'rgba(37,99,235,.15)':'transparent';?>;color:<?php echo $i===$page?'var(--blue-xl)':'var(--txt-s)';?>;text-decoration:none;font-size:.78rem"><?php echo $i;?></a>
        <?php endfor;?>
        <?php if($page<$totalPages):?><a href="?s=<?php echo $filterStatut;?>&q=<?php echo urlencode($search);?>&page=<?php echo $page+1;?>" class="btn btn-ghost btn-xs">Suiv. →</a><?php endif;?>
    </div>
</div>
<?php endif;?>

<!-- ══ MODAL CRÉATION ══════════════════════════════════════════════════════ -->
<div id="createPPAModal" class="modal-ov hidden">
<div class="modal modal-lg">
<div class="modal-hd" style="background:rgba(37,99,235,.06);border-bottom:1px solid rgba(37,99,235,.2)">
    <h2><i class="fas fa-gun" style="color:var(--blue-l)"></i> Nouvelle demande de PPA</h2>
    <button class="modal-cl" onclick="LSPD.closeModal('createPPAModal')">×</button>
</div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create">
    <div class="form-grid">
        <div class="form-group form-full">
            <label class="form-label">Citoyen demandeur *</label>
            <select name="citizen_id" class="form-select" required>
                <option value="">Sélectionner un citoyen…</option>
                <?php foreach($citizens as $c): $sel=($cFilter===$c['id'])?'selected':'';?>
                <option value="<?php echo $c['id'];?>" <?php echo $sel;?>><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option>
                <?php endforeach;?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Type d'arme demandée *</label>
            <select name="type_arme" class="form-select" required>
                <option value="">Sélectionner…</option>
                <?php foreach($TYPES_ARME as $t):?><option value="<?php echo $t;?>"><?php echo $t;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Date de la demande *</label>
            <input type="date" name="date_demande" class="form-input" value="<?php echo date('Y-m-d');?>" required>
        </div>
        <div class="form-group form-full">
            <label class="form-label">Motif / Justification *</label>
            <textarea name="motif_demande" class="form-textarea" rows="4" required placeholder="Raison de la demande : emploi, auto-défense, activité professionnelle, utilité sportive…"></textarea>
        </div>
    </div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('createPPAModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Enregistrer la demande</button>
</div>
</form></div></div>

<!-- ══ MODAL TRAITEMENT ════════════════════════════════════════════════════ -->
<div id="traitPPAModal" class="modal-ov hidden">
<div class="modal">
<div class="modal-hd" style="background:rgba(37,99,235,.06);border-bottom:1px solid rgba(37,99,235,.2)">
    <h2><i class="fas fa-pen-to-square" style="color:var(--blue-l)"></i> Traiter la demande PPA</h2>
    <button class="modal-cl" onclick="LSPD.closeModal('traitPPAModal')">×</button>
</div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="update_statut">
    <input type="hidden" name="id" id="traitPPAId">

    <!-- Sélecteur de statut visuel -->
    <div style="margin-bottom:1.25rem">
        <div style="font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:.7rem">Nouveau statut *</div>
        <div class="statut-grid">
            <?php foreach($STATUT_CFG as $k=>[$l,$badge,$ico,$col,$lc,$bg,$bdr]):?>
            <label style="cursor:pointer">
                <input type="radio" name="statut" value="<?php echo $k;?>" style="display:none" onchange="onStatutChange()">
                <div class="statut-opt" data-k="<?php echo $k;?>" style="border-color:<?php echo $bdr;?>;background:<?php echo $bg;?>">
                    <i class="<?php echo $ico;?>" style="color:<?php echo $lc;?>"></i>
                    <div class="s-label" style="color:<?php echo $lc;?>"><?php echo $l;?></div>
                </div>
            </label>
            <?php endforeach;?>
        </div>
    </div>

    <!-- Motif refus (conditionnel) -->
    <div id="refusSection" style="display:none;margin-bottom:1rem">
        <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:9px;padding:.85rem .95rem">
            <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#fca5a5;margin-bottom:.5rem"><i class="fas fa-times-circle" style="margin-right:.3rem"></i>Motif du refus *</div>
            <textarea name="motif_refus" id="traitRefusTxt" class="form-textarea" rows="3" style="border-color:rgba(239,68,68,.25)" placeholder="Expliquez la raison du refus…"></textarea>
        </div>
    </div>

    <!-- Notes officier -->
    <div class="form-group">
        <label class="form-label"><i class="fas fa-sticky-note"></i> Notes internes de l'officier</label>
        <textarea name="notes_officier" id="traitNotes" class="form-textarea" rows="2" placeholder="Observations, remarques à conserver…"></textarea>
    </div>

    <!-- Résumé de l'action -->
    <div id="traitSummary" style="display:none;margin-top:.75rem;padding:.6rem .85rem;border-radius:8px;font-size:.78rem;font-weight:600"></div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSPD.closeModal('traitPPAModal')">Annuler</button>
    <button type="submit" id="traitSubmitBtn" class="btn btn-primary" disabled><i class="fas fa-save"></i> Enregistrer</button>
</div>
</form></div></div>

<script>
// ── Ouvrir modal traitement ────────────────────────────────────────────────
function openTraitModal(id, statut, notes, motifRefus) {
    document.getElementById('traitPPAId').value = id;
    document.getElementById('traitNotes').value = notes || '';
    document.getElementById('traitRefusTxt').value = motifRefus || '';
    // Reset opts
    document.querySelectorAll('.statut-opt').forEach(o => { o.classList.remove('active'); });
    document.querySelectorAll('input[name="statut"]').forEach(r => r.checked = false);
    // Pré-sélectionner statut actuel
    const inp = document.querySelector('input[name="statut"][value="'+statut+'"]');
    if (inp) { inp.checked=true; inp.nextElementSibling.classList.add('active'); }
    document.getElementById('refusSection').style.display = statut === 'refuse' ? 'block' : 'none';
    updateTraitSummary(statut);
    document.getElementById('traitSubmitBtn').disabled = false;
    LSPD.openModal('traitPPAModal');
}

function onStatutChange() {
    const sel = document.querySelector('input[name="statut"]:checked');
    document.querySelectorAll('.statut-opt').forEach(o => o.classList.remove('active'));
    if (sel) {
        sel.nextElementSibling.classList.add('active');
        document.getElementById('refusSection').style.display = sel.value === 'refuse' ? 'block' : 'none';
        updateTraitSummary(sel.value);
        document.getElementById('traitSubmitBtn').disabled = false;
    }
}

const STATUT_LABELS = {
    'demande':  { text:'La demande passe au statut En attente',    color:'rgba(245,158,11,.12)',  border:'rgba(245,158,11,.3)',   icon:'fas fa-clock',         c:'#fde68a' },
    'en_cours': { text:'La demande passe En cours d\'instruction', color:'rgba(37,99,235,.1)',     border:'rgba(37,99,235,.3)',    icon:'fas fa-hourglass-half', c:'#93c5fd' },
    'valide':   { text:'✓ Le PPA sera accordé au citoyen',         color:'rgba(16,185,129,.1)',    border:'rgba(16,185,129,.3)',   icon:'fas fa-check-circle',  c:'#6ee7b7' },
    'refuse':   { text:'✗ Le PPA sera refusé',                     color:'rgba(239,68,68,.08)',    border:'rgba(239,68,68,.25)',   icon:'fas fa-times-circle',  c:'#fca5a5' },
};
function updateTraitSummary(k) {
    const s=document.getElementById('traitSummary');
    const cfg=STATUT_LABELS[k];
    if (!cfg) { s.style.display='none'; return; }
    s.style.display='block';
    s.style.background=cfg.color;
    s.style.border='1px solid '+cfg.border;
    s.style.color=cfg.c;
    s.innerHTML='<i class="'+cfg.icon+'" style="margin-right:.4rem"></i>'+cfg.text;
}

document.querySelectorAll('input[name="statut"]').forEach(r => r.addEventListener('change', onStatutChange));
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>