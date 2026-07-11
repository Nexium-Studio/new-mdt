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

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion des Véhicules';
$db = Database::getInstance()->getConnection();
$message = '';
$messageType = '';

// Permissions (faction_grades — dynamique)
$__gradeStmt = $db->prepare('SELECT faction_grade FROM users WHERE id=? LIMIT 1');
$__gradeStmt->execute([$_SESSION['user_id'] ?? 0]);
$__grade = (string)($__gradeStmt->fetchColumn() ?: '');
$permissions = getGradePermissions($companyFaction, $__grade);
$canManage   = $permissions['can_manage_stock'] || hasRole(['*', 'Administrateur']);

// AJOUTER VÉHICULE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $canManage) {
    try {
        $stmt = $db->prepare("
            INSERT INTO garage_vehicles (faction, vehicle_name, category, base_price, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyFaction,
            trim($_POST['vehicle_name']),
            trim($_POST['category']),
            floatval($_POST['base_price']),
            trim($_POST['description'] ?? '')
        ]);
        
        $message = 'Véhicule ajouté';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// MODIFIER VÉHICULE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update' && $canManage) {
    try {
        $stmt = $db->prepare("
            UPDATE garage_vehicles 
            SET vehicle_name = ?, category = ?, base_price = ?, description = ?
            WHERE id = ? AND faction = ?
        ");
        $stmt->execute([
            trim($_POST['vehicle_name']),
            trim($_POST['category']),
            floatval($_POST['base_price']),
            trim($_POST['description'] ?? ''),
            intval($_POST['vehicle_id']),
            $companyFaction
        ]);
        
        $message = 'Véhicule mis à jour';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// SUPPRIMER VÉHICULE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    try {
        $stmt = $db->prepare("DELETE FROM garage_vehicles WHERE id = ? AND faction = ?");
        $stmt->execute([intval($_POST['vehicle_id']), $companyFaction]);
        
        $message = 'Véhicule supprimé';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// AJOUTER MODIFICATIONS AU VÉHICULE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mods' && $canManage) {
    try {
        $vehicleId = intval($_POST['vehicle_id']);
        $modifications = $_POST['modifications'] ?? [];
        
        // Supprimer anciennes mods
        $stmt = $db->prepare("DELETE FROM garage_vehicle_mods WHERE vehicle_id = ?");
        $stmt->execute([$vehicleId]);
        
        // Ajouter nouvelles mods
        if (!empty($modifications)) {
            $stmt = $db->prepare("INSERT INTO garage_vehicle_mods (vehicle_id, modification_id) VALUES (?, ?)");
            foreach ($modifications as $modId) {
                $stmt->execute([$vehicleId, intval($modId)]);
            }
        }
        
        $message = 'Modifications appliquées';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// RÉCUPÉRER VÉHICULES
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM garage_vehicles WHERE faction = ?";
$params = [$companyFaction];

if ($search) {
    $query .= " AND vehicle_name LIKE ?";
    $params[] = "%{$search}%";
}

$query .= " ORDER BY category, vehicle_name";
$stmt = $db->prepare($query);
$stmt->execute($params);
$vehiclesData = $stmt->fetchAll();

// Grouper par catégorie
$vehicles = [];
foreach ($vehiclesData as $vehicle) {
    $cat = $vehicle['category'] ?: 'Autre';
    if (!isset($vehicles[$cat])) {
        $vehicles[$cat] = [];
    }
    
    // Récupérer les mods du véhicule
    $stmt = $db->prepare("
        SELECT gm.* 
        FROM garage_modifications gm
        JOIN garage_vehicle_mods gvm ON gm.id = gvm.modification_id
        WHERE gvm.vehicle_id = ?
    ");
    $stmt->execute([$vehicle['id']]);
    $vehicle['mods'] = $stmt->fetchAll();
    
    // Calculer le prix final
    $totalIncrease = 1.0;
    foreach ($vehicle['mods'] as $mod) {
        $totalIncrease *= (1 + $mod['percentage_increase']);
    }
    $vehicle['final_price'] = $vehicle['base_price'] * $totalIncrease;
    
    $vehicles[$cat][] = $vehicle;
}

// RÉCUPÉRER MODIFICATIONS DISPONIBLES
$stmt = $db->prepare("SELECT * FROM garage_modifications WHERE faction = ? ORDER BY mod_type, mod_level");
$stmt->execute([$companyFaction]);
$allMods = $stmt->fetchAll();

// Grouper mods par type
$modsByType = [];
foreach ($allMods as $mod) {
    $type = $mod['mod_type'];
    if (!isset($modsByType[$type])) {
        $modsByType[$type] = [];
    }
    $modsByType[$type][] = $mod;
}

include __DIR__ . '/includes/header.php';
?>



<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!$canManage): ?>
    <div class="alert" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3); color: #60a5fa;">
        <i class="fas fa-info-circle"></i>
        Vous ne pouvez pas gérer les véhicules avec votre grade actuel.
    </div>
<?php endif; ?>

<!-- Barre de recherche -->
<form method="GET" class="search-bar">
    <input type="text" name="search" class="search-input" placeholder="🔍 Rechercher un véhicule..." value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-search"></i> Rechercher
    </button>
    <?php if ($search): ?>
        <a href="?" class="btn btn-secondary">
            <i class="fas fa-times"></i> Réinitialiser
        </a>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="Company.openModal('addVehicleModal')">
            <i class="fas fa-plus"></i> Ajouter Véhicule
        </button>
    <?php endif; ?>
</form>

<!-- Liste véhicules par catégorie -->
<?php if (empty($vehicles)): ?>
    <div class="card">
        <div style="text-align: center; padding: 4rem 2rem; color: #64748b;">
            <i class="fas fa-car" style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
            <p>Aucun véhicule trouvé</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($vehicles as $category => $categoryVehicles): ?>
        <div class="category-section">
            <div class="category-header">
                <span class="category-icon">
                    <?php
                    $icons = [
                        'Moto' => '🏍️',
                        'Sportives' => '⚡',
                        'Sportives Classiques' => '🏁',
                        'Muscle' => '💪',
                        'Super' => '👑',
                        'Off Road' => '🏔️',
                        'Sedan' => '🚙',
                        'Modele' => '🚗',
                        'Vans' => '🚐',
                        'Utilitaire' => '🚚',
                        'Coupe' => '🏎️',
                        'Compact' => '📦'
                    ];
                    echo $icons[$category] ?? '🚗';
                    ?>
                </span>
                <h2 class="category-title"><?php echo htmlspecialchars($category); ?></h2>
                <span class="category-count"><?php echo count($categoryVehicles); ?> véhicule(s)</span>
            </div>
            
            <div class="vehicles-grid">
                <?php foreach ($categoryVehicles as $vehicle): ?>
                    <div class="vehicle-card">
                        <h3 class="vehicle-name"><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></h3>
                        
                        <?php if ($vehicle['description']): ?>
                            <p class="vehicle-description"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="price-section">
                            <div class="price-row">
                                <span class="price-label">Prix de base</span>
                                <span class="price-value base-price">$<?php echo number_format($vehicle['base_price'], 0); ?></span>
                            </div>
                            <?php if (!empty($vehicle['mods'])): ?>
                                <div class="price-row">
                                    <span class="price-label">Avec modifications</span>
                                    <span class="price-value final-price">$<?php echo number_format($vehicle['final_price'], 0); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($vehicle['mods'])): ?>
                            <div class="mods-section">
                                <div class="mods-title">
                                    <i class="fas fa-wrench"></i> Modifications (<?php echo count($vehicle['mods']); ?>)
                                </div>
                                <?php foreach ($vehicle['mods'] as $mod): ?>
                                    <span class="mod-tag">
                                        <?php echo htmlspecialchars($mod['description']); ?>
                                        (+<?php echo number_format($mod['percentage_increase'] * 100, 1); ?>%)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($canManage): ?>
                            <div class="vehicle-actions">
                                <button onclick="openModsModal(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_name'], ENT_QUOTES); ?>')" class="btn btn-secondary btn-sm" style="flex: 1;">
                                    <i class="fas fa-wrench"></i> Customiser
                                </button>
                                <button onclick="editVehicle(<?php echo htmlspecialchars(json_encode($vehicle)); ?>)" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Supprimer ce véhicule ?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Ajouter Véhicule -->
<?php if ($canManage): ?>
<div id="addVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Ajouter un Véhicule</h2>
            <button class="modal-close" onclick="Company.closeModal('addVehicleModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom du Véhicule *</label>
                        <input type="text" name="vehicle_name" class="form-input" required placeholder="Ex: Pfister 811">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" class="form-select" required>
                            <option value="">Sélectionner</option>
                            <option value="Moto">🏍️ Moto</option>
                            <option value="Sportives">⚡ Sportives</option>
                            <option value="Sportives Classiques">🏁 Sportives Classiques</option>
                            <option value="Muscle">💪 Muscle</option>
                            <option value="Super">👑 Super</option>
                            <option value="Off Road">🏔️ Off Road</option>
                            <option value="Sedan">🚙 Sedan</option>
                            <option value="Modele">🚗 Modèle</option>
                            <option value="Vans">🚐 Vans</option>
                            <option value="Utilitaire">🚚 Utilitaire</option>
                            <option value="Coupe">🏎️ Coupé</option>
                            <option value="Compact">📦 Compact</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix de Base ($) *</label>
                        <input type="number" name="base_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3" placeholder="Description du véhicule..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('addVehicleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Ajouter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier Véhicule -->
<div id="editVehicleModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier le Véhicule</h2>
            <button class="modal-close" onclick="Company.closeModal('editVehicleModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Nom du Véhicule *</label>
                        <input type="text" name="vehicle_name" id="edit_vehicle_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="category" id="edit_vehicle_category" class="form-select" required>
                            <option value="Moto">🏍️ Moto</option>
                            <option value="Sportives">⚡ Sportives</option>
                            <option value="Sportives Classiques">🏁 Sportives Classiques</option>
                            <option value="Muscle">💪 Muscle</option>
                            <option value="Super">👑 Super</option>
                            <option value="Off Road">🏔️ Off Road</option>
                            <option value="Sedan">🚙 Sedan</option>
                            <option value="Modele">🚗 Modèle</option>
                            <option value="Vans">🚐 Vans</option>
                            <option value="Utilitaire">🚚 Utilitaire</option>
                            <option value="Coupe">🏎️ Coupé</option>
                            <option value="Compact">📦 Compact</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix de Base ($) *</label>
                        <input type="number" name="base_price" id="edit_vehicle_price" class="form-input" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_vehicle_description" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('editVehicleModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Customiser Véhicule -->
<div id="modsModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h2><i class="fas fa-wrench"></i> Customiser : <span id="mods_vehicle_name"></span></h2>
            <button class="modal-close" onclick="Company.closeModal('modsModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_mods">
                <input type="hidden" name="vehicle_id" id="mods_vehicle_id">
                
                <div style="background: rgba(139, 92, 246, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid rgba(139, 92, 246, 0.3);">
                    <p style="margin: 0; color: #a78bfa; font-size: 0.9rem;">
                        <i class="fas fa-info-circle"></i> Sélectionnez les modifications à appliquer. Le prix final sera calculé automatiquement.
                    </p>
                </div>
                
                <div class="modal-grid">
                    <?php foreach ($modsByType as $type => $mods): ?>
                        <div class="mod-type-section">
                            <div class="mod-type-title">
                                <i class="fas fa-cog"></i> <?php echo htmlspecialchars($type); ?>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach ($mods as $mod): ?>
                                    <label class="mod-checkbox-label">
                                        <input type="checkbox" name="modifications[]" value="<?php echo $mod['id']; ?>">
                                        <span><?php echo htmlspecialchars($mod['description']); ?></span>
                                        <span class="mod-increase">+<?php echo number_format($mod['percentage_increase'] * 100, 1); ?>%</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('modsModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Appliquer
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function editVehicle(vehicle) {
    document.getElementById('edit_vehicle_id').value = vehicle.id;
    document.getElementById('edit_vehicle_name').value = vehicle.vehicle_name;
    document.getElementById('edit_vehicle_category').value = vehicle.category;
    document.getElementById('edit_vehicle_price').value = vehicle.base_price;
    document.getElementById('edit_vehicle_description').value = vehicle.description || '';
    Company.openModal('editVehicleModal');
}

function openModsModal(vehicleId, vehicleName) {
    document.getElementById('mods_vehicle_id').value = vehicleId;
    document.getElementById('mods_vehicle_name').textContent = vehicleName;
    Company.openModal('modsModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>