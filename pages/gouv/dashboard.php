<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Vue d\'ensemble économique';
$db = Database::getInstance()->getConnection();
include __DIR__ . '/includes/header.php';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

// ── Factions commerciales taxables ────────────────────────────────────────────
$COMPANY_FACTIONS = [
    'HAUTE_LUXURY','BAHAMAS','BENNYS','LS_CUSTOM','LS_MOTORS','HEAVY_IMPOUND',
    'DYNASTY','LTD','AMMUNATION','LIFE_INVADER','POSTOP','TAXI','TRIADS',
    'UWU_COFFEE','RESTAURANT_KOI','UP_ATOM','GRUPP6',
];

// ── Agrégats globaux depuis company_finances ──────────────────────────────────
$stmt = $db->query("
    SELECT
        COUNT(*)                         AS nb_entreprises,
        COALESCE(SUM(ca_brut),0)         AS total_ca,
        COALESCE(SUM(charges),0)         AS total_charges,
        COALESCE(SUM(benefice_brut),0)   AS total_b_brut,
        COALESCE(SUM(impot_du),0)        AS total_impots,
        COALESCE(SUM(benefice_net),0)    AS total_b_net,
        COALESCE(SUM(tresorerie),0)      AS total_treso
    FROM company_finances
");
$glob = $stmt->fetch();

// Total factures company_invoices (payées)
$stmt = $db->query("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE status='paid'");
$totalInvoices = (float)$stmt->fetchColumn();

// Total factures SAMS (payées)
$stmt = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE status='paid'");
$totalSams = (float)$stmt->fetchColumn();

// Total dépenses
$stmt = $db->query("SELECT COALESCE(SUM(amount),0) FROM company_expenses");
$totalExpenses = (float)$stmt->fetchColumn();

// Top 5 entreprises par CA
$stmt = $db->query("
    SELECT cf.faction,
           COALESCE(f.name, cf.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6')  AS faction_color,
           cf.ca_brut, cf.benefice_net, cf.impot_du,
           cf.charges, cf.benefice_brut
    FROM company_finances cf
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = cf.faction
    ORDER BY cf.ca_brut DESC
    LIMIT 8
");
$topCompanies = $stmt->fetchAll();

$maxCA = !empty($topCompanies) ? (float)$topCompanies[0]['ca_brut'] : 1;

// Activité factures mois courant
$stmt = $db->query("
    SELECT COUNT(*) as cnt, COALESCE(SUM(final_amount),0) as total
    FROM company_invoices
    WHERE status='paid'
      AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
");
$monthInv = $stmt->fetch();

// Exonérations actives
$stmt = $db->query("SELECT COUNT(*) FROM gouv_exemptions WHERE (expires_at IS NULL OR expires_at >= CURDATE())");
$activeExemptions = (int)$stmt->fetchColumn();

// Répartition CA par secteur (catégories manuelles)
$SECTORS = [
    'Hôtellerie / Bar'    => ['HAUTE_LUXURY','BAHAMAS'],
    'Automobile'          => ['BENNYS','LS_CUSTOM','LS_MOTORS'],
    'Commerce'            => ['LTD','AMMUNATION','HEAVY_IMPOUND'],
    'Services'            => ['LIFE_INVADER','TRIADS'],
    'Transport'           => ['POSTOP','TAXI','GRUPP6'],
    'Restauration'        => ['UWU_COFFEE','RESTAURANT_KOI','UP_ATOM','DYNASTY'],
];
$sectorCA = [];
$allFinances = $db->query("SELECT faction, ca_brut FROM company_finances")->fetchAll(\PDO::FETCH_KEY_PAIR);
foreach ($SECTORS as $sName => $keys) {
    $sectorCA[$sName] = array_sum(array_map(fn($k) => (float)($allFinances[$k] ?? 0), $keys));
}
arsort($sectorCA);
$totalSectorCA = array_sum($sectorCA) ?: 1;
?>

<!-- KPIs économiques -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-chart-bar"></i></div>
        <div class="kpi-lbl">PIB des entreprises</div>
        <div class="kpi-val blue">$<?php echo number_format($glob['total_ca'],0,'.',','); ?></div>
        <div class="kpi-sub">CA brut cumulé toutes entreprises</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(251,191,36,.12);color:#fbbf24"><i class="fas fa-scale-balanced"></i></div>
        <div class="kpi-lbl">Impôts collectés</div>
        <div class="kpi-val gold">$<?php echo number_format($glob['total_impots'],0,'.',','); ?></div>
        <div class="kpi-sub">Total impôts dus sur bénéfices</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(34,197,94,.12);color:#4ade80"><i class="fas fa-money-bill-trend-up"></i></div>
        <div class="kpi-lbl">Bénéfice net ville</div>
        <div class="kpi-val green">$<?php echo number_format($glob['total_b_net'],0,'.',','); ?></div>
        <div class="kpi-sub">Somme des bénéfices nets</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-lbl">Factures payées</div>
        <div class="kpi-val">$<?php echo number_format($totalInvoices + $totalSams,0,'.',','); ?></div>
        <div class="kpi-sub">Entreprises + SAMS</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#f87171"><i class="fas fa-minus-circle"></i></div>
        <div class="kpi-lbl">Charges totales</div>
        <div class="kpi-val red">$<?php echo number_format($glob['total_charges'],0,'.',','); ?></div>
        <div class="kpi-sub">Charges déductibles déclarées</div>
    </div>
    <div class="kpi">
        <div class="kpi-ico"><i class="fas fa-building"></i></div>
        <div class="kpi-lbl">Entreprises actives</div>
        <div class="kpi-val"><?php echo $glob['nb_entreprises']; ?></div>
        <div class="kpi-sub"><?php echo $activeExemptions; ?> exonération(s) en cours</div>
    </div>
</div>

<div class="grid2">
    <!-- Top entreprises -->
    <div class="card">
        <div class="card-title"><i class="fas fa-trophy"></i> Classement économique</div>
        <?php if(empty($topCompanies)): ?>
        <p class="txt-muted" style="text-align:center;padding:2rem">Aucune donnée financière</p>
        <?php else: ?>
        <?php foreach($topCompanies as $c):
            $pct = $maxCA > 0 ? ((float)$c['ca_brut'] / $maxCA * 100) : 0;
            $col = $c['faction_color'];
        ?>
        <div style="margin-bottom:1.1rem">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem">
                <div>
                    <span class="faction-chip" style="background:<?php echo $col; ?>18;color:<?php echo $col; ?>;border:1px solid <?php echo $col; ?>33">
                        <?php echo htmlspecialchars($c['faction_name']); ?>
                    </span>
                </div>
                <div style="text-align:right">
                    <span class="mono" style="font-size:.82rem;color:#f1f5f9;font-weight:700">
                        $<?php echo number_format($c['ca_brut'],0,'.',','); ?>
                    </span>
                    <span style="font-size:.7rem;color:var(--txt-m);margin-left:.35rem">CA brut</span>
                </div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?php echo $pct; ?>%;background:linear-gradient(90deg,<?php echo $col; ?>,<?php echo $col; ?>88)"></div>
            </div>
            <div style="display:flex;gap:1rem;margin-top:.3rem;font-size:.7rem;color:var(--txt-m)">
                <span>Impôts: <strong class="txt-gold">$<?php echo number_format($c['impot_du'],0,'.',','); ?></strong></span>
                <span>Bén. net: <strong class="txt-green">$<?php echo number_format($c['benefice_net'],0,'.',','); ?></strong></span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Répartition par secteur -->
    <div>
        <div class="card">
            <div class="card-title"><i class="fas fa-chart-pie"></i> Répartition par secteur</div>
            <?php foreach($sectorCA as $sName => $sCA):
                $pct = $totalSectorCA > 0 ? ($sCA / $totalSectorCA * 100) : 0;
                if($sCA <= 0) continue;
            ?>
            <div style="margin-bottom:.9rem">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.3rem">
                    <span style="color:var(--txt-s)"><?php echo $sName; ?></span>
                    <span class="mono txt-blue"><?php echo number_format($pct,1); ?>%
                        <span class="txt-muted" style="font-size:.7rem">— $<?php echo number_format($sCA,0,'.',','); ?></span>
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Activité du mois -->
        <div class="card">
            <div class="card-title"><i class="fas fa-calendar-check"></i> Activité ce mois</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div style="text-align:center;padding:.75rem;background:var(--bg-ov);border-radius:9px">
                    <div style="font-size:1.5rem;font-weight:700;color:var(--gov-l);font-family:'IBM Plex Mono',monospace"><?php echo $monthInv['cnt']; ?></div>
                    <div style="font-size:.7rem;color:var(--txt-m);margin-top:.2rem;text-transform:uppercase;letter-spacing:.07em">Factures payées</div>
                </div>
                <div style="text-align:center;padding:.75rem;background:var(--bg-ov);border-radius:9px">
                    <div style="font-size:1.2rem;font-weight:700;color:#4ade80;font-family:'IBM Plex Mono',monospace">$<?php echo number_format($monthInv['total'],0,'.',','); ?></div>
                    <div style="font-size:.7rem;color:var(--txt-m);margin-top:.2rem;text-transform:uppercase;letter-spacing:.07em">CA du mois</div>
                </div>
                <div style="text-align:center;padding:.75rem;background:var(--bg-ov);border-radius:9px">
                    <div style="font-size:1.5rem;font-weight:700;color:#fbbf24;font-family:'IBM Plex Mono',monospace">$<?php echo number_format($totalExpenses,0,'.',','); ?></div>
                    <div style="font-size:.7rem;color:var(--txt-m);margin-top:.2rem;text-transform:uppercase;letter-spacing:.07em">Dépenses totales</div>
                </div>
                <div style="text-align:center;padding:.75rem;background:var(--bg-ov);border-radius:9px">
                    <div style="font-size:1.2rem;font-weight:700;color:#c084fc;font-family:'IBM Plex Mono',monospace">$<?php echo number_format($totalSams,0,'.',','); ?></div>
                    <div style="font-size:.7rem;color:var(--txt-m);margin-top:.2rem;text-transform:uppercase;letter-spacing:.07em">CA SAMS</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Synthèse financière de l'État -->
<div class="card">
    <div class="card-title"><i class="fas fa-university"></i> Synthèse financière — État de Los Santos</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem">
        <?php
        $rows = [
            ['label'=>'Recettes fiscales (impôts)',    'val'=>$glob['total_impots'],  'color'=>'#fbbf24', 'icon'=>'fa-coins'],
            ['label'=>'CA total privé',                 'val'=>$glob['total_ca'],      'color'=>'var(--gov-l)', 'icon'=>'fa-chart-line'],
            ['label'=>'Bénéfices privés nets',          'val'=>$glob['total_b_net'],   'color'=>'#4ade80', 'icon'=>'fa-money-bill-trend-up'],
            ['label'=>'Trésoreries privées cumulées',   'val'=>$glob['total_treso'],   'color'=>'#93c5fd', 'icon'=>'fa-piggy-bank'],
            ['label'=>'Charges d\'exploitation',        'val'=>$glob['total_charges'], 'color'=>'#f87171', 'icon'=>'fa-receipt'],
            ['label'=>'Factures SAMS (medical)',        'val'=>$totalSams,             'color'=>'#f43f5e', 'icon'=>'fa-kit-medical'],
        ];
        foreach($rows as $r): ?>
        <div style="background:var(--bg-ov);border-radius:10px;padding:1rem;border:1px solid var(--bdr)">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem">
                <i class="fas <?php echo $r['icon']; ?>" style="color:<?php echo $r['color']; ?>;width:16px"></i>
                <span style="font-size:.7rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.07em"><?php echo $r['label']; ?></span>
            </div>
            <div style="font-size:1.35rem;font-weight:700;color:<?php echo $r['color']; ?>;font-family:'IBM Plex Mono',monospace">
                $<?php echo number_format($r['val'],2,'.',','); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>