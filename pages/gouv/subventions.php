<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Subventions Publiques';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$CATS = ['securite'=>'Sécurité','sante'=>'Santé','transport'=>'Transport','culture'=>'Culture','economic'=>'Économie','social'=>'Social','autre'=>'Autre'];
$CAT_COLORS = ['securite'=>'#3b82f6','sante'=>'#f43f5e','transport'=>'#f59e0b','culture'=>'#a855f7','economic'=>'#22c55e','social'=>'#06b6d4','autre'=>'#64748b'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        try {
            $db->prepare("INSERT INTO gouv_subventions (faction, beneficiaire, categorie, amount, objet, statut, requested_by, budget_year) VALUES (?,?,?,?,?,'demande',?,?)")
               ->execute([trim($_POST['faction'])??null, trim($_POST['beneficiaire']), $_POST['categorie'], floatval($_POST['amount']), trim($_POST['objet']), $_SESSION['user_id'], intval($_POST['budget_year']??date('Y'))]);
            $msg='Demande de subvention enregistrée.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }
    if ($action === 'approve') {
        $db->prepare("UPDATE gouv_subventions SET statut='approuvee', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$_SESSION['user_id'], intval($_POST['id'])]);
        $msg='Subvention approuvée.'; $msgType='ok';
    }
    if ($action === 'pay') {
        $db->prepare("UPDATE gouv_subventions SET statut='versee', paid_at=NOW() WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Subvention versée.'; $msgType='ok';
    }
    if ($action === 'refuse') {
        $db->prepare("UPDATE gouv_subventions SET statut='refusee' WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Subvention refusée.'; $msgType='ok';
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_subventions WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Supprimé.'; $msgType='ok';
    }
}

$year = intval($_GET['year'] ?? date('Y'));
$filterStatut = $_GET['statut'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$q = "SELECT s.*, u.username AS req_name, a.username AS appr_name,
             COALESCE(f.name,s.faction) AS faction_name,
             COALESCE(f.color,'#3b82f6') AS faction_color
      FROM gouv_subventions s
      LEFT JOIN users u ON u.id=s.requested_by
      LEFT JOIN users a ON a.id=s.approved_by
      LEFT JOIN factions f ON f.faction_key COLLATE utf8mb4_unicode_ci = s.faction
      WHERE s.budget_year=?";
$p = [$year];
if ($filterStatut !== 'all') { $q .= " AND s.statut=?"; $p[]=$filterStatut; }
if ($search) { $q .= " AND (s.beneficiaire LIKE ? OR s.objet LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; }
$q .= " ORDER BY s.created_at DESC";
$stmt=$db->prepare($q); $stmt->execute($p); $subs=$stmt->fetchAll();

$totals = $db->prepare("SELECT statut, COUNT(*) as n, COALESCE(SUM(amount),0) as total FROM gouv_subventions WHERE budget_year=? GROUP BY statut");
$totals->execute([$year]); $totMap=[];
foreach($totals->fetchAll() as $t) $totMap[$t['statut']]=['n'=>$t['n'],'total'=>$t['total']];

$allFactions = $db->query("SELECT faction_key, name FROM factions ORDER BY name")->fetchAll();
$years = range(date('Y'), date('Y')-3);

include __DIR__ . '/includes/header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="kpi-row" style="margin-bottom:1.4rem">
    <?php foreach(['demande'=>['Demandes','blue'],'approuvee'=>['Approuvées','yellow'],'versee'=>['Versées','green'],'refusee'=>['Refusées','red']] as $s=>[$l,$c]): ?>
    <div class="kpi">
        <div class="kpi-lbl"><?php echo $l; ?></div>
        <div class="kpi-val <?php echo $c; ?>"><?php echo $totMap[$s]['n']??0; ?></div>
        <div class="kpi-sub">$<?php echo number_format($totMap[$s]['total']??0,0,'.',','); ?></div>
    </div>
    <?php endforeach; ?>
    <div class="kpi">
        <div class="kpi-lbl">Budget versé <?php echo $year; ?></div>
        <div class="kpi-val gold">$<?php echo number_format($totMap['versee']['total']??0,0,'.',','); ?></div>
        <div class="kpi-sub">année en cours</div>
    </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;flex:1">
        <select name="year" class="form-select" style="width:90px" onchange="this.form.submit()">
            <?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $year==$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="q" class="form-input" placeholder="Bénéficiaire, objet…" value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:160px">
        <select name="statut" class="form-select" style="width:145px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <option value="demande"   <?php echo $filterStatut==='demande'?'selected':''; ?>>Demandes</option>
            <option value="approuvee" <?php echo $filterStatut==='approuvee'?'selected':''; ?>>Approuvées</option>
            <option value="versee"    <?php echo $filterStatut==='versee'?'selected':''; ?>>Versées</option>
            <option value="refusee"   <?php echo $filterStatut==='refusee'?'selected':''; ?>>Refusées</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <button onclick="Gov.openModal('newSubModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle subvention</button>
</div>

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Bénéficiaire</th><th>Catégorie</th><th>Objet</th><th>Montant</th><th>Demandée par</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($subs)): ?>
        <tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucune subvention pour <?php echo $year; ?></td></tr>
        <?php else: ?>
        <?php foreach($subs as $s):
            $catCol = $CAT_COLORS[$s['categorie']] ?? '#64748b';
            $stCls  = ['demande'=>'bg-blue','approuvee'=>'bg-yellow','versee'=>'bg-green','refusee'=>'bg-red','annulee'=>'bg-gray'][$s['statut']] ?? 'bg-gray';
            $stLbl  = ['demande'=>'Demande','approuvee'=>'Approuvée','versee'=>'Versée','refusee'=>'Refusée','annulee'=>'Annulée'][$s['statut']] ?? $s['statut'];
            $col = $s['faction_color'];
        ?>
        <tr>
            <td>
                <div style="font-weight:700;font-size:.845rem"><?php echo htmlspecialchars($s['beneficiaire']); ?></div>
                <?php if($s['faction']): ?><span class="faction-chip" style="background:<?php echo $col; ?>18;color:<?php echo $col; ?>;border:1px solid <?php echo $col; ?>33;font-size:.68rem"><?php echo htmlspecialchars($s['faction_name']); ?></span><?php endif; ?>
            </td>
            <td><span class="badge" style="background:<?php echo $catCol; ?>18;color:<?php echo $catCol; ?>;border:1px solid <?php echo $catCol; ?>33"><?php echo $CATS[$s['categorie']]??$s['categorie']; ?></span></td>
            <td style="font-size:.8rem;color:var(--txt-s);max-width:220px"><?php echo htmlspecialchars(mb_substr($s['objet'],0,100)); ?></td>
            <td class="mono txt-gold" style="font-weight:700">$<?php echo number_format($s['amount'],2,'.',','); ?></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($s['req_name']??'—'); ?></td>
            <td><span class="badge <?php echo $stCls; ?>"><?php echo $stLbl; ?></span></td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <?php if($s['statut']==='demande'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button type="submit" class="btn btn-secondary btn-sm" title="Approuver"><i class="fas fa-check"></i></button></form>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="refuse"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button type="submit" class="btn btn-danger btn-sm" title="Refuser"><i class="fas fa-times"></i></button></form>
                    <?php elseif($s['statut']==='approuvee'): ?>
                    <form method="POST" style="margin:0"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-money-bill"></i> Verser</button></form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button></form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL NOUVELLE SUBVENTION -->
<div id="newSubModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd"><h2><i class="fas fa-hand-holding-dollar"></i> Nouvelle subvention</h2><button class="modal-cl" onclick="Gov.closeModal('newSubModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Bénéficiaire *</label><input type="text" name="beneficiaire" class="form-input" required placeholder="Ex: LSPD, SAMS, Citoyen…"></div>
                    <div class="form-group"><label class="form-label">Faction liée</label><select name="faction" class="form-select"><option value="">— Aucune —</option><?php foreach($allFactions as $af): ?><option value="<?php echo $af['faction_key']; ?>"><?php echo htmlspecialchars($af['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Catégorie *</label><select name="categorie" class="form-select" required><?php foreach($CATS as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Montant ($) *</label><input type="number" name="amount" class="form-input" step="0.01" min="0" required></div>
                    <div class="form-group"><label class="form-label">Année budgétaire</label><input type="number" name="budget_year" class="form-input" value="<?php echo date('Y'); ?>" min="2020"></div>
                    <div class="form-group form-full"><label class="form-label">Objet / Motif *</label><textarea name="objet" class="form-textarea" required placeholder="Décrire l'objet de la subvention…"></textarea></div>
                </div>
            </div>
            <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newSubModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Soumettre</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>