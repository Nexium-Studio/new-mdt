<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// === Vérifier quelles tables existent ===
function tableExists($db, $table) {
    try {
        $db->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasCriminalRecords = tableExists($db, 'criminal_records');
$hasWarrants = tableExists($db, 'arrest_warrants');
$hasCases = tableExists($db, 'court_cases');

// === Vérifier les colonnes de profiles ===
function columnExists($db, $table, $column) {
    try {
        $db->query("SELECT `$column` FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasPhone = columnExists($db, 'profiles', 'phone');
$hasDob = columnExists($db, 'profiles', 'date_of_birth');
$hasAvatar = columnExists($db, 'profiles', 'avatar');

// === RECHERCHE ===
$search = $_GET['q'] ?? '';
$where = ""; $params = [];
if ($search) {
    $searchWhere = "(p.firstname LIKE ? OR p.lastname LIKE ?";
    $s = "%$search%"; 
    $params = [$s, $s];
    if ($hasPhone) {
        $searchWhere .= " OR p.phone LIKE ?";
        $params[] = $s;
    }
    $searchWhere .= ")";
    $where = "WHERE $searchWhere";
}

// Construire les sous-requêtes conditionnellement
$subActive = $hasCriminalRecords ? "(SELECT COUNT(*) FROM criminal_records WHERE citizen_id = p.id AND status = 'active')" : "0";
$subTotal = $hasCriminalRecords ? "(SELECT COUNT(*) FROM criminal_records WHERE citizen_id = p.id)" : "0";
$subWarrants = $hasWarrants ? "(SELECT COUNT(*) FROM arrest_warrants WHERE citizen_id = p.id AND status = 'active')" : "0";
$subCases = $hasCases ? "(SELECT COUNT(*) FROM court_cases WHERE defendant_id = p.id AND status != 'concluded')" : "0";

$phoneCol = $hasPhone ? "p.phone," : "";
$dobCol = $hasDob ? "p.date_of_birth," : "";
$avatarCol = $hasAvatar ? "p.avatar," : "";

try {
    $stmt = $db->prepare("
        SELECT p.id, p.firstname, p.lastname, $phoneCol $dobCol $avatarCol
            $subActive as active_records,
            $subTotal as total_records,
            $subWarrants as active_warrants,
            $subCases as active_cases
        FROM profiles p $where ORDER BY p.lastname ASC, p.firstname ASC LIMIT 500
    ");
    $stmt->execute($params);
    $citizens = $stmt->fetchAll();
} catch (PDOException $e) {
    $citizens = [];
    $dbError = $e->getMessage();
}

$totalCitizens = count($citizens);
$withRecords = 0; $withWarrants = 0;
foreach ($citizens as $c) {
    if ($c['active_records'] > 0) $withRecords++;
    if ($c['active_warrants'] > 0) $withWarrants++;
}

// === PRÉ-CHARGEMENT DES DONNÉES ===
$citizenData = [];
foreach ($citizens as $c) {
    $cid = $c['id'];
    $records = [];
    $warrants = [];
    $cases = [];
    
    if ($hasCriminalRecords) {
        try {
            $stmt2 = $db->prepare("SELECT case_number, record_type, offense_code, offense_title, severity, fine_amount, jail_time, points, incident_date, status FROM criminal_records WHERE citizen_id = ? ORDER BY created_at DESC");
            $stmt2->execute([$cid]);
            $records = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $records = []; }
    }
    
    if ($hasWarrants) {
        try {
            $stmt2 = $db->prepare("SELECT warrant_type, offense_code, offense_title, severity, bail_amount, issued_date, status FROM arrest_warrants WHERE citizen_id = ? AND status = 'active' ORDER BY issued_date DESC");
            $stmt2->execute([$cid]);
            $warrants = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $warrants = []; }
    }
    
    if ($hasCases) {
        try {
            $stmt2 = $db->prepare("SELECT case_number, case_title, case_type, verdict, status, hearing_date FROM court_cases WHERE defendant_id = ? ORDER BY created_at DESC");
            $stmt2->execute([$cid]);
            $cases = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $cases = []; }
    }
    
    $citizenData[$cid] = [
        'profile' => [
            'id' => $c['id'],
            'firstname' => $c['firstname'],
            'lastname' => $c['lastname'],
            'phone' => $c['phone'] ?? '',
            'date_of_birth' => $c['date_of_birth'] ?? '',
            'avatar' => $c['avatar'] ?? ''
        ],
        'records' => $records,
        'warrants' => $warrants,
        'cases' => $cases
    ];
}

$pageTitle = 'Citoyens';
include __DIR__ . '/includes/header.php';
?>

<style>
.modal > form { display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; }
.citizen-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1.25rem; }
.citizen-card { background:#1e293b; border:1px solid rgba(59,130,246,0.12); border-radius:14px; padding:1.5rem; transition:all 0.3s cubic-bezier(0.22,1,0.36,1); cursor:pointer; position:relative; overflow:hidden; }
.citizen-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,#3b82f6,transparent); opacity:0; transition:opacity 0.3s; }
.citizen-card:hover { border-color:rgba(59,130,246,0.35); transform:translateY(-3px); box-shadow:0 8px 30px rgba(59,130,246,0.12); }
.citizen-card:hover::before { opacity:1; }
.citizen-avatar { width:52px; height:52px; border-radius:12px; background:linear-gradient(135deg,#3b82f6,#1e40af); display:flex; align-items:center; justify-content:center; font-family:'Orbitron',monospace; font-weight:800; font-size:1.1rem; color:#fff; box-shadow:0 0 20px rgba(59,130,246,0.3); flex-shrink:0; }
.citizen-avatar.has-warrant { background:linear-gradient(135deg,#ef4444,#dc2626); box-shadow:0 0 20px rgba(239,68,68,0.4); }
.citizen-avatar.has-record { background:linear-gradient(135deg,#f59e0b,#d97706); box-shadow:0 0 20px rgba(245,158,11,0.3); }
.citizen-name { font-family:'Chakra Petch',sans-serif; font-size:1rem; font-weight:700; color:#f1f5f9; }
.citizen-meta { font-size:0.75rem; color:#64748b; font-family:'JetBrains Mono',monospace; margin-top:2px; }
.citizen-badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:0.75rem; }
.citizen-badge { padding:3px 9px; border-radius:5px; font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.badge-clean { background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.25); color:#22c55e; }
.badge-record { background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); color:#f59e0b; }
.badge-warrant { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#ef4444; }
.badge-case { background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.25); color:#3b82f6; }
.profile-section { margin-top:1.5rem; padding-top:1.5rem; border-top:1px solid rgba(59,130,246,0.1); }
.profile-section-title { font-family:'Orbitron',monospace; font-size:0.8rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:#94a3b8; margin-bottom:1rem; display:flex; align-items:center; gap:8px; }
.profile-section-title i { color:#3b82f6; }
.profile-record-item { padding:0.875rem 1rem; background:rgba(0,0,0,0.2); border:1px solid rgba(59,130,246,0.08); border-radius:8px; margin-bottom:0.5rem; }
.profile-record-item:hover { border-color:rgba(59,130,246,0.2); }
</style>

<!-- Données pré-chargées -->
<script>
var CITIZEN_DATA = <?php echo json_encode($citizenData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php if (isset($dbError)): ?>
    <div class="doj-alert doj-alert-error"><i class="fas fa-exclamation-circle"></i> Erreur SQL: <?php echo htmlspecialchars($dbError); ?></div>
<?php endif; ?>

<div class="doj-stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-value"><?php echo $totalCitizens; ?></div><div class="stat-label">Citoyens</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-value"><?php echo $withRecords; ?></div><div class="stat-label">Avec Casier Actif</div></div>
    <div class="doj-stat"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div class="stat-value"><?php echo $withWarrants; ?></div><div class="stat-label">Mandats Actifs</div></div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h2 style="display:flex;align-items:center;gap:8px;"><i class="fas fa-users" style="color:#3b82f6;"></i> Répertoire des Citoyens</h2>
        <form method="GET" style="display:flex;gap:0.75rem;align-items:center;">
            <div class="doj-search-bar" style="max-width:350px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher par nom, prénom..." style="padding:0.6rem 1rem 0.6rem 2.8rem;">
                <i class="fas fa-search" style="left:1rem;font-size:0.85rem;"></i>
            </div>
            <?php if ($search): ?>
                <a href="citoyens.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($citizens)): ?>
    <p style="color:#94a3b8;text-align:center;padding:3rem;">Aucun citoyen trouvé</p>
<?php else: ?>
    <div class="citizen-grid">
        <?php foreach ($citizens as $c): ?>
            <?php
            $initials = strtoupper(mb_substr($c['firstname']??'',0,1) . mb_substr($c['lastname']??'',0,1));
            $avatarClass = 'citizen-avatar';
            if ($c['active_warrants'] > 0) $avatarClass .= ' has-warrant';
            elseif ($c['active_records'] > 0) $avatarClass .= ' has-record';
            ?>
            <div class="citizen-card" onclick="showCitizenProfile(<?php echo $c['id']; ?>)">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div class="<?php echo $avatarClass; ?>"><?php if (!empty($c['avatar'] ?? '')): ?><img src="<?php echo htmlspecialchars($c['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;"><?php else: echo $initials; endif; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="citizen-name"><?php echo htmlspecialchars($c['firstname'] . ' ' . $c['lastname']); ?></div>
                        <div class="citizen-meta">ID #<?php echo $c['id']; ?><?php if (!empty($c['phone'] ?? '')): ?> • <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?></div>
                    </div>
                </div>
                <div class="citizen-badges">
                    <?php if ($c['active_warrants'] > 0): ?>
                        <span class="citizen-badge badge-warrant"><i class="fas fa-exclamation-triangle"></i> <?php echo $c['active_warrants']; ?> Mandat<?php echo $c['active_warrants']>1?'s':''; ?></span>
                    <?php endif; ?>
                    <?php if ($c['active_records'] > 0): ?>
                        <span class="citizen-badge badge-record"><i class="fas fa-file-alt"></i> <?php echo $c['active_records']; ?> Casier<?php echo $c['active_records']>1?'s':''; ?> actif<?php echo $c['active_records']>1?'s':''; ?></span>
                    <?php elseif ($c['total_records'] > 0): ?>
                        <span class="citizen-badge badge-record" style="opacity:0.6;"><i class="fas fa-file-alt"></i> <?php echo $c['total_records']; ?> Casier<?php echo $c['total_records']>1?'s':''; ?></span>
                    <?php endif; ?>
                    <?php if ($c['active_cases'] > 0): ?>
                        <span class="citizen-badge badge-case"><i class="fas fa-landmark"></i> <?php echo $c['active_cases']; ?> Procès</span>
                    <?php endif; ?>
                    <?php if ($c['total_records'] == 0 && $c['active_warrants'] == 0): ?>
                        <span class="citizen-badge badge-clean"><i class="fas fa-check-circle"></i> Casier vierge</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal Profil -->
<div id="profileModal" class="modal-overlay hidden">
    <div class="modal modal-large">
        <div class="modal-header">
            <h2><i class="fas fa-user"></i> <span id="profileName">Profil Citoyen</span></h2>
            <button class="modal-close" onclick="DOJ.closeModal('profileModal')">×</button>
        </div>
        <div class="modal-body" id="profileContent"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="DOJ.closeModal('profileModal')">Fermer</button>
        </div>
    </div>
</div>

<script>
function showCitizenProfile(id) {
    var data = CITIZEN_DATA[id];
    if (!data) {
        document.getElementById('profileContent').innerHTML = '<p style="color:#ef4444;text-align:center;padding:2rem;">Données introuvables pour ce citoyen (ID: ' + id + ')</p>';
        DOJ.openModal('profileModal');
        return;
    }
    
    var p = data.profile;
    document.getElementById('profileName').textContent = p.firstname + ' ' + p.lastname;
    
    var avatarCls = 'citizen-avatar';
    if (data.warrants.length > 0) avatarCls += ' has-warrant';
    else if (data.records.length > 0) avatarCls += ' has-record';
    
    var avaInner = p.avatar
        ? '<img src="' + p.avatar + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">'
        : (p.firstname.charAt(0).toUpperCase() + p.lastname.charAt(0).toUpperCase());
    var html = '<div style="display:flex;align-items:center;gap:1.25rem;margin-bottom:1.5rem;">' +
        '<div class="' + avatarCls + '" style="width:64px;height:64px;font-size:1.3rem;overflow:hidden;">' +
            avaInner +
        '</div><div>' +
            '<div style="font-size:1.25rem;font-weight:800;color:#f1f5f9;font-family:Chakra Petch,sans-serif;">' + p.firstname + ' ' + p.lastname + '</div>' +
            '<div style="font-family:JetBrains Mono,monospace;font-size:0.8rem;color:#64748b;margin-top:3px;">ID #' + p.id + (p.phone ? ' &bull; ' + p.phone : '') + (p.date_of_birth ? ' &bull; Né(e) le ' + p.date_of_birth : '') + '</div>' +
        '</div></div>';
    
    // Compteurs
    var wBg = data.warrants.length > 0 ? 'rgba(239,68,68,0.08)' : 'rgba(59,130,246,0.06)';
    var wBd = data.warrants.length > 0 ? 'rgba(239,68,68,0.2)' : 'rgba(59,130,246,0.15)';
    var wCl = data.warrants.length > 0 ? '#ef4444' : '#3b82f6';
    html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">' +
        '<div style="padding:1rem;background:rgba(59,130,246,0.06);border:1px solid rgba(59,130,246,0.15);border-radius:10px;text-align:center;"><div style="font-family:Orbitron,monospace;font-size:1.5rem;font-weight:800;color:#3b82f6;">' + data.records.length + '</div><div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Casiers</div></div>' +
        '<div style="padding:1rem;background:' + wBg + ';border:1px solid ' + wBd + ';border-radius:10px;text-align:center;"><div style="font-family:Orbitron,monospace;font-size:1.5rem;font-weight:800;color:' + wCl + ';">' + data.warrants.length + '</div><div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Mandats actifs</div></div>' +
        '<div style="padding:1rem;background:rgba(59,130,246,0.06);border:1px solid rgba(59,130,246,0.15);border-radius:10px;text-align:center;"><div style="font-family:Orbitron,monospace;font-size:1.5rem;font-weight:800;color:#3b82f6;">' + data.cases.length + '</div><div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Procès</div></div>' +
    '</div>';
    
    // Mandats actifs
    var sevLabels = {low:'Faible',medium:'Moyen',high:'Élevé',critical:'Critique'};
    var typeLabels = {arrest:'Arrestation',search:'Perquisition',seizure:'Saisie'};
    if (data.warrants.length > 0) {
        html += '<div class="profile-section" style="margin-top:0;padding-top:0;"><div class="profile-section-title" style="color:#ef4444;"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Mandats Actifs</div>';
        for (var i = 0; i < data.warrants.length; i++) {
            var w = data.warrants[i];
            html += '<div class="profile-record-item" style="border-color:rgba(239,68,68,0.15);background:rgba(239,68,68,0.04);">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                    '<div><span style="font-weight:700;color:#f1f5f9;">' + (typeLabels[w.warrant_type] || w.warrant_type) + '</span> <span style="font-family:JetBrains Mono,monospace;font-size:0.78rem;color:#3b82f6;margin-left:8px;">' + (w.offense_code || '') + '</span></div>' +
                    '<div style="display:flex;gap:6px;align-items:center;"><span class="severity-badge severity-' + w.severity + '">' + (sevLabels[w.severity] || w.severity) + '</span></div>' +
                '</div>' +
                '<div style="font-size:0.82rem;color:#94a3b8;margin-top:6px;">' + (w.offense_title || '') + '</div>' +
                (parseFloat(w.bail_amount) > 0 ? '<div style="margin-top:4px;font-family:JetBrains Mono,monospace;font-size:0.8rem;color:#fbbf24;">Caution: $' + Number(w.bail_amount).toLocaleString() + '</div>' : '') +
            '</div>';
        }
        html += '</div>';
    }
    
    // Casiers
    html += '<div class="profile-section"><div class="profile-section-title"><i class="fas fa-file-alt"></i> Casier Judiciaire (' + data.records.length + ' entrée' + (data.records.length > 1 ? 's' : '') + ')</div>';
    if (data.records.length === 0) {
        html += '<div style="padding:1.5rem;text-align:center;color:#22c55e;background:rgba(34,197,94,0.06);border:1px solid rgba(34,197,94,0.15);border-radius:8px;"><i class="fas fa-check-circle"></i> Casier vierge — Aucune infraction enregistrée</div>';
    } else {
        var tL = {felony:'Crime',misdemeanor:'Délit',infraction:'Contrav.',warning:'Avert.'};
        var sL = {active:'Actif',served:'Purgé',pardoned:'Gracié',appealed:'En appel'};
        var sC = {active:'#3b82f6',served:'#22c55e',pardoned:'#64748b',appealed:'#f59e0b'};
        for (var i = 0; i < data.records.length; i++) {
            var r = data.records[i];
            html += '<div class="profile-record-item">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                    '<div><span style="font-family:JetBrains Mono,monospace;font-size:0.78rem;color:#3b82f6;">' + (r.case_number || '') + '</span> <span style="font-weight:700;color:#f1f5f9;margin-left:8px;">' + (r.offense_code || '') + '</span> <span style="color:#94a3b8;font-size:0.82rem;margin-left:4px;">&mdash; ' + (r.offense_title || '') + '</span></div>' +
                    '<div style="display:flex;gap:6px;align-items:center;"><span class="severity-badge severity-' + r.severity + '">Niv.' + r.severity + '</span><span style="font-size:0.72rem;font-weight:700;color:' + (sC[r.status] || '#94a3b8') + '">' + (sL[r.status] || r.status) + '</span></div>' +
                '</div>' +
                '<div style="display:flex;gap:1rem;margin-top:6px;font-size:0.78rem;color:#64748b;">' +
                    '<span>' + (tL[r.record_type] || r.record_type) + '</span>' +
                    (parseFloat(r.fine_amount) > 0 ? '<span style="color:#fbbf24;">Amende: $' + Number(r.fine_amount).toLocaleString() + '</span>' : '') +
                    (parseInt(r.jail_time) > 0 ? '<span>Prison: ' + r.jail_time + 'm</span>' : '') +
                '</div></div>';
        }
    }
    html += '</div>';
    
    // Procès
    if (data.cases.length > 0) {
        var vL = {pending:'En attente',guilty:'Coupable',not_guilty:'Non coupable',dismissed:'Classé',settled:'Réglé'};
        var vC = {guilty:'#ef4444',not_guilty:'#22c55e',dismissed:'#64748b',settled:'#f59e0b',pending:'#94a3b8'};
        html += '<div class="profile-section"><div class="profile-section-title"><i class="fas fa-landmark"></i> Procès (' + data.cases.length + ')</div>';
        for (var i = 0; i < data.cases.length; i++) {
            var c = data.cases[i];
            html += '<div class="profile-record-item"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">' +
                '<div><span style="font-family:JetBrains Mono,monospace;font-size:0.78rem;color:#3b82f6;">' + (c.case_number || '') + '</span> <span style="font-weight:700;color:#f1f5f9;margin-left:8px;">' + (c.case_title || '') + '</span></div>' +
                '<span style="font-size:0.78rem;font-weight:700;color:' + (vC[c.verdict] || '#94a3b8') + '">' + (vL[c.verdict] || c.verdict) + '</span>' +
            '</div></div>';
        }
        html += '</div>';
    }
    
    document.getElementById('profileContent').innerHTML = html;
    DOJ.openModal('profileModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>