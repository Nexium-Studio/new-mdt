<?php
/**
 * Rapports DOJ — module complet (création / modification / consultation)
 * pages/doj/rapports_doj.php
 *
 * Distinct du viewer SASP lecture seule (rapports.php, conservé intact).
 * Stocke les rapports dans la table `reports` avec faction='DOJ'.
 *  - Types : Intervention / Suivi / Autre
 *  - Tags  : uniquement ceux actifs de doj_report_tags
 *  - Citoyens concernés : sélecteur DojPicker (avatar + recherche)
 *  - Infractions : codes pénaux officiels (DojSelect multiple)
 *  - Synchro : si « Générer les casiers » coché, crée les entrées
 *    criminal_records (LSPD) correspondantes (citoyen × infraction).
 */
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = ''; $messageType = '';

// Colonne d'infractions DOJ (idempotent)
try { $db->exec("ALTER TABLE reports ADD COLUMN IF NOT EXISTS doj_infractions TEXT NULL"); } catch (Exception $e) {}

/** Crée les casiers LSPD (criminal_records) depuis un rapport DOJ. */
function doj_generate_casiers(PDO $db, array $citizenIds, array $codes, array $penalMap, int $userId): int {
    $created = 0;
    $ins = $db->prepare("INSERT INTO criminal_records (citizen_id, record_type, offense_code, offense_title, description_rich, severity, fine_amount, jail_time, points, officer_id, case_number, incident_date, status, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
    foreach ($citizenIds as $cid) {
        foreach ($codes as $code) {
            if (!isset($penalMap[$code])) continue;
            $pc = $penalMap[$code];
            $cat = $pc['category'] === 'traffic' ? 'infraction' : ($pc['category'] ?: 'misdemeanor');
            $caseNumber = 'CR-' . date('Y') . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            try {
                $ins->execute([
                    (int)$cid, $cat, $pc['code'], $pc['title'], '', (int)$pc['severity'],
                    (float)$pc['min_fine'], (int)$pc['min_jail_months'], (int)$pc['points'],
                    $userId, $caseNumber, date('Y-m-d H:i:s'),
                    'Généré automatiquement depuis un rapport DOJ', $userId
                ]);
                $created++;
            } catch (Exception $e) { /* ignore les doublons / erreurs individuelles */ }
        }
    }
    return $created;
}

// ── Données de référence ─────────────────────────────────────────────────────
$tags = [];
try { $tags = $db->query("SELECT label, color FROM doj_report_tags WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(); }
catch (Exception $e) { $tags = []; }

try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname")->fetchAll(); }
catch (PDOException $e) { $citizens = $db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname")->fetchAll(); }

$penalCodes = [];
try { $penalCodes = $db->query("SELECT code, title, category, severity, min_fine, min_jail_months, points FROM penal_code WHERE active=1 ORDER BY code")->fetchAll(); } catch (Exception $e) {}
$penalMap = [];
foreach ($penalCodes as $pc) $penalMap[$pc['code']] = $pc;

// ── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'add' || $act === 'update') {
        $title       = trim($_POST['title'] ?? '');
        $type        = in_array($_POST['type'] ?? '', ['intervention','suivi','autre'], true) ? $_POST['type'] : 'autre';
        $priority    = in_array($_POST['priority'] ?? '', ['low','normal','high','critical'], true) ? $_POST['priority'] : 'normal';
        $status      = in_array($_POST['status'] ?? '', ['draft','submitted','reviewing','closed'], true) ? $_POST['status'] : 'submitted';
        $location    = trim($_POST['location'] ?? '');
        $incidentAt  = $_POST['incident_at'] ?? '';
        $incidentAt  = $incidentAt ? str_replace('T', ' ', $incidentAt) . (strlen($incidentAt) === 16 ? ':00' : '') : null;
        $tagsArr     = array_values(array_filter(array_map('trim', $_POST['tags'] ?? [])));
        $civilians   = array_values(array_filter(array_map('intval', json_decode($_POST['civilians_json'] ?? '[]', true) ?: [])));
        $infractions = array_values(array_filter(array_map('trim', $_POST['infractions'] ?? [])));
        $descRich    = $_POST['description'] ?? '';
        $notes       = trim($_POST['internal_notes'] ?? '');
        $genCasiers  = !empty($_POST['gen_casiers']);

        if (!$title) {
            $message = 'Le titre du rapport est obligatoire.'; $messageType = 'error';
        } else {
            try {
                if ($act === 'add') {
                    $stmt = $db->prepare("INSERT INTO reports (type, title, faction, status, priority, location, incident_at, tags, civilians, doj_infractions, description, description_rich, internal_notes, created_by, created_at)
                                          VALUES (?, ?, 'DOJ', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$type, $title, $status, $priority, $location, $incidentAt,
                        json_encode($tagsArr, JSON_UNESCAPED_UNICODE), json_encode($civilians),
                        json_encode($infractions), strip_tags($descRich), $descRich, $notes, $_SESSION['user_id']]);
                    $rid = $db->lastInsertId();
                    $message = "Rapport DOJ #$rid créé avec succès."; $messageType = 'success';
                } else {
                    $rid = (int)($_POST['report_id'] ?? 0);
                    $stmt = $db->prepare("UPDATE reports SET type=?, title=?, status=?, priority=?, location=?, incident_at=?, tags=?, civilians=?, doj_infractions=?, description=?, description_rich=?, internal_notes=? WHERE id=? AND faction='DOJ'");
                    $stmt->execute([$type, $title, $status, $priority, $location, $incidentAt,
                        json_encode($tagsArr, JSON_UNESCAPED_UNICODE), json_encode($civilians),
                        json_encode($infractions), strip_tags($descRich), $descRich, $notes, $rid]);
                    $message = "Rapport DOJ #$rid mis à jour."; $messageType = 'success';
                }

                // Synchronisation : génération des casiers LSPD
                if ($genCasiers && $civilians && $infractions) {
                    $n = doj_generate_casiers($db, $civilians, $infractions, $penalMap, (int)$_SESSION['user_id']);
                    if ($n > 0) $message .= " $n casier(s) LSPD généré(s).";
                }
            } catch (Exception $e) {
                $message = 'Erreur : ' . $e->getMessage(); $messageType = 'error';
            }
        }
    }

    if ($act === 'delete') {
        $rid = (int)($_POST['report_id'] ?? 0);
        try { $db->prepare("DELETE FROM reports WHERE id=? AND faction='DOJ'")->execute([$rid]); $message = 'Rapport supprimé.'; $messageType = 'success'; }
        catch (Exception $e) { $message = 'Erreur lors de la suppression.'; $messageType = 'error'; }
    }
}

// ── Filtres + liste ──────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? ''); $typeF = trim($_GET['t'] ?? ''); $statusF = trim($_GET['s'] ?? '');
$where = ["r.faction='DOJ'"]; $params = [];
if ($search)  { $where[] = "(r.title LIKE ? OR u.username LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($typeF)   { $where[] = "r.type=?"; $params[] = $typeF; }
if ($statusF) { $where[] = "r.status=?"; $params[] = $statusF; }
$whereStr = implode(' AND ', $where);

$reports = [];
try {
    $stmt = $db->prepare("SELECT r.*, u.username AS auteur FROM reports r LEFT JOIN users u ON u.id=r.created_by WHERE $whereStr ORDER BY r.created_at DESC LIMIT 200");
    $stmt->execute($params); $reports = $stmt->fetchAll();
} catch (Exception $e) {}

$kTotal = 0; $kWeek = 0; $kOpen = 0;
try { $kTotal = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='DOJ'")->fetchColumn(); } catch (Exception $e) {}
try { $kWeek  = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='DOJ' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(); } catch (Exception $e) {}
try { $kOpen  = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='DOJ' AND status IN ('submitted','reviewing','draft')")->fetchColumn(); } catch (Exception $e) {}

$TYPE_LABELS   = ['intervention'=>'Intervention','suivi'=>'Suivi','autre'=>'Autre'];
$STATUS_LABELS = ['draft'=>'Brouillon','submitted'=>'Soumis','reviewing'=>'En révision','closed'=>'Clôturé'];
$STATUS_CLASS  = ['draft'=>'status-cancelled','submitted'=>'status-active','reviewing'=>'status-appealed','closed'=>'status-executed'];
$PRIO_LABELS   = ['low'=>'Faible','normal'=>'Normal','high'=>'Élevé','critical'=>'Critique'];

$pageTitle = 'Rapports DOJ';
include __DIR__ . '/includes/header.php';
?>
<style>.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.rd-chip { display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .65rem; border-radius:6px; font-size:.72rem; font-weight:700; }
.rd-picked { display:flex; flex-wrap:wrap; gap:.5rem; }
.rd-picked .dp-field-chip { cursor:default; }
</style>

<?php if ($message): ?>
<div class="doj-alert doj-alert-<?php echo $messageType; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $message; ?></div>
<?php endif; ?>

<div class="doj-stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-file-signature"></i></div><div class="stat-value"><?php echo $kTotal; ?></div><div class="stat-label">Total Rapports</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-calendar-week"></i></div><div class="stat-value"><?php echo $kWeek; ?></div><div class="stat-label">Cette semaine</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-folder-open"></i></div><div class="stat-value"><?php echo $kOpen; ?></div><div class="stat-label">En cours</div></div>
</div>

<div class="card">
    <div class="card-header">
        <button class="btn btn-primary" onclick="openCreate()"><i class="fas fa-plus"></i> Nouveau Rapport DOJ</button>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select name="t" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <?php foreach ($TYPE_LABELS as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $typeF===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
            <select name="s" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <?php foreach ($STATUS_LABELS as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $statusF===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
            </select>
            <div class="doj-search-bar" style="max-width:260px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($reports)): ?>
            <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucun rapport DOJ. Créez-en un.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>#</th><th>Type</th><th>Titre</th><th>Tags</th><th>Auteur</th><th>Priorité</th><th>Statut</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($reports as $r):
                        $rTags = json_decode($r['tags'] ?? '[]', true) ?: [];
                    ?>
                    <tr>
                        <td><span style="font-family:'JetBrains Mono',monospace;font-size:.75rem;color:#475569;">#<?php echo str_pad($r['id'],5,'0',STR_PAD_LEFT); ?></span></td>
                        <td><span class="rd-chip" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:#60a5fa;"><?php echo htmlspecialchars($TYPE_LABELS[$r['type']] ?? $r['type']); ?></span></td>
                        <td><div style="font-weight:600;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($r['title']); ?></div></td>
                        <td><div style="display:flex;gap:4px;flex-wrap:wrap;"><?php foreach (array_slice($rTags,0,3) as $tg): ?><span class="rd-chip" style="background:rgba(148,163,184,.1);border:1px solid rgba(148,163,184,.25);color:#94a3b8;"><i class="fas fa-tag" style="font-size:.55rem;opacity:.7;"></i> <?php echo htmlspecialchars($tg); ?></span><?php endforeach; ?></div></td>
                        <td style="font-size:.82rem;color:#94a3b8;"><?php echo htmlspecialchars($r['auteur'] ?? '—'); ?></td>
                        <td style="font-size:.8rem;"><?php echo $PRIO_LABELS[$r['priority']] ?? $r['priority']; ?></td>
                        <td><span class="status-badge-doj <?php echo $STATUS_CLASS[$r['status']] ?? ''; ?>"><?php echo $STATUS_LABELS[$r['status']] ?? $r['status']; ?></span></td>
                        <td style="font-size:.78rem;color:#64748b;white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="btn btn-secondary btn-sm" data-report='<?php echo htmlspecialchars(json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>' onclick="viewReport(this)"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm" style="background:rgba(59,130,246,.1);color:#60a5fa;border:1px solid rgba(59,130,246,.3);" data-report='<?php echo htmlspecialchars(json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>' onclick="openEdit(this)"><i class="fas fa-pen"></i></button>
                                <form method="POST" style="display:inline;margin:0;" data-doj-confirm="Supprimer définitivement ce rapport DOJ ?" data-doj-confirm-title="Supprimer le rapport" data-doj-confirm-ok="Supprimer" data-doj-confirm-target="#<?php echo str_pad($r['id'],5,'0',STR_PAD_LEFT); ?>">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.3);"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Créer / Modifier -->
<div id="formModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-file-signature"></i> <span id="formModalTitle">Nouveau Rapport DOJ</span></h2><button class="modal-close" onclick="DOJ.closeModal('formModal')">×</button></div>
<form method="POST" onsubmit="return saveEditorContent('rdDescEditor','rdDescription')">
<input type="hidden" name="action" id="rdAction" value="add">
<input type="hidden" name="report_id" id="rdReportId" value="">
<input type="hidden" name="civilians_json" id="rdCiviliansJson" value="[]">
<div class="modal-body"><div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre du rapport *</label><input type="text" name="title" id="rdTitle" class="form-input" required placeholder="Ex: Intervention au tribunal — affaire Doe"></div>
    <div class="form-group"><label class="form-label">Type *</label>
        <select name="type" id="rdType" class="form-select" required>
            <option value="intervention">Intervention</option>
            <option value="suivi">Suivi</option>
            <option value="autre">Autre</option>
        </select>
    </div>
    <div class="form-group"><label class="form-label">Priorité *</label>
        <select name="priority" id="rdPriority" class="form-select" required>
            <option value="low">Faible</option><option value="normal" selected>Normal</option><option value="high">Élevé</option><option value="critical">Critique</option>
        </select>
    </div>
    <div class="form-group"><label class="form-label">Statut *</label>
        <select name="status" id="rdStatus" class="form-select" required>
            <option value="draft">Brouillon</option><option value="submitted" selected>Soumis</option><option value="reviewing">En révision</option><option value="closed">Clôturé</option>
        </select>
    </div>
    <div class="form-group"><label class="form-label">Date des faits</label><input type="datetime-local" name="incident_at" id="rdIncidentAt" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Lieu</label><input type="text" name="location" id="rdLocation" class="form-input" placeholder="Lieu de l'intervention..."></div>

    <div class="form-group form-full"><label class="form-label">Tags DOJ</label>
        <select name="tags[]" id="rdTags" class="form-select" multiple data-placeholder="Sélectionner des tags...">
            <?php foreach ($tags as $tg): ?><option value="<?php echo htmlspecialchars($tg['label']); ?>" data-icon="fas fa-tag"><?php echo htmlspecialchars($tg['label']); ?></option><?php endforeach; ?>
        </select>
        <?php if (empty($tags)): ?><div class="form-hint" style="font-size:.75rem;color:#64748b;margin-top:4px;">Aucun tag actif. Configurez-les dans l'admin (Tag DOJ).</div><?php endif; ?>
    </div>

    <div class="form-group form-full"><label class="form-label">Citoyens concernés</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="rdCitizensBtn"><i class="fas fa-users"></i> Ajouter des citoyens</button>
            <div class="rd-picked" id="rdCitizensChips"></div>
        </div>
    </div>

    <div class="form-group form-full"><label class="form-label">Code pénal — Infractions</label>
        <select name="infractions[]" id="rdInfractions" class="form-select" multiple data-placeholder="Sélectionner des infractions...">
            <?php foreach ($penalCodes as $pc): ?><option value="<?php echo htmlspecialchars($pc['code']); ?>"><?php echo htmlspecialchars($pc['code'].' — '.$pc['title']); ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="form-group form-full">
        <label class="doj-switch" id="rdGenSwitch" data-target="rdGenCasiers"><span class="sw-track"></span><span class="sw-lbl">Générer automatiquement les casiers LSPD correspondants (citoyens × infractions)</span></label>
        <input type="hidden" name="gen_casiers" id="rdGenCasiers" value="">
    </div>

    <div class="form-group form-full"><label class="form-label">Description</label>
        <div class="editor-toolbar"><button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button><button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button><button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button><button type="button" class="editor-btn" onclick="insertList()">Liste</button></div>
        <div id="rdDescEditor" class="rich-editor" contenteditable="true" placeholder="Détails du rapport..."></div>
        <input type="hidden" name="description" id="rdDescription">
    </div>
    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="internal_notes" id="rdNotes" class="form-textarea" placeholder="Notes réservées au DOJ..."></textarea></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('formModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<!-- Modal Détails -->
<div id="viewModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-file-signature"></i> <span id="vrTitle">Rapport DOJ</span></h2><button class="modal-close" onclick="DOJ.closeModal('viewModal')">×</button></div><div class="modal-body" id="vrContent"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="DOJ.closeModal('viewModal')">Fermer</button></div></div></div>

<script>
var RD_CITIZENS = <?php echo json_encode(array_map(function($c){
    return ['id'=>$c['id'], 'name'=>trim($c['firstname'].' '.$c['lastname']), 'sub'=>'ID #'.$c['id'], 'avatar'=>$c['avatar'] ?? ''];
}, $citizens), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var RD_CITIZEN_MAP = {}; RD_CITIZENS.forEach(function(c){ RD_CITIZEN_MAP[c.id] = c; });
var TYPE_LABELS = <?php echo json_encode($TYPE_LABELS); ?>;
var STATUS_LABELS = <?php echo json_encode($STATUS_LABELS); ?>;
var PRIO_LABELS = <?php echo json_encode($PRIO_LABELS); ?>;

var rdSelectedCitizens = []; // tableau d'ids

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

function renderCitizenChips(){
    var box = document.getElementById('rdCitizensChips');
    box.innerHTML = rdSelectedCitizens.map(function(id){
        var c = RD_CITIZEN_MAP[id]; if(!c) return '';
        var ava = c.avatar ? '<img src="'+esc(c.avatar)+'" style="width:22px;height:22px;border-radius:50%;object-fit:cover;">'
            : '<span style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1e40af);display:inline-grid;place-items:center;font-size:.65rem;font-weight:700;color:#fff;">'+esc((c.name||'?').charAt(0).toUpperCase())+'</span>';
        return '<span class="dp-field-chip">'+ava+esc(c.name)+'<span class="x" data-id="'+esc(String(id))+'"><i class="fas fa-times"></i></span></span>';
    }).join('');
    box.querySelectorAll('.x').forEach(function(x){ x.onclick=function(){ rdSelectedCitizens = rdSelectedCitizens.filter(function(i){return String(i)!==x.dataset.id;}); syncCitizens(); }; });
    document.getElementById('rdCiviliansJson').value = JSON.stringify(rdSelectedCitizens.map(Number));
}
function syncCitizens(){ renderCitizenChips(); }

document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('rdCitizensBtn').addEventListener('click', function(){
        DojPicker.open({ title:'Citoyens concernés', multiple:true, items:RD_CITIZENS, selected:rdSelectedCitizens.map(String),
            onConfirm:function(list){ rdSelectedCitizens = list.map(function(it){return it.id;}); syncCitizens(); } });
    });
    // (le switch « générer casiers » est géré par data-target via components.php)
});

function resetForm(){
    document.getElementById('rdAction').value='add';
    document.getElementById('rdReportId').value='';
    document.getElementById('formModalTitle').textContent='Nouveau Rapport DOJ';
    document.getElementById('rdTitle').value='';
    document.getElementById('rdLocation').value='';
    document.getElementById('rdNotes').value='';
    document.getElementById('rdIncidentAt').value='';
    document.getElementById('rdDescEditor').innerHTML='';
    rdSelectedCitizens=[]; renderCitizenChips();
    ['rdType','rdPriority','rdStatus','rdTags','rdInfractions'].forEach(function(id){
        var s=document.getElementById(id);
        Array.from(s.options).forEach(function(o){o.selected=false;});
        if(s.id==='rdType'){s.value='intervention';} if(s.id==='rdPriority'){s.value='normal';} if(s.id==='rdStatus'){s.value='submitted';}
        s.dispatchEvent(new Event('change',{bubbles:true}));
    });
    document.getElementById('rdGenSwitch').classList.remove('on');
    document.getElementById('rdGenCasiers').value='';
}

function openCreate(){ resetForm(); DOJ.openModal('formModal'); }

function openEdit(btn){
    var r = JSON.parse(btn.dataset.report);
    resetForm();
    document.getElementById('rdAction').value='update';
    document.getElementById('rdReportId').value=r.id;
    document.getElementById('formModalTitle').textContent='Modifier le Rapport #'+String(r.id).padStart(5,'0');
    document.getElementById('rdTitle').value=r.title||'';
    document.getElementById('rdLocation').value=r.location||'';
    document.getElementById('rdNotes').value=r.internal_notes||'';
    document.getElementById('rdIncidentAt').value=r.incident_at?String(r.incident_at).replace(' ','T').slice(0,16):'';
    document.getElementById('rdDescEditor').innerHTML=r.description_rich||r.description||'';
    document.getElementById('rdType').value=r.type||'intervention';
    document.getElementById('rdPriority').value=r.priority||'normal';
    document.getElementById('rdStatus').value=r.status||'submitted';
    // tags
    var tags=[]; try{tags=JSON.parse(r.tags||'[]');}catch(e){}
    var tagSel=document.getElementById('rdTags'); Array.from(tagSel.options).forEach(function(o){o.selected=tags.indexOf(o.value)>=0;});
    // infractions
    var infs=[]; try{infs=JSON.parse(r.doj_infractions||'[]');}catch(e){}
    var infSel=document.getElementById('rdInfractions'); Array.from(infSel.options).forEach(function(o){o.selected=infs.indexOf(o.value)>=0;});
    // citoyens
    try{rdSelectedCitizens=(JSON.parse(r.civilians||'[]')||[]).map(Number);}catch(e){rdSelectedCitizens=[];}
    renderCitizenChips();
    ['rdType','rdPriority','rdStatus','rdTags','rdInfractions'].forEach(function(id){ document.getElementById(id).dispatchEvent(new Event('change',{bubbles:true})); });
    DOJ.openModal('formModal');
}

function viewReport(btn){
    var r = JSON.parse(btn.dataset.report);
    document.getElementById('vrTitle').textContent = '#'+String(r.id).padStart(5,'0')+' — '+(r.title||'');
    var tags=[]; try{tags=JSON.parse(r.tags||'[]');}catch(e){}
    var infs=[]; try{infs=JSON.parse(r.doj_infractions||'[]');}catch(e){}
    var civ=[]; try{civ=JSON.parse(r.civilians||'[]');}catch(e){}
    var h = '<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;">'
        + '<span class="rd-chip" style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#60a5fa;">'+esc(TYPE_LABELS[r.type]||r.type)+'</span>'
        + '<span class="rd-chip" style="background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.3);color:#94a3b8;">'+esc(STATUS_LABELS[r.status]||r.status)+'</span>'
        + '<span class="rd-chip" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#fbbf24;">'+esc(PRIO_LABELS[r.priority]||r.priority)+'</span>'
        + '</div>';
    h += '<div style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;">'+esc(r.title)+'</div>';
    h += '<div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:10px;padding:1rem;margin-bottom:1.25rem;">'
        + [['Auteur',r.auteur||'—'],['Lieu',r.location||'—'],['Date des faits',r.incident_at?new Date(String(r.incident_at).replace(' ','T')).toLocaleString('fr-FR'):'—'],['Créé le',r.created_at?new Date(String(r.created_at).replace(' ','T')).toLocaleString('fr-FR'):'—']]
            .map(function(p){return '<div style="display:grid;grid-template-columns:140px 1fr;gap:.5rem;padding:.45rem 0;border-bottom:1px solid rgba(59,130,246,.06);font-size:.82rem;"><span style="color:#475569;font-size:.75rem;">'+p[0]+'</span><span style="color:#f1f5f9;">'+esc(p[1])+'</span></div>';}).join('')
        + '</div>';
    if (tags.length) h += '<div class="rpt-section-title" style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem;"><i class="fas fa-tags"></i> Tags</div><div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">'+tags.map(function(t){return '<span class="rd-chip" style="background:rgba(148,163,184,.1);border:1px solid rgba(148,163,184,.25);color:#94a3b8;"><i class="fas fa-tag" style="font-size:.55rem;"></i> '+esc(t)+'</span>';}).join('')+'</div>';
    if (civ.length) h += '<div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem;"><i class="fas fa-users"></i> Citoyens concernés</div><div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">'+civ.map(function(id){var c=RD_CITIZEN_MAP[id];return '<span class="rd-chip" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:#60a5fa;">'+esc(c?c.name:('#'+id))+'</span>';}).join('')+'</div>';
    if (infs.length) h += '<div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#ef4444;margin-bottom:.65rem;"><i class="fas fa-scale-balanced"></i> Infractions</div><div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">'+infs.map(function(i){return '<span class="rd-chip" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#ef4444;font-family:JetBrains Mono,monospace;">'+esc(i)+'</span>';}).join('')+'</div>';
    if (r.description_rich||r.description) h += '<div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#3b82f6;margin-bottom:.65rem;"><i class="fas fa-file-lines"></i> Description</div><div style="background:rgba(15,23,42,.5);border:1px solid rgba(59,130,246,.1);border-radius:9px;padding:1rem;font-size:.85rem;line-height:1.7;color:#94a3b8;margin-bottom:1rem;">'+(r.description_rich||esc(r.description))+'</div>';
    if (r.internal_notes) h += '<div style="margin-top:.5rem;padding:1rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:8px;"><div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#f59e0b;margin-bottom:.5rem;">Notes internes</div><div style="color:#94a3b8;font-size:.85rem;">'+esc(r.internal_notes)+'</div></div>';
    document.getElementById('vrContent').innerHTML = h;
    DOJ.openModal('viewModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
