<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Facturation & Dépenses';
$db = Database::getInstance()->getConnection();

// Filtres
$factionFilter = $_GET['faction'] ?? 'all';
$typeFilter    = $_GET['type']    ?? 'all';   // invoices | sams | expenses | all
$dateFilter    = $_GET['date']    ?? 'all';
$statusFilter  = $_GET['status']  ?? 'all';
$search        = trim($_GET['q']  ?? '');

// ── Factures company_invoices ─────────────────────────────────────────────────
$qInv = "
    SELECT 'invoice' AS source_type,
           ci.id, ci.faction,
           COALESCE(f.name,ci.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6') AS faction_color,
           ci.customer_name AS party,
           u.username AS created_by_name,
           ci.final_amount AS amount,
           ci.status,
           ci.created_at,
           ci.description
    FROM company_invoices ci
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = ci.faction
    LEFT JOIN users u ON u.id=ci.employee_id
    WHERE 1=1
";
$pInv = [];
if ($factionFilter !== 'all') { $qInv .= " AND ci.faction=?"; $pInv[]=$factionFilter; }
if ($statusFilter  !== 'all') { $qInv .= " AND ci.status=?";  $pInv[]=$statusFilter; }
if ($dateFilter==='today')  $qInv .= " AND DATE(ci.created_at)=CURDATE()";
elseif ($dateFilter==='week') $qInv .= " AND YEARWEEK(ci.created_at,1)=YEARWEEK(CURDATE(),1)";
elseif ($dateFilter==='month') $qInv .= " AND MONTH(ci.created_at)=MONTH(CURDATE()) AND YEAR(ci.created_at)=YEAR(CURDATE())";
if ($search) { $qInv .= " AND (ci.customer_name LIKE ? OR u.username LIKE ?)"; $pInv[]="%$search%"; $pInv[]="%$search%"; }

// ── Factures SAMS ─────────────────────────────────────────────────────────────
$qSams = "
    SELECT 'sams' AS source_type,
           si.id, 'SAMS' AS faction,
           'SAMS' AS faction_name,
           '#f43f5e' AS faction_color,
           CONCAT('Patient #', si.patient_id) AS party,
           u.username AS created_by_name,
           si.total_amount AS amount,
           si.status,
           si.created_at,
           si.items AS description
    FROM sams_invoices si
    LEFT JOIN users u ON u.id=si.created_by
    WHERE 1=1
";
$pSams = [];
if ($statusFilter !== 'all') { $qSams .= " AND si.status=?"; $pSams[]=$statusFilter; }
if ($dateFilter==='today')   $qSams .= " AND DATE(si.created_at)=CURDATE()";
elseif ($dateFilter==='week')  $qSams .= " AND YEARWEEK(si.created_at,1)=YEARWEEK(CURDATE(),1)";
elseif ($dateFilter==='month') $qSams .= " AND MONTH(si.created_at)=MONTH(CURDATE()) AND YEAR(si.created_at)=YEAR(CURDATE())";

// ── Dépenses ──────────────────────────────────────────────────────────────────
$qExp = "
    SELECT 'expense' AS source_type,
           ce.id, ce.faction,
           COALESCE(f.name,ce.faction) AS faction_name,
           COALESCE(f.color,'#3b82f6') AS faction_color,
           ce.expense_name AS party,
           u.username AS created_by_name,
           ce.amount,
           CASE WHEN ce.is_tax_deductible=1 THEN 'deductible' ELSE 'non_deductible' END AS status,
           ce.created_at,
           ce.description
    FROM company_expenses ce
    LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = ce.faction
    LEFT JOIN users u ON u.id=ce.added_by
    WHERE 1=1
";
$pExp = [];
if ($factionFilter !== 'all') { $qExp .= " AND ce.faction=?"; $pExp[]=$factionFilter; }
if ($dateFilter==='today')   $qExp .= " AND DATE(ce.created_at)=CURDATE()";
elseif ($dateFilter==='week')  $qExp .= " AND YEARWEEK(ce.created_at,1)=YEARWEEK(CURDATE(),1)";
elseif ($dateFilter==='month') $qExp .= " AND MONTH(ce.created_at)=MONTH(CURDATE()) AND YEAR(ce.created_at)=YEAR(CURDATE())";
if ($search) { $qExp .= " AND ce.expense_name LIKE ?"; $pExp[]="%$search%"; }

// Combiner selon le filtre type
$rows = [];
if (in_array($typeFilter, ['all','invoices'])) {
    $s=$db->prepare($qInv); $s->execute($pInv); $rows = array_merge($rows, $s->fetchAll());
}
if (in_array($typeFilter, ['all','sams']) && $factionFilter==='all') {
    $s=$db->prepare($qSams); $s->execute($pSams); $rows = array_merge($rows, $s->fetchAll());
}
if (in_array($typeFilter, ['all','expenses'])) {
    $s=$db->prepare($qExp); $s->execute($pExp); $rows = array_merge($rows, $s->fetchAll());
}
usort($rows, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$rows = array_slice($rows, 0, 200);

// Stats globales (sans filtres)
$stmtStats = $db->query("
    SELECT
        (SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE status='paid') AS inv_paid,
        (SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE status='pending') AS inv_pending,
        (SELECT COALESCE(SUM(total_amount),0)  FROM sams_invoices   WHERE status='paid') AS sams_paid,
        (SELECT COALESCE(SUM(amount),0)         FROM company_expenses) AS exp_total,
        (SELECT COUNT(*) FROM company_invoices WHERE status='pending') AS nb_pending
");
$gs = $stmtStats->fetch();

// Factions pour le filtre
$factionList = $db->query("
    SELECT DISTINCT ci.faction COLLATE utf8mb4_unicode_ci AS fk,
           COALESCE(f.name COLLATE utf8mb4_unicode_ci, ci.faction) AS fname
    FROM company_invoices ci LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = ci.faction
    UNION
    SELECT ce.faction COLLATE utf8mb4_unicode_ci AS fk,
           COALESCE(f.name COLLATE utf8mb4_unicode_ci, ce.faction) AS fname
    FROM company_expenses ce LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = ce.faction
    ORDER BY fname
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<style>
.src-invoice{background:rgba(29,78,216,.08);color:var(--gov-l);border:1px solid rgba(29,78,216,.2)}
.src-sams   {background:rgba(244,63,94,.08);color:#fb7185;   border:1px solid rgba(244,63,94,.2)}
.src-expense{background:rgba(239,68,68,.08);color:#f87171;   border:1px solid rgba(239,68,68,.2)}
</style>

<!-- KPIs rapides -->
<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">
    <div class="kpi">
        <div class="kpi-lbl">Factures payées</div>
        <div class="kpi-val green">$<?php echo number_format($gs['inv_paid'],0,'.',','); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Factures en attente</div>
        <div class="kpi-val yellow">$<?php echo number_format($gs['inv_pending'],0,'.',','); ?></div>
        <div class="kpi-sub"><?php echo $gs['nb_pending']; ?> facture(s)</div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">SAMS encaissé</div>
        <div class="kpi-val" style="color:#fb7185">$<?php echo number_format($gs['sams_paid'],0,'.',','); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Dépenses totales</div>
        <div class="kpi-val red">$<?php echo number_format($gs['exp_total'],0,'.',','); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-lbl">Lignes affichées</div>
        <div class="kpi-val"><?php echo count($rows); ?></div>
        <div class="kpi-sub">sur 200 max</div>
    </div>
</div>

<!-- Filtres -->
<form method="GET" style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1rem 1.25rem;margin-bottom:1.4rem">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="flex:2;min-width:180px">
            <label class="form-label">Recherche</label>
            <input type="text" name="q" class="form-input" placeholder="Client, vendeur, libellé…" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Entreprise</label>
            <select name="faction" class="form-select">
                <option value="all">Toutes</option>
                <?php foreach($factionList as $fl): ?>
                <option value="<?php echo $fl['fk']; ?>" <?php echo $factionFilter===$fl['fk']?'selected':''; ?>>
                    <?php echo htmlspecialchars($fl['fname']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
                <option value="all" <?php echo $typeFilter==='all'?'selected':''; ?>>Tout</option>
                <option value="invoices" <?php echo $typeFilter==='invoices'?'selected':''; ?>>Factures</option>
                <option value="sams" <?php echo $typeFilter==='sams'?'selected':''; ?>>SAMS</option>
                <option value="expenses" <?php echo $typeFilter==='expenses'?'selected':''; ?>>Dépenses</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Période</label>
            <select name="date" class="form-select">
                <option value="all" <?php echo $dateFilter==='all'?'selected':''; ?>>Toutes dates</option>
                <option value="today" <?php echo $dateFilter==='today'?'selected':''; ?>>Aujourd'hui</option>
                <option value="week" <?php echo $dateFilter==='week'?'selected':''; ?>>Cette semaine</option>
                <option value="month" <?php echo $dateFilter==='month'?'selected':''; ?>>Ce mois</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Statut</label>
            <select name="status" class="form-select">
                <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>Tous</option>
                <option value="paid" <?php echo $statusFilter==='paid'?'selected':''; ?>>Payé</option>
                <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>En attente</option>
                <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Annulé</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
        <a href="facturation.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Reset</a>
    </div>
</form>

<!-- Table -->
<div class="tbl-wrap">
    <table class="tbl">
        <thead>
            <tr>
                <th>Type</th><th>Date</th><th>Entreprise</th>
                <th>Partie / Libellé</th><th>Créé par</th>
                <th>Montant</th><th>Statut</th><th>Détails</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune ligne pour ces filtres</td></tr>
        <?php else: ?>
        <?php foreach($rows as $row):
            $srcLabel = match($row['source_type']) {
                'invoice' => ['label'=>'Facture',  'cls'=>'src-invoice', 'icon'=>'fa-file-invoice-dollar'],
                'sams'    => ['label'=>'SAMS',     'cls'=>'src-sams',    'icon'=>'fa-kit-medical'],
                'expense' => ['label'=>'Dépense',  'cls'=>'src-expense', 'icon'=>'fa-receipt'],
                default   => ['label'=>'—',        'cls'=>'bg-gray',     'icon'=>'fa-question'],
            };
            $stCls = match($row['status']) {
                'paid','deductible'         => 'bg-green',
                'pending','non_deductible'  => 'bg-yellow',
                'cancelled'                 => 'bg-gray',
                default                     => 'bg-gray',
            };
            $stLabel = match($row['status']) {
                'paid'           => 'Payée',
                'pending'        => 'En attente',
                'cancelled'      => 'Annulée',
                'deductible'     => 'Déductible',
                'non_deductible' => 'Non déductible',
                default          => $row['status'],
            };
            $col = $row['faction_color'];
        ?>
        <tr>
            <td><span class="badge <?php echo $srcLabel['cls']; ?>"><i class="fas <?php echo $srcLabel['icon']; ?>"></i> <?php echo $srcLabel['label']; ?></span></td>
            <td style="font-size:.75rem;color:var(--txt-m)"><?php echo date('d/m/y H:i',strtotime($row['created_at'])); ?></td>
            <td>
                <span class="faction-chip" style="background:<?php echo $col; ?>18;color:<?php echo $col; ?>;border:1px solid <?php echo $col; ?>33">
                    <?php echo htmlspecialchars($row['faction_name']); ?>
                </span>
            </td>
            <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?php echo htmlspecialchars($row['party'] ?? '—'); ?>
            </td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($row['created_by_name'] ?? '—'); ?></td>
            <td class="mono" style="font-weight:700;color:<?php echo $row['source_type']==='expense'?'#f87171':'#4ade80'; ?>">
                <?php echo $row['source_type']==='expense' ? '−' : ''; ?>$<?php echo number_format($row['amount'],2,'.',','); ?>
            </td>
            <td><span class="badge <?php echo $stCls; ?>"><?php echo $stLabel; ?></span></td>
            <td style="font-size:.72rem;color:var(--txt-m);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?php
                $desc = $row['description'] ?? '';
                // Si JSON (SAMS items)
                if ($row['source_type']==='sams') {
                    $items = json_decode($desc, true);
                    if ($items) $desc = implode(', ', array_column($items,'name'));
                }
                echo htmlspecialchars(mb_substr($desc,0,80));
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>