<?php
require_once __DIR__ . '/../../../config/config.php';
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

$pageTitle = 'Gestion des Ventes';
$db = Database::getInstance()->getConnection();
$message = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'vente.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'vente.create');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'vente.delete');

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    
    // Créer vente
    if ($action === 'create_vente') {
        try {
            $db->beginTransaction();
            
            $ref = lsm_sequence_next($db, $dealershipKey, 'VTE', 'VTE-');
            $prixFinal = (float)$_POST['prix_final'];
            $remise = !empty($_POST['remise']) ? (float)$_POST['remise'] : 0;
            $remisePct = $prixFinal > 0 ? ($remise / ($prixFinal + $remise)) * 100 : 0;
            $commissionPct = !empty($_POST['commission_pct']) ? (float)$_POST['commission_pct'] : 5;
            $commissionVendeur = ($prixFinal * $commissionPct) / 100;
            
            $servicesInclus = !empty($_POST['services_inclus']) ? json_encode(array_values(array_filter($_POST['services_inclus']))) : null;
            
            $db->prepare("
                INSERT INTO lsm_ventes 
                (reference, vehicule_id, client_id, vendeur_id, prix_final, remise, remise_pct,
                 mode_paiement, acompte, commission_vendeur, commission_pct, garantie_mois, garantie_km,
                 services_inclus, statut, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_cours', ?)
            ")->execute([
                $ref,
                (int)$_POST['vehicule_id'],
                (int)$_POST['client_id'],
                $_SESSION['user_id'],
                $prixFinal,
                $remise,
                $remisePct,
                $_POST['mode_paiement'] ?? 'comptant',
                !empty($_POST['acompte']) ? (float)$_POST['acompte'] : 0,
                $commissionVendeur,
                $commissionPct,
                (int)($_POST['garantie_mois'] ?? 12),
                (int)($_POST['garantie_km'] ?? 20000),
                $servicesInclus,
                sanitize($_POST['notes'] ?? '')
            ]);
            
            // Mettre véhicule en réservé
            $db->prepare("UPDATE lsm_vehicules SET statut='reserve' WHERE id=? AND dealership_key=?")->execute([(int)$_POST['vehicule_id'], $dealershipKey]);

            // Suivi classement ventes (hebdo)
            $cid = (int)$_POST['client_id'];
            $cst = $db->prepare('SELECT nom FROM lsm_clients WHERE id=? LIMIT 1');
            $cst->execute([$cid]);
            $customerName = (string)($cst->fetchColumn() ?: 'Client');
            $weekSales = date('Y-\WW');
            $db->prepare(
                'INSERT INTO company_sales (faction, employee_id, amount, customer, week) VALUES (?,?,?,?,?)'
            )->execute([$companyFaction, (int)$_SESSION['user_id'], $prixFinal, $customerName, $weekSales]);

            $db->commit();
            $message = "✅ Vente $ref créée avec succès (Commission: $".number_format($commissionVendeur, 2).")";
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Changer statut vente
    if ($action === 'change_statut') {
        try {
            $id = (int)$_POST['vente_id'];
            $newStatut = $_POST['new_statut'];
            
            if ($newStatut === 'livree') {
                // Marquer véhicule comme vendu
                $vente = $db->prepare("SELECT vehicule_id FROM lsm_ventes WHERE id=?")->execute([$id]) ? $db->query("SELECT vehicule_id FROM lsm_ventes WHERE id=$id")->fetch() : null;
                if ($vente) {
                    $db->prepare("UPDATE lsm_vehicules SET statut='vendu', vendu_le=CURDATE() WHERE id=? AND dealership_key=?")->execute([$vente['vehicule_id'], $dealershipKey]);
                }
            }
            
            $db->prepare("UPDATE lsm_ventes SET statut=? WHERE id=?")->execute([$newStatut, $id]);
            $message = "✅ Statut modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Valider contrat
    if ($action === 'valider_contrat') {
        try {
            $id = (int)$_POST['vente_id'];
            $db->prepare("
                UPDATE lsm_ventes 
                SET contrat_signe=1, date_signature=CURDATE(), numero_contrat=?, statut='validee'
                WHERE id=?
            ")->execute([
                sanitize($_POST['numero_contrat']),
                $id
            ]);
            $message = "✅ Contrat validé";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Filtres
$filterStatut = $_GET['statut'] ?? '';
$filterVendeur = $_GET['vendeur'] ?? '';
$search = $_GET['search'] ?? '';

// Requête ventes
$sql = "
    SELECT v.*, 
           vh.modele as vehicule_modele, m.nom as marque_nom,
           c.nom as client_nom, c.telephone as client_telephone,
           u.username as vendeur_nom
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    LEFT JOIN lsm_marques m ON vh.marque_id = m.id
    JOIN lsm_clients c ON v.client_id = c.id
    JOIN users u ON v.vendeur_id = u.id
    WHERE vh.dealership_key = " . $db->quote($dealershipKey) . "
";

if ($filterStatut) $sql .= " AND v.statut = " . $db->quote($filterStatut);
if ($filterVendeur) $sql .= " AND v.vendeur_id = " . (int)$filterVendeur;
if ($search) $sql .= " AND (c.nom LIKE " . $db->quote("%$search%") . " OR v.reference LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY v.created_at DESC";

$ventes = $db->query($sql)->fetchAll();

// Vendeurs pour filtre
$vendeurs = $db->query("SELECT id, username FROM users WHERE faction='$companyFaction' ORDER BY username")->fetchAll();

// Véhicules disponibles
$vehiculesDispo = $db->query("
    SELECT v.*, m.nom as marque_nom, c.nom as categorie_nom
    FROM lsm_vehicules v
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    LEFT JOIN lsm_categories c ON v.categorie_id = c.id
    WHERE v.statut = 'disponible' AND v.dealership_key = " . $db->quote($dealershipKey) . "
    ORDER BY m.nom, v.modele
")->fetchAll();

// Clients
$clients = $db->query("SELECT * FROM lsm_clients ORDER BY nom")->fetchAll();

// Stats
$stats = [
    'total' => count($ventes),
    'en_cours' => count(array_filter($ventes, fn($v) => $v['statut'] === 'en_cours')),
    'validee' => count(array_filter($ventes, fn($v) => $v['statut'] === 'validee')),
    'livree' => count(array_filter($ventes, fn($v) => $v['statut'] === 'livree')),
];

$statutColors = [
    'en_cours' => '#f59e0b',
    'validee' => '#3b82f6',
    'livree' => '#22c55e',
    'annulee' => '#ef4444'
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
        <div class="stat-label">Total Ventes</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['en_cours']; ?></div>
        <div class="stat-label">En Cours</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['validee']; ?></div>
        <div class="stat-label">Validées</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['livree']; ?></div>
        <div class="stat-label">Livrées</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-handshake"></i> Toutes les Ventes</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createVenteModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouvelle Vente
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="statut" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="en_cours" <?php echo $filterStatut === 'en_cours' ? 'selected' : ''; ?>>En Cours</option>
            <option value="validee" <?php echo $filterStatut === 'validee' ? 'selected' : ''; ?>>Validée</option>
            <option value="livree" <?php echo $filterStatut === 'livree' ? 'selected' : ''; ?>>Livrée</option>
            <option value="annulee" <?php echo $filterStatut === 'annulee' ? 'selected' : ''; ?>>Annulée</option>
        </select>
        
        <select name="vendeur" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous vendeurs</option>
            <?php foreach ($vendeurs as $vendeur): ?>
                <option value="<?php echo $vendeur['id']; ?>" <?php echo $filterVendeur == $vendeur['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($vendeur['username']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <?php if ($search || $filterStatut || $filterVendeur): ?>
            <a href="ventes.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste ventes -->
<?php if (empty($ventes)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-handshake" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucune vente trouvée</h3>
        <p style="color: #64748b;">Créez votre première vente</p>
    </div>
<?php else: ?>
    <?php foreach ($ventes as $vente): 
        $servicesInclus = $vente['services_inclus'] ? json_decode($vente['services_inclus'], true) : [];
    ?>
        <div class="vente-card">
            <div class="vente-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.9rem; color: #64748b;">Réf: <?php echo $vente['reference']; ?></span>
                        <span class="badge" style="background: <?php echo $statutColors[$vente['statut']]; ?>20; color: <?php echo $statutColors[$vente['statut']]; ?>; border-color: <?php echo $statutColors[$vente['statut']]; ?>;">
                            <?php echo ucfirst(str_replace('_', ' ', $vente['statut'])); ?>
                        </span>
                    </div>
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.25rem;">
                        <?php echo htmlspecialchars($vente['marque_nom']); ?> <?php echo htmlspecialchars($vente['vehicule_modele']); ?>
                    </h3>
                    <div style="color: #64748b;">
                        Client: <?php echo htmlspecialchars($vente['client_nom']); ?> • 
                        Vendeur: <?php echo htmlspecialchars($vente['vendeur_nom']); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 2rem; font-weight: 700; color: #22c55e; font-family: 'Orbitron', monospace;">
                        $<?php echo number_format($vente['prix_final'], 0); ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        <?php echo date('d/m/Y', strtotime($vente['created_at'])); ?>
                    </div>
                </div>
            </div>
            
            <div class="vente-details">
                <div class="detail-item">
                    <div class="detail-label">Mode Paiement</div>
                    <div class="detail-value"><?php echo ucfirst($vente['mode_paiement']); ?></div>
                </div>
                
                <?php if ($vente['acompte'] > 0): ?>
                    <div class="detail-item">
                        <div class="detail-label">Acompte</div>
                        <div class="detail-value">$<?php echo number_format($vente['acompte'], 0); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($vente['remise'] > 0): ?>
                    <div class="detail-item">
                        <div class="detail-label">Remise</div>
                        <div class="detail-value" style="color: #f59e0b;">
                            -$<?php echo number_format($vente['remise'], 0); ?> 
                            (<?php echo number_format($vente['remise_pct'], 1); ?>%)
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <div class="detail-label">Commission Vendeur</div>
                    <div class="detail-value" style="color: #3b82f6;">
                        $<?php echo number_format($vente['commission_vendeur'], 2); ?>
                        (<?php echo number_format($vente['commission_pct'], 1); ?>%)
                    </div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Garantie</div>
                    <div class="detail-value">
                        <?php echo $vente['garantie_mois']; ?> mois / <?php echo number_format($vente['garantie_km']); ?> km
                    </div>
                </div>
            </div>
            
            <?php if ($vente['contrat_signe']): ?>
                <div style="padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 10px; margin: 1rem 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <i class="fas fa-check-circle" style="color: #22c55e; margin-right: 0.5rem;"></i>
                            <span style="font-weight: 600;">Contrat Signé</span>
                        </div>
                        <div style="color: #64748b;">
                            N° <?php echo $vente['numero_contrat']; ?> • 
                            <?php echo date('d/m/Y', strtotime($vente['date_signature'])); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($vente['notes']): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Notes:</div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($vente['notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <?php if ($vente['statut'] === 'en_cours' && !$vente['contrat_signe']): ?>
                        <button onclick="validerContrat(<?php echo $vente['id']; ?>)" class="btn btn-primary btn-sm">
                            <i class="fas fa-file-signature"></i> Valider Contrat
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($vente['statut'] === 'validee'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
                            <input type="hidden" name="new_statut" value="livree">
                            <button type="submit" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                                <i class="fas fa-check-circle"></i> Marquer Livrée
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($vente['statut'] !== 'annulee' && $vente['statut'] !== 'livree'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="vente_id" value="<?php echo $vente['id']; ?>">
                            <input type="hidden" name="new_statut" value="annulee">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color: #ef4444;" onclick="return confirm('Annuler cette vente ?')">
                                <i class="fas fa-times-circle"></i> Annuler
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Vente -->
<div id="createVenteModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle Vente</h2>
            <button class="modal-close" onclick="Company.closeModal('createVenteModal')">×</button>
        </div>
        <form method="POST" id="createVenteForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_vente">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Véhicule *</label>
                        <select name="vehicule_id" class="form-select" required id="vehicule_select">
                            <option value="">Choisir un véhicule...</option>
                            <?php foreach ($vehiculesDispo as $vh): ?>
                                <option value="<?php echo $vh['id']; ?>" data-prix="<?php echo $vh['prix_vente']; ?>">
                                    <?php echo htmlspecialchars($vh['marque_nom']); ?> <?php echo htmlspecialchars($vh['modele']); ?> 
                                    - $<?php echo number_format($vh['prix_vente'], 0); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Client *</label>
                        <select name="client_id" class="form-select" required>
                            <option value="">Choisir un client...</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>">
                                    <?php echo htmlspecialchars($client['nom']); ?>
                                    <?php if ($client['telephone']): ?>
                                        - <?php echo htmlspecialchars($client['telephone']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #64748b; margin-top: 0.5rem;">
                            <a href="clients.php" target="_blank" style="color: #ef4444;">Créer un nouveau client</a>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prix Final *</label>
                        <input type="number" name="prix_final" class="form-input" step="0.01" required id="prix_final">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Remise ($)</label>
                        <input type="number" name="remise" class="form-input" step="0.01" value="0" id="remise_input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mode Paiement</label>
                        <select name="mode_paiement" class="form-select">
                            <option value="comptant">Comptant</option>
                            <option value="credit">Crédit</option>
                            <option value="leasing">Leasing</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Acompte ($)</label>
                        <input type="number" name="acompte" class="form-input" step="0.01" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Commission Vendeur (%)</label>
                        <input type="number" name="commission_pct" class="form-input" step="0.1" value="5" id="commission_pct">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Garantie (mois)</label>
                        <input type="number" name="garantie_mois" class="form-input" value="12">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Garantie (km)</label>
                        <input type="number" name="garantie_km" class="form-input" value="20000">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Services Inclus (un par ligne)</label>
                        <textarea id="servicesInput" class="form-textarea" rows="2" placeholder="Révision gratuite 1 an&#10;Vidange offerte"></textarea>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" rows="2"></textarea>
                    </div>
                </div>
                
                <div id="commissionPreview" style="margin-top: 1rem; padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 10px; display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600;">Commission calculée:</span>
                        <span id="commissionAmount" style="font-size: 1.3rem; font-weight: 700; color: #3b82f6;"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createVenteModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Créer la Vente
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Valider Contrat -->
<div id="validerContratModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-signature"></i> Valider le Contrat</h2>
            <button class="modal-close" onclick="Company.closeModal('validerContratModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="valider_contrat">
                <input type="hidden" name="vente_id" id="valider_vente_id">
                
                <div class="form-group">
                    <label class="form-label">Numéro de Contrat *</label>
                    <input type="text" name="numero_contrat" class="form-input" required placeholder="CTR-2025-001">
                </div>
                
                <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        La validation du contrat changera le statut de la vente en "Validée" et enregistrera la date de signature.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('validerContratModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Valider
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-remplir prix avec prix véhicule
document.getElementById('vehicule_select')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const prix = selectedOption.getAttribute('data-prix');
    if (prix) {
        document.getElementById('prix_final').value = prix;
        updateCommission();
    }
});

// Calcul commission temps réel
document.getElementById('prix_final')?.addEventListener('input', updateCommission);
document.getElementById('commission_pct')?.addEventListener('input', updateCommission);

function updateCommission() {
    const prixFinal = parseFloat(document.getElementById('prix_final').value) || 0;
    const commissionPct = parseFloat(document.getElementById('commission_pct').value) || 0;
    
    if (prixFinal > 0 && commissionPct > 0) {
        const commission = (prixFinal * commissionPct) / 100;
        document.getElementById('commissionPreview').style.display = 'block';
        document.getElementById('commissionAmount').textContent = '$' + commission.toLocaleString('fr-FR', {minimumFractionDigits: 2});
    } else {
        document.getElementById('commissionPreview').style.display = 'none';
    }
}

// Convert services textarea to hidden inputs
document.getElementById('createVenteForm').addEventListener('submit', function(e) {
    const textarea = document.getElementById('servicesInput');
    const lines = textarea.value.split('\n').filter(l => l.trim());
    lines.forEach(line => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'services_inclus[]';
        input.value = line.trim();
        this.appendChild(input);
    });
});

function validerContrat(id) {
    document.getElementById('valider_vente_id').value = id;
    Company.openModal('validerContratModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>