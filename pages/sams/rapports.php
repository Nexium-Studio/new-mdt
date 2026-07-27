<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');

$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';
$uid = (int)$_SESSION['user_id'];

/** La colonne existe-t-elle dans sams_reports ? (civils/véhicules optionnels). */
function srColExists(PDO $db, string $col): bool {
    try { $db->query("SELECT `$col` FROM sams_reports LIMIT 0"); return true; } catch (Exception $e) { return false; }
}
$hasCivCol = srColExists($db, 'civilians_involved');
$hasVehCol = srColExists($db, 'vehicles_involved');
// Colonne data (signes vitaux + prescription + notes) — migration idempotente.
try { $db->exec("ALTER TABLE `sams_reports` ADD COLUMN IF NOT EXISTS `data` LONGTEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
$hasDataCol = srColExists($db, 'data');

// ─── ACTIONS POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        try {
            $tags    = array_values(array_filter(array_map('trim', $_POST['tags'] ?? [])));
            $doctors = array_values(array_filter(array_map('intval', $_POST['doctors'] ?? [])));
            if (empty($doctors)) $doctors = [$uid];
            $civ = array_values(array_filter(array_map('intval', $_POST['civilians'] ?? [])));
            $veh = array_values(array_filter(array_map('intval', $_POST['vehicles'] ?? [])));
            $patientId = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null;

            // Nouvelles sections : signes vitaux, prescription, notes
            $vitals = [
                'tension'     => trim($_POST['vital_tension'] ?? ''),
                'pouls'       => trim($_POST['vital_pouls'] ?? ''),
                'temperature' => trim($_POST['vital_temp'] ?? ''),
            ];
            $prescription = trim($_POST['prescription'] ?? '');
            $notes        = trim($_POST['notes'] ?? '');
            $reportDataJson = json_encode(['vitals'=>$vitals,'prescription'=>$prescription,'notes'=>$notes], JSON_UNESCAPED_UNICODE);

            if ($action === 'create') {
                $cols = ['type','title','tags','doctors_involved','patient_id','description_rich','created_by'];
                $vals = [$_POST['type'], trim($_POST['title']), json_encode($tags), json_encode($doctors), $patientId, trim($_POST['description'] ?? ''), $uid];
                if ($hasCivCol) { $cols[]='civilians_involved'; $vals[]=json_encode($civ); }
                if ($hasVehCol) { $cols[]='vehicles_involved'; $vals[]=json_encode($veh); }
                if ($hasDataCol){ $cols[]='data'; $vals[]=$reportDataJson; }
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $db->prepare("INSERT INTO sams_reports (".implode(',',$cols).") VALUES ($ph)")->execute($vals);
                $rid = (int)$db->lastInsertId();
                $msg = 'Rapport #'.$rid.' créé avec succès.'; $msgType = 'success';
            } else {
                $rid = intval($_POST['report_id']);
                $sets = ['type=?','title=?','tags=?','doctors_involved=?','patient_id=?','description_rich=?'];
                $vals = [$_POST['type'], trim($_POST['title']), json_encode($tags), json_encode($doctors), $patientId, trim($_POST['description'] ?? '')];
                if ($hasCivCol) { $sets[]='civilians_involved=?'; $vals[]=json_encode($civ); }
                if ($hasVehCol) { $sets[]='vehicles_involved=?'; $vals[]=json_encode($veh); }
                if ($hasDataCol){ $sets[]='data=?'; $vals[]=$reportDataJson; }
                $vals[] = $rid;
                $db->prepare("UPDATE sams_reports SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
                $msg = 'Rapport mis à jour.'; $msgType = 'success';
            }

            // Génération / régénération du document HTML (logo SAMS via le helper centralisé)
            $patientName = '';
            if ($patientId) { $pr = sams_patient_row($db, (int)$patientId); $patientName = trim(($pr['firstname']??'').' '.($pr['lastname']??'')); }
            $rTitle = trim($_POST['title']); $rType = (string)($_POST['type'] ?? ''); $rDesc = trim($_POST['description'] ?? '');
            $content  = '<div class="doc-section"><div class="doc-section-title">Informations du patient</div><div class="doc-grid2">';
            $content .= '<div><div class="doc-field-label">Nom complet</div><div class="doc-field-value">'.htmlspecialchars($patientName !== '' ? $patientName : '—').'</div></div>';
            $content .= '<div><div class="doc-field-label">Type de rapport</div><div class="doc-field-value">'.htmlspecialchars(ucfirst($rType)).'</div></div>';
            $content .= '</div></div>';
            if ($rDesc !== '') $content .= '<div class="doc-section"><div class="doc-section-title">Description de l\'incident</div><p>'.nl2br(htmlspecialchars($rDesc)).'</p></div>';
            if ($vitals['tension']!==''||$vitals['pouls']!==''||$vitals['temperature']!=='') {
                $content .= '<div class="doc-section"><div class="doc-section-title">Signes vitaux</div><div class="doc-vitals">';
                $content .= '<div class="doc-vital v-blue"><div class="vl">Tension artérielle</div><div class="vv">'.htmlspecialchars($vitals['tension'] !== '' ? $vitals['tension'] : '—').'</div><div class="vu">mmHg</div></div>';
                $content .= '<div class="doc-vital v-red"><div class="vl">Pouls</div><div class="vv">'.htmlspecialchars($vitals['pouls'] !== '' ? $vitals['pouls'] : '—').'</div><div class="vu">bpm</div></div>';
                $content .= '<div class="doc-vital v-amber"><div class="vl">Température</div><div class="vv">'.htmlspecialchars($vitals['temperature'] !== '' ? $vitals['temperature'] : '—').'</div><div class="vu">°C</div></div>';
                $content .= '</div></div>';
            }
            if ($prescription !== '') $content .= '<div class="doc-section"><div class="doc-section-title">Prescription / Traitement</div><div class="doc-letter">'.nl2br(htmlspecialchars($prescription)).'</div></div>';
            if ($notes !== '')        $content .= '<div class="doc-section"><div class="doc-section-title">Notes complémentaires</div><p>'.nl2br(htmlspecialchars($notes)).'</p></div>';
            // $msg est échappé (htmlspecialchars) sur cette page → pas de lien dans le message ;
            // le document est accessible via le bouton des cartes et la modale « voir ».
            if (!empty($rid)) {
                $rRef = 'RPT-'.str_pad((string)$rid,4,'0',STR_PAD_LEFT);
                $rDoc = sams_doctor_name($db,$uid);
                $meta = [
                    'layout'         => 'banner',
                    'doc_label'      => 'Rapport Médical',
                    'reference'      => $rRef,
                    'date'           => date('d/m/Y'),
                    'badge'          => $rType !== '' ? ucfirst($rType) : '',
                    'subbar'         => [
                        ['label'=>'Réf. document','value'=>$rRef],
                        ['label'=>'Établi le','value'=>date('d/m/Y')],
                        ['label'=>'Médecin','value'=>$rDoc !== '' ? $rDoc : '—'],
                    ],
                    'signatory_name' => $rDoc,
                    'signatory_sub'  => 'SAMS — Médecin certificateur',
                    'foot_note'      => 'Rapport médical officiel établi par un professionnel de santé agréé du SAMS · '.$rRef,
                ];
                sams_generate_document($db,'sams_reports',(int)$rid,"Rapport médical — ".$rTitle,$content,'rapport',$meta);
            }
        } catch (Exception $e) { $msg = 'Erreur: '.$e->getMessage(); $msgType = 'error'; }
    }

    if ($action === 'delete') {
        $rid = intval($_POST['report_id'] ?? 0);
        $db->prepare("DELETE FROM sams_reports WHERE id=?")->execute([$rid]);
        $msg = 'Rapport supprimé.'; $msgType = 'success';
    }
}

// ─── FILTRES & PAGINATION ───────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$typeF   = $_GET['type'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = "(sr.title LIKE ? OR CONCAT(p.firstname,' ',p.lastname) LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($typeF) { $where[] = "sr.type = ?"; $params[] = $typeF; }
$whereStr = 'WHERE ' . implode(' AND ', $where);

$total = 0;
try {
    $cStmt = $db->prepare("SELECT COUNT(*) FROM sams_reports sr LEFT JOIN profiles p ON p.id=sr.patient_id $whereStr");
    $cStmt->execute($params); $total = (int)$cStmt->fetchColumn();
} catch (Exception $e) {}
$totalPages = max(1, (int)ceil($total / $perPage));

$reports = [];
try {
    // creator_name = Nom + Prénom du profil (jamais le pseudo)
    $rStmt = $db->prepare("SELECT sr.*, CONCAT(p.firstname,' ',p.lastname) AS patient_name,
            cp.firstname AS creator_fn, cp.lastname AS creator_ln
        FROM sams_reports sr
        LEFT JOIN profiles p ON p.id=sr.patient_id
        LEFT JOIN users u ON u.id=sr.created_by
        LEFT JOIN profiles cp ON cp.id=u.profile_id
        $whereStr ORDER BY sr.created_at DESC LIMIT $perPage OFFSET $offset");
    $rStmt->execute($params); $reports = $rStmt->fetchAll();
} catch (Exception $e) {}

// ─── Données pour les sélecteurs (SamsPicker) ───────────────────────────────
$medecinsPicker = [];
try {
    foreach ($db->query("SELECT u.id,u.avatar,u.faction_grade,pr.firstname,pr.lastname FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id WHERE u.faction='SAMS' AND u.is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $n = mdt_rp_display_name($m['firstname'] ?? null, $m['lastname'] ?? null, '');
        if ($n === '' || $n === '—') $n = 'Profil non renseigné';
        $medecinsPicker[] = ['id'=>(int)$m['id'],'name'=>$n,'sub'=>$m['faction_grade'] ?? '','avatar'=>$m['avatar'] ?? null];
    }
} catch (Exception $e) {}

$civilsPicker = [];
try {
    foreach ($db->query("SELECT id,firstname,lastname,phone,avatar FROM profiles ORDER BY lastname LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $civilsPicker[] = ['id'=>(int)$p['id'],'name'=>trim($p['firstname'].' '.$p['lastname']),'sub'=>$p['phone'] ?? '','avatar'=>$p['avatar'] ?? null];
    }
} catch (Exception $e) {}

$vehiclesPicker = [];
try {
    foreach ($db->query("SELECT v.id,v.model,v.plate,v.color,CONCAT(p.firstname,' ',p.lastname) AS owner FROM vehicles v LEFT JOIN profiles p ON p.id=v.owner_id ORDER BY v.id DESC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vehiclesPicker[] = ['id'=>(int)$v['id'],'name'=>trim(($v['model']??'').' — '.($v['plate']??'')),'sub'=>trim(($v['color']??'').($v['owner']?(' · '.$v['owner']):'')),'avatar'=>null];
    }
} catch (Exception $e) {}

// ─── Tags SAMS actifs (table sams_report_tags) ──────────────────────────────
$samsTags = [];
try {
    $samsTags = $db->query("SELECT label,color FROM sams_report_tags WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Types de rapports SAMS (cahier des charges §9)
$reportTypes = ['intervention','consultation','urgence','suivi','psychiatrie','toxicologie','traumatologie','gynécologie','autre'];
$typeIcons = ['urgence'=>'truck-medical','intervention'=>'ambulance','consultation'=>'stethoscope','suivi'=>'clipboard','psychiatrie'=>'brain','toxicologie'=>'biohazard','traumatologie'=>'bone','gynécologie'=>'venus','autre'=>'file-medical'];

$pageTitle = 'Rapports médicaux';
include __DIR__ . '/includes/header.php';
?>
<style>
.report-type-btn { display:inline-flex; align-items:center; gap:.45rem; padding:.45rem .9rem; border-radius:7px;
  border:1.5px solid var(--bdr); background:transparent; color:var(--txt-m); cursor:pointer; font-size:.78rem; font-weight:600;
  font-family:'Barlow',sans-serif; transition:all .15s; text-transform:capitalize; }
.report-type-btn:hover { border-color:var(--red); color:var(--red-l); background:rgba(225,29,72,.06); }
.report-type-btn.active { border-color:var(--red); color:var(--red-l); background:rgba(225,29,72,.1); box-shadow:0 0 0 1px rgba(225,29,72,.2); }
.report-card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; padding:1rem 1.2rem; transition:all .18s; position:relative; overflow:hidden; }
.report-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--rc,var(--red)); opacity:.6; }
.report-card:hover { border-color:var(--bdr-h); background:var(--bg-e); }
.report-card[data-type="urgence"],.report-card[data-type="intervention"] { --rc:var(--red); }
.report-card[data-type="consultation"] { --rc:var(--cyan); }
.report-card[data-type="suivi"] { --rc:var(--green); }
.report-card[data-type="psychiatrie"] { --rc:var(--purple); }
/* tag selectable (custom, pas de checkbox) */
.tag-opt { display:inline-flex; align-items:center; gap:.35rem; padding:.28rem .7rem; border-radius:20px; cursor:pointer;
  font-size:.72rem; font-weight:700; border:1.5px solid; transition:all .15s; opacity:.55; }
.tag-opt.sel { opacity:1; box-shadow:0 0 0 1px currentColor inset; }
.tag-opt i { font-size:.55rem; }
/* picker chips */
.pk-chips { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.5rem; }
.pk-chip { display:inline-flex; align-items:center; gap:.45rem; padding:.3rem .65rem; border-radius:20px; font-size:.74rem; font-weight:600;
  background:rgba(225,29,72,.1); border:1px solid rgba(225,29,72,.28); color:var(--red-l); }
.pk-chip .x { cursor:pointer; opacity:.7; } .pk-chip .x:hover { opacity:1; }
.pick-btn { justify-content:center; border-style:dashed!important; }
/* Corps du formulaire : hauteur fixe + scrollbar personnalisée (charte SAMS) */
#modal-create .modal-bd{max-height:60vh;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--red,#e11d48) rgba(255,255,255,.05);}
#modal-create .modal-bd::-webkit-scrollbar{width:10px;}
#modal-create .modal-bd::-webkit-scrollbar-track{background:rgba(255,255,255,.03);border-radius:10px;margin:4px 0;}
#modal-create .modal-bd::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--red,#e11d48),var(--red-l,#f43f5e));border-radius:10px;border:2px solid transparent;background-clip:padding-box;}
#modal-create .modal-bd::-webkit-scrollbar-thumb:hover{background:var(--red-l,#f43f5e);}
</style>

<div class="page-hdr">
    <div class="page-hdr-left">
        <div class="breadcrumb"><a href="dashboard.php"><i class="fas fa-home"></i></a><span class="breadcrumb-sep">/</span><span>Rapports médicaux</span></div>
        <h1><i class="fas fa-file-medical"></i> Rapports médicaux</h1>
        <div class="page-hdr-sub"><?php echo number_format($total); ?> rapport(s)</div>
    </div>
    <div class="page-hdr-right">
        <button class="btn btn-primary" onclick="openCreate()"><i class="fas fa-plus"></i> Rédiger un rapport</button>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'circle-xmark'; ?>"></i><?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="card" style="padding:.85rem 1.1rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <div class="search-wrap" style="flex:1;min-width:220px;max-width:none">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="search-q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Recherche rapport, patient..." onkeydown="if(event.key==='Enter')applyFilter()">
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <button class="report-type-btn <?php echo !$typeF ? 'active' : ''; ?>" onclick="setType('')"><i class="fas fa-layer-group"></i> Tous</button>
            <?php foreach ($reportTypes as $rt): ?>
            <button class="report-type-btn <?php echo $typeF===$rt ? 'active' : ''; ?>" data-t="<?php echo $rt; ?>" onclick="setType('<?php echo $rt; ?>')"><?php echo ucfirst($rt); ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (empty($reports)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-file-medical empty-icon"></i><p>Aucun rapport trouvé</p>
    <button class="btn btn-secondary btn-sm" style="margin-top:1rem;" onclick="openCreate()"><i class="fas fa-plus"></i> Créer le premier</button></div></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.65rem;">
<?php foreach ($reports as $r):
    $tags = json_decode($r['tags'] ?? '[]', true) ?: [];
    $typeColors = ['urgence'=>'badge-red','intervention'=>'badge-red','consultation'=>'badge-cyan','suivi'=>'badge-green','psychiatrie'=>'badge-purple'];
    $tc = $typeColors[$r['type'] ?? ''] ?? 'badge-gray';
    $creator = mdt_rp_display_name($r['creator_fn'] ?? null, $r['creator_ln'] ?? null, ''); if($creator===''||$creator==='—') $creator='—';
?>
<div class="report-card" data-type="<?php echo htmlspecialchars($r['type'] ?? ''); ?>">
    <div style="display:flex;align-items:flex-start;gap:.9rem;">
        <div style="width:38px;height:38px;border-radius:9px;background:rgba(225,29,72,.08);border:1px solid var(--bdr);display:grid;place-items:center;flex-shrink:0;color:var(--red-l);font-size:.9rem;">
            <i class="fas fa-<?php echo $typeIcons[$r['type'] ?? ''] ?? 'file-medical'; ?>"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem;flex-wrap:wrap;">
                <span class="mono" style="font-size:.7rem;color:var(--txt-m);">#<?php echo $r['id']; ?></span>
                <span class="badge <?php echo $tc; ?>"><?php echo htmlspecialchars($r['type'] ?? '—'); ?></span>
                <span style="font-size:.85rem;font-weight:600;color:var(--txt);"><?php echo htmlspecialchars($r['title'] ?? '—'); ?></span>
            </div>
            <div style="font-size:.75rem;color:var(--txt-s);margin-bottom:.4rem;">
                <?php if ($r['patient_name'] && trim($r['patient_name'])!==''): ?>
                    <i class="fas fa-user-injured" style="margin-right:.3rem;color:var(--txt-m);"></i><?php echo htmlspecialchars($r['patient_name']); ?> &nbsp;·&nbsp;
                <?php endif; ?>
                <i class="fas fa-user-doctor" style="margin-right:.3rem;color:var(--txt-m);"></i><?php echo htmlspecialchars($creator); ?> &nbsp;·&nbsp;
                <i class="fas fa-clock" style="margin-right:.3rem;color:var(--txt-m);"></i><span class="mono"><?php echo date('d/m/Y à H:i', strtotime($r['created_at'])); ?></span>
            </div>
            <?php if (!empty($tags)): ?>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                <?php foreach ($tags as $tag): ?><span class="badge badge-gray" style="font-size:.6rem"><i class="fas fa-tag" style="font-size:.5rem"></i> <?php echo htmlspecialchars($tag); ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0;">
            <?php if(!empty($r['google_docs_url'])): ?><a href="<?php echo htmlspecialchars($r['google_docs_url']); ?>" target="_blank" class="btn btn-ghost btn-xs" title="Document HTML"><i class="fas fa-file-lines"></i></a><?php endif; ?>
            <button class="btn btn-ghost btn-xs" onclick="viewReport(<?php echo $r['id']; ?>)" title="Voir"><i class="fas fa-eye"></i></button>
            <button class="btn btn-secondary btn-xs" onclick='editReport(<?php echo htmlspecialchars(json_encode([
                "id"=>(int)$r["id"],"type"=>$r["type"],"title"=>$r["title"],"description"=>$r["description_rich"],
                "patient_id"=>$r["patient_id"]?(int)$r["patient_id"]:null,
                "data"=>$r["data"]??null,
                "tags"=>json_decode($r["tags"]??"[]",true)?:[],
                "doctors"=>json_decode($r["doctors_involved"]??"[]",true)?:[],
                "civilians"=>$hasCivCol?(json_decode($r["civilians_involved"]??"[]",true)?:[]):[],
                "vehicles"=>$hasVehCol?(json_decode($r["vehicles_involved"]??"[]",true)?:[]):[],
            ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)' title="Modifier"><i class="fas fa-pen"></i></button>
            <form method="POST" data-confirm data-confirm-title="Supprimer le rapport"
                  data-confirm-msg="Cette action est <strong>irréversible</strong>."
                  data-confirm-target="Rapport #<?php echo $r['id']; ?>" data-confirm-ok="Supprimer" style="display:inline;">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="btn btn-danger btn-xs" title="Supprimer"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="tbl-wrap" style="margin-top:.8rem;"><div class="pagination">
    <span class="page-info">Page <?php echo $page; ?> / <?php echo $totalPages; ?> · <?php echo $total; ?> rapport(s)</span>
    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeF); ?>" class="page-btn">←</a><?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeF); ?>" class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeF); ?>" class="page-btn">→</a><?php endif; ?>
</div></div>
<?php endif; ?>
<?php endif; ?>

<!-- ══ MODAL CRÉER / MODIFIER ══ -->
<div class="modal-ov hidden" id="modal-create">
    <div class="modal modal-lg">
        <div class="modal-hd"><h2><i class="fas fa-plus-circle"></i> <span id="cr-modal-title">Nouveau rapport médical</span></h2>
            <button class="modal-close" onclick="closeModal('modal-create')"><i class="fas fa-times"></i></button></div>
        <form method="POST" id="form-create">
            <div class="modal-bd">
                <input type="hidden" name="action" id="cr-action" value="create">
                <input type="hidden" name="report_id" id="cr-report-id" value="">
                <input type="hidden" name="patient_id" id="cr-patient-id" value="">

                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="form-label required"><i class="fas fa-tag"></i> Type de rapport</label>
                    <input type="hidden" name="type" id="cr-type" required>
                    <div style="display:flex;gap:.45rem;flex-wrap:wrap;" id="type-btns">
                        <?php foreach ($reportTypes as $rt): ?>
                        <button type="button" class="report-type-btn" data-t="<?php echo $rt; ?>" onclick="selectType('<?php echo $rt; ?>')">
                            <i class="fas fa-<?php echo $typeIcons[$rt] ?? 'file-medical'; ?>"></i> <?php echo ucfirst($rt); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group form-full" style="margin-bottom:.85rem">
                    <label class="form-label required"><i class="fas fa-heading"></i> Titre du rapport</label>
                    <input type="text" name="title" id="cr-title" class="form-input" placeholder="Ex: Prise en charge traumatisme crânien" required>
                </div>

                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fas fa-user-injured"></i> Patient</label>
                    <button type="button" class="btn btn-secondary btn-sm pick-btn" style="width:100%" onclick="pickPatient()"><i class="fas fa-user-plus"></i> Sélectionner un patient</button>
                    <div class="pk-chips" id="patient-chip"></div>
                </div>

                <!-- Tags depuis l'administration (sams_report_tags) -->
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fas fa-tags"></i> Tags</label>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap" id="tag-opts">
                        <?php if (empty($samsTags)): ?>
                            <span style="font-size:.75rem;color:var(--txt-m)">Aucun tag actif — configurez-les dans Staff › Contenu › SAMS › Tag SAMS.</span>
                        <?php else: foreach ($samsTags as $t):
                            $c = htmlspecialchars($t['color']); $l = htmlspecialchars($t['label']);
                            $rr=hexdec(substr($t['color'],1,2));$gg=hexdec(substr($t['color'],3,2));$bb=hexdec(substr($t['color'],5,2));
                        ?>
                        <span class="tag-opt" data-tag="<?php echo $l; ?>" style="color:<?php echo $c; ?>;border-color:rgba(<?php echo "$rr,$gg,$bb";?>,.45);background:rgba(<?php echo "$rr,$gg,$bb";?>,.08)" onclick="toggleTag(this)">
                            <i class="fas fa-tag"></i><?php echo $l; ?>
                        </span>
                        <?php endforeach; endif; ?>
                    </div>
                    <div id="tag-inputs"></div>
                </div>

                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label required"><i class="fas fa-notes-medical"></i> Description / Observations</label>
                    <textarea name="description" id="cr-description" class="form-textarea" rows="5" placeholder="Observations cliniques, actes effectués..." required style="min-height:110px;"></textarea>
                </div>

                <!-- Signes vitaux (optionnel) -->
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fas fa-heart-pulse"></i> Signes vitaux <span style="color:var(--txt-m);font-weight:400">(optionnel)</span></label>
                    <div class="form-grid-3">
                        <input type="text" name="vital_tension" id="cr-vital-tension" class="form-input" placeholder="Tension (ex: 120/80)">
                        <input type="text" name="vital_pouls" id="cr-vital-pouls" class="form-input" placeholder="Pouls BPM (ex: 72)">
                        <input type="text" name="vital_temp" id="cr-vital-temp" class="form-input" placeholder="Temp. °C (ex: 37,5)">
                    </div>
                </div>

                <!-- Prescription -->
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fas fa-prescription"></i> Prescription</label>
                    <textarea name="prescription" id="cr-prescription" class="form-textarea" rows="3" placeholder="Traitement prescrit, posologie..."></textarea>
                </div>

                <!-- Notes complémentaires (optionnel) -->
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label"><i class="fas fa-note-sticky"></i> Notes complémentaires <span style="color:var(--txt-m);font-weight:400">(optionnel)</span></label>
                    <textarea name="notes" id="cr-notes" class="form-textarea" rows="3" placeholder="Informations complémentaires..."></textarea>
                </div>

                <!-- Sélections avancées (SamsPicker) -->
                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user-doctor"></i> Médecins impliqués</label>
                        <button type="button" class="btn btn-secondary btn-sm pick-btn" style="width:100%" onclick="openPicker('doctors')"><i class="fas fa-plus"></i> Ajouter</button>
                        <div class="pk-chips" id="chips-doctors"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-users"></i> Civils impliqués</label>
                        <button type="button" class="btn btn-secondary btn-sm pick-btn" style="width:100%" onclick="openPicker('civilians')"><i class="fas fa-plus"></i> Ajouter</button>
                        <div class="pk-chips" id="chips-civilians"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-car"></i> Véhicules impliqués</label>
                        <button type="button" class="btn btn-secondary btn-sm pick-btn" style="width:100%" onclick="openPicker('vehicles')"><i class="fas fa-plus"></i> Ajouter</button>
                        <div class="pk-chips" id="chips-vehicles"></div>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-create')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le rapport</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODAL VIEW ══ -->
<div class="modal-ov hidden" id="modal-view-report">
    <div class="modal modal-lg">
        <div class="modal-hd"><h2><i class="fas fa-file-medical"></i> Rapport <span class="mono" id="rp-num" style="color:var(--red-l);font-size:.85rem;"></span></h2>
            <button class="modal-close" onclick="closeModal('modal-view-report')"><i class="fas fa-times"></i></button></div>
        <div class="modal-bd">
            <div style="display:flex;gap:.6rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
                <span class="badge badge-red" id="rp-type"></span>
                <span style="font-size:.95rem;font-weight:700;color:#fff;" id="rp-title"></span>
            </div>
            <div class="grid2" style="gap:.75rem;margin-bottom:1rem;">
                <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:8px;padding:.75rem .9rem;"><div class="form-label" style="margin-bottom:.3rem;">Patient</div><div style="font-size:.84rem;font-weight:600;" id="rp-patient"></div></div>
                <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:8px;padding:.75rem .9rem;"><div class="form-label" style="margin-bottom:.3rem;">Rédacteur</div><div style="font-size:.84rem;font-weight:600;" id="rp-medecin"></div></div>
            </div>
            <div style="background:var(--bg-e);border:1px solid var(--bdr);border-radius:9px;padding:1rem;line-height:1.65;font-size:.83rem;white-space:pre-wrap" id="rp-body"></div>
            <div id="rp-extra" style="margin-top:.75rem;display:flex;flex-direction:column;gap:.5rem"></div>
            <div style="margin-top:.75rem;font-size:.68rem;color:var(--txt-m);" class="mono" id="rp-date"></div>
        </div>
        <div class="modal-ft"><button class="btn btn-ghost btn-sm" onclick="closeModal('modal-view-report')">Fermer</button></div>
    </div>
</div>

<?php include __DIR__ . '/includes/components.php'; ?>
<script>
const PK_DATA = {
    doctors:   <?php echo json_encode($medecinsPicker, JSON_UNESCAPED_UNICODE); ?>,
    civilians: <?php echo json_encode($civilsPicker, JSON_UNESCAPED_UNICODE); ?>,
    vehicles:  <?php echo json_encode($vehiclesPicker, JSON_UNESCAPED_UNICODE); ?>
};
const PK_NAME  = { doctors:'doctors', civilians:'civilians', vehicles:'vehicles' };
const PK_TITLE = { doctors:'Médecins impliqués', civilians:'Civils impliqués', vehicles:'Véhicules impliqués' };
const PK_SEL   = { doctors:new Set(), civilians:new Set(), vehicles:new Set() };
const MY_UID   = <?php echo $uid; ?>;
let PATIENT_SEL = null;

function escapeHtmlR(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

/* ── Filtres ── */
function applyFilter(){const q=document.getElementById('search-q').value;window.location.href=`rapports.php?q=${encodeURIComponent(q)}&type=${encodeURIComponent('<?php echo addslashes($typeF); ?>')}`;}
function setType(t){const q=document.getElementById('search-q').value;window.location.href=`rapports.php?q=${encodeURIComponent(q)}&type=${encodeURIComponent(t)}`;}

/* ── Type dans le formulaire ── */
function selectType(t){document.getElementById('cr-type').value=t;document.querySelectorAll('#type-btns .report-type-btn').forEach(b=>b.classList.toggle('active',b.dataset.t===t));}

/* ── Tags ── */
function toggleTag(el){el.classList.toggle('sel');syncTagInputs();}
function syncTagInputs(){const box=document.getElementById('tag-inputs');box.innerHTML='';document.querySelectorAll('#tag-opts .tag-opt.sel').forEach(el=>{const i=document.createElement('input');i.type='hidden';i.name='tags[]';i.value=el.dataset.tag;box.appendChild(i);});}

/* ── Patient (mono) ── */
function pickPatient(){SamsPicker.open({title:'Sélection du patient',items:PK_DATA.civilians,onConfirm:function(it){if(!it)return;PATIENT_SEL=it;document.getElementById('cr-patient-id').value=it.id;renderPatientChip();}});}
function clearPatient(){PATIENT_SEL=null;document.getElementById('cr-patient-id').value='';renderPatientChip();}
function renderPatientChip(){const c=document.getElementById('patient-chip');if(!PATIENT_SEL){c.innerHTML='';return;}c.innerHTML='<span class="pk-chip">'+escapeHtmlR(PATIENT_SEL.name)+'<span class="x" onclick="clearPatient()"><i class="fas fa-times"></i></span></span>';}

/* ── Pickers multi (médecins / civils / véhicules) ── */
function openPicker(key){SamsPicker.open({title:PK_TITLE[key],items:PK_DATA[key],multiple:true,selected:[...PK_SEL[key]],onConfirm:function(arr){PK_SEL[key]=new Set(arr.map(x=>String(x.id)));renderPicker(key);}});}
function removePick(key,id){PK_SEL[key].delete(String(id));renderPicker(key);}
function renderPicker(key){
    const chips=document.getElementById('chips-'+key);chips.innerHTML='';
    PK_SEL[key].forEach(id=>{
        const it=PK_DATA[key].find(x=>String(x.id)===String(id));if(!it)return;
        chips.insertAdjacentHTML('beforeend','<span class="pk-chip">'+escapeHtmlR(it.name)+'<span class="x" onclick="removePick(\''+key+'\','+id+')"><i class="fas fa-times"></i></span><input type="hidden" name="'+PK_NAME[key]+'[]" value="'+id+'"></span>');
    });
}

/* ── Ouvrir création ── */
function openCreate(){
    document.getElementById('cr-action').value='create';
    document.getElementById('cr-report-id').value='';
    document.getElementById('cr-modal-title').textContent='Nouveau rapport médical';
    document.getElementById('cr-type').value='';
    document.getElementById('cr-title').value='';
    document.getElementById('cr-description').value='';
    document.getElementById('cr-vital-tension').value='';
    document.getElementById('cr-vital-pouls').value='';
    document.getElementById('cr-vital-temp').value='';
    document.getElementById('cr-prescription').value='';
    document.getElementById('cr-notes').value='';
    document.querySelectorAll('#type-btns .report-type-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#tag-opts .tag-opt').forEach(el=>el.classList.remove('sel'));
    syncTagInputs();
    clearPatient();
    PK_SEL.doctors=new Set([String(MY_UID)]); PK_SEL.civilians=new Set(); PK_SEL.vehicles=new Set();
    renderPicker('doctors');renderPicker('civilians');renderPicker('vehicles');
    openModal('modal-create');
}

/* ── Ouvrir édition ── */
function editReport(r){
    document.getElementById('cr-action').value='edit';
    document.getElementById('cr-report-id').value=r.id;
    document.getElementById('cr-modal-title').textContent='Modifier le rapport #'+r.id;
    selectType(r.type||'');
    document.getElementById('cr-title').value=r.title||'';
    document.getElementById('cr-description').value=r.description||'';
    // signes vitaux / prescription / notes
    let _d={}; try{ _d=JSON.parse(r.data||'{}')||{}; }catch(e){ _d={}; }
    const _v=_d.vitals||{};
    document.getElementById('cr-vital-tension').value=_v.tension||'';
    document.getElementById('cr-vital-pouls').value=_v.pouls||'';
    document.getElementById('cr-vital-temp').value=_v.temperature||'';
    document.getElementById('cr-prescription').value=_d.prescription||'';
    document.getElementById('cr-notes').value=_d.notes||'';
    // tags
    const set=new Set((r.tags||[]).map(String));
    document.querySelectorAll('#tag-opts .tag-opt').forEach(el=>el.classList.toggle('sel',set.has(el.dataset.tag)));
    syncTagInputs();
    // patient
    if(r.patient_id){const it=PK_DATA.civilians.find(x=>String(x.id)===String(r.patient_id));PATIENT_SEL=it||{id:r.patient_id,name:'Patient #'+r.patient_id};document.getElementById('cr-patient-id').value=r.patient_id;}else{PATIENT_SEL=null;document.getElementById('cr-patient-id').value='';}
    renderPatientChip();
    PK_SEL.doctors=new Set((r.doctors||[]).map(String));
    PK_SEL.civilians=new Set((r.civilians||[]).map(String));
    PK_SEL.vehicles=new Set((r.vehicles||[]).map(String));
    renderPicker('doctors');renderPicker('civilians');renderPicker('vehicles');
    openModal('modal-create');
}

/* ── Voir ── */
function viewReport(id){
    fetch(`get_report.php?id=${id}`).then(r=>r.json()).then(data=>{
        if(!data.success){showToast('Rapport introuvable','error');return;}
        const r=data.report;
        document.getElementById('rp-num').textContent='#'+r.id;
        document.getElementById('rp-type').textContent=r.type||'—';
        document.getElementById('rp-title').textContent=r.title||'—';
        document.getElementById('rp-patient').textContent=(r.patient_name&&r.patient_name.trim())?r.patient_name:'—';
        document.getElementById('rp-medecin').textContent=r.creator_name||'—';
        document.getElementById('rp-date').textContent=new Date(r.created_at).toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
        document.getElementById('rp-body').textContent=r.description_rich||'Aucune description';
        const ex=document.getElementById('rp-extra');ex.innerHTML='';
        const blk=(label,arr,icon)=>{if(!arr||!arr.length)return;ex.insertAdjacentHTML('beforeend','<div><div class="form-label" style="margin-bottom:.3rem"><i class="fas '+icon+'"></i> '+label+'</div><div style="display:flex;gap:.3rem;flex-wrap:wrap">'+arr.map(n=>'<span class="badge badge-gray">'+escapeHtmlR(n)+'</span>').join('')+'</div></div>');};
        blk('Médecins',r.doctors_names,'fa-user-doctor');
        blk('Civils',r.civilians_names,'fa-users');
        blk('Véhicules',r.vehicles_names,'fa-car');
        const tags=(()=>{try{return JSON.parse(r.tags||'[]')}catch(e){return[]}})();
        blk('Tags',tags,'fa-tags');
        // Signes vitaux / prescription / notes
        let dd={}; try{ dd=JSON.parse(r.data||'{}')||{}; }catch(e){ dd={}; }
        const vv=dd.vitals||{};
        if(vv.tension||vv.pouls||vv.temperature){
            const parts=[vv.tension?'Tension '+vv.tension:'',vv.pouls?'Pouls '+vv.pouls+' BPM':'',vv.temperature?vv.temperature+' °C':''].filter(Boolean);
            ex.insertAdjacentHTML('beforeend','<div><div class="form-label" style="margin-bottom:.3rem"><i class="fas fa-heart-pulse"></i> Signes vitaux</div><div style="display:flex;gap:.3rem;flex-wrap:wrap">'+parts.map(n=>'<span class="badge badge-gray">'+escapeHtmlR(n)+'</span>').join('')+'</div></div>');
        }
        if(dd.prescription){ ex.insertAdjacentHTML('beforeend','<div><div class="form-label" style="margin-bottom:.3rem"><i class="fas fa-prescription"></i> Prescription</div><div style="white-space:pre-wrap;font-size:.82rem;color:var(--txt-s)">'+escapeHtmlR(dd.prescription)+'</div></div>'); }
        if(dd.notes){ ex.insertAdjacentHTML('beforeend','<div><div class="form-label" style="margin-bottom:.3rem"><i class="fas fa-note-sticky"></i> Notes complémentaires</div><div style="white-space:pre-wrap;font-size:.82rem;color:var(--txt-s)">'+escapeHtmlR(dd.notes)+'</div></div>'); }
        if(r.google_docs_url){ ex.insertAdjacentHTML('beforeend','<div style="margin-top:.4rem"><a href="'+escapeHtmlR(r.google_docs_url)+'" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> Voir le document</a></div>'); }
        openModal('modal-view-report');
    });
}

document.getElementById('form-create').addEventListener('submit',function(e){
    if(!document.getElementById('cr-type').value){e.preventDefault();showToast('Veuillez sélectionner un type de rapport','error');}
});
<?php if ($msg): ?>setTimeout(()=>showToast(<?php echo json_encode($msg); ?>,'<?php echo $msgType; ?>'),300);<?php endif; ?>
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
