<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $endDate = null;
            if (!empty($_POST['start_date']) && !empty($_POST['duration_months']) && $_POST['duration_months'] > 0) {
                $start = new DateTime($_POST['start_date']);
                $start->add(new DateInterval('P' . intval($_POST['duration_months']) . 'M'));
                $endDate = $start->format('Y-m-d');
            }
            $stmt = $db->prepare("
                INSERT INTO sentences (case_id, citizen_id, sentence_type, duration_months, duration_hours, fine_amount, conditions_rich, start_date, end_date, status, judge_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['case_id'], $_POST['citizen_id'], $_POST['sentence_type'],
                $_POST['duration_months'] ?? 0, $_POST['duration_hours'] ?? 0,
                $_POST['fine_amount'] ?? 0, $_POST['conditions'],
                $_POST['start_date'] ?: null, $endDate,
                $_POST['judge_id'] ?: null, $_POST['notes'] ?? '', $_SESSION['user_id']
            ]);
            $message = 'Sentence créée avec succès';
            $messageType = 'success';
        } catch (PDOException $e) { $message = 'Erreur: ' . $e->getMessage(); $messageType = 'error'; }
    }
    if ($_POST['action'] === 'update_status' && isset($_POST['sentence_id'], $_POST['new_status'])) {
        try { $db->prepare("UPDATE sentences SET status=? WHERE id=?")->execute([$_POST['new_status'],$_POST['sentence_id']]); $message='Statut mis à jour'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
    if ($_POST['action'] === 'reduce' && isset($_POST['sentence_id'], $_POST['reduction'])) {
        try { $db->prepare("UPDATE sentences SET reduction_months=reduction_months+? WHERE id=?")->execute([intval($_POST['reduction']),$_POST['sentence_id']]); $message='Réduction appliquée'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
}

$statusFilter=$_GET['status']??''; $typeFilter=$_GET['type']??''; $search=$_GET['q']??'';
$where="WHERE 1=1"; $params=[];
if($statusFilter){$where.=" AND s.status=?";$params[]=$statusFilter;}
if($typeFilter){$where.=" AND s.sentence_type=?";$params[]=$typeFilter;}
if($search){$where.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR cc.case_number LIKE ?)";$s="%$search%";$params=array_merge($params,[$s,$s,$s]);}

$stmt=$db->prepare("
    SELECT s.*, p.firstname, p.lastname, cc.case_number, cc.case_title, u.username as judge_name
    FROM sentences s JOIN profiles p ON s.citizen_id=p.id
    LEFT JOIN court_cases cc ON s.case_id=cc.id LEFT JOIN users u ON s.judge_id=u.id
    $where ORDER BY s.created_at DESC LIMIT 200
");
$stmt->execute($params); $sentences=$stmt->fetchAll();
$totalSentences=count($sentences);
$activeCount=$db->query("SELECT COUNT(*) FROM sentences WHERE status='active'")->fetchColumn();
$pendingCount=$db->query("SELECT COUNT(*) FROM sentences WHERE status='pending'")->fetchColumn();
$totalFines=$db->query("SELECT COALESCE(SUM(fine_amount),0) FROM sentences WHERE status IN ('active','completed')")->fetchColumn();
try { $citizens=$db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname")->fetchAll(); }
catch(PDOException $e){ $citizens=$db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname")->fetchAll(); }
$courtCases=$db->query("SELECT id, case_number, case_title, defendant_id FROM court_cases WHERE status='concluded' AND verdict='guilty' ORDER BY created_at DESC")->fetchAll();
try { $dojMembers=$db->query("SELECT id, username, avatar FROM users WHERE faction='DOJ' ORDER BY username")->fetchAll(); } catch(PDOException $e){ $dojMembers=[]; }

$pageTitle = 'Sentences';
include __DIR__ . '/includes/header.php';
?>

<style>.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }</style>

<?php if ($message): ?>
    <div class="doj-alert doj-alert-<?php echo $messageType; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $message; ?></div>
<?php endif; ?>

<div class="doj-stats">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-handcuffs"></i></div><div class="stat-value"><?php echo $totalSentences; ?></div><div class="stat-label">Total</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-value"><?php echo $pendingCount; ?></div><div class="stat-label">En attente</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-bolt"></i></div><div class="stat-value"><?php echo $activeCount; ?></div><div class="stat-label">En exécution</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div><div class="stat-value">$<?php echo number_format($totalFines,0,',',' '); ?></div><div class="stat-label">Amendes</div></div>
</div>

<div class="card">
    <div class="card-header">
        <button class="btn btn-primary" onclick="DOJ.openModal('addModal')"><i class="fas fa-plus"></i> Nouvelle Sentence</button>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select name="status" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>En attente</option>
                <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
                <option value="completed" <?php echo $statusFilter==='completed'?'selected':''; ?>>Complétée</option>
                <option value="violated" <?php echo $statusFilter==='violated'?'selected':''; ?>>Violée</option>
                <option value="commuted" <?php echo $statusFilter==='commuted'?'selected':''; ?>>Commuée</option>
            </select>
            <select name="type" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <option value="prison" <?php echo $typeFilter==='prison'?'selected':''; ?>>Prison</option>
                <option value="probation" <?php echo $typeFilter==='probation'?'selected':''; ?>>Probation</option>
                <option value="community_service" <?php echo $typeFilter==='community_service'?'selected':''; ?>>Travaux</option>
                <option value="fine" <?php echo $typeFilter==='fine'?'selected':''; ?>>Amende</option>
                <option value="suspended" <?php echo $typeFilter==='suspended'?'selected':''; ?>>Sursis</option>
            </select>
            <div class="doj-search-bar" style="max-width:260px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($sentences)): ?>
            <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucune sentence trouvée</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Citoyen</th><th>Dossier</th><th>Type</th><th>Durée</th><th>Amende</th><th>Réduction</th><th>Début</th><th>Fin</th><th>Statut</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($sentences as $s): ?>
                        <?php
                        $typeL=['prison'=>'Prison','probation'=>'Probation','community_service'=>'Travaux','fine'=>'Amende','suspended'=>'Sursis'];
                        $typeIc=['prison'=>'lock','probation'=>'eye','community_service'=>'hands-helping','fine'=>'dollar-sign','suspended'=>'pause-circle'];
                        $statusL=['pending'=>'En attente','active'=>'Active','completed'=>'Complétée','violated'=>'Violée','commuted'=>'Commuée'];
                        $statusC=['pending'=>'status-pending','active'=>'status-active','completed'=>'status-executed','violated'=>'status-cancelled','commuted'=>'status-dismissed'];
                        $durStr=$s['sentence_type']==='community_service'?$s['duration_hours'].'h':($s['sentence_type']==='fine'?'—':$s['duration_months'].' mois');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($s['firstname'].' '.$s['lastname']); ?></strong></td>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:#3b82f6;"><?php echo htmlspecialchars($s['case_number']??'N/A'); ?></span></td>
                            <td><span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.2);border-radius:6px;font-size:0.75rem;font-weight:700;color:#60a5fa;text-transform:uppercase;"><i class="fas fa-<?php echo $typeIc[$s['sentence_type']]??'gavel'; ?>"></i> <?php echo $typeL[$s['sentence_type']]??$s['sentence_type']; ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace;"><?php echo $durStr; ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;color:#fbbf24;"><?php echo $s['fine_amount']>0?'$'.number_format($s['fine_amount'],0,',',' '):'—'; ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;color:#22c55e;"><?php echo $s['reduction_months']>0?'-'.$s['reduction_months'].'m':'—'; ?></td>
                            <td style="font-size:0.82rem;"><?php echo $s['start_date']?date('d/m/Y',strtotime($s['start_date'])):'—'; ?></td>
                            <td style="font-size:0.82rem;"><?php echo $s['end_date']?date('d/m/Y',strtotime($s['end_date'])):'—'; ?></td>
                            <td><span class="status-badge-doj <?php echo $statusC[$s['status']]??''; ?>"><?php echo $statusL[$s['status']]??$s['status']; ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-secondary btn-sm" onclick="viewSentence(this)" data-sentence="<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES,'UTF-8'); ?>"><i class="fas fa-eye"></i></button>
                                    <?php if ($s['status']==='pending'): ?>
                                        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="update_status"><input type="hidden" name="sentence_id" value="<?php echo $s['id']; ?>"><input type="hidden" name="new_status" value="active"><button type="submit" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.3);"><i class="fas fa-play"></i></button></form>
                                    <?php elseif ($s['status']==='active'): ?>
                                        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="update_status"><input type="hidden" name="sentence_id" value="<?php echo $s['id']; ?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.3);"><i class="fas fa-check"></i></button></form>
                                        <button class="btn btn-sm" style="background:rgba(245,158,11,0.1);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);" onclick="openReduction(<?php echo $s['id']; ?>)"><i class="fas fa-minus-circle"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Ajouter -->
<div id="addModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-handcuffs"></i> Nouvelle Sentence</h2><button class="modal-close" onclick="DOJ.closeModal('addModal')">×</button></div>
<form method="POST" onsubmit="return saveEditorContent('condEditor','conditions')"><input type="hidden" name="action" value="add"><div class="modal-body"><div class="form-grid">
    <div class="form-group"><label class="form-label">Dossier (Procès) *</label><select name="case_id" class="form-select" required id="caseSelect" onchange="fillCaseDefendant()"><option value="">Sélectionner</option><?php foreach($courtCases as $cc): ?><option value="<?php echo $cc['id']; ?>" data-defendant="<?php echo $cc['defendant_id']; ?>"><?php echo htmlspecialchars($cc['case_number'].' - '.mb_substr($cc['case_title'],0,40)); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Citoyen *</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="sentCitizenBtn"><i class="fas fa-user-plus"></i> Sélectionner le citoyen</button>
            <span id="sentCitizenChip"></span>
        </div>
        <input type="hidden" name="citizen_id" id="sentCitizenId">
    </div>
    <div class="form-group"><label class="form-label">Type de Peine *</label><select name="sentence_type" class="form-select" required><option value="prison">Prison</option><option value="probation">Probation</option><option value="community_service">Travaux d'intérêt général</option><option value="fine">Amende seule</option><option value="suspended">Sursis</option></select></div>
    <div class="form-group"><label class="form-label">Juge <span style="opacity:.5;font-weight:400;text-transform:none;letter-spacing:0;">(DOJ)</span></label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="sentJudgeBtn"><i class="fas fa-gavel"></i> Assigner un juge</button>
            <span id="sentJudgeChip"></span>
        </div>
        <input type="hidden" name="judge_id" id="sentJudgeId">
    </div>
    <div class="form-group"><label class="form-label">Durée (mois)</label><input type="number" name="duration_months" class="form-input" value="0" min="0"></div>
    <div class="form-group"><label class="form-label">Heures (travaux)</label><input type="number" name="duration_hours" class="form-input" value="0" min="0"></div>
    <div class="form-group"><label class="form-label">Amende ($)</label><input type="number" name="fine_amount" class="form-input" value="0" min="0" step="100"></div>
    <div class="form-group"><label class="form-label">Date de début</label><input type="date" name="start_date" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Conditions</label><div class="editor-toolbar"><button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button><button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button><button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button><button type="button" class="editor-btn" onclick="insertList()">Liste</button></div><div id="condEditor" class="rich-editor" contenteditable="true" placeholder="Conditions de la peine..."></div><input type="hidden" name="conditions" id="conditions"></div>
    <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Notes..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer la Sentence</button></div></form></div></div>

<!-- Modal Réduction -->
<div id="reduceModal" class="modal-overlay hidden"><div class="modal" style="max-width:500px;"><div class="modal-header"><h2><i class="fas fa-minus-circle"></i> Réduction de peine</h2><button class="modal-close" onclick="DOJ.closeModal('reduceModal')">×</button></div>
<form method="POST"><input type="hidden" name="action" value="reduce"><input type="hidden" name="sentence_id" id="reduceSentenceId"><div class="modal-body"><div class="form-group"><label class="form-label">Réduction (mois) *</label><input type="number" name="reduction" class="form-input" required min="1" placeholder="Ex: 3"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('reduceModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Appliquer</button></div></form></div></div>

<!-- Modal Détails -->
<div id="viewModal" class="modal-overlay hidden"><div class="modal"><div class="modal-header"><h2><i class="fas fa-handcuffs"></i> Détails Sentence</h2><button class="modal-close" onclick="DOJ.closeModal('viewModal')">×</button></div><div class="modal-body" id="viewContent"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="DOJ.closeModal('viewModal')">Fermer</button></div></div></div>

<script>
// Sélecteurs avancés (citoyen + juge DOJ) — même système que proces.php
var DOJ_SENT_CITIZENS = <?php echo json_encode(array_map(function($c){
    return ['id'=>$c['id'], 'name'=>trim($c['firstname'].' '.$c['lastname']), 'sub'=>'ID #'.$c['id'], 'avatar'=>$c['avatar'] ?? ''];
}, $citizens), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var DOJ_SENT_MEMBERS = <?php echo json_encode(array_map(function($u){
    return ['id'=>$u['id'], 'name'=>$u['username'], 'sub'=>'Membre DOJ', 'avatar'=>$u['avatar'] ?? ''];
}, $dojMembers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var sentCitizenField = null;
document.addEventListener('DOMContentLoaded', function(){
    sentCitizenField = dojPersonField({ button:'sentCitizenBtn', hidden:'sentCitizenId', chip:'sentCitizenChip',
        title:'Sélection du citoyen', items: DOJ_SENT_CITIZENS });
    dojPersonField({ button:'sentJudgeBtn', hidden:'sentJudgeId', chip:'sentJudgeChip',
        title:'Sélection du juge (DOJ)', items: DOJ_SENT_MEMBERS, required:false });
});
function fillCaseDefendant(){const o=document.getElementById('caseSelect').selectedOptions[0];if(o&&o.dataset.defendant&&sentCitizenField)sentCitizenField.setById(o.dataset.defendant);}
function openReduction(id){document.getElementById('reduceSentenceId').value=id;DOJ.openModal('reduceModal');}
function viewSentence(btn){const s=JSON.parse(btn.dataset.sentence);const tl={prison:'Prison',probation:'Probation',community_service:'Travaux',fine:'Amende',suspended:'Sursis'},sl={pending:'En attente',active:'Active',completed:'Complétée',violated:'Violée',commuted:'Commuée'},sc={pending:'status-pending',active:'status-active',completed:'status-executed',violated:'status-cancelled',commuted:'status-dismissed'};let dur=s.sentence_type==='community_service'?s.duration_hours+'h':(s.sentence_type==='fine'?'—':s.duration_months+' mois');let h=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;"><div class="form-group"><div class="form-label">Citoyen</div><div style="color:#f1f5f9;font-weight:700;font-size:1.05rem;">${s.firstname} ${s.lastname}</div></div><div class="form-group"><div class="form-label">Statut</div><div><span class="status-badge-doj ${sc[s.status]||''}">${sl[s.status]||s.status}</span></div></div><div class="form-group"><div class="form-label">Dossier</div><div style="color:#3b82f6;font-family:'JetBrains Mono',monospace;">${s.case_number||'N/A'}</div></div><div class="form-group"><div class="form-label">Type</div><div>${tl[s.sentence_type]||s.sentence_type}</div></div><div class="form-group"><div class="form-label">Durée</div><div style="font-family:'JetBrains Mono',monospace;">${dur}</div></div><div class="form-group"><div class="form-label">Amende</div><div style="color:#fbbf24;font-family:'JetBrains Mono',monospace;font-weight:700;">${s.fine_amount>0?'$'+Number(s.fine_amount).toLocaleString():'—'}</div></div><div class="form-group"><div class="form-label">Réduction</div><div style="color:#22c55e;font-family:'JetBrains Mono',monospace;">${s.reduction_months>0?'-'+s.reduction_months+' mois':'Aucune'}</div></div><div class="form-group"><div class="form-label">Juge</div><div>${s.judge_name||'N/A'}</div></div><div class="form-group"><div class="form-label">Début</div><div>${s.start_date?new Date(s.start_date).toLocaleDateString('fr-FR'):'—'}</div></div><div class="form-group"><div class="form-label">Fin</div><div>${s.end_date?new Date(s.end_date).toLocaleDateString('fr-FR'):'—'}</div></div></div>`;if(s.conditions_rich)h+=`<div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid rgba(59,130,246,0.1);"><div class="form-label" style="margin-bottom:0.75rem;">Conditions</div><div style="color:#94a3b8;line-height:1.7;">${s.conditions_rich}</div></div>`;document.getElementById('viewContent').innerHTML=h;DOJ.openModal('viewModal');}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>