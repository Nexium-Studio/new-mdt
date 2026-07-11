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
            $caseNumber = 'CC-' . date('Y') . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $charges = [];
            if (!empty($_POST['charges_text'])) {
                $charges = array_map('trim', explode(',', $_POST['charges_text']));
            }
            $stmt = $db->prepare("
                INSERT INTO court_cases (case_number, case_title, case_type, defendant_id, plaintiff_id, charges, description_rich, prosecutor_id, defense_attorney_id, judge_id, hearing_date, status, priority, court_notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'filed', ?, ?, ?)
            ");
            $stmt->execute([
                $caseNumber, $_POST['case_title'], $_POST['case_type'], $_POST['defendant_id'],
                $_POST['plaintiff_id'] ?: null, json_encode($charges), $_POST['description'],
                $_POST['prosecutor_id'] ?: null, $_POST['defense_attorney_id'] ?: null,
                $_POST['judge_id'] ?: null, $_POST['hearing_date'] ?: null,
                $_POST['priority'], $_POST['court_notes'] ?? '', $_SESSION['user_id']
            ]);
            $message = "Procès <strong>$caseNumber</strong> créé avec succès";
            $messageType = 'success';
        } catch (PDOException $e) { $message = 'Erreur: ' . $e->getMessage(); $messageType = 'error'; }
    }
    if ($_POST['action'] === 'update_status' && isset($_POST['case_id'], $_POST['new_status'])) {
        try { $db->prepare("UPDATE court_cases SET status=? WHERE id=?")->execute([$_POST['new_status'],$_POST['case_id']]); $message='Statut mis à jour'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
    if ($_POST['action'] === 'set_verdict' && isset($_POST['case_id'], $_POST['verdict'])) {
        try { $db->prepare("UPDATE court_cases SET verdict=?, verdict_date=NOW(), verdict_details=?, status='concluded' WHERE id=?")->execute([$_POST['verdict'],$_POST['verdict_details']??'',$_POST['case_id']]); $message='Verdict enregistré'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
}

$statusFilter=$_GET['status']??''; $typeFilter=$_GET['type']??''; $search=$_GET['q']??'';
$where="WHERE 1=1"; $params=[];
if($statusFilter){$where.=" AND cc.status=?";$params[]=$statusFilter;}
if($typeFilter){$where.=" AND cc.case_type=?";$params[]=$typeFilter;}
if($search){$where.=" AND (p.firstname LIKE ? OR p.lastname LIKE ? OR cc.case_number LIKE ? OR cc.case_title LIKE ?)";$s="%$search%";$params=array_merge($params,[$s,$s,$s,$s]);}

$stmt=$db->prepare("
    SELECT cc.*, p.firstname, p.lastname, j.username as judge_name, pr.username as prosecutor_name, da.username as defense_name
    FROM court_cases cc JOIN profiles p ON cc.defendant_id=p.id
    LEFT JOIN users j ON cc.judge_id=j.id LEFT JOIN users pr ON cc.prosecutor_id=pr.id LEFT JOIN users da ON cc.defense_attorney_id=da.id
    $where ORDER BY cc.created_at DESC LIMIT 200
");
$stmt->execute($params); $cases=$stmt->fetchAll();
$totalCases=count($cases);
$pendingCount=$db->query("SELECT COUNT(*) FROM court_cases WHERE status IN ('filed','scheduled','in_progress')")->fetchColumn();
$concludedCount=$db->query("SELECT COUNT(*) FROM court_cases WHERE status='concluded'")->fetchColumn();
try { $citizens=$db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname")->fetchAll(); }
catch(PDOException $e){ $citizens=$db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname")->fetchAll(); }
// Membres de faction pour juge / procureur (DOJ) et avocat défense (AVOCATS)
try { $dojMembers=$db->query("SELECT id, username, avatar FROM users WHERE faction='DOJ' ORDER BY username")->fetchAll(); } catch(PDOException $e){ $dojMembers=[]; }
try { $avocatMembers=$db->query("SELECT id, username, avatar FROM users WHERE faction='AVOCATS' ORDER BY username")->fetchAll(); } catch(PDOException $e){ $avocatMembers=[]; }

$pageTitle = 'Procès';
include __DIR__ . '/includes/header.php';
?>

<style>.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }</style>

<?php if ($message): ?>
    <div class="doj-alert doj-alert-<?php echo $messageType; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $message; ?></div>
<?php endif; ?>

<div class="doj-stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-landmark"></i></div><div class="stat-value"><?php echo $totalCases; ?></div><div class="stat-label">Total Procès</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-value"><?php echo $pendingCount; ?></div><div class="stat-label">En cours</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-value"><?php echo $concludedCount; ?></div><div class="stat-label">Conclus</div></div>
</div>

<div class="card">
    <div class="card-header">
        <button class="btn btn-primary" onclick="DOJ.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Procès</button>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select name="status" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <option value="filed" <?php echo $statusFilter==='filed'?'selected':''; ?>>Déposé</option>
                <option value="scheduled" <?php echo $statusFilter==='scheduled'?'selected':''; ?>>Planifié</option>
                <option value="in_progress" <?php echo $statusFilter==='in_progress'?'selected':''; ?>>En cours</option>
                <option value="concluded" <?php echo $statusFilter==='concluded'?'selected':''; ?>>Conclu</option>
                <option value="appealed" <?php echo $statusFilter==='appealed'?'selected':''; ?>>En appel</option>
            </select>
            <select name="type" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <option value="criminal" <?php echo $typeFilter==='criminal'?'selected':''; ?>>Pénal</option>
                <option value="civil" <?php echo $typeFilter==='civil'?'selected':''; ?>>Civil</option>
                <option value="administrative" <?php echo $typeFilter==='administrative'?'selected':''; ?>>Administratif</option>
            </select>
            <div class="doj-search-bar" style="max-width:260px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($cases)): ?>
            <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucun procès trouvé</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Dossier</th><th>Titre</th><th>Accusé</th><th>Type</th><th>Juge</th><th>Audience</th><th>Priorité</th><th>Verdict</th><th>Statut</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($cases as $c): ?>
                        <?php
                        $typeL=['criminal'=>'Pénal','civil'=>'Civil','administrative'=>'Admin.'];
                        $statusL=['filed'=>'Déposé','scheduled'=>'Planifié','in_progress'=>'En cours','concluded'=>'Conclu','appealed'=>'En appel'];
                        $statusC=['filed'=>'status-pending','scheduled'=>'status-active','in_progress'=>'status-active','concluded'=>'status-executed','appealed'=>'status-appealed'];
                        $verdictL=['pending'=>'En attente','guilty'=>'Coupable','not_guilty'=>'Non coupable','dismissed'=>'Classé','settled'=>'Réglé'];
                        $verdictColors=['guilty'=>'#ef4444','not_guilty'=>'#22c55e','dismissed'=>'#64748b','settled'=>'#f59e0b','pending'=>'#94a3b8'];
                        $prioC=['low'=>'severity-low','normal'=>'severity-medium','high'=>'severity-high','urgent'=>'severity-critical'];
                        $prioL=['low'=>'Faible','normal'=>'Normal','high'=>'Haute','urgent'=>'Urgente'];
                        ?>
                        <tr>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:#3b82f6;"><?php echo htmlspecialchars($c['case_number']); ?></span></td>
                            <td><strong style="font-size:0.88rem;"><?php echo htmlspecialchars(mb_substr($c['case_title'],0,35)); ?></strong></td>
                            <td><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']); ?></td>
                            <td style="font-size:0.8rem;"><?php echo $typeL[$c['case_type']]??$c['case_type']; ?></td>
                            <td style="font-size:0.82rem;"><?php echo $c['judge_name']?htmlspecialchars($c['judge_name']):'<span style="color:#475569">—</span>'; ?></td>
                            <td style="font-size:0.82rem;"><?php echo $c['hearing_date']?date('d/m/Y H:i',strtotime($c['hearing_date'])):'<span style="color:#475569">—</span>'; ?></td>
                            <td><span class="severity-badge <?php echo $prioC[$c['priority']]??''; ?>"><?php echo $prioL[$c['priority']]??$c['priority']; ?></span></td>
                            <td><span style="font-weight:700;font-size:0.82rem;color:<?php echo $verdictColors[$c['verdict']]??'#94a3b8'; ?>"><?php echo $verdictL[$c['verdict']]??$c['verdict']; ?></span></td>
                            <td><span class="status-badge-doj <?php echo $statusC[$c['status']]??''; ?>"><?php echo $statusL[$c['status']]??$c['status']; ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-secondary btn-sm" onclick="viewCase(this)" data-case="<?php echo htmlspecialchars(json_encode($c),ENT_QUOTES,'UTF-8'); ?>"><i class="fas fa-eye"></i></button>
                                    <?php if ($c['verdict']==='pending'): ?>
                                        <button class="btn btn-sm" style="background:rgba(245,158,11,0.1);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);" onclick="openVerdict(<?php echo $c['id']; ?>)"><i class="fas fa-gavel"></i></button>
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
<div id="addModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-landmark"></i> Nouveau Procès</h2><button class="modal-close" onclick="DOJ.closeModal('addModal')">×</button></div>
<form method="POST" onsubmit="return saveEditorContent('descEditor','description')"><input type="hidden" name="action" value="add"><div class="modal-body"><div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Titre du Procès *</label><input type="text" name="case_title" class="form-input" required placeholder="Ex: État vs. John Doe - Vol à main armée"></div>
    <div class="form-group"><label class="form-label">Type *</label><select name="case_type" class="form-select" required><option value="criminal">Pénal</option><option value="civil">Civil</option><option value="administrative">Administratif</option></select></div>
    <div class="form-group"><label class="form-label">Priorité *</label><select name="priority" class="form-select" required><option value="low">Faible</option><option value="normal" selected>Normale</option><option value="high">Haute</option><option value="urgent">Urgente</option></select></div>
    <div class="form-group"><label class="form-label">Accusé *</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="defBtn"><i class="fas fa-user-plus"></i> Sélectionner l'accusé</button>
            <span id="defChip"></span>
        </div>
        <input type="hidden" name="defendant_id" id="defendant_id">
    </div>
    <div class="form-group"><label class="form-label">Plaignant</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="plBtn"><i class="fas fa-user-plus"></i> Sélectionner le plaignant</button>
            <span id="plChip"></span>
        </div>
        <input type="hidden" name="plaintiff_id" id="plaintiff_id">
    </div>
    <div class="form-group"><label class="form-label">Juge <span style="opacity:.5;font-weight:400;text-transform:none;letter-spacing:0;">(DOJ)</span></label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="judgeBtn"><i class="fas fa-gavel"></i> Assigner un juge</button>
            <span id="judgeChip"></span>
        </div>
        <input type="hidden" name="judge_id" id="judge_id">
    </div>
    <div class="form-group"><label class="form-label">Procureur <span style="opacity:.5;font-weight:400;text-transform:none;letter-spacing:0;">(DOJ)</span></label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="prosBtn"><i class="fas fa-user-tie"></i> Assigner un procureur</button>
            <span id="prosChip"></span>
        </div>
        <input type="hidden" name="prosecutor_id" id="prosecutor_id">
    </div>
    <div class="form-group"><label class="form-label">Avocat Défense <span style="opacity:.5;font-weight:400;text-transform:none;letter-spacing:0;">(Avocats)</span></label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="defAttBtn"><i class="fas fa-scale-balanced"></i> Assigner un avocat</button>
            <span id="defAttChip"></span>
        </div>
        <input type="hidden" name="defense_attorney_id" id="defense_attorney_id">
    </div>
    <div class="form-group"><label class="form-label">Date d'audience</label><input type="datetime-local" name="hearing_date" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Charges (codes, séparer par virgules)</label><input type="text" name="charges_text" class="form-input" placeholder="PC 487, PC 211, VC 23152..."></div>
    <div class="form-group form-full"><label class="form-label">Description</label><div class="editor-toolbar"><button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button><button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button><button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button><button type="button" class="editor-btn" onclick="insertList()">Liste</button></div><div id="descEditor" class="rich-editor" contenteditable="true" placeholder="Description de l'affaire..."></div><input type="hidden" name="description" id="description"></div>
    <div class="form-group form-full"><label class="form-label">Notes du tribunal</label><textarea name="court_notes" class="form-textarea" placeholder="Notes internes..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer le Procès</button></div></form></div></div>

<!-- Modal Verdict -->
<div id="verdictModal" class="modal-overlay hidden"><div class="modal"><div class="modal-header"><h2><i class="fas fa-gavel"></i> Rendre le Verdict</h2><button class="modal-close" onclick="DOJ.closeModal('verdictModal')">×</button></div>
<form method="POST"><input type="hidden" name="action" value="set_verdict"><input type="hidden" name="case_id" id="verdictCaseId"><div class="modal-body"><div class="form-grid">
    <div class="form-group form-full"><label class="form-label">Verdict *</label><select name="verdict" class="form-select" required><option value="guilty">Coupable</option><option value="not_guilty">Non Coupable</option><option value="dismissed">Classé sans suite</option><option value="settled">Règlement à l'amiable</option></select></div>
    <div class="form-group form-full"><label class="form-label">Détails du Verdict</label><textarea name="verdict_details" class="form-textarea" required placeholder="Détails, justification du verdict..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('verdictModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-gavel"></i> Rendre le Verdict</button></div></form></div></div>

<!-- Modal Détails -->
<div id="viewModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-landmark"></i> <span id="viewTitle"></span></h2><button class="modal-close" onclick="DOJ.closeModal('viewModal')">×</button></div><div class="modal-body" id="viewContent"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="DOJ.closeModal('viewModal')">Fermer</button></div></div></div>

<script>
// ── Données des sélecteurs avancés (citoyens + membres de faction) ──────────
var DOJ_PROCES_CITIZENS = <?php echo json_encode(array_map(function($c){
    return ['id'=>$c['id'], 'name'=>trim($c['firstname'].' '.$c['lastname']), 'sub'=>'ID #'.$c['id'], 'avatar'=>$c['avatar'] ?? ''];
}, $citizens), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var DOJ_MEMBERS = <?php echo json_encode(array_map(function($u){
    return ['id'=>$u['id'], 'name'=>$u['username'], 'sub'=>'Membre DOJ', 'avatar'=>$u['avatar'] ?? ''];
}, $dojMembers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
var AVOCAT_MEMBERS = <?php echo json_encode(array_map(function($u){
    return ['id'=>$u['id'], 'name'=>$u['username'], 'sub'=>'Avocat', 'avatar'=>$u['avatar'] ?? ''];
}, $avocatMembers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
document.addEventListener('DOMContentLoaded', function(){
    dojPersonField({ button:'defBtn',    hidden:'defendant_id',         chip:'defChip',    title:'Sélection de l\'accusé', items: DOJ_PROCES_CITIZENS });
    dojPersonField({ button:'plBtn',     hidden:'plaintiff_id',         chip:'plChip',     title:'Sélection du plaignant', items: DOJ_PROCES_CITIZENS, required:false });
    dojPersonField({ button:'judgeBtn',  hidden:'judge_id',             chip:'judgeChip',  title:'Sélection du juge (DOJ)', items: DOJ_MEMBERS, required:false });
    dojPersonField({ button:'prosBtn',   hidden:'prosecutor_id',        chip:'prosChip',   title:'Sélection du procureur (DOJ)', items: DOJ_MEMBERS, required:false });
    dojPersonField({ button:'defAttBtn', hidden:'defense_attorney_id',  chip:'defAttChip', title:'Sélection de l\'avocat (Avocats)', items: AVOCAT_MEMBERS, required:false });
});

function openVerdict(id){document.getElementById('verdictCaseId').value=id;DOJ.openModal('verdictModal');}
function viewCase(btn){const c=JSON.parse(btn.dataset.case);document.getElementById('viewTitle').textContent=c.case_number;const tl={criminal:'Pénal',civil:'Civil',administrative:'Administratif'},sl={filed:'Déposé',scheduled:'Planifié',in_progress:'En cours',concluded:'Conclu',appealed:'En appel'},vl={pending:'En attente',guilty:'Coupable',not_guilty:'Non coupable',dismissed:'Classé',settled:'Réglé'},vc={guilty:'#ef4444',not_guilty:'#22c55e',dismissed:'#64748b',settled:'#f59e0b',pending:'#94a3b8'},sc={filed:'status-pending',scheduled:'status-active',in_progress:'status-active',concluded:'status-executed',appealed:'status-appealed'};let charges='';try{const ch=JSON.parse(c.charges||'[]');charges=ch.join(', ');}catch(e){charges=c.charges||'';}let h=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;"><div class="form-group form-full"><div class="form-label">Titre</div><div style="color:#f1f5f9;font-size:1.1rem;font-weight:700;">${c.case_title}</div></div><div class="form-group"><div class="form-label">Accusé</div><div style="color:#f1f5f9;font-weight:700;">${c.firstname} ${c.lastname}</div></div><div class="form-group"><div class="form-label">Type</div><div>${tl[c.case_type]||c.case_type}</div></div><div class="form-group"><div class="form-label">Statut</div><div><span class="status-badge-doj ${sc[c.status]||''}">${sl[c.status]||c.status}</span></div></div><div class="form-group"><div class="form-label">Verdict</div><div style="font-weight:700;color:${vc[c.verdict]||'#94a3b8'}">${vl[c.verdict]||c.verdict}</div></div><div class="form-group"><div class="form-label">Juge</div><div>${c.judge_name||'Non assigné'}</div></div><div class="form-group"><div class="form-label">Procureur</div><div>${c.prosecutor_name||'Non assigné'}</div></div><div class="form-group"><div class="form-label">Défense</div><div>${c.defense_name||'Non assigné'}</div></div><div class="form-group"><div class="form-label">Audience</div><div>${c.hearing_date?new Date(c.hearing_date).toLocaleString('fr-FR'):'Non planifiée'}</div></div>`;if(charges)h+=`<div class="form-group form-full"><div class="form-label">Charges</div><div style="display:flex;gap:6px;flex-wrap:wrap;">${charges.split(',').map(ch=>'<span style="padding:4px 10px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:5px;font-family:JetBrains Mono,monospace;font-size:0.78rem;color:#ef4444;">'+ch.trim()+'</span>').join('')}</div></div>`;h+=`</div>`;if(c.description_rich)h+=`<div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid rgba(59,130,246,0.1);"><div class="form-label" style="margin-bottom:0.75rem;">Description</div><div style="color:#94a3b8;line-height:1.7;">${c.description_rich}</div></div>`;if(c.verdict_details)h+=`<div style="margin-top:1rem;padding:1rem;background:rgba(59,130,246,0.06);border:1px solid rgba(59,130,246,0.15);border-radius:8px;"><div class="form-label" style="color:#3b82f6;margin-bottom:0.5rem;">Détails du verdict</div><div style="color:#94a3b8;">${c.verdict_details}</div></div>`;document.getElementById('viewContent').innerHTML=h;DOJ.openModal('viewModal');}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>