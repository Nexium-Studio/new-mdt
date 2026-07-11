<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Life Invader';
$companyColor   = '#4f7cff';
$companyFaction = 'LIFE_INVADER';
$pageTitle      = 'Articles';
$pageSubtitle   = 'Production éditoriale et workflow de publication';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}
$db      = Database::getInstance()->getConnection();
$userId  = $_SESSION['user_id'];
$isChef  = hasRole(['*','Administrateur','Développeur','Staff']) || in_array($_SESSION['user_faction_rank']??'',[
    'Directeur de Publication','Rédacteur en Chef','Chef de Rubrique','Rédacteur Senior','Éditeur'
]);
$msg = ''; $msgType = '';

/* ─── Sanitization basique du HTML rich-text ────────────────────────
   On retire les scripts/iframes & attributs on<event> / javascript:
─────────────────────────────────────────────────────────────────── */
if (!function_exists('li_sanitize_html')) {
    function li_sanitize_html(string $html): string {
        if ($html === '') return '';
        $html = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
        $html = preg_replace('#<\s*iframe\b[^>]*>.*?<\s*/\s*iframe\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|iframe|object|embed|form|input|button|style)\b[^>]*/?>#i', '', $html);
        // Strip on* event attributes
        $html = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\s+on[a-z]+\s*=\s*'[^']*'#i", '', $html);
        // Strip javascript: in href/src
        $html = preg_replace('#(href|src)\s*=\s*"javascript:[^"]*"#i', '$1="#"', $html);
        $html = preg_replace("#(href|src)\s*=\s*'javascript:[^']*'#i", '$1="#"', $html);
        return $html;
    }
}

/* ═══════════════════════════════════════════════════════════
   ACTIONS POST
═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_article') {
        try {
            $db->beginTransaction();
            $yr = date('Y');
            $sq = $db->prepare("SELECT dernier FROM li_sequences WHERE type='ART' FOR UPDATE");
            $sq->execute();
            $last = $sq->fetchColumn();
            if($last===false){
                $db->prepare("INSERT INTO li_sequences(type,annee,dernier) VALUES('ART',?,1)")->execute([$yr]);
                $num=1;
            } else {
                $num=$last+1;
                $db->prepare("UPDATE li_sequences SET dernier=?,annee=? WHERE type='ART'")->execute([$num,$yr]);
            }
            $ref = "ART-$yr-".str_pad($num,5,'0',STR_PAD_LEFT);

            $tags = !empty($_POST['tags']) ? json_encode(array_map('trim',explode(',',strip_tags($_POST['tags'])))) : null;

            // Test colonne image_principale (rétrocompatible)
            $hasImgCol = false;
            try {
                $hasImgCol = (bool)$db->query("SHOW COLUMNS FROM li_articles LIKE 'image_principale'")->fetchColumn();
            } catch (Exception $e) {}

            $cols = "reference,titre,chapeau,contenu,categorie_id,auteur_id,statut,priorite,tags,exclusif,a_la_une,confidentiel,date_deadline,notes_redaction,created_by";
            $vals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
            $params = [
                $ref,
                trim($_POST['titre']),
                trim($_POST['chapeau']??''),
                li_sanitize_html($_POST['contenu']??''),
                $_POST['categorie_id']?:null,
                $userId,
                $_POST['statut']??'brouillon',
                $_POST['priorite']??'normale',
                $tags,
                isset($_POST['exclusif'])?1:0,
                isset($_POST['a_la_une'])?1:0,
                isset($_POST['confidentiel'])?1:0,
                !empty($_POST['date_deadline'])?$_POST['date_deadline']:null,
                trim($_POST['notes_redaction']??''),
                $userId
            ];
            if ($hasImgCol) {
                $cols .= ",image_principale";
                $vals .= ",?";
                $params[] = trim($_POST['image_principale']??'') ?: null;
            }

            $db->prepare("INSERT INTO li_articles ($cols) VALUES ($vals)")->execute($params);
            // Si publication directe, date_publication
            if (($_POST['statut']??'')==='publie') {
                $aid = $db->lastInsertId();
                $db->prepare("UPDATE li_articles SET date_publication=NOW(), relecteur_id=? WHERE id=?")->execute([$userId,$aid]);
            }
            $db->commit();
            $msg = "Article $ref créé avec succès."; $msgType='success';
        } catch(Exception $e){$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'update_article') {
        try {
            $aid = (int)$_POST['article_id'];
            if(!$isChef){
                $chk=$db->prepare("SELECT auteur_id FROM li_articles WHERE id=?");
                $chk->execute([$aid]);
                if($chk->fetchColumn()!=$userId) throw new Exception("Permission refusée");
            }
            $tags = !empty($_POST['tags']) ? json_encode(array_map('trim',explode(',',strip_tags($_POST['tags'])))) : null;

            $hasImgCol = false;
            try { $hasImgCol = (bool)$db->query("SHOW COLUMNS FROM li_articles LIKE 'image_principale'")->fetchColumn(); } catch (Exception $e) {}

            $sql = "UPDATE li_articles SET titre=?,chapeau=?,contenu=?,categorie_id=?,statut=?,priorite=?,tags=?,exclusif=?,a_la_une=?,confidentiel=?,date_deadline=?,notes_redaction=?";
            $params = [
                trim($_POST['titre']),
                trim($_POST['chapeau']??''),
                li_sanitize_html($_POST['contenu']??''),
                $_POST['categorie_id']?:null,
                $_POST['statut']??'brouillon',
                $_POST['priorite']??'normale',
                $tags,
                isset($_POST['exclusif'])?1:0,
                isset($_POST['a_la_une'])?1:0,
                isset($_POST['confidentiel'])?1:0,
                !empty($_POST['date_deadline'])?$_POST['date_deadline']:null,
                trim($_POST['notes_redaction']??''),
            ];
            if ($hasImgCol) {
                $sql .= ", image_principale=?";
                $params[] = trim($_POST['image_principale']??'') ?: null;
            }
            $sql .= " WHERE id=?";
            $params[] = $aid;
            $db->prepare($sql)->execute($params);

            // Si statut passe à publié, date_publication
            if (($_POST['statut']??'')==='publie') {
                $db->prepare("UPDATE li_articles SET date_publication=COALESCE(date_publication,NOW()), relecteur_id=? WHERE id=?")->execute([$userId,$aid]);
            }
            $msg="Article mis à jour."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'change_statut') {
        try {
            if(!$isChef && !in_array($_POST['new_statut'],['revision','brouillon'])) throw new Exception("Permission refusée");
            $nowPub = ($_POST['new_statut']==='publie') ? ", date_publication=NOW()" : "";
            if ($_POST['new_statut']==='refuse' && !empty($_POST['raison_refus'])) {
                $db->prepare("UPDATE li_articles SET raison_refus=? WHERE id=?")
                   ->execute([trim($_POST['raison_refus']),(int)$_POST['article_id']]);
            }
            $db->prepare("UPDATE li_articles SET statut=?,relecteur_id=?$nowPub WHERE id=?")
               ->execute([$_POST['new_statut'],$userId,(int)$_POST['article_id']]);
            $labels=['publie'=>'publié','refuse'=>'refusé','archive'=>'archivé','revision'=>'envoyé en relecture','brouillon'=>'remis en brouillon'];
            $msg="Article ".(isset($labels[$_POST['new_statut']])?$labels[$_POST['new_statut']]:$_POST['new_statut'])."."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    elseif ($action === 'delete_article') {
        try {
            if(!$isChef) throw new Exception("Permission refusée");
            $db->prepare("DELETE FROM li_articles WHERE id=?")->execute([(int)$_POST['article_id']]);
            $msg="Article supprimé."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }
}

/* ═══════════════════════════════════════════════════════════
   FILTRES + REQUÊTES
═══════════════════════════════════════════════════════════ */
$fStatut   = $_GET['statut']  ?? '';
$fCat      = $_GET['cat']     ?? '';
$fPrio     = $_GET['priorite']?? '';
$fAuteur   = $_GET['auteur']  ?? '';
$fSearch   = trim($_GET['q']  ?? '');
$openNew   = isset($_GET['open']) && $_GET['open']==='new';
$viewId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Détecter colonne image
$hasImgCol = false;
try { $hasImgCol = (bool)$db->query("SHOW COLUMNS FROM li_articles LIKE 'image_principale'")->fetchColumn(); } catch (Exception $e) {}
$imgSelect = $hasImgCol ? ', a.image_principale' : '';

$where = []; $params = [];
if(!$isChef){ $where[]='(a.auteur_id=? OR a.confidentiel=0)'; $params[]=$userId; }
if($fStatut)  { $where[]='a.statut=?';   $params[]=$fStatut; }
if($fCat)     { $where[]='a.categorie_id=?'; $params[]=$fCat; }
if($fPrio)    { $where[]='a.priorite=?'; $params[]=$fPrio; }
if($fAuteur)  { $where[]='a.auteur_id=?';$params[]=$fAuteur; }
if($fSearch)  { $where[]='(a.titre LIKE ? OR a.chapeau LIKE ?)'; $params[]="%$fSearch%"; $params[]="%$fSearch%"; }
$whereSQL = $where ? "WHERE ".implode(" AND ",$where) : "";

$articles = $db->prepare("
    SELECT a.id, a.reference, a.titre, a.chapeau, a.statut, a.priorite, a.tags,
           a.exclusif, a.a_la_une, a.confidentiel, a.nb_vues, a.nb_likes,
           a.date_publication, a.created_at, a.updated_at, a.auteur_id $imgSelect,
           c.nom AS cat_nom, c.couleur AS cat_col, c.icone AS cat_ico,
           u.username AS auteur_nom,
           p.firstname AS auteur_firstname, p.lastname AS auteur_lastname,
           (SELECT COUNT(*) FROM li_commentaires lc WHERE lc.article_id=a.id AND lc.statut='attente') nb_comm_att
    FROM li_articles a
    LEFT JOIN li_categories c ON a.categorie_id=c.id
    JOIN users u ON a.auteur_id=u.id
    LEFT JOIN profiles p ON p.id=u.profile_id
    $whereSQL
    ORDER BY FIELD(a.priorite,'flash','urgente','importante','normale'), a.created_at DESC
    LIMIT 200");
$articles->execute($params);
$articles = $articles->fetchAll(PDO::FETCH_ASSOC);

$statsQ = $db->prepare("SELECT statut,COUNT(*) n FROM li_articles ".($isChef?'':' WHERE auteur_id='.$userId)." GROUP BY statut");
$statsQ->execute(); $statsArr=[]; foreach($statsQ->fetchAll(PDO::FETCH_ASSOC) as $r) $statsArr[$r['statut']]=$r['n'];

$cats = $db->query("SELECT * FROM li_categories WHERE actif=1 ORDER BY ordre, nom")->fetchAll(PDO::FETCH_ASSOC);
$journos = $db->prepare("
    SELECT u.id, u.username, u.faction_rank,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id=u.profile_id
    WHERE u.faction=? AND u.is_active=1
    ORDER BY u.username");
$journos->execute([$companyFaction]);
$journos = $journos->fetchAll(PDO::FETCH_ASSOC);

$editArt = null;
if($viewId){
    $eq=$db->prepare("
        SELECT a.* $imgSelect, c.nom cat_nom, c.couleur cat_col, c.icone cat_ico,
               u.username auteur_nom, p.firstname auteur_firstname, p.lastname auteur_lastname
        FROM li_articles a
        LEFT JOIN li_categories c ON a.categorie_id=c.id
        JOIN users u ON a.auteur_id=u.id
        LEFT JOIN profiles p ON p.id=u.profile_id
        WHERE a.id=?");
    $eq->execute([$viewId]); $editArt=$eq->fetch(PDO::FETCH_ASSOC);
}

include __DIR__.'/includes/header.php';
?>
<style>
.art-view {
    background: var(--surface); border: 1px solid var(--bd);
    border-radius: var(--r-lg); padding: 2rem; margin-bottom: 1.5rem;
}
.art-view-hero {
    width: 100%; aspect-ratio: 21/9;
    background-size: cover; background-position: center;
    border-radius: var(--r-md); margin-bottom: 1.4rem;
    background-color: var(--surface-2);
}
.art-view-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.85rem; font-weight: 700; color: var(--tx);
    line-height: 1.25; margin-bottom: .7rem;
}
.art-view-chapeau {
    font-size: 1rem; color: var(--tx-2); font-style: italic;
    margin-bottom: 1.4rem; line-height: 1.65;
    border-left: 3px solid var(--li); padding-left: 1rem;
}
.art-view-body {
    font-size: .95rem; color: var(--tx-2); line-height: 1.85;
}
.art-view-body h1, .art-view-body h2, .art-view-body h3 {
    font-family: 'Playfair Display', serif; color: var(--tx);
    margin: 1rem 0 .5rem; font-weight: 700;
}
.art-view-body h1 { font-size: 1.65rem; }
.art-view-body h2 { font-size: 1.4rem; }
.art-view-body h3 { font-size: 1.18rem; }
.art-view-body p  { margin: .85rem 0; }
.art-view-body blockquote {
    border-left: 3px solid var(--li);
    padding: .5rem 1rem; margin: 1rem 0;
    color: var(--tx-2); font-style: italic;
    background: var(--li-softer); border-radius: 0 var(--r-sm) var(--r-sm) 0;
}
.art-view-body ul, .art-view-body ol { padding-left: 1.5rem; margin: .8rem 0; }
.art-view-body img { max-width: 100%; height: auto; border-radius: var(--r-md); margin: .8rem 0; }
.art-view-body a { color: var(--li); text-decoration: underline; }
.list-view { background: var(--surface); border: 1px solid var(--bd); border-radius: var(--r-lg); overflow: hidden; }
.view-toggle { display: flex; gap: .4rem; }
.view-btn {
    width: 32px; height: 32px;
    border: 1px solid var(--bd); border-radius: 8px;
    background: transparent; color: var(--tx-3);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
}
.view-btn.active, .view-btn:hover { background: var(--li-soft); border-color: var(--li-bd); color: var(--li); }
.cb-group { display: flex; gap: .55rem; flex-wrap: wrap; align-items: center; }
.cb-item { display: flex; align-items: center; gap: .4rem; cursor: pointer; font-size: .82rem; color: var(--tx-2); user-select: none; padding: .35rem .6rem; border: 1px solid var(--bd); border-radius: 7px; background: var(--surface-2); }
.cb-item:hover { border-color: var(--li-bd); }
.cb-item input { accent-color: var(--li); width: 14px; height: 14px; }
.cb-item input:checked + i + span { color: var(--li); font-weight: 700; }
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════
// VUE ARTICLE UNIQUE (lecture)
// ═══════════════════════════════════════════════════
if ($editArt && !isset($_GET['edit'])):
    $artDisplay = mdt_rp_display_name($editArt['auteur_firstname']??null,$editArt['auteur_lastname']??null,(string)$editArt['auteur_nom']);
    $imgUrl = $hasImgCol ? ($editArt['image_principale'] ?? '') : '';
?>
<div style="margin-bottom:1.2rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <a href="articles.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        <span style="font-size:.72rem;color:var(--tx-3);font-family:'JetBrains Mono',monospace"><?php echo htmlspecialchars($editArt['reference']); ?></span>
    </div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <a href="articles.php?id=<?php echo $editArt['id']; ?>&edit=1" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
        <?php if($isChef): ?>
        <?php if($editArt['statut']==='revision'): ?>
        <form method="POST" style="display:inline"
              data-confirm="L'article %t sera mis en ligne sur le site public et visible par tous les lecteurs."
              data-confirm-title="Publier l'article ?"
              data-confirm-tone="success"
              data-confirm-icon="fa-newspaper"
              data-confirm-ok="Publier"
              data-confirm-target="<?php echo htmlspecialchars($editArt['titre']); ?>">
            <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $editArt['id']; ?>"><input type="hidden" name="new_statut" value="publie">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Publier</button>
        </form>
        <button class="btn btn-danger btn-sm" onclick="Company.openModal('modalRefus')"><i class="fas fa-times"></i> Refuser</button>
        <?php endif; ?>
        <?php if(!in_array($editArt['statut'],['archive','publie'])): ?>
        <form method="POST" style="display:inline"
              data-confirm="L'article ne sera plus modifiable. Il restera consultable dans les archives."
              data-confirm-title="Archiver l'article ?"
              data-confirm-tone="warn"
              data-confirm-icon="fa-box-archive"
              data-confirm-ok="Archiver">
            <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $editArt['id']; ?>"><input type="hidden" name="new_statut" value="archive">
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-archive"></i> Archiver</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if($editArt['auteur_id']==$userId && $editArt['statut']==='brouillon'): ?>
        <form method="POST" style="display:inline"
              data-confirm="L'article sera envoyé en file de relecture. La rédaction en chef pourra le valider ou le refuser."
              data-confirm-title="Soumettre pour relecture ?"
              data-confirm-tone="info"
              data-confirm-icon="fa-paper-plane"
              data-confirm-ok="Soumettre">
            <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $editArt['id']; ?>"><input type="hidden" name="new_statut" value="revision">
            <button type="submit" class="btn btn-warn btn-sm"><i class="fas fa-paper-plane"></i> Soumettre</button>
        </form>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></button>
    </div>
</div>
<div class="art-view">
    <?php if (!empty($imgUrl)): ?>
    <div class="art-view-hero" style="background-image:url('<?php echo htmlspecialchars($imgUrl); ?>')"></div>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:.55rem;margin-bottom:.9rem;flex-wrap:wrap">
        <?php if($editArt['cat_nom']): ?><span class="badge" style="background:<?php echo $editArt['cat_col']; ?>22;color:<?php echo $editArt['cat_col']; ?>;border-color:<?php echo $editArt['cat_col']; ?>55"><i class="fas <?php echo $editArt['cat_ico']??'fa-newspaper'; ?>"></i> <?php echo htmlspecialchars($editArt['cat_nom']); ?></span><?php endif; ?>
        <span class="badge badge-<?php echo $editArt['statut']; ?>"><?php echo $editArt['statut']; ?></span>
        <span class="badge badge-<?php echo $editArt['priorite']; ?>"><?php echo $editArt['priorite']; ?></span>
        <?php if($editArt['exclusif']): ?><span class="badge" style="background:rgba(139,92,246,.12);color:#8b5cf6;border-color:rgba(139,92,246,.3)"><i class="fas fa-star"></i> Exclusif</span><?php endif; ?>
        <?php if($editArt['a_la_une']): ?><span class="badge" style="background:rgba(245,158,11,.12);color:#f59e0b;border-color:rgba(245,158,11,.3)"><i class="fas fa-crown"></i> À la Une</span><?php endif; ?>
        <?php if($editArt['confidentiel']): ?><span class="badge" style="background:rgba(239,68,68,.1);color:#ef4444;border-color:rgba(239,68,68,.28)"><i class="fas fa-lock"></i> Confidentiel</span><?php endif; ?>
        <span style="margin-left:auto;font-size:.72rem;color:var(--tx-3)">Par <strong style="color:var(--tx-2)"><?php echo htmlspecialchars($artDisplay); ?></strong></span>
    </div>
    <div class="art-view-title"><?php echo htmlspecialchars($editArt['titre']); ?></div>
    <?php if($editArt['chapeau']): ?><div class="art-view-chapeau"><?php echo htmlspecialchars($editArt['chapeau']); ?></div><?php endif; ?>
    <?php if($editArt['contenu']): ?>
    <div class="art-view-body"><?php echo li_sanitize_html($editArt['contenu']); ?></div>
    <?php endif; ?>
    <div style="display:flex;gap:1.2rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--bd);font-size:.78rem;color:var(--tx-3);flex-wrap:wrap">
        <span><i class="fas fa-eye" style="color:var(--li)"></i> <?php echo number_format($editArt['nb_vues']); ?> vues</span>
        <span><i class="fas fa-heart" style="color:#ef4444"></i> <?php echo number_format($editArt['nb_likes']); ?> likes</span>
        <span><i class="fas fa-share" style="color:#10b981"></i> <?php echo number_format($editArt['nb_partages']); ?> partages</span>
        <?php if($editArt['date_publication']): ?><span><i class="fas fa-calendar"></i> Publié le <?php echo date('d/m/Y H:i',strtotime($editArt['date_publication'])); ?></span><?php endif; ?>
    </div>
    <?php if($editArt['notes_redaction']): ?>
    <div style="margin-top:1rem;padding:.9rem;background:var(--li-softer);border:1px solid var(--li-bd);border-radius:9px;font-size:.82rem;color:var(--tx-2)"><i class="fas fa-sticky-note" style="color:var(--li);margin-right:.4rem"></i><?php echo nl2br(htmlspecialchars($editArt['notes_redaction'])); ?></div>
    <?php endif; ?>
    <?php if($editArt['raison_refus'] && $editArt['statut']==='refuse'): ?>
    <div style="margin-top:.8rem;padding:.9rem;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.22);border-radius:9px;font-size:.82rem;color:#ef4444"><i class="fas fa-times-circle" style="margin-right:.4rem"></i><strong>Raison du refus :</strong> <?php echo htmlspecialchars($editArt['raison_refus']); ?></div>
    <?php endif; ?>
</div>

<!-- Modal refus -->
<?php if($isChef && $editArt['statut']==='revision'): ?>
<div class="modal-overlay hidden" id="modalRefus">
    <div class="modal modal-sm">
        <div class="modal-header"><h2><i class="fas fa-times-circle"></i> Refuser l'article</h2><button class="modal-close" onclick="Company.closeModal('modalRefus')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="change_statut">
            <input type="hidden" name="article_id" value="<?php echo $editArt['id']; ?>">
            <input type="hidden" name="new_statut" value="refuse">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Raison du refus</label><textarea name="raison_refus" class="form-textarea" rows="4" placeholder="Expliquez pourquoi l'article est refusé…" required></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalRefus')">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Confirmer le refus</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════
// LISTE
// ═══════════════════════════════════════════════════
else:
?>

<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-newspaper"></i> Articles</div>
        <div class="page-header-sub">
            <strong style="color:var(--tx-2)"><?php echo count($articles); ?></strong> article<?php echo count($articles)>1?'s':''; ?> ·
            <?php echo $statsArr['publie']??0; ?> publié<?php echo ($statsArr['publie']??0)>1?'s':''; ?> ·
            <?php echo $statsArr['revision']??0; ?> en validation
        </div>
    </div>
    <div class="page-header-actions">
        <?php if($isChef && ($statsArr['revision']??0)>0): ?>
        <a href="articles.php?statut=revision" class="btn btn-warn btn-sm"><i class="fas fa-tasks"></i> Validation <span style="background:rgba(0,0,0,.18);border-radius:10px;padding:.05rem .4rem;font-size:.68rem;margin-left:.2rem"><?php echo $statsArr['revision']; ?></span></a>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="Company.openModal('modalCreate')"><i class="fas fa-pen"></i> Rédiger un article</button>
    </div>
</div>

<!-- Onglets statut -->
<div class="stat-tab">
    <a href="articles.php" class="stat-tab-item <?php echo !$fStatut?'active':''; ?>">Tous (<?php echo array_sum($statsArr); ?>)</a>
    <a href="?statut=publie<?php echo $fCat?"&cat=$fCat":''; ?>" class="stat-tab-item <?php echo $fStatut==='publie'?'active-publie':''; ?>"><i class="fas fa-check-circle"></i> Publiés (<?php echo $statsArr['publie']??0; ?>)</a>
    <a href="?statut=revision<?php echo $fCat?"&cat=$fCat":''; ?>" class="stat-tab-item <?php echo $fStatut==='revision'?'active-revision':''; ?>"><i class="fas fa-clock"></i> En révision (<?php echo $statsArr['revision']??0; ?>)</a>
    <a href="?statut=brouillon<?php echo $fCat?"&cat=$fCat":''; ?>" class="stat-tab-item <?php echo $fStatut==='brouillon'?'active-brouillon':''; ?>"><i class="fas fa-pencil-alt"></i> Brouillons (<?php echo $statsArr['brouillon']??0; ?>)</a>
    <a href="?statut=refuse<?php echo $fCat?"&cat=$fCat":''; ?>" class="stat-tab-item <?php echo $fStatut==='refuse'?'active-refuse':''; ?>"><i class="fas fa-ban"></i> Refusés (<?php echo $statsArr['refuse']??0; ?>)</a>
    <a href="?statut=archive" class="stat-tab-item <?php echo $fStatut==='archive'?'active':''; ?>"><i class="fas fa-archive"></i> Archives (<?php echo $statsArr['archive']??0; ?>)</a>
</div>

<!-- Filtres -->
<div class="filters-bar">
    <div class="filter-group" style="flex:2;min-width:180px">
        <label class="filter-lbl"><i class="fas fa-search"></i> Recherche</label>
        <input type="text" id="searchInput" class="form-input" placeholder="Titre, chapeau…" value="<?php echo htmlspecialchars($fSearch); ?>" onkeyup="applyFilters()">
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Rubrique</label>
        <select class="form-select" id="fCat" onchange="applyFilters()">
            <option value="">Toutes</option>
            <?php foreach($cats as $cat): ?><option value="<?php echo $cat['id']; ?>" <?php echo $fCat==$cat['id']?'selected':''; ?>><?php echo htmlspecialchars($cat['nom']); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-lbl">Priorité</label>
        <select class="form-select" id="fPrio" onchange="applyFilters()">
            <option value="">Toutes</option>
            <option value="flash" <?php echo $fPrio==='flash'?'selected':''; ?>>Flash</option>
            <option value="urgente" <?php echo $fPrio==='urgente'?'selected':''; ?>>Urgente</option>
            <option value="importante" <?php echo $fPrio==='importante'?'selected':''; ?>>Importante</option>
            <option value="normale" <?php echo $fPrio==='normale'?'selected':''; ?>>Normale</option>
        </select>
    </div>
    <?php if($isChef): ?>
    <div class="filter-group">
        <label class="filter-lbl">Auteur</label>
        <select class="form-select" id="fAuteur" onchange="applyFilters()">
            <option value="">Tous</option>
            <?php foreach($journos as $j):
                $jDisp = mdt_rp_display_name($j['profile_firstname']??null,$j['profile_lastname']??null,(string)$j['username']);
            ?><option value="<?php echo $j['id']; ?>" <?php echo $fAuteur==$j['id']?'selected':''; ?>><?php echo htmlspecialchars($jDisp); ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-group" style="flex:0;min-width:auto">
        <label class="filter-lbl">&nbsp;</label>
        <div style="display:flex;gap:.4rem;align-items:center">
            <div class="view-toggle">
                <button class="view-btn active" id="btnGrid" onclick="setView('grid')" title="Grille"><i class="fas fa-th-large"></i></button>
                <button class="view-btn" id="btnList" onclick="setView('list')" title="Liste"><i class="fas fa-list"></i></button>
            </div>
            <a href="articles.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
        </div>
    </div>
</div>

<?php if(empty($articles)): ?>
<div class="card"><div class="empty-state"><i class="fas fa-newspaper"></i><h3>Aucun article</h3><p>Commencez à rédiger pour faire vivre la rédaction.</p><button class="btn btn-primary" onclick="Company.openModal('modalCreate')"><i class="fas fa-pen"></i> Créer un article</button></div></div>
<?php else: ?>

<div id="articlesGrid" class="articles-grid">
    <?php foreach($articles as $a):
        $auteurDisp = mdt_rp_display_name($a['auteur_firstname']??null,$a['auteur_lastname']??null,(string)$a['auteur_nom']);
        $thumb = $hasImgCol ? ($a['image_principale'] ?? '') : '';
    ?>
    <div class="art-card">
        <?php if (!empty($thumb)): ?>
        <a href="articles.php?id=<?php echo $a['id']; ?>" class="art-card-thumb" style="background-image:url('<?php echo htmlspecialchars($thumb); ?>')"></a>
        <?php else: ?>
        <a href="articles.php?id=<?php echo $a['id']; ?>" class="art-card-thumb">
            <div class="art-card-thumb-placeholder"><i class="fas <?php echo $a['cat_ico']??'fa-newspaper'; ?>"></i></div>
        </a>
        <?php endif; ?>
        <div class="art-card-stripe" style="background:<?php echo $a['cat_col']??'var(--li)'; ?>"></div>
        <div class="art-card-body">
            <div class="art-card-meta">
                <?php if($a['cat_nom']): ?><span class="badge" style="background:<?php echo $a['cat_col']; ?>1a;color:<?php echo $a['cat_col']; ?>;border-color:<?php echo $a['cat_col']; ?>40;font-size:.6rem"><i class="fas <?php echo $a['cat_ico']??'fa-newspaper'; ?>"></i> <?php echo htmlspecialchars($a['cat_nom']); ?></span><?php endif; ?>
                <span class="badge badge-<?php echo $a['priorite']; ?>"><?php echo strtoupper($a['priorite']); ?></span>
                <?php if($a['exclusif']): ?><span class="badge" style="background:rgba(139,92,246,.12);color:#8b5cf6;border-color:rgba(139,92,246,.3);font-size:.58rem"><i class="fas fa-star"></i></span><?php endif; ?>
                <?php if($a['a_la_une']): ?><span class="badge" style="background:rgba(245,158,11,.12);color:#f59e0b;border-color:rgba(245,158,11,.28);font-size:.58rem">UNE</span><?php endif; ?>
            </div>
            <a href="articles.php?id=<?php echo $a['id']; ?>" style="color:inherit;text-decoration:none">
                <div class="art-card-title"><?php echo htmlspecialchars($a['titre']); ?></div>
            </a>
            <?php if($a['chapeau']): ?><div class="art-card-chapeau"><?php echo htmlspecialchars($a['chapeau']); ?></div><?php endif; ?>
            <div class="art-card-footer">
                <div>
                    <span class="badge badge-<?php echo $a['statut']; ?>"><?php echo $a['statut']; ?></span>
                    <span style="font-size:.68rem;color:var(--tx-3);margin-left:.4rem"><?php echo htmlspecialchars($auteurDisp); ?></span>
                </div>
                <div class="art-card-stats">
                    <span><i class="fas fa-eye"></i><?php echo number_format($a['nb_vues']); ?></span>
                    <span><i class="fas fa-heart"></i><?php echo $a['nb_likes']; ?></span>
                </div>
            </div>
            <div style="display:flex;gap:.35rem;margin-top:.75rem;flex-wrap:wrap">
                <a href="articles.php?id=<?php echo $a['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-eye"></i> Voir</a>
                <a href="articles.php?id=<?php echo $a['id']; ?>&edit=1" class="btn btn-sm btn-ghost btn-icon" title="Modifier"><i class="fas fa-edit"></i></a>
                <?php if($a['statut']==='brouillon' && ($a['auteur_id']==$userId||$isChef)): ?>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $a['id']; ?>"><input type="hidden" name="new_statut" value="revision"><button class="btn btn-sm btn-warn btn-icon" title="Soumettre en révision"><i class="fas fa-paper-plane"></i></button></form>
                <?php endif; ?>
                <?php if($a['statut']==='revision' && $isChef): ?>
                <form method="POST" style="display:inline"
                      data-confirm="L'article %t sera mis en ligne sur le site public."
                      data-confirm-title="Publier l'article ?"
                      data-confirm-tone="success"
                      data-confirm-icon="fa-newspaper"
                      data-confirm-ok="Publier"
                      data-confirm-target="<?php echo htmlspecialchars($a['titre']); ?>">
                    <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $a['id']; ?>"><input type="hidden" name="new_statut" value="publie">
                    <button type="submit" class="btn btn-sm btn-success btn-icon" title="Publier"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>
                <?php if($isChef): ?>
                <form method="POST" style="display:inline;margin-left:auto"
                      data-confirm="L'article %t sera définitivement supprimé. Cette action est irréversible."
                      data-confirm-title="Supprimer l'article ?"
                      data-confirm-tone="danger"
                      data-confirm-icon="fa-trash"
                      data-confirm-ok="Supprimer définitivement"
                      data-confirm-target="<?php echo htmlspecialchars($a['titre']); ?>">
                    <input type="hidden" name="action" value="delete_article"><input type="hidden" name="article_id" value="<?php echo $a['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Supprimer"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div id="articlesList" style="display:none" class="list-view">
    <table class="li-table">
        <thead><tr><th>Référence</th><th>Titre</th><th>Rubrique</th><th>Auteur</th><th>Priorité</th><th>Statut</th><th>Vues</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($articles as $a):
            $auteurDisp = mdt_rp_display_name($a['auteur_firstname']??null,$a['auteur_lastname']??null,(string)$a['auteur_nom']);
        ?>
        <tr>
            <td><span style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--tx-3)"><?php echo $a['reference']; ?></span></td>
            <td style="max-width:260px">
                <div style="font-weight:700;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($a['titre']); ?></div>
                <div style="font-size:.7rem;color:var(--tx-3)"><?php echo htmlspecialchars(mb_substr($a['chapeau']??'',0,60)); ?><?php if(strlen($a['chapeau']??'')>60):?>…<?php endif;?></div>
            </td>
            <td><?php if($a['cat_nom']): ?><span class="badge" style="background:<?php echo $a['cat_col']; ?>1a;color:<?php echo $a['cat_col']; ?>;border-color:<?php echo $a['cat_col']; ?>40;font-size:.6rem"><?php echo htmlspecialchars($a['cat_nom']); ?></span><?php endif; ?></td>
            <td style="font-size:.8rem"><?php echo htmlspecialchars($auteurDisp); ?></td>
            <td><span class="badge badge-<?php echo $a['priorite']; ?>"><?php echo $a['priorite']; ?></span></td>
            <td><span class="badge badge-<?php echo $a['statut']; ?>"><?php echo $a['statut']; ?></span></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:.78rem"><?php echo number_format($a['nb_vues']); ?></td>
            <td style="font-size:.75rem;color:var(--tx-3)"><?php echo date('d/m/y',strtotime($a['created_at'])); ?></td>
            <td>
                <div style="display:flex;gap:.3rem">
                    <a href="articles.php?id=<?php echo $a['id']; ?>" class="btn btn-sm btn-secondary btn-icon"><i class="fas fa-eye"></i></a>
                    <a href="articles.php?id=<?php echo $a['id']; ?>&edit=1" class="btn btn-sm btn-ghost btn-icon"><i class="fas fa-edit"></i></a>
                    <?php if($isChef): ?><form method="POST" style="display:inline"
                              data-confirm="L'article %t sera définitivement supprimé. Cette action est irréversible."
                              data-confirm-title="Supprimer l'article ?"
                              data-confirm-tone="danger"
                              data-confirm-icon="fa-trash"
                              data-confirm-ok="Supprimer définitivement"
                              data-confirm-target="<?php echo htmlspecialchars($a['titre']); ?>">
                        <input type="hidden" name="action" value="delete_article"><input type="hidden" name="article_id" value="<?php echo $a['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Supprimer"><i class="fas fa-trash"></i></button>
                    </form><?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; // fin liste vs détail ?>

<!-- ═══ MODAL CRÉER ═══ -->
<div class="modal-overlay <?php echo $openNew?'':'hidden'; ?>" id="modalCreate">
    <div class="modal modal-xl">
        <div class="modal-header"><h2><i class="fas fa-pen"></i> Rédiger un nouvel article</h2><button class="modal-close" onclick="Company.closeModal('modalCreate')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="create_article">
            <div class="modal-body">
                <div class="form-grid" style="grid-template-columns:2fr 1fr">
                    <div style="display:flex;flex-direction:column;gap:1rem">
                        <div class="form-group">
                            <label class="form-label">Titre <span style="color:#ef4444">*</span></label>
                            <input name="titre" class="form-input" placeholder="Un titre accrocheur…" required style="font-size:1.05rem;font-weight:600;font-family:'Playfair Display',serif">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Chapeau (accroche)</label>
                            <textarea name="chapeau" class="form-textarea" rows="2" placeholder="Résumé court qui donne envie de lire l'article…"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contenu de l'article</label>
                            <textarea name="contenu" data-rt-editor placeholder="Rédigez le corps de votre article ici. Utilisez la barre d'outils pour mettre en forme (titres, gras, citations, images, listes…)." data-min-height="320px"></textarea>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:1rem">
                        <div class="form-group">
                            <label class="form-label">Image principale (URL)</label>
                            <input name="image_principale" type="url" class="form-input" placeholder="https://…" oninput="document.getElementById('thumbPrev').style.backgroundImage=this.value?`url('${this.value}')`:'none'">
                            <div id="thumbPrev" style="aspect-ratio:16/9;border:1px dashed var(--bd);border-radius:var(--r-md);background-size:cover;background-position:center;background-color:var(--surface-2);margin-top:.3rem;display:flex;align-items:center;justify-content:center;color:var(--tx-mute);font-size:.78rem">
                                <span>Aperçu</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rubrique</label>
                            <select name="categorie_id" class="form-select">
                                <option value="">— Sélectionner —</option>
                                <?php foreach($cats as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priorité</label>
                            <select name="priorite" class="form-select">
                                <option value="normale">Normale</option>
                                <option value="importante">Importante</option>
                                <option value="urgente">Urgente</option>
                                <option value="flash">Flash (Breaking)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Statut initial</label>
                            <select name="statut" class="form-select">
                                <option value="brouillon">Brouillon</option>
                                <option value="revision">Envoyer en révision</option>
                                <?php if($isChef): ?><option value="publie">Publier directement</option><?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tags <span style="color:var(--tx-3);font-weight:400;text-transform:none">(virgule)</span></label>
                            <input name="tags" class="form-input" placeholder="crime, lspd, los santos…">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Deadline</label>
                            <input type="datetime-local" name="date_deadline" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Options éditoriales</label>
                            <div class="cb-group">
                                <label class="cb-item"><input type="checkbox" name="a_la_une"><i class="fas fa-crown" style="color:#f59e0b"></i><span>À la Une</span></label>
                                <label class="cb-item"><input type="checkbox" name="exclusif"><i class="fas fa-star" style="color:#8b5cf6"></i><span>Exclusif</span></label>
                                <label class="cb-item"><input type="checkbox" name="confidentiel"><i class="fas fa-lock" style="color:#ef4444"></i><span>Confidentiel</span></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes rédaction <span style="color:var(--tx-3);font-weight:400;text-transform:none">(internes)</span></label>
                            <textarea name="notes_redaction" class="form-textarea" rows="3" placeholder="Notes pour la rédaction (non publiées)…"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalCreate')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL ÉDITER ═══ -->
<?php if($editArt && isset($_GET['edit'])):
    $editImg = $hasImgCol ? ($editArt['image_principale'] ?? '') : '';
?>
<div class="modal-overlay" id="modalEdit" style="display:flex">
    <div class="modal modal-xl">
        <div class="modal-header"><h2><i class="fas fa-edit"></i> Modifier — <?php echo $editArt['reference']; ?></h2><button class="modal-close" onclick="window.location='articles.php?id=<?php echo $editArt['id']; ?>'"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_article">
            <input type="hidden" name="article_id" value="<?php echo $editArt['id']; ?>">
            <div class="modal-body">
                <div class="form-grid" style="grid-template-columns:2fr 1fr">
                    <div style="display:flex;flex-direction:column;gap:1rem">
                        <div class="form-group"><label class="form-label">Titre *</label><input name="titre" class="form-input" value="<?php echo htmlspecialchars($editArt['titre']); ?>" required style="font-size:1.05rem;font-weight:600;font-family:'Playfair Display',serif"></div>
                        <div class="form-group"><label class="form-label">Chapeau</label><textarea name="chapeau" class="form-textarea" rows="2"><?php echo htmlspecialchars($editArt['chapeau']??''); ?></textarea></div>
                        <div class="form-group">
                            <label class="form-label">Contenu</label>
                            <textarea name="contenu" data-rt-editor data-min-height="320px"><?php echo htmlspecialchars($editArt['contenu']??''); ?></textarea>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:1rem">
                        <div class="form-group">
                            <label class="form-label">Image principale (URL)</label>
                            <input name="image_principale" type="url" class="form-input" value="<?php echo htmlspecialchars($editImg); ?>" oninput="document.getElementById('thumbPrevEdit').style.backgroundImage=this.value?`url('${this.value}')`:'none'">
                            <div id="thumbPrevEdit" style="aspect-ratio:16/9;border:1px dashed var(--bd);border-radius:var(--r-md);background-size:cover;background-position:center;background-color:var(--surface-2);margin-top:.3rem;background-image:<?php echo $editImg?'url(\''.htmlspecialchars($editImg).'\')':'none'; ?>"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rubrique</label>
                            <select name="categorie_id" class="form-select">
                                <option value="">— Sélectionner —</option>
                                <?php foreach($cats as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $editArt['categorie_id']==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['nom']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priorité</label>
                            <select name="priorite" class="form-select">
                                <?php foreach(['normale','importante','urgente','flash'] as $p): ?><option value="<?php echo $p; ?>" <?php echo $editArt['priorite']===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select">
                                <option value="brouillon" <?php echo $editArt['statut']==='brouillon'?'selected':''; ?>>Brouillon</option>
                                <option value="revision" <?php echo $editArt['statut']==='revision'?'selected':''; ?>>En révision</option>
                                <?php if($isChef): ?>
                                <option value="publie" <?php echo $editArt['statut']==='publie'?'selected':''; ?>>Publié</option>
                                <option value="refuse" <?php echo $editArt['statut']==='refuse'?'selected':''; ?>>Refusé</option>
                                <option value="archive" <?php echo $editArt['statut']==='archive'?'selected':''; ?>>Archivé</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tags</label>
                            <input name="tags" class="form-input" value="<?php $t=json_decode($editArt['tags']??'[]',true); echo htmlspecialchars(is_array($t)?implode(', ',$t):''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Deadline</label>
                            <input type="datetime-local" name="date_deadline" class="form-input" value="<?php echo $editArt['date_deadline']?date('Y-m-d\TH:i',strtotime($editArt['date_deadline'])):''; ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Options</label>
                            <div class="cb-group">
                                <label class="cb-item"><input type="checkbox" name="a_la_une" <?php echo $editArt['a_la_une']?'checked':''; ?>><i class="fas fa-crown" style="color:#f59e0b"></i><span>À la Une</span></label>
                                <label class="cb-item"><input type="checkbox" name="exclusif" <?php echo $editArt['exclusif']?'checked':''; ?>><i class="fas fa-star" style="color:#8b5cf6"></i><span>Exclusif</span></label>
                                <label class="cb-item"><input type="checkbox" name="confidentiel" <?php echo $editArt['confidentiel']?'checked':''; ?>><i class="fas fa-lock" style="color:#ef4444"></i><span>Confidentiel</span></label>
                            </div>
                        </div>
                        <div class="form-group"><label class="form-label">Notes rédaction</label><textarea name="notes_redaction" class="form-textarea" rows="3"><?php echo htmlspecialchars($editArt['notes_redaction']??''); ?></textarea></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="articles.php?id=<?php echo $editArt['id']; ?>" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
let currentView = localStorage.getItem('li_art_view') || 'grid';
function setView(v) {
    currentView = v;
    localStorage.setItem('li_art_view', v);
    const grid = document.getElementById('articlesGrid');
    const list = document.getElementById('articlesList');
    if (grid) grid.style.display = v === 'grid' ? 'grid' : 'none';
    if (list) list.style.display = v === 'list' ? 'block' : 'none';
    document.getElementById('btnGrid')?.classList.toggle('active', v === 'grid');
    document.getElementById('btnList')?.classList.toggle('active', v === 'list');
}
document.addEventListener('DOMContentLoaded', () => setView(currentView));

function applyFilters() {
    const url = new URL(window.location.href);
    const q = document.getElementById('searchInput')?.value || '';
    const cat = document.getElementById('fCat')?.value || '';
    const prio = document.getElementById('fPrio')?.value || '';
    const auteur = document.getElementById('fAuteur')?.value || '';
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (cat) url.searchParams.set('cat', cat); else url.searchParams.delete('cat');
    if (prio) url.searchParams.set('priorite', prio); else url.searchParams.delete('priorite');
    if (auteur) url.searchParams.set('auteur', auteur); else url.searchParams.delete('auteur');
    clearTimeout(window._artFilterTO);
    window._artFilterTO = setTimeout(() => window.location = url, 400);
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
