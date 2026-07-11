<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Vérifier faction SAMS
if ($_SESSION['user_faction'] !== 'SAMS' && !in_array($_SESSION['user_role'], ['Administrateur', 'Développeur', 'Staff'])) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Récupérer le grade de l'utilisateur connecté
$gradeStmt = $db->prepare("SELECT faction_grade FROM users WHERE id = ?");
$gradeStmt->execute([$_SESSION['user_id']]);
$currentUserGrade = $gradeStmt->fetch()['faction_grade'] ?? '';
$currentUserRole = $_SESSION['user_role'] ?? '';

// Charge tous les grades SAMS avec leur rank_order pour la nav et les selects
try {
    $__samsGStmt = $db->prepare(
        "SELECT fg.name, fg.rank_order FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = 'SAMS' ORDER BY fg.rank_order ASC"
    );
    $__samsGStmt->execute();
    $__samsGradeRows = $__samsGStmt->fetchAll(PDO::FETCH_ASSOC);
    $samsGrades = array_column($__samsGradeRows, 'name');
} catch(Exception $e) {
    // Fallback ordonné du plus haut au plus bas (rank_order ASC)
    $samsGrades = ['Director','Clinical Supervisor','Medecin II','Medecin I','Paramedic II','Paramedic I','Stagiaire'];
}

// Contrôle d'accès : on compare le rank_order de l'utilisateur directement en DB.
// Seuil = rank_order de 'Clinical Supervisor' (tous les grades >= ce seuil ont accès).
// Ainsi même si tu ajoutes/supprimes des grades, le seuil reste ancré sur le nom.
$allowedRoles = ['Administrateur', 'Développeur', 'Staff'];

try {
    // rank_order de l'utilisateur courant
    $__userRankStmt = $db->prepare(
        "SELECT fg.rank_order FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = 'SAMS' AND fg.name = ? LIMIT 1"
    );
    $__userRankStmt->execute([$currentUserGrade]);
    $__userRankRow = $__userRankStmt->fetch(PDO::FETCH_ASSOC);
    $__userRankOrder = $__userRankRow ? (int)$__userRankRow['rank_order'] : 9999;

    // rank_order du seuil minimum requis ('Clinical Supervisor')
    $__threshStmt = $db->prepare(
        "SELECT fg.rank_order FROM faction_grades fg
         JOIN factions f ON f.id = fg.faction_id
         WHERE f.faction_key = 'SAMS' AND fg.name = 'Clinical Supervisor' LIMIT 1"
    );
    $__threshStmt->execute();
    $__threshRow = $__threshStmt->fetch(PDO::FETCH_ASSOC);
    $__threshRank = $__threshRow ? (int)$__threshRow['rank_order'] : 2;
} catch (Exception $e) {
    $__userRankOrder = 9999;
    $__threshRank   = 2;
}

// rank_order plus petit = grade plus élevé (Director=1, Clinical Supervisor=2...)
$hasAccess = in_array($currentUserRole, $allowedRoles) || $__userRankOrder <= $__threshRank;

if (!$hasAccess) {
    $_SESSION['error'] = 'Accès refusé : grade Clinical Supervisor minimum requis';
    redirect(SITE_URL . '/pages/sams/dashboard.php');
}



// Affichage strict Nom + Prénom issu du profil personnage — jamais le pseudo.
// Règle obligatoire du panel SAMS : le pseudo ne doit apparaître nulle part.
$samsName = function (?string $fn, ?string $ln): string {
    $n = mdt_rp_display_name($fn, $ln, '');
    return ($n === '' || $n === '—') ? 'Profil non renseigné' : $n;
};
$samsInitial = function (?string $fn, ?string $ln): string {
    $n = mdt_rp_display_name($fn, $ln, '');
    return ($n === '' || $n === '—') ? '?' : mb_strtoupper(mb_substr($n, 0, 1));
};

$message = '';
$messageType = '';

// === TRAITEMENT DES ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Recruter un citoyen
    if ($action === 'recruit') {
        $userId = intval($_POST['user_id']);
        $grade = sanitize($_POST['grade'] ?? '');
        
        if (!$grade) {
            $message = 'Veuillez sélectionner un grade';
            $messageType = 'error';
        } else {
            // Vérifier que le user est bien Citoyen sans faction
            $check = $db->prepare(
                "SELECT u.id, u.username, u.faction, u.role, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id = ? AND u.faction = 'NONE' AND u.role = 'Citoyen'"
            );
            $check->execute([$userId]);
            $target = $check->fetch();
            
            if ($target) {
                $stmt = $db->prepare("UPDATE users SET faction = 'SAMS', faction_grade = ? WHERE id = ?");
                if ($stmt->execute([$grade, $userId])) {
                    $tDisp = $samsName($target['profile_firstname'] ?? null, $target['profile_lastname'] ?? null);
                    $message = htmlspecialchars($tDisp) . ' a été recruté(e) en tant que ' . htmlspecialchars($grade);
                    $messageType = 'success';
                    
                    // Log
                    try {
                        $logStmt = $db->prepare("INSERT INTO sams_activity_logs (user_id, action_type, description, target_type, target_id, target_name, ip_address) VALUES (?, 'recruit_member', ?, 'user', ?, ?, ?)");
                        $logStmt->execute([
                            $_SESSION['user_id'],
                            "Recrutement de " . $tDisp . " au grade " . $grade,
                            $userId,
                            $tDisp,
                            $_SERVER['REMOTE_ADDR']
                        ]);
                    } catch (PDOException $e) {}
                } else {
                    $message = 'Erreur lors du recrutement';
                    $messageType = 'error';
                }
            } else {
                $message = 'Utilisateur non éligible au recrutement';
                $messageType = 'error';
            }
        }
    }
    
    // Modifier le grade d'un membre
    if ($action === 'update_grade') {
        $userId = intval($_POST['user_id']);
        $grade = sanitize($_POST['grade'] ?? '');
        
        $check = $db->prepare(
            "SELECT u.id, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
             FROM users u
             LEFT JOIN profiles p ON p.id = u.profile_id
             WHERE u.id = ? AND u.faction = 'SAMS'"
        );
        $check->execute([$userId]);
        $target = $check->fetch();
        
        if ($target && $grade) {
            $stmt = $db->prepare("UPDATE users SET faction_grade = ? WHERE id = ?");
            if ($stmt->execute([$grade, $userId])) {
                $tDisp = $samsName($target['profile_firstname'] ?? null, $target['profile_lastname'] ?? null);
                $message = 'Grade de ' . htmlspecialchars($tDisp) . ' mis à jour : ' . htmlspecialchars($grade);
                $messageType = 'success';
                
                try {
                    $logStmt = $db->prepare("INSERT INTO sams_activity_logs (user_id, action_type, description, target_type, target_id, target_name, ip_address) VALUES (?, 'update_grade', ?, 'user', ?, ?, ?)");
                    $logStmt->execute([
                        $_SESSION['user_id'],
                        "Changement de grade de " . $tDisp . " vers " . $grade,
                        $userId,
                        $tDisp,
                        $_SERVER['REMOTE_ADDR']
                    ]);
                } catch (PDOException $e) {}
            }
        } else {
            $message = 'Erreur : membre ou grade invalide';
            $messageType = 'error';
        }
    }
    
    // Retirer de la faction
    if ($action === 'remove') {
        $userId = intval($_POST['user_id']);
        
        if ($userId === $_SESSION['user_id']) {
            $message = 'Vous ne pouvez pas vous retirer vous-même';
            $messageType = 'error';
        } else {
            $check = $db->prepare(
                "SELECT u.id, u.username, p.firstname AS profile_firstname, p.lastname AS profile_lastname
                 FROM users u
                 LEFT JOIN profiles p ON p.id = u.profile_id
                 WHERE u.id = ? AND u.faction = 'SAMS'"
            );
            $check->execute([$userId]);
            $target = $check->fetch();
            
            if ($target) {
                $stmt = $db->prepare("UPDATE users SET faction = 'NONE', faction_grade = NULL WHERE id = ?");
                if ($stmt->execute([$userId])) {
                    $tDisp = $samsName($target['profile_firstname'] ?? null, $target['profile_lastname'] ?? null);
                    $message = htmlspecialchars($tDisp) . ' a été retiré(e) du SAMS';
                    $messageType = 'success';
                    
                    try {
                        $logStmt = $db->prepare("INSERT INTO sams_activity_logs (user_id, action_type, description, target_type, target_id, target_name, ip_address) VALUES (?, 'remove_member', ?, 'user', ?, ?, ?)");
                        $logStmt->execute([
                            $_SESSION['user_id'],
                            "Retrait de " . $tDisp . " du SAMS",
                            $userId,
                            $tDisp,
                            $_SERVER['REMOTE_ADDR']
                        ]);
                    } catch (PDOException $e) {}
                }
            }
        }
    }
}

// === DONNÉES ===
// Membres SAMS actuels
// ORDER BY dynamique basé sur les grades récupérés en DB (rank_order ASC = plus haut en premier)
$__fieldList = implode(',', array_map(fn($g) => $db->quote($g), $samsGrades));
$samsMembers = $db->query("
    SELECT u.id, u.username, u.email, u.faction_grade, u.is_active, u.created_at, u.avatar,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE u.faction = 'SAMS'
    ORDER BY FIELD(u.faction_grade, {$__fieldList}), u.username
")->fetchAll();

// Citoyens recrutables (rôle Citoyen + faction NONE)
$recruitableCitizens = $db->query("
    SELECT u.id, u.username, u.email, u.avatar, u.created_at,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id = u.profile_id
    WHERE u.role = 'Citoyen' AND u.faction = 'NONE' AND u.is_active = 1
    ORDER BY u.username
")->fetchAll();

$pageTitle = 'Gestion des Effectifs';
include __DIR__ . '/includes/header.php';
?>

<style>
.effectifs-header {
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.12), rgba(220, 20, 60, 0.06));
    border: 1px solid rgba(255, 23, 68, 0.25);
    border-radius: 20px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.effectifs-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, #ff1744, #ff2d78, #ff1744, transparent);
}
.effectifs-title {
    font-family: 'Orbitron', monospace;
    font-size: 1.6rem;
    font-weight: 800;
    background: linear-gradient(135deg, #ff1744, #ff4569);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.effectifs-subtitle { color: #7a6b70; font-size: 0.9rem; }

.eff-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}
.eff-stat-card {
    background: rgba(255, 23, 68, 0.05);
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
}
.eff-stat-value {
    font-family: 'Orbitron', monospace;
    font-size: 2rem;
    font-weight: 900;
    background: linear-gradient(135deg, #ff1744, #ff4569);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.eff-stat-label {
    font-size: 0.75rem;
    color: #7a6b70;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 0.5rem;
}

/* Tabs */
.eff-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 2rem;
    background: #0d1220;
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 12px;
    overflow: hidden;
}
.eff-tab {
    flex: 1;
    padding: 1rem 1.5rem;
    text-align: center;
    font-family: 'Chakra Petch', sans-serif;
    font-weight: 700;
    font-size: 0.9rem;
    color: #7a6b70;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
    background: none;
    position: relative;
}
.eff-tab:hover { color: #ff4569; background: rgba(255, 23, 68, 0.03); }
.eff-tab.active {
    color: #ff1744;
    background: rgba(255, 23, 68, 0.08);
}
.eff-tab.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #ff1744, #ff4569);
}
.eff-tab i { margin-right: 0.5rem; }
.tab-count {
    display: inline-block;
    background: rgba(255, 23, 68, 0.2);
    border: 1px solid rgba(255, 23, 68, 0.3);
    border-radius: 10px;
    padding: 0.15rem 0.5rem;
    font-size: 0.7rem;
    margin-left: 0.5rem;
    font-family: 'JetBrains Mono', monospace;
}

.eff-panel { display: none; }
.eff-panel.active { display: block; }

/* Member cards */
.members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
.member-card {
    background: #0d1220;
    border: 1px solid rgba(255, 23, 68, 0.12);
    border-radius: 14px;
    padding: 1.5rem;
    transition: all 0.3s;
}
.member-card:hover {
    border-color: rgba(255, 23, 68, 0.35);
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(255, 23, 68, 0.12);
}
.member-top {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}
.member-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.25), rgba(220, 20, 60, 0.15));
    border: 2px solid rgba(255, 23, 68, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Orbitron', monospace;
    font-weight: 700;
    font-size: 1.2rem;
    color: #ff1744;
    flex-shrink: 0;
    overflow: hidden;
}
.member-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.member-name {
    font-weight: 700;
    font-size: 1rem;
    color: #f0e6e8;
}
.member-grade {
    font-size: 0.78rem;
    color: #ff4569;
    font-family: 'JetBrains Mono', monospace;
    margin-top: 2px;
}
.member-email {
    font-size: 0.75rem;
    color: #7a6b70;
    margin-top: 1px;
}
.member-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 23, 68, 0.08);
}
.btn-sm-action {
    flex: 1;
    padding: 0.6rem 0.8rem;
    border: 1px solid;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
    font-family: 'Chakra Petch', sans-serif;
    background: none;
}
.btn-edit-grade {
    border-color: rgba(255, 23, 68, 0.3);
    color: #ff4569;
}
.btn-edit-grade:hover { background: rgba(255, 23, 68, 0.1); }
.btn-remove {
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444;
}
.btn-remove:hover { background: rgba(239, 68, 68, 0.1); }
.btn-recruit {
    border-color: rgba(34, 197, 94, 0.3);
    color: #22c55e;
    width: 100%;
}
.btn-recruit:hover { background: rgba(34, 197, 94, 0.1); }

/* Alert */
.eff-alert {
    padding: 1rem 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.eff-alert.success {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.25);
    color: #22c55e;
}
.eff-alert.error {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #ef4444;
}

/* Search */
.eff-search {
    margin-bottom: 1.5rem;
    position: relative;
}
.eff-search input {
    width: 100%;
    padding: 0.85rem 1rem 0.85rem 2.5rem;
    background: #0d1220;
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 10px;
    color: #f0e6e8;
    font-family: 'Chakra Petch', sans-serif;
    font-size: 0.9rem;
}
.eff-search input:focus {
    outline: none;
    border-color: rgba(255, 23, 68, 0.4);
    box-shadow: 0 0 15px rgba(255, 23, 68, 0.1);
}
.eff-search i {
    position: absolute;
    left: 0.85rem;
    top: 50%;
    transform: translateY(-50%);
    color: #7a6b70;
}

/* Modal */
.eff-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(5px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}
.eff-modal-overlay.show { display: flex; }
.eff-modal {
    background: #0d1220;
    border: 1px solid rgba(255, 23, 68, 0.3);
    border-radius: 16px;
    width: 90%;
    max-width: 450px;
    overflow: hidden;
}
.eff-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid rgba(255, 23, 68, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.eff-modal-header h3 {
    font-family: 'Orbitron', monospace;
    font-size: 1rem;
    font-weight: 700;
    color: #f0e6e8;
}
.eff-modal-close {
    background: none;
    border: none;
    color: #7a6b70;
    font-size: 1.3rem;
    cursor: pointer;
    padding: 0.25rem;
}
.eff-modal-close:hover { color: #ff4569; }
.eff-modal-body { padding: 1.5rem; }
.eff-modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid rgba(255, 23, 68, 0.1);
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
}
.eff-form-group { margin-bottom: 1rem; }
.eff-form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: #7a6b70;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.eff-form-select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 8px;
    color: #f0e6e8;
    font-family: 'Chakra Petch', sans-serif;
    font-size: 0.9rem;
    appearance: auto;
}
.eff-form-select:focus {
    outline: none;
    border-color: rgba(255, 23, 68, 0.4);
}
.eff-form-select option {
    background: #0d1220;
    color: #f0e6e8;
}
.btn-cancel {
    padding: 0.6rem 1.2rem;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    color: #7a6b70;
    cursor: pointer;
    font-weight: 600;
}
.btn-confirm {
    padding: 0.6rem 1.2rem;
    background: rgba(255, 23, 68, 0.15);
    border: 1px solid rgba(255, 23, 68, 0.4);
    border-radius: 8px;
    color: #ff4569;
    cursor: pointer;
    font-weight: 700;
}
.btn-confirm:hover { background: rgba(255, 23, 68, 0.25); }
.btn-confirm.danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
    color: #ef4444;
}
.member-status-inactive {
    opacity: 0.5;
    border-style: dashed;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #7a6b70;
}
.empty-state i {
    font-size: 3rem;
    opacity: 0.2;
    margin-bottom: 1rem;
    display: block;
}

@media (max-width: 768px) {
    .eff-stats { grid-template-columns: 1fr; }
    .members-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Header -->
<div class="effectifs-header">
    <h1 class="effectifs-title">
        <i class="fas fa-user-plus"></i>
        Gestion des Effectifs SAMS
    </h1>
    <p class="effectifs-subtitle">
        <i class="fas fa-shield-alt"></i> Recrutement et gestion des grades — Réservé aux superviseurs
    </p>
</div>

<?php if ($message): ?>
    <div class="eff-alert <?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="eff-stats">
    <div class="eff-stat-card">
        <div class="eff-stat-value"><?php echo count($samsMembers); ?></div>
        <div class="eff-stat-label">Membres SAMS</div>
    </div>
    <div class="eff-stat-card">
        <div class="eff-stat-value"><?php echo count(array_filter($samsMembers, fn($m) => $m['is_active'])); ?></div>
        <div class="eff-stat-label">Actifs</div>
    </div>
    <div class="eff-stat-card">
        <div class="eff-stat-value"><?php echo count($recruitableCitizens); ?></div>
        <div class="eff-stat-label">Citoyens Recrutables</div>
    </div>
</div>

<!-- Tabs -->
<div class="eff-tabs">
    <button class="eff-tab active" onclick="switchTab('members', this)">
        <i class="fas fa-users"></i> Membres SAMS
        <span class="tab-count"><?php echo count($samsMembers); ?></span>
    </button>
    <button class="eff-tab" onclick="switchTab('recruit', this)">
        <i class="fas fa-user-plus"></i> Recruter
        <span class="tab-count"><?php echo count($recruitableCitizens); ?></span>
    </button>
</div>

<!-- Panel: Membres SAMS -->
<div id="panel-members" class="eff-panel active">
    <div class="eff-search">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Rechercher un membre..." oninput="filterCards(this.value, 'members-grid')">
    </div>
    
    <?php if (empty($samsMembers)): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>Aucun membre SAMS</h3>
            <p>Commencez par recruter des citoyens</p>
        </div>
    <?php else: ?>
        <div class="members-grid" id="members-grid">
            <?php foreach ($samsMembers as $member):
                $mDisp = $samsName($member['profile_firstname'] ?? null, $member['profile_lastname'] ?? null);
                $mIni  = $samsInitial($member['profile_firstname'] ?? null, $member['profile_lastname'] ?? null);
                $mSearch = strtolower($mDisp);
            ?>
                <div class="member-card <?php echo !$member['is_active'] ? 'member-status-inactive' : ''; ?>" data-name="<?php echo htmlspecialchars($mSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="member-top">
                        <div class="member-avatar">
                            <?php if ($member['avatar']): ?>
                                <img src="<?php echo htmlspecialchars($member['avatar']); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars($mIni); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="member-name"><?php echo htmlspecialchars($mDisp); ?></div>
                            <div class="member-grade"><?php echo htmlspecialchars($member['faction_grade'] ?: 'Aucun grade'); ?></div>
                        </div>
                    </div>
                    <div class="member-actions">
                        <button class="btn-sm-action btn-edit-grade" onclick="openEditGrade(<?php echo $member['id']; ?>, <?php echo htmlspecialchars(json_encode($mDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($member['faction_grade'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                            <i class="fas fa-pen"></i> Modifier grade
                        </button>
                        <?php if ($member['id'] !== $_SESSION['user_id']): ?>
                            <button class="btn-sm-action btn-remove" onclick="openRemove(<?php echo $member['id']; ?>, <?php echo htmlspecialchars(json_encode($mDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-user-minus"></i> Retirer
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Panel: Recruter -->
<div id="panel-recruit" class="eff-panel">
    <div class="eff-search">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Rechercher un citoyen..." oninput="filterCards(this.value, 'recruit-grid')">
    </div>
    
    <?php if (empty($recruitableCitizens)): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h3>Aucun citoyen disponible</h3>
            <p>Tous les citoyens ont déjà une faction assignée</p>
        </div>
    <?php else: ?>
        <div class="members-grid" id="recruit-grid">
            <?php foreach ($recruitableCitizens as $citizen):
                $cDisp = $samsName($citizen['profile_firstname'] ?? null, $citizen['profile_lastname'] ?? null);
                $cIni  = $samsInitial($citizen['profile_firstname'] ?? null, $citizen['profile_lastname'] ?? null);
                $cSearch = strtolower($cDisp);
            ?>
                <div class="member-card" data-name="<?php echo htmlspecialchars($cSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="member-top">
                        <div class="member-avatar">
                            <?php if ($citizen['avatar']): ?>
                                <img src="<?php echo htmlspecialchars($citizen['avatar']); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars($cIni); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="member-name"><?php echo htmlspecialchars($cDisp); ?></div>
                            <div class="member-grade" style="color: #7a6b70;">Citoyen — Sans faction</div>
                        </div>
                    </div>
                    <div class="member-actions">
                        <button class="btn-sm-action btn-recruit" onclick="openRecruit(<?php echo $citizen['id']; ?>, <?php echo htmlspecialchars(json_encode($cDisp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                            <i class="fas fa-user-plus"></i> Recruter au SAMS
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Modifier grade -->
<div class="eff-modal-overlay" id="modalEditGrade">
    <div class="eff-modal">
        <div class="eff-modal-header">
            <h3><i class="fas fa-pen" style="color:#ff1744;margin-right:8px;"></i> Modifier le grade</h3>
            <button class="eff-modal-close" onclick="closeModal('modalEditGrade')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_grade">
            <input type="hidden" name="user_id" id="editGradeUserId">
            <div class="eff-modal-body">
                <p style="color:#7a6b70;margin-bottom:1rem;font-size:0.9rem;">
                    Membre : <strong style="color:#f0e6e8;" id="editGradeUsername"></strong>
                </p>
                <div class="eff-form-group">
                    <label class="eff-form-label">Nouveau grade</label>
                    <select name="grade" id="editGradeSelect" class="eff-form-select" required>
                        <?php foreach ($samsGrades as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="eff-modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalEditGrade')">Annuler</button>
                <button type="submit" class="btn-confirm">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Recruter -->
<div class="eff-modal-overlay" id="modalRecruit">
    <div class="eff-modal">
        <div class="eff-modal-header">
            <h3><i class="fas fa-user-plus" style="color:#22c55e;margin-right:8px;"></i> Recruter</h3>
            <button class="eff-modal-close" onclick="closeModal('modalRecruit')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="recruit">
            <input type="hidden" name="user_id" id="recruitUserId">
            <div class="eff-modal-body">
                <p style="color:#7a6b70;margin-bottom:1rem;font-size:0.9rem;">
                    Recruter <strong style="color:#f0e6e8;" id="recruitUsername"></strong> au SAMS
                </p>
                <div class="eff-form-group">
                    <label class="eff-form-label">Grade d'entrée</label>
                    <select name="grade" class="eff-form-select" required>
                        <option value="">— Sélectionner un grade —</option>
                        <?php foreach ($samsGrades as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="eff-modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalRecruit')">Annuler</button>
                <button type="submit" class="btn-confirm" style="color:#22c55e;border-color:rgba(34,197,94,0.4);background:rgba(34,197,94,0.15);">Recruter</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Retirer -->
<div class="eff-modal-overlay" id="modalRemove">
    <div class="eff-modal">
        <div class="eff-modal-header">
            <h3><i class="fas fa-user-minus" style="color:#ef4444;margin-right:8px;"></i> Retirer du SAMS</h3>
            <button class="eff-modal-close" onclick="closeModal('modalRemove')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="user_id" id="removeUserId">
            <div class="eff-modal-body">
                <p style="color:#ef4444;font-weight:700;margin-bottom:0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i> Attention
                </p>
                <p style="color:#7a6b70;font-size:0.9rem;">
                    Êtes-vous sûr de vouloir retirer <strong style="color:#f0e6e8;" id="removeUsername"></strong> du SAMS ?
                    Sa faction sera remise à "Aucune" et son grade supprimé.
                </p>
            </div>
            <div class="eff-modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalRemove')">Annuler</button>
                <button type="submit" class="btn-confirm danger">Confirmer le retrait</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.eff-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.eff-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
}

function filterCards(query, gridId) {
    var q = query.toLowerCase();
    document.querySelectorAll('#' + gridId + ' .member-card').forEach(function(card) {
        card.style.display = card.dataset.name.includes(q) ? '' : 'none';
    });
}

function openEditGrade(userId, username, currentGrade) {
    document.getElementById('editGradeUserId').value = userId;
    document.getElementById('editGradeUsername').textContent = username;
    document.getElementById('editGradeSelect').value = currentGrade;
    document.getElementById('modalEditGrade').classList.add('show');
}

function openRecruit(userId, username) {
    document.getElementById('recruitUserId').value = userId;
    document.getElementById('recruitUsername').textContent = username;
    document.getElementById('modalRecruit').classList.add('show');
}

function openRemove(userId, username) {
    document.getElementById('removeUserId').value = userId;
    document.getElementById('removeUsername').textContent = username;
    document.getElementById('modalRemove').classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

// Fermer modal en cliquant à côté
document.querySelectorAll('.eff-modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('show');
    });
});
</script>

<?php include __DIR__ . '/includes/components.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>