<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Licences Commerciales';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$TYPES = ['commerce'=>'Commerce général','restauration'=>'Restauration','alcool'=>'Débit de boissons','armes'=>'Vente d\'armes','transport'=>'Transport','media'=>'Média / Presse','immobilier'=>'Immobilier','securite'=>'Sécurité privée','autre'=>'Autre'];
$TYPE_ICONS = ['commerce'=>'fa-store','restauration'=>'fa-utensils','alcool'=>'fa-martini-glass','armes'=>'fa-gun','transport'=>'fa-truck','media'=>'fa-newspaper','immobilier'=>'fa-building','securite'=>'fa-shield-halved','autre'=>'fa-certificate'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $db->prepare("
                INSERT INTO gouv_licences (faction, business_name, licence_type, statut, granted_by, expires_at, fee_amount, fee_paid, conditions, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                trim($_POST['faction']) ?: null,
                trim($_POST['business_name']),
                $_POST['licence_type'],
                'active',
                $_SESSION['user_id'],
                !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
                floatval($_POST['fee_amount'] ?? 0),
                isset($_POST['fee_paid']) ? 1 : 0,
                trim($_POST['conditions'] ?? ''),
                trim($_POST['notes'] ?? ''),
            ]);
            $msg='Licence accordée.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'update_statut') {
        $db->prepare("UPDATE gouv_licences SET statut=? WHERE id=?")->execute([$_POST['statut'], intval($_POST['id'])]);
        $msg='Statut mis à jour.'; $msgType='ok';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_licences WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Licence supprimée.'; $msgType='ok';
    }
}

$filterType   = $_GET['type']   ?? 'all';
$filterStatut = $_GET['statut'] ?? 'all';
$search       = trim($_GET['q'] ?? '');

$q = "SELECT l.*, u.username AS granted_by_name,
             COALESCE(f.name,l.faction) AS faction_name,
             COALESCE(f.color,'#3b82f6') AS faction_color
      FROM gouv_licences l
      LEFT JOIN users u ON u.id=l.granted_by
      LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = l.faction
      WHERE 1=1";
$p = [];
if ($filterType   !== 'all') { $q .= " AND l.licence_type=?"; $p[]=$filterType; }
if ($filterStatut !== 'all') { $q .= " AND l.statut=?";       $p[]=$filterStatut; }
if ($search) { $q .= " AND (l.business_name LIKE ? OR l.faction LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; }
$q .= " ORDER BY l.created_at DESC";
$stmt=$db->prepare($q); $stmt->execute($p); $licences=$stmt->fetchAll();

// Stats
$stats = $db->query("SELECT statut, COUNT(*) as n FROM gouv_licences GROUP BY statut")->fetchAll(\PDO::FETCH_KEY_PAIR);
$totalFees = (float)$db->query("SELECT COALESCE(SUM(fee_amount),0) FROM gouv_licences WHERE fee_paid=1")->fetchColumn();

$allFactions = $db->query("SELECT faction_key, name FROM factions ORDER BY name")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.4rem">
    <div class="kpi"><div class="kpi-lbl">Actives</div><div class="kpi-val green"><?php echo $stats['active']??0; ?></div></div>
    <div class="kpi"><div class="kpi-lbl">Suspendues</div><div class="kpi-val yellow"><?php echo $stats['suspendue']??0; ?></div></div>
    <div class="kpi"><div class="kpi-lbl">Révoquées</div><div class="kpi-val red"><?php echo $stats['revoquee']??0; ?></div></div>
    <div class="kpi"><div class="kpi-lbl">Total</div><div class="kpi-val"><?php echo array_sum($stats); ?></div></div>
    <div class="kpi"><div class="kpi-lbl">Frais encaissés</div><div class="kpi-val gold">$<?php echo number_format($totalFees,0,'.',','); ?></div></div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;flex:1">
        <input type="text" name="q" class="form-input" placeholder="Entreprise, faction…" value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:180px">
        <select name="type" class="form-select" style="width:180px" onchange="this.form.submit()">
            <option value="all">Tous types</option>
            <?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterType===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
        </select>
        <select name="statut" class="form-select" style="width:140px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <option value="active"    <?php echo $filterStatut==='active'?'selected':''; ?>>Active</option>
            <option value="suspendue" <?php echo $filterStatut==='suspendue'?'selected':''; ?>>Suspendue</option>
            <option value="revoquee"  <?php echo $filterStatut==='revoquee'?'selected':''; ?>>Révoquée</option>
            <option value="expiree"   <?php echo $filterStatut==='expiree'?'selected':''; ?>>Expirée</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <a href="licences.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <button onclick="Gov.openModal('newLicenceModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle licence</button>
</div>

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Établissement</th><th>Type</th><th>Faction</th><th>Accordée par</th><th>Expire</th><th>Frais</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($licences)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune licence</td></tr>
        <?php else: ?>
        <?php foreach($licences as $l):
            $icon = $TYPE_ICONS[$l['licence_type']] ?? 'fa-certificate';
            $stCls = ['active'=>'bg-green','suspendue'=>'bg-yellow','revoquee'=>'bg-red','expiree'=>'bg-gray'][$l['statut']] ?? 'bg-gray';
            $stLbl = ['active'=>'Active','suspendue'=>'Suspendue','revoquee'=>'Révoquée','expiree'=>'Expirée'][$l['statut']] ?? $l['statut'];
            $col = $l['faction_color'];
        ?>
        <tr>
            <td>
                <div style="font-weight:700;font-size:.845rem"><?php echo htmlspecialchars($l['business_name']); ?></div>
                <?php if($l['conditions']): ?><div style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars(mb_substr($l['conditions'],0,60)); ?></div><?php endif; ?>
            </td>
            <td>
                <span style="font-size:.78rem;color:var(--txt-s)">
                    <i class="fas <?php echo $icon; ?>" style="color:var(--gov-l);margin-right:.35rem"></i>
                    <?php echo $TYPES[$l['licence_type']] ?? $l['licence_type']; ?>
                </span>
            </td>
            <td>
                <?php if($l['faction']): ?>
                <span class="faction-chip" style="background:<?php echo $col; ?>18;color:<?php echo $col; ?>;border:1px solid <?php echo $col; ?>33">
                    <?php echo htmlspecialchars($l['faction_name']); ?>
                </span>
                <?php else: ?><span style="color:var(--txt-m)">—</span><?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($l['granted_by_name']??'—'); ?></td>
            <td style="font-size:.78rem;color:<?php echo ($l['expires_at'] && $l['expires_at']<date('Y-m-d'))?'#f87171':'var(--txt-m)'; ?>">
                <?php echo $l['expires_at'] ? date('d/m/Y',strtotime($l['expires_at'])) : '∞'; ?>
            </td>
            <td>
                <?php if($l['fee_amount']>0): ?>
                <span class="mono <?php echo $l['fee_paid']?'txt-green':'txt-red'; ?>" style="font-size:.82rem;font-weight:700">
                    $<?php echo number_format($l['fee_amount'],0,'.',','); ?>
                    <?php echo $l['fee_paid']?'✓':'(impayé)'; ?>
                </span>
                <?php else: ?><span style="color:var(--txt-m)">—</span><?php endif; ?>
            </td>
            <td><span class="badge <?php echo $stCls; ?>"><?php echo $stLbl; ?></span></td>
            <td>
                <div style="display:flex;gap:.35rem">
                    <?php if($l['statut']==='active'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $l['id']; ?>"><input type="hidden" name="statut" value="suspendue"><button type="submit" class="btn btn-warning btn-sm" title="Suspendre"><i class="fas fa-pause"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $l['id']; ?>"><input type="hidden" name="statut" value="revoquee"><button type="submit" class="btn btn-danger btn-sm" title="Révoquer"><i class="fas fa-ban"></i></button></form>
                    <?php elseif($l['statut']==='suspendue'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="update_statut"><input type="hidden" name="id" value="<?php echo $l['id']; ?>"><input type="hidden" name="statut" value="active"><button type="submit" class="btn btn-secondary btn-sm" title="Réactiver"><i class="fas fa-play"></i></button></form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $l['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button></form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL NOUVELLE LICENCE -->
<div id="newLicenceModal" class="modal-ov hidden">
    <div class="modal">
        <div class="modal-hd"><h2><i class="fas fa-id-card"></i> Nouvelle Licence</h2><button class="modal-cl" onclick="Gov.closeModal('newLicenceModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Nom de l'établissement *</label><input type="text" name="business_name" class="form-input" required placeholder="Ex: Ammunation — Los Santos"></div>
                    <div class="form-group"><label class="form-label">Type de licence *</label><select name="licence_type" class="form-select" required><?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Faction liée</label><select name="faction" class="form-select"><option value="">— Aucune —</option><?php foreach($allFactions as $af): ?><option value="<?php echo $af['faction_key']; ?>"><?php echo htmlspecialchars($af['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Frais de licence ($)</label><input type="number" name="fee_amount" class="form-input" step="0.01" min="0" value="0"></div>
                    <div class="form-group"><label class="form-label">Expire le</label><input type="date" name="expires_at" class="form-input"></div>
                    <div class="form-group form-full"><label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;color:var(--txt-s);font-size:.875rem"><input type="checkbox" name="fee_paid" value="1" style="accent-color:var(--gov)"> Frais déjà payés</label></div>
                    <div class="form-group form-full"><label class="form-label">Conditions particulières</label><textarea name="conditions" class="form-textarea" placeholder="Restrictions, obligations…"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" placeholder="Notes confidentielles…"></textarea></div>
                </div>
            </div>
            <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newLicenceModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Accorder</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>