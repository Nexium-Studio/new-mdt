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
            $expDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
            $stmt = $db->prepare("
                INSERT INTO arrest_warrants (citizen_id, warrant_type, offense_code, offense_title, reason_rich, severity, bail_amount, issued_by, issued_date, expiration_date, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'active', ?, ?)
            ");
            $stmt->execute([
                $_POST['citizen_id'], $_POST['warrant_type'], $_POST['offense_code'], $_POST['offense_title'],
                $_POST['reason'], $_POST['severity'], $_POST['bail_amount'] ?? 0,
                $_SESSION['user_id'], $expDate, $_POST['notes'] ?? '', $_SESSION['user_id']
            ]);
            $message = 'Mandat émis avec succès';
            $messageType = 'success';
        } catch (PDOException $e) { $message = 'Erreur lors de la création'; $messageType = 'error'; }
    }
    if ($_POST['action'] === 'execute' && isset($_POST['warrant_id'])) {
        try { $db->prepare("UPDATE arrest_warrants SET status='executed', execution_date=NOW(), executed_by=? WHERE id=?")->execute([$_SESSION['user_id'],$_POST['warrant_id']]); $message='Mandat exécuté'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
    if ($_POST['action'] === 'cancel' && isset($_POST['warrant_id'])) {
        try { $db->prepare("UPDATE arrest_warrants SET status='cancelled' WHERE id=?")->execute([$_POST['warrant_id']]); $message='Mandat annulé'; $messageType='success'; } catch(PDOException $e){ $message='Erreur'; $messageType='error'; }
    }
}

$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$search = $_GET['q'] ?? '';
$where = "WHERE 1=1"; $params = [];
if ($statusFilter) { $where .= " AND aw.status = ?"; $params[] = $statusFilter; }
if ($typeFilter) { $where .= " AND aw.warrant_type = ?"; $params[] = $typeFilter; }
if ($search) { $where .= " AND (p.firstname LIKE ? OR p.lastname LIKE ? OR aw.offense_code LIKE ?)"; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }

$stmt = $db->prepare("
    SELECT aw.*, p.firstname, p.lastname, u.username as issuer_name, u2.username as executor_name
    FROM arrest_warrants aw JOIN profiles p ON aw.citizen_id=p.id
    LEFT JOIN users u ON aw.issued_by=u.id LEFT JOIN users u2 ON aw.executed_by=u2.id
    $where ORDER BY aw.created_at DESC LIMIT 200
");
$stmt->execute($params); $warrants = $stmt->fetchAll();
$totalWarrants = count($warrants);
$activeCount = $db->query("SELECT COUNT(*) FROM arrest_warrants WHERE status='active'")->fetchColumn();
$executedCount = $db->query("SELECT COUNT(*) FROM arrest_warrants WHERE status='executed'")->fetchColumn();
try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname")->fetchAll(); }
catch (PDOException $e) { $citizens = $db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname")->fetchAll(); }
$penalCodes = $db->query("SELECT code, title, category, severity FROM penal_code WHERE active=1 ORDER BY code")->fetchAll();

$pageTitle = 'Mandats';
include __DIR__ . '/includes/header.php';
?>

<style>.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }</style>

<?php if ($message): ?>
    <div class="doj-alert doj-alert-<?php echo $messageType; ?>"><i class="fas fa-<?php echo $messageType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $message; ?></div>
<?php endif; ?>

<div class="doj-stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-value"><?php echo $totalWarrants; ?></div><div class="stat-label">Résultats</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div class="stat-value"><?php echo $activeCount; ?></div><div class="stat-label">Mandats Actifs</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-check-double"></i></div><div class="stat-value"><?php echo $executedCount; ?></div><div class="stat-label">Exécutés</div></div>
</div>

<div class="card">
    <div class="card-header">
        <button class="btn btn-primary" onclick="DOJ.openModal('addModal')"><i class="fas fa-plus"></i> Émettre un Mandat</button>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select name="status" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Actif</option>
                <option value="executed" <?php echo $statusFilter==='executed'?'selected':''; ?>>Exécuté</option>
                <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Annulé</option>
                <option value="expired" <?php echo $statusFilter==='expired'?'selected':''; ?>>Expiré</option>
            </select>
            <select name="type" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <option value="arrest" <?php echo $typeFilter==='arrest'?'selected':''; ?>>Arrestation</option>
                <option value="search" <?php echo $typeFilter==='search'?'selected':''; ?>>Perquisition</option>
                <option value="seizure" <?php echo $typeFilter==='seizure'?'selected':''; ?>>Saisie</option>
            </select>
            <div class="doj-search-bar" style="max-width:260px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($warrants)): ?>
            <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucun mandat trouvé</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Citoyen</th><th>Type</th><th>Infraction</th><th>Gravité</th><th>Caution</th><th>Émis par</th><th>Date</th><th>Expiration</th><th>Statut</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($warrants as $w): ?>
                        <?php
                        $typeL=['arrest'=>'Arrestation','search'=>'Perquisition','seizure'=>'Saisie'];
                        $statusL=['active'=>'Actif','executed'=>'Exécuté','cancelled'=>'Annulé','expired'=>'Expiré'];
                        $statusC=['active'=>'status-active','executed'=>'status-executed','cancelled'=>'status-cancelled','expired'=>'status-expired'];
                        $sevL=['low'=>'Faible','medium'=>'Moyen','high'=>'Élevé','critical'=>'Critique'];
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($w['firstname'].' '.$w['lastname']); ?></strong></td>
                            <td><span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.2);border-radius:6px;font-size:0.75rem;font-weight:700;color:#60a5fa;text-transform:uppercase;"><i class="fas fa-<?php echo $w['warrant_type']==='arrest'?'handcuffs':($w['warrant_type']==='search'?'search-location':'box-open'); ?>"></i> <?php echo $typeL[$w['warrant_type']]??$w['warrant_type']; ?></span></td>
                            <td><div style="font-weight:600;"><?php echo htmlspecialchars($w['offense_code']); ?></div><div style="font-size:0.73rem;color:#94a3b8;"><?php echo htmlspecialchars(mb_substr($w['offense_title'],0,30)); ?></div></td>
                            <td><span class="severity-badge severity-<?php echo $w['severity']; ?>"><?php echo $sevL[$w['severity']]??$w['severity']; ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace;color:#fbbf24;">$<?php echo number_format($w['bail_amount'],0,',',' '); ?></td>
                            <td style="font-size:0.82rem;"><?php echo htmlspecialchars($w['issuer_name']??'N/A'); ?></td>
                            <td style="font-size:0.82rem;"><?php echo date('d/m/Y',strtotime($w['issued_date'])); ?></td>
                            <td style="font-size:0.82rem;"><?php echo $w['expiration_date']?date('d/m/Y',strtotime($w['expiration_date'])):'<span style="color:#475569;">∞</span>'; ?></td>
                            <td><span class="status-badge-doj <?php echo $statusC[$w['status']]??''; ?>"><?php echo $statusL[$w['status']]??$w['status']; ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-secondary btn-sm" onclick="viewWarrant(this)" data-warrant="<?php echo htmlspecialchars(json_encode($w),ENT_QUOTES,'UTF-8'); ?>"><i class="fas fa-eye"></i></button>
                                    <?php if ($w['status']==='active'): ?>
                                        <form method="POST" style="display:inline;" data-doj-confirm="Confirmer l'exécution de ce mandat ?" data-doj-confirm-title="Exécuter le mandat" data-doj-confirm-ok="Exécuter" data-doj-confirm-danger="false" data-doj-confirm-target="<?php echo htmlspecialchars($w['firstname'].' '.$w['lastname']); ?>"><input type="hidden" name="action" value="execute"><input type="hidden" name="warrant_id" value="<?php echo $w['id']; ?>"><button type="submit" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.3);"><i class="fas fa-check"></i></button></form>
                                        <form method="POST" style="display:inline;" data-doj-confirm="Annuler définitivement ce mandat ?" data-doj-confirm-title="Annuler le mandat" data-doj-confirm-ok="Annuler le mandat" data-doj-confirm-target="<?php echo htmlspecialchars($w['firstname'].' '.$w['lastname']); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="warrant_id" value="<?php echo $w['id']; ?>"><button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.3);"><i class="fas fa-times"></i></button></form>
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
<div id="addModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-gavel"></i> Émettre un Mandat</h2><button class="modal-close" onclick="DOJ.closeModal('addModal')">×</button></div>
<form method="POST" onsubmit="return saveEditorContent('reasonEditor','reason')"><input type="hidden" name="action" value="add"><div class="modal-body"><div class="form-grid">
    <div class="form-group"><label class="form-label">Citoyen *</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="mandCitizenBtn"><i class="fas fa-user-plus"></i> Ajouter un citoyen</button>
            <span id="mandCitizenChip"></span>
        </div>
        <input type="hidden" name="citizen_id" id="mandCitizenId">
    </div>
    <div class="form-group"><label class="form-label">Type de Mandat *</label><select name="warrant_type" class="form-select" required><option value="">Sélectionner</option><option value="arrest">Mandat d'Arrestation</option><option value="search">Mandat de Perquisition</option><option value="seizure">Mandat de Saisie</option></select></div>
    <div class="form-group"><label class="form-label">Code Pénal *</label><select name="offense_code" class="form-select" required id="offCodeSelect" onchange="fillWarrantData()"><option value="">Sélectionner</option><?php foreach($penalCodes as $pc): ?><option value="<?php echo htmlspecialchars($pc['code']); ?>" data-title="<?php echo htmlspecialchars($pc['title']); ?>" data-severity="<?php echo $pc['severity']; ?>"><?php echo htmlspecialchars($pc['code'].' - '.$pc['title']); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Titre infraction *</label><input type="text" name="offense_title" id="warrantTitle" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Gravité *</label><select name="severity" id="warrantSeverity" class="form-select" required><option value="low">Faible</option><option value="medium" selected>Moyen</option><option value="high">Élevé</option><option value="critical">Critique</option></select></div>
    <div class="form-group"><label class="form-label">Caution ($)</label><input type="number" name="bail_amount" class="form-input" value="0" min="0" step="500"></div>
    <div class="form-group form-full"><label class="form-label">Date d'expiration</label><input type="datetime-local" name="expiration_date" class="form-input"></div>
    <div class="form-group form-full"><label class="form-label">Motif détaillé *</label><div class="editor-toolbar"><button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button><button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button><button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button><button type="button" class="editor-btn" onclick="insertList()">Liste</button></div><div id="reasonEditor" class="rich-editor" contenteditable="true" placeholder="Motifs justifiant l'émission du mandat..."></div><input type="hidden" name="reason" id="reason"></div>
    <div class="form-group form-full"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Notes additionnelles..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-gavel"></i> Émettre le Mandat</button></div></form></div></div>

<!-- Modal Détails -->
<div id="viewModal" class="modal-overlay hidden"><div class="modal"><div class="modal-header"><h2><i class="fas fa-gavel"></i> Détails du Mandat</h2><button class="modal-close" onclick="DOJ.closeModal('viewModal')">×</button></div><div class="modal-body" id="viewContent"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="DOJ.closeModal('viewModal')">Fermer</button></div></div></div>

<script>
// Sélecteur citoyen custom (DojPicker + avatar) — remplace le <select> natif
var DOJ_MANDAT_CITIZENS = <?php echo json_encode(array_map(function($c){
    return ['id'=>$c['id'], 'name'=>trim($c['firstname'].' '.$c['lastname']), 'sub'=>'ID #'.$c['id'], 'avatar'=>$c['avatar'] ?? ''];
}, $citizens), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
document.addEventListener('DOMContentLoaded', function(){
    dojPersonField({ button:'mandCitizenBtn', hidden:'mandCitizenId', chip:'mandCitizenChip',
        title:'Sélection citoyen', items: DOJ_MANDAT_CITIZENS });
});

function fillWarrantData(){const o=document.getElementById('offCodeSelect').selectedOptions[0];if(!o||!o.value)return;document.getElementById('warrantTitle').value=o.dataset.title||'';const sv={'1':'low','2':'medium','3':'medium','4':'high','5':'critical'};document.getElementById('warrantSeverity').value=sv[o.dataset.severity]||'medium';}
function viewWarrant(btn){const w=JSON.parse(btn.dataset.warrant);const tl={arrest:'Arrestation',search:'Perquisition',seizure:'Saisie'},sl={active:'Actif',executed:'Exécuté',cancelled:'Annulé',expired:'Expiré'},sc={active:'status-active',executed:'status-executed',cancelled:'status-cancelled',expired:'status-expired'},sv={low:'Faible',medium:'Moyen',high:'Élevé',critical:'Critique'};let h=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;"><div class="form-group"><div class="form-label">Citoyen</div><div style="color:#f1f5f9;font-size:1.05rem;font-weight:700;">${w.firstname} ${w.lastname}</div></div><div class="form-group"><div class="form-label">Statut</div><div><span class="status-badge-doj ${sc[w.status]||''}">${sl[w.status]||w.status}</span></div></div><div class="form-group"><div class="form-label">Type</div><div style="color:#60a5fa;font-weight:700;">${tl[w.warrant_type]||w.warrant_type}</div></div><div class="form-group"><div class="form-label">Gravité</div><div><span class="severity-badge severity-${w.severity}">${sv[w.severity]||w.severity}</span></div></div><div class="form-group"><div class="form-label">Code</div><div style="color:#3b82f6;font-family:'JetBrains Mono',monospace;">${w.offense_code}</div></div><div class="form-group"><div class="form-label">Caution</div><div style="color:#fbbf24;font-family:'JetBrains Mono',monospace;font-weight:700;">$${Number(w.bail_amount).toLocaleString()}</div></div><div class="form-group"><div class="form-label">Émis par</div><div>${w.issuer_name||'N/A'}</div></div><div class="form-group"><div class="form-label">Date</div><div>${new Date(w.issued_date).toLocaleDateString('fr-FR')}</div></div></div>`;if(w.reason_rich)h+=`<div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid rgba(59,130,246,0.1);"><div class="form-label" style="margin-bottom:0.75rem;">Motif</div><div style="color:#94a3b8;line-height:1.7;">${w.reason_rich}</div></div>`;if(w.executor_name)h+=`<div style="margin-top:1rem;padding:1rem;background:rgba(34,197,94,0.06);border:1px solid rgba(34,197,94,0.15);border-radius:8px;"><div class="form-label" style="color:#22c55e;margin-bottom:0.5rem;">Exécution</div><div style="color:#94a3b8;">Par ${w.executor_name} le ${w.execution_date?new Date(w.execution_date).toLocaleDateString('fr-FR'):'N/A'}</div></div>`;document.getElementById('viewContent').innerHTML=h;DOJ.openModal('viewModal');}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>