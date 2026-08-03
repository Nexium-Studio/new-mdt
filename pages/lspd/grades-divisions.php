<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'LSPD' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Vérifier permissions (Sergeant I minimum)
// Grade lu depuis la DB
try { $__grdS=$db->prepare('SELECT faction_rank FROM users WHERE id=? LIMIT 1');$__grdS->execute([(int)($_SESSION['user_id']??0)]);$__grdRow=$__grdS->fetch();$userRank = $__grdRow['faction_rank'] ?? ''; } catch(Exception $e){ $userRank = $_SESSION['user_faction_rank'] ?? ''; }
$canManage = in_array($userRank, [
    'Sergeant I', 'Sergeant II', 'Lieutenant I', 'Lieutenant II',
    'Captain', 'Commander', 'Assistant Chief', 'Chief of Police'
]) || hasRole(['*', 'Administrateur', 'Développeur', 'Staff']);

// MISE À JOUR SALAIRE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_salary') {
    if (!$canManage) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("UPDATE lspd_ranks SET base_salary = ? WHERE id = ?");
            $stmt->execute([floatval($_POST['salary']), intval($_POST['rank_id'])]);
            $message = 'Salaire mis à jour';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// CRÉATION DIVISION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_division') {
    if (!$canManage) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO lspd_divisions (name, abbreviation, description, min_rank, color)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                strtoupper($_POST['abbreviation']),
                $_POST['description'],
                $_POST['min_rank'],
                $_POST['color']
            ]);
            $message = 'Division créée avec succès';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// SUPPRESSION DIVISION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_division') {
    if (!$canManage) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("DELETE FROM lspd_divisions WHERE id = ?");
            $stmt->execute([intval($_POST['division_id'])]);
            $message = 'Division supprimée';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// AJOUT MEMBRE DIVISION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    if (!$canManage) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO lspd_division_members (division_id, user_id, joined_date, role)
                VALUES (?, ?, CURDATE(), ?)
            ");
            $stmt->execute([
                intval($_POST['division_id']),
                intval($_POST['user_id']),
                $_POST['role'] ?? 'Member'
            ]);
            $message = 'Membre ajouté à la division';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// RETRAIT MEMBRE DIVISION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_member') {
    if (!$canManage) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("DELETE FROM lspd_division_members WHERE id = ?");
            $stmt->execute([intval($_POST['member_id'])]);
            $message = 'Membre retiré de la division';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// LISTE GRADES AVEC COMPTEURS
$stmt = $db->query("
    SELECT lr.*,
           COUNT(u.id) as member_count
    FROM lspd_ranks lr
    LEFT JOIN users u ON lr.rank_name = u.faction_rank AND u.faction = 'LSPD'
    GROUP BY lr.id
    ORDER BY lr.hierarchy_level ASC
");
$grades = $stmt->fetchAll();

// LISTE DIVISIONS AVEC MEMBRES
$stmt = $db->query("
    SELECT ld.*,
           COUNT(ldm.id) as member_count
    FROM lspd_divisions ld
    LEFT JOIN lspd_division_members ldm ON ld.id = ldm.division_id
    GROUP BY ld.id
    ORDER BY ld.name
");
$divisions = $stmt->fetchAll();

// EMPLOYÉS LSPD (pour ajout division)
$employees = $db->query("
    SELECT id, username, faction_rank
    FROM users
    WHERE faction = 'LSPD'
    ORDER BY username
")->fetchAll();

// GRADES (pour min_rank division)
$ranksList = $db->query("SELECT rank_name FROM lspd_ranks ORDER BY hierarchy_level ASC")->fetchAll();

$pageTitle = 'Grades & Divisions';
include __DIR__ . '/includes/header.php';
?>

<style>
.section-tabs {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid rgba(59, 130, 246, 0.1);
}

.tab-btn {
    padding: 1rem 2rem;
    background: transparent;
    border: none;
    color: #64748b;
    font-weight: 600;
    cursor: pointer;
    position: relative;
    transition: all 0.3s;
    font-family: 'Inter', sans-serif;
}

.tab-btn.active {
    color: #3b82f6;
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #3b82f6, #60a5fa);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.rank-table {
    width: 100%;
    background: linear-gradient(135deg, #0f1729, #0a0e1a);
    border: 1px solid rgba(59, 130, 246, 0.15);
    border-radius: 14px;
    overflow: hidden;
}

.rank-row {
    display: grid;
    grid-template-columns: 50px 1fr 150px 150px 100px 120px;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.08);
    transition: all 0.3s;
}

.rank-row:hover {
    background: rgba(59, 130, 246, 0.05);
}

.rank-header {
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 1.5px;
    color: #64748b;
    padding: 1.25rem 1.5rem;
    background: rgba(59, 130, 246, 0.05);
}

.hierarchy-badge {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(30, 64, 175, 0.1));
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #60a5fa;
    font-family: 'IBM Plex Mono', monospace;
}

.rank-name-cell {
    font-family: 'Montserrat', sans-serif;
    font-weight: 700;
    font-size: 0.95rem;
    color: #e8edf5;
    display: flex;
    align-items: center;
}

.member-count-badge {
    padding: 0.4rem 0.8rem;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 6px;
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 700;
    color: #60a5fa;
    font-size: 0.85rem;
}

.salary-cell {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 700;
    color: #22c55e;
    font-size: 0.95rem;
}

.salary-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 6px;
    color: #e8edf5;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.85rem;
}

.divisions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.5rem;
}

.division-card {
    background: linear-gradient(135deg, #0f1729, #0a0e1a);
    border: 1px solid rgba(59, 130, 246, 0.15);
    border-radius: 14px;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s;
}

.division-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    transition: background 0.3s;
}

.division-card:hover {
    border-color: rgba(59, 130, 246, 0.4);
    transform: translateY(-4px);
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.15);
}

.division-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.division-title {
    font-family: 'Montserrat', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.division-abbr {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 700;
}

.division-info {
    margin-bottom: 1rem;
}

.division-desc {
    color: #94a3b8;
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 1rem;
}

.division-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.85rem;
}

.division-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #64748b;
}

.division-actions {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

@media (max-width: 1200px) {
    .rank-row {
        grid-template-columns: 50px 1fr 120px 100px 80px 100px;
        font-size: 0.85rem;
    }
}

@media (max-width: 768px) {
    .rank-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
    
    .divisions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!$canManage): ?>
    <div class="alert alert-warning">
        <i class="fas fa-info-circle"></i>
        Grade Sergeant I minimum requis pour gérer les grades et divisions.
    </div>
<?php endif; ?>

<div class="section-tabs">
    <button class="tab-btn active" onclick="switchTab('grades')">
        <i class="fas fa-star"></i> Grades
    </button>
    <button class="tab-btn" onclick="switchTab('divisions')">
        <i class="fas fa-sitemap"></i> Divisions
    </button>
</div>

<!-- TAB GRADES -->
<div id="grades-tab" class="tab-content active">
    <div class="rank-table">
        <div class="rank-row rank-header">
            <div>Niv</div>
            <div>Grade</div>
            <div>Membres</div>
            <div>Salaire</div>
            <div>Recrut.</div>
            <div>Actions</div>
        </div>
        <?php foreach ($grades as $grade): ?>
            <div class="rank-row">
                <div>
                    <div class="hierarchy-badge"><?php echo $grade['hierarchy_level']; ?></div>
                </div>
                <div class="rank-name-cell">
                    <?php echo htmlspecialchars($grade['rank_name']); ?>
                </div>
                <div>
                    <span class="member-count-badge">
                        <i class="fas fa-users"></i> <?php echo $grade['member_count']; ?>
                    </span>
                </div>
                <div class="salary-cell">
                    $<?php echo number_format($grade['base_salary'], 0); ?>
                </div>
                <div>
                    <?php if ($grade['can_recruit']): ?>
                        <span style="color: #22c55e;"><i class="fas fa-check-circle"></i></span>
                    <?php else: ?>
                        <span style="color: #64748b;"><i class="fas fa-times-circle"></i></span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($canManage): ?>
                        <button onclick="editSalary(<?php echo $grade['id']; ?>, '<?php echo htmlspecialchars($grade['rank_name']); ?>', <?php echo $grade['base_salary']; ?>)" class="btn btn-secondary btn-sm">
                            <i class="fas fa-edit"></i> Salaire
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- TAB DIVISIONS -->
<div id="divisions-tab" class="tab-content">
    <?php if ($canManage): ?>
        <button onclick="LSPD.openModal('createDivisionModal')" class="btn btn-primary" style="margin-bottom: 2rem;">
            <i class="fas fa-plus"></i> Créer une Division
        </button>
    <?php endif; ?>
    
    <div class="divisions-grid">
        <?php foreach ($divisions as $div): ?>
            <div class="division-card" style="border-left: 4px solid <?php echo htmlspecialchars($div['color']); ?>;">
                <div class="division-header">
                    <div>
                        <div class="division-title"><?php echo htmlspecialchars($div['name']); ?></div>
                        <div class="division-abbr"><?php echo htmlspecialchars($div['abbreviation']); ?></div>
                    </div>
                    <?php if ($canManage): ?>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Supprimer cette division ?');">
                            <input type="hidden" name="action" value="delete_division">
                            <input type="hidden" name="division_id" value="<?php echo $div['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #ef4444;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <div class="division-info">
                    <div class="division-desc"><?php echo htmlspecialchars($div['description']); ?></div>
                    
                    <div class="division-meta">
                        <div class="division-meta-item">
                            <i class="fas fa-star"></i>
                            <span>Min: <?php echo htmlspecialchars($div['min_rank']); ?></span>
                        </div>
                        <div class="division-meta-item">
                            <i class="fas fa-users"></i>
                            <span><?php echo $div['member_count']; ?> membre(s)</span>
                        </div>
                        <div class="division-meta-item">
                            <i class="fas fa-circle" style="color: <?php echo htmlspecialchars($div['color']); ?>;"></i>
                            <span><?php echo htmlspecialchars($div['color']); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="division-actions">
                    <button onclick="viewMembers(<?php echo $div['id']; ?>, '<?php echo htmlspecialchars($div['name']); ?>')" class="btn btn-secondary btn-sm">
                        <i class="fas fa-users"></i> Membres
                    </button>
                    <?php if ($canManage): ?>
                        <button onclick="addMember(<?php echo $div['id']; ?>, '<?php echo htmlspecialchars($div['name']); ?>')" class="btn btn-secondary btn-sm">
                            <i class="fas fa-user-plus"></i> Ajouter
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Modifier Salaire -->
<?php if ($canManage): ?>
<div id="salaryModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-money-bill"></i> Modifier le Salaire</h2>
            <button class="modal-close" onclick="LSPD.closeModal('salaryModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_salary">
                <input type="hidden" name="rank_id" id="salary_rank_id">
                
                <p style="margin-bottom: 1rem; color: #94a3b8;">
                    <strong>Grade:</strong> <span id="salary_rank_name"></span>
                </p>
                
                <div class="form-group form-full">
                    <label class="form-label">Nouveau Salaire ($) *</label>
                    <input type="number" name="salary" id="salary_amount" class="form-input" step="0.01" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSPD.closeModal('salaryModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Modifier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Créer Division -->
<div id="createDivisionModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Créer une Division</h2>
            <button class="modal-close" onclick="LSPD.closeModal('createDivisionModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_division">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom de la Division *</label>
                        <input type="text" name="name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Abréviation *</label>
                        <input type="text" name="abbreviation" class="form-input" maxlength="10" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Couleur *</label>
                        <input type="color" name="color" class="form-input" value="#3b82f6" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Grade Minimum *</label>
                        <select name="min_rank" class="form-select" required>
                            <?php foreach ($ranksList as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['rank_name']); ?>">
                                    <?php echo htmlspecialchars($r['rank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSPD.closeModal('createDivisionModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Créer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ajouter Membre Division -->
<div id="addMemberModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Ajouter un Membre</h2>
            <button class="modal-close" onclick="LSPD.closeModal('addMemberModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="division_id" id="add_member_division_id">
                
                <p style="margin-bottom: 1rem; color: #94a3b8;">
                    <strong>Division:</strong> <span id="add_member_division_name"></span>
                </p>
                
                <div class="form-group form-full">
                    <label class="form-label">Agent *</label>
                    <select name="user_id" class="form-select" required>
                        <option value="">Sélectionner un agent</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['username']); ?> (<?php echo htmlspecialchars($emp['faction_rank']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group form-full">
                    <label class="form-label">Rôle dans la Division</label>
                    <input type="text" name="role" class="form-input" placeholder="Ex: Leader, Member, Specialist">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSPD.closeModal('addMemberModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Voir Membres -->
<div id="membersModal" class="modal-overlay hidden">
    <div class="modal modal-large">
        <div class="modal-header">
            <h2><i class="fas fa-users"></i> Membres de la Division</h2>
            <button class="modal-close" onclick="LSPD.closeModal('membersModal')">×</button>
        </div>
        <div class="modal-body" id="membersContent">
            <!-- Chargé dynamiquement -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="LSPD.closeModal('membersModal')">Fermer</button>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(tab + '-tab').classList.add('active');
}

function editSalary(rankId, rankName, currentSalary) {
    document.getElementById('salary_rank_id').value = rankId;
    document.getElementById('salary_rank_name').textContent = rankName;
    document.getElementById('salary_amount').value = currentSalary;
    LSPD.openModal('salaryModal');
}

function addMember(divisionId, divisionName) {
    document.getElementById('add_member_division_id').value = divisionId;
    document.getElementById('add_member_division_name').textContent = divisionName;
    LSPD.openModal('addMemberModal');
}

function viewMembers(divisionId, divisionName) {
    LSPD.openModal('membersModal');
    document.getElementById('membersContent').innerHTML = '<p style="text-align: center; padding: 2rem;">Chargement...</p>';
    
    fetch('ajax/get_division_members.php?id=' + divisionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `<h3 style="margin-bottom: 1rem; color: #e8edf5;">${divisionName}</h3>`;
                
                if (data.members.length === 0) {
                    html += '<p style="text-align: center; color: #64748b; padding: 2rem;">Aucun membre dans cette division</p>';
                } else {
                    html += '<table style="width: 100%;"><thead><tr><th>Badge</th><th>Nom</th><th>Grade</th><th>Rôle</th><th>Depuis</th><?php echo $canManage ? "<th>Actions</th>" : ""; ?></tr></thead><tbody>';
                    
                    data.members.forEach(member => {
                        html += `
                            <tr>
                                <td>#${String(member.user_id).padStart(4, '0')}</td>
                                <td>${member.username}</td>
                                <td>${member.faction_rank}</td>
                                <td>${member.role || 'Member'}</td>
                                <td>${member.joined_date}</td>
                                <?php if ($canManage): ?>
                                <td>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Retirer ce membre ?');">
                                        <input type="hidden" name="action" value="remove_member">
                                        <input type="hidden" name="member_id" value="${member.id}">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #ef4444;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table>';
                }
                
                document.getElementById('membersContent').innerHTML = html;
            } else {
                document.getElementById('membersContent').innerHTML = '<p style="color: #ef4444;">Erreur de chargement</p>';
            }
        });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>