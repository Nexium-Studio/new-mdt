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

$pageTitle = 'Gestion des Clients';
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
    
    // Créer client
    if ($action === 'create_client') {
        try {
            $db->prepare("
                INSERT INTO lsm_clients 
                (nom, prenom, telephone, email, adresse, type, numero_permis, date_naissance, notes, vip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                sanitize($_POST['nom']),
                sanitize($_POST['prenom'] ?? ''),
                sanitize($_POST['telephone'] ?? ''),
                sanitize($_POST['email'] ?? ''),
                sanitize($_POST['adresse'] ?? ''),
                $_POST['type'] ?? 'particulier',
                sanitize($_POST['numero_permis'] ?? ''),
                !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
                sanitize($_POST['notes'] ?? ''),
                isset($_POST['vip']) ? 1 : 0
            ]);
            
            $message = "✅ Client créé avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Modifier client
    if ($action === 'update_client') {
        try {
            $id = (int)$_POST['client_id'];
            $db->prepare("
                UPDATE lsm_clients SET
                    nom=?, prenom=?, telephone=?, email=?, adresse=?, type=?,
                    numero_permis=?, date_naissance=?, notes=?, vip=?
                WHERE id=?
            ")->execute([
                sanitize($_POST['nom']),
                sanitize($_POST['prenom'] ?? ''),
                sanitize($_POST['telephone'] ?? ''),
                sanitize($_POST['email'] ?? ''),
                sanitize($_POST['adresse'] ?? ''),
                $_POST['type'] ?? 'particulier',
                sanitize($_POST['numero_permis'] ?? ''),
                !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
                sanitize($_POST['notes'] ?? ''),
                isset($_POST['vip']) ? 1 : 0,
                $id
            ]);
            
            $message = "✅ Client modifié avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Supprimer client
    if ($action === 'delete_client') {
        try {
            $id = (int)$_POST['client_id'];
            // Vérifier qu'il n'a pas d'achats
            $hasVentes = $db->query("
                SELECT COUNT(*) FROM lsm_ventes v
                JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
                WHERE v.client_id=$id AND vh.dealership_key = $dk
            ")->fetchColumn();
            if ($hasVentes > 0) {
                throw new Exception("Impossible de supprimer un client avec un historique d'achats");
            }
            $db->prepare("DELETE FROM lsm_clients WHERE id=?")->execute([$id]);
            $message = "✅ Client supprimé";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterType = $_GET['type'] ?? '';
$filterVip = $_GET['vip'] ?? '';
$search = $_GET['search'] ?? '';

// Requête clients avec stats
$sql = "
    SELECT c.*,
           COUNT(DISTINCT v.id) as nb_achats,
           SUM(v.prix_final) as total_depense,
           MAX(v.created_at) as dernier_achat
    FROM lsm_clients c
    LEFT JOIN (
        lsm_ventes v INNER JOIN lsm_vehicules vh ON v.vehicule_id = vh.id AND vh.dealership_key = $dk
    ) ON c.id = v.client_id AND v.statut != 'annulee'
    WHERE 1=1
";

if ($filterType) $sql .= " AND c.type = " . $db->quote($filterType);
if ($filterVip) $sql .= " AND c.vip = 1";
if ($search) $sql .= " AND (c.nom LIKE " . $db->quote("%$search%") . " OR c.prenom LIKE " . $db->quote("%$search%") . " OR c.telephone LIKE " . $db->quote("%$search%") . ")";

$sql .= " GROUP BY c.id ORDER BY c.created_at DESC";

$clients = $db->query($sql)->fetchAll();

// Update stats clients
foreach ($clients as $client) {
    if ($client['nb_achats'] > 0) {
        $db->prepare("UPDATE lsm_clients SET nb_achats=?, total_depense=?, dernier_achat=? WHERE id=?")
           ->execute([$client['nb_achats'], $client['total_depense'] ?? 0, $client['dernier_achat'], $client['id']]);
    }
}

// Stats
$stats = [
    'total' => count($clients),
    'particulier' => count(array_filter($clients, fn($c) => $c['type'] === 'particulier')),
    'entreprise' => count(array_filter($clients, fn($c) => $c['type'] === 'entreprise')),
    'vip' => count(array_filter($clients, fn($c) => $c['vip'] == 1)),
];

$typeColors = [
    'particulier' => '#3b82f6',
    'entreprise' => '#8b5cf6',
    'vip' => '#f59e0b'
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
        <div class="stat-label">Total Clients</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['particulier']; ?></div>
        <div class="stat-label">Particuliers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['entreprise']; ?></div>
        <div class="stat-label">Entreprises</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['vip']; ?></div>
        <div class="stat-label">Clients VIP</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-users"></i> Tous les Clients</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createClientModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouveau Client
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="type" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous types</option>
            <option value="particulier" <?php echo $filterType === 'particulier' ? 'selected' : ''; ?>>Particulier</option>
            <option value="entreprise" <?php echo $filterType === 'entreprise' ? 'selected' : ''; ?>>Entreprise</option>
            <option value="vip" <?php echo $filterType === 'vip' ? 'selected' : ''; ?>>VIP</option>
        </select>
        
        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
            <input type="checkbox" name="vip" value="1" <?php echo $filterVip ? 'checked' : ''; ?> onchange="this.form.submit()">
            <span>VIP uniquement</span>
        </label>
        
        <?php if ($search || $filterType || $filterVip): ?>
            <a href="clients.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste clients -->
<?php if (empty($clients)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-users" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucun client trouvé</h3>
        <p style="color: #64748b;">Ajoutez votre premier client</p>
    </div>
<?php else: ?>
    <?php foreach ($clients as $client): ?>
        <div class="client-card <?php echo $client['vip'] ? 'vip' : ''; ?>">
            <?php if ($client['vip']): ?>
                <div class="vip-badge">
                    <i class="fas fa-crown"></i> VIP
                </div>
            <?php endif; ?>
            
            <div class="client-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
                        <span class="badge" style="background: <?php echo $typeColors[$client['type']]; ?>20; color: <?php echo $typeColors[$client['type']]; ?>; border-color: <?php echo $typeColors[$client['type']]; ?>;">
                            <?php echo ucfirst($client['type']); ?>
                        </span>
                    </div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                        <?php echo htmlspecialchars($client['nom']); ?>
                        <?php if ($client['prenom']): ?>
                            <?php echo htmlspecialchars($client['prenom']); ?>
                        <?php endif; ?>
                    </h3>
                    <div style="color: #64748b;">
                        Client depuis <?php echo date('d/m/Y', strtotime($client['created_at'])); ?>
                    </div>
                </div>
            </div>
            
            <!-- Informations -->
            <div class="client-info">
                <?php if ($client['telephone']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b;">Téléphone</div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($client['telephone']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($client['email']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b;">Email</div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($client['email']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($client['numero_permis']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b;">Permis</div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($client['numero_permis']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($client['adresse']): ?>
                <div style="padding: 0.75rem 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem;">
                        <i class="fas fa-map-marker-alt"></i> Adresse
                    </div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($client['adresse'])); ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Historique -->
            <?php if ($client['nb_achats'] > 0): ?>
                <div class="historique-box">
                    <div class="historique-item">
                        <div class="historique-value"><?php echo $client['nb_achats']; ?></div>
                        <div class="historique-label">Achats</div>
                    </div>
                    <div class="historique-item">
                        <div class="historique-value">$<?php echo number_format($client['total_depense'] ?? 0, 0); ?></div>
                        <div class="historique-label">Total Dépensé</div>
                    </div>
                    <div class="historique-item">
                        <div class="historique-value" style="font-size: 1rem; color: #64748b;">
                            <?php echo $client['dernier_achat'] ? date('d/m/Y', strtotime($client['dernier_achat'])) : 'N/A'; ?>
                        </div>
                        <div class="historique-label">Dernier Achat</div>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 1.5rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <i class="fas fa-shopping-cart" style="font-size: 2rem; color: #64748b; margin-bottom: 0.5rem;"></i>
                    <div style="color: #64748b;">Aucun achat pour le moment</div>
                </div>
            <?php endif; ?>
            
            <?php if ($client['notes']): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">
                        <i class="fas fa-sticky-note"></i> Notes internes
                    </div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($client['notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <button onclick="editClient(<?php echo htmlspecialchars(json_encode($client)); ?>)" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i> Modifier
                    </button>
                    
                    <?php if ($client['nb_achats'] == 0): ?>
                        <button onclick="if(confirm('Supprimer ce client ?')) deleteClient(<?php echo $client['id']; ?>)" class="btn btn-secondary btn-sm" style="color: #ef4444;">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Client -->
<div id="createClientModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouveau Client</h2>
            <button class="modal-close" onclick="Company.closeModal('createClientModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_client">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="nom" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prénom</label>
                        <input type="text" name="prenom" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="tel" name="telephone" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date de Naissance</label>
                        <input type="date" name="date_naissance" class="form-input">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Numéro de Permis</label>
                        <input type="text" name="numero_permis" class="form-input">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Adresse</label>
                        <textarea name="adresse" class="form-textarea" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes Internes</label>
                        <textarea name="notes" class="form-textarea" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="vip">
                            <span>Marquer comme VIP</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createClientModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Créer le Client
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifier Client -->
<div id="editClientModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Modifier Client</h2>
            <button class="modal-close" onclick="Company.closeModal('editClientModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_client">
                <input type="hidden" name="client_id" id="edit_client_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="nom" class="form-input" id="edit_nom" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prénom</label>
                        <input type="text" name="prenom" class="form-input" id="edit_prenom">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Téléphone</label>
                        <input type="tel" name="telephone" class="form-input" id="edit_telephone">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" id="edit_email">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" id="edit_type">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date de Naissance</label>
                        <input type="date" name="date_naissance" class="form-input" id="edit_date_naissance">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Numéro de Permis</label>
                        <input type="text" name="numero_permis" class="form-input" id="edit_numero_permis">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Adresse</label>
                        <textarea name="adresse" class="form-textarea" rows="2" id="edit_adresse"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes Internes</label>
                        <textarea name="notes" class="form-textarea" rows="2" id="edit_notes"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="vip" id="edit_vip">
                            <span>Marquer comme VIP</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('editClientModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editClient(client) {
    document.getElementById('edit_client_id').value = client.id;
    document.getElementById('edit_nom').value = client.nom;
    document.getElementById('edit_prenom').value = client.prenom || '';
    document.getElementById('edit_telephone').value = client.telephone || '';
    document.getElementById('edit_email').value = client.email || '';
    document.getElementById('edit_type').value = client.type;
    document.getElementById('edit_date_naissance').value = client.date_naissance || '';
    document.getElementById('edit_numero_permis').value = client.numero_permis || '';
    document.getElementById('edit_adresse').value = client.adresse || '';
    document.getElementById('edit_notes').value = client.notes || '';
    document.getElementById('edit_vip').checked = client.vip == 1;
    
    Company.openModal('editClientModal');
}

function deleteClient(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_client">
        <input type="hidden" name="client_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>