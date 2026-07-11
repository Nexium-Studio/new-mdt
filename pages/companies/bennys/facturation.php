<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyFaction = 'BENNYS';
$pageTitle      = 'Facturation';
$pageIcon       = 'file-invoice-dollar';

if ($_SESSION['user_faction'] !== $companyFaction &&
    !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'facturation.view')) faction_acl_deny();
$canCreate = faction_acl_can($db, $uid, $companyFaction, 'facturation.create');
$canEdit   = faction_acl_can($db, $uid, $companyFaction, 'facturation.edit');
$canDelete = faction_acl_can($db, $uid, $companyFaction, 'facturation.delete');
$canSend   = faction_acl_can($db, $uid, $companyFaction, 'facturation.send');
$canManage = $canCreate;

// Mise à jour statut
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status' && $canManage) {
    try {
        require_once __DIR__ . '/../../../includes/garage_vente_lib.php';
        $invoiceId = intval($_POST['invoice_id']);
        $newStatus = (string) $_POST['status'];
        $db->beginTransaction();
        garage_vente_on_invoice_status_change($db, $companyFaction, $invoiceId, $newStatus);
        $db->prepare('UPDATE company_invoices SET status=? WHERE id=? AND faction=?')
           ->execute([$newStatus, $invoiceId, $companyFaction]);
        $db->commit();
        $msg = 'Statut mis à jour.'; $msgType = 'success';
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

// Filtres
$filterStatus = $_GET['status'] ?? 'all';
$filterSearch = trim($_GET['search'] ?? '');
$page  = max(1, intval($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "ci.faction=?"; $params = [$companyFaction];
if ($filterStatus !== 'all') { $where .= " AND ci.status=?"; $params[] = $filterStatus; }
if ($filterSearch) { $where .= " AND (ci.customer_name LIKE ? OR u.username LIKE ?)"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }

$invoices = $db->prepare("
    SELECT ci.*, u.username as employee_name
    FROM company_invoices ci
    LEFT JOIN users u ON u.id = ci.employee_id
    WHERE $where ORDER BY ci.created_at DESC LIMIT $limit OFFSET $offset
");
$invoices->execute($params);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$totalCount = $db->prepare("SELECT COUNT(*) FROM company_invoices ci LEFT JOIN users u ON u.id=ci.employee_id WHERE $where");
$totalCount->execute($params);
$totalCount = (int)$totalCount->fetchColumn();
$totalPages = ceil($totalCount / $limit);

// Stats
$stats = $db->prepare("SELECT COUNT(*) as nb, COALESCE(SUM(final_amount),0) as total, status FROM company_invoices WHERE faction=? GROUP BY status");
$stats->execute([$companyFaction]);
$statsMap = ['paid'=>['nb'=>0,'total'=>0],'pending'=>['nb'=>0,'total'=>0],'cancelled'=>['nb'=>0,'total'=>0]];
foreach ($stats->fetchAll(PDO::FETCH_ASSOC) as $s) { if (isset($statsMap[$s['status']])) $statsMap[$s['status']] = $s; }

include __DIR__ . '/includes/header.php';
?>
<style>
/* ══ FACTURATION PAGE ═══════════════════════════════════════════════════════ */
.fact-layout { display:grid; grid-template-columns:1fr; gap:1.35rem; position:relative; z-index:1; }

/* Stats KPI */
.fact-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:.85rem; margin-bottom:1.4rem; }
.fkpi {
    background:var(--bg-c); border:1px solid var(--bdr);
    border-radius:10px; padding:.95rem 1.1rem;
    position:relative; overflow:hidden;
    transition: border-color var(--speed), transform var(--speed);
}
.fkpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:var(--fkc,var(--a)); }
.fkpi:hover { border-color:var(--bdr-h); transform:translateY(-2px); }
.fkpi-label { font-size:.59rem; font-weight:700; text-transform:uppercase; letter-spacing:.15em; color:var(--txt-s); margin-bottom:.3rem; }
.fkpi-val { font-size:1.45rem; font-weight:900; color:var(--txt); line-height:1; }
.fkpi-sub { font-size:.64rem; color:var(--txt-s); margin-top:.18rem; }

/* Toolbar */
.fact-toolbar {
    display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;
    margin-bottom:1.25rem;
}
.fact-search { flex:1; min-width:200px; max-width:340px; position:relative; }
.fact-search i { position:absolute; left:.78rem; top:50%; transform:translateY(-50%); color:var(--txt-s); font-size:.77rem; pointer-events:none; }
.fact-search input { width:100%; padding:.6rem .9rem .6rem 2.1rem; background:var(--bg-c); border:1px solid var(--bdr); border-radius:7px; color:var(--txt); font-family:inherit; font-size:.84rem; transition:all var(--speed); }
.fact-search input:focus { outline:none; border-color:var(--a); box-shadow:0 0 0 3px rgba(245,158,11,.1); }
.fact-search input::placeholder { color:var(--txt-m); }

.fact-filters { display:flex; gap:.45rem; flex-wrap:wrap; }
.filter-btn {
    padding:.38rem .85rem; border-radius:6px; border:1px solid var(--bdr);
    background:transparent; color:var(--txt-s); font-family:inherit; font-size:.76rem; font-weight:700;
    cursor:pointer; transition:all var(--speed); letter-spacing:.03em;
    text-decoration:none; display:inline-flex; align-items:center; gap:.35rem;
}
.filter-btn:hover { border-color:rgba(245,158,11,.3); color:var(--a-l); }
.filter-btn.active { background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.35); color:var(--a-l); }
.filter-btn.f-paid.active   { background:rgba(16,185,129,.1); border-color:rgba(16,185,129,.3); color:#6ee7b7; }
.filter-btn.f-pend.active   { background:rgba(251,191,36,.1); border-color:rgba(251,191,36,.3); color:#fde68a; }
.filter-btn.f-cancel.active { background:rgba(239,68,68,.1);  border-color:rgba(239,68,68,.3);  color:#fca5a5; }

/* Invoice table */
.fact-table-wrap { background:var(--bg-c); border:1px solid var(--bdr); border-radius:12px; overflow:hidden; }
.fact-table { width:100%; border-collapse:collapse; }
.fact-table th {
    text-align:left; padding:.72rem 1.1rem;
    background:rgba(245,158,11,.04); border-bottom:1px solid rgba(245,158,11,.12);
    font-size:.6rem; font-weight:800; text-transform:uppercase; letter-spacing:.13em; color:var(--txt-s);
    white-space:nowrap;
}
.fact-table td {
    padding:.7rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.025);
    font-size:.82rem;
    transition: background var(--speed);
}
.fact-table tbody tr:last-child td { border-bottom:none; }
.fact-table tbody tr { transition: background var(--speed); }
.fact-table tbody tr:hover td { background:rgba(245,158,11,.025); cursor:pointer; }

/* Status badges */
.st-paid     { background:rgba(16,185,129,.1); color:#6ee7b7; border:1px solid rgba(16,185,129,.2); padding:.18rem .55rem; border-radius:4px; font-size:.63rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
.st-pending  { background:rgba(251,191,36,.1); color:#fde68a; border:1px solid rgba(251,191,36,.2); padding:.18rem .55rem; border-radius:4px; font-size:.63rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
.st-cancelled{ background:rgba(239,68,68,.1);  color:#fca5a5; border:1px solid rgba(239,68,68,.2);  padding:.18rem .55rem; border-radius:4px; font-size:.63rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }

/* Amount display */
.amt-pos { color:#6ee7b7; font-weight:800; }
.amt-neg { color:#fca5a5; font-weight:800; }
.amt-main { font-size:.95rem; font-weight:900; font-variant-numeric:tabular-nums; }

/* Invoice detail modal */
.inv-desc { white-space:pre-wrap; font-size:.8rem; color:var(--txt-s); background:var(--bg); border:1px solid var(--bdr); border-radius:7px; padding:.85rem 1rem; line-height:1.6; font-family:'Consolas','Courier New',monospace; }

/* Pagination */
.pagination { display:flex; align-items:center; gap:.4rem; padding:1rem 1.1rem; border-top:1px solid var(--bdr); }
.pg-btn {
    display:flex; align-items:center; justify-content:center;
    width:30px; height:30px; border-radius:6px;
    border:1px solid var(--bdr); color:var(--txt-s); text-decoration:none;
    font-size:.78rem; font-weight:700; transition:all var(--speed);
}
.pg-btn:hover { border-color:rgba(245,158,11,.3); color:var(--a-l); }
.pg-btn.active { background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.35); color:var(--a-l); }
.pg-info { margin-left:auto; font-size:.72rem; color:var(--txt-s); }

/* Empty state */
.fact-empty { text-align:center; padding:4rem 2rem; color:var(--txt-s); }
.fact-empty i { font-size:3rem; opacity:.12; display:block; margin-bottom:1rem; color:var(--a); }

@media(max-width:900px) { .fact-stats { grid-template-columns:repeat(2,1fr); } }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- KPI -->
<div class="fact-stats">
    <div class="fkpi" style="--fkc:#10b981">
        <div class="fkpi-label">Factures payées</div>
        <div class="fkpi-val"><?php echo $statsMap['paid']['nb']; ?></div>
        <div class="fkpi-sub">$<?php echo number_format($statsMap['paid']['total'],0,',',' '); ?></div>
    </div>
    <div class="fkpi" style="--fkc:#fbbf24">
        <div class="fkpi-label">En attente</div>
        <div class="fkpi-val"><?php echo $statsMap['pending']['nb']; ?></div>
        <div class="fkpi-sub">$<?php echo number_format($statsMap['pending']['total'],0,',',' '); ?></div>
    </div>
    <div class="fkpi" style="--fkc:#ef4444">
        <div class="fkpi-label">Annulées</div>
        <div class="fkpi-val"><?php echo $statsMap['cancelled']['nb']; ?></div>
        <div class="fkpi-sub">$<?php echo number_format($statsMap['cancelled']['total'],0,',',' '); ?></div>
    </div>
    <div class="fkpi" style="--fkc:#f59e0b">
        <div class="fkpi-label">CA total</div>
        <div class="fkpi-val mono">$<?php echo number_format($statsMap['paid']['total'],0,',',' '); ?></div>
        <div class="fkpi-sub">toutes périodes</div>
    </div>
</div>

<!-- Toolbar -->
<div class="fact-toolbar">
    <div class="fact-search">
        <i class="fas fa-search"></i>
        <input type="text" id="srch" placeholder="Client ou employé…" value="<?php echo htmlspecialchars($filterSearch); ?>" oninput="debounceFilter(this.value)">
    </div>
    <div class="fact-filters">
        <?php
        $base = '?'; // keep other params
        $makeLink = fn($s,$lbl,$cls,$ic) => '<a href="?status='.$s.'&search='.urlencode($filterSearch).'" class="filter-btn '.$cls.($filterStatus===$s?' active':'').'">'
            .'<i class="fas fa-'.$ic.'"></i>'.$lbl.'</a>';
        echo $makeLink('all','Tout','','list');
        echo $makeLink('paid','Payées','f-paid','check-circle');
        echo $makeLink('pending','Attente','f-pend','clock');
        echo $makeLink('cancelled','Annulées','f-cancel','ban');
        ?>
    </div>
</div>

<!-- Table -->
<div class="fact-table-wrap">
    <?php if (empty($invoices)): ?>
    <div class="fact-empty">
        <i class="fas fa-file-invoice"></i>
        <p>Aucune facture trouvée.</p>
    </div>
    <?php else: ?>
    <table class="fact-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Client</th>
                <th>Employé</th>
                <th>Montant</th>
                <th>Ajustement</th>
                <th>Total</th>
                <th>Statut</th>
                <?php if ($canManage): ?><th>Action</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr onclick="openInvoice(<?php echo htmlspecialchars(json_encode($inv),ENT_QUOTES); ?>)">
            <td class="mono" style="color:var(--txt-s)">#<?php echo $inv['id']; ?></td>
            <td style="color:var(--txt-s);font-size:.76rem;white-space:nowrap"><?php echo date('d/m/y H:i', strtotime($inv['created_at'])); ?></td>
            <td style="font-weight:700"><?php echo htmlspecialchars($inv['customer_name']); ?></td>
            <td style="color:var(--txt-s)"><?php echo htmlspecialchars($inv['employee_name'] ?? '—'); ?></td>
            <td class="mono">$<?php echo number_format($inv['amount'],2,',','.'); ?></td>
            <td class="mono <?php echo $inv['discount']<0?'amt-neg':($inv['discount']>0?'amt-pos':''); ?>">
                <?php if ($inv['discount'] != 0): ?><?php echo ($inv['discount']>0?'+':'').'$'.number_format(abs($inv['discount']),2,',','.'); ?><?php else: ?>—<?php endif; ?>
            </td>
            <td class="mono amt-main amt-pos">$<?php echo number_format($inv['final_amount'],2,',','.'); ?></td>
            <td>
                <span class="st-<?php echo $inv['status']; ?>">
                    <?php echo match($inv['status']){'paid'=>'Payée','pending'=>'Attente','cancelled'=>'Annulée',default=>$inv['status']}; ?>
                </span>
            </td>
            <?php if ($canManage): ?>
            <td onclick="event.stopPropagation()">
                <form method="POST" style="display:flex;gap:.35rem">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                    <select name="status" class="form-select" style="font-size:.74rem;padding:.28rem .55rem;width:auto" onchange="this.form.submit()">
                        <option value="paid"      <?php echo $inv['status']==='paid'?'selected':'';     ?>>Payée</option>
                        <option value="pending"   <?php echo $inv['status']==='pending'?'selected':'';  ?>>Attente</option>
                        <option value="cancelled" <?php echo $inv['status']==='cancelled'?'selected':'';?>>Annulée</option>
                    </select>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i=1;$i<=$totalPages;$i++): ?>
        <a href="?status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>&p=<?php echo $i; ?>" class="pg-btn<?php echo $i===$page?' active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <span class="pg-info"><?php echo $totalCount; ?> facture<?php echo $totalCount>1?'s':''; ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Detail modal -->
<div id="invModal" class="modal-overlay hidden">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice-dollar"></i> Détail facture <span id="invNo" style="color:var(--a)"></span></h2>
            <button class="modal-close" onclick="closeModal('invModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-grid" style="margin-bottom:1.2rem">
                <div class="form-group"><div class="form-label">Client</div><div id="invClient" style="font-weight:800;font-size:1rem"></div></div>
                <div class="form-group"><div class="form-label">Employé</div><div id="invEmp" style="color:var(--txt-s)"></div></div>
                <div class="form-group"><div class="form-label">Date</div><div id="invDate" style="color:var(--txt-s);font-size:.85rem"></div></div>
                <div class="form-group"><div class="form-label">Total</div><div id="invTotal" style="font-size:1.3rem;font-weight:900;color:var(--a)"></div></div>
            </div>
            <div class="form-group">
                <div class="form-label">Détail</div>
                <div id="invDesc" class="inv-desc" style="min-height:2rem;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('invModal')">Fermer</button>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(SITE_URL . ASSETS_PATH); ?>/js/invoice-detail-view.js"></script>
<script>
function openModal(id){const el=document.getElementById(id);if(el)el.classList.remove('hidden');}
function closeModal(id){const el=document.getElementById(id);if(el)el.classList.add('hidden');}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.add('hidden');}));

function openInvoice(inv) {
    document.getElementById('invNo').textContent    = '#'+inv.id;
    document.getElementById('invClient').textContent= inv.customer_name||'—';
    document.getElementById('invEmp').textContent   = inv.employee_name||'—';
    document.getElementById('invDate').textContent  = inv.created_at||'';
    document.getElementById('invTotal').textContent = '$'+parseFloat(inv.final_amount).toLocaleString('fr-FR',{minimumFractionDigits:2});
    document.getElementById('invDesc').innerHTML = inv.description
        ? MDTInvoiceDetail.format(inv.description)
        : '—';
    openModal('invModal');
}

let debounceT;
function debounceFilter(v) {
    clearTimeout(debounceT);
    debounceT = setTimeout(() => {
        const url = new URL(window.location);
        url.searchParams.set('search', v);
        url.searchParams.set('p','1');
        window.location = url;
    }, 500);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>