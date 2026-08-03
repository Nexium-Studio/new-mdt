<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SESSION['user_faction'] !== 'DOJ' && !hasRole(['*', 'Administrateur', 'Développeur', 'Staff', 'Modérateur', 'Helper', 'Pôle 911', 'Pôle Illégale', 'Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Stats
$stmt = $db->query("SELECT COUNT(*) as count FROM criminal_records WHERE status = 'active'");
$activeRecords = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM arrest_warrants WHERE status = 'active'");
$activeWarrants = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM court_cases WHERE status != 'concluded'");
$activeCases = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM sentences WHERE status IN ('pending', 'active')");
$activeSentences = $stmt->fetch()['count'];

// Derniers casiers
$stmt = $db->query("
    SELECT cr.*, p.firstname, p.lastname, u.username as officer_name
    FROM criminal_records cr
    JOIN profiles p ON cr.citizen_id = p.id
    LEFT JOIN users u ON cr.officer_id = u.id
    ORDER BY cr.created_at DESC
    LIMIT 5
");
$recentRecords = $stmt->fetchAll();

// Mandats actifs
$stmt = $db->query("
    SELECT aw.*, p.firstname, p.lastname
    FROM arrest_warrants aw
    JOIN profiles p ON aw.citizen_id = p.id
    WHERE aw.status = 'active'
    ORDER BY aw.issued_date DESC
    LIMIT 5
");
$activeWarrantsList = $stmt->fetchAll();

// Rapports LSPD en attente de traitement DOJ (déclenché par un tag « Alerte DOJ »).
// Calculé dynamiquement : disparaît dès que tous les rapports concernés sont traités.
$dojPending = 0;
try {
    $dojPending = (int)$db->query("SELECT COUNT(*) FROM reports WHERE faction='LSPD' AND doj_alert=1 AND doj_treated=0")->fetchColumn();
} catch (Throwable $e) { $dojPending = 0; }

$pageTitle = 'Dashboard DOJ';
include __DIR__ . '/includes/header.php';
?>

<?php if ($dojPending > 0): ?>
<a href="<?php echo SITE_URL; ?>/pages/doj/rapports.php?pending=1" style="text-decoration:none;display:block">
  <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;margin-bottom:1.5rem;background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.3);border-radius:12px;transition:background .2s" onmouseover="this.style.background='rgba(139,92,246,.17)'" onmouseout="this.style.background='rgba(139,92,246,.1)'">
    <div style="width:44px;height:44px;border-radius:11px;background:rgba(139,92,246,.18);display:flex;align-items:center;justify-content:center;color:#a78bfa;font-size:1.1rem;flex-shrink:0"><i class="fas fa-scale-balanced"></i></div>
    <div style="flex:1">
      <div style="font-weight:700;color:#f1f5f9;font-size:.95rem">Vous avez <?php echo $dojPending; ?> rapport<?php echo $dojPending>1?'s':''; ?> LSPD en attente de traitement.</div>
      <div style="font-size:.78rem;color:#94a3b8;margin-top:.15rem">Cliquez pour consulter les rapports signalés au DOJ.</div>
    </div>
    <i class="fas fa-chevron-right" style="color:#a78bfa"></i>
  </div>
</a>
<?php endif; ?>

<div class="doj-stats">
    <div class="doj-stat">
        <div class="stat-icon">
            <i class="fas fa-file-alt"></i>
        </div>
        <div class="stat-value"><?php echo $activeRecords; ?></div>
        <div class="stat-label">Casiers Actifs</div>
    </div>
    
    <div class="doj-stat">
        <div class="stat-icon">
            <i class="fas fa-gavel"></i>
        </div>
        <div class="stat-value"><?php echo $activeWarrants; ?></div>
        <div class="stat-label">Mandats Actifs</div>
    </div>
    
    <div class="doj-stat">
        <div class="stat-icon">
            <i class="fas fa-landmark"></i>
        </div>
        <div class="stat-value"><?php echo $activeCases; ?></div>
        <div class="stat-label">Procès en cours</div>
    </div>
    
    <div class="doj-stat">
        <div class="stat-icon">
            <i class="fas fa-handcuffs"></i>
        </div>
        <div class="stat-value"><?php echo $activeSentences; ?></div>
        <div class="stat-label">Sentences Actives</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <!-- Derniers casiers -->
    <div class="doj-card">
        <div class="doj-card-header">
            <h3 class="doj-card-title">
                <i class="fas fa-file-alt"></i> Derniers Casiers
            </h3>
            <a href="casier.php" class="btn-doj btn-sm">Voir tout</a>
        </div>
        
        <?php if (empty($recentRecords)): ?>
            <p style="color: #94a3b8; text-align: center; padding: 2rem;">Aucun casier récent</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Citoyen</th>
                        <th>Délit</th>
                        <th>Gravité</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecords as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['firstname'] . ' ' . $record['lastname']); ?></td>
                            <td><?php echo htmlspecialchars($record['offense_code']); ?></td>
                            <td>
                                <span class="severity-badge severity-<?php echo $record['severity']; ?>">
                                    Niveau <?php echo $record['severity']; ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($record['incident_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Mandats actifs -->
    <div class="doj-card">
        <div class="doj-card-header">
            <h3 class="doj-card-title">
                <i class="fas fa-gavel"></i> Mandats Actifs
            </h3>
            <a href="mandats.php" class="btn-doj btn-sm">Voir tout</a>
        </div>
        
        <?php if (empty($activeWarrantsList)): ?>
            <p style="color: #94a3b8; text-align: center; padding: 2rem;">Aucun mandat actif</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Citoyen</th>
                        <th>Type</th>
                        <th>Gravité</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeWarrantsList as $warrant): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($warrant['firstname'] . ' ' . $warrant['lastname']); ?></td>
                            <td>
                                <?php 
                                $types = [
                                    'arrest' => 'Arrêt',
                                    'search' => 'Perquisition',
                                    'seizure' => 'Saisie'
                                ];
                                echo $types[$warrant['warrant_type']] ?? $warrant['warrant_type'];
                                ?>
                            </td>
                            <td>
                                <span class="severity-badge severity-<?php echo $warrant['severity']; ?>">
                                    <?php echo ucfirst($warrant['severity']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($warrant['issued_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="doj-card" style="margin-top: 1.5rem;">
    <div class="doj-card-header">
        <h3 class="doj-card-title">
            <i class="fas fa-chart-line"></i> Actions Rapides
        </h3>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1rem;">
        <a href="casier.php" class="btn-doj btn-block">
            <i class="fas fa-plus"></i> Nouveau Casier
        </a>
        <a href="mandats.php" class="btn-doj btn-block">
            <i class="fas fa-gavel"></i> Émettre Mandat
        </a>
        <a href="proces.php" class="btn-doj btn-block">
            <i class="fas fa-landmark"></i> Créer Procès
        </a>
        <a href="code-penal.php" class="btn-doj btn-block">
            <i class="fas fa-book-open"></i> Code Pénal
        </a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>