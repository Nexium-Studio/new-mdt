<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/faction_helper.php';
requireLogin();
$panelCfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../../config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$companyName = $panelCfg['name'];
$companyColor = $panelCfg['color'];
$companyFaction = $panelCfg['faction'];
$dealershipKey = $panelCfg['dealership_key'];

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Maintenance Véhicules';
$db = Database::getInstance()->getConnection();
$dk = $db->quote($dealershipKey);
$message = ''; $msgType = '';

// Permissions (faction_grades — dynamique)
$__gradeStmt = $db->prepare('SELECT faction_grade FROM users WHERE id=? LIMIT 1');
$__gradeStmt->execute([$_SESSION['user_id'] ?? 0]);
$__grade = (string)($__gradeStmt->fetchColumn() ?: '');
$perms = getGradePermissions($companyFaction, $__grade);
$canManage = $perms['can_manage_stock'] || hasRole(['*', 'Administrateur']);

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    
    // Créer maintenance
    if ($action === 'create_maintenance') {
        try {
            $db->beginTransaction();
            
            $piecesRemplacees = !empty($_POST['pieces_remplacees']) ? json_encode(array_values(array_filter($_POST['pieces_remplacees']))) : null;
            
            $db->prepare("
                INSERT INTO lsm_maintenances 
                (vehicule_id, type, description, date_debut, cout, pieces_remplacees, technicien, garage, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$_POST['vehicule_id'],
                $_POST['type'] ?? 'revision',
                sanitize($_POST['description']),
                $_POST['date_debut'],
                !empty($_POST['cout']) ? (float)$_POST['cout'] : 0,
                $piecesRemplacees,
                sanitize($_POST['technicien'] ?? ''),
                sanitize($_POST['garage'] ?? ''),
                $_SESSION['user_id']
            ]);
            
            // Mettre véhicule en maintenance
            $db->prepare("UPDATE lsm_vehicules SET statut='maintenance' WHERE id=? AND dealership_key=?")->execute([(int)$_POST['vehicule_id'], $dealershipKey]);
            
            $db->commit();
            $message = "✅ Maintenance créée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Démarrer maintenance
    if ($action === 'demarrer_maintenance') {
        try {
            $id = (int)$_POST['maintenance_id'];
            $db->prepare("UPDATE lsm_maintenances SET statut='en_cours' WHERE id=?")->execute([$id]);
            $message = "✅ Maintenance démarrée";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Terminer maintenance
    if ($action === 'terminer_maintenance') {
        try {
            $db->beginTransaction();
            
            $id = (int)$_POST['maintenance_id'];
            $vehiculeId = (int)$_POST['vehicule_id'];
            $coutFinal = !empty($_POST['cout_final']) ? (float)$_POST['cout_final'] : 0;
            
            $db->prepare("
                UPDATE lsm_maintenances 
                SET statut='terminee', date_fin=CURDATE(), cout=?
                WHERE id=?
            ")->execute([$coutFinal, $id]);
            
            // Remettre véhicule disponible
            $db->prepare("UPDATE lsm_vehicules SET statut='disponible' WHERE id=? AND dealership_key=?")->execute([$vehiculeId, $dealershipKey]);
            
            $db->commit();
            $message = "✅ Maintenance terminée - Véhicule disponible";
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterType = $_GET['type'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$search = $_GET['search'] ?? '';

// Requête maintenances
$sql = "
    SELECT m.*,
           v.modele as vehicule_modele, v.reference as vehicule_reference,
           ma.nom as marque_nom,
           u.username as created_by_nom
    FROM lsm_maintenances m
    JOIN lsm_vehicules v ON m.vehicule_id = v.id
    LEFT JOIN lsm_marques ma ON v.marque_id = ma.id
    JOIN users u ON m.created_by = u.id
    WHERE v.dealership_key = $dk
";

if ($filterType) $sql .= " AND m.type = " . $db->quote($filterType);
if ($filterStatut) $sql .= " AND m.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (v.modele LIKE " . $db->quote("%$search%") . " OR v.reference LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY m.created_at DESC";

$maintenances = $db->query($sql)->fetchAll();

// Véhicules pour maintenance
$vehiculesPourMaintenance = $db->query("
    SELECT v.*, m.nom as marque_nom
    FROM lsm_vehicules v
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    WHERE v.statut IN ('disponible', 'reserve') AND v.dealership_key = $dk
    ORDER BY m.nom, v.modele
")->fetchAll();

// Stats
$stats = [
    'total' => count($maintenances),
    'en_attente' => count(array_filter($maintenances, fn($m) => $m['statut'] === 'en_attente')),
    'en_cours' => count(array_filter($maintenances, fn($m) => $m['statut'] === 'en_cours')),
    'terminee' => count(array_filter($maintenances, fn($m) => $m['statut'] === 'terminee')),
];

$coutTotal = array_sum(array_map(fn($m) => $m['cout'], array_filter($maintenances, fn($m) => $m['statut'] === 'terminee')));

$statutColors = [
    'en_attente' => '#f59e0b',
    'en_cours' => '#3b82f6',
    'terminee' => '#22c55e'
];

$typeColors = [
    'revision' => '#3b82f6',
    'reparation' => '#ef4444',
    'controle' => '#f59e0b',
    'preparation_vente' => '#22c55e'
];

include __DIR__ . '/includes/header.php';
?>



<?php if ($message): ?>
    <div class="alert alert-<?php echo $msgType; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Maintenances</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['en_attente']; ?></div>
        <div class="stat-label">En Attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['en_cours']; ?></div>
        <div class="stat-label">En Cours</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">$<?php echo number_format($coutTotal, 0); ?></div>
        <div class="stat-label">Coût Total</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-tools"></i> Maintenances</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createMaintenanceModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouvelle Maintenance
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="type" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous types</option>
            <option value="revision" <?php echo $filterType === 'revision' ? 'selected' : ''; ?>>Révision</option>
            <option value="reparation" <?php echo $filterType === 'reparation' ? 'selected' : ''; ?>>Réparation</option>
            <option value="controle" <?php echo $filterType === 'controle' ? 'selected' : ''; ?>>Contrôle</option>
            <option value="preparation_vente" <?php echo $filterType === 'preparation_vente' ? 'selected' : ''; ?>>Préparation Vente</option>
        </select>
        
        <select name="statut" class="form-select" style="max-width: 150px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="en_attente" <?php echo $filterStatut === 'en_attente' ? 'selected' : ''; ?>>En Attente</option>
            <option value="en_cours" <?php echo $filterStatut === 'en_cours' ? 'selected' : ''; ?>>En Cours</option>
            <option value="terminee" <?php echo $filterStatut === 'terminee' ? 'selected' : ''; ?>>Terminée</option>
        </select>
        
        <?php if ($search || $filterType || $filterStatut): ?>
            <a href="maintenance.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste maintenances -->
<?php if (empty($maintenances)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-tools" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucune maintenance</h3>
        <p style="color: #64748b;">Ajoutez votre première maintenance</p>
    </div>
<?php else: ?>
    <?php foreach ($maintenances as $maintenance): 
        $pieces = $maintenance['pieces_remplacees'] ? json_decode($maintenance['pieces_remplacees'], true) : [];
        $duree = $maintenance['date_fin'] ? (strtotime($maintenance['date_fin']) - strtotime($maintenance['date_debut'])) / 86400 : null;
    ?>
        <div class="maintenance-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                        <span class="badge" style="background: <?php echo $typeColors[$maintenance['type']]; ?>20; color: <?php echo $typeColors[$maintenance['type']]; ?>; border-color: <?php echo $typeColors[$maintenance['type']]; ?>;">
                            <?php echo ucfirst(str_replace('_', ' ', $maintenance['type'])); ?>
                        </span>
                        <span class="badge" style="background: <?php echo $statutColors[$maintenance['statut']]; ?>20; color: <?php echo $statutColors[$maintenance['statut']]; ?>; border-color: <?php echo $statutColors[$maintenance['statut']]; ?>;">
                            <?php echo ucfirst(str_replace('_', ' ', $maintenance['statut'])); ?>
                        </span>
                    </div>
                    
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.5rem;">
                        <?php echo htmlspecialchars($maintenance['marque_nom']); ?> <?php echo htmlspecialchars($maintenance['vehicule_modele']); ?>
                    </h3>
                    
                    <div style="color: #64748b;">
                        Réf: <?php echo $maintenance['vehicule_reference']; ?> • 
                        Créé par: <?php echo htmlspecialchars($maintenance['created_by_nom']); ?>
                    </div>
                </div>
                
                <?php if ($maintenance['cout'] > 0): ?>
                    <div style="text-align: right;">
                        <div style="font-size: 1.8rem; font-weight: 700; color: #ef4444; font-family: 'Orbitron', monospace;">
                            $<?php echo number_format($maintenance['cout'], 2); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">Coût Total</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
                    <i class="fas fa-file-alt"></i> Description:
                </div>
                <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($maintenance['description'])); ?></div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Date Début</div>
                    <div style="font-weight: 600; margin-top: 0.25rem; color: #e8edf5;">
                        <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($maintenance['date_debut'])); ?>
                    </div>
                </div>
                
                <?php if ($maintenance['date_fin']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Date Fin</div>
                        <div style="font-weight: 600; margin-top: 0.25rem; color: #e8edf5;">
                            <i class="fas fa-calendar-check"></i> <?php echo date('d/m/Y', strtotime($maintenance['date_fin'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($duree !== null): ?>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b;">Durée</div>
                            <div style="font-weight: 600; margin-top: 0.25rem; color: #e8edf5;">
                                <i class="fas fa-clock"></i> <?php echo ceil($duree); ?> jour(s)
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($maintenance['technicien']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Technicien</div>
                        <div style="font-weight: 600; margin-top: 0.25rem; color: #e8edf5;">
                            <i class="fas fa-user-cog"></i> <?php echo htmlspecialchars($maintenance['technicien']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($maintenance['garage']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Garage</div>
                        <div style="font-weight: 600; margin-top: 0.25rem; color: #e8edf5;">
                            <i class="fas fa-warehouse"></i> <?php echo htmlspecialchars($maintenance['garage']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($pieces)): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.75rem;">
                        <i class="fas fa-cogs"></i> Pièces Remplacées:
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <?php foreach ($pieces as $piece): ?>
                            <span style="padding: 0.4rem 0.8rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px; font-size: 0.8rem; color: #fca5a5;">
                                <i class="fas fa-wrench"></i> <?php echo htmlspecialchars($piece); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <?php if ($maintenance['statut'] === 'en_attente'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="demarrer_maintenance">
                            <input type="hidden" name="maintenance_id" value="<?php echo $maintenance['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-play"></i> Démarrer
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($maintenance['statut'] === 'en_cours'): ?>
                        <button onclick="terminerMaintenance(<?php echo $maintenance['id']; ?>, <?php echo $maintenance['vehicule_id']; ?>, <?php echo $maintenance['cout']; ?>)" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                            <i class="fas fa-check"></i> Terminer
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Maintenance -->
<div id="createMaintenanceModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle Maintenance</h2>
            <button class="modal-close" onclick="Company.closeModal('createMaintenanceModal')">×</button>
        </div>
        <form method="POST" id="createMaintenanceForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_maintenance">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Véhicule *</label>
                        <select name="vehicule_id" class="form-select" required>
                            <option value="">Choisir un véhicule...</option>
                            <?php foreach ($vehiculesPourMaintenance as $vh): ?>
                                <option value="<?php echo $vh['id']; ?>">
                                    <?php echo htmlspecialchars($vh['marque_nom']); ?> <?php echo htmlspecialchars($vh['modele']); ?> 
                                    (<?php echo $vh['reference']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <option value="revision">Révision</option>
                            <option value="reparation">Réparation</option>
                            <option value="controle">Contrôle Technique</option>
                            <option value="preparation_vente">Préparation Vente</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date Début *</label>
                        <input type="date" name="date_debut" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-textarea" rows="3" required placeholder="Détails de l'intervention..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Coût Estimé ($)</label>
                        <input type="number" name="cout" class="form-input" step="0.01" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Technicien</label>
                        <input type="text" name="technicien" class="form-input" placeholder="Nom du technicien">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Garage</label>
                        <input type="text" name="garage" class="form-input" placeholder="Nom du garage">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Pièces Remplacées (une par ligne)</label>
                        <textarea id="piecesInput" class="form-textarea" rows="3" placeholder="Filtre à huile&#10;Plaquettes de frein&#10;Batterie"></textarea>
                    </div>
                </div>
                
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        <i class="fas fa-info-circle" style="color: #ef4444;"></i>
                        Le véhicule sera automatiquement mis en statut "Maintenance" et ne sera plus disponible à la vente.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createMaintenanceModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Créer la Maintenance
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Terminer Maintenance -->
<div id="terminerMaintenanceModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-check"></i> Terminer la Maintenance</h2>
            <button class="modal-close" onclick="Company.closeModal('terminerMaintenanceModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="terminer_maintenance">
                <input type="hidden" name="maintenance_id" id="terminer_maintenance_id">
                <input type="hidden" name="vehicule_id" id="terminer_vehicule_id">
                
                <div class="form-group">
                    <label class="form-label">Coût Final ($) *</label>
                    <input type="number" name="cout_final" class="form-input" step="0.01" id="cout_final_input" required>
                </div>
                
                <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i>
                        La maintenance sera terminée et le véhicule redeviendra disponible à la vente.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('terminerMaintenanceModal')">Annuler</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                    <i class="fas fa-check"></i> Terminer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Convert pieces textarea to hidden inputs
document.getElementById('createMaintenanceForm').addEventListener('submit', function(e) {
    const textarea = document.getElementById('piecesInput');
    const lines = textarea.value.split('\n').filter(l => l.trim());
    lines.forEach(line => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'pieces_remplacees[]';
        input.value = line.trim();
        this.appendChild(input);
    });
});

function terminerMaintenance(maintenanceId, vehiculeId, coutActuel) {
    document.getElementById('terminer_maintenance_id').value = maintenanceId;
    document.getElementById('terminer_vehicule_id').value = vehiculeId;
    document.getElementById('cout_final_input').value = coutActuel;
    Company.openModal('terminerMaintenanceModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>