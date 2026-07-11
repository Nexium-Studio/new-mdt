<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Mise à jour du groupe sanguin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_blood') {
    try {
        $stmt = $db->prepare("UPDATE profiles SET blood_type = ? WHERE id = ?");
        $stmt->execute([$_POST['blood_type'], $_POST['patient_id']]);
        $message = 'Groupe sanguin mis à jour';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'Erreur lors de la mise à jour';
        $messageType = 'error';
    }
}

$search = $_GET['search'] ?? '';
$where = "WHERE 1=1";
$params = [];

if ($search) {
    $where .= " AND (firstname LIKE :s1 OR lastname LIKE :s2 OR phone LIKE :s3)";
    $params['s1'] = "%$search%";
    $params['s2'] = "%$search%";
    $params['s3'] = "%$search%";
}

$stmt = $db->prepare("SELECT * FROM profiles $where ORDER BY lastname, firstname");
$stmt->execute($params);
$citoyens = $stmt->fetchAll();

$pageTitle = 'Patients';
include __DIR__ . '/includes/header.php';
?>

<style>
/* === SEARCH BAR === */
.sams-search-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.sams-search-bar {
    position: relative;
    flex: 1;
    max-width: 600px;
}

.sams-search-bar input {
    width: 100%;
    padding: 1rem 1.25rem 1rem 3.5rem;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.05), rgba(13, 18, 32, 0.8));
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 12px;
    color: #f0e6e8;
    font-family: 'Chakra Petch', sans-serif;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.sams-search-bar input:focus {
    outline: none;
    border-color: #ff1744;
    background: rgba(13, 18, 32, 0.95);
    box-shadow: 0 0 0 4px rgba(255, 23, 68, 0.1), 0 0 30px rgba(255, 23, 68, 0.15);
    transform: translateY(-1px);
}

.sams-search-bar input::placeholder {
    color: #4a3d42;
}

.sams-search-icon {
    position: absolute;
    left: 1.25rem;
    top: 50%;
    transform: translateY(-50%);
    color: #7a6b70;
    font-size: 1rem;
    transition: all 0.3s;
}

.sams-search-bar input:focus + .sams-search-icon {
    color: #ff1744;
    transform: translateY(-50%) scale(1.1);
}

.sams-patients-count {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.9rem;
    font-weight: 600;
    color: #7a6b70;
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.08), rgba(220, 20, 60, 0.04));
    border: 1px solid rgba(255, 23, 68, 0.2);
    border-radius: 10px;
    white-space: nowrap;
}

/* === PATIENTS GRID === */
.sams-patients-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.75rem;
}

/* === PATIENT CARD PREMIUM === */
.sams-patient-card {
    background: linear-gradient(135deg, rgba(13, 18, 32, 0.95), rgba(17, 26, 46, 0.9));
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 16px;
    padding: 2rem;
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    cursor: pointer;
}

.sams-patient-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, #ff1744, #ff4569, #ff1744, transparent);
    opacity: 0;
    transition: opacity 0.4s;
}

.sams-patient-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(255, 23, 68, 0.03), transparent 70%);
    opacity: 0;
    transition: opacity 0.4s;
}

.sams-patient-card:hover {
    border-color: rgba(255, 23, 68, 0.4);
    transform: translateY(-6px);
    box-shadow: 
        0 12px 40px rgba(0, 0, 0, 0.3),
        0 0 40px rgba(255, 23, 68, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.sams-patient-card:hover::before,
.sams-patient-card:hover::after {
    opacity: 1;
}

/* === PATIENT HEADER === */
.sams-patient-header {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 1.75rem;
    position: relative;
    z-index: 1;
}

.sams-patient-avatar {
    width: 64px;
    height: 64px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.25), rgba(220, 20, 60, 0.15));
    border: 2px solid rgba(255, 23, 68, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Orbitron', monospace;
    font-size: 1.4rem;
    font-weight: 800;
    color: #ff1744;
    flex-shrink: 0;
    box-shadow: 0 0 20px rgba(255, 23, 68, 0.2);
    transition: all 0.3s;
}

.sams-patient-card:hover .sams-patient-avatar {
    transform: scale(1.05) rotate(-5deg);
    box-shadow: 0 0 30px rgba(255, 23, 68, 0.4);
}

.sams-patient-info-header {
    flex: 1;
}

.sams-patient-name {
    font-family: 'Orbitron', monospace;
    font-size: 1.2rem;
    font-weight: 800;
    color: #f0e6e8;
    margin-bottom: 0.5rem;
    letter-spacing: 0.5px;
    text-shadow: 0 0 10px rgba(255, 23, 68, 0.2);
}

.sams-patient-id {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    color: #7a6b70;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

/* === PATIENT STATS === */
.sams-patient-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1.75rem;
    padding: 1.25rem;
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 23, 68, 0.08);
    border-radius: 12px;
}

.sams-stat-item {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.sams-stat-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #4a3d42;
}

.sams-stat-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #f0e6e8;
    font-family: 'Chakra Petch', sans-serif;
}

.sams-stat-value.blood-type {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.9rem;
    background: linear-gradient(135deg, rgba(220, 20, 60, 0.15), rgba(255, 23, 68, 0.08));
    border: 1px solid rgba(255, 23, 68, 0.25);
    border-radius: 8px;
    font-family: 'Orbitron', monospace;
    font-size: 0.85rem;
    font-weight: 700;
    color: #ff4569;
    letter-spacing: 1px;
}

/* === PATIENT ACTIONS === */
.sams-patient-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.sams-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 0.9rem 1rem;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.08), rgba(220, 20, 60, 0.04));
    border: 1px solid rgba(255, 23, 68, 0.2);
    border-radius: 10px;
    color: #ff4569;
    text-decoration: none;
    font-family: 'Chakra Petch', sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.sams-action-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.15), rgba(220, 20, 60, 0.08));
    opacity: 0;
    transition: opacity 0.3s;
}

.sams-action-btn:hover {
    border-color: #ff1744;
    color: #ff1744;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 23, 68, 0.2);
}

.sams-action-btn:hover::before {
    opacity: 1;
}

.sams-action-btn i {
    font-size: 0.9rem;
    position: relative;
    z-index: 1;
}

.sams-action-btn span {
    position: relative;
    z-index: 1;
}

/* === MODAL PROFIL COMPLET === */
.sams-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 5, 15, 0.9);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 2rem;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.sams-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.sams-modal {
    background: linear-gradient(135deg, #0d1220, #111a2e);
    border: 1px solid rgba(255, 23, 68, 0.3);
    border-radius: 20px;
    max-width: 1000px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 
        0 0 60px rgba(255, 23, 68, 0.2),
        0 30px 90px rgba(0, 0, 0, 0.6);
    transform: scale(0.9);
    transition: transform 0.3s;
}

.sams-modal-overlay.active .sams-modal {
    transform: scale(1);
}

.sams-modal::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, #ff1744, #ff2d78, #ff1744, transparent);
}

.sams-modal-header {
    padding: 2rem 2.5rem;
    border-bottom: 1px solid rgba(255, 23, 68, 0.15);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(255, 23, 68, 0.03);
}

.sams-modal-title {
    font-family: 'Orbitron', monospace;
    font-size: 1.3rem;
    font-weight: 800;
    background: linear-gradient(135deg, #ff1744, #ff4569);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: 1px;
}

.sams-modal-close {
    width: 40px;
    height: 40px;
    border: 1px solid rgba(255, 23, 68, 0.25);
    border-radius: 10px;
    background: rgba(255, 23, 68, 0.08);
    color: #ff4569;
    font-size: 1.4rem;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sams-modal-close:hover {
    background: rgba(255, 23, 68, 0.2);
    border-color: #ff1744;
    transform: rotate(90deg);
}

.sams-modal-body {
    padding: 2.5rem;
}

/* === PROFIL PATIENT MODAL === */
.sams-profil-header {
    display: flex;
    align-items: center;
    gap: 2rem;
    padding: 2rem;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.08), rgba(220, 20, 60, 0.04));
    border: 1px solid rgba(255, 23, 68, 0.15);
    border-radius: 16px;
    margin-bottom: 2rem;
}

.sams-profil-avatar-large {
    width: 90px;
    height: 90px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.3), rgba(220, 20, 60, 0.2));
    border: 2px solid rgba(255, 23, 68, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Orbitron', monospace;
    font-size: 2rem;
    font-weight: 900;
    color: #ff1744;
    box-shadow: 0 0 30px rgba(255, 23, 68, 0.3);
}

.sams-profil-details {
    flex: 1;
}

.sams-profil-name {
    font-family: 'Orbitron', monospace;
    font-size: 1.8rem;
    font-weight: 900;
    color: #f0e6e8;
    margin-bottom: 0.5rem;
}

.sams-profil-meta {
    display: flex;
    gap: 2rem;
    font-size: 0.9rem;
    color: #7a6b70;
}

/* === SECTIONS DOCUMENTS === */
.sams-section-title {
    font-family: 'Orbitron', monospace;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: #7a6b70;
    margin: 2rem 0 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255, 23, 68, 0.1);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.sams-section-title::before {
    content: '';
    width: 3px;
    height: 18px;
    background: linear-gradient(180deg, #ff1744, #ff4569);
    border-radius: 2px;
}

.sams-documents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.sams-doc-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 23, 68, 0.1);
    border-radius: 12px;
    transition: all 0.3s;
}

.sams-doc-item:hover {
    border-color: rgba(255, 23, 68, 0.3);
    background: rgba(255, 23, 68, 0.05);
    transform: translateX(4px);
}

.sams-doc-item.has-doc {
    cursor: pointer;
}

.sams-doc-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.15), rgba(220, 20, 60, 0.08));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ff4569;
    font-size: 1.1rem;
    margin-right: 1rem;
}

.sams-doc-item.has-doc .sams-doc-icon {
    background: linear-gradient(135deg, rgba(0, 255, 136, 0.15), rgba(0, 204, 108, 0.08));
    color: #00ff88;
}

.sams-doc-info {
    flex: 1;
}

.sams-doc-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: #f0e6e8;
    margin-bottom: 0.25rem;
}

.sams-doc-status {
    font-size: 0.75rem;
    color: #7a6b70;
}

.sams-doc-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}

.sams-doc-badge.present {
    background: rgba(0, 255, 136, 0.1);
    border: 1px solid rgba(0, 255, 136, 0.3);
    color: #00ff88;
}

.sams-doc-badge.absent {
    background: rgba(120, 100, 110, 0.1);
    border: 1px solid rgba(120, 100, 110, 0.2);
    color: #786e72;
}

/* === EMPTY STATE === */
.sams-empty-state {
    text-align: center;
    padding: 5rem 2rem;
}

.sams-empty-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 2rem;
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(255, 23, 68, 0.08), rgba(220, 20, 60, 0.04));
    border: 2px dashed rgba(255, 23, 68, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
}

.sams-empty-icon i {
    font-size: 3rem;
    color: #7a6b70;
    opacity: 0.4;
}

.sams-empty-title {
    font-family: 'Orbitron', monospace;
    font-size: 1.2rem;
    font-weight: 700;
    color: #7a6b70;
    margin-bottom: 0.75rem;
}

.sams-empty-text {
    color: #4a3d42;
    font-size: 0.95rem;
}

/* === RESPONSIVE === */
@media (max-width: 1024px) {
    .sams-patients-grid {
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    }
}

@media (max-width: 768px) {
    .sams-search-container {
        flex-direction: column;
        align-items: stretch;
    }
    
    .sams-search-bar {
        max-width: 100%;
    }
    
    .sams-patients-grid {
        grid-template-columns: 1fr;
    }
    
    .sams-documents-grid {
        grid-template-columns: 1fr;
    }
    
    .sams-patient-stats {
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

<div class="sams-search-container">
    <div class="sams-search-bar">
        <form method="GET">
            <input type="text" name="search" placeholder="Rechercher un patient (nom, prénom, téléphone, groupe sanguin)..." value="<?php echo htmlspecialchars($search); ?>">
            <i class="fas fa-search sams-search-icon"></i>
        </form>
    </div>
    <div class="sams-patients-count">
        <i class="fas fa-users"></i> <?php echo count($citoyens); ?> patient(s)
    </div>
</div>

<?php if (empty($citoyens)): ?>
    <div class="card">
        <div class="card-body">
            <div class="sams-empty-state">
                <div class="sams-empty-icon">
                    <i class="fas fa-user-injured"></i>
                </div>
                <h3 class="sams-empty-title">Aucun patient trouvé</h3>
                <p class="sams-empty-text">Modifiez votre recherche ou consultez tous les patients</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="sams-patients-grid">
        <?php foreach ($citoyens as $c): ?>
            <div class="sams-patient-card" onclick="openPatientModal(<?php echo $c['id']; ?>)">
                <div class="sams-patient-header">
                    <div class="sams-patient-avatar">
                        <?php echo strtoupper(substr($c['firstname'], 0, 1) . substr($c['lastname'], 0, 1)); ?>
                    </div>
                    <div class="sams-patient-info-header">
                        <div class="sams-patient-name"><?php echo htmlspecialchars($c['firstname'] . ' ' . $c['lastname']); ?></div>
                        <div class="sams-patient-id">ID: #<?php echo str_pad($c['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                </div>
                
                <div class="sams-patient-stats">
                    <?php if (!empty($c['age'])): ?>
                        <div class="sams-stat-item">
                            <div class="sams-stat-label">Âge</div>
                            <div class="sams-stat-value"><?php echo $c['age']; ?> ans</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($c['phone'])): ?>
                        <div class="sams-stat-item">
                            <div class="sams-stat-label">Téléphone</div>
                            <div class="sams-stat-value"><?php echo htmlspecialchars($c['phone']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($c['blood_type'])): ?>
                        <div class="sams-stat-item">
                            <div class="sams-stat-label">Groupe Sanguin</div>
                            <div class="sams-stat-value blood-type">
                                <i class="fas fa-tint"></i>
                                <?php echo htmlspecialchars($c['blood_type']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($c['nationality'])): ?>
                        <div class="sams-stat-item">
                            <div class="sams-stat-label">Nationalité</div>
                            <div class="sams-stat-value"><?php echo htmlspecialchars($c['nationality']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="sams-patient-actions" onclick="event.stopPropagation()">
                    <a href="documents/ordonnance.php?patient_id=<?php echo $c['id']; ?>" class="sams-action-btn">
                        <i class="fas fa-prescription"></i>
                        <span>Ordonnances</span>
                    </a>
                    <a href="documents/grossesse.php?patient_id=<?php echo $c['id']; ?>" class="sams-action-btn">
                        <i class="fas fa-heartbeat"></i>
                        <span>Grossesse</span>
                    </a>
                    <a href="documents/arret-travail.php?patient_id=<?php echo $c['id']; ?>" class="sams-action-btn">
                        <i class="fas fa-file-medical"></i>
                        <span>Arrêt</span>
                    </a>
                    <a href="rapports.php?patient_id=<?php echo $c['id']; ?>" class="sams-action-btn">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Rapports</span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal Profil Patient Complet -->
<div id="patientModal" class="sams-modal-overlay">
    <div class="sams-modal">
        <div class="sams-modal-header">
            <h2 class="sams-modal-title">Dossier Médical Complet</h2>
            <button class="sams-modal-close" onclick="closePatientModal()">×</button>
        </div>
        <div class="sams-modal-body" id="modalContent">
            <!-- Contenu chargé dynamiquement -->
        </div>
    </div>
</div>

<script>
function openPatientModal(patientId) {
    const modal = document.getElementById('patientModal');
    const content = document.getElementById('modalContent');
    
    // Afficher le modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Charger le contenu via AJAX
    content.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #ff1744;"></i></div>';
    
    fetch('ajax/get_patient_profile.php?id=' + patientId)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
            if (window.SamsSelect) SamsSelect.enhanceAll(content); // enhance les <select> injectés
            if (window.SamsDateTime) SamsDateTime.enhanceAll(content); // enhance les date/heure injectés
        })
        .catch(error => {
            content.innerHTML = '<div style="text-align: center; padding: 3rem; color: #ff1744;">Erreur de chargement</div>';
        });
}

function closePatientModal() {
    const modal = document.getElementById('patientModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Fermer avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePatientModal();
    }
});

// Fermer en cliquant à l'extérieur
document.getElementById('patientModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePatientModal();
    }
});
</script>

<?php include __DIR__ . '/includes/components.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>