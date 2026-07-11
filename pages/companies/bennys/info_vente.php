<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/faction_helper.php';
requireLogin();

$companyFaction = 'BENNYS';
$pageTitle      = 'Classement Ventes';
$pageIcon       = 'trophy';

$db = Database::getInstance()->getConnection();

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'info_vente.view')) faction_acl_deny();
$canEditCoeff = faction_acl_can($db, $uid, $companyFaction, 'info_vente.edit_coeff');
$isStaff = faction_acl_staff_bypass($db, $uid, $companyFaction);
$msg = ''; $msgType = '';
$week = date('Y-\WW');

// ── AUTO RESET HEBDOMADAIRE (dimanche 00h) ───────────────────────────────
$now = new DateTime();
if ($now->format('N') == 7) { // dimanche
    // Archiver semaine précédente si pas encore fait
    $prevWeek = (clone $now)->modify('-7 days')->format('Y-\WW');
    $alreadyArchived = $db->prepare("SELECT COUNT(*) FROM bennys_weekly_archive WHERE week=?");
    $alreadyArchived->execute([$prevWeek]);
    if (!$alreadyArchived->fetchColumn()) {
        // Fetch last week data
        $lwData = $db->prepare("
            SELECT bs.employee_id, u.username,
                   COALESCE(SUM(bs.amount),0) as ca
            FROM bennys_sales bs
            JOIN users u ON u.id=bs.employee_id
            WHERE bs.week=?
            GROUP BY bs.employee_id
            ORDER BY ca DESC
        ");
        $lwData->execute([$prevWeek]);
        $lwData = $lwData->fetchAll(PDO::FETCH_ASSOC);

        // Fetch pay config
        $payConf = $db->query("SELECT rank,pct FROM bennys_pay_config ORDER BY rank")->fetchAll(PDO::FETCH_ASSOC);
        $maxPct  = end($payConf)['pct'] ?? 4.80;

        foreach ($lwData as $rank => $row) {
            $rankNum = $rank + 1;
            $pctRow  = array_values(array_filter($payConf, fn($r) => $r['rank'] == $rankNum));
            $pct     = !empty($pctRow) ? $pctRow[0]['pct'] : $maxPct;
            $payAmt  = $row['ca'] * $pct / 100;
            $db->prepare("INSERT IGNORE INTO bennys_weekly_archive (week,employee_id,username,rank,ca,pay_pct,pay_amount) VALUES (?,?,?,?,?,?,?)")
               ->execute([$prevWeek, $row['employee_id'], $row['username'], $rankNum, $row['ca'], $pct, $payAmt]);
        }
    }
}

// ── ACTIONS STAFF ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff) {
    if (($_POST['action'] ?? '') === 'update_pay') {
        foreach ($_POST['pct'] ?? [] as $rank => $pct) {
            $db->prepare("UPDATE bennys_pay_config SET pct=? WHERE rank=?")->execute([round(floatval($pct),2), intval($rank)]);
        }
        $msg = 'Coefficients de paye mis à jour.'; $msgType = 'success';
    }
}

// ── DONNÉES SEMAINE COURANTE ──────────────────────────────────────────────
$rankings = $db->prepare("
    SELECT bs.employee_id, u.username,
           u.faction_grade, u.faction_rank,
           COALESCE(SUM(bs.amount),0) as ca,
           COUNT(bs.id) as nb_ventes
    FROM bennys_sales bs
    JOIN users u ON u.id=bs.employee_id
    WHERE bs.week=?
    GROUP BY bs.employee_id
    ORDER BY ca DESC
");
$rankings->execute([$week]);
$rankings = $rankings->fetchAll(PDO::FETCH_ASSOC);

// Pay config
$payConf = $db->query("SELECT * FROM bennys_pay_config ORDER BY rank")->fetchAll(PDO::FETCH_ASSOC);
$getPayPct = function(int $rank) use ($payConf): float {
    $max = 4.80;
    foreach ($payConf as $r) { if ($r['rank'] == $rank) return (float)$r['pct']; $max = (float)$r['pct']; }
    return $max;
};

// Compute pay + enriched rankings
$totalCA = 0;
foreach ($rankings as &$r) {
    $totalCA += $r['ca'];
    $rank1 = array_search($r, $rankings) + 1;
}
unset($r);

foreach ($rankings as $i => &$r) {
    $rankNum      = $i + 1;
    $pct          = $getPayPct($rankNum);
    $r['rank']    = $rankNum;
    $r['pay_pct'] = $pct;
    $r['pay_amt'] = round($r['ca'] * $pct / 100, 2);
}
unset($r);

// Global stats
$allStats = $db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as nb FROM bennys_sales WHERE week=?");
$allStats->execute([$week]);
$allStats = $allStats->fetch(PDO::FETCH_ASSOC);

// Historique semaines
$history = $db->query("
    SELECT week, SUM(ca) as total_ca, COUNT(*) as nb_emp, SUM(pay_amount) as total_pay
    FROM bennys_weekly_archive
    GROUP BY week ORDER BY week DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// My ranking
$myRank = null;
foreach ($rankings as $r) { if ($r['employee_id'] == $_SESSION['user_id']) { $myRank = $r; break; } }

include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ INFO_VENTE PAGE ═══════════════════════════════════════════════════════ */
.iv-layout { display:grid; grid-template-columns:1fr 340px; gap:1.4rem; position:relative; z-index:1; }

/* KPI top */
.iv-kpi { display:grid; grid-template-columns:repeat(4,1fr); gap:.85rem; margin-bottom:1.4rem; position:relative; z-index:1; }
.ivk {
    background:var(--bg-c); border:1px solid var(--bdr);
    border-radius:10px; padding:.9rem 1.1rem;
    position:relative; overflow:hidden;
    transition: border-color var(--speed), transform var(--speed);
}
.ivk::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:var(--ivkc,var(--a)); }
.ivk:hover { border-color:var(--bdr-h); transform:translateY(-2px); }
.ivk-label { font-size:.59rem; font-weight:700; text-transform:uppercase; letter-spacing:.15em; color:var(--txt-s); margin-bottom:.3rem; }
.ivk-val { font-size:1.55rem; font-weight:900; color:var(--txt); line-height:1; }
.ivk-sub { font-size:.64rem; color:var(--txt-s); margin-top:.18rem; }

/* Ranking table */
.rank-panel { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; }
.rank-header { padding:1rem 1.3rem; border-bottom:1px solid var(--bdr); background:rgba(245,158,11,.03); display:flex; align-items:center; justify-content:space-between; }
.rank-title { font-size:.9rem; font-weight:800; color:var(--txt); display:flex; align-items:center; gap:.5rem; }
.rank-title i { color:var(--a); }
.week-label { font-size:.72rem; color:var(--txt-s); background:var(--bg); border:1px solid var(--bdr); border-radius:6px; padding:.25rem .65rem; font-family:'Consolas',monospace; }

.rank-row {
    display:grid;
    grid-template-columns: 48px 1fr 100px 100px 110px;
    align-items:center; gap:.75rem;
    padding:.85rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.03);
    transition:background var(--speed);
    animation: rankIn .25s ease both;
}
@keyframes rankIn { from{opacity:0;transform:translateX(-8px)} to{opacity:1;transform:none} }
.rank-row:last-child { border-bottom:none; }
.rank-row:hover { background:rgba(245,158,11,.025); }
.rank-row.me { background:rgba(245,158,11,.06); border-left:3px solid var(--a); }

/* Rank number */
.rank-num {
    width:36px; height:36px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:.82rem; font-weight:900; flex-shrink:0;
}
.rank-1 { background:linear-gradient(135deg,#fbbf24,#92400e); color:#000; box-shadow:0 0 14px rgba(251,191,36,.45); }
.rank-2 { background:linear-gradient(135deg,#cbd5e1,#64748b); color:#000; box-shadow:0 0 10px rgba(148,163,184,.3); }
.rank-3 { background:linear-gradient(135deg,#fcd34d,#78350f); color:#000; box-shadow:0 0 10px rgba(245,158,11,.3); }
.rank-n { background:rgba(255,255,255,.05); border:1px solid var(--bdr); color:var(--txt-s); }

/* Rank info */
.rank-info { min-width:0; }
.rank-name { font-size:.88rem; font-weight:800; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rank-grade { font-size:.68rem; color:var(--txt-s); margin-top:.05rem; }

/* Rank data cells */
.rank-nb { font-size:.82rem; color:var(--txt-s); text-align:center; }
.rank-ca { font-size:.95rem; font-weight:900; color:var(--a); text-align:right; font-variant-numeric:tabular-nums; }
.rank-pay { text-align:right; }
.rank-pay-amt { font-size:.88rem; font-weight:800; color:#6ee7b7; font-variant-numeric:tabular-nums; }
.rank-pay-pct { font-size:.63rem; color:var(--txt-s); margin-top:.04rem; }

/* Rank empty */
.rank-empty { padding:3rem 1.5rem; text-align:center; color:var(--txt-s); }
.rank-empty i { font-size:2.5rem; display:block; margin-bottom:.8rem; opacity:.14; color:var(--a); }

/* Right panel */
.iv-side { display:flex; flex-direction:column; gap:1.1rem; }

/* My stats card */
.my-card {
    background: linear-gradient(135deg, rgba(245,158,11,.08), rgba(180,83,9,.04));
    border: 1px solid rgba(245,158,11,.25);
    border-radius:12px; padding:1.25rem;
    position:relative; overflow:hidden;
}
.my-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg, var(--a-d), var(--a), var(--a-l));
}
.my-card-title { font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.15em; color:var(--a); margin-bottom:.9rem; }
.my-stat { display:flex; justify-content:space-between; align-items:center; margin-bottom:.6rem; padding-bottom:.6rem; border-bottom:1px solid rgba(245,158,11,.1); }
.my-stat:last-child { margin-bottom:0; padding-bottom:0; border-bottom:none; }
.my-stat-label { font-size:.78rem; color:var(--txt-s); }
.my-stat-val { font-size:.95rem; font-weight:900; }

/* Pay config (staff) */
.pay-config-card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; }
.pay-config-header { padding:.85rem 1.1rem; border-bottom:1px solid var(--bdr); background:rgba(245,158,11,.03); display:flex; align-items:center; justify-content:space-between; }
.pay-config-title { font-size:.82rem; font-weight:800; display:flex; align-items:center; gap:.45rem; }
.pay-config-title i { color:var(--a); }
.pay-config-body { padding:.85rem 1rem; }
.pay-row { display:grid; grid-template-columns:80px 1fr; align-items:center; gap:.65rem; margin-bottom:.5rem; }
.pay-row:last-child { margin-bottom:0; }
.pay-row-label { font-size:.73rem; font-weight:700; color:var(--txt-s); }
.pay-input { padding:.38rem .65rem; background:var(--bg); border:1px solid var(--bdr); border-radius:5px; color:var(--txt); font-family:inherit; font-size:.82rem; width:100%; text-align:right; transition:all var(--speed); }
.pay-input:focus { outline:none; border-color:var(--a); box-shadow:0 0 0 2px rgba(245,158,11,.1); }
.pay-pct-display { font-size:.82rem; font-weight:800; color:var(--a-l); text-align:right; }

/* History */
.hist-card { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; }
.hist-header { padding:.85rem 1.1rem; border-bottom:1px solid var(--bdr); background:rgba(245,158,11,.03); }
.hist-title { font-size:.82rem; font-weight:800; display:flex; align-items:center; gap:.45rem; }
.hist-title i { color:var(--a); }
.hist-row { display:flex; align-items:center; justify-content:space-between; padding:.62rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.03); font-size:.78rem; transition:background var(--speed); }
.hist-row:last-child { border-bottom:none; }
.hist-row:hover { background:rgba(245,158,11,.025); }
.hist-week { color:var(--txt-s); font-family:'Consolas',monospace; font-size:.72rem; }
.hist-ca { font-weight:800; color:var(--a); }

@media(max-width:1100px) { .iv-layout { grid-template-columns:1fr; } .iv-kpi { grid-template-columns:repeat(2,1fr); } }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>" style="position:relative;z-index:1">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- KPI -->
<div class="iv-kpi">
    <div class="ivk" style="--ivkc:#fbbf24">
        <div class="ivk-label">Vendeurs actifs</div>
        <div class="ivk-val"><?php echo count($rankings); ?></div>
        <div class="ivk-sub">cette semaine</div>
    </div>
    <div class="ivk" style="--ivkc:#10b981">
        <div class="ivk-label">CA semaine</div>
        <div class="ivk-val mono">$<?php echo number_format($allStats['total'],0,',',' '); ?></div>
        <div class="ivk-sub"><?php echo $allStats['nb']; ?> ventes</div>
    </div>
    <div class="ivk" style="--ivkc:#3b82f6">
        <div class="ivk-label">Masse salariale</div>
        <div class="ivk-val mono">$<?php echo number_format(array_sum(array_column($rankings,'pay_amt')),0,',',' '); ?></div>
        <div class="ivk-sub">calculée</div>
    </div>
    <div class="ivk" style="--ivkc:#8b5cf6">
        <div class="ivk-label">Semaine</div>
        <div class="ivk-val" style="font-size:1rem"><?php echo $week; ?></div>
        <div class="ivk-sub">en cours</div>
    </div>
</div>

<div class="iv-layout">

<!-- ── CLASSEMENT ── -->
<div class="rank-panel">
    <div class="rank-header">
        <div class="rank-title"><i class="fas fa-trophy"></i>Classement hebdomadaire</div>
        <span class="week-label"><?php echo $week; ?></span>
    </div>

    <?php if (empty($rankings)): ?>
    <div class="rank-empty">
        <i class="fas fa-chart-bar"></i>
        <p>Aucune vente cette semaine pour l'instant.</p>
    </div>
    <?php else: ?>
    <!-- Table header -->
    <div style="display:grid;grid-template-columns:48px 1fr 100px 100px 110px;gap:.75rem;padding:.55rem 1.2rem;border-bottom:1px solid var(--bdr);background:rgba(245,158,11,.03)">
        <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-s)">Rang</div>
        <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-s)">Employé</div>
        <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-s);text-align:center">Ventes</div>
        <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-s);text-align:right">CA</div>
        <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--txt-s);text-align:right">Paye</div>
    </div>

    <?php foreach ($rankings as $i => $r):
        $rn = $r['rank'];
        $numClass = $rn <= 3 ? "rank-$rn" : 'rank-n';
        $isMe = $r['employee_id'] == $_SESSION['user_id'];
        $grade = $r['faction_grade'] ?: $r['faction_rank'] ?: '—';
    ?>
    <div class="rank-row<?php echo $isMe?' me':''; ?>" style="animation-delay:<?php echo $i*.04; ?>s">
        <div class="rank-num <?php echo $numClass; ?>">
            <?php if ($rn===1): ?><i class="fas fa-crown" style="font-size:.8rem"></i>
            <?php elseif ($rn===2): ?><i class="fas fa-medal"></i>
            <?php elseif ($rn===3): ?><i class="fas fa-award"></i>
            <?php else: echo $rn; endif; ?>
        </div>
        <div class="rank-info">
            <div class="rank-name"><?php echo htmlspecialchars($r['username']); ?><?php if($isMe): ?> <span style="font-size:.62rem;color:var(--a);margin-left:.3rem">(moi)</span><?php endif; ?></div>
            <div class="rank-grade"><?php echo htmlspecialchars($grade); ?></div>
        </div>
        <div class="rank-nb"><?php echo $r['nb_ventes']; ?> vente<?php echo $r['nb_ventes']>1?'s':''; ?></div>
        <div class="rank-ca mono">$<?php echo number_format($r['ca'],0,',',' '); ?></div>
        <div class="rank-pay">
            <div class="rank-pay-amt mono">$<?php echo number_format($r['pay_amt'],2,',','.'); ?></div>
            <div class="rank-pay-pct"><?php echo number_format($r['pay_pct'],1); ?>% CA</div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── SIDE PANEL ── -->
<div class="iv-side">

    <!-- Mon classement -->
    <div class="my-card">
        <div class="my-card-title"><i class="fas fa-user"></i> Mes performances</div>
        <?php if ($myRank): ?>
        <div class="my-stat">
            <span class="my-stat-label">Classement</span>
            <span class="my-stat-val" style="color:var(--a)">#<?php echo $myRank['rank']; ?></span>
        </div>
        <div class="my-stat">
            <span class="my-stat-label">CA cette semaine</span>
            <span class="my-stat-val mono">$<?php echo number_format($myRank['ca'],0,',',' '); ?></span>
        </div>
        <div class="my-stat">
            <span class="my-stat-label">Ventes</span>
            <span class="my-stat-val"><?php echo $myRank['nb_ventes']; ?></span>
        </div>
        <div class="my-stat">
            <span class="my-stat-label">Paye calculée</span>
            <span class="my-stat-val mono" style="color:#6ee7b7">$<?php echo number_format($myRank['pay_amt'],2,',','.'); ?></span>
        </div>
        <div class="my-stat">
            <span class="my-stat-label">Coefficient</span>
            <span class="my-stat-val" style="color:var(--txt-s)"><?php echo $myRank['pay_pct']; ?>%</span>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:1rem;color:var(--txt-s);font-size:.84rem">
            <i class="fas fa-chart-line" style="display:block;font-size:1.5rem;margin-bottom:.5rem;opacity:.2"></i>
            Aucune vente cette semaine.
        </div>
        <?php endif; ?>
    </div>

    <!-- Pay config (staff only) -->
    <?php if ($isStaff): ?>
    <div class="pay-config-card">
        <div class="pay-config-header">
            <div class="pay-config-title"><i class="fas fa-sliders"></i>Coefficients de paye</div>
            <span class="badge badge-amber">Staff</span>
        </div>
        <div class="pay-config-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pay">
                <?php foreach ($payConf as $pc): ?>
                <div class="pay-row">
                    <span class="pay-row-label"><?php echo htmlspecialchars($pc['label']); ?></span>
                    <input type="number" name="pct[<?php echo $pc['rank']; ?>]" value="<?php echo $pc['pct']; ?>" step=".5" min="0" max="100" class="pay-input">
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary btn-full btn-sm" style="margin-top:.85rem">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historique -->
    <?php if (!empty($history)): ?>
    <div class="hist-card">
        <div class="hist-header">
            <div class="hist-title"><i class="fas fa-history"></i>Historique</div>
        </div>
        <?php foreach ($history as $h): ?>
        <div class="hist-row">
            <span class="hist-week"><?php echo $h['week']; ?></span>
            <span style="font-size:.74rem;color:var(--txt-s)"><?php echo (int)$h['nb_emp']; ?> emp.</span>
            <span class="hist-ca mono">$<?php echo number_format($h['total_ca'],0,',',' '); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- .iv-side -->
</div><!-- .iv-layout -->

<?php include __DIR__ . '/includes/footer.php'; ?>