<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'LSSD' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Vérifier si l'utilisateur peut recruter
$userRank = $_SESSION['user_faction_grade'] ?? '';
$canRecruit = in_array($userRank, [
    'Sergeant', 'Sergeant', 'Lieutenant', 'Lieutenant',
    'Captain', 'Division Chief', 'Undersheriff', 'Sheriff'
]) || hasRole(['*', 'Administrateur', 'Développeur', 'Staff']);

// RECRUTEMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recruit') {
    if (!$canRecruit) {
        $message = 'Accès refusé : Grade Sergeant I minimum requis pour recruter';
        $messageType = 'error';
    } else {
        try {
            $citizenId = intval($_POST['citizen_id']);
            $rank = $_POST['rank'];
            $badgeNumber = $_POST['badge_number'] ?? null;
            $matricule = $_POST['matricule'] ?? null;
            
            // Récupérer les infos du citoyen
            $stmt = $db->prepare("SELECT id, firstname, lastname FROM profiles WHERE id = ?");
            $stmt->execute([$citizenId]);
            $citizen = $stmt->fetch();
            
            if (!$citizen) {
                throw new Exception("Citoyen introuvable");
            }
            
            // Créer un compte utilisateur si n'existe pas
            $email = strtolower($citizen['firstname'] . '.' . $citizen['lastname']) . '@lssd.gov';
            $username = $citizen['firstname'] . ' ' . $citizen['lastname'];
            
            // Vérifier si le user existe déjà
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmtCheck->execute([$email]);
            $existingUser = $stmtCheck->fetch();
            
            if ($existingUser) {
                // Mettre à jour l'utilisateur existant
                $stmt = $db->prepare("
                    UPDATE users 
                    SET faction = 'LSSD', 
                        faction_rank = ?,
                        badge_number = ?,
                        matricule = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$rank, $badgeNumber, $matricule, $existingUser['id']]);
                $message = "{$username} a été recruté avec le grade de {$rank}";
            } else {
                // Créer nouvel utilisateur
                $password = password_hash('lssd2026', PASSWORD_BCRYPT);
                $stmt = $db->prepare("
                    INSERT INTO users (email, username, password, faction, faction_rank, badge_number, matricule, role)
                    VALUES (?, ?, ?, 'LSSD', ?, ?, ?, 'Citoyen')
                ");
                $stmt->execute([$email, $username, $password, $rank, $badgeNumber, $matricule]);
                $message = "{$username} recruté avec succès ! Email: {$email} / Mot de passe: lssd2026";
            }
            
            $messageType = 'success';
            
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// MODIFICATION GRADE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_rank') {
    if (!$canRecruit) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET faction_grade = ? WHERE id = ? AND faction = 'LSSD'");
            $stmt->execute([$_POST['new_rank'], intval($_POST['user_id'])]);
            $message = 'Grade mis à jour';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// MODIFICATION BADGE/MATRICULE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_badge') {
    if (!$canRecruit) {
        $message = 'Accès refusé';
        $messageType = 'error';
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE users 
                SET badge_number = ?, 
                    matricule = ? 
                WHERE id = ? AND faction = 'LSSD'
            ");
            $stmt->execute([
                $_POST['badge_number'],
                $_POST['matricule'],
                intval($_POST['user_id'])
            ]);
            $message = 'Badge et matricule mis à jour';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erreur: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// LISTE EMPLOYÉS LSSD
$stmt = $db->query("
    SELECT u.*, 
           lr.base_salary,
           GROUP_CONCAT(DISTINCT ld.abbreviation SEPARATOR ', ') as divisions,
           COUNT(DISTINCT ls.id) as sanctions_count,
           COUNT(DISTINCT le.id) as equipment_count
    FROM users u
    LEFT JOIN lssd_ranks lr ON u.faction_rank = lr.rank_name
    LEFT JOIN lssd_division_members ldm ON u.id = ldm.user_id
    LEFT JOIN lssd_divisions ld ON ldm.division_id = ld.id
    LEFT JOIN lssd_sanctions ls ON u.id = ls.officer_id AND ls.status = 'active'
    LEFT JOIN lssd_equipment le ON u.id = le.officer_id AND le.status = 'assigned'
    WHERE u.faction = 'LSSD'
    GROUP BY u.id
    ORDER BY lr.hierarchy_level ASC, u.username
");
$employees = $stmt->fetchAll();

// LISTE CITOYENS (pour recrutement)
$citizens = $db->query("SELECT id, firstname, lastname, age, phone FROM profiles ORDER BY lastname")->fetchAll();

// GRADES LSSD
$ranks = $db->query("SELECT rank_name FROM lssd_ranks ORDER BY hierarchy_level ASC")->fetchAll();

$pageTitle = 'Employés LSSD';
include __DIR__ . '/includes/header.php';
?>

<style>
.employee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
}

.employee-card {
    background: linear-gradient(135deg, #0f1729, #0a0e1a);
    border: 1px solid rgba(59, 130, 246, 0.15);
    border-radius: 14px;
    padding: 1.5rem;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.employee-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #10b981, transparent);
    opacity: 0;
    transition: opacity 0.3s;
}

.employee-card:hover {
    border-color: rgba(59, 130, 246, 0.4);
    transform: translateY(-4px);
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.15);
}

.employee-card:hover::before {
    opacity: 1;
}

.employee-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
}

.employee-avatar {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.25), rgba(30, 64, 175, 0.15));
    border: 2px solid rgba(59, 130, 246, 0.3);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #10b981;
    font-family: 'Orbitron', monospace;
    font-weight: 700;
}

.employee-info h3 {
    font-family: 'Orbitron', monospace;
    font-size: 1rem;
    color: #e8edf5;
    margin-bottom: 0.25rem;
}

.employee-rank {
    font-size: 0.85rem;
    color: #64748b;
    font-family: 'JetBrains Mono', monospace;
}

.badge-matricule {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.badge-item {
    padding: 0.3rem 0.6rem;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 6px;
    font-size: 0.75rem;
    font-family: 'JetBrains Mono', monospace;
    color: #34d399;
    font-weight: 700;
}

.employee-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.stat-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-label {
    font-size: 0.7rem;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.stat-value {
    font-weight: 600;
    color: #e8edf5;
    font-family: 'Chakra Petch', sans-serif;
}

.divisions-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.division-badge {
    padding: 0.35rem 0.7rem;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #34d399;
    font-family: 'JetBrains Mono', monospace;
}

.sanctions-warning {
    padding: 0.5rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 6px;
    color: #ef4444;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.employee-actions {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
}

.recruit-notice {
    padding: 1rem 1.5rem;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 10px;
    color: #f59e0b;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!$canRecruit): ?>
    <div class="recruit-notice">
        <i class="fas fa-info-circle" style="font-size: 1.5rem;"></i>
        <div>
            <strong>Information:</strong> Grade Sergeant I minimum requis pour recruter des membres.
        </div>
    </div>
<?php endif; ?>

<div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
    <?php if ($canRecruit): ?>
        <button onclick="LSSD.openModal('recruitModal')" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Recruter un Citoyen
        </button>
    <?php endif; ?>
    <span style="font-family: 'JetBrains Mono', monospace; padding: 0.75rem 1.5rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 10px; color: #34d399;">
        <i class="fas fa-users"></i> <?php echo count($employees); ?> employé(s)
    </span>
</div>

<div class="employee-grid">
    <?php foreach ($employees as $emp): ?>
        <div class="employee-card">
            <div class="employee-header">
                <div class="employee-avatar">
                    <?php echo strtoupper(substr($emp['username'], 0, 1)); ?>
                </div>
                <div class="employee-info">
                    <h3><?php echo htmlspecialchars($emp['username'] ?? ''); ?></h3>
                    <div class="employee-rank"><?php echo htmlspecialchars($emp['faction_rank'] ?? ''); ?></div>
                    <div class="badge-matricule">
                        <?php if ($emp['badge_number']): ?>
                            <span class="badge-item">
                                <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($emp['badge_number'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($emp['matricule']): ?>
                            <span class="badge-item">
                                <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($emp['matricule'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="employee-stats">
                <div class="stat-item">
                    <span class="stat-label"><i class="fas fa-money-bill"></i> Salaire</span>
                    <span class="stat-value">$<?php echo number_format($emp['base_salary'] ?? 0, 0); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="stat-value" style="font-size: 0.75rem; overflow: hidden; text-overflow: ellipsis;">
                        <?php echo htmlspecialchars($emp['email'] ?? ''); ?>
                    </span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><i class="fas fa-boxes"></i> Équipement</span>
                    <span class="stat-value"><?php echo $emp['equipment_count']; ?> item(s)</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><i class="fas fa-hashtag"></i> ID</span>
                    <span class="stat-value">#<?php echo str_pad($emp['id'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>
            
            <?php if ($emp['divisions']): ?>
                <div class="divisions-badges">
                    <?php foreach (explode(', ', $emp['divisions']) as $div): ?>
                        <span class="division-badge"><?php echo htmlspecialchars($div); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($emp['sanctions_count'] > 0): ?>
                <div class="sanctions-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $emp['sanctions_count']; ?> sanction(s) active(s)</strong>
                </div>
            <?php endif; ?>
            
            <div class="employee-actions">
                <button onclick="viewProfile(<?php echo $emp['id']; ?>)" class="btn btn-secondary btn-sm">
                    <i class="fas fa-eye"></i> Profil
                </button>
                <?php if ($canRecruit): ?>
                    <button onclick="changeRank(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username'] ?? ''); ?>', '<?php echo htmlspecialchars($emp['faction_grade'] ?? $emp['faction_rank'] ?? ''); ?>')" class="btn btn-secondary btn-sm">
                        <i class="fas fa-star"></i> Grade
                    </button>
                    <button onclick="editBadge(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['username']); ?>', '<?php echo htmlspecialchars($emp['badge_number'] ?? ''); ?>', '<?php echo htmlspecialchars($emp['matricule'] ?? ''); ?>')" class="btn btn-secondary btn-sm">
                        <i class="fas fa-id-badge"></i> Badge
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal Recrutement -->
<?php if ($canRecruit): ?>
<div id="recruitModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus"></i> Recruter un Citoyen</h2>
            <button class="modal-close" onclick="LSSD.closeModal('recruitModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="recruit">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Citoyen *</label>
                        <select name="citizen_id" class="form-select" required>
                            <option value="">Sélectionner un citoyen</option>
                            <?php foreach ($citizens as $citizen): ?>
                                <option value="<?php echo $citizen['id']; ?>">
                                    <?php echo htmlspecialchars($citizen['firstname'] . ' ' . $citizen['lastname']); ?>
                                    <?php echo $citizen['age'] ? " ({$citizen['age']} ans)" : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Grade Initial *</label>
                        <select name="rank" class="form-select" required>
                            <?php foreach ($ranks as $rank): ?>
                                <option value="<?php echo htmlspecialchars($rank['rank_name']); ?>">
                                    <?php echo htmlspecialchars($rank['rank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Numéro de Badge</label>
                        <input type="text" name="badge_number" class="form-input" placeholder="Ex: 1001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Matricule</label>
                        <input type="text" name="matricule" class="form-input" placeholder="Ex: LSSD-001">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSSD.closeModal('recruitModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Recruter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Changement Grade -->
<div id="rankModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-star"></i> Modifier le Grade</h2>
            <button class="modal-close" onclick="LSSD.closeModal('rankModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_rank">
                <input type="hidden" name="user_id" id="rank_user_id">
                
                <p style="margin-bottom: 1rem; color: #94a3b8;">
                    <strong>Agent:</strong> <span id="rank_username"></span><br>
                    <strong>Grade actuel:</strong> <span id="rank_current"></span>
                </p>
                
                <div class="form-group form-full">
                    <label class="form-label">Nouveau Grade *</label>
                    <select name="new_rank" class="form-select" required>
                        <?php foreach ($ranks as $rank): ?>
                            <option value="<?php echo htmlspecialchars($rank['rank_name']); ?>">
                                <?php echo htmlspecialchars($rank['rank_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSSD.closeModal('rankModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Modifier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Badge/Matricule -->
<?php if ($canRecruit): ?>
<div id="badgeModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-id-badge"></i> Modifier Badge & Matricule</h2>
            <button class="modal-close" onclick="LSSD.closeModal('badgeModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_badge">
                <input type="hidden" name="user_id" id="badge_user_id">
                
                <p style="margin-bottom: 1rem; color: #94a3b8;">
                    <strong>Agent:</strong> <span id="badge_username"></span>
                </p>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Numéro de Badge</label>
                        <input type="text" name="badge_number" id="badge_number" class="form-input" placeholder="Ex: 1001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Matricule</label>
                        <input type="text" name="matricule" id="badge_matricule" class="form-input" placeholder="Ex: LSSD-001">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="LSSD.closeModal('badgeModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Profil -->
<div id="profileModal" class="modal-overlay hidden">
    <div class="modal modal-large">
        <div class="modal-header">
            <h2><i class="fas fa-id-badge"></i> Profil de l'Agent</h2>
            <button class="modal-close" onclick="LSSD.closeModal('profileModal')">×</button>
        </div>
        <div class="modal-body" id="profileContent">
            <!-- Chargé dynamiquement -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="LSSD.closeModal('profileModal')">Fermer</button>
        </div>
    </div>
</div>

<script>
function changeRank(userId, username, currentRank) {
    document.getElementById('rank_user_id').value = userId;
    document.getElementById('rank_username').textContent = username;
    document.getElementById('rank_current').textContent = currentRank;
    LSSD.openModal('rankModal');
}

function editBadge(userId, username, badgeNumber, matricule) {
    document.getElementById('badge_user_id').value = userId;
    document.getElementById('badge_username').textContent = username;
    document.getElementById('badge_number').value = badgeNumber;
    document.getElementById('badge_matricule').value = matricule;
    LSSD.openModal('badgeModal');
}

function viewProfile(userId) {
    LSSD.openModal('profileModal');
    document.getElementById('profileContent').innerHTML = '<p style="text-align: center; padding: 2rem;">Chargement...</p>';
    
    fetch('ajax/get_employee_profile.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const emp = data.employee;
                document.getElementById('profileContent').innerHTML = `
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem;">
                        <div>
                            <h3 style="margin-bottom: 1rem; color: #e8edf5; border-bottom: 1px solid rgba(59, 130, 246, 0.2); padding-bottom: 0.5rem;">
                                <i class="fas fa-user"></i> Informations Personnelles
                            </h3>
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <div><strong>Nom:</strong> ${emp.username}</div>
                                <div><strong>Badge:</strong> ${emp.badge_number || 'Non assigné'}</div>
                                <div><strong>Matricule:</strong> ${emp.matricule || 'Non assigné'}</div>
                                <div><strong>Email:</strong> ${emp.email}</div>
                                <div><strong>Grade:</strong> ${emp.faction_rank}</div>
                                <div><strong>Salaire:</strong> $${parseFloat(emp.base_salary || 0).toLocaleString()}</div>
                            </div>
                        </div>
                        <div>
                            <h3 style="margin-bottom: 1rem; color: #e8edf5; border-bottom: 1px solid rgba(59, 130, 246, 0.2); padding-bottom: 0.5rem;">
                                <i class="fas fa-shield-alt"></i> Statut Service
                            </h3>
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <div><strong>Divisions:</strong> ${emp.divisions || 'Aucune'}</div>
                                <div><strong>Équipements:</strong> ${emp.equipment_count} item(s)</div>
                                <div><strong>Sanctions actives:</strong> ${emp.sanctions_count}</div>
                                <div><strong>Inscrit le:</strong> ${emp.created_at}</div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                document.getElementById('profileContent').innerHTML = '<p style="color: #ef4444;">Erreur de chargement</p>';
            }
        });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>