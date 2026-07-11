<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName = 'LS Motors';
$companyColor = '#3b82f6';
$companyFaction = 'LS_MOTORS';
$dealershipKey = 'LS_MOTORS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Gestion des Plaques';
$db = Database::getInstance()->getConnection();
$dk = $db->quote($dealershipKey);
$message = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'stock.view')) faction_acl_deny();
$canManage = faction_acl_can($db, $uid, $companyFaction, 'stock.add');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'stock.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'stock.delete');

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    
    // Créer plaque
    if ($action === 'create_plaque') {
        try {
            $numeroPlaque = strtoupper(sanitize($_POST['numero_plaque']));
            
            // Vérifier unicité
            $exists = $db->prepare("SELECT id FROM lsm_plaques WHERE numero_plaque=?")->execute([$numeroPlaque]) ? $db->query("SELECT id FROM lsm_plaques WHERE numero_plaque='$numeroPlaque'")->fetch() : null;
            if ($exists) {
                throw new Exception("Ce numéro de plaque existe déjà");
            }
            
            // Récupérer infos vente
            $vst = $db->prepare("SELECT v.vehicule_id, v.client_id FROM lsm_ventes v JOIN lsm_vehicules vh ON v.vehicule_id = vh.id WHERE v.id=? AND vh.dealership_key=?");
            $vst->execute([(int)$_POST['vente_id'], $dealershipKey]);
            $vente = $vst->fetch(PDO::FETCH_ASSOC);
            if (!$vente) {
                throw new Exception("Vente introuvable");
            }
            
            $db->prepare("
                INSERT INTO lsm_plaques 
                (numero_plaque, vente_id, vehicule_id, client_id, type, date_demande, cout_plaque, notes, declare_par)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)
            ")->execute([
                $numeroPlaque,
                (int)$_POST['vente_id'],
                $vente['vehicule_id'],
                $vente['client_id'],
                $_POST['type'] ?? 'standard',
                !empty($_POST['cout_plaque']) ? (float)$_POST['cout_plaque'] : 0,
                sanitize($_POST['notes'] ?? ''),
                $_SESSION['user_id']
            ]);
            
            $message = "✅ Plaque $numeroPlaque déclarée avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Changer statut plaque
    if ($action === 'change_statut') {
        try {
            $id = (int)$_POST['plaque_id'];
            $newStatut = $_POST['new_statut'];
            
            $updateFields = "statut=?";
            $params = [$newStatut];
            
            if ($newStatut === 'attribuee') {
                $updateFields .= ", date_attribution=CURDATE()";
            } elseif ($newStatut === 'installee') {
                $updateFields .= ", date_installation=CURDATE()";
            }
            
            $params[] = $id;
            $db->prepare("UPDATE lsm_plaques SET $updateFields WHERE id=?")->execute($params);
            
            $message = "✅ Statut modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Supprimer plaque
    if ($action === 'delete_plaque') {
        try {
            $id = (int)$_POST['plaque_id'];
            $db->prepare("DELETE FROM lsm_plaques WHERE id=? AND statut='en_attente'")->execute([$id]);
            $message = "✅ Plaque supprimée";
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

// Requête plaques
$sql = "
    SELECT p.*,
           v.modele as vehicule_modele, m.nom as marque_nom,
           c.nom as client_nom, c.telephone as client_telephone,
           u.username as declare_par_nom,
           vte.reference as vente_reference
    FROM lsm_plaques p
    JOIN lsm_vehicules v ON p.vehicule_id = v.id
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    JOIN lsm_clients c ON p.client_id = c.id
    JOIN users u ON p.declare_par = u.id
    JOIN lsm_ventes vte ON p.vente_id = vte.id
    WHERE v.dealership_key = $dk
";

if ($filterStatut) $sql .= " AND p.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (p.numero_plaque LIKE " . $db->quote("%$search%") . " OR c.nom LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY p.created_at DESC";

$plaques = $db->query($sql)->fetchAll();

// Ventes pour nouvelle plaque
$ventesForPlaque = $db->query("
    SELECT v.*, vh.modele, m.nom as marque_nom, c.nom as client_nom
    FROM lsm_ventes v
    JOIN lsm_vehicules vh ON v.vehicule_id = vh.id
    LEFT JOIN lsm_marques m ON vh.marque_id = m.id
    JOIN lsm_clients c ON v.client_id = c.id
    WHERE v.statut IN ('validee', 'livree')
    AND vh.dealership_key = $dk
    AND v.id NOT IN (SELECT vente_id FROM lsm_plaques)
    ORDER BY v.created_at DESC
")->fetchAll();

// Stats
$stats = [
    'total' => count($plaques),
    'en_attente' => count(array_filter($plaques, fn($p) => $p['statut'] === 'en_attente')),
    'attribuee' => count(array_filter($plaques, fn($p) => $p['statut'] === 'attribuee')),
    'installee' => count(array_filter($plaques, fn($p) => $p['statut'] === 'installee')),
];

$statutColors = [
    'en_attente' => '#f59e0b',
    'attribuee' => '#3b82f6',
    'installee' => '#22c55e',
    'annulee' => '#ef4444'
];

$typeBadges = [
    'standard' => ['color' => '#3b82f6', 'label' => 'Standard'],
    'personnalisee' => ['color' => '#8b5cf6', 'label' => 'Personnalisée'],
    'temporaire' => ['color' => '#f59e0b', 'label' => 'Temporaire']
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
        <div class="stat-label">Total Plaques</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['en_attente']; ?></div>
        <div class="stat-label">En Attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['attribuee']; ?></div>
        <div class="stat-label">Attribuées</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['installee']; ?></div>
        <div class="stat-label">Installées</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-id-card"></i> Toutes les Plaques</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createPlaqueModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nouvelle Déclaration
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="statut" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="en_attente" <?php echo $filterStatut === 'en_attente' ? 'selected' : ''; ?>>En Attente</option>
            <option value="attribuee" <?php echo $filterStatut === 'attribuee' ? 'selected' : ''; ?>>Attribuée</option>
            <option value="installee" <?php echo $filterStatut === 'installee' ? 'selected' : ''; ?>>Installée</option>
            <option value="annulee" <?php echo $filterStatut === 'annulee' ? 'selected' : ''; ?>>Annulée</option>
        </select>
        
        <?php if ($search || $filterStatut): ?>
            <a href="plaques.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste plaques -->
<?php if (empty($plaques)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-id-card" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucune plaque déclarée</h3>
        <p style="color: #64748b;">Déclarez votre première plaque</p>
    </div>
<?php else: ?>
    <?php foreach ($plaques as $plaque): ?>
        <div class="plaque-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                <div>
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
                        <span class="badge" style="background: <?php echo $typeBadges[$plaque['type']]['color']; ?>20; color: <?php echo $typeBadges[$plaque['type']]['color']; ?>; border-color: <?php echo $typeBadges[$plaque['type']]['color']; ?>;">
                            <?php echo $typeBadges[$plaque['type']]['label']; ?>
                        </span>
                        <span class="badge" style="background: <?php echo $statutColors[$plaque['statut']]; ?>20; color: <?php echo $statutColors[$plaque['statut']]; ?>; border-color: <?php echo $statutColors[$plaque['statut']]; ?>;">
                            <?php echo ucfirst(str_replace('_', ' ', $plaque['statut'])); ?>
                        </span>
                    </div>
                    <h3 style="font-size: 1.3rem; font-weight: 700; color: #e8edf5;">
                        <?php echo htmlspecialchars($plaque['marque_nom']); ?> <?php echo htmlspecialchars($plaque['vehicule_modele']); ?>
                    </h3>
                    <div style="color: #64748b; margin-top: 0.25rem;">
                        Client: <?php echo htmlspecialchars($plaque['client_nom']); ?> • 
                        Vente: <?php echo $plaque['vente_reference']; ?>
                    </div>
                </div>
            </div>
            
            <div class="plaque-numero">
                <?php echo htmlspecialchars($plaque['numero_plaque']); ?>
            </div>
            
            <!-- Timeline -->
            <div class="timeline">
                <div class="timeline-step <?php echo $plaque['statut'] !== null ? 'active' : ''; ?>">
                    <div class="timeline-step-circle">
                        <i class="fas fa-file-alt" style="color: <?php echo $plaque['statut'] !== null ? 'white' : '#64748b'; ?>;"></i>
                    </div>
                    <div class="timeline-step-label">Demande</div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                        <?php echo date('d/m/Y', strtotime($plaque['date_demande'])); ?>
                    </div>
                </div>
                
                <div class="timeline-step <?php echo in_array($plaque['statut'], ['attribuee', 'installee']) ? 'active' : ''; ?>">
                    <div class="timeline-step-circle">
                        <i class="fas fa-check" style="color: <?php echo in_array($plaque['statut'], ['attribuee', 'installee']) ? 'white' : '#64748b'; ?>;"></i>
                    </div>
                    <div class="timeline-step-label">Attribuée</div>
                    <?php if ($plaque['date_attribution']): ?>
                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                            <?php echo date('d/m/Y', strtotime($plaque['date_attribution'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="timeline-step <?php echo $plaque['statut'] === 'installee' ? 'active' : ''; ?>">
                    <div class="timeline-step-circle">
                        <i class="fas fa-car" style="color: <?php echo $plaque['statut'] === 'installee' ? 'white' : '#64748b'; ?>;"></i>
                    </div>
                    <div class="timeline-step-label">Installée</div>
                    <?php if ($plaque['date_installation']): ?>
                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                            <?php echo date('d/m/Y', strtotime($plaque['date_installation'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Infos -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0; padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px;">
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Organisme</div>
                    <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo htmlspecialchars($plaque['organisme_delivrance']); ?></div>
                </div>
                
                <?php if ($plaque['cout_plaque'] > 0): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Coût</div>
                        <div style="font-weight: 600; margin-top: 0.25rem;">$<?php echo number_format($plaque['cout_plaque'], 2); ?></div>
                    </div>
                <?php endif; ?>
                
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Déclaré par</div>
                    <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo htmlspecialchars($plaque['declare_par_nom']); ?></div>
                </div>
            </div>
            
            <?php if ($plaque['notes']): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Notes:</div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($plaque['notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <?php if ($plaque['statut'] === 'en_attente'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="plaque_id" value="<?php echo $plaque['id']; ?>">
                            <input type="hidden" name="new_statut" value="attribuee">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-check"></i> Marquer Attribuée
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($plaque['statut'] === 'attribuee'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="plaque_id" value="<?php echo $plaque['id']; ?>">
                            <input type="hidden" name="new_statut" value="installee">
                            <button type="submit" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                                <i class="fas fa-car"></i> Marquer Installée
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($plaque['statut'] === 'en_attente'): ?>
                        <button type="button" onclick='MDTConfirm.runIfConfirmed("Supprimer cette plaque ?", () => deletePlaque(<?php echo $plaque['id']; ?>))' class="btn btn-secondary btn-sm" style="color: #ef4444;">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Plaque -->
<div id="createPlaqueModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Nouvelle Déclaration de Plaque</h2>
            <button class="modal-close" onclick="Company.closeModal('createPlaqueModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_plaque">
                
                <div class="form-grid">
                    <div class="form-group form-full">
                        <label class="form-label">Vente *</label>
                        <select name="vente_id" class="form-select" required>
                            <option value="">Choisir une vente...</option>
                            <?php foreach ($ventesForPlaque as $vente): ?>
                                <option value="<?php echo $vente['id']; ?>">
                                    <?php echo $vente['reference']; ?> - 
                                    <?php echo htmlspecialchars($vente['marque_nom']); ?> <?php echo htmlspecialchars($vente['modele']); ?> - 
                                    <?php echo htmlspecialchars($vente['client_nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Numéro de Plaque *</label>
                        <input type="text" name="numero_plaque" class="form-input" required placeholder="5LSM123" style="text-transform: uppercase;">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="standard">Standard</option>
                            <option value="personnalisee">Personnalisée</option>
                            <option value="temporaire">Temporaire</option>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Coût de la Plaque ($)</label>
                        <input type="number" name="cout_plaque" class="form-input" step="0.01" value="0">
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
                
                <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 10px; margin-top: 1rem;">
                    <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
                        <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                        La plaque sera créée avec le statut "En Attente". Vous pourrez ensuite la marquer comme "Attribuée" une fois le DMV aura validé, puis "Installée" une fois posée sur le véhicule.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createPlaqueModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Déclarer la Plaque
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function deletePlaque(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_plaque">
        <input type="hidden" name="plaque_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>