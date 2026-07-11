<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Décrets & Arrêtés';
$db = Database::getInstance()->getConnection();
$msg = ''; $msgType = '';

$TYPES = ['decret'=>'Décret','arrete'=>'Arrêté','loi'=>'Loi','circulaire'=>'Circulaire','ordonnance'=>'Ordonnance'];
$TYPE_COLORS = ['decret'=>'#1d4ed8','arrete'=>'#7c3aed','loi'=>'#b45309','circulaire'=>'#0891b2','ordonnance'=>'#be185d'];

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            // Générer un numéro auto
            $year = date('Y');
            $stmt = $db->prepare("SELECT COUNT(*)+1 FROM gouv_decrets WHERE YEAR(created_at)=?");
            $stmt->execute([$year]); $seq = $stmt->fetchColumn();
            $type = $_POST['type'] ?? 'decret';
            $prefix = strtoupper(substr($type,0,3));
            $numero = "{$prefix}-{$year}-".str_pad($seq,3,'0',STR_PAD_LEFT);

            $db->prepare("
                INSERT INTO gouv_decrets (type, numero, titre, contenu, statut, created_by, effective_at, expires_at)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $type, $numero,
                trim($_POST['titre']),
                trim($_POST['contenu']),
                $_POST['statut'] ?? 'brouillon',
                $_SESSION['user_id'],
                !empty($_POST['effective_at']) ? $_POST['effective_at'] : null,
                !empty($_POST['expires_at'])   ? $_POST['expires_at']   : null,
            ]);
            $msg="Texte officiel $numero publié."; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'revoke') {
        try {
            $db->prepare("UPDATE gouv_decrets SET statut='revoque', revoked_by=?, revoked_at=NOW(), revoke_reason=? WHERE id=?")
               ->execute([$_SESSION['user_id'], trim($_POST['revoke_reason']??''), intval($_POST['id'])]);
            $msg='Texte révoqué.'; $msgType='ok';
        } catch(Exception $e) { $msg='Erreur : '.$e->getMessage(); $msgType='err'; }
    }

    if ($action === 'activate') {
        $db->prepare("UPDATE gouv_decrets SET statut='actif' WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Texte mis en vigueur.'; $msgType='ok';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM gouv_decrets WHERE id=?")->execute([intval($_POST['id'])]);
        $msg='Texte supprimé.'; $msgType='ok';
    }
}

// Filtres
$filterType   = $_GET['type']   ?? 'all';
$filterStatut = $_GET['statut'] ?? 'all';
$search       = trim($_GET['q'] ?? '');

$q = "SELECT d.*, u.username AS author FROM gouv_decrets d LEFT JOIN users u ON u.id=d.created_by WHERE 1=1";
$p = [];
if ($filterType   !== 'all') { $q .= " AND d.type=?";   $p[]=$filterType; }
if ($filterStatut !== 'all') { $q .= " AND d.statut=?";  $p[]=$filterStatut; }
if ($search) { $q .= " AND (d.titre LIKE ? OR d.numero LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; }
$q .= " ORDER BY d.created_at DESC";
$stmt=$db->prepare($q); $stmt->execute($p); $decrets=$stmt->fetchAll();

// Stats
$counts = $db->query("SELECT statut, COUNT(*) as n FROM gouv_decrets GROUP BY statut")->fetchAll(\PDO::FETCH_KEY_PAIR);

include __DIR__ . '/includes/header.php';
?>
<style>
.decret-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:12px;margin-bottom:.85rem;overflow:hidden;transition:border-color .2s}
.decret-card:hover{border-color:var(--bdr-h)}
.decret-head{display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.25rem;cursor:pointer}
.decret-type-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .65rem;border-radius:6px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;flex-shrink:0}
.decret-numero{font-family:'IBM Plex Mono',monospace;font-size:.72rem;color:var(--txt-m);margin-bottom:.25rem}
.decret-titre{font-weight:700;font-size:.9rem;color:#f1f5f9;line-height:1.35}
.decret-meta{font-size:.72rem;color:var(--txt-m);margin-top:.3rem}
.decret-body{padding:0 1.25rem 1.25rem;border-top:1px solid rgba(255,255,255,.05);display:none}
.decret-body.open{display:block}
.decret-content{background:var(--bg-ov);border-radius:8px;padding:1rem;font-size:.845rem;line-height:1.7;color:var(--txt-s);white-space:pre-wrap;margin:1rem 0}
.statut-actif    {background:rgba(34,197,94,.1); color:#4ade80; border:1px solid rgba(34,197,94,.2)}
.statut-brouillon{background:rgba(100,116,139,.1);color:#94a3b8; border:1px solid rgba(100,116,139,.2)}
.statut-revoque  {background:rgba(239,68,68,.1); color:#f87171; border:1px solid rgba(239,68,68,.2)}
.statut-expire   {background:rgba(245,158,11,.1);color:#fcd34d; border:1px solid rgba(245,158,11,.2)}
.filter-pills{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.4rem}
.fpill{padding:.38rem .85rem;border-radius:20px;font-size:.78rem;font-weight:600;cursor:pointer;border:1px solid var(--bdr);background:transparent;color:var(--txt-s);text-decoration:none;transition:all .18s}
.fpill:hover,.fpill.on{background:var(--gov);border-color:var(--gov);color:#fff}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='ok'?'check-circle':'exclamation-circle'; ?>"></i><?php echo $msg; ?></div>
<?php endif; ?>

<!-- Header + stats rapides -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <?php foreach(['actif'=>'Actifs','brouillon'=>'Brouillons','revoque'=>'Révoqués'] as $s=>$l): ?>
        <div style="background:var(--bg-c);border:1px solid var(--bdr);border-radius:9px;padding:.6rem 1rem;text-align:center;min-width:90px">
            <div style="font-size:.65rem;color:var(--txt-m);text-transform:uppercase;letter-spacing:.08em"><?php echo $l; ?></div>
            <div style="font-size:1.3rem;font-weight:700;color:#f1f5f9"><?php echo $counts[$s]??0; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <button onclick="Gov.openModal('newDecretModal')" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouveau texte officiel
    </button>
</div>

<!-- Filtres -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center">
    <input type="text" name="q" class="form-input" placeholder="Rechercher titre, numéro…" value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:200px">
    <select name="type" class="form-select" style="width:160px" onchange="this.form.submit()">
        <option value="all" <?php echo $filterType==='all'?'selected':''; ?>>Tous types</option>
        <?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $filterType===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
    </select>
    <select name="statut" class="form-select" style="width:140px" onchange="this.form.submit()">
        <option value="all" <?php echo $filterStatut==='all'?'selected':''; ?>>Tous statuts</option>
        <option value="actif"     <?php echo $filterStatut==='actif'?'selected':''; ?>>Actifs</option>
        <option value="brouillon" <?php echo $filterStatut==='brouillon'?'selected':''; ?>>Brouillons</option>
        <option value="revoque"   <?php echo $filterStatut==='revoque'?'selected':''; ?>>Révoqués</option>
        <option value="expire"    <?php echo $filterStatut==='expire'?'selected':''; ?>>Expirés</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    <a href="decrets.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
</form>

<!-- Liste -->
<?php if(empty($decrets)): ?>
<div class="card" style="text-align:center;padding:4rem;color:var(--txt-m)">
    <i class="fas fa-scroll" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    Aucun texte officiel
</div>
<?php else: ?>
<?php foreach($decrets as $d):
    $tCol = $TYPE_COLORS[$d['type']] ?? '#1d4ed8';
    $tLbl = $TYPES[$d['type']] ?? $d['type'];
    $stCls = 'statut-'.$d['statut'];
    $stLbl = ['actif'=>'En vigueur','brouillon'=>'Brouillon','revoque'=>'Révoqué','expire'=>'Expiré'][$d['statut']] ?? $d['statut'];
    $cid   = 'dc-'.$d['id'];
?>
<div class="decret-card">
    <div class="decret-head" onclick="toggleDecret('<?php echo $cid; ?>')">
        <div>
            <span class="decret-type-badge" style="background:<?php echo $tCol; ?>18;color:<?php echo $tCol; ?>;border:1px solid <?php echo $tCol; ?>33">
                <i class="fas fa-scroll"></i><?php echo $tLbl; ?>
            </span>
        </div>
        <div style="flex:1">
            <div class="decret-numero"><?php echo htmlspecialchars($d['numero']??'—'); ?></div>
            <div class="decret-titre"><?php echo htmlspecialchars($d['titre']); ?></div>
            <div class="decret-meta">
                Publié par <strong style="color:var(--txt-s)"><?php echo htmlspecialchars($d['author']??'—'); ?></strong>
                · <?php echo date('d/m/Y',strtotime($d['created_at'])); ?>
                <?php if($d['effective_at']): ?> · En vigueur : <strong><?php echo date('d/m/Y',strtotime($d['effective_at'])); ?></strong><?php endif; ?>
                <?php if($d['expires_at']): ?> · Expire : <?php echo date('d/m/Y',strtotime($d['expires_at'])); ?><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0">
            <span class="badge <?php echo $stCls; ?>"><?php echo $stLbl; ?></span>
            <i class="fas fa-chevron-down" style="color:var(--txt-m);font-size:.7rem;transition:transform .2s" id="chev-<?php echo $cid; ?>"></i>
        </div>
    </div>
    <div class="decret-body" id="<?php echo $cid; ?>">
        <div class="decret-content"><?php echo htmlspecialchars($d['contenu']); ?></div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <?php if($d['statut']==='brouillon'): ?>
            <form method="POST" style="margin:0">
                <input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-check"></i> Mettre en vigueur</button>
            </form>
            <?php endif; ?>
            <?php if($d['statut']==='actif'): ?>
            <button onclick="openRevoke(<?php echo $d['id']; ?>, '<?php echo addslashes($d['titre']); ?>')" class="btn btn-danger btn-sm">
                <i class="fas fa-ban"></i> Révoquer
            </button>
            <?php endif; ?>
            <form method="POST" style="margin:0" onsubmit="return confirm('Supprimer ce texte ?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- MODAL NOUVEAU TEXTE -->
<div id="newDecretModal" class="modal-ov hidden">
    <div class="modal" style="max-width:700px">
        <div class="modal-hd">
            <h2><i class="fas fa-scroll"></i> Nouveau texte officiel</h2>
            <button class="modal-cl" onclick="Gov.closeModal('newDecretModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="create">
                <div class="form-grid" style="margin-bottom:1rem">
                    <div class="form-group">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            <?php foreach($TYPES as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Statut</label>
                        <select name="statut" class="form-select">
                            <option value="brouillon">Brouillon</option>
                            <option value="actif">Mettre en vigueur immédiatement</option>
                        </select>
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Titre *</label>
                        <input type="text" name="titre" class="form-input" required placeholder="Intitulé officiel du texte…">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date d'entrée en vigueur</label>
                        <input type="date" name="effective_at" class="form-input" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date d'expiration</label>
                        <input type="date" name="expires_at" class="form-input">
                    </div>
                    <div class="form-group form-full">
                        <label class="form-label">Contenu / Texte intégral *</label>
                        <textarea name="contenu" class="form-textarea" required style="min-height:200px" placeholder="Rédigez le texte du décret, arrêté, loi…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newDecretModal')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Publier</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RÉVOQUER -->
<div id="revokeModal" class="modal-ov hidden">
    <div class="modal modal-sm">
        <div class="modal-hd">
            <h2><i class="fas fa-ban"></i> Révoquer le texte</h2>
            <button class="modal-cl" onclick="Gov.closeModal('revokeModal')">×</button>
        </div>
        <form method="POST">
            <div class="modal-bd">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="id" id="revokeId">
                <p style="margin-bottom:1rem;font-size:.875rem;color:var(--txt-s)">Texte : <strong id="revokeTitre" style="color:#f1f5f9"></strong></p>
                <div class="form-group">
                    <label class="form-label">Motif de révocation *</label>
                    <textarea name="revoke_reason" class="form-textarea" required placeholder="Motif officiel…"></textarea>
                </div>
            </div>
            <div class="modal-ft">
                <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('revokeModal')">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Révoquer</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDecret(id) {
    const body = document.getElementById(id);
    const chev = document.getElementById('chev-'+id);
    const open = body.classList.toggle('open');
    if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
}
function openRevoke(id, titre) {
    document.getElementById('revokeId').value = id;
    document.getElementById('revokeTitre').textContent = titre;
    Gov.openModal('revokeModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>