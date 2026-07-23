<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Recensement Armes Civiles';
$db  = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id']??0);
$msg = ''; $msgType = '';

require_once __DIR__ . '/../../includes/lssd_cdn.php';
require_once __DIR__ . '/../../includes/matricule_helper.php';

// ── AJAX champ image (upload / liste CDN) ─────────────────────────────────────
$__imgAction = $_POST['img_action'] ?? $_GET['img_action'] ?? '';
if ($__imgAction !== '') {
    header('Content-Type: application/json');
    if ($__imgAction === 'upload') { echo json_encode(lssd_cdn_store_file_raw($_FILES['file'] ?? [])); exit; }
    if ($__imgAction === 'cdn_list') {
        $out = array_map(fn($r) => ['url' => $r['public_url'], 'title' => $r['title']], lssd_cdn_all($db));
        echo json_encode(['success' => true, 'images' => $out]); exit;
    }
    echo json_encode(['success' => false, 'error' => 'Action inconnue']); exit;
}

// ── Auto-création table ───────────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS `lssd_armes_civils` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `citizen_id`          INT UNSIGNED NOT NULL,
  `type_arme`           VARCHAR(100) NOT NULL,
  `modele`              VARCHAR(200),
  `numero_serie`        VARCHAR(100),
  `calibre`             VARCHAR(50),
  `date_enregistrement` DATE NOT NULL,
  `date_expiration`     DATE,
  `statut`              ENUM('enregistree','suspendue','revoquee') NOT NULL DEFAULT 'enregistree',
  `officer_id`          INT UNSIGNED,
  `ppa_id`              INT UNSIGNED,
  `notes`               TEXT,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_citizen` (`citizen_id`),
  KEY `idx_statut`  (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}
// Colonne images (JSON array d'URLs) — pour installations existantes
try { $db->exec("ALTER TABLE lssd_armes_civils ADD COLUMN IF NOT EXISTS images TEXT NULL"); } catch(Exception $e){}

// ── ACTIONS ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        try {
            $cid = intval($_POST['citizen_id']);
            if (!$cid) throw new Exception('Sélectionnez un citoyen.');
            $hasPPA = false;
            try {
                $s = $db->prepare("SELECT COUNT(*) FROM profiles WHERE id=? AND license_ppa=1");
                $s->execute([$cid]);
                $hasPPA = (int)$s->fetchColumn() > 0;
            } catch(Exception $e){}
            if (!$hasPPA) throw new Exception("Ce citoyen ne dispose pas d'un PPA valide.");

            $numSerie = trim($_POST['numero_serie']??'');
            if (empty($numSerie)) $numSerie = 'LS-'.strtoupper(substr(md5(uniqid()),0,8));

            $imagesJson = $_POST['images'] ?? '';
            $imagesArr  = json_decode($imagesJson, true);
            $imagesVal  = (is_array($imagesArr) && $imagesArr) ? json_encode(array_values($imagesArr)) : null;
            $db->prepare("INSERT INTO lssd_armes_civils
                (citizen_id,type_arme,modele,numero_serie,calibre,date_enregistrement,date_expiration,statut,officer_id,ppa_id,notes,images)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $cid, $_POST['type_arme'], trim($_POST['modele']??''), $numSerie,
                   trim($_POST['calibre']??''), $_POST['date_enregistrement']??date('Y-m-d'),
                   $_POST['date_expiration']?:null, 'enregistree', $uid,
                   $_POST['ppa_id']?:null, trim($_POST['notes']??''), $imagesVal
               ]);
            $msg = "Arme enregistrée — N° série : <strong>$numSerie</strong>"; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'update_statut') {
        try {
            $id     = intval($_POST['id']);
            $statut = $_POST['statut'];
            if (!in_array($statut, ['enregistree','suspendue','revoquee'])) throw new Exception('Statut invalide.');
            $db->prepare("UPDATE lssd_armes_civils SET statut=?,officer_id=?,notes=?,updated_at=NOW() WHERE id=?")
               ->execute([$statut, $uid, trim($_POST['notes']??''), $id]);
            if ($statut === 'revoquee') {
                $r = $db->prepare("SELECT citizen_id FROM lssd_armes_civils WHERE id=?"); $r->execute([$id]); $r=$r->fetch();
                if ($r) try{$db->prepare("UPDATE profiles SET license_ppa=0 WHERE id=?")->execute([$r['citizen_id']]);}catch(Exception $e){}
            }
            $msg = 'Statut mis à jour.'; $msgType = 'success';
        } catch(Exception $e) { $msg = 'Erreur : '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM lssd_armes_civils WHERE id=?")->execute([intval($_POST['id'])]);
        $msg = 'Arme supprimée du recensement.'; $msgType = 'success';
    }
}

// ── Filtres & pagination ──────────────────────────────────────────────────────
$filterStatut = $_GET['s']    ?? 'all';
$filterType   = $_GET['t']    ?? 'all';
$search       = trim($_GET['q'] ?? '');
$cFilter      = intval($_GET['cid'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$where = ['1=1']; $params = [];
if ($filterStatut !== 'all') { $where[] = 'a.statut=?';    $params[] = $filterStatut; }
if ($filterType   !== 'all') { $where[] = 'a.type_arme=?'; $params[] = $filterType; }
if ($search) {
    $where[]  = "(p.firstname LIKE ? OR p.lastname LIKE ? OR a.numero_serie LIKE ? OR a.modele LIKE ?)";
    $pa       = "%$search%";
    $params   = array_merge($params, [$pa,$pa,$pa,$pa]);
}
if ($cFilter) { $where[] = 'a.citizen_id=?'; $params[] = $cFilter; }
$whereStr = implode(' AND ', $where);

$totalRows = 0;
try {
    $cs = $db->prepare("SELECT COUNT(*) FROM lssd_armes_civils a LEFT JOIN profiles p ON p.id=a.citizen_id WHERE $whereStr");
    $cs->execute($params); $totalRows = (int)$cs->fetchColumn();
} catch(Exception $e){}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset     = ($page - 1) * $perPage;

$armes = [];
try {
    $stmt = $db->prepare("
        SELECT a.*,
               p.firstname, p.lastname, p.avatar, p.license_ppa,
               CONCAT(p.firstname,' ',p.lastname) AS citizen_name,
               u.username AS officer_name
        FROM lssd_armes_civils a
        LEFT JOIN profiles p   ON p.id = a.citizen_id
        LEFT JOIN users u      ON u.id = a.officer_id
        WHERE $whereStr
        ORDER BY
            FIELD(a.statut,'suspendue','enregistree','revoquee'),
            a.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params); $armes = $stmt->fetchAll();
} catch(Exception $e){}

// Matricules des officiers (academy_matricules) pour la modale détail
$officerMatricules = lspd_matricules_map($db, array_column($armes, 'officer_id'), 'LSSD');

// Données auxiliaires
$citizens  = []; try { $citizens  = $db->query("SELECT id,firstname,lastname FROM profiles WHERE license_ppa=1 ORDER BY lastname,firstname LIMIT 500")->fetchAll(); } catch(Exception $e){}
$ppaValides= []; try { $ppaValides= $db->query("SELECT d.id,CONCAT(p.firstname,' ',p.lastname) AS cname,d.type_arme FROM lssd_ppa_demandes d LEFT JOIN profiles p ON p.id=d.citizen_id WHERE d.statut='valide' ORDER BY d.created_at DESC LIMIT 200")->fetchAll(); } catch(Exception $e){}

// KPIs
$kTotal=0; $kActives=0; $kSusp=0; $kRev=0; $kExpires=0;
try { $kTotal   = (int)$db->query("SELECT COUNT(*) FROM lssd_armes_civils")->fetchColumn(); } catch(Exception $e){}
try { $kActives = (int)$db->query("SELECT COUNT(*) FROM lssd_armes_civils WHERE statut='enregistree'")->fetchColumn(); } catch(Exception $e){}
try { $kSusp    = (int)$db->query("SELECT COUNT(*) FROM lssd_armes_civils WHERE statut='suspendue'")->fetchColumn(); } catch(Exception $e){}
try { $kRev     = (int)$db->query("SELECT COUNT(*) FROM lssd_armes_civils WHERE statut='revoquee'")->fetchColumn(); } catch(Exception $e){}
try { $kExpires = (int)$db->query("SELECT COUNT(*) FROM lssd_armes_civils WHERE statut='enregistree' AND date_expiration IS NOT NULL AND date_expiration < CURDATE()")->fetchColumn(); } catch(Exception $e){}

$TYPES_ARME = ['Pistolet','Revolver','Fusil à pompe','Fusil d\'assaut','Carabine','Pistolet mitrailleur','Arme de défense','Autre'];

// [label, badge, icon, couleur principale, couleur light, bg, border]
$STATUT_CFG = [
    'enregistree' => ['Enregistrée','bg-green', 'fas fa-check-circle', '#10b981','#6ee7b7','rgba(16,185,129,.08)','rgba(16,185,129,.22)'],
    'suspendue'   => ['Suspendue',  'bg-yellow','fas fa-pause-circle',  '#f59e0b','#fde68a','rgba(245,158,11,.08)','rgba(245,158,11,.22)'],
    'revoquee'    => ['Révoquée',   'bg-red',   'fas fa-ban',           '#ef4444','#fca5a5','rgba(239,68,68,.08)', 'rgba(239,68,68,.22)'],
];

$preselCitizen = null;
if ($cFilter) { try { $s=$db->prepare("SELECT * FROM profiles WHERE id=?"); $s->execute([$cFilter]); $preselCitizen=$s->fetch(); } catch(Exception $e){} }

include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ Armes table ═══════════════════════════════════════════════════════════ */
.armes-tbl {
    width:100%; border-collapse:collapse;
}
.armes-tbl thead tr {
    background:linear-gradient(90deg,rgba(16,185,129,.07),rgba(16,185,129,.02));
    border-bottom:2px solid rgba(16,185,129,.18);
}
.armes-tbl th {
    padding:.7rem 1rem;
    font-family:'IBM Plex Mono',monospace;
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
    color:var(--txt-s); text-align:left; white-space:nowrap;
}
.armes-tbl td {
    padding:.72rem 1rem;
    border-bottom:1px solid rgba(16,185,129,.06);
    vertical-align:middle; font-size:.81rem;
}
.armes-tbl tbody tr { transition:background .12s; }
.armes-tbl tbody tr:hover td { background:rgba(16,185,129,.04); }
.armes-tbl tbody tr:last-child td { border-bottom:none; }
.armes-tbl tbody tr.st-suspendue td:first-child { border-left:3px solid #f59e0b; }
.armes-tbl tbody tr.st-revoquee  td:first-child { border-left:3px solid #ef4444; }
.armes-tbl tbody tr.st-enregistree td:first-child { border-left:3px solid #10b981; }

/* Citizen cell */
.cit-av {
    width:34px; height:34px; border-radius:8px;
    overflow:hidden; display:inline-flex; align-items:center; justify-content:center;
    font-family:'Syne',serif; font-weight:800; font-size:.85rem; flex-shrink:0;
    vertical-align:middle;
}
.cit-av img { width:100%; height:100%; object-fit:cover; }

/* Serie mono */
.serie-mono {
    font-family:'IBM Plex Mono',monospace; font-size:.73rem; font-weight:600;
    background:rgba(16,185,129,.07); border:1px solid rgba(16,185,129,.15);
    border-radius:5px; padding:.15rem .5rem; color:var(--blue-xl);
    white-space:nowrap;
}

/* Type badge */
.type-badge {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.22rem .6rem; border-radius:6px;
    background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.18);
    font-size:.72rem; font-weight:600; color:var(--blue-xl); white-space:nowrap;
}

/* Filter tabs */
.arme-tabs { display:flex; background:rgba(0,0,0,.3); border:1px solid var(--bdr); border-radius:9px; overflow:hidden; }
.arme-tab  {
    padding:.38rem .85rem; font-size:.76rem; font-weight:600; cursor:pointer;
    text-decoration:none; transition:all .15s; white-space:nowrap;
    color:var(--txt-s); border-right:1px solid var(--bdr);
}
.arme-tab:last-child { border-right:none; }
.arme-tab.active { background:rgba(16,185,129,.18); color:var(--blue-xl); }
.arme-tab .tc { font-family:'IBM Plex Mono',monospace; font-size:.62rem; opacity:.65; margin-left:.25rem; }

/* Action btn */
.act-btn {
    display:inline-flex; align-items:center; gap:.28rem;
    padding:.25rem .55rem; border-radius:5px; font-size:.69rem; font-weight:600;
    cursor:pointer; border:none; transition:all .15s;
}

/* Statut radio buttons */
.statut-radio-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; }
.s-opt {
    text-align:center; padding:.75rem .4rem; border-radius:9px;
    cursor:pointer; transition:all .17s; opacity:.45;
    border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.02);
}
.s-opt.active { opacity:1; transform:scale(1.04); box-shadow:0 0 18px rgba(0,0,0,.45); }
.s-opt i      { display:block; font-size:1.1rem; margin-bottom:.3rem; }
.s-opt span   { font-size:.7rem; font-weight:700; }
</style>

<?php if($msg):?>
<div class="alert alert-<?php echo $msgType;?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo $msg;?>
</div>
<?php endif;?>

<!-- Retour profil si filtré par citoyen -->
<?php if($cFilter && $preselCitizen):?>
<div style="margin-bottom:1rem">
    <a href="profils.php?id=<?php echo $cFilter;?>" class="btn btn-ghost btn-sm">
        <i class="fas fa-arrow-left"></i> Retour — <?php echo htmlspecialchars($preselCitizen['firstname'].' '.$preselCitizen['lastname']);?>
    </a>
</div>
<?php endif;?>

<!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.8rem;margin-bottom:1.4rem">

    <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.18);border-radius:11px;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:9px;background:rgba(16,185,129,.12);display:flex;align-items:center;justify-content:center;color:#6ee7b7;font-size:.9rem;flex-shrink:0"><i class="fas fa-gun"></i></div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)">Total</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:#6ee7b7;line-height:1"><?php echo $kTotal;?></div>
        </div>
    </div>

    <a href="?s=enregistree&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>" style="text-decoration:none">
    <div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2<?php echo $filterStatut==='enregistree'?'5':'';?>);border-radius:11px;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem;transition:all .2s<?php echo $filterStatut==='enregistree'?';border-width:2px':'';?>">
        <div style="width:36px;height:36px;border-radius:9px;background:rgba(16,185,129,.12);display:flex;align-items:center;justify-content:center;color:#6ee7b7;font-size:.9rem;flex-shrink:0"><i class="fas fa-check-circle"></i></div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)">Actives</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:#6ee7b7;line-height:1"><?php echo $kActives;?></div>
        </div>
    </div>
    </a>

    <a href="?s=suspendue&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>" style="text-decoration:none">
    <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2<?php echo $filterStatut==='suspendue'?'5':'';?>);border-radius:11px;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem;transition:all .2s<?php echo $filterStatut==='suspendue'?';border-width:2px':'';?>">
        <div style="width:36px;height:36px;border-radius:9px;background:rgba(245,158,11,.12);display:flex;align-items:center;justify-content:center;color:#fde68a;font-size:.9rem;flex-shrink:0"><i class="fas fa-pause-circle"></i></div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)">Suspendues</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:#fde68a;line-height:1"><?php echo $kSusp;?></div>
        </div>
    </div>
    </a>

    <a href="?s=revoquee&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>" style="text-decoration:none">
    <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2<?php echo $filterStatut==='revoquee'?'5':'';?>);border-radius:11px;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem;transition:all .2s<?php echo $filterStatut==='revoquee'?';border-width:2px':'';?>">
        <div style="width:36px;height:36px;border-radius:9px;background:rgba(239,68,68,.12);display:flex;align-items:center;justify-content:center;color:#fca5a5;font-size:.9rem;flex-shrink:0"><i class="fas fa-ban"></i></div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)">Révoquées</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:#fca5a5;line-height:1"><?php echo $kRev;?></div>
        </div>
    </div>
    </a>

    <div style="background:<?php echo $kExpires>0?'rgba(239,68,68,.07)':'rgba(16,185,129,.04)';?>;border:1px solid <?php echo $kExpires>0?'rgba(239,68,68,.22)':'rgba(16,185,129,.12)';?>;border-radius:11px;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:9px;background:<?php echo $kExpires>0?'rgba(239,68,68,.12)':'rgba(16,185,129,.08)';?>;display:flex;align-items:center;justify-content:center;color:<?php echo $kExpires>0?'#fca5a5':'var(--txt-s)';?>;font-size:.9rem;flex-shrink:0"><i class="fas fa-calendar-xmark"></i></div>
        <div>
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)">Expirées</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.4rem;font-weight:800;color:<?php echo $kExpires>0?'#fca5a5':'var(--txt-s)';?>;line-height:1"><?php echo $kExpires;?></div>
        </div>
    </div>

</div>

<!-- ── TOOLBAR ───────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap">

        <!-- Onglets statut -->
        <div class="arme-tabs">
            <a href="?s=all&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>" class="arme-tab <?php echo $filterStatut==='all'?'active':'';?>">Tous<span class="tc">(<?php echo $kTotal;?>)</span></a>
            <?php foreach($STATUT_CFG as $k=>[$l]):
                $cnt = $k==='enregistree'?$kActives:($k==='suspendue'?$kSusp:($k==='revoquee'?$kRev:0));
            ?>
            <a href="?s=<?php echo $k;?>&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>" class="arme-tab <?php echo $filterStatut===$k?'active':'';?>"><?php echo $l;?><span class="tc">(<?php echo $cnt;?>)</span></a>
            <?php endforeach;?>
        </div>

        <!-- Filtre par type -->
        <select onchange="location.href='?s=<?php echo $filterStatut;?>&t='+this.value+'&q=<?php echo urlencode($search);?>'" class="form-select" style="width:155px;font-size:.78rem">
            <option value="all" <?php echo $filterType==='all'?'selected':'';?>>Tous types</option>
            <?php foreach($TYPES_ARME as $t):?><option value="<?php echo $t;?>" <?php echo $filterType===$t?'selected':'';?>><?php echo $t;?></option><?php endforeach;?>
        </select>

        <!-- Recherche -->
        <form method="GET" style="display:flex;gap:.4rem;align-items:center">
            <input type="hidden" name="s" value="<?php echo $filterStatut;?>">
            <input type="hidden" name="t" value="<?php echo $filterType;?>">
            <?php if($cFilter):?><input type="hidden" name="cid" value="<?php echo $cFilter;?>"><?php endif;?>
            <div style="position:relative">
                <i class="fas fa-search" style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.72rem;pointer-events:none"></i>
                <input type="text" name="q" class="form-input" placeholder="Citoyen, n° série, modèle…" value="<?php echo htmlspecialchars($search);?>" style="padding-left:2rem;width:220px;font-size:.81rem">
            </div>
            <?php if($search||$filterType!=='all'):?><a href="?s=<?php echo $filterStatut;?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
        </form>
    </div>

    <button onclick="LSSD.openModal('registerArmeModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Enregistrer une arme
    </button>
</div>

<!-- ── TABLE ─────────────────────────────────────────────────────────────── -->
<div style="background:rgba(6,13,26,.72);border:1px solid rgba(16,185,129,.15);border-radius:13px;overflow:hidden;margin-bottom:1rem">
    <table class="armes-tbl">
        <thead>
            <tr>
                <th style="width:36px"></th>
                <th>Citoyen</th>
                <th>Type / Modèle</th>
                <th>N° Série</th>
                <th>Calibre</th>
                <th>Enregistrement</th>
                <th>Expiration</th>
                <th>Officier</th>
                <th>Statut</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($armes)):?>
        <tr><td colspan="10" style="text-align:center;padding:4rem 2rem;color:var(--txt-s)">
            <i class="fas fa-gun" style="font-size:2.2rem;opacity:.1;display:block;margin-bottom:.8rem"></i>
            <div style="font-weight:600;margin-bottom:.25rem">Aucune arme enregistrée</div>
            <div style="font-size:.78rem">Utilisez le bouton ci-dessus pour enregistrer la première arme</div>
        </td></tr>
        <?php else: foreach($armes as $arme):
            [$sl,$sbadge,$sico,$scol,$slc,$sbg,$sbdr] = $STATUT_CFG[$arme['statut']];
            $pInit  = strtoupper(substr($arme['firstname']??'?',0,1));
            $gradMap = ['A'=>'#059669','B'=>'#10b981','C'=>'#8b5cf6','D'=>'#f59e0b','E'=>'#ec4899','F'=>'#06b6d4'];
            $pColor = $gradMap[$pInit]??'#475569';
            $today  = new DateTime();
            $isExp  = !empty($arme['date_expiration']) && new DateTime($arme['date_expiration']) < $today && $arme['statut']==='enregistree';
            $armeImgs = json_decode($arme['images'] ?? '[]', true) ?: [];
            $firstImg = $armeImgs[0] ?? '';
        ?>
        <tr class="st-<?php echo $arme['statut'];?>" style="<?php echo $arme['statut']==='revoquee'?'opacity:.6':'';?>">

            <!-- Avatar -->
            <td style="padding:.65rem .75rem .65rem 1rem">
                <div class="cit-av" style="background:<?php echo $pColor;?>22;border:1.5px solid <?php echo $pColor;?>44;color:<?php echo $pColor;?>">
                    <?php if(!empty($arme['avatar'])):?><img src="<?php echo htmlspecialchars(preg_match('/^https?:\/\//',$arme['avatar'])?$arme['avatar']:SITE_URL.$arme['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:8px"><?php else:?><?php echo $pInit;?><?php endif;?>
                </div>
            </td>

            <!-- Citoyen -->
            <td>
                <a href="profils.php?id=<?php echo $arme['citizen_id'];?>" style="font-weight:700;color:var(--txt);text-decoration:none;display:block;font-size:.84rem"><?php echo htmlspecialchars($arme['citizen_name']);?></a>
                <div style="font-size:.68rem;color:var(--txt-m);margin-top:.08rem;font-family:'IBM Plex Mono',monospace">#<?php echo str_pad($arme['id'],5,'0',STR_PAD_LEFT);?></div>
            </td>

            <!-- Type / Modèle -->
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <?php if($firstImg):?>
                    <img src="<?php echo htmlspecialchars($firstImg);?>" alt="" onclick="openArmeDetail(<?php echo $arme['id'];?>)" style="width:38px;height:38px;border-radius:7px;object-fit:cover;border:1px solid rgba(16,185,129,.25);cursor:pointer;flex-shrink:0">
                    <?php endif;?>
                    <div>
                        <div class="type-badge" style="margin-bottom:.22rem"><i class="fas fa-gun" style="font-size:.65rem;opacity:.7"></i><?php echo htmlspecialchars($arme['type_arme']);?></div>
                        <?php if(!empty($arme['modele'])):?>
                        <div style="font-size:.72rem;color:var(--txt-s);margin-top:.15rem"><?php echo htmlspecialchars($arme['modele']);?></div>
                        <?php endif;?>
                    </div>
                </div>
            </td>

            <!-- N° Série -->
            <td><span class="serie-mono"><?php echo htmlspecialchars($arme['numero_serie']??'—');?></span></td>

            <!-- Calibre -->
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($arme['calibre']??'—');?></td>

            <!-- Date enreg -->
            <td style="font-size:.78rem;white-space:nowrap"><?php echo date('d/m/Y',strtotime($arme['date_enregistrement']));?></td>

            <!-- Expiration -->
            <td style="white-space:nowrap">
                <?php if($arme['date_expiration']):?>
                <span style="font-size:.78rem;color:<?php echo $isExp?'#fca5a5':'var(--txt-s)';?>;font-weight:<?php echo $isExp?'700':'400';?>">
                    <?php if($isExp):?><i class="fas fa-triangle-exclamation" style="font-size:.65rem;margin-right:.2rem"></i><?php endif;?>
                    <?php echo date('d/m/Y',strtotime($arme['date_expiration']));?>
                </span>
                <?php else:?><span style="color:var(--txt-m);font-size:.75rem">—</span><?php endif;?>
            </td>

            <!-- Officier -->
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($arme['officer_name']??'—');?></td>

            <!-- Statut -->
            <td>
                <span class="badge <?php echo $sbadge;?>" style="font-size:.63rem;white-space:nowrap">
                    <i class="<?php echo $sico;?>" style="font-size:.57rem"></i> <?php echo $sl;?>
                </span>
                <?php if($isExp):?><br><span class="badge bg-red" style="font-size:.6rem;margin-top:.2rem"><i class="fas fa-clock" style="font-size:.55rem"></i> Expirée</span><?php endif;?>
            </td>

            <!-- Actions -->
            <td style="text-align:right">
                <div style="display:flex;gap:.3rem;justify-content:flex-end;flex-wrap:nowrap">
                    <button onclick="openArmeDetail(<?php echo $arme['id'];?>)"
                            class="act-btn" style="background:rgba(16,185,129,.08);color:var(--blue-l);border:1px solid rgba(16,185,129,.2)">
                        <i class="fas fa-eye" style="font-size:.62rem"></i>
                    </button>
                    <button onclick="openStatutModal(<?php echo $arme['id'];?>,'<?php echo $arme['statut'];?>','<?php echo addslashes($arme['notes']??'');?>')"
                            class="act-btn" style="background:rgba(16,185,129,.1);color:var(--blue-xl);border:1px solid rgba(16,185,129,.22)">
                        <i class="fas fa-pen" style="font-size:.62rem"></i> Modifier
                    </button>
                    <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Supprimer l\'arme #<?php echo htmlspecialchars($arme['numero_serie']??'');?> du recensement ?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $arme['id'];?>">
                        <button type="submit" class="act-btn" style="background:rgba(239,68,68,.09);color:#fca5a5;border:1px solid rgba(239,68,68,.2)">
                            <i class="fas fa-trash" style="font-size:.62rem"></i>
                        </button>
                    </form>
                </div>
                <?php if(!empty($arme['notes'])):?>
                <div style="margin-top:.35rem;font-size:.67rem;color:var(--txt-m);font-style:italic;text-align:right;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($arme['notes']);?>">
                    <i class="fas fa-sticky-note" style="opacity:.5;font-size:.6rem;margin-right:.2rem"></i><?php echo htmlspecialchars($arme['notes']);?>
                </div>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach; endif;?>
        </tbody>
    </table>
</div>

<!-- ── Pagination ────────────────────────────────────────────────────────── -->
<?php if($totalPages > 1):?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;font-size:.78rem;margin-bottom:.5rem">
    <span style="color:var(--txt-m)"><?php echo $totalRows;?> arme(s) · Page <?php echo $page;?>/<?php echo $totalPages;?></span>
    <div style="display:flex;gap:.3rem">
        <?php if($page>1):?><a href="?s=<?php echo $filterStatut;?>&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>&page=<?php echo $page-1;?>" class="btn btn-ghost btn-xs">← Préc.</a><?php endif;?>
        <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++):?>
        <a href="?s=<?php echo $filterStatut;?>&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>&page=<?php echo $i;?>"
           style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid <?php echo $i===$page?'var(--blue)':'var(--bdr)';?>;background:<?php echo $i===$page?'rgba(16,185,129,.15)':'transparent';?>;color:<?php echo $i===$page?'var(--blue-xl)':'var(--txt-s)';?>;text-decoration:none;font-size:.78rem;font-weight:<?php echo $i===$page?'700':'400';?>"><?php echo $i;?></a>
        <?php endfor;?>
        <?php if($page<$totalPages):?><a href="?s=<?php echo $filterStatut;?>&t=<?php echo urlencode($filterType);?>&q=<?php echo urlencode($search);?>&page=<?php echo $page+1;?>" class="btn btn-ghost btn-xs">Suiv. →</a><?php endif;?>
    </div>
</div>
<?php endif;?>


<!-- ══ MODAL ENREGISTREMENT ═══════════════════════════════════════════════ -->
<div id="registerArmeModal" class="modal-ov hidden">
<div class="modal modal-lg">
<div class="modal-hd" style="background:rgba(16,185,129,.06);border-bottom:1px solid rgba(16,185,129,.2)">
    <h2><i class="fas fa-gun" style="color:var(--blue-l)"></i> Enregistrer une arme civile</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('registerArmeModal')">×</button>
</div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="register">

    <!-- Info PPA obligatoire -->
    <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.18);border-radius:9px;padding:.75rem .95rem;margin-bottom:1.1rem;display:flex;align-items:center;gap:.65rem;font-size:.8rem;color:var(--blue-xl)">
        <i class="fas fa-info-circle" style="flex-shrink:0;font-size:.95rem"></i>
        <span>Seuls les citoyens disposant d'un <strong>PPA valide</strong> peuvent faire enregistrer une arme. Le permis est vérifié automatiquement.</span>
    </div>

    <div class="form-grid">
        <!-- Citoyen -->
        <div class="form-group form-full">
            <label class="form-label">Citoyen titulaire du PPA *</label>
            <select name="citizen_id" class="form-select" required>
                <option value="">Sélectionner un citoyen avec PPA…</option>
                <?php foreach($citizens as $c):?>
                <option value="<?php echo $c['id'];?>" <?php echo $cFilter===$c['id']?'selected':'';?>><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']);?></option>
                <?php endforeach;?>
            </select>
            <?php if(empty($citizens)):?><div style="font-size:.72rem;color:#fde68a;margin-top:.3rem;display:flex;align-items:center;gap:.35rem"><i class="fas fa-triangle-exclamation"></i> Aucun citoyen avec PPA actif trouvé.</div><?php endif;?>
        </div>

        <!-- Type + Modèle -->
        <div class="form-group">
            <label class="form-label">Type d'arme *</label>
            <select name="type_arme" class="form-select" required>
                <option value="">Sélectionner…</option>
                <?php foreach($TYPES_ARME as $t):?><option value="<?php echo $t;?>"><?php echo $t;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Modèle</label>
            <input type="text" name="modele" class="form-input" placeholder="Glock 17, Desert Eagle, AR-15…">
        </div>

        <!-- Série + Calibre -->
        <div class="form-group">
            <label class="form-label">Numéro de série</label>
            <input type="text" name="numero_serie" class="form-input" placeholder="Vide = généré automatiquement">
        </div>
        <div class="form-group">
            <label class="form-label">Calibre</label>
            <input type="text" name="calibre" class="form-input" placeholder="9mm, .45 ACP, .357 Mag…">
        </div>

        <!-- Dates -->
        <div class="form-group">
            <label class="form-label">Date d'enregistrement *</label>
            <input type="date" name="date_enregistrement" class="form-input" value="<?php echo date('Y-m-d');?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Date d'expiration <span style="font-weight:400;color:var(--txt-m)">(optionnel)</span></label>
            <input type="date" name="date_expiration" class="form-input">
        </div>

        <!-- Lier PPA -->
        <?php if(!empty($ppaValides)):?>
        <div class="form-group form-full">
            <label class="form-label">Lier à une demande PPA validée <span style="font-weight:400;color:var(--txt-m)">(optionnel)</span></label>
            <select name="ppa_id" class="form-select">
                <option value="">— Ne pas lier —</option>
                <?php foreach($ppaValides as $ppa):?>
                <option value="<?php echo $ppa['id'];?>">#<?php echo str_pad($ppa['id'],5,'0',STR_PAD_LEFT);?> — <?php echo htmlspecialchars($ppa['cname'].' ('.$ppa['type_arme'].')');?></option>
                <?php endforeach;?>
            </select>
        </div>
        <?php endif;?>

        <!-- Images -->
        <div class="form-group form-full">
            <label class="form-label">Images de l'arme <span style="font-weight:400;color:var(--txt-m)">(URL, upload ou CDN)</span></label>
            <input type="hidden" name="images" id="armeImagesHidden" value="[]">
            <div id="armeImagesField"></div>
        </div>

        <!-- Notes -->
        <div class="form-group form-full">
            <label class="form-label">Notes / Observations</label>
            <textarea name="notes" class="form-textarea" placeholder="Remarques particulières sur l'arme ou le porteur…"></textarea>
        </div>
    </div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('registerArmeModal')">Annuler</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer l'arme</button>
</div>
</form></div></div>


<!-- ══ MODAL CHANGEMENT DE STATUT ═════════════════════════════════════════ -->
<div id="statutArmeModal" class="modal-ov hidden">
<div class="modal">
<div class="modal-hd" style="background:rgba(16,185,129,.06);border-bottom:1px solid rgba(16,185,129,.2)">
    <h2><i class="fas fa-pen" style="color:var(--blue-l)"></i> Modifier le statut de l'arme</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('statutArmeModal')">×</button>
</div>
<form method="POST"><div class="modal-bd">
    <input type="hidden" name="action" value="update_statut">
    <input type="hidden" name="id" id="statutArmeId">

    <!-- Arme ciblée (affichée dynamiquement) -->
    <div id="statutArmeInfo" style="background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);border-radius:9px;padding:.7rem .9rem;margin-bottom:1.1rem;font-size:.8rem;color:var(--txt-s);display:none">
        <i class="fas fa-gun" style="margin-right:.4rem;color:var(--blue-l)"></i>
        <span id="statutArmeLabel"></span>
    </div>

    <!-- Sélecteur statut visuel -->
    <div style="margin-bottom:1.1rem">
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txt-s);margin-bottom:.65rem">Nouveau statut *</div>
        <div class="statut-radio-grid">
            <?php foreach($STATUT_CFG as $k=>[$l,$badge,$ico,$col,$lc,$bg,$bdr]):?>
            <label style="cursor:pointer">
                <input type="radio" name="statut" value="<?php echo $k;?>" style="display:none" onchange="onStatutArmeChange()">
                <div class="s-opt" data-k="<?php echo $k;?>" style="border-color:<?php echo $bdr;?>;background:<?php echo $bg;?>">
                    <i class="<?php echo $ico;?>" style="color:<?php echo $lc;?>"></i>
                    <span style="color:<?php echo $lc;?>"><?php echo $l;?></span>
                </div>
            </label>
            <?php endforeach;?>
        </div>
    </div>

    <!-- Avertissement révocation -->
    <div id="revokeWarning" style="display:none;margin-bottom:.9rem">
        <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.22);border-radius:8px;padding:.7rem .9rem;display:flex;gap:.6rem;align-items:flex-start;font-size:.8rem;color:#fca5a5">
            <i class="fas fa-triangle-exclamation" style="flex-shrink:0;margin-top:.05rem"></i>
            <span>La révocation retirera automatiquement le <strong>PPA</strong> du citoyen dans son profil.</span>
        </div>
    </div>

    <!-- Résumé action -->
    <div id="statutArmeSummary" style="display:none;padding:.55rem .85rem;border-radius:7px;font-size:.78rem;font-weight:600;margin-bottom:.9rem"></div>

    <!-- Notes -->
    <div class="form-group">
        <label class="form-label"><i class="fas fa-sticky-note" style="font-size:.65rem;margin-right:.3rem;color:var(--blue-l)"></i> Notes de l'officier</label>
        <textarea name="notes" id="statutArmeNotes" class="form-textarea" rows="2" placeholder="Raison du changement, observations…"></textarea>
    </div>
</div>
<div class="modal-ft">
    <button type="button" class="btn btn-ghost" onclick="LSSD.closeModal('statutArmeModal')">Annuler</button>
    <button type="submit" id="statutArmeSubmit" class="btn btn-primary" disabled><i class="fas fa-save"></i> Enregistrer le statut</button>
</div>
</form></div></div>

<!-- ══ MODAL DÉTAIL ARME ═══════════════════════════════════════════════════ -->
<div id="armeDetailModal" class="modal-ov hidden">
<div class="modal modal-lg">
<div class="modal-hd" style="background:rgba(16,185,129,.06);border-bottom:1px solid rgba(16,185,129,.2)">
    <h2><i class="fas fa-gun" style="color:var(--blue-l)"></i> Détail de l'arme</h2>
    <button class="modal-cl" onclick="LSSD.closeModal('armeDetailModal')">×</button>
</div>
<div class="modal-bd" id="armeDetailBody"></div>
</div></div>

<?php
$ARMES_JS = [];
foreach ($armes as $a) {
    $ARMES_JS[(int)$a['id']] = [
        'citizen'   => $a['citizen_name'],
        'type'      => $a['type_arme'],
        'modele'    => $a['modele'] ?: '—',
        'serie'     => $a['numero_serie'] ?: '—',
        'calibre'   => $a['calibre'] ?: '—',
        'dateEnr'   => $a['date_enregistrement'] ? date('d/m/Y', strtotime($a['date_enregistrement'])) : '—',
        'dateExp'   => $a['date_expiration'] ? date('d/m/Y', strtotime($a['date_expiration'])) : '—',
        'statut'    => $STATUT_CFG[$a['statut']][0] ?? $a['statut'],
        'officer'   => $a['officer_name'] ?: '—',
        'matricule' => $officerMatricules[(int)$a['officer_id']] ?? null,
        'images'    => json_decode($a['images'] ?? '[]', true) ?: [],
        'notes'     => $a['notes'] ?: '',
    ];
}
?>
<script>
const ARMES_DATA = <?php echo json_encode($ARMES_JS); ?>;
function armeEsc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function openArmeDetail(id){
    const a = ARMES_DATA[id]; if(!a) return;
    const gallery = a.images.length
        ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.6rem;margin-bottom:1.2rem">'+
          a.images.map(u=>'<a href="'+armeEsc(u)+'" target="_blank" style="aspect-ratio:1;border-radius:9px;background:#020508 center/cover no-repeat;border:1px solid rgba(16,185,129,.25);display:block" ><img src="'+armeEsc(u)+'" style="width:100%;height:100%;object-fit:cover;border-radius:8px"></a>').join('')+'</div>'
        : '<div style="text-align:center;padding:1.4rem;color:var(--txt-m);font-size:.82rem;margin-bottom:1rem"><i class="fas fa-image" style="font-size:1.6rem;display:block;margin-bottom:.4rem;opacity:.4"></i>Aucune image</div>';
    const row=(l,v)=>'<div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid rgba(16,185,129,.07)"><span style="color:var(--txt-s);font-size:.78rem">'+l+'</span><span style="font-weight:600;font-size:.82rem">'+armeEsc(v)+'</span></div>';
    document.getElementById('armeDetailBody').innerHTML =
        gallery +
        row('Citoyen', a.citizen) + row('Type', a.type) + row('Modèle', a.modele) +
        row('N° série', a.serie) + row('Calibre', a.calibre) +
        row('Enregistrement', a.dateEnr) + row('Expiration', a.dateExp) +
        row('Statut', a.statut) +
        row('Officier', a.officer + (a.matricule ? ' · Matricule '+a.matricule : '')) +
        (a.notes ? '<div style="margin-top:.9rem;padding:.7rem .9rem;background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.14);border-radius:8px;font-size:.8rem;color:var(--txt-s)"><i class="fas fa-sticky-note" style="margin-right:.4rem;color:var(--blue-l)"></i>'+armeEsc(a.notes)+'</div>' : '');
    LSSD.openModal('armeDetailModal');
}
(function(){
    function go(){ if(window.mdtImageField){ mdtImageField({container:'armeImagesField',hidden:'armeImagesHidden',uploadUrl:'armes-civils.php',listUrl:'armes-civils.php?img_action=cdn_list'}); return true; } return false; }
    if(!go()){ var n=0,iv=setInterval(function(){ if(go()||++n>60) clearInterval(iv); },50); }
})();

// ── Ouvrir modale statut ───────────────────────────────────────────────────
function openStatutModal(id, current, notes) {
    document.getElementById('statutArmeId').value    = id;
    document.getElementById('statutArmeNotes').value = notes || '';

    // Reset
    document.querySelectorAll('.s-opt').forEach(o => o.classList.remove('active'));
    document.querySelectorAll('input[name="statut"]').forEach(r => r.checked = false);

    // Pré-select statut courant
    const inp = document.querySelector('input[name="statut"][value="'+current+'"]');
    if (inp) { inp.checked=true; inp.nextElementSibling.classList.add('active'); }

    updateStatutArme(current);
    document.getElementById('statutArmeSubmit').disabled = false;
    LSSD.openModal('statutArmeModal');
}

const ARME_STATUT_CFG = {
    'enregistree': { label:'Activer — l\'arme sera de nouveau enregistrée',   col:'rgba(16,185,129,.12)',  bdr:'rgba(16,185,129,.3)',  tc:'#6ee7b7' },
    'suspendue':   { label:'Suspendre — le port sera temporairement interdit', col:'rgba(245,158,11,.1)',   bdr:'rgba(245,158,11,.28)', tc:'#fde68a' },
    'revoquee':    { label:'Révoquer — le PPA du citoyen sera retiré',         col:'rgba(239,68,68,.09)',   bdr:'rgba(239,68,68,.25)',  tc:'#fca5a5' },
};

function updateStatutArme(k) {
    const cfg = ARME_STATUT_CFG[k];
    const sum = document.getElementById('statutArmeSummary');
    const rev = document.getElementById('revokeWarning');
    if (!cfg) { sum.style.display='none'; if(rev) rev.style.display='none'; return; }
    sum.style.display  = 'block';
    sum.style.background = cfg.col;
    sum.style.border   = '1px solid '+cfg.bdr;
    sum.style.color    = cfg.tc;
    sum.textContent    = cfg.label;
    if (rev) rev.style.display = (k === 'revoquee') ? 'block' : 'none';
}

function onStatutArmeChange() {
    const sel = document.querySelector('input[name="statut"]:checked');
    document.querySelectorAll('.s-opt').forEach(o => o.classList.remove('active'));
    if (sel) {
        sel.nextElementSibling.classList.add('active');
        updateStatutArme(sel.value);
        document.getElementById('statutArmeSubmit').disabled = false;
    }
}

document.querySelectorAll('input[name="statut"]').forEach(r =>
    r.addEventListener('change', onStatutArmeChange)
);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>