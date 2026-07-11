<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = '🔫 Ammunation';
$companyColor   = '#dc2626';
$companyFaction = 'AMMUNATION';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Facturation';
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

// MODIFIER STATUT
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_status' && $canManage) {
    try {
        $db->prepare("UPDATE company_invoices SET status=? WHERE id=? AND faction=?")
           ->execute([$_POST['status'], intval($_POST['invoice_id']), $companyFaction]);
        $msg='Statut mis à jour.'; $msgType='success';
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// SUPPRIMER
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete' && $canManage) {
    try {
        $db->prepare("DELETE FROM company_invoices WHERE id=? AND faction=?")
           ->execute([intval($_POST['invoice_id']), $companyFaction]);
        $msg='Facture supprimée.'; $msgType='success';
    } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='error'; }
}

// FILTRES
$search = $_GET['q'] ?? '';
$status = $_GET['status'] ?? 'all';
$date   = $_GET['date']   ?? 'all';

$q = "SELECT ci.*, u.username as emp, u.faction_rank as emp_rank
      FROM company_invoices ci
      JOIN users u ON ci.employee_id=u.id
      WHERE ci.faction=?";
$p = [$companyFaction];

if ($search) { $q.=" AND (ci.customer_name LIKE ? OR u.username LIKE ?)"; $p[]="%{$search}%"; $p[]="%{$search}%"; }
if ($status!=='all') { $q.=" AND ci.status=?"; $p[]=$status; }
if ($date==='today') $q.=" AND DATE(ci.created_at)=CURDATE()";
elseif ($date==='week')  $q.=" AND YEARWEEK(ci.created_at,1)=YEARWEEK(CURDATE(),1)";
elseif ($date==='month') $q.=" AND MONTH(ci.created_at)=MONTH(CURDATE()) AND YEAR(ci.created_at)=YEAR(CURDATE())";
$q.=" ORDER BY ci.created_at DESC LIMIT 100";

$stmt=$db->prepare($q); $stmt->execute($p); $invoices=$stmt->fetchAll();

// Stats mois
$stmt=$db->prepare("
    SELECT COUNT(*) as total,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status='paid' THEN final_amount ELSE 0 END) as total_paid,
    SUM(CASE WHEN status='pending' THEN final_amount ELSE 0 END) as total_pending
    FROM company_invoices WHERE faction=?
    AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
");
$stmt->execute([$companyFaction]); $stats=$stmt->fetch();

include __DIR__ . '/includes/header.php';
?>
<style>
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.75rem}
.scard{background:rgba(13,21,38,.8);border:1px solid rgba(220,38,38,.14);border-radius:12px;padding:1.25rem;text-align:center}
.scard-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:.4rem}
.scard-val{font-size:1.6rem;font-weight:900;color:#f1f5f9}
.scard-sub{font-size:.75rem;color:#475569;margin-top:.2rem}

.filters{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem}
.filters form{display:flex;gap:.75rem;flex-wrap:wrap;flex:1}
.filter-inp{padding:.65rem 1rem;background:#0a0f1e;border:1px solid rgba(220,38,38,.18);border-radius:8px;color:#e2e8f0;font-size:.875rem;font-family:inherit}
.filter-inp:focus{outline:none;border-color:var(--cc)}
.filter-inp option{background:#0d1526}
.f-search{flex:1;min-width:200px}

.inv-detail{font-size:.78rem;color:#64748b;white-space:pre-line;max-width:280px;line-height:1.4}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-row">
    <div class="scard">
        <div class="scard-lbl">Factures (mois)</div>
        <div class="scard-val"><?php echo $stats['total']??0; ?></div>
    </div>
    <div class="scard">
        <div class="scard-lbl">Payées</div>
        <div class="scard-val" style="color:#4ade80"><?php echo $stats['paid']??0; ?></div>
        <div class="scard-sub">$<?php echo number_format($stats['total_paid']??0,0); ?></div>
    </div>
    <div class="scard">
        <div class="scard-lbl">En attente</div>
        <div class="scard-val" style="color:#fcd34d"><?php echo $stats['pending']??0; ?></div>
        <div class="scard-sub">$<?php echo number_format($stats['total_pending']??0,0); ?></div>
    </div>
    <div class="scard">
        <div class="scard-lbl">CA Facturé (mois)</div>
        <div class="scard-val" style="color:#93c5fd">$<?php echo number_format(($stats['total_paid']??0)+($stats['total_pending']??0),0); ?></div>
    </div>
</div>

<!-- Filtres -->
<div class="filters">
    <form method="GET">
        <input type="text" name="q" class="filter-inp f-search" placeholder="Rechercher client, vendeur…" value="<?php echo htmlspecialchars($search); ?>">
        <select name="status" class="filter-inp" onchange="this.form.submit()">
            <option value="all" <?php echo $status==='all'?'selected':''; ?>>Tous les statuts</option>
            <option value="paid" <?php echo $status==='paid'?'selected':''; ?>>Payées</option>
            <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>En attente</option>
            <option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>Annulées</option>
        </select>
        <select name="date" class="filter-inp" onchange="this.form.submit()">
            <option value="all" <?php echo $date==='all'?'selected':''; ?>>Toutes dates</option>
            <option value="today" <?php echo $date==='today'?'selected':''; ?>>Aujourd'hui</option>
            <option value="week" <?php echo $date==='week'?'selected':''; ?>>Cette semaine</option>
            <option value="month" <?php echo $date==='month'?'selected':''; ?>>Ce mois</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
    </form>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th><th>Date</th><th>Client</th><th>Vendeur</th>
                <th>Détails</th><th>Montant</th><th>Statut</th>
                <?php if($canManage): ?><th>Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($invoices)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:#475569">Aucune facture trouvée</td></tr>
        <?php else: ?>
        <?php foreach($invoices as $inv):
            $statusLabel=['paid'=>'Payée','pending'=>'En attente','cancelled'=>'Annulée'][$inv['status']]??$inv['status'];
            $statusCls=['paid'=>'badge-green','pending'=>'badge-yellow','cancelled'=>'badge-gray'][$inv['status']]??'badge-gray';
        ?>
        <tr>
            <td class="mono" style="color:#475569">#<?php echo $inv['id']; ?></td>
            <td style="color:#94a3b8;font-size:.8rem"><?php echo date('d/m/y H:i', strtotime($inv['created_at'])); ?></td>
            <td style="font-weight:600"><?php echo htmlspecialchars($inv['customer_name']??'—'); ?></td>
            <td style="color:#94a3b8"><?php echo htmlspecialchars($inv['emp']); ?></td>
            <td><div class="inv-detail"><?php echo htmlspecialchars(mb_substr($inv['description']??'',0,120).(strlen($inv['description']??'')>120?'…':'')); ?></div></td>
            <td style="font-weight:800;color:#4ade80">$<?php echo number_format($inv['final_amount'],2); ?></td>
            <td><span class="badge <?php echo $statusCls; ?>"><?php echo $statusLabel; ?></span></td>
            <?php if($canManage): ?>
            <td>
                <div style="display:flex;gap:.35rem">
                    <?php if($inv['status']==='pending'): ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                        <input type="hidden" name="status" value="paid">
                        <button type="submit" class="btn btn-sm btn-secondary" title="Marquer payée"><i class="fas fa-check"></i></button>
                    </form>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="btn btn-sm btn-danger" title="Annuler"><i class="fas fa-ban"></i></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" data-confirm="Supprimer cette facture ?">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>