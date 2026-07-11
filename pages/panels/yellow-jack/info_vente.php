<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyFaction = 'YELLOW_JACK';
$companyName    = 'Yellow Jack';
$pageTitle      = 'Classement Ventes';
$pageIcon       = 'trophy';
$db             = Database::getInstance()->getConnection();

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'info_vente.view')) faction_acl_deny();
$canEditCoeff = faction_acl_can($db, $uid, $companyFaction, 'info_vente.edit_coeff');
$isStaff = faction_acl_staff_bypass($db, $uid, $companyFaction);
$week    = date('Y-\\WW');

// ── AUTO-ARCHIVE DIMANCHE ─────────────────────────────────────────────────────
$now = new DateTime();
if ($now->format('N') == 7) {
    $prevWeek = (clone $now)->modify('-7 days')->format('Y-\\WW');
    $check = $db->prepare("SELECT COUNT(*) FROM company_sales_archive WHERE week=? AND faction=?");
    $check->execute([$prevWeek, $companyFaction]);
    if (!$check->fetchColumn()) {
        $lwData = $db->prepare("
            SELECT cs.employee_id, u.username,
                   COALESCE(SUM(cs.amount),0) as ca, COUNT(cs.id) as nb_ventes
            FROM company_sales cs JOIN users u ON u.id=cs.employee_id
            WHERE cs.week=? AND cs.faction=?
            GROUP BY cs.employee_id ORDER BY ca DESC
        ");
        $lwData->execute([$prevWeek, $companyFaction]);
        $lwData = $lwData->fetchAll(PDO::FETCH_ASSOC);
        $payConf = $db->query("SELECT rank,pct FROM company_pay_config WHERE faction='$companyFaction' ORDER BY rank")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($payConf)) $payConf = $db->query("SELECT rank,pct FROM company_pay_config WHERE faction IS NULL ORDER BY rank")->fetchAll(PDO::FETCH_ASSOC);
        $defPct = 4.80;
        foreach ($lwData as $rk => $row) {
            $rn = $rk + 1;
            $pRow = array_values(array_filter($payConf, fn($r) => $r['rank']==$rn));
            $pct  = !empty($pRow) ? $pRow[0]['pct'] : $defPct;
            $db->prepare("INSERT IGNORE INTO company_sales_archive (faction,week,employee_id,username,rank,ca,pay_pct,pay_amount) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$companyFaction,$prevWeek,$row['employee_id'],$row['username'],$rn,$row['ca'],$pct,round($row['ca']*$pct/100,2)]);
        }
    }
}

// ── ACTIONS STAFF ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && $isStaff && ($_POST['action']??'')==='update_pay') {
    foreach ($_POST['pct'] ?? [] as $rank => $pct) {
        $db->prepare("INSERT INTO company_pay_config (faction,rank,pct,label) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pct=VALUES(pct)")
           ->execute([$companyFaction, intval($rank), round(floatval($pct),2), 'Rang '.$rank]);
    }
    header('Location: info_vente.php?saved=1'); exit;
}

// ── DONNÉES SEMAINE COURANTE ──────────────────────────────────────────────────
$rankings = $db->prepare("
    SELECT cs.employee_id, u.username, u.faction_grade, u.faction_rank,
           COALESCE(SUM(cs.amount),0) as ca, COUNT(cs.id) as nb_ventes
    FROM company_sales cs JOIN users u ON u.id=cs.employee_id
    WHERE cs.week=? AND cs.faction=?
    GROUP BY cs.employee_id ORDER BY ca DESC
");
$rankings->execute([$week, $companyFaction]);
$rankings = $rankings->fetchAll(PDO::FETCH_ASSOC);

// Pay config
$payConf = $db->prepare("SELECT * FROM company_pay_config WHERE faction=? ORDER BY rank");
$payConf->execute([$companyFaction]);
$payConf = $payConf->fetchAll(PDO::FETCH_ASSOC);
if (empty($payConf)) {
    // defaults
    $payConf = [
        ['rank'=>1,'pct'=>15.0,'label'=>'Top 1'],
        ['rank'=>2,'pct'=>12.0,'label'=>'Top 2'],
        ['rank'=>3,'pct'=>10.5,'label'=>'Top 3'],
        ['rank'=>4,'pct'=>9.0,'label'=>'Top 4'],
        ['rank'=>5,'pct'=>8.5,'label'=>'Top 5'],
        ['rank'=>6,'pct'=>4.8,'label'=>'Autres'],
    ];
}
$getPayPct = function(int $rank) use ($payConf): float {
    foreach ($payConf as $r) { if ($r['rank']==$rank) return (float)$r['pct']; }
    return (float)(end($payConf)['pct'] ?? 4.80);
};

foreach ($rankings as $i => &$r) {
    $rn = $i+1;
    $r['rank']    = $rn;
    $r['pay_pct'] = $getPayPct($rn);
    $r['pay_amt'] = round($r['ca'] * $r['pay_pct'] / 100, 2);
}
unset($r);

$weekStats = $db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as nb FROM company_sales WHERE week=? AND faction=?");
$weekStats->execute([$week, $companyFaction]);
$weekStats = $weekStats->fetch(PDO::FETCH_ASSOC);

$history = $db->prepare("SELECT week, SUM(ca) as total_ca, COUNT(*) as nb_emp, SUM(pay_amount) as total_pay FROM company_sales_archive WHERE faction=? GROUP BY week ORDER BY week DESC LIMIT 8");
$history->execute([$companyFaction]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

$myRank = null;
foreach ($rankings as $r) { if ($r['employee_id']==$_SESSION['user_id']) { $myRank=$r; break; } }

$totalPay = array_sum(array_column($rankings,'pay_amt'));

include __DIR__ . '/includes/header.php';
$__ivAccent = !empty($__panelColor) ? $__panelColor : '#ca8a04';
$__hex = ltrim($__ivAccent, '#');
if (strlen($__hex) === 3) {
    $__hex = $__hex[0] . $__hex[0] . $__hex[1] . $__hex[1] . $__hex[2] . $__hex[2];
}
$__ccR = hexdec(substr($__hex, 0, 2));
$__ccG = hexdec(substr($__hex, 2, 2));
$__ccB = hexdec(substr($__hex, 4, 2));
?>
<style>
:root {
    --cc: <?php echo htmlspecialchars($__ivAccent); ?>;
    --cc-r: <?php echo (int) $__ccR; ?>; --cc-g: <?php echo (int) $__ccG; ?>; --cc-b: <?php echo (int) $__ccB; ?>;
}
.iv-wrap { position:relative; z-index:1; }
/* KPI */
.iv-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(148px,1fr)); gap:.85rem; margin-bottom:1.6rem; }
.ivk {
    background:rgba(13,21,38,.7); border:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.13);
    border-radius:11px; padding:.92rem 1.1rem;
    position:relative; overflow:hidden;
    transition:border-color .2s, transform .2s;
}
.ivk::before { content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background:var(--kc,var(--cc)); box-shadow:0 0 8px var(--kc,var(--cc)); }
.ivk:hover { border-color:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.32); transform:translateY(-2px); }
.ivk-label { font-size:.59rem; font-weight:700; text-transform:uppercase; letter-spacing:.15em; color:#64748b; margin-bottom:.3rem; }
.ivk-val   { font-size:1.55rem; font-weight:900; color:#f1f5f9; line-height:1; }
.ivk-sub   { font-size:.64rem; color:#64748b; margin-top:.18rem; }

/* Layout */
.iv-layout { display:grid; grid-template-columns:1fr 310px; gap:1.3rem; }

/* Ranking panel */
.rank-panel { background:rgba(13,21,38,.7); border:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.14); border-radius:12px; overflow:hidden; }
.rank-hdr { padding:.92rem 1.2rem; border-bottom:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.12); background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.04); display:flex; align-items:center; justify-content:space-between; }
.rank-hdr-title { font-size:.9rem; font-weight:800; color:#f1f5f9; display:flex; align-items:center; gap:.5rem; }
.rank-hdr-title i { color:var(--cc); }
.week-chip { font-size:.68rem; color:#64748b; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:5px; padding:.22rem .6rem; font-family:'Consolas',monospace; }

.rank-cols { display:grid; grid-template-columns:46px 1fr 85px 90px 105px; gap:.7rem; align-items:center; padding:.52rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.04); background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.025); }
.rank-col-label { font-size:.57rem; font-weight:800; text-transform:uppercase; letter-spacing:.12em; color:#475569; }

.rank-row { display:grid; grid-template-columns:46px 1fr 85px 90px 105px; gap:.7rem; align-items:center; padding:.8rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.03); transition:background .18s; animation:rIn .22s ease both; }
@keyframes rIn { from{opacity:0;transform:translateX(-6px)} to{opacity:1;transform:none} }
.rank-row:last-child { border-bottom:none; }
.rank-row:hover { background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.025); }
.rank-row.me { background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.06); border-left:3px solid var(--cc); }

.rk-num { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:900; flex-shrink:0; }
.rk-1 { background:linear-gradient(135deg,#fbbf24,#92400e); color:#000; box-shadow:0 0 12px rgba(251,191,36,.4); }
.rk-2 { background:linear-gradient(135deg,#cbd5e1,#64748b); color:#000; box-shadow:0 0 8px rgba(148,163,184,.3); }
.rk-3 { background:linear-gradient(135deg,#fcd34d,#78350f); color:#000; box-shadow:0 0 8px rgba(245,158,11,.3); }
.rk-n { background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#64748b; }

.rk-info { min-width:0; }
.rk-name { font-size:.85rem; font-weight:800; color:#f1f5f9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rk-grade { font-size:.67rem; color:#64748b; margin-top:.04rem; }

.rk-nb { font-size:.82rem; color:#64748b; text-align:center; }
.rk-ca { font-size:.92rem; font-weight:900; color:var(--cc); text-align:right; font-variant-numeric:tabular-nums; }
.rk-pay { text-align:right; }
.rk-pay-amt { font-size:.86rem; font-weight:800; color:#4ade80; font-variant-numeric:tabular-nums; }
.rk-pay-pct { font-size:.62rem; color:#64748b; }

.rank-empty { padding:3rem 1.5rem; text-align:center; color:#475569; }
.rank-empty i { font-size:2.5rem; display:block; margin-bottom:.8rem; opacity:.14; color:var(--cc); }

/* Side panel */
.iv-side { display:flex; flex-direction:column; gap:1.1rem; }

.side-card {
    background:rgba(13,21,38,.7);
    border:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.14);
    border-radius:11px; overflow:hidden;
}
.side-card-hdr {
    padding:.8rem 1.1rem; border-bottom:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.1);
    background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.03);
    font-size:.82rem; font-weight:800; color:#f1f5f9;
    display:flex; align-items:center; justify-content:space-between;
}
.side-card-hdr i { color:var(--cc); margin-right:.4rem; }
.side-card-body { padding:.9rem 1.1rem; }

/* My stats */
.my-stat { display:flex; justify-content:space-between; align-items:center; margin-bottom:.55rem; padding-bottom:.55rem; border-bottom:1px solid rgba(255,255,255,.04); }
.my-stat:last-child { margin-bottom:0; padding-bottom:0; border-bottom:none; }
.my-stat-label { font-size:.78rem; color:#64748b; }
.my-stat-val { font-size:.92rem; font-weight:800; }

/* Pay config */
.pay-row { display:grid; grid-template-columns:80px 1fr; align-items:center; gap:.6rem; margin-bottom:.45rem; }
.pay-row:last-child { margin-bottom:0; }
.pay-lbl { font-size:.73rem; font-weight:700; color:#64748b; }
.pay-inp {
    width:100%; padding:.36rem .6rem;
    background:rgba(8,12,23,.8); border:1px solid rgba(255,255,255,.1);
    border-radius:5px; color:#f1f5f9; font-family:inherit; font-size:.82rem; text-align:right;
    transition:border-color .18s;
}
.pay-inp:focus { outline:none; border-color:var(--cc); }

/* History */
.hist-row { display:flex; align-items:center; justify-content:space-between; padding:.58rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.03); font-size:.78rem; transition:background .18s; }
.hist-row:last-child { border-bottom:none; }
.hist-row:hover { background:rgba(255,255,255,.03); }
.hist-week { color:#64748b; font-family:'Consolas',monospace; font-size:.72rem; }
.hist-ca   { font-weight:800; color:var(--cc); }

/* Top1 hero */
.top1-hero {
    background:linear-gradient(135deg, rgba(var(--cc-r),var(--cc-g),var(--cc-b),.1), rgba(var(--cc-r),var(--cc-g),var(--cc-b),.04));
    border:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.25);
    border-radius:11px; padding:1.2rem;
    text-align:center; position:relative; overflow:hidden;
    margin-bottom:1.3rem;
}
.top1-hero::before {
    content:''; position:absolute; top:-30px; left:50%; transform:translateX(-50%);
    width:180px; height:180px; border-radius:50%;
    background:radial-gradient(circle, rgba(var(--cc-r),var(--cc-g),var(--cc-b),.12), transparent 65%);
    animation:top1glow 3s ease-in-out infinite;
}
@keyframes top1glow { 0%,100%{transform:translateX(-50%) scale(1)} 50%{transform:translateX(-50%) scale(1.15)} }
.top1-crown { font-size:1.8rem; display:block; margin-bottom:.5rem; }
.top1-name  { font-size:1.1rem; font-weight:900; color:#f1f5f9; margin-bottom:.25rem; }
.top1-ca    { font-size:.95rem; font-weight:800; color:var(--cc); }
.top1-label { font-size:.62rem; text-transform:uppercase; letter-spacing:.15em; color:#64748b; margin-top:.25rem; }

.badge-staff { background:rgba(var(--cc-r),var(--cc-g),var(--cc-b),.12); color:var(--cc); border:1px solid rgba(var(--cc-r),var(--cc-g),var(--cc-b),.22); padding:.18rem .52rem; border-radius:4px; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
.btn-save { display:flex; align-items:center; gap:.45rem; padding:.58rem 1.1rem; border-radius:7px; background:linear-gradient(135deg,var(--cc),rgba(var(--cc-r),var(--cc-g),var(--cc-b),.7)); color:#fff; border:none; cursor:pointer; font-weight:700; font-size:.82rem; width:100%; justify-content:center; margin-top:.75rem; transition:all .18s; }
.btn-save:hover { opacity:.88; transform:translateY(-1px); }

@media(max-width:1100px) { .iv-layout{ grid-template-columns:1fr; } .iv-kpi{ grid-template-columns:repeat(2,1fr); } }
</style>

<?php if (($_GET['saved']??'')==='1'): ?>
<div class="alert" style="background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.22);color:#4ade80;padding:.75rem 1.1rem;border-radius:8px;margin-bottom:1.3rem;display:flex;align-items:center;gap:.6rem;font-size:.86rem;font-weight:600;animation:fadeIn .22s ease">
    <i class="fas fa-check-circle"></i> Coefficients enregistrés.
</div>
<?php endif; ?>

<div class="iv-wrap">

<!-- KPI -->
<div class="iv-kpi">
    <div class="ivk" style="--kc:var(--cc)">
        <div class="ivk-label">Vendeurs actifs</div>
        <div class="ivk-val"><?php echo count($rankings); ?></div>
        <div class="ivk-sub">cette semaine</div>
    </div>
    <div class="ivk" style="--kc:#10b981">
        <div class="ivk-label">CA semaine</div>
        <div class="ivk-val">$<?php echo number_format($weekStats['total'],0,',',' '); ?></div>
        <div class="ivk-sub"><?php echo $weekStats['nb']; ?> ventes</div>
    </div>
    <div class="ivk" style="--kc:#3b82f6">
        <div class="ivk-label">Masse salariale</div>
        <div class="ivk-val">$<?php echo number_format($totalPay,0,',',' '); ?></div>
        <div class="ivk-sub">calculée</div>
    </div>
    <div class="ivk" style="--kc:#8b5cf6">
        <div class="ivk-label">Semaine</div>
        <div class="ivk-val" style="font-size:.95rem"><?php echo $week; ?></div>
        <div class="ivk-sub">en cours</div>
    </div>
</div>

<div class="iv-layout">

<!-- Classement -->
<div class="rank-panel">
    <div class="rank-hdr">
        <div class="rank-hdr-title"><i class="fas fa-trophy"></i>Classement hebdomadaire</div>
        <span class="week-chip"><?php echo $week; ?></span>
    </div>

    <?php if (!empty($rankings)): ?>
    <?php $top1 = $rankings[0]; ?>
    <div class="top1-hero">
        <span class="top1-crown">👑</span>
        <div class="top1-name"><?php echo htmlspecialchars($top1['username']); ?></div>
        <div class="top1-ca">$<?php echo number_format($top1['ca'],0,',',' '); ?></div>
        <div class="top1-label">Top vendeur de la semaine</div>
    </div>
    <?php endif; ?>

    <div class="rank-cols">
        <span class="rank-col-label">Rang</span>
        <span class="rank-col-label">Employé</span>
        <span class="rank-col-label" style="text-align:center">Ventes</span>
        <span class="rank-col-label" style="text-align:right">CA</span>
        <span class="rank-col-label" style="text-align:right">Paye estimée</span>
    </div>

    <?php if (empty($rankings)): ?>
    <div class="rank-empty">
        <i class="fas fa-chart-bar"></i>
        <p>Aucune vente cette semaine.</p>
    </div>
    <?php else: foreach ($rankings as $i => $r):
        $rn = $r['rank'];
        $nc = $rn<=3 ? "rk-$rn" : 'rk-n';
        $me = ($r['employee_id']==$_SESSION['user_id']);
        $grd= $r['faction_grade'] ?: $r['faction_rank'] ?: '—';
    ?>
    <div class="rank-row<?php echo $me?' me':''; ?>" style="animation-delay:<?php echo $i*.04; ?>s">
        <div class="rk-num <?php echo $nc; ?>">
            <?php if($rn===1):?><i class="fas fa-crown" style="font-size:.75rem"></i>
            <?php elseif($rn===2):?><i class="fas fa-medal"></i>
            <?php elseif($rn===3):?><i class="fas fa-award"></i>
            <?php else: echo $rn; endif; ?>
        </div>
        <div class="rk-info">
            <div class="rk-name"><?php echo htmlspecialchars($r['username']); ?><?php if($me):?> <span style="color:var(--cc);font-size:.62rem">(moi)</span><?php endif;?></div>
            <div class="rk-grade"><?php echo htmlspecialchars($grd); ?></div>
        </div>
        <div class="rk-nb"><?php echo $r['nb_ventes']; ?></div>
        <div class="rk-ca">$<?php echo number_format($r['ca'],0,',',' '); ?></div>
        <div class="rk-pay">
            <div class="rk-pay-amt">$<?php echo number_format($r['pay_amt'],2,',','.'); ?></div>
            <div class="rk-pay-pct"><?php echo number_format($r['pay_pct'],1); ?>% CA</div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- Side -->
<div class="iv-side">

    <!-- Mes stats -->
    <div class="side-card">
        <div class="side-card-hdr"><span><i class="fas fa-user"></i>Mes performances</span></div>
        <div class="side-card-body">
        <?php if ($myRank): ?>
            <div class="my-stat"><span class="my-stat-label">Classement</span><span class="my-stat-val" style="color:var(--cc)">#<?php echo $myRank['rank']; ?></span></div>
            <div class="my-stat"><span class="my-stat-label">CA semaine</span><span class="my-stat-val">$<?php echo number_format($myRank['ca'],0,',',' '); ?></span></div>
            <div class="my-stat"><span class="my-stat-label">Ventes</span><span class="my-stat-val"><?php echo $myRank['nb_ventes']; ?></span></div>
            <div class="my-stat"><span class="my-stat-label">Paye calculée</span><span class="my-stat-val" style="color:#4ade80">$<?php echo number_format($myRank['pay_amt'],2,',','.'); ?></span></div>
            <div class="my-stat"><span class="my-stat-label">Coefficient</span><span class="my-stat-val" style="color:#64748b"><?php echo $myRank['pay_pct']; ?>%</span></div>
        <?php else: ?>
            <div style="text-align:center;color:#475569;font-size:.82rem;padding:.8rem 0">
                <i class="fas fa-chart-line" style="display:block;font-size:1.4rem;margin-bottom:.45rem;opacity:.18"></i>
                Aucune vente cette semaine.
            </div>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($isStaff): ?>
    <!-- Config paye -->
    <div class="side-card">
        <div class="side-card-hdr">
            <span><i class="fas fa-sliders"></i>Coefficients de paye</span>
            <span class="badge-staff">Staff</span>
        </div>
        <div class="side-card-body">
            <form method="POST" action="info_vente.php">
                <input type="hidden" name="action" value="update_pay">
                <?php foreach ($payConf as $pc): ?>
                <div class="pay-row">
                    <span class="pay-lbl"><?php echo htmlspecialchars($pc['label']); ?></span>
                    <input type="number" name="pct[<?php echo $pc['rank']; ?>]"
                           value="<?php echo $pc['pct']; ?>"
                           step=".5" min="0" max="100" class="pay-inp">
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i>Enregistrer</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($history)): ?>
    <!-- Historique -->
    <div class="side-card">
        <div class="side-card-hdr"><span><i class="fas fa-history"></i>Historique</span></div>
        <?php foreach ($history as $h): ?>
        <div class="hist-row">
            <span class="hist-week"><?php echo $h['week']; ?></span>
            <span style="font-size:.72rem;color:#64748b"><?php echo (int)$h['nb_emp']; ?> emp.</span>
            <span class="hist-ca">$<?php echo number_format($h['total_ca'],0,',',' '); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- side -->
</div><!-- iv-layout -->
</div><!-- iv-wrap -->

<?php include __DIR__ . '/includes/footer.php'; ?>
