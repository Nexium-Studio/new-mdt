<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Alertes & Avis Publics';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$NIVEAUX = ['info'=>'Information','avertissement'=>'Avertissement','critique'=>'Critique','urgence'=>'Urgence'];
$NIV_COLORS = ['info'=>'#3b82f6','avertissement'=>'#f59e0b','critique'=>'#ef4444','urgence'=>'#dc2626'];
$NIV_ICONS  = ['info'=>'fa-circle-info','avertissement'=>'fa-triangle-exclamation','critique'=>'fa-circle-exclamation','urgence'=>'fa-siren-on'];
$CATS = ['securite'=>'Sécurité','sante'=>'Santé','meteo'=>'Météo','circulation'=>'Circulation','economie'=>'Économie','autre'=>'Autre'];
$CIBLES = ['tous'=>'Tous','lspd'=>'LSPD','sams'=>'SAMS','doj'=>'DOJ','entreprises'=>'Entreprises','citoyens'=>'Citoyens'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        try {
            $db->prepare("INSERT INTO gouv_alertes (titre, contenu, niveau, categorie, statut, cible, created_by, expires_at) VALUES (?,?,?,?,'active',?,?,?)")
               ->execute([
                   trim($_POST['titre']),
                   trim($_POST['contenu']),
                   $_POST['niveau'],
                   $_POST['categorie'],
                   $_POST['cible'],
                   $_SESSION['user_id'],
                   !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
               ]);
            $msg='Alerte publiée.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }
    if ($action === 'resolve') {
        $db->prepare("UPDATE gouv_alertes SET statut='resolue', resolved_by=?, resolved_at=NOW() WHERE id=?")->execute([$_SESSION['user_id'], intval($_POST['id'])]);
        $msg='Alerte résolue.'; $msgType='ok';
    }
    if ($action === 'cancel') {
        $db->prepare("UPDATE gouv_alertes SET statut='annulee' WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Alerte annulée.'; $msgType='ok';
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_alertes WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Supprimé.'; $msgType='ok';
    }
}

$filterNiv    = $_GET['niveau'] ?? 'all';
$filterStatut = $_GET['statut'] ?? 'active';

$q = "SELECT a.*, u.username AS author, r.username AS resolver
      FROM gouv_alertes a
      LEFT JOIN users u ON u.id=a.created_by
      LEFT JOIN users r ON r.id=a.resolved_by
      WHERE 1=1";
$p = [];
if ($filterNiv    !== 'all') { $q .= " AND a.niveau=?";  $p[]=$filterNiv; }
if ($filterStatut !== 'all') { $q .= " AND a.statut=?";  $p[]=$filterStatut; }
$q .= " ORDER BY FIELD(a.niveau,'urgence','critique','avertissement','info'), a.created_at DESC";
$stmt=$db->prepare($q); $stmt->execute($p); $alertes=$stmt->fetchAll();

$counts = $db->query("SELECT statut, COUNT(*) as n FROM gouv_alertes GROUP BY statut")->fetchAll(\PDO::FETCH_KEY_PAIR);
$active = $db->query("SELECT COUNT(*) FROM gouv_alertes WHERE statut='active'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>
<style>
.alerte-card{border-radius:12px;padding:1.25rem 1.4rem;margin-bottom:.85rem;border:2px solid;position:relative;overflow:hidden}
.alerte-card::before{content:'';position:absolute;top:0;left:0;width:5px;height:100%}
.alerte-info        {background:rgba(59,130,246,.07); border-color:rgba(59,130,246,.25)}  .alerte-info::before{background:#3b82f6}
.alerte-avertissement{background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.25)} .alerte-avertissement::before{background:#f59e0b}
.alerte-critique    {background:rgba(239,68,68,.07);  border-color:rgba(239,68,68,.3)}   .alerte-critique::before{background:#ef4444}
.alerte-urgence     {background:rgba(220,38,38,.12);  border-color:rgba(220,38,38,.45)}  .alerte-urgence::before{background:#dc2626;width:8px}
.alerte-resolue,.alerte-annulee{opacity:.5;filter:saturate(.4)}
.alerte-head{display:flex;align-items:flex-start;gap:1rem}
.alerte-ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.alerte-titre{font-weight:700;font-size:.95rem;color:#f1f5f9;margin-bottom:.25rem}
.alerte-meta{font-size:.72rem;color:var(--txt-m)}
.alerte-contenu{margin-top:.75rem;font-size:.845rem;line-height:1.65;color:var(--txt-s);background:rgba(0,0,0,.15);border-radius:8px;padding:.75rem 1rem;white-space:pre-wrap}
.alerte-actions{display:flex;gap:.5rem;margin-top:.85rem;flex-wrap:wrap}
.pulse{animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- KPIs + bouton -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:.6rem;background:var(--bg-c);border:1px solid rgba(220,38,38,.3);border-radius:10px;padding:.6rem 1.1rem">
            <span class="pulse" style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block"></span>
            <span style="font-weight:700;color:#f87171"><?php echo $active; ?> alerte(s) active(s)</span>
        </div>
        <?php foreach(['resolue'=>'Résolues','annulee'=>'Annulées'] as $s=>$l): ?>
        <div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:.6rem 1.1rem;color:var(--txt-m);font-size:.82rem">
            <?php echo $l; ?>: <strong style="color:var(--txt)"><?php echo $counts[$s]??0; ?></strong>
        </div>
        <?php endforeach; ?>
    </div>
    <button onclick="Gov.openModal('newAlerteModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle alerte</button>
</div>

<!-- Filtres -->
<div style="display:flex;gap:.6rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <?php foreach(['active'=>'Actives','resolue'=>'Résolues','annulee'=>'Annulées','all'=>'Toutes'] as $s=>$l): ?>
    <a href="?statut=<?php echo $s; ?>&niveau=<?php echo $filterNiv; ?>" class="btn btn-sm <?php echo $filterStatut===$s?'btn-primary':'btn-ghost'; ?>"><?php echo $l; ?></a>
    <?php endforeach; ?>
    <div style="margin-left:auto;display:flex;gap:.5rem">
        <?php foreach(['all'=>'Tout niveau']+$NIVEAUX as $k=>$v): ?>
        <a href="?statut=<?php echo $filterStatut; ?>&niveau=<?php echo $k; ?>" class="btn btn-sm <?php echo $filterNiv===$k?'btn-secondary':'btn-ghost'; ?>"><?php echo $v; ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Alertes -->
<?php if(empty($alertes)): ?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-circle-check" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem;color:#4ade80"></i>
    Aucune alerte <?php echo $filterStatut==='active'?'active':''; ?>
</div>
<?php else: ?>
<?php foreach($alertes as $a):
    $niv   = $a['niveau'];
    $nCol  = $NIV_COLORS[$niv] ?? '#3b82f6';
    $nIco  = $NIV_ICONS[$niv]  ?? 'fa-circle-info';
    $nLbl  = $NIVEAUX[$niv]    ?? $niv;
    $rCls  = $a['statut'] !== 'active' ? ' alerte-'.$a['statut'] : '';
?>
<div class="alerte-card alerte-<?php echo $niv.$rCls; ?>">
    <div class="alerte-head">
        <div class="alerte-ico" style="background:<?php echo $nCol; ?>20;color:<?php echo $nCol; ?>">
            <i class="fas <?php echo $nIco; ?>"></i>
        </div>
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.2rem">
                <span class="badge" style="background:<?php echo $nCol; ?>20;color:<?php echo $nCol; ?>;border:1px solid <?php echo $nCol; ?>44">
                    <?php echo $nLbl; ?>
                </span>
                <span class="badge bg-gray"><?php echo $CATS[$a['categorie']]??$a['categorie']; ?></span>
                <span class="badge bg-blue"><?php echo $CIBLES[$a['cible']]??$a['cible']; ?></span>
                <?php if($a['statut']==='resolue'): ?><span class="badge bg-green">Résolue</span><?php endif; ?>
                <?php if($a['statut']==='annulee'): ?><span class="badge bg-gray">Annulée</span><?php endif; ?>
            </div>
            <div class="alerte-titre"><?php echo htmlspecialchars($a['titre']); ?></div>
            <div class="alerte-meta">
                Publiée par <strong style="color:var(--txt-s)"><?php echo htmlspecialchars($a['author']??'—'); ?></strong>
                · <?php echo date('d/m/Y H:i',strtotime($a['created_at'])); ?>
                <?php if($a['expires_at']): ?> · Expire : <?php echo date('d/m/Y H:i',strtotime($a['expires_at'])); ?><?php endif; ?>
                <?php if($a['resolved_at']): ?> · Résolue le <?php echo date('d/m/Y',strtotime($a['resolved_at'])); ?> par <?php echo htmlspecialchars($a['resolver']??'—'); ?><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="alerte-contenu"><?php echo htmlspecialchars($a['contenu']); ?></div>
    <div class="alerte-actions">
        <?php if($a['statut']==='active'): ?>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-check-circle"></i> Résoudre</button></form>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-ban"></i> Annuler</button></form>
        <?php endif; ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer définitivement ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- MODAL NOUVELLE ALERTE -->
<div id="newAlerteModal" class="modal-ov hidden">
    <div class="modal">
        <div class="modal-hd"><h2><i class="fas fa-triangle-exclamation"></i> Nouvelle alerte publique</h2><button class="modal-cl" onclick="Gov.closeModal('newAlerteModal')">×</button></div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Niveau *</label>
                        <select name="niveau" class="form-select" required>
                            <?php foreach($NIVEAUX as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Catégorie *</label>
                        <select name="categorie" class="form-select" required>
                            <?php foreach($CATS as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Cible</label>
                        <select name="cible" class="form-select">
                            <?php foreach($CIBLES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Expire le</label><input type="datetime-local" name="expires_at" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required placeholder="Titre de l'alerte…"></div>
                    <div class="form-group form-full"><label class="form-label">Message *</label><textarea name="contenu" class="form-textarea" style="min-height:140px" required placeholder="Contenu de l'alerte publique…"></textarea></div>
                </div>
            </div>
            <div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newAlerteModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-broadcast-tower"></i> Publier</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>