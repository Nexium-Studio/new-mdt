<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName  = 'Triads Records';
$companyColor = '#8b5cf6';
$companyFaction = 'TRIADS';

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
$message = '';
$messageType = '';

/* ══════════════════════════════════════════
   ACTION : Recalculer les finances
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalculate') {
    try {
        $db->beginTransaction();

        // CA brut = factures payées
        $stmt = $db->prepare("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='paid'");
        $stmt->execute([$companyFaction]);
        $ca_brut = (float)$stmt->fetchColumn();

        // Charges déductibles
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction=? AND is_tax_deductible=1");
        $stmt->execute([$companyFaction]);
        $charges = (float)$stmt->fetchColumn();

        $benefice_brut = $ca_brut - $charges;

        // Barème impôts standard
        $impot = 0;
        if ($benefice_brut > 500000)      $impot = 65000 + ($benefice_brut - 500000) * 0.20;
        elseif ($benefice_brut > 100000)  $impot = 5000  + ($benefice_brut - 100000) * 0.15;
        elseif ($benefice_brut > 50000)   $impot = ($benefice_brut - 50000) * 0.10;

        $benefice_net = $benefice_brut - $impot;
        $tresorerie   = $benefice_net * 0.30;

        $db->prepare("
            INSERT INTO company_finances (faction, ca_brut, charges, benefice_brut, impot_du, benefice_net, tresorerie)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                ca_brut=VALUES(ca_brut), charges=VALUES(charges),
                benefice_brut=VALUES(benefice_brut), impot_du=VALUES(impot_du),
                benefice_net=VALUES(benefice_net), tresorerie=VALUES(tresorerie)
        ")->execute([$companyFaction, $ca_brut, $charges, $benefice_brut, $impot, $benefice_net, $tresorerie]);

        $db->commit();
        $message = 'Finances recalculées avec succès !';
        $messageType = 'success';
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur : ' . $e->getMessage();
        $messageType = 'error';
    }
}

/* ══════════════════════════════════════════
   DONNÉES
══════════════════════════════════════════ */

// Finances
$stmt = $db->prepare("SELECT * FROM company_finances WHERE faction=?");
$stmt->execute([$companyFaction]);
$fin = $stmt->fetch() ?: ['ca_brut'=>0,'charges'=>0,'benefice_brut'=>0,'impot_du'=>0,'benefice_net'=>0,'tresorerie'=>0];

// Employés & shifts
$stmt = $db->prepare("SELECT COUNT(*) FROM company_employees WHERE faction=?");
$stmt->execute([$companyFaction]);
$totalEmp = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM company_shifts WHERE faction=? AND shift_end IS NULL");
$stmt->execute([$companyFaction]);
$activeShifts = (int)$stmt->fetchColumn();

// Artistes
$stmt = $db->prepare("SELECT COUNT(*) FROM company_artists WHERE faction=? AND status='active'");
$stmt->execute([$companyFaction]);
$activeArtists = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM company_artists WHERE faction=?");
$stmt->execute([$companyFaction]);
$totalArtists = (int)$stmt->fetchColumn();

// Productions
$stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(streams),0), COALESCE(SUM(revenue),0) FROM company_productions WHERE faction=?");
$stmt->execute([$companyFaction]);
[$totalProds, $totalStreams, $prodRevenue] = $stmt->fetch(PDO::FETCH_NUM);

$stmt = $db->prepare("SELECT COUNT(*) FROM company_productions WHERE faction=? AND status='in_progress'");
$stmt->execute([$companyFaction]);
$inProgress = (int)$stmt->fetchColumn();

// Événements à venir
$stmt = $db->prepare("SELECT COUNT(*) FROM company_events WHERE faction=? AND event_date >= CURDATE() AND status NOT IN ('cancelled')");
$stmt->execute([$companyFaction]);
$upcomingEvents = (int)$stmt->fetchColumn();

// Factures en attente
$stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='pending'");
$stmt->execute([$companyFaction]);
[$pendingInv, $pendingAmt] = $stmt->fetch(PDO::FETCH_NUM);

// CA du mois
$stmt = $db->prepare("
    SELECT COALESCE(SUM(final_amount),0)
    FROM company_invoices
    WHERE faction=? AND status='paid'
    AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())
");
$stmt->execute([$companyFaction]);
$monthCA = (float)$stmt->fetchColumn();

// Studios — réservations aujourd'hui
$stmt = $db->prepare("
    SELECT COUNT(*) FROM company_studio_bookings
    WHERE faction=? AND DATE(start_datetime)=CURDATE() AND status='confirmed'
");
$stmt->execute([$companyFaction]);
$todayBookings = (int)$stmt->fetchColumn();

// Top 5 artistes par revenus
$stmt = $db->prepare("
    SELECT ca.artist_name, ca.genre, ca.status,
           COALESCE(SUM(cp.revenue),0) as rev,
           COALESCE(SUM(cp.streams),0) as str,
           COUNT(cp.id) as prods
    FROM company_artists ca
    LEFT JOIN company_productions cp ON cp.artist_id = ca.id
    WHERE ca.faction=?
    GROUP BY ca.id
    ORDER BY rev DESC
    LIMIT 5
");
$stmt->execute([$companyFaction]);
$topArtists = $stmt->fetchAll();

// Dernières productions
$stmt = $db->prepare("
    SELECT cp.title, cp.type, cp.status, cp.revenue, cp.streams, cp.release_date,
           ca.artist_name
    FROM company_productions cp
    LEFT JOIN company_artists ca ON cp.artist_id = ca.id
    WHERE cp.faction=?
    ORDER BY cp.created_at DESC
    LIMIT 8
");
$stmt->execute([$companyFaction]);
$recentProds = $stmt->fetchAll();

// Prochains événements
$stmt = $db->prepare("
    SELECT title, type, location, event_date, tickets_sold, capacity, status
    FROM company_events
    WHERE faction=? AND event_date >= CURDATE() AND status != 'cancelled'
    ORDER BY event_date ASC
    LIMIT 5
");
$stmt->execute([$companyFaction]);
$nextEvents = $stmt->fetchAll();

// Contrats expirant dans 30j
$stmt = $db->prepare("
    SELECT COUNT(*) FROM company_contracts
    WHERE faction=? AND status='active' AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$companyFaction]);
$expiringContracts = (int)$stmt->fetchColumn();

// Distributions
$poolPrimes     = $fin['benefice_net'] * 0.30;
$poolDividendes = $fin['benefice_brut'] * 0.40;
$tresorerie     = $fin['tresorerie'];

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Dashboard styles ── */
.finance-block {
    background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(139,92,246,.04));
    border: 1px solid rgba(139,92,246,.25);
    border-radius: var(--radius);
    padding: 1.35rem;
}
.fin-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: .6rem 0; border-bottom: 1px solid var(--border);
    font-size: .84rem;
}
.fin-row:last-child { border-bottom: none; }
.fin-row-label { display: flex; align-items: center; gap: .55rem; color: var(--txt-2); }
.fin-row-label i { width: 16px; text-align: center; color: var(--txt-3); }
.fin-highlight {
    background: rgba(139,92,246,.08);
    margin: .5rem -1.35rem; padding: .9rem 1.35rem;
    border-top: 1px solid rgba(139,92,246,.2);
    border-bottom: 1px solid rgba(139,92,246,.2);
}
.fin-highlight .fin-row-label { color: var(--txt); font-size: .92rem; font-weight: 700; }

.artist-row {
    display: flex; align-items: center; gap: .9rem;
    padding: .65rem 0; border-bottom: 1px solid var(--border);
}
.artist-row:last-child { border-bottom: none; }
.artist-rank {
    width: 24px; height: 24px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 800; font-family: var(--font-d);
    flex-shrink: 0;
}
.rank-1 { background: rgba(245,158,11,.2); color: var(--yellow); }
.rank-2 { background: rgba(148,163,184,.15); color: #94a3b8; }
.rank-3 { background: rgba(180,112,71,.15); color: #cd7f32; }
.rank-n { background: rgba(255,255,255,.05); color: var(--txt-3); }

.prod-row {
    display: flex; align-items: center; gap: .85rem;
    padding: .6rem 0; border-bottom: 1px solid var(--border);
    font-size: .83rem;
}
.prod-row:last-child { border-bottom: none; }
.prod-icon {
    width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
    background: rgba(255,255,255,.04); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
}

.event-row {
    display: flex; align-items: center; gap: .9rem;
    padding: .65rem 0; border-bottom: 1px solid var(--border);
    font-size: .83rem;
}
.event-row:last-child { border-bottom: none; }
.event-date-pill {
    background: linear-gradient(135deg, var(--c), var(--c-dk));
    border-radius: 9px; padding: .35rem .6rem;
    text-align: center; min-width: 46px; flex-shrink: 0;
}
.event-date-pill .day { font-family: var(--font-d); font-size: 1.15rem; font-weight: 800; line-height: 1; color: #fff; }
.event-date-pill .mon { font-size: .58rem; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,.7); }

.warning-banner {
    background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.22);
    border-radius: var(--radius-sm); padding: .7rem 1rem;
    font-size: .8rem; color: var(--yellow);
    display: flex; align-items: center; gap: .55rem; margin-bottom: .75rem;
}

.dist-pill {
    background: rgba(255,255,255,.03); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: .8rem 1rem;
    display: flex; align-items: center; justify-content: space-between;
    font-size: .83rem; margin-top: .6rem;
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>">
    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($expiringContracts > 0): ?>
<div class="warning-banner">
    <i class="fas fa-exclamation-triangle"></i>
    <strong><?php echo $expiringContracts; ?> contrat(s)</strong> expire(nt) dans les 30 prochains jours.
    <a href="contrats.php?status=active" style="margin-left:auto; color:var(--yellow); text-decoration:underline; font-weight:600;">Voir →</a>
</div>
<?php endif; ?>

<!-- ── KPI ROW ── -->
<div class="kpi-grid mb-3">
    <div class="kpi">
        <div class="kpi-label">CA Brut Total</div>
        <div class="kpi-value green" style="font-size:1.5rem;">$<?php echo number_format($fin['ca_brut'],0,'.',' '); ?></div>
        <div class="kpi-sub">Ce mois : <span class="up">$<?php echo number_format($monthCA,0,'.',' '); ?></span></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Bénéfice Net</div>
        <div class="kpi-value purple" style="font-size:1.5rem;">$<?php echo number_format($fin['benefice_net'],0,'.',' '); ?></div>
        <div class="kpi-sub">Après impôts</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Artistes signés</div>
        <div class="kpi-value"><?php echo $activeArtists; ?></div>
        <div class="kpi-sub"><?php echo $totalArtists; ?> au total · <?php echo $inProgress; ?> prod. en cours</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Streams Totaux</div>
        <div class="kpi-value blue"><?php echo number_format($totalStreams / 1000, 1); ?>K</div>
        <div class="kpi-sub">Revenus streaming : <span class="up">$<?php echo number_format($prodRevenue,0,'.',' '); ?></span></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Employés</div>
        <div class="kpi-value"><?php echo $totalEmp; ?></div>
        <div class="kpi-sub"><span class="up"><?php echo $activeShifts; ?></span> en service maintenant</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Événements à venir</div>
        <div class="kpi-value yellow"><?php echo $upcomingEvents; ?></div>
        <div class="kpi-sub">Réservations studio aujourd'hui : <?php echo $todayBookings; ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Factures en attente</div>
        <div class="kpi-value <?php echo $pendingInv > 0 ? 'red' : ''; ?>"><?php echo $pendingInv; ?></div>
        <div class="kpi-sub">Montant : <span class="<?php echo $pendingInv > 0 ? 'down' : ''; ?>">$<?php echo number_format($pendingAmt,0,'.',' '); ?></span></div>
    </div>
    <div class="kpi" style="display:flex; align-items:center; justify-content:center;">
        <form method="POST" data-confirm="Recalculer les finances ?" style="width:100%;">
            <input type="hidden" name="action" value="recalculate">
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                <i class="fas fa-calculator"></i> Recalculer Finances
            </button>
        </form>
    </div>
</div>

<!-- ── ROW 2 : Finances détail + Top Artistes ── -->
<div class="grid-2 mb-3">

    <!-- Finances détaillées -->
    <div class="finance-block">
        <div class="card-head" style="margin-bottom:.9rem;">
            <div class="card-title"><i class="fas fa-chart-pie"></i> Finances Détaillées</div>
        </div>

        <div class="fin-row">
            <div class="fin-row-label"><i class="fas fa-cash-register"></i> Chiffre d'Affaires Brut</div>
            <span style="font-weight:700;">$<?php echo number_format($fin['ca_brut'],2,'.',' '); ?></span>
        </div>
        <div class="fin-row">
            <div class="fin-row-label"><i class="fas fa-minus-circle"></i> Charges d'exploitation</div>
            <span class="txt-neg">−$<?php echo number_format($fin['charges'],2,'.',' '); ?></span>
        </div>
        <div class="fin-row">
            <div class="fin-row-label"><i class="fas fa-equals"></i> Bénéfice Brut</div>
            <span class="txt-money">$<?php echo number_format($fin['benefice_brut'],2,'.',' '); ?></span>
        </div>
        <div class="fin-row">
            <div class="fin-row-label"><i class="fas fa-landmark"></i> Impôts dus</div>
            <span class="txt-neg">−$<?php echo number_format($fin['impot_du'],2,'.',' '); ?></span>
        </div>

        <div class="fin-highlight">
            <div class="fin-row" style="border:none; padding:.2rem 0;">
                <div class="fin-row-label"><i class="fas fa-trophy" style="color:var(--c);"></i> Bénéfice Net</div>
                <span style="font-size:1.35rem; font-weight:800; font-family:var(--font-d); color:var(--c-lt);">
                    $<?php echo number_format($fin['benefice_net'],2,'.',' '); ?>
                </span>
            </div>
        </div>

        <div style="margin-top:.9rem; padding-top:.9rem; border-top:1px solid var(--border);">
            <div class="fs-xs txt-3 fw-700" style="text-transform:uppercase; letter-spacing:1.2px; margin-bottom:.5rem;">Distribution</div>
            <div class="dist-pill">
                <div class="f-center gap-2"><i class="fas fa-gift txt-3"></i><span>Pool Primes (30% net)</span></div>
                <span class="txt-money">$<?php echo number_format($poolPrimes,0,'.',' '); ?></span>
            </div>
            <div class="dist-pill">
                <div class="f-center gap-2"><i class="fas fa-crown txt-3"></i><span>Dividendes (40% brut)</span></div>
                <span class="txt-money">$<?php echo number_format($poolDividendes,0,'.',' '); ?></span>
            </div>
            <div class="dist-pill">
                <div class="f-center gap-2"><i class="fas fa-piggy-bank txt-3"></i><span>Trésorerie (30% net)</span></div>
                <span style="color:var(--blue); font-weight:700;">$<?php echo number_format($tresorerie,0,'.',' '); ?></span>
            </div>
        </div>
    </div>

    <!-- Top Artistes -->
    <div class="card">
        <div class="card-head">
            <div class="card-title"><i class="fas fa-trophy"></i> Top Artistes</div>
            <a href="artistes.php" class="btn btn-ghost btn-sm">Voir tous <i class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($topArtists)): ?>
            <div style="text-align:center; color:var(--txt-3); padding:2.5rem 0; font-size:.84rem;">
                <i class="fas fa-microphone" style="font-size:1.8rem; margin-bottom:.8rem; display:block;"></i>
                Aucun artiste enregistré
            </div>
        <?php else: ?>
            <?php
            $rankCss = ['rank-1','rank-2','rank-3','rank-n','rank-n'];
            $statusBadge = [
                'active'      => 'badge-green',
                'prospect'    => 'badge-blue',
                'negotiation' => 'badge-yellow',
                'inactive'    => 'badge-gray',
            ];
            foreach ($topArtists as $i => $a):
                $rc  = $rankCss[$i] ?? 'rank-n';
                $sbc = $statusBadge[$a['status']] ?? 'badge-gray';
            ?>
            <div class="artist-row">
                <div class="artist-rank <?php echo $rc; ?>"><?php echo $i + 1; ?></div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:600; font-size:.86rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($a['artist_name']); ?>
                    </div>
                    <div class="fs-xs txt-3" style="margin-top:.12rem;">
                        <?php echo htmlspecialchars($a['genre'] ?? '—'); ?> · <?php echo $a['prods']; ?> prod.
                    </div>
                </div>
                <div style="text-align:right; flex-shrink:0;">
                    <div class="txt-money" style="font-size:.86rem;">$<?php echo number_format($a['rev'],0,'.',' '); ?></div>
                    <div class="fs-xs txt-3"><?php echo number_format($a['str']/1000,1); ?>K streams</div>
                </div>
                <span class="badge <?php echo $sbc; ?> badge-dot" style="flex-shrink:0;"></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── ROW 3 : Productions récentes + Prochains événements ── -->
<div class="grid-2">

    <!-- Productions récentes -->
    <div class="card">
        <div class="card-head">
            <div class="card-title"><i class="fas fa-compact-disc"></i> Productions Récentes</div>
            <a href="productions.php" class="btn btn-ghost btn-sm">Tout voir <i class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($recentProds)): ?>
            <div style="text-align:center; color:var(--txt-3); padding:2rem 0; font-size:.83rem;">
                <i class="fas fa-compact-disc" style="font-size:1.8rem; margin-bottom:.7rem; display:block;"></i>
                Aucune production
            </div>
        <?php else: ?>
            <?php
            $typeIcons = ['single'=>'🎵','ep'=>'💿','album'=>'📀','mixtape'=>'🎚','feature'=>'🎤','instrumental'=>'🎹'];
            $statusBadge = [
                'in_progress'    => ['label'=>'En cours',   'css'=>'badge-yellow'],
                'mixing'         => ['label'=>'Mixage',     'css'=>'badge-blue'],
                'mastering'      => ['label'=>'Mastering',  'css'=>'badge-purple'],
                'pending_release'=> ['label'=>'Sortie imm.','css'=>'badge-pink'],
                'released'       => ['label'=>'Sorti',      'css'=>'badge-green'],
                'cancelled'      => ['label'=>'Annulé',     'css'=>'badge-gray'],
            ];
            foreach ($recentProds as $p):
                $ic = $typeIcons[$p['type']] ?? '🎵';
                $sb = $statusBadge[$p['status']] ?? ['label'=>$p['status'],'css'=>'badge-gray'];
            ?>
            <div class="prod-row">
                <div class="prod-icon"><?php echo $ic; ?></div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($p['title']); ?>
                    </div>
                    <div class="fs-xs txt-3"><?php echo htmlspecialchars($p['artist_name'] ?? '—'); ?></div>
                </div>
                <div style="text-align:right; flex-shrink:0; margin-right:.5rem;">
                    <div class="txt-money" style="font-size:.8rem;">$<?php echo number_format($p['revenue'],0,'.',' '); ?></div>
                    <div class="fs-xs txt-3"><?php echo number_format($p['streams']/1000,1); ?>K</div>
                </div>
                <span class="badge <?php echo $sb['css']; ?>"><?php echo $sb['label']; ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Prochains événements -->
    <div class="card">
        <div class="card-head">
            <div class="card-title"><i class="fas fa-calendar-star"></i> Prochains Événements</div>
            <a href="evenements.php" class="btn btn-ghost btn-sm">Tout voir <i class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($nextEvents)): ?>
            <div style="text-align:center; color:var(--txt-3); padding:2rem 0; font-size:.83rem;">
                <i class="fas fa-calendar" style="font-size:1.8rem; margin-bottom:.7rem; display:block;"></i>
                Aucun événement planifié
            </div>
        <?php else: ?>
            <?php
            $evTypeName = ['concert'=>'Concert','festival'=>'Festival','showcase'=>'Showcase',
                           'listening_party'=>'Listening Party','release_party'=>'Release Party',
                           'signing'=>'Dédicaces','other'=>'Autre'];
            $evStatusBadge = [
                'planned'   => 'badge-blue',  'confirmed' => 'badge-green',
                'in_progress'=> 'badge-yellow','completed' => 'badge-gray',
                'cancelled' => 'badge-red',
            ];
            foreach ($nextEvents as $ev):
                $fillPct = $ev['capacity'] > 0 ? min(100, round($ev['tickets_sold']/$ev['capacity']*100)) : 0;
                $esb = $evStatusBadge[$ev['status']] ?? 'badge-gray';
            ?>
            <div class="event-row">
                <div class="event-date-pill">
                    <div class="day"><?php echo date('d', strtotime($ev['event_date'])); ?></div>
                    <div class="mon"><?php echo date('M', strtotime($ev['event_date'])); ?></div>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($ev['title']); ?>
                    </div>
                    <div class="fs-xs txt-3" style="margin-top:.1rem;">
                        <?php echo $evTypeName[$ev['type']] ?? $ev['type']; ?> · <?php echo htmlspecialchars($ev['location']); ?>
                    </div>
                    <?php if ($ev['capacity'] > 0): ?>
                    <div class="progress" style="margin-top:.4rem;">
                        <div class="progress-fill <?php echo $fillPct >= 80 ? 'green' : ($fillPct >= 50 ? '' : 'yellow'); ?>"
                             style="width:<?php echo $fillPct; ?>%;"></div>
                    </div>
                    <div class="fs-xs txt-3" style="margin-top:.2rem;"><?php echo $ev['tickets_sold']; ?>/<?php echo $ev['capacity']; ?> tickets (<?php echo $fillPct; ?>%)</div>
                    <?php endif; ?>
                </div>
                <span class="badge <?php echo $esb; ?> badge-dot" style="flex-shrink:0;"></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div><!-- /.grid-2 -->

<?php include __DIR__ . '/includes/footer.php'; ?>