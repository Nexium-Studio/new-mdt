<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Amendes Civiles';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$CATS = ['circulation'=>'Circulation','commerce'=>'Commerce','nuisance'=>'Nuisances','fraude'=>'Fraude','administrative'=>'Administrative','autre'=>'Autre'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $db->prepare("INSERT INTO gouv_amendes (citizen_name, citizen_id, amount, reason, category, statut, issued_by, due_date, notes) VALUES (?,?,?,?,?,'pending',?,?,?)")
               ->execute([
                   trim($_POST['citizen_name']),
                   !empty($_POST['citizen_id']) ? intval($_POST['citizen_id']) : null,
                   floatval($_POST['amount']),
                   trim($_POST['reason']),
                   $_POST['category'],
                   $_SESSION['user_id'],
                   !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                   trim($_POST['notes'] ?? ''),
               ]);
            $msg='Amende émise.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'update_statut') {
        $paid_at = $_POST['statut']==='paid' ? ', paid_at=NOW()' : '';
        $db->prepare("UPDATE gouv_amendes SET statut=?{$paid_at} WHERE id=?")->execute([$_POST['statut'], intval($_POST['id'])]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_amendes WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Amende supprimée.'; $msgType='ok';
    }
}

$filterCat    = $_GET['cat']    ?? 'all';
$filterStatut = $_GET['statut'] ?? 'all';
$search       = trim($_GET['q'] ?? '');

$q = "SELECT a.*, u.username AS issued_by_name FROM gouv_amendes a LEFT JOIN users u ON u.id=a.issued_by WHERE 1=1";
$p = [];
if ($filterCat    !== 'all') { $q .= " AND a.category=?"; $p[]=$filterCat; }
if ($filterStatut !== 'all') { $q .= " AND a.statut=?";   $p[]=$filterStatut; }
if ($search) { $q .= " AND a.citizen_name LIKE ?"; $p[]="%$search%"; }
$q .= " ORDER BY a.created_at DESC LIMIT 150";
$stmt=$db->prepare($q); $stmt->execute($p); $amendes=$stmt->fetchAll();

$stats = $db->query("
    SELECT statut, COUNT(*) as n, COALESCE(SUM(amount),0) as total
    FROM gouv_amendes GROUP BY statut
")->fetchAll(\PDO::FETCH_ASSOC);
$statMap = []; foreach($stats as $s) $statMap[$s['statut']] = $s;

include __DIR__ . '/includes/header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-row" style="margin-bottom:1.4rem">
    <?php foreach(['pending'=>['En attente','yellow'],'paid'=>['Payées','green'],'contested'=>['Contestées','blue'],'cancelled'=>['Annulées','gray']] as $s=>[$lbl,$col]): ?>
    <div class="kpi">
        <div class="kpi-lbl"><?php echo $lbl; ?></div>
        <div class="kpi-val <?php echo $col; ?>"><?php echo $statMap[$s]['n']??0; ?></div>
        <div class="kpi-sub">$<?php echo number_format($statMap[$s]['total']??0,0,'.',','); ?></div>
    </div>
    <?php endforeach; ?>
    <div class="kpi">
        <div class="kpi-lbl">Montant encaissé</div>
        <div class="kpi-val gold">$<?php echo number_format($statMap['paid']['total']??0,0,'.',','); ?></div>
        <div class="kpi-sub">amendes payées</div>
    </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;flex:1">
        <input type="text" name="q" class="form-input" placeholder="Nom du citoyen…" value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:180px">
        <select name="cat" class="form-select" style="width:160px" onchange="this.form.submit()">
            <option value="all">Toutes catégories</option>
            <?php foreach($CATS as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterCat===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
        </select>
        <select name="statut" class="form-select" style="width:140px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <option value="pending"   <?php echo $filterStatut==='pending'?'selected':''; ?>>En attente</option>
            <option value="paid"      <?php echo $filterStatut==='paid'?'selected':''; ?>>Payées</option>
            <option value="contested" <?php echo $filterStatut==='contested'?'selected':''; ?>>Contestées</option>
            <option value="cancelled" <?php echo $filterStatut==='cancelled'?'selected':''; ?>>Annulées</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <a href="amendes.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <button onclick="Gov.openModal('newAmendeModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Émettre une amende</button>
</div>

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Citoyen</th><th>Catégorie</th><th>Motif</th><th>Montant</th><th>Émise par</th><th>Échéance</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($amendes)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune amende</td></tr>
        <?php else: ?>
        <?php foreach($amendes as $a):
            $stCls = ['pending'=>'bg-yellow','paid'=>'bg-green','contested'=>'bg-blue','cancelled'=>'bg-gray'][$a['statut']] ?? 'bg-gray';
            $stLbl = ['pending'=>'En attente','paid'=>'Payée','contested'=>'Contestée','cancelled'=>'Annulée'][$a['statut']] ?? $a['statut'];
            $overdue = $a['due_date'] && $a['due_date']<date('Y-m-d') && $a['statut']==='pending';
        ?>
        <tr <?php echo $overdue?'style="background:rgba(239,68,68,.04)"':''; ?>>
            <td>
                <div style="font-weight:700;font-size:.845rem"><?php echo htmlspecialchars($a['citizen_name']); ?></div>
                <div style="font-size:.7rem;color:var(--txt-m)">Émise le <?php echo date('d/m/Y',strtotime($a['created_at'])); ?></div>
            </td>
            <td><span style="font-size:.78rem;color:var(--txt-s)"><?php echo $CATS[$a['category']]??$a['category']; ?></span></td>
            <td style="font-size:.8rem;max-width:220px;color:var(--txt-s)"><?php echo htmlspecialchars(mb_substr($a['reason'],0,80)); ?></td>
            <td class="mono txt-gold" style="font-weight:700">$<?php echo number_format($a['amount'],2,'.',','); ?></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($a['issued_by_name']??'—'); ?></td>
            <td style="font-size:.78rem;color:<?php echo $overdue?'#f87171':'var(--txt-m)'; ?>">
                <?php echo $a['due_date'] ? date('d/m/Y',strtotime($a['due_date'])).($overdue?' ⚠️':'') : '—'; ?>
            </td>
            <td><span class="badge <?php echo $stCls; ?>"><?php echo $stLbl; ?></span></td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <?php if($a['statut']==='pending'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><input type="hidden" name="statut" value="paid"><button type="submit" class="btn btn-secondary btn-sm" title="Marquer payée"><i class="fas fa-check"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><input type="hidden" name="statut" value="contested"><button type="submit" class="btn btn-ghost btn-sm" title="Contester"><i class="fas fa-comment-slash"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><input type="hidden" name="statut" value="cancelled"><button type="submit" class="btn btn-danger btn-sm" title="Annuler"><i class="fas fa-ban"></i></button></form>
                    <?php elseif($a['statut']==='contested'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><input type="hidden" name="statut" value="paid"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-check"></i> Valider</button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><input type="hidden" name="statut" value="cancelled"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i></button></form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button></form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL AMENDE -->
<div id="newAmendeModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd"><h2><i class="fas fa-gavel"></i> Émettre une amende</h2><button class="modal-cl" onclick="Gov.closeModal('newAmendeModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Nom du citoyen *</label><input type="text" name="citizen_name" class="form-input" required placeholder="Prénom NOM"></div>
                    <div class="form-group"><label class="form-label">Catégorie *</label><select name="category" class="form-select" required><?php foreach($CATS as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="amount" class="form-input" step="0.01" min="0" required></div>
                    <div class="form-group form-full"><label class="form-label">Date d'échéance</label><input type="date" name="due_date" class="form-input" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"></div>
                    <div class="form-group form-full"><label class="form-label">Motif *</label><textarea name="reason" class="form-textarea" required placeholder="Description de l'infraction…"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" placeholder="Notes internes…"></div>
                </div>
            </div>
            <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newAmendeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-gavel"></i> Émettre</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>