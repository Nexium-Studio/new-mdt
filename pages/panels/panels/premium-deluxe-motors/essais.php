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

$pageTitle = 'Rendez-vous d\'Essai';
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
    
    // Créer essai
    if ($action === 'create_essai') {
        try {
            $db->prepare("
                INSERT INTO lsm_essais 
                (client_id, vehicule_id, date_rdv, duree_minutes, vendeur_accompagnant, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$_POST['client_id'],
                (int)$_POST['vehicule_id'],
                $_POST['date_rdv'] . ' ' . $_POST['heure_rdv'],
                (int)($_POST['duree_minutes'] ?? 30),
                !empty($_POST['vendeur_accompagnant']) ? (int)$_POST['vendeur_accompagnant'] : null,
                sanitize($_POST['notes'] ?? ''),
                $_SESSION['user_id']
            ]);
            
            $message = "✅ Rendez-vous d'essai créé avec succès";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Changer statut
    if ($action === 'change_statut') {
        try {
            $id = (int)$_POST['essai_id'];
            $newStatut = $_POST['new_statut'];
            $db->prepare("UPDATE lsm_essais SET statut=? WHERE id=?")->execute([$newStatut, $id]);
            $message = "✅ Statut modifié";
            $msgType = 'success';
        } catch (Exception $e) {
            $message = '❌ ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    
    // Terminer essai avec résultat
    if ($action === 'terminer_essai') {
        try {
            $id = (int)$_POST['essai_id'];
            $db->prepare("
                UPDATE lsm_essais 
                SET statut='termine', resultat=?, notes=?
                WHERE id=?
            ")->execute([
                $_POST['resultat'],
                sanitize($_POST['notes'] ?? ''),
                $id
            ]);
            $message = "✅ Essai terminé";
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

// Requête essais
$sql = "
    SELECT e.*,
           c.nom as client_nom, c.telephone as client_telephone,
           v.modele as vehicule_modele, m.nom as marque_nom,
           u1.username as created_by_nom,
           u2.username as vendeur_nom
    FROM lsm_essais e
    JOIN lsm_clients c ON e.client_id = c.id
    JOIN lsm_vehicules v ON e.vehicule_id = v.id
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    JOIN users u1 ON e.created_by = u1.id
    LEFT JOIN users u2 ON e.vendeur_accompagnant = u2.id
    WHERE v.dealership_key = $dk
";

if ($filterStatut) $sql .= " AND e.statut = " . $db->quote($filterStatut);
if ($search) $sql .= " AND (c.nom LIKE " . $db->quote("%$search%") . " OR v.modele LIKE " . $db->quote("%$search%") . ")";

$sql .= " ORDER BY e.date_rdv DESC";

$essais = $db->query($sql)->fetchAll();

// Listes pour création
$clients = $db->query("SELECT * FROM lsm_clients ORDER BY nom")->fetchAll();
$vehicules = $db->query("
    SELECT v.*, m.nom as marque_nom
    FROM lsm_vehicules v
    LEFT JOIN lsm_marques m ON v.marque_id = m.id
    WHERE v.statut = 'disponible' AND v.dealership_key = $dk
    ORDER BY m.nom, v.modele
")->fetchAll();
$vendeurs = $db->query("SELECT id, username FROM users WHERE faction='$companyFaction' ORDER BY username")->fetchAll();

// Stats
$stats = [
    'total' => count($essais),
    'planifie' => count(array_filter($essais, fn($e) => $e['statut'] === 'planifie')),
    'termine' => count(array_filter($essais, fn($e) => $e['statut'] === 'termine')),
    'interesse' => count(array_filter($essais, fn($e) => $e['resultat'] === 'interesse')),
];

$statutColors = [
    'planifie' => '#3b82f6',
    'en_cours' => '#f59e0b',
    'termine' => '#22c55e',
    'annule' => '#ef4444'
];

$resultatColors = [
    'interesse' => '#22c55e',
    'pas_interesse' => '#ef4444',
    'a_reflechi' => '#f59e0b'
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
        <div class="stat-label">Total Essais</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['planifie']; ?></div>
        <div class="stat-label">Planifiés</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['termine']; ?></div>
        <div class="stat-label">Terminés</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['interesse']; ?></div>
        <div class="stat-label">Clients Intéressés</div>
    </div>
</div>

<!-- Header + Filtres -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2><i class="fas fa-key"></i> Rendez-vous d'Essai</h2>
        <?php if ($canManage): ?>
            <button onclick="Company.openModal('createEssaiModal')" class="btn btn-primary">
                <i class="fas fa-plus"></i> Planifier un Essai
            </button>
        <?php endif; ?>
    </div>
    
    <form class="filters-bar" method="GET">
        <input type="text" name="search" class="form-input" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 250px;">
        
        <select name="statut" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
            <option value="">Tous statuts</option>
            <option value="planifie" <?php echo $filterStatut === 'planifie' ? 'selected' : ''; ?>>Planifié</option>
            <option value="en_cours" <?php echo $filterStatut === 'en_cours' ? 'selected' : ''; ?>>En Cours</option>
            <option value="termine" <?php echo $filterStatut === 'termine' ? 'selected' : ''; ?>>Terminé</option>
            <option value="annule" <?php echo $filterStatut === 'annule' ? 'selected' : ''; ?>>Annulé</option>
        </select>
        
        <?php if ($search || $filterStatut): ?>
            <a href="essais.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste essais -->
<?php if (empty($essais)): ?>
    <div class="card" style="text-align: center; padding: 4rem;">
        <i class="fas fa-key" style="font-size: 5rem; color: #64748b; margin-bottom: 1.5rem;"></i>
        <h3 style="color: #94a3b8;">Aucun essai planifié</h3>
        <p style="color: #64748b;">Planifiez votre premier rendez-vous d'essai</p>
    </div>
<?php else: ?>
    <?php foreach ($essais as $essai): ?>
        <div class="essai-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1.5rem;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                        <span class="badge" style="background: <?php echo $statutColors[$essai['statut']]; ?>20; color: <?php echo $statutColors[$essai['statut']]; ?>; border-color: <?php echo $statutColors[$essai['statut']]; ?>;">
                            <?php echo ucfirst(str_replace('_', ' ', $essai['statut'])); ?>
                        </span>
                        
                        <?php if ($essai['resultat']): ?>
                            <span class="badge" style="background: <?php echo $resultatColors[$essai['resultat']]; ?>20; color: <?php echo $resultatColors[$essai['resultat']]; ?>; border-color: <?php echo $resultatColors[$essai['resultat']]; ?>;">
                                <?php echo ucfirst(str_replace('_', ' ', $essai['resultat'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: #e8edf5; margin-bottom: 0.5rem;">
                        <?php echo htmlspecialchars($essai['marque_nom']); ?> <?php echo htmlspecialchars($essai['vehicule_modele']); ?>
                    </h3>
                    
                    <div style="color: #64748b;">
                        Client: <?php echo htmlspecialchars($essai['client_nom']); ?>
                        <?php if ($essai['client_telephone']): ?>
                            • <?php echo htmlspecialchars($essai['client_telephone']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="rdv-badge">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d/m/Y', strtotime($essai['date_rdv'])); ?><br>
                    <?php echo date('H:i', strtotime($essai['date_rdv'])); ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Durée Prévue</div>
                    <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo $essai['duree_minutes']; ?> minutes</div>
                </div>
                
                <?php if ($essai['vendeur_nom']): ?>
                    <div>
                        <div style="font-size: 0.75rem; color: #64748b;">Vendeur Accompagnant</div>
                        <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo htmlspecialchars($essai['vendeur_nom']); ?></div>
                    </div>
                <?php endif; ?>
                
                <div>
                    <div style="font-size: 0.75rem; color: #64748b;">Créé par</div>
                    <div style="font-weight: 600; margin-top: 0.25rem;"><?php echo htmlspecialchars($essai['created_by_nom']); ?></div>
                </div>
            </div>
            
            <?php if ($essai['notes']): ?>
                <div style="padding: 1rem; background: rgba(239, 68, 68, 0.05); border-radius: 10px; margin: 1rem 0;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.25rem;">Notes:</div>
                    <div style="color: #e8edf5;"><?php echo nl2br(htmlspecialchars($essai['notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($canManage): ?>
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(239, 68, 68, 0.1);">
                    <?php if ($essai['statut'] === 'planifie'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="essai_id" value="<?php echo $essai['id']; ?>">
                            <input type="hidden" name="new_statut" value="en_cours">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-play"></i> Démarrer l'Essai
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($essai['statut'] === 'en_cours'): ?>
                        <button onclick="terminerEssai(<?php echo $essai['id']; ?>)" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                            <i class="fas fa-check"></i> Terminer l'Essai
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($essai['statut'] === 'planifie'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="change_statut">
                            <input type="hidden" name="essai_id" value="<?php echo $essai['id']; ?>">
                            <input type="hidden" name="new_statut" value="annule">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color: #ef4444;" onclick="return confirm('Annuler cet essai ?')">
                                <i class="fas fa-times"></i> Annuler
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal Créer Essai -->
<div id="createEssaiModal" class="modal-overlay hidden">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Planifier un Essai</h2>
            <button class="modal-close" onclick="Company.closeModal('createEssaiModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_essai">
                
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
                    
                    <div class="form-group form-full">
                        <label class="form-label">Véhicule *</label>
                        <select name="vehicule_id" class="form-select" required>
                            <option value="">Choisir un véhicule...</option>
                            <?php foreach ($vehicules as $vh): ?>
                                <option value="<?php echo $vh['id']; ?>">
                                    <?php echo htmlspecialchars($vh['marque_nom']); ?> <?php echo htmlspecialchars($vh['modele']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date_rdv" class="form-input" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Heure *</label>
                        <input type="time" name="heure_rdv" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Durée (minutes)</label>
                        <input type="number" name="duree_minutes" class="form-input" value="30" min="15" max="120">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Vendeur Accompagnant</label>
                        <select name="vendeur_accompagnant" class="form-select">
                            <option value="">Non assigné</option>
                            <?php foreach ($vendeurs as $vendeur): ?>
                                <option value="<?php echo $vendeur['id']; ?>"><?php echo htmlspecialchars($vendeur['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group form-full">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('createEssaiModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Planifier l'Essai
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Terminer Essai -->
<div id="terminerEssaiModal" class="modal-overlay hidden">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-check"></i> Terminer l'Essai</h2>
            <button class="modal-close" onclick="Company.closeModal('terminerEssaiModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="terminer_essai">
                <input type="hidden" name="essai_id" id="terminer_essai_id">
                
                <div class="form-group">
                    <label class="form-label">Résultat de l'Essai *</label>
                    <select name="resultat" class="form-select" required>
                        <option value="">Choisir...</option>
                        <option value="interesse">Intéressé 👍</option>
                        <option value="pas_interesse">Pas Intéressé 👎</option>
                        <option value="a_reflechi">À Réfléchi 🤔</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes / Feedback</label>
                    <textarea name="notes" class="form-textarea" rows="4" placeholder="Impressions du client, remarques..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('terminerEssaiModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Terminer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function terminerEssai(id) {
    document.getElementById('terminer_essai_id').value = id;
    Company.openModal('terminerEssaiModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>