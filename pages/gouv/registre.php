<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Registre Civil';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$TYPES = ['naissance'=>'Naissance','deces'=>'Décès','mariage'=>'Mariage','divorce'=>'Divorce','adoption'=>'Adoption'];
$TYPE_ICONS = ['naissance'=>'fa-baby','deces'=>'fa-cross','mariage'=>'fa-rings-wedding','divorce'=>'fa-file-circle-xmark','adoption'=>'fa-people-roof'];
$TYPE_COLORS = ['naissance'=>'#4ade80','deces'=>'#94a3b8','mariage'=>'#f472b6','divorce'=>'#f87171','adoption'=>'#93c5fd'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        try {
            $year = date('Y');
            $type = $_POST['type'] ?? 'naissance';
            $stmt = $db->prepare("SELECT COUNT(*)+1 FROM gouv_registre WHERE YEAR(created_at)=? AND type=?");
            $stmt->execute([$year, $type]); $seq=$stmt->fetchColumn();
            $prefix = strtoupper(substr($type,0,3));
            $numero = "REG-{$prefix}-{$year}-".str_pad($seq,4,'0',STR_PAD_LEFT);

            $db->prepare("INSERT INTO gouv_registre (type, person_name, person_b_name, date_event, lieu, details, numero_acte, registered_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([
                   $type,
                   trim($_POST['person_name']),
                   trim($_POST['person_b_name'] ?? '') ?: null,
                   $_POST['date_event'],
                   trim($_POST['lieu'] ?? '') ?: null,
                   trim($_POST['details'] ?? '') ?: null,
                   $numero,
                   $_SESSION['user_id'],
               ]);
            $msg="Acte $numero enregistré."; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_registre WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Acte supprimé.'; $msgType='ok';
    }
}

$filterType = $_GET['type'] ?? 'all';
$search     = trim($_GET['q'] ?? '');

$q = "SELECT r.*, u.username AS registrar FROM gouv_registre r LEFT JOIN users u ON u.id=r.registered_by WHERE 1=1";
$p = [];
if ($filterType !== 'all') { $q .= " AND r.type=?"; $p[]=$filterType; }
if ($search) { $q .= " AND (r.person_name LIKE ? OR r.person_b_name LIKE ? OR r.numero_acte LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; $p[]="%$search%"; }
$q .= " ORDER BY r.date_event DESC, r.created_at DESC LIMIT 200";
$stmt=$db->prepare($q); $stmt->execute($p); $actes=$stmt->fetchAll();

$counts = $db->query("SELECT type, COUNT(*) as n FROM gouv_registre GROUP BY type")->fetchAll(\PDO::FETCH_ASSOC);
$countMap=[]; foreach($counts as $c) $countMap[$c['type']]=$c['n'];

include __DIR__ . '/includes/header.php';
?>
<style>
.reg-type-btn{display:flex;align-items:center;gap:.5rem;padding:.55rem 1.1rem;border-radius:9px;font-size:.8rem;font-weight:600;cursor:pointer;border:1px solid var(--bdr);background:transparent;color:var(--txt-s);text-decoration:none;transition:all .18s}
.reg-type-btn:hover,.reg-type-btn.on{color:#fff}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- Filtres par type -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="?type=all" class="reg-type-btn <?php echo $filterType==='all'?'on':''; ?>" style="<?php echo $filterType==='all'?'background:var(--gov);border-color:var(--gov);':''; ?>">
            <i class="fas fa-list"></i> Tous (<?php echo array_sum($countMap); ?>)
        </a>
        <?php foreach($TYPES as $k=>$v):
            $col = $TYPE_COLORS[$k]; $ico=$TYPE_ICONS[$k]; $n=$countMap[$k]??0;
        ?>
        <a href="?type=<?php echo $k; ?>" class="reg-type-btn <?php echo $filterType===$k?'on':''; ?>"
           style="<?php echo $filterType===$k?"background:{$col}22;border-color:{$col}55;color:{$col};":'' ?>">
            <i class="fas <?php echo $ico; ?>"></i> <?php echo $v; ?> (<?php echo $n; ?>)
        </a>
        <?php endforeach; ?>
    </div>
    <button onclick="Gov.openModal('newActeModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvel acte</button>
</div>

<!-- Recherche -->
<form method="GET" style="display:flex;gap:.6rem;margin-bottom:1.25rem">
    <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
    <input type="text" name="q" class="form-input" placeholder="Nom, n° d'acte…" value="<?php echo htmlspecialchars($search); ?>" style="flex:1">
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    <a href="?type=<?php echo $filterType; ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
</form>

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>N° Acte</th><th>Type</th><th>Personne(s)</th><th>Date</th><th>Lieu</th><th>Détails</th><th>Enregistré par</th><th></th></tr></thead>
        <tbody>
        <?php if(empty($actes)): ?>
        <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--txt-m)">Aucun acte</td></tr>
        <?php else: ?>
        <?php foreach($actes as $a):
            $tCol = $TYPE_COLORS[$a['type']] ?? '#94a3b8';
            $tIco = $TYPE_ICONS[$a['type']] ?? 'fa-file';
            $tLbl = $TYPES[$a['type']] ?? $a['type'];
        ?>
        <tr>
            <td class="mono" style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($a['numero_acte']??'—'); ?></td>
            <td>
                <span class="badge" style="background:<?php echo $tCol; ?>18;color:<?php echo $tCol; ?>;border:1px solid <?php echo $tCol; ?>33">
                    <i class="fas <?php echo $tIco; ?>"></i> <?php echo $tLbl; ?>
                </span>
            </td>
            <td>
                <div style="font-weight:700;font-size:.845rem"><?php echo htmlspecialchars($a['person_name']); ?></div>
                <?php if($a['person_b_name']): ?><div style="font-size:.75rem;color:var(--txt-m)">& <?php echo htmlspecialchars($a['person_b_name']); ?></div><?php endif; ?>
            </td>
            <td style="font-size:.8rem;color:var(--txt-s)"><?php echo date('d/m/Y',strtotime($a['date_event'])); ?></td>
            <td style="font-size:.78rem;color:var(--txt-m)"><?php echo htmlspecialchars($a['lieu']??'—'); ?></td>
            <td style="font-size:.78rem;color:var(--txt-m);max-width:180px"><?php echo htmlspecialchars(mb_substr($a['details']??'',0,80)); ?></td>
            <td style="font-size:.78rem;color:var(--txt-s)"><?php echo htmlspecialchars($a['registrar']??'—'); ?></td>
            <td>
                <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer cet acte ?')">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                    <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL NOUVEL ACTE -->
<div id="newActeModal" class="modal-ov hidden">
    <div class="modal">
        <div class="modal-hd"><h2><i class="fas fa-book-open"></i> Nouvel acte civil</h2><button class="modal-cl" onclick="Gov.closeModal('newActeModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Type *</label>
                        <select name="type" class="form-select" required onchange="updateActeForm(this.value)">
                            <?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Date de l'événement *</label><input type="date" name="date_event" class="form-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group form-full"><label class="form-label" id="nameLabel">Nom (personne principale) *</label><input type="text" name="person_name" class="form-input" required placeholder="Prénom NOM"></div>
                    <div class="form-group form-full" id="bNameGroup" style="display:none"><label class="form-label" id="bNameLabel">Second individu</label><input type="text" name="person_b_name" class="form-input" placeholder="Prénom NOM"></div>
                    <div class="form-group form-full"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" placeholder="Ex: Los Santos Medical Center"></div>
                    <div class="form-group form-full"><label class="form-label">Détails</label><textarea name="details" class="form-textarea" placeholder="Informations complémentaires…"></textarea></div>
                </div>
            </div>
            <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newActeModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer l'acte</button></div>
        </form>
    </div>
</div>

<script>
function updateActeForm(type) {
    const bg = document.getElementById('bNameGroup');
    const bl = document.getElementById('bNameLabel');
    const nl = document.getElementById('nameLabel');
    if (['mariage','divorce','adoption'].includes(type)) {
        bg.style.display='block';
        bl.textContent = type==='mariage'?'Second conjoint':type==='adoption'?'Parent adoptif':'Second conjoint';
        nl.textContent = type==='adoption'?'Enfant adopté *':'Premier conjoint *';
    } else { bg.style.display='none'; nl.textContent='Nom (personne concernée) *'; }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>