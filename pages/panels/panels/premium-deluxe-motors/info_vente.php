<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();
$panelCfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../../config/panel_access.php';
requireFactionPanelAccess($panelCfg['faction']);

$companyFaction = $panelCfg['faction'];
$companyName    = $panelCfg['name'];
$companyColor   = $panelCfg['color'];
$pageTitle      = 'Classement Ventes';
$db             = Database::getInstance()->getConnection();

$__ivStmt = $db->prepare('SELECT COALESCE(faction_grade,faction_rank,\'\') FROM users WHERE id=? LIMIT 1');
$__ivStmt->execute([$_SESSION['user_id'] ?? 0]);
$__ivGrade = (string)($__ivStmt->fetchColumn() ?: '');

$__isStaff  = hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']);
$__isMember = ($_SESSION['user_faction'] === $companyFaction);
$__isRHPlus = false;

if (!$__isStaff && $__isMember && $__ivGrade) {
    try {
        $__ivQ = $db->prepare(
            'SELECT fg.can_recruit FROM faction_grades fg'
            .' JOIN factions f ON f.id=fg.faction_id'
            .' WHERE f.faction_key=? AND fg.name=? LIMIT 1'
        );
        $__ivQ->execute([$companyFaction, $__ivGrade]);
        $__ivRow = $__ivQ->fetch(PDO::FETCH_ASSOC);
        if ($__ivRow && !empty($__ivRow['can_recruit'])) {
            $__isRHPlus = true;
        }
    } catch (Exception $e) {
    }
}

if (!($__isStaff || ($__isMember && $__isRHPlus))) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$isStaff = $__isStaff;
$week    = date('Y-\\WW');

$now = new DateTime();
if ($now->format('N') == 7) {
    $prevWeek = (clone $now)->modify('-7 days')->format('Y-\\WW');
    $check = $db->prepare('SELECT COUNT(*) FROM company_sales_archive WHERE week=? AND faction=?');
    $check->execute([$prevWeek, $companyFaction]);
    if (!$check->fetchColumn()) {
        $lwData = $db->prepare('
            SELECT cs.employee_id, u.username,
                   COALESCE(SUM(cs.amount),0) as ca, COUNT(cs.id) as nb_ventes
            FROM company_sales cs JOIN users u ON u.id=cs.employee_id
            WHERE cs.week=? AND cs.faction=?
            GROUP BY cs.employee_id ORDER BY ca DESC
        ');
        $lwData->execute([$prevWeek, $companyFaction]);
        $lwData = $lwData->fetchAll(PDO::FETCH_ASSOC);
        $payConf = $db->query("SELECT rank,pct FROM company_pay_config WHERE faction='$companyFaction' ORDER BY rank")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($payConf)) {
            $payConf = $db->query('SELECT rank,pct FROM company_pay_config WHERE faction IS NULL ORDER BY rank')->fetchAll(PDO::FETCH_ASSOC);
        }
        $defPct = 4.80;
        foreach ($lwData as $rk => $row) {
            $rn = $rk + 1;
            $pRow = array_values(array_filter($payConf, fn($r) => $r['rank'] == $rn));
            $pct  = !empty($pRow) ? $pRow[0]['pct'] : $defPct;
            $db->prepare('INSERT IGNORE INTO company_sales_archive (faction,week,employee_id,username,rank,ca,pay_pct,pay_amount) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$companyFaction, $prevWeek, $row['employee_id'], $row['username'], $rn, $row['ca'], $pct, round($row['ca'] * $pct / 100, 2)]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff && ($_POST['action'] ?? '') === 'update_pay') {
    foreach ($_POST['pct'] ?? [] as $rank => $pct) {
        $db->prepare('INSERT INTO company_pay_config (faction,rank,pct,label) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pct=VALUES(pct)')
           ->execute([$companyFaction, (int) $rank, round((float) $pct, 2), 'Rang '.$rank]);
    }
    header('Location: info_vente.php?saved=1');
    exit;
}

$rankings = $db->prepare('
    SELECT cs.employee_id, u.username, u.faction_grade, u.faction_rank,
           COALESCE(SUM(cs.amount),0) as ca, COUNT(cs.id) as nb_ventes
    FROM company_sales cs JOIN users u ON u.id=cs.employee_id
    WHERE cs.week=? AND cs.faction=?
    GROUP BY cs.employee_id ORDER BY ca DESC
');
$rankings->execute([$week, $companyFaction]);
$rankings = $rankings->fetchAll(PDO::FETCH_ASSOC);

$payConf = $db->prepare('SELECT * FROM company_pay_config WHERE faction=? ORDER BY rank');
$payConf->execute([$companyFaction]);
$payConf = $payConf->fetchAll(PDO::FETCH_ASSOC);
if (empty($payConf)) {
    $payConf = [
        ['rank'=>1,'pct'=>15.0,'label'=>'Top 1'],
        ['rank'=>2,'pct'=>12.0,'label'=>'Top 2'],
        ['rank'=>3,'pct'=>10.5,'label'=>'Top 3'],
        ['rank'=>4,'pct'=>9.0,'label'=>'Top 4'],
        ['rank'=>5,'pct'=>8.5,'label'=>'Top 5'],
        ['rank'=>6,'pct'=>4.8,'label'=>'Autres'],
    ];
}
$getPayPct = function (int $rank) use ($payConf): float {
    foreach ($payConf as $r) {
        if ($r['rank'] == $rank) {
            return (float) $r['pct'];
        }
    }
    return (float) (end($payConf)['pct'] ?? 4.80);
};

foreach ($rankings as $i => &$r) {
    $rn = $i + 1;
    $r['rank']    = $rn;
    $r['pay_pct'] = $getPayPct($rn);
    $r['pay_amt'] = round($r['ca'] * $r['pay_pct'] / 100, 2);
}
unset($r);

$weekStats = $db->prepare('SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as nb FROM company_sales WHERE week=? AND faction=?');
$weekStats->execute([$week, $companyFaction]);
$weekStats = $weekStats->fetch(PDO::FETCH_ASSOC);

$history = $db->prepare('SELECT week, SUM(ca) as total_ca, COUNT(*) as nb_emp, SUM(pay_amount) as total_pay FROM company_sales_archive WHERE faction=? GROUP BY week ORDER BY week DESC LIMIT 8');
$history->execute([$companyFaction]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

$myRank = null;
foreach ($rankings as $r) {
    if ($r['employee_id'] == $_SESSION['user_id']) {
        $myRank = $r;
        break;
    }
}

$totalPay = array_sum(array_column($rankings, 'pay_amt'));

include __DIR__ . '/includes/header.php';
?>

<?php if (($_GET['saved'] ?? '') === '1'): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> Coefficients enregistrés.
</div>
<?php endif; ?>

<div class="iv-wrap">
    <header class="lm-classement-intro">
        <p class="lm-classement-eyebrow">Performance commerciale</p>
        <h2 class="lm-classement-title">Classement hebdomadaire</h2>
        <p class="lm-classement-sub">Semaine <strong><?php echo htmlspecialchars($week); ?></strong> — rémunération indicative selon les coefficients RH.</p>
    </header>

    <div class="iv-kpi">
        <div class="ivk ivk--accent">
            <div class="ivk-label">Vendeurs actifs</div>
            <div class="ivk-val"><?php echo count($rankings); ?></div>
            <div class="ivk-sub">cette semaine</div>
        </div>
        <div class="ivk ivk--emerald">
            <div class="ivk-label">CA semaine</div>
            <div class="ivk-val">$<?php echo number_format((float)$weekStats['total'], 0, ',', ' '); ?></div>
            <div class="ivk-sub"><?php echo (int)$weekStats['nb']; ?> ventes</div>
        </div>
        <div class="ivk ivk--sky">
            <div class="ivk-label">Masse salariale</div>
            <div class="ivk-val">$<?php echo number_format((float)$totalPay, 0, ',', ' '); ?></div>
            <div class="ivk-sub">estimée</div>
        </div>
        <div class="ivk ivk--violet">
            <div class="ivk-label">Période</div>
            <div class="ivk-val ivk-val--sm"><?php echo htmlspecialchars($week); ?></div>
            <div class="ivk-sub">en cours</div>
        </div>
    </div>

    <div class="iv-layout">
        <div class="rank-panel">
            <div class="rank-hdr">
                <div class="rank-hdr-title"><i class="fas fa-ranking-star"></i> Leaderboard</div>
                <span class="week-chip"><?php echo htmlspecialchars($week); ?></span>
            </div>

            <?php if (!empty($rankings)): ?>
                <?php $top1 = $rankings[0]; ?>
                <div class="top1-hero">
                    <i class="fas fa-crown top1-crown" aria-hidden="true"></i>
                    <div class="top1-name"><?php echo htmlspecialchars($top1['username']); ?></div>
                    <div class="top1-ca">$<?php echo number_format((float)$top1['ca'], 0, ',', ' '); ?></div>
                    <div class="top1-label">Meilleure performance de la semaine</div>
                </div>
            <?php endif; ?>

            <div class="rank-cols">
                <span class="rank-col-label">Rang</span>
                <span class="rank-col-label">Employé</span>
                <span class="rank-col-label rank-col-label--center">Ventes</span>
                <span class="rank-col-label rank-col-label--end">CA</span>
                <span class="rank-col-label rank-col-label--end">Paye est.</span>
            </div>

            <?php if (empty($rankings)): ?>
                <div class="rank-empty">
                    <i class="fas fa-chart-bar"></i>
                    <p>Aucune vente enregistrée cette semaine.</p>
                </div>
            <?php else: ?>
                <?php foreach ($rankings as $i => $r): ?>
                    <?php
                    $rn = $r['rank'];
                    $nc = $rn <= 3 ? "rk-$rn" : 'rk-n';
                    $me = ($r['employee_id'] == $_SESSION['user_id']);
                    $grd = $r['faction_grade'] ?: $r['faction_rank'] ?: '—';
                    ?>
                    <div class="rank-row<?php echo $me ? ' me' : ''; ?>" style="animation-delay:<?php echo $i * 0.04; ?>s">
                        <div class="rk-num <?php echo $nc; ?>">
                            <?php if ($rn === 1): ?>
                                <i class="fas fa-crown" style="font-size:.75rem"></i>
                            <?php elseif ($rn === 2): ?>
                                <i class="fas fa-medal"></i>
                            <?php elseif ($rn === 3): ?>
                                <i class="fas fa-award"></i>
                            <?php else: ?>
                                <?php echo (int)$rn; ?>
                            <?php endif; ?>
                        </div>
                        <div class="rk-info">
                            <div class="rk-name">
                                <?php echo htmlspecialchars($r['username']); ?>
                                <?php if ($me): ?>
                                    <span class="rk-me-tag">vous</span>
                                <?php endif; ?>
                            </div>
                            <div class="rk-grade"><?php echo htmlspecialchars($grd); ?></div>
                        </div>
                        <div class="rk-nb"><?php echo (int)$r['nb_ventes']; ?></div>
                        <div class="rk-ca">$<?php echo number_format((float)$r['ca'], 0, ',', ' '); ?></div>
                        <div class="rk-pay">
                            <div class="rk-pay-amt">$<?php echo number_format((float)$r['pay_amt'], 2, ',', '.'); ?></div>
                            <div class="rk-pay-pct"><?php echo number_format((float)$r['pay_pct'], 1); ?>% CA</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <aside class="iv-side">
            <div class="side-card">
                <div class="side-card-hdr"><span><i class="fas fa-user-astronaut"></i> Ma ligne</span></div>
                <div class="side-card-body">
                    <?php if ($myRank): ?>
                        <div class="my-stat"><span class="my-stat-label">Classement</span><span class="my-stat-val" style="color:var(--cc)">#<?php echo (int)$myRank['rank']; ?></span></div>
                        <div class="my-stat"><span class="my-stat-label">CA semaine</span><span class="my-stat-val">$<?php echo number_format((float)$myRank['ca'], 0, ',', ' '); ?></span></div>
                        <div class="my-stat"><span class="my-stat-label">Ventes</span><span class="my-stat-val"><?php echo (int)$myRank['nb_ventes']; ?></span></div>
                        <div class="my-stat"><span class="my-stat-label">Paye calculée</span><span class="my-stat-val" style="color:#4ade80">$<?php echo number_format((float)$myRank['pay_amt'], 2, ',', '.'); ?></span></div>
                        <div class="my-stat"><span class="my-stat-label">Coefficient</span><span class="my-stat-val" style="color:#64748b"><?php echo htmlspecialchars((string)$myRank['pay_pct']); ?>%</span></div>
                    <?php else: ?>
                        <div class="lm-empty-mini">
                            <i class="fas fa-chart-line"></i>
                            <p>Aucune vente à votre nom cette semaine.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isStaff): ?>
            <div class="side-card">
                <div class="side-card-hdr">
                    <span><i class="fas fa-sliders"></i> Coefficients</span>
                    <span class="badge-staff">Staff</span>
                </div>
                <div class="side-card-body">
                    <form method="POST" action="info_vente.php">
                        <input type="hidden" name="action" value="update_pay">
                        <?php foreach ($payConf as $pc): ?>
                        <div class="pay-row">
                            <span class="pay-lbl"><?php echo htmlspecialchars($pc['label']); ?></span>
                            <input type="number" name="pct[<?php echo (int)$pc['rank']; ?>]"
                                   value="<?php echo htmlspecialchars((string)$pc['pct']); ?>"
                                   step="0.5" min="0" max="100" class="pay-inp">
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
            <div class="side-card">
                <div class="side-card-hdr"><span><i class="fas fa-archive"></i> Archives</span></div>
                <div class="side-card-body" style="padding-top:0">
                    <?php foreach ($history as $h): ?>
                    <div class="hist-row">
                        <span class="hist-week"><?php echo htmlspecialchars($h['week']); ?></span>
                        <span class="lm-muted" style="font-size:.72rem"><?php echo (int)$h['nb_emp']; ?> emp.</span>
                        <span class="hist-ca">$<?php echo number_format((float)$h['total_ca'], 0, ',', ' '); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
