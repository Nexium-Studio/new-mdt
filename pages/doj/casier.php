<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// === ACTIONS POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $caseNumber = 'CR-' . date('Y') . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $db->prepare("
                INSERT INTO criminal_records (citizen_id, record_type, offense_code, offense_title, description_rich, severity, fine_amount, jail_time, points, officer_id, case_number, incident_date, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ");
            $stmt->execute([
                $_POST['citizen_id'], $_POST['record_type'], $_POST['offense_code'], $_POST['offense_title'],
                $_POST['description'], $_POST['severity'], $_POST['fine_amount'] ?? 0, $_POST['jail_time'] ?? 0,
                $_POST['points'] ?? 0, $_SESSION['user_id'], $caseNumber, $_POST['incident_date'],
                $_POST['notes'] ?? '', $_SESSION['user_id']
            ]);
            $message = "Casier <strong>$caseNumber</strong> créé avec succès";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Erreur lors de la création du casier';
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'update_status' && isset($_POST['record_id'], $_POST['new_status'])) {
        try {
            $db->prepare("UPDATE criminal_records SET status = ? WHERE id = ?")->execute([$_POST['new_status'], $_POST['record_id']]);
            $message = 'Statut mis à jour avec succès';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Erreur lors de la mise à jour';
            $messageType = 'error';
        }
    }
}

// === FILTRES ===
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$search = $_GET['q'] ?? '';

$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND cr.status = ?"; $params[] = $statusFilter; }
if ($typeFilter) { $where .= " AND cr.record_type = ?"; $params[] = $typeFilter; }
if ($search) { $where .= " AND (p.firstname LIKE ? OR p.lastname LIKE ? OR cr.offense_code LIKE ? OR cr.case_number LIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s,$s,$s,$s]); }

$stmt = $db->prepare("
    SELECT cr.*, p.firstname, p.lastname, u.username as officer_name
    FROM criminal_records cr
    JOIN profiles p ON cr.citizen_id = p.id
    LEFT JOIN users u ON cr.officer_id = u.id
    $where ORDER BY cr.created_at DESC LIMIT 200
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$totalRecords = count($records);
$activeCount = $db->query("SELECT COUNT(*) FROM criminal_records WHERE status = 'active'")->fetchColumn();
$felonyCount = $db->query("SELECT COUNT(*) FROM criminal_records WHERE record_type = 'felony' AND status = 'active'")->fetchColumn();

try { $citizens = $db->query("SELECT id, firstname, lastname, avatar FROM profiles ORDER BY lastname")->fetchAll(); }
catch (PDOException $e) { $citizens = $db->query("SELECT id, firstname, lastname FROM profiles ORDER BY lastname")->fetchAll(); }
$penalCodes = $db->query("SELECT code, title, category, severity, min_fine, max_fine, min_jail_months, max_jail_months, points FROM penal_code WHERE active = 1 ORDER BY code")->fetchAll();

$pageTitle = 'Casier Judiciaire';
include __DIR__ . '/includes/header.php';
?>

<!-- FIX: Modal scroll quand form wraps body -->
<style>.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }</style>

<?php if ($message): ?>
    <div class="doj-alert doj-alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="doj-stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-value"><?php echo $totalRecords; ?></div><div class="stat-label">Résultats</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-value"><?php echo $activeCount; ?></div><div class="stat-label">Casiers Actifs</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-skull-crossbones"></i></div><div class="stat-value"><?php echo $felonyCount; ?></div><div class="stat-label">Crimes Actifs</div></div>
</div>

<div class="card">
    <div class="card-header">
        <button class="btn btn-primary" onclick="DOJ.openModal('addModal')"><i class="fas fa-plus"></i> Nouveau Casier</button>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select name="status" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous statuts</option>
                <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Actif</option>
                <option value="served" <?php echo $statusFilter==='served'?'selected':''; ?>>Purgé</option>
                <option value="pardoned" <?php echo $statusFilter==='pardoned'?'selected':''; ?>>Gracié</option>
                <option value="appealed" <?php echo $statusFilter==='appealed'?'selected':''; ?>>En appel</option>
            </select>
            <select name="type" class="form-select" style="width:auto;padding:0.6rem 1rem;" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <option value="felony" <?php echo $typeFilter==='felony'?'selected':''; ?>>Crime</option>
                <option value="misdemeanor" <?php echo $typeFilter==='misdemeanor'?'selected':''; ?>>Délit</option>
                <option value="infraction" <?php echo $typeFilter==='infraction'?'selected':''; ?>>Contravention</option>
                <option value="warning" <?php echo $typeFilter==='warning'?'selected':''; ?>>Avertissement</option>
            </select>
            <div class="doj-search-bar" style="max-width:260px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($records)): ?>
            <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucun casier trouvé</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Dossier</th><th>Citoyen</th><th>Infraction</th><th>Type</th><th>Gravité</th><th>Amende</th><th>Prison</th><th>Statut</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <?php
                        $typeL = ['felony'=>'Crime','misdemeanor'=>'Délit','infraction'=>'Contrav.','warning'=>'Avert.'];
                        $statusL = ['active'=>'Actif','served'=>'Purgé','pardoned'=>'Gracié','appealed'=>'En appel'];
                        $statusC = ['active'=>'status-active','served'=>'status-executed','pardoned'=>'status-dismissed','appealed'=>'status-appealed'];
                        ?>
                        <tr>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:#3b82f6;"><?php echo htmlspecialchars($r['case_number']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($r['firstname'].' '.$r['lastname']); ?></strong></td>
                            <td><div style="font-weight:600;"><?php echo htmlspecialchars($r['offense_code']); ?></div><div style="font-size:0.73rem;color:#94a3b8;"><?php echo htmlspecialchars(mb_substr($r['offense_title'],0,30)); ?></div></td>
                            <td style="font-size:0.8rem;"><?php echo $typeL[$r['record_type']]??$r['record_type']; ?></td>
                            <td><span class="severity-badge severity-<?php echo $r['severity']; ?>">Niv.<?php echo $r['severity']; ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace;color:#fbbf24;">$<?php echo number_format($r['fine_amount'],0,',',' '); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;"><?php echo $r['jail_time']; ?>m</td>
                            <td><span class="status-badge-doj <?php echo $statusC[$r['status']]??''; ?>"><?php echo $statusL[$r['status']]??$r['status']; ?></span></td>
                            <td style="font-size:0.82rem;"><?php echo date('d/m/Y',strtotime($r['incident_date'])); ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-secondary btn-sm" onclick="viewRecord(<?php echo $r['id']; ?>)" data-record="<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-eye"></i></button>
                                    <?php if ($r['status']==='active'): ?>
                                        <form method="POST" style="display:inline;" data-doj-confirm="Marquer ce casier comme purgé ?" data-doj-confirm-title="Marquer purgé" data-doj-confirm-ok="Marquer purgé" data-doj-confirm-danger="false" data-doj-confirm-target="<?php echo htmlspecialchars($r['case_number']); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="record_id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="new_status" value="served">
                                            <button type="submit" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.3);"><i class="fas fa-check"></i></button>
                                        </form>
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
<div id="addModal" class="modal-overlay hidden"><div class="modal modal-large"><div class="modal-header"><h2><i class="fas fa-file-alt"></i> Nouveau Casier Judiciaire</h2><button class="modal-close" onclick="DOJ.closeModal('addModal')">×</button></div>
<form method="POST" onsubmit="return saveEditorContent('descEditor','description')"><input type="hidden" name="action" value="add"><div class="modal-body"><div class="form-grid">
    <div class="form-group"><label class="form-label">Citoyen *</label>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;min-height:44px;">
            <button type="button" class="dj-btn dj-btn-ghost" id="casCitizenBtn"><i class="fas fa-user-plus"></i> Ajouter un citoyen</button>
            <span id="casCitizenChip"></span>
        </div>
        <input type="hidden" name="citizen_id" id="casCitizenId">
    </div>
    <div class="form-group"><label class="form-label">Date des faits *</label><input type="datetime-local" name="incident_date" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Code Pénal *</label><select name="offense_code" class="form-select" required id="offenseSelect" onchange="fillOffenseData()"><option value="">Sélectionner</option><?php foreach($penalCodes as $pc): ?><option value="<?php echo htmlspecialchars($pc['code']); ?>" data-title="<?php echo htmlspecialchars($pc['title']); ?>" data-category="<?php echo $pc['category']; ?>" data-severity="<?php echo $pc['severity']; ?>" data-minfine="<?php echo $pc['min_fine']; ?>" data-minjail="<?php echo $pc['min_jail_months']; ?>" data-points="<?php echo $pc['points']; ?>"><?php echo htmlspecialchars($pc['code'].' - '.$pc['title']); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Titre infraction *</label><input type="text" name="offense_title" id="offenseTitle" class="form-input" required></div>
    <div class="form-group"><label class="form-label">Type *</label><select name="record_type" id="recordType" class="form-select" required><option value="">Sélectionner</option><option value="felony">Crime (Felony)</option><option value="misdemeanor">Délit (Misdemeanor)</option><option value="infraction">Contravention</option><option value="warning">Avertissement</option></select></div>
    <div class="form-group"><label class="form-label">Points permis</label><input type="number" name="points" id="pointsInput" class="form-input" value="0" min="0"></div>
    <!-- Sanctions auto-calculées depuis le code pénal sélectionné (non saisissables) -->
    <div class="form-group form-full">
        <label class="form-label"><i class="fas fa-wand-magic-sparkles" style="color:#60a5fa;margin-right:6px;"></i>Sanctions automatiques (depuis le code pénal)</label>
        <div id="autoCalcBox" style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;">
            <div style="background:rgba(59,130,246,0.05);border:1px solid rgba(59,130,246,0.15);border-radius:10px;padding:.85rem 1rem;">
                <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#475569;margin-bottom:.4rem;">Gravité</div>
                <div id="acSeverity"><span class="severity-badge severity-1">Niveau 1</span></div>
            </div>
            <div style="background:rgba(245,158,11,0.05);border:1px solid rgba(245,158,11,0.15);border-radius:10px;padding:.85rem 1rem;">
                <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#475569;margin-bottom:.4rem;">Amende</div>
                <div id="acFine" style="font-family:'JetBrains Mono',monospace;font-weight:700;color:#fbbf24;font-size:1.05rem;">$0</div>
            </div>
            <div style="background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.15);border-radius:10px;padding:.85rem 1rem;">
                <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#475569;margin-bottom:.4rem;">Prison</div>
                <div id="acJail" style="font-family:'JetBrains Mono',monospace;font-weight:700;color:#f87171;font-size:1.05rem;">0 mois</div>
            </div>
        </div>
        <input type="hidden" name="severity" id="severitySelect" value="1">
        <input type="hidden" name="fine_amount" id="fineAmount" value="0">
        <input type="hidden" name="jail_time" id="jailTime" value="0">
    </div>
    <div class="form-group form-full"><label class="form-label">Description</label><div class="editor-toolbar"><button type="button" class="editor-btn" onclick="formatText('bold')"><strong>B</strong></button><button type="button" class="editor-btn" onclick="formatText('italic')"><em>I</em></button><button type="button" class="editor-btn" onclick="formatText('underline')"><u>U</u></button><button type="button" class="editor-btn" onclick="insertList()">Liste</button></div><div id="descEditor" class="rich-editor" contenteditable="true" placeholder="Description des faits..."></div><input type="hidden" name="description" id="description"></div>
    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" placeholder="Notes réservées au département..."></textarea></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="DOJ.closeModal('addModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer le Casier</button></div></form></div></div>

<!-- Modal Détails -->
<div id="viewModal" class="modal-overlay hidden"><div class="modal"><div class="modal-header"><h2><i class="fas fa-file-alt"></i> <span id="viewTitle"></span></h2><button class="modal-close" onclick="DOJ.closeModal('viewModal')">×</button></div><div class="modal-body" id="viewContent"></div><div class="modal-footer"><button class="btn btn-secondary" onclick="DOJ.closeModal('viewModal')">Fermer</button></div></div></div>

<script>
// Sélecteur de citoyen custom (DojPicker + avatar) — remplace le <select> natif
var DOJ_CASIER_CITIZENS = <?php echo json_encode(array_map(function($c){
    return ['id'=>$c['id'], 'name'=>trim($c['firstname'].' '.$c['lastname']), 'sub'=>'ID #'.$c['id'], 'avatar'=>$c['avatar'] ?? ''];
}, $citizens), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
document.addEventListener('DOMContentLoaded', function(){
    dojPersonField({ button:'casCitizenBtn', hidden:'casCitizenId', chip:'casCitizenChip',
        title:'Sélection citoyen', items: DOJ_CASIER_CITIZENS });
});

function fillOffenseData(){
    const o=document.getElementById('offenseSelect').selectedOptions[0];if(!o||!o.value)return;
    document.getElementById('offenseTitle').value=o.dataset.title||'';
    const c=o.dataset.category||'';
    const rt=document.getElementById('recordType'); rt.value=(c==='traffic'?'infraction':c)||rt.value; rt.dispatchEvent(new Event('change',{bubbles:true}));
    const sev=o.dataset.severity||'1', fine=parseInt(o.dataset.minfine||0,10), jail=parseInt(o.dataset.minjail||0,10), pts=o.dataset.points||0;
    document.getElementById('severitySelect').value=sev;
    document.getElementById('fineAmount').value=fine;
    document.getElementById('jailTime').value=jail;
    document.getElementById('pointsInput').value=pts;
    // Récap auto-calculé (lecture seule)
    document.getElementById('acSeverity').innerHTML='<span class="severity-badge severity-'+sev+'">Niveau '+sev+'</span>';
    document.getElementById('acFine').textContent='$'+Number(fine).toLocaleString('fr-FR');
    document.getElementById('acJail').textContent=jail+' mois';
}

function viewRecord(id){
    const btn=document.querySelector('[onclick="viewRecord('+id+')"]');
    const r=JSON.parse(btn.dataset.record);
    document.getElementById('viewTitle').textContent=r.case_number;
    const t={felony:'Crime',misdemeanor:'Délit',infraction:'Contravention',warning:'Avertissement'},s={active:'Actif',served:'Purgé',pardoned:'Gracié',appealed:'En appel'},sc={active:'status-active',served:'status-executed',pardoned:'status-dismissed',appealed:'status-appealed'};
    let h=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
        <div class="form-group"><div class="form-label">Citoyen</div><div style="color:#f1f5f9;font-size:1.05rem;font-weight:700;">${r.firstname} ${r.lastname}</div></div>
        <div class="form-group"><div class="form-label">Statut</div><div><span class="status-badge-doj ${sc[r.status]||''}">${s[r.status]||r.status}</span></div></div>
        <div class="form-group"><div class="form-label">Code</div><div style="color:#3b82f6;font-family:'JetBrains Mono',monospace;font-weight:700;">${r.offense_code}</div></div>
        <div class="form-group"><div class="form-label">Infraction</div><div style="color:#f1f5f9;">${r.offense_title}</div></div>
        <div class="form-group"><div class="form-label">Type</div><div>${t[r.record_type]||r.record_type}</div></div>
        <div class="form-group"><div class="form-label">Gravité</div><div><span class="severity-badge severity-${r.severity}">Niveau ${r.severity}</span></div></div>
        <div class="form-group"><div class="form-label">Amende</div><div style="color:#fbbf24;font-family:'JetBrains Mono',monospace;font-weight:700;">$${Number(r.fine_amount).toLocaleString()}</div></div>
        <div class="form-group"><div class="form-label">Prison</div><div style="font-family:'JetBrains Mono',monospace;">${r.jail_time} mois</div></div>
        <div class="form-group"><div class="form-label">Agent</div><div>${r.officer_name||'N/A'}</div></div>
        <div class="form-group"><div class="form-label">Date des faits</div><div>${new Date(r.incident_date).toLocaleDateString('fr-FR')}</div></div>
    </div>`;
    if(r.description_rich)h+=`<div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid rgba(59,130,246,0.1);"><div class="form-label" style="margin-bottom:0.75rem;">Description</div><div style="color:#94a3b8;line-height:1.7;">${r.description_rich}</div></div>`;
    if(r.notes)h+=`<div style="margin-top:1rem;padding:1rem;background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.15);border-radius:8px;"><div class="form-label" style="color:#f59e0b;margin-bottom:0.5rem;">Notes internes</div><div style="color:#94a3b8;">${r.notes}</div></div>`;
    document.getElementById('viewContent').innerHTML=h;DOJ.openModal('viewModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>