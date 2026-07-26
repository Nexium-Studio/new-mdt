<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🏠 Dynasty';
$companyColor   = '#22c55e';
$companyFaction = 'DYNASTY';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle    = 'Catalogue';
$pageSubtitle = 'Portefeuille immobilier · Dynasty';
$db        = Database::getInstance()->getConnection();
$message   = ''; $msgType = '';

// Permissions — RBAC granulaire (biens = stock)
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'stock.delete');

// Zones (module Zones & Tarification) : sélecteur facultatif reliant un bien
// à un quartier tracé. La colonne dynasty_biens.zone_id est ajoutée par
// l'auto-migration ci-dessous ; en son absence le champ est simplement ignoré.
require_once __DIR__ . '/includes/zones_bootstrap.php';
dynasty_zones_bootstrap($db);
$hasZoneId = dynasty_zones_biens_has_zone_id($db);
$hasImages = dynasty_biens_has_images($db);
$hasCatIds = dynasty_biens_has_categories($db);
$dyCsrf    = dynasty_zones_csrf();
$zonesListe = [];
try {
    $zonesListe = $db->query("SELECT id, nom FROM dynasty_zones ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $zonesListe = []; }

// Catégories de quartier (tags) sélectionnables sur un bien.
$quartiersListe = [];
try {
    $quartiersListe = $db->query("SELECT id, label, couleur FROM dynasty_zone_categories WHERE actif=1 ORDER BY ordre ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $quartiersListe = []; }
$quartiersValidIds = array_map(fn($q) => (int)$q['id'], $quartiersListe);
$quartiersById = [];
foreach ($quartiersListe as $q) { $quartiersById[(int)$q['id']] = $q; }

/* ── Helpers ── */
function nextRef($db) {
    $db->beginTransaction();
    $r    = $db->query("SELECT * FROM dynasty_sequences WHERE type='REF' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    $year = (int)date('Y');
    $next = ($r && (int)$r['annee'] === $year) ? (int)$r['dernier'] + 1 : 1;
    $db->prepare("INSERT INTO dynasty_sequences(type,annee,dernier) VALUES('REF',?,?) ON DUPLICATE KEY UPDATE annee=VALUES(annee),dernier=VALUES(dernier)")
       ->execute([$year,$next]);
    $db->commit();
    return 'REF-'.str_pad($next,5,'0',STR_PAD_LEFT);
}

$TYPES = ['appartement'=>'Appartement','maison'=>'Maison','villa'=>'Villa','bureau'=>'Bureau',
          'local_commercial'=>'Local Commercial','terrain'=>'Terrain','parking'=>'Parking','studio'=>'Studio'];
$ICONS = ['appartement'=>'fa-building','maison'=>'fa-home','villa'=>'fa-landmark','bureau'=>'fa-briefcase',
          'local_commercial'=>'fa-store','terrain'=>'fa-map','parking'=>'fa-parking','studio'=>'fa-door-open'];
$EQUIP_LIST = ['parking'=>'Parking','piscine'=>'Piscine','jardin'=>'Jardin','balcon'=>'Balcon',
               'terrasse'=>'Terrasse','ascenseur'=>'Ascenseur','gardien'=>'Gardien','garage'=>'Garage',
               'cave'=>'Cave','interphone'=>'Interphone','climatisation'=>'Clim.',
               'jacuzzi'=>'Jacuzzi','salle_sport'=>'Salle sport','acces_livraison'=>'Accès livraison','vitrine'=>'Vitrine'];

/* ═══ ACTIONS POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action']??'';

    /* ── Créer bien ── */
    if ($action === 'create_bien') {
        try {
            $ref   = nextRef($db);
            $rawEquip = $_POST['equipements'] ?? '[]';
            if (is_string($rawEquip)) {
                $equip = json_encode(array_values(array_filter(json_decode($rawEquip, true) ?: [])));
            } else {
                $equip = json_encode(array_values(array_filter($rawEquip)));
            }
            $db->prepare("
                INSERT INTO dynasty_biens
                    (reference,type_bien,titre,description,adresse,quartier,
                     surface,pieces,chambres,salles_bain,etage,annee_construction,
                     prix_vente,prix_location_semaine,prix_location_mois,
                     charges_mois,caution_mois,disponible_vente,disponible_location,
                     statut,agent_id,commission_vente_pct,commission_location_pct,
                     equipements,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'disponible',?,?,?,?,?)
            ")->execute([
                $ref,
                $_POST['type_bien'],
                sanitize($_POST['titre']),
                sanitize($_POST['description']??''),
                sanitize($_POST['adresse']),
                sanitize($_POST['quartier']??''),
                !empty($_POST['surface'])  ? (float)$_POST['surface']  : null,
                (int)($_POST['pieces']??1),
                (int)($_POST['chambres']??0),
                (int)($_POST['salles_bain']??1),
                (int)($_POST['etage']??0),
                !empty($_POST['annee_construction']) ? (int)$_POST['annee_construction'] : null,
                !empty($_POST['prix_vente'])            ? (float)$_POST['prix_vente']            : null,
                !empty($_POST['prix_location_semaine']) ? (float)$_POST['prix_location_semaine'] : null,
                !empty($_POST['prix_location_mois'])    ? (float)$_POST['prix_location_mois']    : null,
                (float)($_POST['charges_mois']??0),
                (float)($_POST['caution_mois']??1),
                isset($_POST['disponible_vente'])    ? 1 : 0,
                isset($_POST['disponible_location']) ? 1 : 0,
                !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null,
                (float)($_POST['commission_vente_pct']??5),
                (float)($_POST['commission_location_pct']??10),
                $equip,
                $_SESSION['user_id'],
            ]);
            // Rattachement facultatif à une zone (requête séparée : la colonne
            // peut ne pas exister sur une base pas encore migrée).
            $newId = (int)$db->lastInsertId();
            if ($hasZoneId && isset($_POST['zone_id'])) {
                $zid = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
                $db->prepare("UPDATE dynasty_biens SET zone_id=? WHERE id=?")->execute([$zid, $newId]);
            }
            if ($hasImages) {
                $imgs = json_encode(dynasty_biens_clean_images($_POST['images'] ?? '[]'), JSON_UNESCAPED_SLASHES);
                $db->prepare("UPDATE dynasty_biens SET images=? WHERE id=?")->execute([$imgs, $newId]);
            }
            if ($hasCatIds) {
                $cats = dynasty_biens_clean_category_ids($_POST['categorie_ids'] ?? '[]', $quartiersValidIds);
                $db->prepare("UPDATE dynasty_biens SET categorie_ids=? WHERE id=?")->execute([json_encode($cats), $newId]);
            }
            $message = "✅ Instance $ref créée avec succès."; $msgType = 'success';
        } catch(Exception $e) { $message = '❌ '.$e->getMessage(); $msgType = 'error'; }
    }

    /* ── Modifier bien ── */
    if ($action === 'update_bien') {
        try {
            $id    = (int)$_POST['bien_id'];
            $rawEquip = $_POST['equipements'] ?? '[]';
            if (is_string($rawEquip)) {
                $equip = json_encode(array_values(array_filter(json_decode($rawEquip, true) ?: [])));
            } else {
                $equip = json_encode(array_values(array_filter($rawEquip)));
            }
            $db->prepare("
                UPDATE dynasty_biens SET
                    type_bien=?,titre=?,description=?,adresse=?,quartier=?,
                    surface=?,pieces=?,chambres=?,salles_bain=?,etage=?,annee_construction=?,
                    prix_vente=?,prix_location_semaine=?,prix_location_mois=?,
                    charges_mois=?,caution_mois=?,disponible_vente=?,disponible_location=?,
                    statut=?,agent_id=?,commission_vente_pct=?,commission_location_pct=?,equipements=?
                WHERE id=?
            ")->execute([
                $_POST['type_bien'],sanitize($_POST['titre']),sanitize($_POST['description']??''),
                sanitize($_POST['adresse']),sanitize($_POST['quartier']??''),
                !empty($_POST['surface'])  ? (float)$_POST['surface']  : null,
                (int)($_POST['pieces']??1),(int)($_POST['chambres']??0),(int)($_POST['salles_bain']??1),(int)($_POST['etage']??0),
                !empty($_POST['annee_construction']) ? (int)$_POST['annee_construction'] : null,
                !empty($_POST['prix_vente'])            ? (float)$_POST['prix_vente']            : null,
                !empty($_POST['prix_location_semaine']) ? (float)$_POST['prix_location_semaine'] : null,
                !empty($_POST['prix_location_mois'])    ? (float)$_POST['prix_location_mois']    : null,
                (float)($_POST['charges_mois']??0),(float)($_POST['caution_mois']??1),
                isset($_POST['disponible_vente'])?1:0,isset($_POST['disponible_location'])?1:0,
                $_POST['statut'],
                !empty($_POST['agent_id'])?(int)$_POST['agent_id']:null,
                (float)($_POST['commission_vente_pct']??5),(float)($_POST['commission_location_pct']??10),
                $equip,$id,
            ]);
            if ($hasZoneId && isset($_POST['zone_id'])) {
                $zid = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
                $db->prepare("UPDATE dynasty_biens SET zone_id=? WHERE id=?")->execute([$zid, $id]);
            }
            if ($hasImages) {
                $imgs = json_encode(dynasty_biens_clean_images($_POST['images'] ?? '[]'), JSON_UNESCAPED_SLASHES);
                $db->prepare("UPDATE dynasty_biens SET images=? WHERE id=?")->execute([$imgs, $id]);
            }
            if ($hasCatIds) {
                $cats = dynasty_biens_clean_category_ids($_POST['categorie_ids'] ?? '[]', $quartiersValidIds);
                $db->prepare("UPDATE dynasty_biens SET categorie_ids=? WHERE id=?")->execute([json_encode($cats), $id]);
            }
            $message = '✅ Instance mise à jour.'; $msgType = 'success';
        } catch(Exception $e) { $message = '❌ '.$e->getMessage(); $msgType = 'error'; }
    }

    /* ── Supprimer bien ── */
    if ($action === 'delete_bien') {
        try {
            $id = (int)$_POST['bien_id'];
            $used = $db->prepare("SELECT (SELECT COUNT(*) FROM dynasty_locations WHERE bien_id=? AND statut='actif')+(SELECT COUNT(*) FROM dynasty_ventes WHERE bien_id=? AND statut IN('en_cours','signe'))");
            $used->execute([$id,$id]);
            if ($used->fetchColumn()>0) throw new Exception("Ce bien a des contrats actifs. Terminez-les d'abord.");
            $db->prepare("DELETE FROM dynasty_biens WHERE id=?")->execute([$id]);
            $message = '✅ Instance supprimée.'; $msgType = 'success';
        } catch(Exception $e) { $message = '❌ '.$e->getMessage(); $msgType = 'error'; }
    }
}

/* ── Filtres ── */
$fType   = $_GET['type']   ?? 'all';
$fStatut = $_GET['statut'] ?? 'all';
$fDispo  = $_GET['dispo']  ?? 'all';
$search  = trim($_GET['q'] ?? '');

$where = ['1=1']; $params = [];
if ($fType   !== 'all') { $where[] = 'b.type_bien=?'; $params[] = $fType; }
if ($fStatut !== 'all') { $where[] = 'b.statut=?';    $params[] = $fStatut; }
if ($fDispo  === 'vente')    $where[] = 'b.disponible_vente=1';
if ($fDispo  === 'location') $where[] = 'b.disponible_location=1';
if ($search) {
    $where[] = '(b.titre LIKE ? OR b.adresse LIKE ? OR b.quartier LIKE ? OR b.reference LIKE ?)';
    $params  = array_merge($params,["%$search%","%$search%","%$search%","%$search%"]);
}

$biens = $db->prepare("
    SELECT b.*, u.username agent_nom,
        (SELECT COUNT(*) FROM dynasty_locations l WHERE l.bien_id=b.id AND l.statut='actif') locations_actives
    FROM dynasty_biens b LEFT JOIN users u ON b.agent_id=u.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY b.created_at DESC
");
$biens->execute($params);
$biens = $biens->fetchAll(PDO::FETCH_ASSOC);

// Stats rapides
$smap = [];
foreach ($db->query("SELECT statut,COUNT(*) n FROM dynasty_biens GROUP BY statut")->fetchAll(PDO::FETCH_ASSOC) as $r) $smap[$r['statut']] = $r['n'];

// Agents — source unifiée : users.faction
$agents = $db->query("
    SELECT u.id, u.username,
           COALESCE(NULLIF(u.faction_rank,''), 'Agent') AS `rank`,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE u.faction='DYNASTY' AND u.is_active=1
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Gestionnaire d'images (modales créer / modifier) ── */
.dy-imgmgr { display:flex; flex-direction:column; gap:.7rem; }
.dy-imgmgr-add { display:grid; grid-template-columns:1fr auto; gap:.6rem; align-items:stretch; }
@media (max-width:640px){ .dy-imgmgr-add { grid-template-columns:1fr; } }
.dy-imgmgr-url { display:flex; gap:.5rem; }
.dy-imgmgr-url .form-input { flex:1; }
.dy-imgmgr-drop {
    display:flex; align-items:center; gap:.6rem; padding:.7rem 1rem; cursor:pointer;
    background:var(--surface-2); border:1px dashed var(--bd-2); border-radius:var(--r-md);
    color:var(--tx-3); font-size:.8rem; font-weight:600; transition:border-color .15s, background .15s, color .15s;
    white-space:nowrap;
}
.dy-imgmgr-drop:hover, .dy-imgmgr-drop.drag { border-color:var(--dy); background:var(--dy-soft); color:var(--dy); }
.dy-imgmgr-drop i { font-size:1.05rem; }
.dy-imgmgr-drop small { display:block; font-weight:500; color:var(--tx-mute); font-size:.68rem; }
.dy-imgmgr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(96px,1fr)); gap:.55rem; }
.dy-imgmgr-empty { grid-column:1/-1; padding:1.3rem; text-align:center; color:var(--tx-mute); font-size:.78rem; border:1px dashed var(--bd); border-radius:var(--r-md); }
.dy-thumb {
    position:relative; aspect-ratio:1/1; border-radius:var(--r-md); overflow:hidden;
    border:1px solid var(--bd-2); background:var(--surface-3); cursor:grab;
}
.dy-thumb.dragging { opacity:.4; }
.dy-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.dy-thumb .dy-thumb-broken { display:flex; align-items:center; justify-content:center; width:100%; height:100%; color:var(--tx-mute); font-size:1.1rem; }
.dy-thumb-x {
    position:absolute; top:4px; right:4px; width:22px; height:22px; border:0; border-radius:6px;
    background:rgba(2,6,12,.7); color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center;
    font-size:.7rem; opacity:0; transition:opacity .15s, background .15s;
}
.dy-thumb:hover .dy-thumb-x { opacity:1; }
.dy-thumb-x:hover { background:var(--danger); }
.dy-thumb-cover {
    position:absolute; left:0; bottom:0; right:0; padding:.2rem .35rem; font-size:.6rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.03em; text-align:center;
    background:linear-gradient(0deg,rgba(16,185,129,.92),rgba(16,185,129,.55)); color:#04140e;
}
.dy-imgmgr-hint { font-size:.68rem; color:var(--tx-mute); }
.dy-uploading { display:inline-flex; align-items:center; gap:.4rem; font-size:.75rem; color:var(--dy); }

/* Cover réelle dans la carte de gestion */
.bien-photo img.bien-cover { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.bien-photo.has-cover > i { display:none; }

/* ── Sélecteur de quartiers (tags) ── */
.quartier-tags { display:flex; flex-wrap:wrap; gap:.45rem; }
.quartier-tag {
    display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; user-select:none;
    padding:.4rem .7rem; border-radius:var(--r-pill,999px); font-size:.78rem; font-weight:600;
    color:var(--tx-3); background:var(--surface-2); border:1px solid var(--bd-2);
    transition:background .15s, border-color .15s, color .15s;
}
.quartier-tag .qt-dot { width:9px; height:9px; border-radius:50%; background:var(--qc,#94a3b8); flex-shrink:0; }
.quartier-tag:hover { border-color:var(--qc,var(--bd-h)); color:var(--tx-2); }
.quartier-tag.on {
    color:var(--tx); border-color:var(--qc,var(--dy));
    background:color-mix(in srgb, var(--qc,var(--dy)) 16%, var(--surface-2));
}

/* Tags quartiers sur la carte de gestion */
.bien-quartiers { display:flex; flex-wrap:wrap; gap:.35rem; margin-bottom:.6rem; }
.bien-quartier-tag { display:inline-flex; align-items:center; gap:.35rem; font-size:.68rem; font-weight:600; color:var(--tx-2); background:var(--surface-2); border:1px solid var(--bd-2); border-radius:var(--r-pill,999px); padding:.2rem .55rem; }
.bien-quartier-tag .qt-dot { width:7px; height:7px; border-radius:50%; }
</style>

<?php if($message): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $message; ?></div>
<?php endif; ?>

<!-- Header page -->
<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-building"></i> Catalogue des biens</div>
        <div class="page-header-sub"><?php echo count($biens); ?> bien<?php echo count($biens)>1?'s':''; ?> affiché<?php echo count($biens)>1?'s':''; ?> · <?php echo array_sum($smap); ?> au portefeuille total</div>
    </div>
    <?php if($canManage): ?>
    <div class="page-header-actions">
        <button onclick="openCreate()" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau bien</button>
    </div>
    <?php endif; ?>
</div>

<!-- Stats rapides -->
<div class="dy-stats" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.2rem;">
    <?php $sDef=['disponible'=>['#22c55e','fa-check-circle'],'loue'=>['#3b82f6','fa-key'],'vendu'=>['#94a3b8','fa-gavel'],'reserve'=>['#f59e0b','fa-bookmark'],'indisponible'=>['#ef4444','fa-times-circle']];
    foreach($sDef as $s=>[$col,$ic]): ?>
    <div class="dy-stat" style="flex-direction:column;align-items:flex-start;gap:.4rem;padding:1rem 1.2rem;">
        <i class="fas <?php echo $ic; ?>" style="color:<?php echo $col; ?>;font-size:1rem;"></i>
        <div class="dy-stat-val" style="font-size:1.3rem;"><?php echo $smap[$s]??0; ?></div>
        <div class="dy-stat-lbl"><?php echo ucfirst($s); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filtres -->
<form method="GET">
<div class="filters-bar">
    <div class="filter-group" style="flex:2;">
        <label class="filter-lbl">Recherche</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-input" placeholder="Titre, adresse, réf...">
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Type</label>
        <select name="type" class="form-select">
            <option value="all">Tous les types</option>
            <?php foreach($TYPES as $k=>$v): ?>
            <option value="<?php echo $k; ?>" <?php echo $fType===$k?'selected':''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Statut</label>
        <select name="statut" class="form-select">
            <option value="all">Tous statuts</option>
            <option value="disponible" <?php echo $fStatut==='disponible'?'selected':''; ?>>Disponible</option>
            <option value="loue"       <?php echo $fStatut==='loue'?'selected':''; ?>>Loué</option>
            <option value="vendu"      <?php echo $fStatut==='vendu'?'selected':''; ?>>Vendu</option>
            <option value="reserve"    <?php echo $fStatut==='reserve'?'selected':''; ?>>Réservé</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Disponible pour</label>
        <select name="dispo" class="form-select">
            <option value="all">Tous</option>
            <option value="vente"    <?php echo $fDispo==='vente'?'selected':''; ?>>Vente</option>
            <option value="location" <?php echo $fDispo==='location'?'selected':''; ?>>Location</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;"><i class="fas fa-search"></i></button>
    <?php if($search||$fType!=='all'||$fStatut!=='all'||$fDispo!=='all'): ?>
    <a href="biens.php" class="btn btn-secondary btn-sm" style="align-self:flex-end;"><i class="fas fa-times"></i></a>
    <?php endif; ?>
</div>
</form>

<!-- Grille biens -->
<?php if(empty($biens)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-home"></i><p>Aucune instance ne correspond à vos critères.</p>
<?php if($canManage): ?><button onclick="openCreate()" class="btn btn-primary"><i class="fas fa-plus"></i> Créer la première instance</button><?php endif; ?>
</div></div>
<?php else: ?>
<div class="biens-grid">
<?php foreach($biens as $b):
    $ico   = $ICONS[$b['type_bien']]??'fa-home';
    $equip = json_decode($b['equipements']??'[]',true) ?: [];
    $imgs  = $hasImages ? dynasty_biens_clean_images($b['images'] ?? '[]') : [];
    $cover = $imgs ? dynasty_biens_image_url($imgs[0]) : '';
    $bienCats = $hasCatIds ? dynasty_biens_clean_category_ids($b['categorie_ids'] ?? '[]', $quartiersValidIds) : [];
?>
<div class="bien-card">
    <div class="bien-photo<?php echo $cover?' has-cover':''; ?>">
        <?php if($cover): ?><img class="bien-cover" src="<?php echo htmlspecialchars($cover); ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove();this.parentNode.classList.remove('has-cover')"><?php endif; ?>
        <i class="fas <?php echo $ico; ?>"></i>
        <?php if(count($imgs)>1): ?><span class="ov-ref" style="left:.7rem;right:auto;background:rgba(2,6,12,.7)"><i class="fas fa-images"></i> <?php echo count($imgs); ?></span><?php endif; ?>
        <span class="ov-statut"><span class="badge badge-<?php echo $b['statut']; ?>"><?php echo ucfirst($b['statut']); ?></span></span>
        <span class="ov-ref"><?php echo $b['reference']; ?></span>
    </div>
    <div class="bien-body">
        <div class="bien-type-tag"><i class="fas <?php echo $ico; ?>"></i><?php echo $TYPES[$b['type_bien']]??$b['type_bien']; ?></div>
        <div class="bien-titre"><?php echo htmlspecialchars($b['titre']); ?></div>
        <div class="bien-adr"><i class="fas fa-map-marker-alt" style="color:#22c55e;"></i><?php echo htmlspecialchars($b['quartier']?$b['quartier'].', '.$b['adresse']:$b['adresse']); ?></div>
        <?php if($bienCats): ?>
        <div class="bien-quartiers">
            <?php foreach($bienCats as $cid): if(!isset($quartiersById[$cid])) continue; $qc=$quartiersById[$cid]; ?>
            <span class="bien-quartier-tag"><span class="qt-dot" style="background:<?php echo htmlspecialchars($qc['couleur']); ?>"></span><?php echo htmlspecialchars($qc['label']); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="bien-specs">
            <?php if($b['surface']): ?><span class="bien-spec"><i class="fas fa-ruler-combined"></i><?php echo $b['surface']; ?> m²</span><?php endif; ?>
            <?php if($b['pieces']): ?><span class="bien-spec"><i class="fas fa-door-open"></i><?php echo $b['pieces']; ?> p.</span><?php endif; ?>
            <?php if($b['chambres']): ?><span class="bien-spec"><i class="fas fa-bed"></i><?php echo $b['chambres']; ?> ch.</span><?php endif; ?>
            <?php if($b['etage']>0): ?><span class="bien-spec"><i class="fas fa-layer-group"></i>Ét. <?php echo $b['etage']; ?></span><?php endif; ?>
        </div>

        <?php if(!empty($equip)): ?>
        <div class="equip-tags" style="margin-bottom:.8rem;">
            <?php foreach(array_slice($equip,0,4) as $e): ?><span class="equip-tag"><?php echo $EQUIP_LIST[$e]??$e; ?></span><?php endforeach; ?>
            <?php if(count($equip)>4): ?><span class="equip-tag">+<?php echo count($equip)-4; ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="bien-prix-grid">
            <?php if($b['prix_vente']): $pvTTC=round($b['prix_vente']*1.10,2); ?>
            <div class="bien-prix-item">
                <div class="bien-prix-lbl"><i class="fas fa-handshake"></i> Achat HT</div>
                <div class="bien-prix-val">$<?php echo number_format($b['prix_vente'],0); ?></div>
                <div style="font-size:.62rem;color:#22c55e;opacity:.75;">TTC $<?php echo number_format($pvTTC,0); ?></div>
            </div>
            <?php endif; ?>
            <?php if($b['prix_location_semaine']): $plTTC=round($b['prix_location_semaine']*1.10,2); ?>
            <div class="bien-prix-item">
                <div class="bien-prix-lbl"><i class="fas fa-calendar-week"></i> Location /sem. HT</div>
                <div class="bien-prix-val">$<?php echo number_format($b['prix_location_semaine'],0); ?></div>
                <div style="font-size:.62rem;color:#22c55e;opacity:.75;">TTC $<?php echo number_format($plTTC,0); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div style="font-size:.7rem;color:var(--tx2);margin-bottom:.7rem;">
            <?php if($b['agent_nom']): ?>Agent : <?php echo htmlspecialchars($b['agent_nom']); ?> · <?php endif; ?>
            <?php if($b['locations_actives']>0): ?><span style="color:#3b82f6;"><i class="fas fa-key"></i> <?php echo $b['locations_actives']; ?> loc. active(s)</span><?php endif; ?>
        </div>

        <?php if($canManage): ?>
        <div class="bien-actions">
            <button onclick='editBien(<?php echo json_encode($b,JSON_HEX_APOS); ?>)' class="btn btn-secondary btn-sm" style="flex:1;"><i class="fas fa-edit"></i> Modifier</button>
            <a href="locations.php?bien_id=<?php echo $b['id']; ?>&open=new" class="btn btn-secondary btn-sm btn-icon" title="Créer location"><i class="fas fa-key"></i></a>
            <a href="ventes.php?bien_id=<?php echo $b['id']; ?>&open=new"   class="btn btn-warn btn-sm btn-icon" title="Créer vente"><i class="fas fa-handshake"></i></a>
            <button onclick="delBien(<?php echo $b['id']; ?>,'<?php echo addslashes(htmlspecialchars($b['titre'])); ?>')" class="btn btn-danger btn-sm btn-icon"><i class="fas fa-trash"></i></button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ════ MODAL CRÉER ════ -->
<?php if($canManage): ?>
<div id="createModal" class="modal-overlay hidden">
<div class="modal modal-lg">
    <div class="modal-header"><h2><i class="fas fa-layer-group"></i> Créer une instance</h2><button class="modal-close" onclick="Company.closeModal('createModal')">×</button></div>
    <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="create_bien">

        <div class="sec-title"><i class="fas fa-info-circle"></i> Informations générales</div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Type *</label><select name="type_bien" class="form-select" required><?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Agent responsable</label><select name="agent_id" class="form-select"><option value="">— Aucun —</option><?php foreach($agents as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['username']); ?> (<?php echo htmlspecialchars($a['rank']); ?>)</option><?php endforeach; ?></select></div>
            <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required placeholder="Ex: Bel Appartement Vinewood Hills"></div>
            <div class="form-group form-full"><label class="form-label">Adresse *</label><input type="text" name="adresse" class="form-input" required placeholder="Adresse complète"></div>
            <div class="form-group"><label class="form-label">Quartier</label><input type="text" name="quartier" class="form-input" placeholder="Ex: Vinewood Hills"></div>
            <div class="form-group"><label class="form-label">Année construction</label><input type="number" name="annee_construction" class="form-input" min="1800" max="2100" placeholder="Ex: 2020"></div>
            <?php if($hasCatIds && $quartiersListe): ?>
            <div class="form-group form-full">
                <label class="form-label">Quartiers <span style="color:var(--tx-mute);font-weight:400">— tags affichés sur le catalogue public</span></label>
                <div class="quartier-tags" id="quartierTags">
                    <?php foreach($quartiersListe as $q): ?>
                    <span class="quartier-tag" data-id="<?php echo (int)$q['id']; ?>" style="--qc:<?php echo htmlspecialchars($q['couleur']); ?>" onclick="toggleQuartier(this,'quartierTags','quartierInput')">
                        <span class="qt-dot"></span><?php echo htmlspecialchars($q['label']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="categorie_ids" id="quartierInput" value="[]">
            </div>
            <?php endif; ?>
            <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Description du bien..."></textarea></div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-ruler-combined"></i> Caractéristiques</div>
        <div class="form-grid3">
            <div class="form-group"><label class="form-label">Surface (m²)</label><input type="number" name="surface" class="form-input" step="0.01" min="0" placeholder="75"></div>
            <div class="form-group"><label class="form-label">Pièces</label><input type="number" name="pieces" class="form-input" min="1" value="1"></div>
            <div class="form-group"><label class="form-label">Chambres</label><input type="number" name="chambres" class="form-input" min="0" value="0"></div>
            <div class="form-group"><label class="form-label">Salles de bain</label><input type="number" name="salles_bain" class="form-input" min="0" value="1"></div>
            <div class="form-group"><label class="form-label">Étage</label><input type="number" name="etage" class="form-input" min="0" value="0"></div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-dollar-sign"></i> Prix (HT — taxe 10% ajoutée automatiquement)</div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-handshake" style="color:#22c55e"></i> Prix d'achat HT ($)</label>
                <input type="number" name="prix_vente" id="cv_pv" class="form-input" step="0.01" min="0" placeholder="0" oninput="calcTTC('cv_pv','cv_pv_ttc')">
                <div style="font-size:.7rem;color:#64748b;margin-top:.3rem">TTC (avec taxe 10%) : <strong id="cv_pv_ttc" style="color:#22c55e">—</strong></div>
                <input type="hidden" name="disponible_vente" value="1">
            </div>
            <div class="form-group"><label class="form-label">Commission vente (%)</label><input type="number" name="commission_vente_pct" class="form-input" step="0.01" value="5"></div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-calendar-week" style="color:#22c55e"></i> Location /semaine HT ($)</label>
                <input type="number" name="prix_location_semaine" id="cv_ps" class="form-input" step="0.01" min="0" placeholder="0" oninput="calcTTC('cv_ps','cv_ps_ttc')">
                <div style="font-size:.7rem;color:#64748b;margin-top:.3rem">TTC (avec taxe 10%) : <strong id="cv_ps_ttc" style="color:#22c55e">—</strong></div>
                <input type="hidden" name="disponible_location" value="1">
            </div>
            <div class="form-group"><label class="form-label">Commission location (%)</label><input type="number" name="commission_location_pct" class="form-input" step="0.01" value="10"></div>
        </div>

        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-tools"></i> Équipements</div>
        <div class="equip-tags" id="equipTags">
            <?php foreach($EQUIP_LIST as $k=>$v): ?>
            <span class="equip-tag" data-val="<?php echo $k; ?>" onclick="toggleEquip(this)"><?php echo $v; ?></span>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="equipements" id="equipInput">

        <?php if($hasImages): ?>
        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-images"></i> Images du bien</div>
        <div class="dy-imgmgr" data-imgmgr>
            <div class="dy-imgmgr-add">
                <div class="dy-imgmgr-url">
                    <input type="url" class="form-input" placeholder="https://… URL directe d'une image" data-img-url>
                    <button type="button" class="btn btn-secondary" data-img-addurl><i class="fas fa-link"></i></button>
                </div>
                <label class="dy-imgmgr-drop" data-img-drop tabindex="0">
                    <input type="file" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp" multiple hidden data-img-file>
                    <i class="fas fa-cloud-arrow-up"></i>
                    <span>Importer des fichiers<small>PNG, JPG, JPEG, GIF, WEBP · 6 Mo</small></span>
                </label>
            </div>
            <div class="dy-imgmgr-grid" data-img-grid></div>
            <div class="dy-imgmgr-hint"><i class="fas fa-arrows-up-down-left-right"></i> Glissez pour réordonner · la 1ʳᵉ image sert de couverture.</div>
            <input type="hidden" name="images" data-img-input value="[]">
        </div>
        <?php endif; ?>

    </div><div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer l'instance</button>
    </div></form>
</div></div>

<!-- ════ MODAL MODIFIER ════ -->
<div id="editModal" class="modal-overlay hidden">
<div class="modal modal-lg">
    <div class="modal-header"><h2><i class="fas fa-edit"></i> Modifier l'instance</h2><button class="modal-close" onclick="Company.closeModal('editModal')">×</button></div>
    <form method="POST"><div class="modal-body"><input type="hidden" name="action" value="update_bien"><input type="hidden" name="bien_id" id="e_id">
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Type *</label><select name="type_bien" id="e_type" class="form-select" required><?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Agent</label><select name="agent_id" id="e_agent" class="form-select"><option value="">— Aucun —</option><?php foreach($agents as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['username']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" id="e_titre" class="form-input" required></div>
            <div class="form-group form-full"><label class="form-label">Adresse *</label><input type="text" name="adresse" id="e_adresse" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Quartier</label><input type="text" name="quartier" id="e_quartier" class="form-input"></div>
            <div class="form-group"><label class="form-label">Statut</label><select name="statut" id="e_statut" class="form-select"><option value="disponible">Disponible</option><option value="loue">Loué</option><option value="vendu">Vendu</option><option value="reserve">Réservé</option><option value="indisponible">Indisponible</option></select></div>
            <?php if($hasCatIds && $quartiersListe): ?>
            <div class="form-group form-full">
                <label class="form-label">Quartiers <span style="color:var(--tx-mute);font-weight:400">— tags affichés sur le catalogue public</span></label>
                <div class="quartier-tags" id="editQuartierTags">
                    <?php foreach($quartiersListe as $q): ?>
                    <span class="quartier-tag" data-id="<?php echo (int)$q['id']; ?>" style="--qc:<?php echo htmlspecialchars($q['couleur']); ?>" onclick="toggleQuartier(this,'editQuartierTags','editQuartierInput')">
                        <span class="qt-dot"></span><?php echo htmlspecialchars($q['label']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="categorie_ids" id="editQuartierInput" value="[]">
            </div>
            <?php endif; ?>
            <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" id="e_desc" class="form-textarea"></textarea></div>
            <div class="form-group"><label class="form-label">Surface (m²)</label><input type="number" name="surface" id="e_surface" class="form-input" step="0.01"></div>
            <div class="form-group"><label class="form-label">Pièces</label><input type="number" name="pieces" id="e_pieces" class="form-input" min="1"></div>
            <div class="form-group"><label class="form-label">Chambres</label><input type="number" name="chambres" id="e_chambres" class="form-input" min="0"></div>
            <div class="form-group"><label class="form-label">SdB</label><input type="number" name="salles_bain" id="e_sdb" class="form-input" min="0"></div>
            <div class="form-group"><label class="form-label">Étage</label><input type="number" name="etage" id="e_etage" class="form-input" min="0"></div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-handshake" style="color:#22c55e"></i> Prix d'achat HT ($)</label>
                <input type="number" name="prix_vente" id="e_pv" class="form-input" step="0.01" min="0" oninput="calcTTC('e_pv','e_pv_ttc')">
                <div style="font-size:.7rem;color:#64748b;margin-top:.3rem">TTC : <strong id="e_pv_ttc" style="color:#22c55e">—</strong></div>
                <input type="hidden" name="disponible_vente" value="1">
            </div>
            <div class="form-group"><label class="form-label">Comm. vente %</label><input type="number" name="commission_vente_pct" id="e_cv" class="form-input" step="0.01"></div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-calendar-week" style="color:#22c55e"></i> Location /semaine HT ($)</label>
                <input type="number" name="prix_location_semaine" id="e_ps" class="form-input" step="0.01" min="0" oninput="calcTTC('e_ps','e_ps_ttc')">
                <div style="font-size:.7rem;color:#64748b;margin-top:.3rem">TTC : <strong id="e_ps_ttc" style="color:#22c55e">—</strong></div>
                <input type="hidden" name="disponible_location" value="1">
            </div>
            <div class="form-group"><label class="form-label">Comm. location %</label><input type="number" name="commission_location_pct" id="e_cl" class="form-input" step="0.01"></div>
        </div>
        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-tools"></i> Équipements</div>
        <div class="equip-tags" id="editEquipTags">
            <?php foreach($EQUIP_LIST as $k=>$v): ?>
            <span class="equip-tag" data-val="<?php echo $k; ?>" onclick="toggleEquipEdit(this)"><?php echo $v; ?></span>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="equipements" id="editEquipInput">

        <?php if($hasImages): ?>
        <div class="sec-title" style="margin-top:1rem;"><i class="fas fa-images"></i> Images du bien</div>
        <div class="dy-imgmgr" data-imgmgr>
            <div class="dy-imgmgr-add">
                <div class="dy-imgmgr-url">
                    <input type="url" class="form-input" placeholder="https://… URL directe d'une image" data-img-url>
                    <button type="button" class="btn btn-secondary" data-img-addurl><i class="fas fa-link"></i></button>
                </div>
                <label class="dy-imgmgr-drop" data-img-drop tabindex="0">
                    <input type="file" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp" multiple hidden data-img-file>
                    <i class="fas fa-cloud-arrow-up"></i>
                    <span>Importer des fichiers<small>PNG, JPG, JPEG, GIF, WEBP · 6 Mo</small></span>
                </label>
            </div>
            <div class="dy-imgmgr-grid" data-img-grid></div>
            <div class="dy-imgmgr-hint"><i class="fas fa-arrows-up-down-left-right"></i> Glissez pour réordonner · la 1ʳᵉ image sert de couverture.</div>
            <input type="hidden" name="images" data-img-input value="[]">
        </div>
        <?php endif; ?>
    </div><div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="Company.closeModal('editModal')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div></form>
</div></div>
<?php endif; ?>

<?php if($hasImages): ?>
<script>
window.DY_UPLOAD_URL = '<?php echo SITE_URL; ?>/pages/companies/dynasty/api/upload_image.php';
window.DY_CSRF = <?php echo json_encode($dyCsrf); ?>;
window.DY_SITE = '<?php echo rtrim(SITE_URL,'/'); ?>';

/* ══════════════════════════════════════════════════════════════════════════
   Gestionnaire d'images d'un bien : ajout par URL ou par import de fichiers,
   vignettes réordonnables (glisser), 1ʳᵉ = couverture. La liste est tenue à
   jour dans un <input hidden name="images"> sérialisé en JSON.
   ══════════════════════════════════════════════════════════════════════════ */
window.DynImages = (function () {
    'use strict';
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); };

    function resolveUrl(u) {
        return /^https?:/i.test(u) ? u : (window.DY_SITE + '/' + String(u).replace(/^\/+/, ''));
    }

    function Manager(root) {
        this.root  = root;
        this.list  = [];
        this.input = root.querySelector('[data-img-input]');
        this.grid  = root.querySelector('[data-img-grid]');
        this.dragIdx = null;
        this._bind();
        this.render();
    }

    Manager.prototype.setList = function (arr) {
        this.list = Array.isArray(arr) ? arr.filter(function (u) { return typeof u === 'string' && u.trim(); }) : [];
        this.sync();
    };
    Manager.prototype.sync = function () {
        this.input.value = JSON.stringify(this.list);
        this.render();
    };
    Manager.prototype.add = function (urls) {
        var self = this;
        (Array.isArray(urls) ? urls : [urls]).forEach(function (u) {
            u = String(u || '').trim();
            if (u && self.list.indexOf(u) < 0 && self.list.length < 24) self.list.push(u);
        });
        this.sync();
    };
    Manager.prototype.remove = function (i) { this.list.splice(i, 1); this.sync(); };
    Manager.prototype.move = function (from, to) {
        if (from === to || from < 0 || to < 0 || from >= this.list.length || to >= this.list.length) return;
        var it = this.list.splice(from, 1)[0];
        this.list.splice(to, 0, it);
        this.sync();
    };

    Manager.prototype.render = function () {
        var self = this;
        if (!this.list.length) {
            this.grid.innerHTML = '<div class="dy-imgmgr-empty">Aucune image. Ajoutez une URL ou importez des fichiers.</div>';
            return;
        }
        this.grid.innerHTML = this.list.map(function (u, i) {
            return '<div class="dy-thumb" draggable="true" data-i="' + i + '">' +
                '<img src="' + esc(resolveUrl(u)) + '" alt="" referrerpolicy="no-referrer" ' +
                'onerror="this.outerHTML=\'<div class=&quot;dy-thumb-broken&quot;><i class=&quot;fas fa-image&quot;></i></div>\'">' +
                '<button type="button" class="dy-thumb-x" data-x="' + i + '" title="Retirer"><i class="fas fa-times"></i></button>' +
                (i === 0 ? '<span class="dy-thumb-cover">Couverture</span>' : '') +
                '</div>';
        }).join('');
    };

    Manager.prototype._bind = function () {
        var self = this;

        // Ajout par URL
        var urlInput = this.root.querySelector('[data-img-url]');
        var addUrl = function () {
            var v = (urlInput.value || '').trim();
            if (!v) return;
            if (!/^https?:\/\//i.test(v)) { Company.toast('URL invalide (http/https attendu).', 'warning'); return; }
            self.add(v); urlInput.value = '';
        };
        this.root.querySelector('[data-img-addurl]').addEventListener('click', addUrl);
        urlInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addUrl(); } });

        // Import de fichiers
        var fileInput = this.root.querySelector('[data-img-file]');
        fileInput.addEventListener('change', function () { self.upload(fileInput.files); fileInput.value = ''; });

        // Glisser-déposer de fichiers sur la zone
        var drop = this.root.querySelector('[data-img-drop]');
        ['dragover', 'dragenter'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) self.upload(e.dataTransfer.files);
        });

        // Suppression + réordonnancement (délégation)
        this.grid.addEventListener('click', function (e) {
            var x = e.target.closest('[data-x]'); if (x) { self.remove(parseInt(x.dataset.x, 10)); }
        });
        this.grid.addEventListener('dragstart', function (e) {
            var t = e.target.closest('.dy-thumb'); if (!t) return;
            self.dragIdx = parseInt(t.dataset.i, 10); t.classList.add('dragging');
        });
        this.grid.addEventListener('dragend', function (e) {
            var t = e.target.closest('.dy-thumb'); if (t) t.classList.remove('dragging');
        });
        this.grid.addEventListener('dragover', function (e) { e.preventDefault(); });
        this.grid.addEventListener('drop', function (e) {
            e.preventDefault();
            var t = e.target.closest('.dy-thumb'); if (!t || self.dragIdx === null) return;
            self.move(self.dragIdx, parseInt(t.dataset.i, 10)); self.dragIdx = null;
        });
    };

    Manager.prototype.upload = function (files) {
        var self = this;
        if (!files || !files.length) return;
        var fd = new FormData();
        fd.append('csrf', window.DY_CSRF);
        var n = 0;
        for (var i = 0; i < files.length; i++) {
            if (/^image\/(png|jpe?g|gif|webp)$/i.test(files[i].type) || /\.(png|jpe?g|gif|webp)$/i.test(files[i].name)) {
                fd.append('images[]', files[i]); n++;
            }
        }
        if (!n) { Company.toast('Formats acceptés : PNG, JPG, JPEG, GIF, WEBP.', 'warning'); return; }

        var drop = this.root.querySelector('[data-img-drop] span');
        var old = drop ? drop.innerHTML : '';
        if (drop) drop.innerHTML = '<span class="dy-uploading"><i class="fas fa-spinner fa-spin"></i> Envoi…</span>';

        // X-Requested-With : indispensable pour que la garde de licence globale
        // (config.php) traite la requête comme de l'AJAX et ne renvoie pas la
        // page HTML « MDT desactive » à la place du JSON.
        fetch(window.DY_UPLOAD_URL, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) {
                // Le corps peut ne pas être du JSON (500 Apache brut) : on le lit
                // en texte d'abord pour donner un message exploitable.
                return r.text().then(function (t) {
                    try { return JSON.parse(t); }
                    catch (e) { return { ok: false, error: 'Réponse serveur invalide (HTTP ' + r.status + ').' }; }
                });
            })
            .then(function (j) {
                if (j.ok && j.data && j.data.urls) {
                    self.add(j.data.urls);
                    if (j.data.refuses && j.data.refuses.length) Company.toast(j.data.refuses.join(' · '), 'warning');
                    else Company.toast(j.data.urls.length + ' image(s) importée(s).', 'success');
                } else {
                    Company.toast(j.error || 'Échec de l\'import.', 'error');
                }
            })
            .catch(function () { Company.toast('Le serveur n\'a pas répondu.', 'error'); })
            .finally(function () { if (drop) drop.innerHTML = old; });
    };

    return {
        mount: function (root) { return root ? new Manager(root) : null; }
    };
})();

/* Un gestionnaire par modale (créer / modifier). */
window.__imgCreate = null; window.__imgEdit = null;
document.addEventListener('DOMContentLoaded', function () {
    var c = document.querySelector('#createModal [data-imgmgr]');
    var e = document.querySelector('#editModal [data-imgmgr]');
    if (c) window.__imgCreate = DynImages.mount(c);
    if (e) window.__imgEdit   = DynImages.mount(e);
});
</script>
<?php endif; ?>

<script>
/* ── Équipements ── */
function toggleEquip(el) {
    el.classList.toggle('on');
    const vals = [...document.querySelectorAll('#equipTags .equip-tag.on')].map(e=>e.dataset.val);
    document.getElementById('equipInput').value = JSON.stringify(vals);
}
function toggleEquipEdit(el) {
    el.classList.toggle('on');
    const vals = [...document.querySelectorAll('#editEquipTags .equip-tag.on')].map(e=>e.dataset.val);
    document.getElementById('editEquipInput').value = JSON.stringify(vals);
}

/* ── Quartiers (tags catalogue) ── */
function toggleQuartier(el, containerId, inputId) {
    el.classList.toggle('on');
    const ids = [...document.querySelectorAll('#'+containerId+' .quartier-tag.on')].map(e=>parseInt(e.dataset.id,10));
    const inp = document.getElementById(inputId);
    if (inp) inp.value = JSON.stringify(ids);
}
function setQuartiers(containerId, inputId, ids) {
    ids = Array.isArray(ids) ? ids.map(Number) : [];
    document.querySelectorAll('#'+containerId+' .quartier-tag').forEach(tag=>{
        tag.classList.toggle('on', ids.includes(parseInt(tag.dataset.id,10)));
    });
    const inp = document.getElementById(inputId);
    if (inp) inp.value = JSON.stringify(ids);
}

/* ── Ouvrir créer ── */
function openCreate() {
    if (window.__imgCreate) window.__imgCreate.setList([]);
    if (document.getElementById('quartierTags')) setQuartiers('quartierTags','quartierInput',[]);
    Company.openModal('createModal');
}

/* ── Pré-remplir édition ── */
function editBien(b) {
    const f = {id:'e_id',type_bien:'e_type',titre:'e_titre',adresse:'e_adresse',quartier:'e_quartier',
               statut:'e_statut',description:'e_desc',surface:'e_surface',pieces:'e_pieces',
               chambres:'e_chambres',salles_bain:'e_sdb',etage:'e_etage',
               prix_vente:'e_pv',commission_vente_pct:'e_cv',
               prix_location_mois:'e_pm',prix_location_semaine:'e_ps',
               charges_mois:'e_ch',caution_mois:'e_ca',commission_location_pct:'e_cl'};
    for(const [k,id] of Object.entries(f)) {
        const el = document.getElementById(id);
        if(el) el.value = b[k]||'';
    }
    document.getElementById('e_agent').value = b.agent_id||'';
    const zoneSel = document.getElementById('e_zone_id');
    if (zoneSel) zoneSel.value = b.zone_id || '';
    // Images
    if (window.__imgEdit) {
        let imgs=[]; try{ imgs=JSON.parse(b.images||'[]'); }catch(e){}
        window.__imgEdit.setList(Array.isArray(imgs)?imgs:[]);
    }
    // Quartiers (tags)
    if (document.getElementById('editQuartierTags')) {
        let cats=[]; try{ cats=JSON.parse(b.categorie_ids||'[]'); }catch(e){}
        setQuartiers('editQuartierTags','editQuartierInput',Array.isArray(cats)?cats:[]);
    }
    // Recalc TTC after fill
    calcTTC('e_pv','e_pv_ttc'); calcTTC('e_ps','e_ps_ttc');

    // Équipements
    let eq=[]; try{ eq=JSON.parse(b.equipements||'[]'); }catch(e){}
    document.querySelectorAll('#editEquipTags .equip-tag').forEach(tag=>{
        const on = eq.includes(tag.dataset.val);
        tag.classList.toggle('on',on);
    });
    document.getElementById('editEquipInput').value = JSON.stringify(eq);
    Company.openModal('editModal');
}

/* ── Supprimer ── */
async function delBien(id, titre) {
    const C = window.Company || window.Pearl;
    if (!await C.confirm({ message: `Supprimer "${titre}" ?\n\nAttention : irréversible.`, tone: 'danger', title: 'Suppression' })) return;
    const f=document.createElement('form'); f.method='POST';
    f.innerHTML=`<input type="hidden" name="action" value="delete_bien"><input type="hidden" name="bien_id" value="${id}">`;
    document.body.appendChild(f); f.submit();
}

function calcTTC(inputId, displayId) {
    const val = parseFloat(document.getElementById(inputId)?.value) || 0;
    const el  = document.getElementById(displayId);
    if (el) el.textContent = val > 0 ? '$' + (val * 1.10).toLocaleString('fr-CA', {minimumFractionDigits:0, maximumFractionDigits:0}) : '—';
}

// Auto-ouvrir si ?open=new
if(new URLSearchParams(location.search).get('open')==='new') {
    <?php if($canManage): ?>openCreate();<?php endif; ?>
}
</script>

<!-- Menus déroulants personnalisés (cohérence avec le reste du module Dynasty) -->
<script src="<?php echo SITE_URL; ?>/assets/js/dynasty/dynasty-ui.js?v=<?php echo @filemtime(ROOT_PATH . '/assets/js/dynasty/dynasty-ui.js') ?: '1'; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.DynastyUI) DynastyUI.enhance(document);
});
// Après pré-remplissage de l'édition, resynchroniser les menus custom.
(function () {
    var _edit = window.editBien;
    if (typeof _edit === 'function') {
        window.editBien = function (b) {
            _edit(b);
            if (window.DynastyUI) ['e_type','e_agent','e_statut'].forEach(function (id) {
                DynastyUI.refresh(document.getElementById(id));
            });
        };
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>