<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Life Invader';
$companyColor   = '#4f7cff';
$companyFaction = 'LIFE_INVADER';
$pageTitle      = 'Communiqués';
$pageSubtitle   = 'Réception, traitement et publication des communiqués officiels';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$db     = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$isChef = hasRole(['*','Administrateur','Développeur','Staff']) || in_array($_SESSION['user_faction_rank']??'',[
    'Directeur de Publication','Rédacteur en Chef','Chef de Rubrique','Rédacteur Senior','Éditeur'
]);
$msg = ''; $msgType = '';

if (!function_exists('li_sanitize_html')) {
    function li_sanitize_html(string $html): string {
        if ($html === '') return '';
        $html = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
        $html = preg_replace('#<\s*iframe\b[^>]*>.*?<\s*/\s*iframe\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|iframe|object|embed|form|input|button|style)\b[^>]*/?>#i', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\s+on[a-z]+\s*=\s*'[^']*'#i", '', $html);
        $html = preg_replace('#(href|src)\s*=\s*"javascript:[^"]*"#i', '$1="#"', $html);
        $html = preg_replace("#(href|src)\s*=\s*'javascript:[^']*'#i", '$1="#"', $html);
        return $html;
    }
}

// Détection colonne image_principale & statut élargi
$hasImgCol = false;
try { $hasImgCol = (bool)$db->query("SHOW COLUMNS FROM li_communiques LIKE 'image_principale'")->fetchColumn(); } catch (Exception $e) {}

/* ═══════════════════════════════════════════════════════════
   ACTIONS POST
═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_communique') {
        try {
            $db->beginTransaction();
            $yr  = date('Y');
            $sq  = $db->prepare("SELECT dernier FROM li_sequences WHERE type='CP' FOR UPDATE"); $sq->execute();
            $last = $sq->fetchColumn();
            if ($last === false) { $db->prepare("INSERT INTO li_sequences(type,annee,dernier) VALUES('CP',?,1)")->execute([$yr]); $num=1; }
            else { $num=$last+1; $db->prepare("UPDATE li_sequences SET dernier=?,annee=? WHERE type='CP'")->execute([$num,$yr]); }
            $ref = "CP-$yr-".str_pad($num,5,'0',STR_PAD_LEFT);

            $statutInit = $_POST['statut'] ?? 'recu';
            if (!in_array($statutInit, ['brouillon','recu','attente','publie'])) $statutInit = 'recu';

            $cols = "reference,titre,contenu,source,contact,priorite,statut,traite_par,notes,recu_le";
            $vals = "?,?,?,?,?,?,?,?,?,?";
            $params = [
                $ref,
                trim($_POST['titre']),
                li_sanitize_html($_POST['contenu'] ?? ''),
                trim($_POST['source']),
                trim($_POST['contact'] ?? ''),
                $_POST['priorite'] ?? 'normale',
                $statutInit,
                $userId,
                trim($_POST['notes'] ?? ''),
                !empty($_POST['recu_le']) ? $_POST['recu_le'] : date('Y-m-d H:i:s'),
            ];
            if ($hasImgCol) {
                $cols .= ",image_principale";
                $vals .= ",?";
                $params[] = trim($_POST['image_principale'] ?? '') ?: null;
            }

            $db->prepare("INSERT INTO li_communiques ($cols) VALUES ($vals)")->execute($params);
            $db->commit();
            $msg = "Communiqué $ref enregistré."; $msgType='success';
        } catch(Exception $e){$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'update_communique') {
        try {
            $sql = "UPDATE li_communiques SET titre=?,contenu=?,source=?,contact=?,priorite=?,notes=?";
            $params = [
                trim($_POST['titre']),
                li_sanitize_html($_POST['contenu'] ?? ''),
                trim($_POST['source']),
                trim($_POST['contact'] ?? ''),
                $_POST['priorite'] ?? 'normale',
                trim($_POST['notes'] ?? ''),
            ];
            if ($hasImgCol) {
                $sql .= ", image_principale=?";
                $params[] = trim($_POST['image_principale'] ?? '') ?: null;
            }
            $sql .= " WHERE id=?";
            $params[] = (int)$_POST['cp_id'];
            $db->prepare($sql)->execute($params);
            $msg = "Communiqué mis à jour."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'change_statut_cp') {
        try {
            $ns = $_POST['new_statut'];
            $db->prepare("UPDATE li_communiques SET statut=?,traite_par=? WHERE id=?")->execute([$ns,$userId,(int)$_POST['cp_id']]);
            $labels=['brouillon'=>'remis en brouillon','en_traitement'=>'pris en traitement','attente'=>'mis en attente','archive'=>'archivé','rejete'=>'rejeté','publie'=>'publié'];
            $msg = "Communiqué ".($labels[$ns] ?? $ns)."."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'convert_to_article') {
        try {
            $db->beginTransaction();
            $cpId = (int)$_POST['cp_id'];
            $cpQ = $db->prepare("SELECT * FROM li_communiques WHERE id=?"); $cpQ->execute([$cpId]);
            $cp = $cpQ->fetch(PDO::FETCH_ASSOC);
            if (!$cp) throw new Exception("Communiqué introuvable");

            $yr  = date('Y');
            $sq  = $db->prepare("SELECT dernier FROM li_sequences WHERE type='ART' FOR UPDATE"); $sq->execute();
            $last = $sq->fetchColumn();
            if ($last === false) { $db->prepare("INSERT INTO li_sequences(type,annee,dernier) VALUES('ART',?,1)")->execute([$yr]); $num=1; }
            else { $num=$last+1; $db->prepare("UPDATE li_sequences SET dernier=?,annee=? WHERE type='ART'")->execute([$num,$yr]); }
            $artRef = "ART-$yr-".str_pad($num,5,'0',STR_PAD_LEFT);

            $chapeau = "Source : ".$cp['source'].($cp['contact']?" — Contact : ".$cp['contact']:"");
            $contenuArt = $cp['contenu']."<hr><p><em>Communiqué original : ".htmlspecialchars($cp['reference'])."</em></p>";

            $db->prepare("INSERT INTO li_articles (reference,titre,chapeau,contenu,auteur_id,statut,priorite,notes_redaction,created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                $artRef,
                !empty($_POST['titre_art']) ? trim($_POST['titre_art']) : $cp['titre'],
                $chapeau,
                $contenuArt,
                $userId,
                'brouillon',
                $cp['priorite'],
                "Généré depuis communiqué ".$cp['reference']." — Source : ".$cp['source'],
                $userId
            ]);
            $artId = $db->lastInsertId();
            $db->prepare("UPDATE li_communiques SET article_id=?,statut='en_traitement' WHERE id=?")->execute([$artId,$cpId]);
            $db->commit();
            $msg = "Article $artRef créé depuis le communiqué. À retravailler en brouillon."; $msgType='success';
        } catch(Exception $e){$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'delete_cp' && $isChef) {
        try {
            $db->prepare("DELETE FROM li_communiques WHERE id=?")->execute([(int)$_POST['cp_id']]);
            $msg = "Communiqué supprimé."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }
}

/* ═══════════════════════════════════════════════════════════
   REQUÊTES
═══════════════════════════════════════════════════════════ */
$fStatut = $_GET['statut']  ?? '';
$fPrio   = $_GET['priorite']?? '';
$fSearch = trim($_GET['q']  ?? '');
$openNew = isset($_GET['open']) && $_GET['open']==='new';
$viewId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$where=[]; $params=[];
if ($fStatut) { $where[]='cp.statut=?';   $params[]=$fStatut; }
if ($fPrio)   { $where[]='cp.priorite=?'; $params[]=$fPrio; }
if ($fSearch) { $where[]='(cp.titre LIKE ? OR cp.source LIKE ?)'; $params[]="%$fSearch%"; $params[]="%$fSearch%"; }
$whereSQL = $where ? "WHERE ".implode(" AND ",$where) : "";

$imgSelect = $hasImgCol ? ', cp.image_principale' : '';
$cps = $db->prepare("
    SELECT cp.id, cp.reference, cp.titre, cp.contenu, cp.source, cp.contact, cp.priorite, cp.statut,
           cp.notes, cp.recu_le, cp.created_at, cp.article_id, cp.traite_par $imgSelect,
           u.username traite_nom,
           a.reference art_ref, a.statut art_statut, a.id art_id, a.titre art_titre
    FROM li_communiques cp
    LEFT JOIN users u ON cp.traite_par=u.id
    LEFT JOIN li_articles a ON cp.article_id=a.id
    $whereSQL
    ORDER BY FIELD(cp.statut,'recu','attente','en_traitement','brouillon','publie','archive','rejete'),
             FIELD(cp.priorite,'urgente','importante','normale'),
             cp.recu_le DESC
    LIMIT 200");
$cps->execute($params);
$cps = $cps->fetchAll(PDO::FETCH_ASSOC);

$sQ = $db->query("SELECT statut,COUNT(*) n FROM li_communiques GROUP BY statut");
$sArr = []; foreach($sQ->fetchAll(PDO::FETCH_ASSOC) as $r) $sArr[$r['statut']]=$r['n'];

$cpView = null;
if ($viewId) {
    $vq=$db->prepare("SELECT cp.* $imgSelect, u.username traite_nom, a.reference art_ref, a.titre art_titre, a.statut art_statut, a.id art_id FROM li_communiques cp LEFT JOIN users u ON cp.traite_par=u.id LEFT JOIN li_articles a ON cp.article_id=a.id WHERE cp.id=?");
    $vq->execute([$viewId]); $cpView=$vq->fetch(PDO::FETCH_ASSOC);
}

include __DIR__.'/includes/header.php';
?>
<style>
.cp-card {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: 1.15rem;
    transition: all .18s; position: relative; overflow: hidden;
    display: flex; flex-direction: column; gap: .75rem;
}
.cp-card:hover { border-color: var(--li-bd); transform: translateY(-2px); box-shadow: var(--shadow-2); }
.cp-card-stripe { position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.cp-source-badge {
    display: inline-flex; align-items: center; gap: .32rem;
    padding: .22rem .6rem;
    background: var(--li-soft); border: 1px solid var(--li-bd);
    border-radius: 999px; font-size: .66rem; font-weight: 700; color: var(--li);
}
.cp-body { font-size: .9rem; color: var(--tx-2); line-height: 1.8; }
.cp-body p { margin: .55rem 0; }
.cp-body blockquote { border-left: 3px solid var(--li); padding: .35rem 1rem; margin: .65rem 0; color: var(--tx-2); font-style: italic; background: var(--li-softer); border-radius: 0 var(--r-sm) var(--r-sm) 0; }
.cp-body img { max-width: 100%; height: auto; border-radius: var(--r-md); }
.cp-view-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.3rem; }
@media (max-width: 900px) { .cp-view-grid { grid-template-columns: 1fr; } }
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<?php if($cpView):
    $cpImg = $hasImgCol ? ($cpView['image_principale'] ?? '') : '';
?>
<!-- Vue détail communiqué -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.2rem">
    <div style="display:flex;gap:.6rem;align-items:center">
        <a href="communiques.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        <span style="font-size:.72rem;color:var(--tx-3);font-family:'JetBrains Mono',monospace"><?php echo $cpView['reference']; ?></span>
    </div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if($cpView['statut']==='recu' || $cpView['statut']==='brouillon'): ?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="change_statut_cp"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>"><input type="hidden" name="new_statut" value="en_traitement"><button class="btn btn-warn btn-sm"><i class="fas fa-cog"></i> Prendre en traitement</button></form>
        <?php endif; ?>
        <?php if(in_array($cpView['statut'],['recu','en_traitement','attente','brouillon'])): ?>
        <button class="btn btn-primary btn-sm" onclick="Company.openModal('modalConvert')"><i class="fas fa-magic"></i> Convertir en article</button>
        <?php endif; ?>
        <?php if($cpView['art_id']): ?>
        <a href="articles.php?id=<?php echo $cpView['art_id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-newspaper"></i> Voir l'article</a>
        <?php endif; ?>
        <button class="btn btn-secondary btn-sm" onclick="Company.openModal('modalEditCp')"><i class="fas fa-edit"></i> Modifier</button>
        <?php if($isChef): ?>
        <form method="POST" style="display:inline"
              data-confirm="Le communiqué %t sera publié sur le site public et visible par tous les lecteurs."
              data-confirm-title="Publier le communiqué ?"
              data-confirm-tone="success"
              data-confirm-icon="fa-bullhorn"
              data-confirm-ok="Publier"
              data-confirm-target="<?php echo htmlspecialchars($cpView['titre']); ?>">
            <input type="hidden" name="action" value="change_statut_cp"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>"><input type="hidden" name="new_statut" value="publie">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Publier</button>
        </form>
        <form method="POST" style="display:inline"
              data-confirm="Le communiqué sera marqué comme rejeté et ne sera plus actif."
              data-confirm-title="Rejeter ce communiqué ?"
              data-confirm-tone="danger"
              data-confirm-icon="fa-ban"
              data-confirm-ok="Rejeter">
            <input type="hidden" name="action" value="change_statut_cp"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>"><input type="hidden" name="new_statut" value="rejete">
            <button type="submit" class="btn btn-danger btn-sm" title="Rejeter"><i class="fas fa-ban"></i></button>
        </form>
        <form method="POST" style="display:inline"
              data-confirm="Le communiqué sera archivé et ne sera plus visible dans la file active."
              data-confirm-title="Archiver le communiqué ?"
              data-confirm-tone="warn"
              data-confirm-icon="fa-box-archive"
              data-confirm-ok="Archiver">
            <input type="hidden" name="action" value="change_statut_cp"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>"><input type="hidden" name="new_statut" value="archive">
            <button type="submit" class="btn btn-secondary btn-sm" title="Archiver"><i class="fas fa-archive"></i></button>
        </form>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></button>
    </div>
</div>

<div class="cp-view-grid">
<div>
    <div class="li-widget">
        <?php if (!empty($cpImg)): ?>
        <div style="aspect-ratio:21/9;background:var(--surface-2);background-size:cover;background-position:center;border-radius:var(--r-md);margin-bottom:1rem;background-image:url('<?php echo htmlspecialchars($cpImg); ?>')"></div>
        <?php endif; ?>
        <div style="display:flex;align-items:flex-start;gap:.85rem;margin-bottom:1.2rem">
            <div style="width:48px;height:48px;border-radius:13px;background:var(--li-soft);border:1px solid var(--li-bd);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--li);flex-shrink:0"><i class="fas fa-envelope-open-text"></i></div>
            <div style="flex:1">
                <div style="font-family:'Playfair Display',serif;font-size:1.35rem;font-weight:700;color:var(--tx);line-height:1.3;margin-bottom:.45rem"><?php echo htmlspecialchars($cpView['titre']); ?></div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
                    <span class="badge badge-<?php echo $cpView['statut']; ?>"><?php echo $cpView['statut']; ?></span>
                    <span class="badge badge-<?php echo $cpView['priorite']; ?>"><?php echo $cpView['priorite']; ?></span>
                    <span class="cp-source-badge"><i class="fas fa-building"></i> <?php echo htmlspecialchars($cpView['source']); ?></span>
                </div>
            </div>
        </div>
        <div class="cp-body"><?php echo li_sanitize_html($cpView['contenu']); ?></div>
        <?php if($cpView['notes']): ?>
        <div style="margin-top:1rem;padding:.9rem;background:var(--li-softer);border:1px solid var(--li-bd);border-radius:9px;font-size:.82rem;color:var(--tx-2)"><i class="fas fa-sticky-note" style="color:var(--li);margin-right:.4rem"></i><?php echo nl2br(htmlspecialchars($cpView['notes'])); ?></div>
        <?php endif; ?>
    </div>
</div>
<div>
    <div class="li-widget">
        <div class="li-widget-title"><i class="fas fa-info-circle"></i> Détails</div>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-hashtag"></i>Référence</div><div style="font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--tx-3)"><?php echo $cpView['reference']; ?></div></div>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-building"></i>Source</div><div style="font-weight:700;font-size:.82rem"><?php echo htmlspecialchars($cpView['source']); ?></div></div>
        <?php if($cpView['contact']): ?><div class="fin-row"><div class="fin-lbl"><i class="fas fa-user"></i>Contact</div><div style="font-size:.82rem"><?php echo htmlspecialchars($cpView['contact']); ?></div></div><?php endif; ?>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-calendar-plus"></i>Reçu le</div><div style="font-size:.82rem"><?php echo date('d/m/Y H:i',strtotime($cpView['recu_le'])); ?></div></div>
        <?php if($cpView['traite_nom']): ?><div class="fin-row"><div class="fin-lbl"><i class="fas fa-user-edit"></i>Traité par</div><div style="font-size:.82rem"><?php echo htmlspecialchars($cpView['traite_nom']); ?></div></div><?php endif; ?>
    </div>
    <?php if($cpView['art_id']): ?>
    <div class="li-widget">
        <div class="li-widget-title"><i class="fas fa-newspaper"></i> Article généré</div>
        <a href="articles.php?id=<?php echo $cpView['art_id']; ?>" style="display:flex;align-items:center;gap:.7rem;padding:.8rem;background:var(--li-softer);border:1px solid var(--li-bd);border-radius:9px;text-decoration:none">
            <i class="fas fa-file-alt" style="color:var(--li);font-size:1.1rem"></i>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.85rem;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($cpView['art_titre']??''); ?></div>
                <div style="font-size:.7rem;color:var(--tx-3)"><?php echo $cpView['art_ref']; ?> · <span class="badge badge-<?php echo $cpView['art_statut']; ?>"><?php echo $cpView['art_statut']; ?></span></div>
            </div>
            <i class="fas fa-external-link-alt" style="color:var(--tx-3);font-size:.7rem"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Modal convertir -->
<div class="modal-overlay hidden" id="modalConvert">
    <div class="modal modal-sm">
        <div class="modal-header"><h2><i class="fas fa-magic"></i> Convertir en article</h2><button class="modal-close" onclick="Company.closeModal('modalConvert')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="convert_to_article"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>">
            <div class="modal-body">
                <div class="alert alert-info" style="margin-bottom:1rem;font-size:.82rem"><i class="fas fa-info-circle"></i> Le contenu du communiqué sera utilisé comme base. L'article sera créé en <strong>brouillon</strong> pour être retravaillé.</div>
                <div class="form-group">
                    <label class="form-label">Titre de l'article <span style="color:var(--tx-3);font-weight:400;text-transform:none">(vide = titre du communiqué)</span></label>
                    <input name="titre_art" class="form-input" placeholder="<?php echo htmlspecialchars($cpView['titre']); ?>">
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalConvert')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-magic"></i> Créer l'article</button></div>
        </form>
    </div>
</div>

<!-- Modal modifier CP -->
<div class="modal-overlay hidden" id="modalEditCp">
    <div class="modal modal-lg">
        <div class="modal-header"><h2><i class="fas fa-edit"></i> Modifier le communiqué</h2><button class="modal-close" onclick="Company.closeModal('modalEditCp')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_communique"><input type="hidden" name="cp_id" value="<?php echo $cpView['id']; ?>">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input name="titre" class="form-input" value="<?php echo htmlspecialchars($cpView['titre']); ?>" required></div>
                    <div class="form-group"><label class="form-label">Source *</label><input name="source" class="form-input" value="<?php echo htmlspecialchars($cpView['source']); ?>" required></div>
                    <div class="form-group"><label class="form-label">Contact</label><input name="contact" class="form-input" value="<?php echo htmlspecialchars($cpView['contact']??''); ?>"></div>
                    <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select"><?php foreach(['normale','importante','urgente'] as $p): ?><option value="<?php echo $p; ?>" <?php echo $cpView['priorite']===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?></select></div>
                    <?php if ($hasImgCol): ?>
                    <div class="form-group form-full"><label class="form-label">Image principale (URL)</label><input name="image_principale" type="url" class="form-input" value="<?php echo htmlspecialchars($cpImg); ?>" placeholder="https://…"></div>
                    <?php endif; ?>
                    <div class="form-group form-full"><label class="form-label">Contenu</label><textarea name="contenu" data-rt-editor data-min-height="280px"><?php echo htmlspecialchars($cpView['contenu']); ?></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes internes</label><textarea name="notes" class="form-textarea" rows="3"><?php echo htmlspecialchars($cpView['notes']??''); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalEditCp')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<?php else: ?>

<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-envelope-open-text"></i> Communiqués de presse</div>
        <div class="page-header-sub">
            <strong style="color:var(--tx-2)"><?php echo array_sum($sArr); ?></strong> communiqué<?php echo array_sum($sArr)>1?'s':''; ?> ·
            <?php echo $sArr['recu']??0; ?> à traiter ·
            <?php echo $sArr['publie']??0; ?> publié<?php echo ($sArr['publie']??0)>1?'s':''; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="Company.openModal('modalCreate')"><i class="fas fa-plus"></i> Nouveau communiqué</button>
    </div>
</div>

<!-- KPI -->
<div class="li-stats">
    <div class="li-stat"><div class="li-stat-ico ico-cyan"><i class="fas fa-inbox"></i></div><div><div class="li-stat-val"><?php echo $sArr['recu']??0; ?></div><div class="li-stat-lbl">À traiter</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-amber"><i class="fas fa-clock"></i></div><div><div class="li-stat-val"><?php echo ($sArr['attente']??0)+($sArr['en_traitement']??0); ?></div><div class="li-stat-lbl">En cours / attente</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-emerald"><i class="fas fa-check"></i></div><div><div class="li-stat-val"><?php echo $sArr['publie']??0; ?></div><div class="li-stat-lbl">Publiés</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-red"><i class="fas fa-ban"></i></div><div><div class="li-stat-val"><?php echo $sArr['rejete']??0; ?></div><div class="li-stat-lbl">Rejetés</div></div></div>
</div>

<!-- Tabs -->
<div class="stat-tab">
    <a href="communiques.php" class="stat-tab-item <?php echo !$fStatut?'active':''; ?>">Tous (<?php echo array_sum($sArr); ?>)</a>
    <a href="?statut=recu" class="stat-tab-item <?php echo $fStatut==='recu'?'active':''; ?>"><i class="fas fa-inbox"></i> Reçus (<?php echo $sArr['recu']??0; ?>)</a>
    <a href="?statut=en_traitement" class="stat-tab-item <?php echo $fStatut==='en_traitement'?'active-revision':''; ?>"><i class="fas fa-cog"></i> En traitement (<?php echo $sArr['en_traitement']??0; ?>)</a>
    <a href="?statut=attente" class="stat-tab-item <?php echo $fStatut==='attente'?'active-revision':''; ?>"><i class="fas fa-hourglass-half"></i> En attente (<?php echo $sArr['attente']??0; ?>)</a>
    <a href="?statut=brouillon" class="stat-tab-item <?php echo $fStatut==='brouillon'?'active-brouillon':''; ?>"><i class="fas fa-pencil-alt"></i> Brouillons (<?php echo $sArr['brouillon']??0; ?>)</a>
    <a href="?statut=publie" class="stat-tab-item <?php echo $fStatut==='publie'?'active-publie':''; ?>"><i class="fas fa-check"></i> Publiés (<?php echo $sArr['publie']??0; ?>)</a>
    <a href="?statut=rejete" class="stat-tab-item <?php echo $fStatut==='rejete'?'active-refuse':''; ?>"><i class="fas fa-ban"></i> Rejetés (<?php echo $sArr['rejete']??0; ?>)</a>
    <a href="?statut=archive" class="stat-tab-item <?php echo $fStatut==='archive'?'active':''; ?>"><i class="fas fa-archive"></i> Archives (<?php echo $sArr['archive']??0; ?>)</a>
</div>

<!-- Filtres -->
<div class="filters-bar">
    <div class="filter-group" style="flex:2;min-width:180px"><label class="filter-lbl"><i class="fas fa-search"></i> Recherche</label><input type="text" class="form-input" id="searchCp" placeholder="Titre, source…" value="<?php echo htmlspecialchars($fSearch); ?>" onkeyup="applyFilter()"></div>
    <div class="filter-group"><label class="filter-lbl">Priorité</label>
        <select class="form-select" id="fPrioSel" onchange="applyFilter()">
            <option value="">Toutes</option>
            <option value="urgente" <?php echo $fPrio==='urgente'?'selected':''; ?>>Urgente</option>
            <option value="importante" <?php echo $fPrio==='importante'?'selected':''; ?>>Importante</option>
            <option value="normale" <?php echo $fPrio==='normale'?'selected':''; ?>>Normale</option>
        </select>
    </div>
    <div class="filter-group" style="flex:0"><label class="filter-lbl">&nbsp;</label><a href="communiques.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a></div>
</div>

<?php if(empty($cps)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-envelope-open-text"></i><h3>Aucun communiqué</h3><p>Enregistrez ici les communiqués reçus.</p><button class="btn btn-primary" onclick="Company.openModal('modalCreate')"><i class="fas fa-plus"></i> Ajouter</button></div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:1.1rem" id="cpGrid">
    <?php foreach($cps as $cp):
        $cpThumb = $hasImgCol ? ($cp['image_principale'] ?? '') : '';
        $pCol=['urgente'=>'#ef4444','importante'=>'#f59e0b','normale'=>'var(--li)'];
    ?>
    <div class="cp-card">
        <div class="cp-card-stripe" style="background:<?php echo $pCol[$cp['priorite']]??'var(--li)'; ?>"></div>
        <?php if (!empty($cpThumb)): ?>
        <a href="communiques.php?id=<?php echo $cp['id']; ?>" style="display:block;aspect-ratio:16/9;background:var(--surface-2) center/cover url('<?php echo htmlspecialchars($cpThumb); ?>');border-radius:var(--r-md);margin:0 -.15rem"></a>
        <?php endif; ?>
        <div style="display:flex;align-items:flex-start;gap:.75rem">
            <div style="width:36px;height:36px;background:var(--li-soft);border:1px solid var(--li-bd);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:var(--li);flex-shrink:0"><i class="fas fa-envelope-open-text"></i></div>
            <div style="flex:1;min-width:0">
                <a href="communiques.php?id=<?php echo $cp['id']; ?>" style="color:inherit;text-decoration:none">
                    <div style="font-family:'Playfair Display',serif;font-weight:700;font-size:.95rem;color:var(--tx);line-height:1.32;margin-bottom:.22rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo htmlspecialchars($cp['titre']); ?></div>
                </a>
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
                    <span class="cp-source-badge"><i class="fas fa-building"></i><?php echo htmlspecialchars(mb_substr($cp['source'],0,28)); ?></span>
                    <span style="font-size:.66rem;color:var(--tx-3)"><?php echo date('d/m/Y H:i',strtotime($cp['recu_le'])); ?></span>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
            <span class="badge badge-<?php echo $cp['statut']; ?>"><?php echo $cp['statut']; ?></span>
            <span class="badge badge-<?php echo $cp['priorite']; ?>"><?php echo $cp['priorite']; ?></span>
            <?php if($cp['art_ref']): ?><span class="badge" style="background:rgba(16,185,129,.10);color:#10b981;border-color:rgba(16,185,129,.28);font-size:.6rem"><i class="fas fa-link"></i> <?php echo $cp['art_ref']; ?></span><?php endif; ?>
        </div>
        <div style="font-size:.78rem;color:var(--tx-3);line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
            <?php echo htmlspecialchars(mb_substr(strip_tags($cp['contenu']),0,200)); ?>…
        </div>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:auto;padding-top:.5rem;border-top:1px solid var(--bd)">
            <a href="communiques.php?id=<?php echo $cp['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-eye"></i> Voir</a>
            <?php if($cp['statut']==='recu'): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="change_statut_cp"><input type="hidden" name="cp_id" value="<?php echo $cp['id']; ?>"><input type="hidden" name="new_statut" value="en_traitement"><button class="btn btn-sm btn-warn"><i class="fas fa-cog"></i></button></form>
            <?php endif; ?>
            <?php if($cp['art_id']): ?><a href="articles.php?id=<?php echo $cp['art_id']; ?>" class="btn btn-sm btn-success btn-icon" title="Voir l'article"><i class="fas fa-newspaper"></i></a><?php endif; ?>
            <?php if($isChef): ?><form method="POST" style="display:inline;margin-left:auto"
                          data-confirm="Le communiqué %t sera définitivement supprimé. Cette action est irréversible."
                          data-confirm-title="Supprimer le communiqué ?"
                          data-confirm-tone="danger"
                          data-confirm-icon="fa-trash"
                          data-confirm-ok="Supprimer définitivement"
                          data-confirm-target="<?php echo htmlspecialchars($cp['titre']); ?>">
                <input type="hidden" name="action" value="delete_cp"><input type="hidden" name="cp_id" value="<?php echo $cp['id']; ?>">
                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Supprimer"><i class="fas fa-trash"></i></button>
            </form><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ MODAL CRÉER COMMUNIQUÉ ═══ -->
<div class="modal-overlay <?php echo $openNew?'':'hidden'; ?>" id="modalCreate">
    <div class="modal modal-lg">
        <div class="modal-header"><h2><i class="fas fa-envelope-open-text"></i> Enregistrer un communiqué</h2><button class="modal-close" onclick="Company.closeModal('modalCreate')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="create_communique">
            <div class="modal-body">
                <div class="alert alert-info" style="margin-bottom:1rem;font-size:.82rem"><i class="fas fa-info-circle"></i> Communiqués officiels reçus de mairie, LSPD, organisations… Vous pourrez ensuite les convertir en articles.</div>
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre du communiqué *</label><input name="titre" class="form-input" placeholder="Titre officiel…" required></div>
                    <div class="form-group"><label class="form-label">Source / Organisation *</label><input name="source" class="form-input" placeholder="Mairie de Los Santos, LSPD, Maze Bank…" required></div>
                    <div class="form-group"><label class="form-label">Contact</label><input name="contact" class="form-input" placeholder="Nom, téléphone, email…"></div>
                    <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select"><option value="normale">Normale</option><option value="importante">Importante</option><option value="urgente">Urgente</option></select></div>
                    <div class="form-group"><label class="form-label">Statut initial</label>
                        <select name="statut" class="form-select">
                            <option value="recu">Reçu (à traiter)</option>
                            <option value="brouillon">Brouillon</option>
                            <option value="attente">En attente de validation</option>
                            <?php if ($isChef): ?><option value="publie">Publier directement</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Date de réception</label><input type="datetime-local" name="recu_le" class="form-input" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
                    <?php if ($hasImgCol): ?>
                    <div class="form-group form-full"><label class="form-label">Image principale (URL)</label><input name="image_principale" type="url" class="form-input" placeholder="https://…"></div>
                    <?php endif; ?>
                    <div class="form-group form-full"><label class="form-label">Contenu *</label><textarea name="contenu" data-rt-editor data-min-height="260px" placeholder="Collez ou rédigez le contenu intégral du communiqué…" required></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes internes <span style="color:var(--tx-3);font-weight:400;text-transform:none">(non publiées)</span></label><textarea name="notes" class="form-textarea" rows="3" placeholder="Contexte, fiabilité de la source, instructions…"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalCreate')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<script>
function applyFilter() {
    const url = new URL(window.location.href);
    const q = (document.getElementById('searchCp')?.value || '').trim();
    const prio = document.getElementById('fPrioSel')?.value || '';
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (prio) url.searchParams.set('priorite', prio); else url.searchParams.delete('priorite');
    clearTimeout(window._cpFilterTO);
    window._cpFilterTO = setTimeout(() => window.location = url, 400);
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
