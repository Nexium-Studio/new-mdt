<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff'])) {
    die('Accès refusé');
}

$db = Database::getInstance()->getConnection();
$patientId = intval($_GET['id'] ?? 0);

if (!$patientId) {
    die('Patient invalide');
}

// Récupérer les infos du patient
$stmt = $db->prepare("SELECT * FROM profiles WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    die('Patient non trouvé');
}

// Compter les documents
$stmt = $db->prepare("SELECT COUNT(*) as count FROM sams_reports WHERE patient_id = ?");
$stmt->execute([$patientId]);
$nbRapports = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_prescriptions WHERE patient_id = ?");
$stmt->execute([$patientId]);
$nbOrdonnances = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'psy_suivi'");
$stmt->execute([$patientId]);
$nbSuiviPsy = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'naissance'");
$stmt->execute([$patientId]);
$nbNaissance = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM pregnancy_tracking WHERE patient_id = ? AND status = 'ongoing'");
$stmt->execute([$patientId]);
$nbGrossesse = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'aptitude'");
$stmt->execute([$patientId]);
$nbAptitude = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'arret_travail'");
$stmt->execute([$patientId]);
$nbArret = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'aptitude_ppa'");
$stmt->execute([$patientId]);
$nbPPA = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'psy_eval'");
$stmt->execute([$patientId]);
$nbEvalPsy = $stmt->fetch()['count'];

$stmt = $db->prepare("SELECT COUNT(*) as count FROM medical_certificates WHERE patient_id = ? AND type = 'traumatisme'");
$stmt->execute([$patientId]);
$nbTrauma = $stmt->fetch()['count'];
?>

<div class="sams-profil-header">
    <div class="sams-profil-avatar-large">
        <?php echo strtoupper(substr($patient['firstname'], 0, 1) . substr($patient['lastname'], 0, 1)); ?>
    </div>
    <div class="sams-profil-details">
        <div class="sams-profil-name"><?php echo htmlspecialchars($patient['firstname'] . ' ' . $patient['lastname']); ?></div>
        <div class="sams-profil-meta">
            <div><i class="fas fa-id-card"></i> ID: #<?php echo str_pad($patient['id'], 6, '0', STR_PAD_LEFT); ?></div>
            <?php if ($patient['age']): ?>
                <div><i class="fas fa-birthday-cake"></i> <?php echo $patient['age']; ?> ans</div>
            <?php endif; ?>
            <?php if ($patient['phone']): ?>
                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($patient['phone']); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Formulaire groupe sanguin -->
<div style="margin-bottom: 2rem;">
    <form method="POST" action="citoyens.php" style="display: flex; gap: 1rem; align-items: flex-end;">
        <input type="hidden" name="action" value="update_blood">
        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
        
        <div style="flex: 1;">
            <label style="display: block; font-size: 0.75rem; color: #7a6b70; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Groupe Sanguin</label>
            <select name="blood_type" class="form-select" required>
                <option value="">Sélectionner</option>
                <option value="A+" <?php echo $patient['blood_type'] === 'A+' ? 'selected' : ''; ?>>A+</option>
                <option value="A-" <?php echo $patient['blood_type'] === 'A-' ? 'selected' : ''; ?>>A-</option>
                <option value="B+" <?php echo $patient['blood_type'] === 'B+' ? 'selected' : ''; ?>>B+</option>
                <option value="B-" <?php echo $patient['blood_type'] === 'B-' ? 'selected' : ''; ?>>B-</option>
                <option value="AB+" <?php echo $patient['blood_type'] === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                <option value="AB-" <?php echo $patient['blood_type'] === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                <option value="O+" <?php echo $patient['blood_type'] === 'O+' ? 'selected' : ''; ?>>O+</option>
                <option value="O-" <?php echo $patient['blood_type'] === 'O-' ? 'selected' : ''; ?>>O-</option>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Enregistrer
        </button>
    </form>
</div>

<div class="sams-section-title">
    <i class="fas fa-folder-open"></i> Documents Médicaux
</div>

<div class="sams-documents-grid">
    <!-- Rapports -->
    <div class="sams-doc-item <?php echo $nbRapports > 0 ? 'has-doc' : ''; ?>" 
         onclick="<?php echo $nbRapports > 0 ? "window.location.href='rapports.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-file-medical"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Rapports Médicaux</div>
            <div class="sams-doc-status"><?php echo $nbRapports; ?> rapport(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbRapports > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbRapports > 0 ? $nbRapports : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Ordonnances -->
    <div class="sams-doc-item <?php echo $nbOrdonnances > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbOrdonnances > 0 ? "window.location.href='documents/ordonnance.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-prescription"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Ordonnances</div>
            <div class="sams-doc-status"><?php echo $nbOrdonnances; ?> ordonnance(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbOrdonnances > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbOrdonnances > 0 ? $nbOrdonnances : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Suivi Psy -->
    <div class="sams-doc-item <?php echo $nbSuiviPsy > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbSuiviPsy > 0 ? "window.location.href='documents/suivi-psy.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-brain"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Suivi Psychologique</div>
            <div class="sams-doc-status"><?php echo $nbSuiviPsy; ?> dossier(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbSuiviPsy > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbSuiviPsy > 0 ? $nbSuiviPsy : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Évaluation Psy -->
    <div class="sams-doc-item <?php echo $nbEvalPsy > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbEvalPsy > 0 ? "window.location.href='documents/evaluation-psy.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-clipboard-check"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Évaluation Psychologique</div>
            <div class="sams-doc-status"><?php echo $nbEvalPsy; ?> évaluation(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbEvalPsy > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbEvalPsy > 0 ? $nbEvalPsy : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Acte de Naissance -->
    <div class="sams-doc-item <?php echo $nbNaissance > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbNaissance > 0 ? "window.location.href='documents/naissance.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-baby"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Acte de Naissance</div>
            <div class="sams-doc-status"><?php echo $nbNaissance; ?> acte(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbNaissance > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbNaissance > 0 ? $nbNaissance : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Suivi Grossesse -->
    <div class="sams-doc-item <?php echo $nbGrossesse > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbGrossesse > 0 ? "window.location.href='documents/grossesse.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-heartbeat"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Suivi Grossesse</div>
            <div class="sams-doc-status"><?php echo $nbGrossesse; ?> suivi(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbGrossesse > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbGrossesse > 0 ? 'En cours' : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Certificat Aptitude -->
    <div class="sams-doc-item <?php echo $nbAptitude > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbAptitude > 0 ? "window.location.href='documents/certificat-aptitude.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-certificate"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Certificat d'Aptitude</div>
            <div class="sams-doc-status"><?php echo $nbAptitude; ?> certificat(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbAptitude > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbAptitude > 0 ? $nbAptitude : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Arrêt de Travail -->
    <div class="sams-doc-item <?php echo $nbArret > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbArret > 0 ? "window.location.href='documents/arret-travail.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-file-medical-alt"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Arrêt de Travail</div>
            <div class="sams-doc-status"><?php echo $nbArret; ?> arrêt(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbArret > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbArret > 0 ? $nbArret : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Aptitude PPA -->
    <div class="sams-doc-item <?php echo $nbPPA > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbPPA > 0 ? "window.location.href='documents/aptitude-ppa.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-id-badge"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Aptitude PPA</div>
            <div class="sams-doc-status"><?php echo $nbPPA; ?> certificat(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbPPA > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbPPA > 0 ? $nbPPA : 'Aucun'; ?>
        </div>
    </div>
    
    <!-- Traumatisme -->
    <div class="sams-doc-item <?php echo $nbTrauma > 0 ? 'has-doc' : ''; ?>"
         onclick="<?php echo $nbTrauma > 0 ? "window.location.href='documents/traumatisme.php?patient_id={$patient['id']}'" : ''; ?>">
        <div class="sams-doc-icon">
            <i class="fas fa-band-aid"></i>
        </div>
        <div class="sams-doc-info">
            <div class="sams-doc-name">Certificat Traumatisme</div>
            <div class="sams-doc-status"><?php echo $nbTrauma; ?> certificat(s)</div>
        </div>
        <div class="sams-doc-badge <?php echo $nbTrauma > 0 ? 'present' : 'absent'; ?>">
            <?php echo $nbTrauma > 0 ? $nbTrauma : 'Aucun'; ?>
        </div>
    </div>
</div>