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

$pageTitle = 'Reprises de Véhicules';
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
    
    // Créer reprise
    if ($action === 'create_reprise') {
        try {
            $db->prepare("
                INSERT INTO lsm_reprises 
                (dealership_key, vente_id, client_id, marque, modele, annee, kilometrage, etat_general,
                 prix_estime, expertise_notes, evalue_par)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $dealershipKey,
                !empty($_POST['vente_id']) ? (int)$_POST['vente_id'] : null,
                (int)$_POST['client_id'],
                sanitize($_POST['marque']),
                sanitize($_POST['modele']),
                !empty($_POST['annee']) ? (int)$_POST['annee'] : null,
                !empty($_POST['kilometrage']) ? (int)$_POST['kilometrage'] : null,
                $_POST['etat_general'] ?? 'bon',
                !empty($_POST['prix_estime']) ? (float)$_POST['prix_estime'] : null,
                sanitize($_POST['expertise_notes'] ?? ''),
                $_SESSION['user_id']
            ]);
            
            $message = "✅ Reprise créée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Proposer prix
    if ($action === 'proposer_prix') {
        try {
            $id = (int)$_POST['reprise_id'];
            $db->prepare("
                UPDATE lsm_reprises 
                SET prix_propose=?, statut='proposee', expertise_faite=1
                WHERE id=? AND dealership_key=?
            ")->execute([
                (float)$_POST['prix_propose'],
                $id,
                $dealershipKey
            ]);
            $message = "✅ Prix proposé au client";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Accepter/Refuser reprise
    if ($action === 'change_statut') {
        try {
            $id = (int)$_POST['reprise_id'];
            $newStatut = $_POST['new_statut'];
            
            $update = "UPDATE lsm_reprises SET statut=?";
            $params = [$newStatut];
            
            if ($newStatut === 'acceptee' && !empty($_POST['prix_accepte'])) {
                $update .= ", prix_accepte=?";
                $params[] = (float)$_POST['prix_accepte'];
            }
            
            $update .= " WHERE id=? AND dealership_key=?";
            $params[] = $id;
            $params[] = $dealershipKey;
            
            $db->prepare($update)->execute($params);
            $message = "✅ Statut modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterStatut = $_GET['statut'] ?? '';
$search = $_GET['search'] ?? '';

// Requête reprises
$sql = "
    SELECT r.*,
           c.nom as client_nom, c.telephone as client_telephone,
           u.username as evalue_par_nom,
           v.reference as vente_reference
    FROM lsm_reprises r
    JOIN lsm_clients c ON r.client_id = c.id
    JOIN users u ON r.evalue_par = u.id
    LEFT JOIN lsm_ventes v ON r.vente_id = v.id
    WHERE r.dealership_key = $dk
";

if ($filterStatut) $sql .= " AND r.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (r.marque LIKE " . $db->quote("%$search%") . " OR r.modele LIKE " . $db->quote("%$search%") . " OR c.nom LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY r.created_at DESC";

$reprises = $db->query($sql)->fetchAll();

// Listes pour création
$clients = $db->query("SELECT * FROM lsm_clients ORDER BY nom")->fetchAll();
$ventesEnCours = $db->query("
    SELECT v.id, v.reference FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    WHERE v.statut='en_cours' AND vh.dealership_key = $dk
    ORDER BY v.created_at DESC
")->fetchAll();

// Stats
$stats = [
    'total' => count($reprises),
    'evaluation' => count(array_filter($reprises, fn($r) => $r['statut'] === 'evaluation')),
    'proposee' => count(array_filter($reprises, fn($r) => $r['statut'] === 'proposee')),
    'acceptee' => count(array_filter($reprises, fn($r) => $r['statut'] === 'acceptee')),
];

$statutColors = [
    'evaluation' => '#f59e0b',
    'proposee' => '#3b82f6',
    'acceptee' => '#22c55e',
    'refusee' => '#ef4444'
];

$etatColors = [
    'excellent' => '#22c55e',
    'bon' => '#3b82f6',
    'moyen' => '#f59e0b',
    'mauvais' => '#ef4444'
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
        <div class="stat-label">Total Reprises</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['evaluation']; ?></div>
        <div class="stat-label">En Évaluation</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['proposee']; ?></div>
        <div class="stat-label">Prix Proposés</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['acceptee']; ?></div>
        <div class="stat-label">Acceptées</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-exchange-alt"></i> Reprises de Véhicules</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createRepriseModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouvelle Reprise
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="statut" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="evaluation" <?php echo $filterStatut === 'evaluation' ? 'selected' : ''; ?>>Évaluation</option>
            <option value="proposee" <?php echo $filterStatut === 'proposee' ? 'selected' : ''; ?>>Proposée</option>
            <option value="acceptee" <?php echo $filterStatut === 'acceptee' ? 'selected' : ''; ?>>Acceptée</option>
            <option value="refusee" <?php echo $filterStatut === 'refusee' ? 'selected' : ''; ?>>Refusée</option>
        </select>
        
        <?php if ($search || $filterStatut): ?>
            <a href="reprises.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste reprises -->
<?php if (empty($reprises)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-exchange-alt" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucune reprise</h3>
        <p style="color: #64748b;">Ajoutez votre première reprise</p>
    </div>
<?php else: ?>
    <?php foreach ($reprises as $reprise): ?>
        <div class="reprise-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                        <span class="badge" style="background: <?php echo $statutColors[$reprise['statut']]; ?>20; color: <?php echo $statutColors[$reprise['statut']]; ?>; border-color: <?php echo $statutColors[$reprise['statut']]; ?>;">
                            <?php echo ucfirst($reprise['statut']); ?>
                        </span>
                        <span class="badge" style="background: <?php echo $etatColors[$reprise['etat_general']]; ?>20; color: <?php echo $etatColors[$reprise['etat_general']]; ?>; border-color: <?php echo $etatColors[$reprise['etat_general']]; ?>;">
                            État: <?php echo ucfirst($reprise['etat_general']); ?>
                        </span>
                        <?php if ($reprise['expertise_faite']): ?>
                            <span class="badge" style="background: rgba(34, 197, 94, 0.1); color: #22c55e; border-color: #22c55e;">
                                <i class="fas fa-check"></i> Expertisé
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.5rem;">
                        <?php echo htmlspecialchars($reprise['marque']); ?> <?php echo htmlspecialchars($reprise['modele']); ?>
                    </h3>
                    
                    <div style="color: #64748b;">
                        Client: <?php echo htmlspecialchars($reprise['client_nom']); ?>
                        <?php if ($reprise['vente_reference']): ?>
                            • Vente: <?php echo $reprise['vente_reference']; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                <?php if ($reprise['annee']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Année</div>
                        <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo $reprise['annee']; ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($reprise['kilometrage']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Kilométrage</div>
                        <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo number_format($reprise['kilometrage']); ?> km</div>
                    </div>
                <?php endif; ?>
                
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Évalué par</div>
                    <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo htmlspecialchars($reprise['evalue_par_nom']); ?></div>
                </div>
            </div>
            
            <?php if ($reprise['prix_estime'] || $reprise['prix_propose'] || $reprise['prix_accepte']): ?>
                <div class="prix-box">
                    <?php if ($reprise['prix_estime']): ?>
                        <div class="prix-item">
                            <div class="prix-label">Prix Estimé</div>
                            <div class="prix-value" style="color: #64748b;">
                                $<?php echo number_format($reprise['prix_estime'], 0); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($reprise['prix_propose']): ?>
                        <div class="prix-item">
                            <div class="prix-label">Prix Proposé</div>
                            <div class="prix-value">
                                $<?php echo number_format($reprise['prix_propose'], 0); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($reprise['prix_accepte']): ?>
                        <div class="prix-item">
                            <div class="prix-label">Prix Accepté</div>
                            <div class="prix-value" style="color: #22c55e;">
                                $<?php echo number_format($reprise['prix_accepte'], 0); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($reprise['expertise_notes']): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Notes d'Expertise:</div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($reprise['expertise_notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <?php if ($reprise['statut'] === 'evaluation'): ?>
                        <button onclick="proposerPrix(<?php echo $reprise['id']; ?>)" class="btn btn-primary btn-sm">
                            <i class="fas fa-tag"></i> Proposer un Prix
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($reprise['statut'] === 'proposee'): ?>
                        <button onclick="accepterReprise(<?php echo $reprise['id']; ?>, <?php echo $reprise['prix_propose'] ?? 0; ?>)" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                            <i class="fas fa-check"></i> Accepter
                        </button>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="reprise_id" value="<?php echo $reprise['id']; ?>">
                            <input type="hidden" name="new_statut" value="refusee">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color: #ef4444;" onclick="return confirm('Refuser cette reprise ?')">
                                <i class="fas fa-times"></i> Refuser
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Reprise -->
<div id="createRepriseModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle Reprise</h2>
            <button class="modal-close" onclick="Company.closeModal('createRepriseModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_reprise">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Client *</label>
                        <select name="client_id" class="form-select" required>
                            <option value="">Choisir un client...</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>">
                                    <?php echo htmlspecialchars($client['nom']); ?>
                                    <?php if ($client['prenom']): ?>
                                        <?php echo htmlspecialchars($client['prenom']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Vente Associée</label>
                        <select name="vente_id" class="form-select">
                            <option value="">Aucune</option>
                            <?php foreach ($ventesEnCours as $vente): ?>
                                <option value="<?php echo $vente['id']; ?>"><?php echo $vente['reference']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Marque *</label>
                        <input type="text" name="marque" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Modèle *</label>
                        <input type="text" name="modele" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Année</label>
                        <input type="number" name="annee" class="form-input" min="1900" max="2030">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kilométrage</label>
                        <input type="number" name="kilometrage" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">État Général</label>
                        <select name="etat_general" class="form-select">
                            <option value="excellent">Excellent</option>
                            <option value="bon" selected>Bon</option>
                            <option value="moyen">Moyen</option>
                            <option value="mauvais">Mauvais</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix Estimé ($)</label>
                        <input type="number" name="prix_estime" class="form-input" step="0.01">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes d'Expertise</label>
                        <textarea name="expertise_notes" class="form-textarea" rows="4"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createRepriseModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Créer la Reprise
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Proposer Prix -->
<div id="proposerPrixModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-tag"></i> Proposer un Prix</h2>
            <button class="modal-close" onclick="Company.closeModal('proposerPrixModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="proposer_prix">
                <input type="hidden" name="reprise_id" id="proposer_reprise_id">
                
                <div class="form-group">
                    <label class="form-label">Prix Proposé au Client ($) *</label>
                    <input type="number" name="prix_propose" class="form-input" step="0.01" required>
                </div>
                
                <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                        Ce prix sera proposé au client. Il pourra l'accepter ou refuser.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('proposerPrixModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Proposer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Accepter Reprise -->
<div id="accepterRepriseModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-check"></i> Accepter la Reprise</h2>
            <button class="modal-close" onclick="Company.closeModal('accepterRepriseModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="change_statut">
                <input type="hidden" name="reprise_id" id="accepter_reprise_id">
                <input type="hidden" name="new_statut" value="acceptee">
                
                <div class="form-group">
                    <label class="form-label">Prix Final Accepté ($) *</label>
                    <input type="number" name="prix_accepte" class="form-input" step="0.01" id="accepter_prix" required>
                </div>
                
                <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        <i class="fas fa-check-circle" style="color: #22c55e;"></i>
                        La reprise sera acceptée et vous pourrez ajouter ce véhicule au stock.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('accepterRepriseModal')">Annuler</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                    <i class="fas fa-check"></i> Accepter
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function proposerPrix(id) {
    document.getElementById('proposer_reprise_id').value = id;
    Company.openModal('proposerPrixModal');
}

function accepterReprise(id, prixPropose) {
    document.getElementById('accepter_reprise_id').value = id;
    document.getElementById('accepter_prix').value = prixPropose;
    Company.openModal('accepterRepriseModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>