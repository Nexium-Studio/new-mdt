<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = "🏨 Pearl's Resort";
$companyColor   = '#d4a853';
$companyFaction = 'PEARLS_RESORT';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Dashboard';
$db = Database::getInstance()->getConnection();

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();
$canStats = faction_acl_can($db, $uid, $companyFaction, 'dashboard.stats');
// ── Stats chambres ──
$totalRooms = $db->prepare("SELECT COUNT(*) FROM pearls_rooms");
$totalRooms->execute(); $totalRooms = (int)$totalRooms->fetchColumn();

$roomsStats = [];
foreach (['libre','occupée','maintenance','nettoyage'] as $s) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM pearls_rooms WHERE status = ?");
    $stmt->execute([$s]); $roomsStats[$s] = (int)$stmt->fetchColumn();
}

$occupancyRate = $totalRooms > 0 ? round(($roomsStats['occupée'] / $totalRooms) * 100) : 0;

// ── Réservations en cours ──
$activeRes = $db->prepare("SELECT COUNT(*) FROM pearls_reservations WHERE status = 'en cours'");
$activeRes->execute(); $activeRes = (int)$activeRes->fetchColumn();

// ── Chiffre d'affaires du mois ──
$caMonth = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0) FROM pearls_reservations
    WHERE status IN ('terminée','en cours') AND MONTH(check_in) = MONTH(NOW()) AND YEAR(check_in) = YEAR(NOW())
");
$caMonth->execute(); $caMonth = (float)$caMonth->fetchColumn();

// ── Réservations à venir (7 jours) ──
$upcoming = $db->prepare("
    SELECT r.*, rm.room_number, rm.room_type
    FROM pearls_reservations r
    JOIN pearls_rooms rm ON r.room_id = rm.id
    WHERE r.status = 'confirmée' AND r.check_in BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY r.check_in ASC LIMIT 5
"); $upcoming->execute(); $upcoming = $upcoming->fetchAll();

// ── Départs aujourd'hui ──
$departures = $db->prepare("
    SELECT r.*, rm.room_number, rm.room_type
    FROM pearls_reservations r
    JOIN pearls_rooms rm ON r.room_id = rm.id
    WHERE r.status = 'en cours' AND r.check_out = CURDATE()
    ORDER BY r.room_id ASC LIMIT 5
"); $departures->execute(); $departures = $departures->fetchAll();

// ── Effectifs ──
$staffCount = $db->prepare("SELECT COUNT(*) FROM company_employees WHERE faction = ?");
$staffCount->execute([$companyFaction]); $staffCount = (int)$staffCount->fetchColumn();

// ── Dernières réservations ──
$recentRes = $db->prepare("
    SELECT r.*, rm.room_number, rm.room_type
    FROM pearls_reservations r
    JOIN pearls_rooms rm ON r.room_id = rm.id
    ORDER BY r.created_at DESC LIMIT 6
"); $recentRes->execute(); $recentRes = $recentRes->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- STATS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-bed"></i></div>
        <div>
            <div class="stat-label">Total Chambres</div>
            <div class="stat-value" data-count="<?php echo $totalRooms; ?>">0</div>
        </div>
    </div>
    <div class="stat-card" style="border-color:rgba(16,185,129,.25);">
        <div class="stat-icon" style="color:#10b981;background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.2);">
            <i class="fas fa-door-open"></i>
        </div>
        <div>
            <div class="stat-label">Disponibles</div>
            <div class="stat-value" style="color:#10b981;" data-count="<?php echo $roomsStats['libre']; ?>">0</div>
        </div>
    </div>
    <div class="stat-card" style="border-color:rgba(239,68,68,.2);">
        <div class="stat-icon" style="color:#ef4444;background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);">
            <i class="fas fa-door-closed"></i>
        </div>
        <div>
            <div class="stat-label">Occupées</div>
            <div class="stat-value" style="color:#ef4444;" data-count="<?php echo $roomsStats['occupée']; ?>">0</div>
            <div class="stat-meta">Taux: <?php echo $occupancyRate; ?>%</div>
        </div>
    </div>
    <div class="stat-card" style="border-color:rgba(212,168,83,.25);">
        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
        <div>
            <div class="stat-label">Réservations en cours</div>
            <div class="stat-value" data-count="<?php echo $activeRes; ?>">0</div>
        </div>
    </div>
    <div class="stat-card" style="border-color:rgba(212,168,83,.25);">
        <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
        <div>
            <div class="stat-label">CA ce mois</div>
            <div class="stat-value" style="font-size:1.15rem;">$<?php echo number_format($caMonth, 0, ',', ' '); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:#8b5cf6;background:rgba(139,92,246,.1);border-color:rgba(139,92,246,.2);">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="stat-label">Effectifs</div>
            <div class="stat-value" style="color:#8b5cf6;" data-count="<?php echo $staffCount; ?>">0</div>
        </div>
    </div>
</div>

<!-- TAUX D'OCCUPATION VISUEL -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-pie"></i> État des chambres</div>
        <a href="chambres.php" class="btn btn-secondary btn-sm"><i class="fas fa-bed"></i> Gérer les chambres</a>
    </div>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
        <?php
        $roomStatuses = [
            'libre'       => ['label'=>'Libres',       'color'=>'#10b981', 'bg'=>'rgba(16,185,129,.12)'],
            'occupée'     => ['label'=>'Occupées',     'color'=>'#ef4444', 'bg'=>'rgba(239,68,68,.12)'],
            'nettoyage'   => ['label'=>'Nettoyage',    'color'=>'#eab308', 'bg'=>'rgba(234,179,8,.12)'],
            'maintenance' => ['label'=>'Maintenance',  'color'=>'#94a3b8', 'bg'=>'rgba(100,116,139,.12)'],
        ];
        foreach ($roomStatuses as $key => $meta):
            $count = $roomsStats[$key] ?? 0;
            $pct   = $totalRooms > 0 ? round(($count / $totalRooms) * 100) : 0;
        ?>
        <div style="flex:1;min-width:120px;background:<?php echo $meta['bg']; ?>;border:1px solid <?php echo $meta['color']; ?>33;border-radius:12px;padding:1rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:<?php echo $meta['color']; ?>;font-family:'DM Mono',monospace;"><?php echo $count; ?></div>
            <div style="font-size:.72rem;color:var(--text-2);margin-top:.2rem;"><?php echo $meta['label']; ?></div>
            <div style="font-size:.7rem;color:<?php echo $meta['color']; ?>;font-weight:700;"><?php echo $pct; ?>%</div>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Barre de progression -->
    <div style="height:8px;background:rgba(255,255,255,.05);border-radius:4px;overflow:hidden;display:flex;gap:2px;">
        <?php foreach ($roomStatuses as $key => $meta):
            $pct = $totalRooms > 0 ? round(($roomsStats[$key] / $totalRooms) * 100) : 0;
            if ($pct <= 0) continue;
        ?>
        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $meta['color']; ?>;border-radius:4px;"></div>
        <?php endforeach; ?>
    </div>
</div>

<!-- GRILLE 2 COLONNES -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

    <!-- Arrivées prévues -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-plane-arrival"></i> Arrivées (7j)</div>
            <span class="badge badge-green"><?php echo count($upcoming); ?></span>
        </div>
        <?php if (empty($upcoming)): ?>
        <div class="empty-state" style="padding:1.5rem;"><i class="fas fa-calendar-xmark"></i><span>Aucune arrivée prévue</span></div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($upcoming as $r): ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.7rem;background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);border-radius:10px;">
                <div style="width:38px;height:38px;background:rgba(16,185,129,.12);border-radius:9px;display:grid;place-items:center;color:#10b981;font-size:.85rem;flex-shrink:0;">
                    <i class="fas fa-bed"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.875rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($r['client_name']); ?></div>
                    <div style="font-size:.72rem;color:var(--text-2);">Chambre <?php echo htmlspecialchars($r['room_number']); ?> · <?php echo htmlspecialchars($r['room_type']); ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:.78rem;color:#10b981;font-weight:700;"><?php echo date('d/m', strtotime($r['check_in'])); ?></div>
                    <div style="font-size:.68rem;color:var(--text-2);"><?php echo $r['nights']; ?> nuit(s)</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Départs aujourd'hui -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-plane-departure"></i> Départs aujourd'hui</div>
            <span class="badge badge-orange"><?php echo count($departures); ?></span>
        </div>
        <?php if (empty($departures)): ?>
        <div class="empty-state" style="padding:1.5rem;"><i class="fas fa-calendar-xmark"></i><span>Aucun départ aujourd'hui</span></div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($departures as $r): ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.7rem;background:rgba(249,115,22,.05);border:1px solid rgba(249,115,22,.15);border-radius:10px;">
                <div style="width:38px;height:38px;background:rgba(249,115,22,.12);border-radius:9px;display:grid;place-items:center;color:#f97316;font-size:.85rem;flex-shrink:0;">
                    <i class="fas fa-door-open"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.875rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($r['client_name']); ?></div>
                    <div style="font-size:.72rem;color:var(--text-2);">Chambre <?php echo htmlspecialchars($r['room_number']); ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <span class="badge badge-orange">Départ</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dernières réservations -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list"></i> Dernières réservations</div>
        <a href="reservations.php" class="btn btn-secondary btn-sm">Voir tout</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Client</th><th>Chambre</th><th>Check-in</th><th>Check-out</th><th>Total</th><th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentRes)): ?>
                <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-2);">Aucune réservation</td></tr>
                <?php else: ?>
                <?php foreach ($recentRes as $r):
                    $statusMeta = [
                        'confirmée' => ['badge-blue',   'Confirmée'],
                        'en cours'  => ['badge-green',  'En cours'],
                        'terminée'  => ['badge-slate',  'Terminée'],
                        'annulée'   => ['badge-red',    'Annulée'],
                        'no-show'   => ['badge-orange', 'No-show'],
                    ][$r['status']] ?? ['badge-slate', $r['status']];
                ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($r['client_name']); ?></td>
                    <td>
                        <span class="badge badge-gold">N°<?php echo htmlspecialchars($r['room_number']); ?></span>
                        <span style="font-size:.75rem;color:var(--text-2);margin-left:.4rem;"><?php echo htmlspecialchars($r['room_type']); ?></span>
                    </td>
                    <td style="font-size:.8rem;font-family:'DM Mono',monospace;"><?php echo date('d/m/Y', strtotime($r['check_in'])); ?></td>
                    <td style="font-size:.8rem;font-family:'DM Mono',monospace;"><?php echo date('d/m/Y', strtotime($r['check_out'])); ?></td>
                    <td style="font-weight:700;color:var(--gold);">$<?php echo number_format($r['total_amount'], 0, ',', ' '); ?></td>
                    <td><span class="badge <?php echo $statusMeta[0]; ?>"><?php echo $statusMeta[1]; ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>