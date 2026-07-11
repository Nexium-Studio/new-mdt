<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

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
$msg = ''; $msgType = '';

// ── RECALCULER FINANCES ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='recalculate') {
    try {
        $db->beginTransaction();

        $ca = $db->prepare("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='paid'");
        $ca->execute([$companyFaction]); $ca_brut = $ca->fetchColumn();

        $ch = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction=? AND is_tax_deductible=1");
        $ch->execute([$companyFaction]); $charges = $ch->fetchColumn();

        $b_brut = $ca_brut - $charges;

        // Barème Ammunation
        $impot = 0;
        if ($b_brut > 100000)       $impot = min($b_brut-100000, 900000)*0.20;
        if ($b_brut > 1000000)      $impot += ($b_brut-1000000)*0.20;

        $b_net     = $b_brut - $impot;
        $tresorerie = $b_net * 0.30;

        $db->prepare("
            INSERT INTO company_finances (faction,ca_brut,charges,benefice_brut,impot_du,benefice_net,tresorerie)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE ca_brut=VALUES(ca_brut),charges=VALUES(charges),
            benefice_brut=VALUES(benefice_brut),impot_du=VALUES(impot_du),
            benefice_net=VALUES(benefice_net),tresorerie=VALUES(tresorerie)
        ")->execute([$companyFaction,$ca_brut,$charges,$b_brut,$impot,$b_net,$tresorerie]);

        $db->commit();
        $msg='Finances recalculées avec succès.'; $msgType='success';
    } catch(Exception $e) {
        if($db->inTransaction()) $db->rollBack();
        $msg='Erreur : '.$e->getMessage(); $msgType='error';
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$stmt=$db->prepare("SELECT * FROM company_finances WHERE faction=?");
$stmt->execute([$companyFaction]);
$fin=$stmt->fetch()?:['ca_brut'=>0,'charges'=>0,'benefice_brut'=>0,'impot_du'=>0,'benefice_net'=>0,'tresorerie'=>0];

$stmt=$db->prepare("SELECT COUNT(*) FROM company_employees WHERE faction=?");
$stmt->execute([$companyFaction]); $totalEmp=$stmt->fetchColumn();

$stmt=$db->prepare("SELECT COUNT(*) FROM company_shifts WHERE faction=? AND DATE(shift_start)=CURDATE() AND shift_end IS NULL");
$stmt->execute([$companyFaction]); $activeShifts=$stmt->fetchColumn();

$stmt=$db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(final_amount),0) as sum FROM company_invoices WHERE faction=? AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$stmt->execute([$companyFaction]); $monthStats=$stmt->fetch();

// Stock stats
$stmt=$db->prepare("SELECT COUNT(*) as refs, COALESCE(SUM(stock_quantity),0) as units, SUM(CASE WHEN stock_quantity<10 AND stock_quantity>0 THEN 1 ELSE 0 END) as low FROM company_stock WHERE faction=?");
$stmt->execute([$companyFaction]); $stockStats=$stmt->fetch();

// Top vendeurs
$stmt=$db->prepare("SELECT u.username, ce.individual_ca, ce.rank FROM company_employees ce JOIN users u ON ce.user_id=u.id WHERE ce.faction=? ORDER BY ce.individual_ca DESC LIMIT 5");
$stmt->execute([$companyFaction]); $topSellers=$stmt->fetchAll();

// Dernières ventes
$stmt=$db->prepare("SELECT ci.*, u.username as emp FROM company_invoices ci JOIN users u ON ci.employee_id=u.id WHERE ci.faction=? ORDER BY ci.created_at DESC LIMIT 8");
$stmt->execute([$companyFaction]); $recent=$stmt->fetchAll();

// Ventes par catégorie (ce mois)
$stmt=$db->prepare("
    SELECT item_category, COUNT(*) as cnt, SUM(stock_quantity) as units
    FROM company_stock WHERE faction=? GROUP BY item_category
");
$stmt->execute([$companyFaction]); $byCat=$stmt->fetchAll();

$primes  = $fin['benefice_net']*0.30;
$divs    = $fin['benefice_brut']*0.40;

include __DIR__ . '/includes/header.php';
?>
<style>
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.1rem;margin-bottom:2rem}
.kpi{background:rgba(13,21,38,.8);border:1px solid rgba(220,38,38,.14);border-radius:14px;padding:1.4rem;position:relative;overflow:hidden}
.kpi::after{content:'';position:absolute;top:-25px;right:-25px;width:90px;height:90px;background:radial-gradient(circle,rgba(220,38,38,.14) 0%,transparent 70%);pointer-events:none}
.kpi-ico{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.9rem;background:rgba(220,38,38,.12);color:var(--cc)}
.kpi-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:.35rem}
.kpi-val{font-size:1.85rem;font-weight:900;color:#f1f5f9;line-height:1;margin-bottom:.25rem}
.kpi-val.g{color:#4ade80}.kpi-val.r{color:#f87171}.kpi-val.b{color:#93c5fd}.kpi-val.y{color:#fcd34d}
.kpi-sub{font-size:.75rem;color:#475569}

.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:1.5rem;margin-bottom:1.5rem}

.fin-card{background:rgba(13,21,38,.9);border:2px solid rgba(220,38,38,.22);border-radius:14px;padding:1.5rem;margin-bottom:1.5rem}
.fin-row{display:flex;justify-content:space-between;align-items:center;padding:.85rem 0;border-bottom:1px solid rgba(255,255,255,.05)}
.fin-row:last-child{border-bottom:none}
.fin-lbl{color:#94a3b8;font-size:.875rem;display:flex;align-items:center;gap:.65rem}
.fin-lbl i{width:18px;color:#475569}
.fin-val{font-weight:800;font-size:1.1rem}
.fin-val.g{color:#4ade80}.fin-val.r{color:#f87171}.fin-val.b{color:#93c5fd}
.fin-highlight{background:rgba(220,38,38,.07);margin:0 -.5rem;padding:.85rem .5rem;border-radius:8px}

.seller-item{display:flex;align-items:center;gap:.9rem;padding:.9rem;background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.1);border-radius:10px;margin-bottom:.6rem;transition:all .2s}
.seller-item:hover{background:rgba(220,38,38,.09);transform:translateX(4px)}
.seller-rank{width:36px;height:36px;background:linear-gradient(135deg,var(--cc),var(--cc-d));border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;flex-shrink:0}
.seller-info{flex:1}.seller-name{font-weight:700;font-size:.875rem;color:#f1f5f9}.seller-role{font-size:.75rem;color:#64748b}
.seller-ca{font-weight:800;color:#4ade80;font-size:.9rem}

.status-paid     {background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.2);}
.status-pending  {background:rgba(245,158,11,.1);color:#fcd34d; border:1px solid rgba(245,158,11,.2);}
.status-cancelled{background:rgba(100,116,139,.1);color:#94a3b8;border:1px solid rgba(100,116,139,.2);}

@media(max-width:1100px){.grid2{grid-template-columns:1fr}}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Top action -->
<div style="display:flex;justify-content:flex-end;margin-bottom:1.5rem;gap:.75rem">
    <a href="vente.php" class="btn btn-primary"><i class="fas fa-cash-register"></i> Point de Vente</a>
    <form method="POST" data-confirm="Recalculer toutes les finances ?">
        <input type="hidden" name="action" value="recalculate">
        <button type="submit" class="btn btn-secondary"><i class="fas fa-calculator"></i> Recalculer les Finances</button>
    </form>
</div>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-dollar-sign"></i></div>
        <div class="kpi-lbl">CA Brut Total</div>
        <div class="kpi-val b">$<?php echo number_format($fin['ca_brut'],0); ?></div>
        <div class="kpi-sub">toutes factures payées</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(74,222,128,.12);color:#4ade80"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-lbl">Bénéfice Net</div>
        <div class="kpi-val g">$<?php echo number_format($fin['benefice_net'],0); ?></div>
        <div class="kpi-sub">après impôts &amp; charges</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-lbl">Ventes ce mois</div>
        <div class="kpi-val"><?php echo $monthStats['total']; ?></div>
        <div class="kpi-sub">$<?php echo number_format($monthStats['sum'],0); ?> encaissé</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(147,197,253,.12);color:#93c5fd"><i class="fas fa-users"></i></div>
        <div class="kpi-lbl">Employés</div>
        <div class="kpi-val b"><?php echo $totalEmp; ?></div>
        <div class="kpi-sub"><?php echo $activeShifts; ?> en service</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(251,191,36,.12);color:#fcd34d"><i class="fas fa-boxes"></i></div>
        <div class="kpi-lbl">Stock</div>
        <div class="kpi-val y"><?php echo number_format($stockStats['units']); ?></div>
        <div class="kpi-sub"><?php echo $stockStats['low']; ?> articles faible stock</div>
    </div>
</div>

<!-- Finances -->
<div class="fin-card">
    <div class="card-title"><i class="fas fa-chart-pie"></i> Finances Détaillées</div>
    <div class="fin-row">
        <div class="fin-lbl"><i class="fas fa-cash-register"></i> Chiffre d'Affaires Brut</div>
        <div class="fin-val b">$<?php echo number_format($fin['ca_brut'],2); ?></div>
    </div>
    <div class="fin-row">
        <div class="fin-lbl"><i class="fas fa-minus-circle"></i> Charges d'Exploitation</div>
        <div class="fin-val r">−$<?php echo number_format($fin['charges'],2); ?></div>
    </div>
    <div class="fin-row">
        <div class="fin-lbl"><i class="fas fa-equals"></i> Bénéfice Brut</div>
        <div class="fin-val g">$<?php echo number_format($fin['benefice_brut'],2); ?></div>
    </div>
    <div class="fin-row">
        <div class="fin-lbl"><i class="fas fa-landmark"></i> Impôts Dus (barème AMMU)</div>
        <div class="fin-val r">−$<?php echo number_format($fin['impot_du'],2); ?></div>
    </div>
    <div class="fin-row fin-highlight">
        <div class="fin-lbl" style="color:#f1f5f9;font-weight:700;font-size:1rem"><i class="fas fa-trophy" style="color:var(--cc)"></i> Bénéfice Net</div>
        <div class="fin-val g" style="font-size:1.5rem">$<?php echo number_format($fin['benefice_net'],2); ?></div>
    </div>

    <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:2px solid rgba(220,38,38,.15)">
        <div class="card-title" style="margin-bottom:1rem"><i class="fas fa-share-alt"></i> Distribution</div>
        <div class="fin-row">
            <div class="fin-lbl"><i class="fas fa-gift"></i> Pool Primes (30% bén. net)</div>
            <div class="fin-val g">$<?php echo number_format($primes,2); ?></div>
        </div>
        <div class="fin-row">
            <div class="fin-lbl"><i class="fas fa-crown"></i> Dividendes (40% bén. brut)</div>
            <div class="fin-val g">$<?php echo number_format($divs,2); ?></div>
        </div>
        <div class="fin-row">
            <div class="fin-lbl"><i class="fas fa-piggy-bank"></i> Trésorerie (30% bén. net)</div>
            <div class="fin-val b">$<?php echo number_format($fin['tresorerie'],2); ?></div>
        </div>
    </div>
</div>

<div class="grid2">
    <!-- Top vendeurs -->
    <div class="card">
        <div class="card-title"><i class="fas fa-trophy"></i> Top 5 Vendeurs</div>
        <?php if(empty($topSellers)): ?>
        <p style="text-align:center;color:#475569;padding:2rem">Aucune vente enregistrée</p>
        <?php else: ?>
        <?php foreach($topSellers as $i=>$s): ?>
        <div class="seller-item">
            <div class="seller-rank"><?php echo $i+1; ?></div>
            <div class="seller-info">
                <div class="seller-name"><?php echo htmlspecialchars($s['username']); ?></div>
                <div class="seller-role"><?php echo htmlspecialchars($s['rank']); ?></div>
            </div>
            <div class="seller-ca">$<?php echo number_format($s['individual_ca'],0); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Dernières ventes -->
    <div class="card">
        <div class="card-title"><i class="fas fa-receipt"></i> Dernières Ventes</div>
        <?php if(empty($recent)): ?>
        <p style="text-align:center;color:#475569;padding:2rem">Aucune vente</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Client</th><th>Vendeur</th><th>Montant</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach($recent as $inv): ?>
            <?php $statusLabel=['paid'=>'Payée','pending'=>'Attente','cancelled'=>'Annulée'][$inv['status']]??$inv['status']; ?>
            <tr>
                <td><?php echo htmlspecialchars($inv['customer_name']??'—'); ?></td>
                <td style="color:#94a3b8"><?php echo htmlspecialchars($inv['emp']); ?></td>
                <td style="color:#4ade80;font-weight:700">$<?php echo number_format($inv['final_amount'],2); ?></td>
                <td><span class="badge status-<?php echo $inv['status']; ?>"><?php echo $statusLabel; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>