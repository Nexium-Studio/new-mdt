<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/faction_helper.php';
require_once __DIR__ . '/../../../config/lsm_dealership_helpers.php';
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

$pageTitle = 'Gestion du Stock';
$db = Database::getInstance()->getConnection();
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
    
    // Créer véhicule
    if ($action === 'create_vehicule') {
        try {
            $ref = lsm_sequence_next($db, $dealershipKey, 'VEH', 'VEH-');
            $prixAchat = !empty($_POST['prix_achat']) ? (float)$_POST['prix_achat'] : 0;
            $prixVente = (float)$_POST['prix_vente'];
            $marge = $prixVente - $prixAchat;
            $margePct = $prixAchat > 0 ? ($marge / $prixAchat) * 100 : 0;
            
            $equipements = !empty($_POST['equipements']) ? json_encode(array_values(array_filter($_POST['equipements']))) : null;
            
            $db->prepare("
                INSERT INTO lsm_vehicules 
                (dealership_key, reference, marque_id, categorie_id, modele, annee, couleur,
                 prix_achat, prix_vente, marge, marge_pct,
                 kilometrage, nb_places, puissance_cv, type_carburant, boite,
                 etat, statut, description, equipements, date_arrivee, date_mise_vente, ajout_par)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $dealershipKey,
                $ref,
                !empty($_POST['marque_id']) ? (int)$_POST['marque_id'] : null,
                !empty($_POST['categorie_id']) ? (int)$_POST['categorie_id'] : null,
                sanitize($_POST['modele']),
                !empty($_POST['annee']) ? (int)$_POST['annee'] : null,
                sanitize($_POST['couleur'] ?? ''),
                $prixAchat,
                $prixVente,
                $marge,
                $margePct,
                (int)($_POST['kilometrage'] ?? 0),
                (int)($_POST['nb_places'] ?? 5),
                !empty($_POST['puissance_cv']) ? (int)$_POST['puissance_cv'] : null,
                $_POST['type_carburant'] ?? 'essence',
                $_POST['boite'] ?? 'manuelle',
                $_POST['etat'] ?? 'neuf',
                'disponible',
                sanitize($_POST['description'] ?? ''),
                $equipements,
                date('Y-m-d'),
                date('Y-m-d'),
                $_SESSION['user_id']
            ]);
            
            $message = "✅ Véhicule $ref ajouté au stock (Marge: $".number_format($marge, 0)." / ".number_format($margePct, 1)."%)";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Modifier véhicule
    if ($action === 'update_vehicule') {
        try {
            $id = (int)$_POST['vehicule_id'];
            $prixAchat = !empty($_POST['prix_achat']) ? (float)$_POST['prix_achat'] : 0;
            $prixVente = (float)$_POST['prix_vente'];
            $marge = $prixVente - $prixAchat;
            $margePct = $prixAchat > 0 ? ($marge / $prixAchat) * 100 : 0;
            
            $equipements = !empty($_POST['equipements']) ? json_encode(array_values(array_filter($_POST['equipements']))) : null;
            
            $db->prepare("
                UPDATE lsm_vehicules SET
                    marque_id=?, categorie_id=?, modele=?, annee=?, couleur=?,
                    prix_achat=?, prix_vente=?, marge=?, marge_pct=?,
                    kilometrage=?, nb_places=?, puissance_cv=?, type_carburant=?, boite=?,
                    etat=?, description=?, equipements=?
                WHERE id=? AND dealership_key=?
            ")->execute([
                !empty($_POST['marque_id']) ? (int)$_POST['marque_id'] : null,
                !empty($_POST['categorie_id']) ? (int)$_POST['categorie_id'] : null,
                sanitize($_POST['modele']),
                !empty($_POST['annee']) ? (int)$_POST['annee'] : null,
                sanitize($_POST['couleur'] ?? ''),
                $prixAchat,
                $prixVente,
                $marge,
                $margePct,
                (int)($_POST['kilometrage'] ?? 0),
                (int)($_POST['nb_places'] ?? 5),
                !empty($_POST['puissance_cv']) ? (int)$_POST['puissance_cv'] : null,
                $_POST['type_carburant'] ?? 'essence',
                $_POST['boite'] ?? 'manuelle',
                $_POST['etat'] ?? 'neuf',
                sanitize($_POST['description'] ?? ''),
                $equipements,
                $id,
                $dealershipKey
            ]);
            
            $message = "✅ Véhicule modifié avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Changer statut
    if ($action === 'change_statut') {
        try {
            $id = (int)$_POST['vehicule_id'];
            $newStatut = $_POST['new_statut'];
            $db->prepare("UPDATE lsm_vehicules SET statut=? WHERE id=? AND dealership_key=?")->execute([$newStatut, $id, $dealershipKey]);
            $message = "✅ Statut modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Supprimer véhicule
    if ($action === 'delete_vehicule') {
        try {
            $id = (int)$_POST['vehicule_id'];
            $db->prepare("DELETE FROM lsm_vehicules WHERE id=? AND statut != 'vendu' AND dealership_key=?")->execute([$id, $dealershipKey]);
            $message = "✅ Véhicule supprimé du stock";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterCategorie = $_GET['categorie'] ?? '';
$filterMarque = $_GET['marque'] ?? '';
$filterStatut = $_GET['statut'] ?? '';
$filterEtat = $_GET['etat'] ?? '';
$search = $_GET['search'] ?? '';

// Requête véhicules
$sql = "
    SELECT v.*, m.nom as marque_nom, c.nom as categorie_nom, c.couleur as categorie_couleur
    FROM lsm_vehicules v
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    LEFT JOIN lsm_categories c ON v.categorie_id = c.id
    WHERE v.dealership_key = " . $db->quote($dealershipKey) . "
";

if ($filterCategorie) $sql .= " AND v.categorie_id = " . (int)$filterCategorie;
if ($filterMarque) $sql .= " AND v.marque_id = " . (int)$filterMarque;
if ($filterStatut) $sql .= " AND v.statut = " . $db->quote($filterStatut);
if ($filterEtat) $sql .= " AND v.etat = " . $db->quote($filterEtat);
if ($search) $sql .= " AND (v.modele LIKE " . $db->quote("%$search%") . " OR v.reference LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY v.created_at DESC";

$vehicules = $db->query($sql)->fetchAll();

// Listes pour les filtres
$categories = $db->query("SELECT * FROM lsm_categories WHERE actif=1 ORDER BY ordre")->fetchAll();
$marques = $db->query("SELECT * FROM lsm_marques WHERE actif=1 ORDER BY nom")->fetchAll();

// Stats
$stats = [
    'total' => count($vehicules),
    'disponible' => count(array_filter($vehicules, fn($v) => $v['statut'] === 'disponible')),
    'reserve' => count(array_filter($vehicules, fn($v) => $v['statut'] === 'reserve')),
    'vendu' => count(array_filter($vehicules, fn($v) => $v['statut'] === 'vendu')),
];

$statutColors = [
    'disponible' => '#22c55e',
    'reserve' => '#f59e0b',
    'vendu' => '#3b82f6',
    'maintenance' => '#64748b'
];

$etatBadges = [
    'neuf' => ['color' => '#22c55e', 'label' => 'Neuf'],
    'occasion' => ['color' => '#3b82f6', 'label' => 'Occasion'],
    'collection' => ['color' => '#a855f7', 'label' => 'Collection']
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
        <div class="stat-label">Total Véhicules</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['disponible']; ?></div>
        <div class="stat-label">Disponibles</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['reserve']; ?></div>
        <div class="stat-label">Réservés</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['vendu']; ?></div>
        <div class="stat-label">Vendus</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-warehouse"></i> Stock de Véhicules</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createVehiculeModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouveau Véhicule
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="categorie" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Toutes catégories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $filterCategorie == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['nom']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="marque" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Toutes marques</option>
            <?php foreach ($marques as $marque): ?>
                <option value="<?php echo $marque['id']; ?>" <?php echo $filterMarque == $marque['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($marque['nom']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="statut" class="form-select" style="max-width: 150px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="disponible" <?php echo $filterStatut === 'disponible' ? 'selected' : ''; ?>>Disponible</option>
            <option value="reserve" <?php echo $filterStatut === 'reserve' ? 'selected' : ''; ?>>Réservé</option>
            <option value="vendu" <?php echo $filterStatut === 'vendu' ? 'selected' : ''; ?>>Vendu</option>
            <option value="maintenance" <?php echo $filterStatut === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
        </select>
        
        <select name="etat" class="form-select" style="max-width: 150px;" onchange="this.form.submit()">
            <option value="">Tous états</option>
            <option value="neuf" <?php echo $filterEtat === 'neuf' ? 'selected' : ''; ?>>Neuf</option>
            <option value="occasion" <?php echo $filterEtat === 'occasion' ? 'selected' : ''; ?>>Occasion</option>
            <option value="collection" <?php echo $filterEtat === 'collection' ? 'selected' : ''; ?>>Collection</option>
        </select>
        
        <?php if ($search || $filterCategorie || $filterMarque || $filterStatut || $filterEtat): ?>
            <a href="stock.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste véhicules -->
<?php if (empty($vehicules)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-car" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucun véhicule trouvé</h3>
        <p style="color: #64748b;">Ajoutez votre premier véhicule au stock</p>
    </div>
<?php else: ?>
    <?php foreach ($vehicules as $vehicule): 
        $equipements = $vehicule['equipements'] ? json_decode($vehicule['equipements'], true) : [];
    ?>
        <div class="vehicule-card">
            <div class="vehicule-header">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                        <?php if ($vehicule['categorie_nom']): ?>
                            <span class="badge" style="background: <?php echo $vehicule['categorie_couleur']; ?>20; color: <?php echo $vehicule['categorie_couleur']; ?>; border-color: <?php echo $vehicule['categorie_couleur']; ?>;">
                                <?php echo $vehicule['categorie_nom']; ?>
                            </span>
                        <?php endif; ?>
                        
                        <span class="badge" style="background: <?php echo $etatBadges[$vehicule['etat']]['color']; ?>20; color: <?php echo $etatBadges[$vehicule['etat']]['color']; ?>; border-color: <?php echo $etatBadges[$vehicule['etat']]['color']; ?>;">
                            <?php echo $etatBadges[$vehicule['etat']]['label']; ?>
                        </span>
                        
                        <span class="badge" style="background: <?php echo $statutColors[$vehicule['statut']]; ?>20; color: <?php echo $statutColors[$vehicule['statut']]; ?>; border-color: <?php echo $statutColors[$vehicule['statut']]; ?>;">
                            <?php echo ucfirst($vehicule['statut']); ?>
                        </span>
                    </div>
                    
                    <h3 class="vehicule-title">
                        <?php echo htmlspecialchars($vehicule['marque_nom']); ?> 
                        <?php echo htmlspecialchars($vehicule['modele']); ?>
                    </h3>
                    
                    <div style="color: #64748b; font-size: 0.9rem;">
                        Réf: <?php echo $vehicule['reference']; ?> 
                        <?php if ($vehicule['annee']): ?>
                            • <?php echo $vehicule['annee']; ?>
                        <?php endif; ?>
                        <?php if ($vehicule['couleur']): ?>
                            • <?php echo htmlspecialchars($vehicule['couleur']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Prix et Marge -->
            <div class="prix-section">
                <div class="prix-item">
                    <div class="prix-label">Prix d'Achat</div>
                    <div class="prix-value" style="color: #64748b;">
                        $<?php echo number_format($vehicule['prix_achat'] ?? 0, 0); ?>
                    </div>
                </div>
                <div class="prix-item">
                    <div class="prix-label">Prix de Vente</div>
                    <div class="prix-value">
                        $<?php echo number_format($vehicule['prix_vente'] ?? 0, 0); ?>
                    </div>
                </div>
                <div class="prix-item">
                    <div class="prix-label">Marge</div>
                    <div class="prix-value marge-positive">
                        $<?php echo number_format($vehicule['marge'] ?? 0, 0); ?>
                        <div style="font-size: 0.8rem;">(<?php echo number_format($vehicule['marge_pct'] ?? 0, 1); ?>%)</div>
                    </div>
                </div>
            </div>
            
            <!-- Caractéristiques -->
            <div class="vehicule-meta">
                <?php if ($vehicule['kilometrage']): ?>
                    <div class="meta-item">
                        <div class="meta-label">Kilométrage</div>
                        <div class="meta-value"><?php echo number_format($vehicule['kilometrage']); ?> km</div>
                    </div>
                <?php endif; ?>
                
                <?php if ($vehicule['puissance_cv']): ?>
                    <div class="meta-item">
                        <div class="meta-label">Puissance</div>
                        <div class="meta-value"><?php echo $vehicule['puissance_cv']; ?> CV</div>
                    </div>
                <?php endif; ?>
                
                <div class="meta-item">
                    <div class="meta-label">Carburant</div>
                    <div class="meta-value"><?php echo ucfirst($vehicule['type_carburant']); ?></div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label">Boîte</div>
                    <div class="meta-value"><?php echo ucfirst($vehicule['boite']); ?></div>
                </div>
                
                <div class="meta-item">
                    <div class="meta-label">Places</div>
                    <div class="meta-value"><?php echo $vehicule['nb_places']; ?> places</div>
                </div>
            </div>
            
            <?php if ($vehicule['description']): ?>
                <p style="color: #94a3b8; line-height: 1.6; margin: 1rem 0;">
                    <?php echo nl2br(htmlspecialchars($vehicule['description'])); ?>
                </p>
            <?php endif; ?>
            
            <?php if (!empty($equipements)): ?>
                <div class="equipements-list">
                    <?php foreach ($equipements as $equip): ?>
                        <span class="equipement-tag"><?php echo htmlspecialchars($equip); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <button onclick="editVehicule(<?php echo htmlspecialchars(json_encode($vehicule)); ?>)" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i> Modifier
                    </button>
                    
                    <?php if ($vehicule['statut'] === 'disponible'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="vehicule_id" value="<?php echo $vehicule['id']; ?>">
                            <input type="hidden" name="new_statut" value="reserve">
                            <button type="submit" class="btn btn-secondary btn-sm" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                                <i class="fas fa-bookmark"></i> Réserver
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($vehicule['statut'] === 'reserve'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="vehicule_id" value="<?php echo $vehicule['id']; ?>">
                            <input type="hidden" name="new_statut" value="disponible">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-undo"></i> Libérer
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($vehicule['statut'] !== 'vendu'): ?>
                        <button onclick="if(confirm('Supprimer ce véhicule ?')) deleteVehicule(<?php echo $vehicule['id']; ?>)" class="btn btn-secondary btn-sm" style="color: #ef4444;">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Véhicule -->
<div id="createVehiculeModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouveau Véhicule</h2>
            <button class="modal-close" onclick="Company.closeModal('createVehiculeModal')">×</button>
        </div>
        <form method="POST" id="createForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_vehicule">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Marque *</label>
                        <select name="marque_id" class="form-select" required>
                            <option value="">Choisir...</option>
                            <?php foreach ($marques as $marque): ?>
                                <option value="<?php echo $marque['id']; ?>"><?php echo htmlspecialchars($marque['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="categorie_id" class="form-select" required>
                            <option value="">Choisir...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Modèle *</label>
                        <input type="text" name="modele" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Année</label>
                        <input type="number" name="annee" class="form-input" min="1900" max="2030" value="<?php echo date('Y'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Couleur</label>
                        <input type="text" name="couleur" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix d'Achat</label>
                        <input type="number" name="prix_achat" class="form-input" step="0.01" id="prixAchatCreate">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix de Vente *</label>
                        <input type="number" name="prix_vente" class="form-input" step="0.01" required id="prixVenteCreate">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kilométrage</label>
                        <input type="number" name="kilometrage" class="form-input" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Puissance (CV)</label>
                        <input type="number" name="puissance_cv" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Carburant</label>
                        <select name="type_carburant" class="form-select">
                            <option value="essence">Essence</option>
                            <option value="diesel">Diesel</option>
                            <option value="electrique">Électrique</option>
                            <option value="hybride">Hybride</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Boîte</label>
                        <select name="boite" class="form-select">
                            <option value="manuelle">Manuelle</option>
                            <option value="automatique">Automatique</option>
                            <option value="semi-auto">Semi-Auto</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Places</label>
                        <input type="number" name="nb_places" class="form-input" value="5" min="1" max="9">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">État</label>
                        <select name="etat" class="form-select">
                            <option value="neuf">Neuf</option>
                            <option value="occasion">Occasion</option>
                            <option value="collection">Collection</option>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Équipements (un par ligne)</label>
                        <textarea id="equipementsInput" class="form-textarea" rows="3" placeholder="GPS&#10;Climatisation&#10;Sièges cuir"></textarea>
                    </div>
                </div>
                
                <div id="margePreviewCreate" style="margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 10px; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600;">Marge calculée:</span>
                        <div>
                            <span id="margeAmount" style="font-size: 1.3rem; font-weight: 700; color: #22c55e;"></span>
                            <span id="margePct" style="color: #64748b; margin-left: 0.5rem;"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createVehiculeModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Ajouter au Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier Véhicule -->
<div id="editVehiculeModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier Véhicule</h2>
            <button class="modal-close" onclick="Company.closeModal('editVehiculeModal')">×</button>
        </div>
        <form method="POST" id="editForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_vehicule">
                <input type="hidden" name="vehicule_id" id="edit_vehicule_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Marque *</label>
                        <select name="marque_id" class="form-select" id="edit_marque_id" required>
                            <option value="">Choisir...</option>
                            <?php foreach ($marques as $marque): ?>
                                <option value="<?php echo $marque['id']; ?>"><?php echo htmlspecialchars($marque['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Catégorie *</label>
                        <select name="categorie_id" class="form-select" id="edit_categorie_id" required>
                            <option value="">Choisir...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Modèle *</label>
                        <input type="text" name="modele" class="form-input" id="edit_modele" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Année</label>
                        <input type="number" name="annee" class="form-input" id="edit_annee">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Couleur</label>
                        <input type="text" name="couleur" class="form-input" id="edit_couleur">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix d'Achat</label>
                        <input type="number" name="prix_achat" class="form-input" step="0.01" id="edit_prix_achat">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix de Vente *</label>
                        <input type="number" name="prix_vente" class="form-input" step="0.01" id="edit_prix_vente" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kilométrage</label>
                        <input type="number" name="kilometrage" class="form-input" id="edit_kilometrage">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Puissance (CV)</label>
                        <input type="number" name="puissance_cv" class="form-input" id="edit_puissance_cv">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Carburant</label>
                        <select name="type_carburant" class="form-select" id="edit_type_carburant">
                            <option value="essence">Essence</option>
                            <option value="diesel">Diesel</option>
                            <option value="electrique">Électrique</option>
                            <option value="hybride">Hybride</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Boîte</label>
                        <select name="boite" class="form-select" id="edit_boite">
                            <option value="manuelle">Manuelle</option>
                            <option value="automatique">Automatique</option>
                            <option value="semi-auto">Semi-Auto</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Places</label>
                        <input type="number" name="nb_places" class="form-input" id="edit_nb_places">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">État</label>
                        <select name="etat" class="form-select" id="edit_etat">
                            <option value="neuf">Neuf</option>
                            <option value="occasion">Occasion</option>
                            <option value="collection">Collection</option>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3" id="edit_description"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Équipements (un par ligne)</label>
                        <textarea id="edit_equipements" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
                
                <div id="margePreviewEdit" style="margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 10px; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600;">Marge calculée:</span>
                        <div>
                            <span id="margeAmountEdit" style="font-size: 1.3rem; font-weight: 700; color: #22c55e;"></span>
                            <span id="margePctEdit" style="color: #64748b; margin-left: 0.5rem;"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('editVehiculeModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Calcul marge temps réel - Create
document.getElementById('prixAchatCreate')?.addEventListener('input', updateMargeCreate);
document.getElementById('prixVenteCreate')?.addEventListener('input', updateMargeCreate);

function updateMargeCreate() {
    const achat = parseFloat(document.getElementById('prixAchatCreate').value) || 0;
    const vente = parseFloat(document.getElementById('prixVenteCreate').value) || 0;
    
    if (achat > 0 && vente > 0) {
        const marge = vente - achat;
        const margePct = (marge / achat) * 100;
        
        document.getElementById('margePreviewCreate').style.display = 'block';
        document.getElementById('margeAmount').textContent = '$' + marge.toLocaleString('fr-FR', {minimumFractionDigits: 0});
        document.getElementById('margePct').textContent = '(' + margePct.toFixed(1) + '%)';
    } else {
        document.getElementById('margePreviewCreate').style.display = 'none';
    }
}

// Calcul marge temps réel - Edit
document.getElementById('edit_prix_achat')?.addEventListener('input', updateMargeEdit);
document.getElementById('edit_prix_vente')?.addEventListener('input', updateMargeEdit);

function updateMargeEdit() {
    const achat = parseFloat(document.getElementById('edit_prix_achat').value) || 0;
    const vente = parseFloat(document.getElementById('edit_prix_vente').value) || 0;
    
    if (achat > 0 && vente > 0) {
        const marge = vente - achat;
        const margePct = (marge / achat) * 100;
        
        document.getElementById('margePreviewEdit').style.display = 'block';
        document.getElementById('margeAmountEdit').textContent = '$' + marge.toLocaleString('fr-FR', {minimumFractionDigits: 0});
        document.getElementById('margePctEdit').textContent = '(' + margePct.toFixed(1) + '%)';
    } else {
        document.getElementById('margePreviewEdit').style.display = 'none';
    }
}

// Convert equipements textarea to hidden inputs
document.getElementById('createForm').addEventListener('submit', function(e) {
    const textarea = document.getElementById('equipementsInput');
    const lines = textarea.value.split('\n').filter(l => l.trim());
    lines.forEach(line => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'equipements[]';
        input.value = line.trim();
        this.appendChild(input);
    });
});

document.getElementById('editForm').addEventListener('submit', function(e) {
    const textarea = document.getElementById('edit_equipements');
    const lines = textarea.value.split('\n').filter(l => l.trim());
    lines.forEach(line => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'equipements[]';
        input.value = line.trim();
        this.appendChild(input);
    });
});

// Edit vehicule
function editVehicule(vehicule) {
    document.getElementById('edit_vehicule_id').value = vehicule.id;
    document.getElementById('edit_marque_id').value = vehicule.marque_id || '';
    document.getElementById('edit_categorie_id').value = vehicule.categorie_id || '';
    document.getElementById('edit_modele').value = vehicule.modele;
    document.getElementById('edit_annee').value = vehicule.annee || '';
    document.getElementById('edit_couleur').value = vehicule.couleur || '';
    document.getElementById('edit_prix_achat').value = vehicule.prix_achat || '';
    document.getElementById('edit_prix_vente').value = vehicule.prix_vente;
    document.getElementById('edit_kilometrage').value = vehicule.kilometrage || 0;
    document.getElementById('edit_puissance_cv').value = vehicule.puissance_cv || '';
    document.getElementById('edit_type_carburant').value = vehicule.type_carburant;
    document.getElementById('edit_boite').value = vehicule.boite;
    document.getElementById('edit_nb_places').value = vehicule.nb_places || 5;
    document.getElementById('edit_etat').value = vehicule.etat;
    document.getElementById('edit_description').value = vehicule.description || '';
    
    // Equipements
    if (vehicule.equipements) {
        try {
            const equips = JSON.parse(vehicule.equipements);
            document.getElementById('edit_equipements').value = equips.join('\n');
        } catch(e) {
            document.getElementById('edit_equipements').value = '';
        }
    } else {
        document.getElementById('edit_equipements').value = '';
    }
    
    updateMargeEdit();
    Company.openModal('editVehiculeModal');
}

// Delete vehicule
function deleteVehicule(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_vehicule">
        <input type="hidden" name="vehicule_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>