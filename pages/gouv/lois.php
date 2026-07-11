<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (($_SESSION['user_faction']??'')<>'GOUV' && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','DOJ','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Textes de Loi';
$db = Database::getInstance()->getConnection();
$uid = (int)($_SESSION['user_id']??0);
$msg = trim($_GET['msg']??''); $msgType = trim($_GET['mt']??'success'); if($msg===''){$msg='';$msgType='';}
$canEdit  = hasRole(['*','Administrateur','Développeur','Staff']) || ($_SESSION['user_faction']??'')==='GOUV';
$canAdmin = hasRole(['*','Administrateur','Développeur','Staff']);

// ── ACTIONS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && $canEdit) {
    $action = $_POST['action']??'';

    if ($action==='create_law') {
        try {
            $db->prepare("INSERT INTO gov_laws (code,title,description,type,version,status,sort_order,author_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([strtoupper(trim($_POST['code'])),trim($_POST['title']),trim($_POST['description']??''),$_POST['type']??'code',trim($_POST['version']??'1.0'),$_POST['status']??'en_vigueur',intval($_POST['sort_order']??0),$uid]);
            $msg='Code de loi créé avec succès.'; $msgType='success';
            header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage(); $msgType='error';}
    }

    if ($action==='edit_law') {
        try {
            $db->prepare("UPDATE gov_laws SET title=?,description=?,type=?,version=?,status=?,sort_order=? WHERE id=?")
               ->execute([trim($_POST['title']),trim($_POST['description']??''),$_POST['type']??'code',trim($_POST['version']??'1.0'),$_POST['status']??'en_vigueur',intval($_POST['sort_order']??0),intval($_POST['law_id'])]);
            $msg='Code mis à jour.'; $msgType='success';
            header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage(); $msgType='error';}
    }

    if ($action==='delete_law' && $canAdmin) {
        $lid = intval($_POST['law_id']);
        $db->prepare("DELETE FROM gov_law_amendments WHERE article_id IN (SELECT id FROM gov_law_articles WHERE law_id=?)")->execute([$lid]);
        $db->prepare("DELETE FROM gov_law_articles WHERE law_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM gov_laws WHERE id=?")->execute([$lid]);
        $msg='Code supprimé.'; $msgType='success';
        header('Location: lois.php?msg='.urlencode($msg).'&mt=success'); exit;
    }

    if ($action==='create_article') {
        try {
            $lid = intval($_POST['law_id']);
            if(!$lid) throw new Exception('Loi invalide.');
            $db->prepare("INSERT INTO gov_law_articles (law_id,chapter,chapter_title,section,article_number,title,content,status,sort_order,author_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$lid,trim($_POST['chapter']??''),trim($_POST['chapter_title']??''),trim($_POST['section']??''),trim($_POST['article_number']),trim($_POST['article_title']??''),trim($_POST['content']),$_POST['status']??'en_vigueur',intval($_POST['sort_order']??0),$uid]);
            $msg='Article créé.'; $msgType='success';
            header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage(); $msgType='error';}
    }

    if ($action==='edit_article') {
        try {
            $aid = intval($_POST['article_id']);
            $old = $db->prepare("SELECT content FROM gov_law_articles WHERE id=?");
            $old->execute([$aid]); $oldRow = $old->fetch();
            $db->prepare("UPDATE gov_law_articles SET chapter=?,chapter_title=?,section=?,article_number=?,title=?,content=?,status=?,sort_order=? WHERE id=?")
               ->execute([trim($_POST['chapter']??''),trim($_POST['chapter_title']??''),trim($_POST['section']??''),trim($_POST['article_number']),trim($_POST['article_title']??''),trim($_POST['content']),$_POST['status']??'en_vigueur',intval($_POST['sort_order']??0),$aid]);
            if(($oldRow['content']??'') !== trim($_POST['content'])){
                $db->prepare("INSERT INTO gov_law_amendments (article_id,type,old_content,new_content,motif,author_id,date_effect,status) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$aid,'modification',$oldRow['content']??'',trim($_POST['content']),trim($_POST['motif']??''),$uid,date('Y-m-d'),'adopte']);
            }
            $msg='Article mis à jour.'; $msgType='success';
            header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
        } catch(Exception $e){$msg='Erreur: '.$e->getMessage(); $msgType='error';}
    }

    if ($action==='delete_article') {
        $aid = intval($_POST['article_id']);
        $db->prepare("DELETE FROM gov_law_amendments WHERE article_id=?")->execute([$aid]);
        $db->prepare("DELETE FROM gov_law_articles WHERE id=?")->execute([$aid]);
        $msg='Article supprimé.'; $msgType='success';
        header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
    }

    if ($action==='toggle_article_status') {
        $db->prepare("UPDATE gov_law_articles SET status=? WHERE id=?")->execute([$_POST['new_status'],intval($_POST['article_id'])]);
        $msg='Statut mis à jour.'; $msgType='success';
        header('Location: lois.php?law='.$activeLawId.'&msg='.urlencode($msg).'&mt=success'); exit;
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────
$activeLawId = intval($_GET['law']??0);
$viewArticleId = intval($_GET['article']??0);
$search = trim($_GET['q']??'');
$fStatus = $_GET['s']??'en_vigueur';
$fChapter = trim($_GET['ch']??'');
$fSection = trim($_GET['sec']??'');

// Load all laws with article counts
$laws = [];
try {
    $laws = $db->query(
        "SELECT l.*,
            (SELECT COUNT(*) FROM gov_law_articles WHERE law_id=l.id) AS nb_articles,
            (SELECT COUNT(*) FROM gov_law_articles WHERE law_id=l.id AND status='en_vigueur') AS nb_actifs
         FROM gov_laws l ORDER BY l.sort_order, l.code"
    )->fetchAll();
} catch(Exception $e){}

// Determine active law
$activeLaw = null;
if ($activeLawId > 0) {
    foreach($laws as $l) if($l['id']==$activeLawId){ $activeLaw=$l; break; }
}
if (!$activeLaw && !empty($laws)) {
    $activeLaw = $laws[0];
    $activeLawId = $activeLaw['id'];
}

// Chapters + sections for active law
$chapters = []; $sections = [];
if ($activeLawId > 0) {
    try {
        $ch = $db->prepare("SELECT DISTINCT chapter, chapter_title FROM gov_law_articles WHERE law_id=? AND chapter!='' ORDER BY sort_order");
        $ch->execute([$activeLawId]);
        $chapters = $ch->fetchAll();
    } catch(Exception $e){}
    try {
        $sc = $db->prepare("SELECT DISTINCT section FROM gov_law_articles WHERE law_id=? AND section!='' ORDER BY sort_order");
        $sc->execute([$activeLawId]);
        $sections = array_column($sc->fetchAll(), 'section');
    } catch(Exception $e){}
}

// Load articles for active law
$articles = [];
if ($activeLawId > 0) {
    $q = "SELECT a.*, u.username AS author FROM gov_law_articles a LEFT JOIN users u ON u.id=a.author_id WHERE a.law_id=?";
    $params = [$activeLawId];
    if ($fStatus !== 'all') { $q .= " AND a.status=?"; $params[] = $fStatus; }
    if ($fChapter !== '')   { $q .= " AND a.chapter=?"; $params[] = $fChapter; }
    if ($fSection !== '')   { $q .= " AND a.section=?"; $params[] = $fSection; }
    if ($search !== '') {
        $q .= " AND (a.article_number LIKE ? OR a.title LIKE ? OR a.content LIKE ?)";
        $s = "%$search%"; $params[] = $s; $params[] = $s; $params[] = $s;
    }
    $q .= " ORDER BY a.sort_order, a.chapter, a.article_number";
    try {
        $stmt = $db->prepare($q); $stmt->execute($params); $articles = $stmt->fetchAll();
    } catch(Exception $e){}
}

// Group articles by chapter then section
$grouped = [];
foreach($articles as $a) {
    $ch  = $a['chapter'] ?: '__none__';
    $sec = $a['section'] ?: '__none__';
    $grouped[$ch][$sec][] = $a;
}

// Single article view
$viewArticle = null; $amendments = [];
if ($viewArticleId > 0) {
    try {
        $s = $db->prepare("SELECT a.*, l.code AS law_code, l.title AS law_title FROM gov_law_articles a JOIN gov_laws l ON l.id=a.law_id WHERE a.id=?");
        $s->execute([$viewArticleId]); $viewArticle = $s->fetch();
    } catch(Exception $e){}
    if ($viewArticle) {
        try {
            $s = $db->prepare("SELECT am.*, u.username AS author FROM gov_law_amendments am LEFT JOIN users u ON u.id=am.author_id WHERE am.article_id=? ORDER BY am.created_at DESC LIMIT 15");
            $s->execute([$viewArticleId]); $amendments = $s->fetchAll();
        } catch(Exception $e){}
    }
}

// Is LP (Livret des Peines = table format)
$isLP = ($activeLaw['code']??'') === 'LP';

$LTYPES  = ['constitution'=>['Constitution','#7c3aed'],'code'=>['Code','#1d4ed8'],'loi'=>['Loi','#0369a1'],'decret'=>['Décret','#0e7490'],'autre'=>['Autre','#475569']];
$LSTATUS = ['en_vigueur'=>['En vigueur','#10b981'],'en_revision'=>['En révision','#f59e0b'],'abroge'=>['Abrogé','#ef4444'],'projet'=>['Projet','#6b7280']];
$ASTATUS = ['en_vigueur'=>['En vigueur','bg-green'],'abroge'=>['Abrogé','bg-red'],'modifie'=>['Modifié','bg-yellow']];
$LP_SECTION_COLOR = ['Crime'=>'#ef4444','Délit Majeur'=>'#f97316','Délit Mineur'=>'#f59e0b','Infraction'=>'#3b82f6','Financier'=>'#8b5cf6'];

include __DIR__ . '/includes/header.php';
?>
<style>
:root{--gov:#1d4ed8;--gov-d:#1e3a8a;--gov-l:#93c5fd;--gov-xl:#dbeafe;--bdr:rgba(29,78,216,.15);--bdr-h:rgba(29,78,216,.38)}
.loi-layout{display:grid;grid-template-columns:270px 1fr;height:calc(100vh - 145px);border:1px solid var(--bdr);border-radius:14px;overflow:hidden;background:var(--bg)}
.loi-sb{border-right:1px solid var(--bdr);overflow-y:auto;background:var(--bg-c);display:flex;flex-direction:column}
.loi-sb::-webkit-scrollbar{width:3px}.loi-sb::-webkit-scrollbar-thumb{background:rgba(29,78,216,.2)}
.loi-sb-head{padding:.9rem 1rem .75rem;border-bottom:1px solid var(--bdr);flex-shrink:0;background:rgba(29,78,216,.03)}
.loi-sb-title{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(29,78,216,.7);display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem}
.loi-item{padding:.65rem 1rem;cursor:pointer;transition:all .17s;border-bottom:1px solid rgba(255,255,255,.025);display:flex;align-items:flex-start;gap:.65rem;position:relative}
.loi-item:hover{background:rgba(29,78,216,.06)}
.loi-item.active{background:rgba(29,78,216,.12);border-right:3px solid var(--gov)}
.loi-item-badge{flex-shrink:0;width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-family:'IBM Plex Mono',monospace;font-weight:800;font-size:.62rem;text-align:center;line-height:1.1}
.loi-item-title{font-size:.78rem;font-weight:600;line-height:1.3;margin-bottom:.12rem;color:var(--txt)}
.loi-item-meta{font-size:.63rem;color:var(--txt-m);display:flex;gap:.35rem;align-items:center;flex-wrap:wrap}
.loi-main{overflow-y:auto;display:flex;flex-direction:column;background:var(--bg)}
.loi-main::-webkit-scrollbar{width:3px}.loi-main::-webkit-scrollbar-thumb{background:rgba(29,78,216,.2)}
.loi-topbar{position:sticky;top:0;z-index:50;background:rgba(5,9,15,.97);backdrop-filter:blur(12px);border-bottom:1px solid var(--bdr);padding:.8rem 1.25rem;flex-shrink:0}
.loi-content{padding:1.1rem 1.25rem;flex:1}
.chapter-hdr{padding:.58rem 1.25rem;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(29,78,216,.65);background:rgba(29,78,216,.04);border-top:1px solid var(--bdr);border-bottom:1px solid var(--bdr);margin:.75rem 0 .55rem;position:sticky;top:57px;z-index:10;backdrop-filter:blur(5px);display:flex;align-items:center;justify-content:space-between}
.section-hdr{padding:.38rem 1.25rem;font-size:.62rem;color:var(--txt-m);background:rgba(255,255,255,.02);border-bottom:1px solid rgba(255,255,255,.03);margin-bottom:.35rem}
.article-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;margin-bottom:.6rem;overflow:hidden;transition:border-color .2s}
.article-card:hover{border-color:var(--bdr-h)}
.article-card.abroge{opacity:.55;border-style:dashed}
.article-head{padding:.78rem 1rem;display:flex;align-items:center;gap:.75rem;cursor:pointer;user-select:none}
.article-num{font-family:'IBM Plex Mono',monospace;font-size:.74rem;font-weight:800;color:var(--gov-xl);background:rgba(29,78,216,.1);padding:.2rem .6rem;border-radius:5px;border:1px solid rgba(29,78,216,.2);flex-shrink:0;white-space:nowrap}
.article-title-txt{font-weight:700;font-size:.845rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.article-body{padding:.85rem 1rem;border-top:1px solid rgba(255,255,255,.04);font-size:.845rem;line-height:1.85;color:var(--txt-s);white-space:pre-wrap;max-height:600px;overflow-y:auto}
.article-body::-webkit-scrollbar{width:3px}.article-body::-webkit-scrollbar-thumb{background:rgba(29,78,216,.15)}
.loi-search-box{position:relative;flex:1}
.loi-search-box i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.75rem;pointer-events:none}
.loi-search-box input{width:100%;padding:.52rem .75rem .52rem 2.1rem;background:rgba(0,0,0,.3);border:1px solid rgba(29,78,216,.2);border-radius:8px;color:var(--txt);font-family:inherit;font-size:.79rem}
.loi-search-box input:focus{outline:none;border-color:rgba(29,78,216,.5);box-shadow:0 0 0 3px rgba(29,78,216,.1)}
/* LP TABLE */
.lp-table{width:100%;border-collapse:collapse}
.lp-table th{text-align:left;padding:.65rem .85rem;background:rgba(29,78,216,.07);border-bottom:2px solid rgba(29,78,216,.2);font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txt-s)}
.lp-table td{padding:.62rem .85rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem}
.lp-table tr:hover td{background:rgba(29,78,216,.04)}
.lp-badge{display:inline-flex;align-items:center;padding:.18rem .55rem;border-radius:4px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
/* Article detail */
.detail-card{background:var(--bg-c);border:1px solid var(--bdr);border-radius:13px;overflow:hidden}
.detail-hd{padding:1.1rem 1.35rem;background:rgba(29,78,216,.06);border-bottom:1px solid var(--bdr)}
.detail-body{padding:1.35rem;font-size:.885rem;line-height:2;color:var(--txt);white-space:pre-wrap}
.timeline-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:.28rem}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem 2rem;text-align:center;flex:1}
/* Filter bar */
.filter-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.filter-chip{padding:.3rem .7rem;border-radius:6px;font-size:.73rem;font-weight:600;cursor:pointer;text-decoration:none;border:1px solid var(--bdr);color:var(--txt-s);background:transparent;transition:all .17s;white-space:nowrap}
.filter-chip:hover,.filter-chip.active{background:rgba(29,78,216,.1);border-color:rgba(29,78,216,.3);color:var(--gov-xl)}
@media(max-width:1100px){.loi-layout{grid-template-columns:1fr;height:auto}}
</style>

<?php if($msg):?><div class="alert alert-<?php echo $msgType;?>" style="margin-bottom:1rem"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle';?>"></i> <?php echo htmlspecialchars($msg);?></div><?php endif;?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.65rem">
    <div>
        <div style="font-size:.62rem;font-family:'IBM Plex Mono',monospace;color:var(--txt-m);margin-bottom:.15rem">PANEL GOUVERNEMENT</div>
        <div style="font-family:'Syne',serif;font-size:1.05rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.55rem"><i class="fas fa-balance-scale" style="color:var(--gov-l)"></i>Textes de Loi — Législation de l'État de San Andreas</div>
    </div>
    <?php if($canEdit):?>
    <button onclick="Gov.openModal('newLawModal')" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Nouveau code</button>
    <?php endif;?>
</div>

<div class="loi-layout">

<!-- ── SIDEBAR ──────────────────────────────────────────────────────── -->
<div class="loi-sb">
    <div class="loi-sb-head">
        <div class="loi-sb-title"><i class="fas fa-landmark"></i> <?php echo count($laws);?> Codes & Constitutions</div>
        <input type="text" id="lawFilter" class="form-input" style="font-size:.75rem;padding:.38rem .65rem" placeholder="Filtrer..." oninput="filterLaws(this.value)">
    </div>
    <?php foreach($laws as $l):
        [$lt,$lc] = $LTYPES[$l['type']]??['Code','#1d4ed8'];
        [$sl,$sc] = $LSTATUS[$l['status']]??['—','#94a3b8'];
        $isActive = $l['id']==$activeLawId;
    ?>
    <div class="loi-item <?php echo $isActive?'active':'';?>" onclick="location.href='?law=<?php echo $l['id'];?>'" data-search="<?php echo strtolower(htmlspecialchars($l['title'].' '.$l['code'].' '.$lt));?>">
        <div class="loi-item-badge" style="background:<?php echo $lc;?>15;border:1.5px solid <?php echo $lc;?>30;color:<?php echo $lc;?>">
            <?php echo htmlspecialchars($l['code']);?>
        </div>
        <div style="flex:1;min-width:0">
            <div class="loi-item-title"><?php echo htmlspecialchars($l['title']);?></div>
            <div class="loi-item-meta">
                <span style="color:<?php echo $lc;?>"><?php echo $lt;?></span>
                <span>·</span>
                <span style="color:<?php echo $sc;?>"><?php echo $sl;?></span>
                <?php if($l['nb_articles']>0):?>
                <span>·</span>
                <span style="color:var(--txt-m)"><?php echo $l['nb_actifs'];?>/<?php echo $l['nb_articles'];?> art.</span>
                <?php endif;?>
            </div>
        </div>
    </div>
    <?php endforeach;?>
</div><!-- /loi-sb -->

<!-- ── MAIN ─────────────────────────────────────────────────────────── -->
<div class="loi-main">

<?php if($viewArticle):
    [$asl,$asb] = $ASTATUS[$viewArticle['status']]??['—','bg-gray'];
?>
<!-- ── VUE ARTICLE DÉTAIL ──────────────────────────────────────────── -->
<div class="loi-topbar">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <a href="?law=<?php echo $activeLawId;?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        <span class="mono" style="font-size:.72rem;color:var(--gov-xl)"><?php echo htmlspecialchars($viewArticle['law_code']);?></span>
        <span style="font-size:.72rem;color:var(--txt-m)"><?php echo htmlspecialchars($viewArticle['law_title']);?></span>
        <span class="badge <?php echo $asb;?>" style="font-size:.62rem"><?php echo $asl;?></span>
        <?php if($canEdit):?>
        <div style="margin-left:auto;display:flex;gap:.4rem">
            <button onclick="openEditArticle(<?php echo htmlspecialchars(json_encode($viewArticle));?>)" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i> Modifier</button>
            <?php if($viewArticle['status']==='en_vigueur'):?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="toggle_article_status"><input type="hidden" name="article_id" value="<?php echo $viewArticle['id'];?>"><input type="hidden" name="new_status" value="abroge"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Abroger cet article ?')"><i class="fas fa-ban"></i> Abroger</button></form>
            <?php endif;?>
            <?php if($canAdmin):?>
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="delete_article"><input type="hidden" name="article_id" value="<?php echo $viewArticle['id'];?>"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Supprimer définitivement ?')"><i class="fas fa-trash"></i></button></form>
            <?php endif;?>
        </div>
        <?php endif;?>
    </div>
</div>
<div class="loi-content">
    <div class="detail-card">
        <div class="detail-hd">
            <div style="display:flex;align-items:flex-start;gap:.85rem">
                <span style="font-family:'IBM Plex Mono',monospace;font-size:.88rem;font-weight:800;color:var(--gov-xl);background:rgba(29,78,216,.12);padding:.28rem .75rem;border-radius:7px;border:1px solid rgba(29,78,216,.22);flex-shrink:0"><?php echo htmlspecialchars($viewArticle['article_number']);?></span>
                <div style="flex:1">
                    <?php if($viewArticle['chapter']):?><div style="font-size:.72rem;color:var(--txt-m);margin-bottom:.2rem"><i class="fas fa-book-open" style="color:var(--gov-l);margin-right:.35rem"></i>Chapitre <?php echo htmlspecialchars($viewArticle['chapter']);?><?php if($viewArticle['chapter_title']):?> — <?php echo htmlspecialchars($viewArticle['chapter_title']);?><?php endif;?></div><?php endif;?>
                    <?php if($viewArticle['section']):?><div style="font-size:.72rem;color:var(--txt-m);margin-bottom:.2rem"><?php echo htmlspecialchars($viewArticle['section']);?></div><?php endif;?>
                    <?php if($viewArticle['title']):?><div style="font-family:'Syne',serif;font-size:.98rem;font-weight:800;color:#fff"><?php echo htmlspecialchars($viewArticle['title']);?></div><?php endif;?>
                </div>
            </div>
        </div>
        <div class="detail-body"><?php echo htmlspecialchars($viewArticle['content']);?></div>
        <?php if(!empty($amendments)):?>
        <div style="padding:1.1rem 1.35rem;border-top:1px solid var(--bdr)">
            <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:rgba(29,78,216,.7);margin-bottom:.75rem"><i class="fas fa-history" style="color:var(--gov-l);margin-right:.4rem"></i>Historique (<?php echo count($amendments);?> modification(s))</div>
            <?php foreach($amendments as $am):$atc=['modification'=>'#f59e0b','abrogation'=>'#ef4444','ajout'=>'#10b981','correction'=>'#3b82f6'][$am['type']]??'#94a3b8';?>
            <div style="display:flex;gap:.75rem;padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
                <div class="timeline-dot" style="background:<?php echo $atc;?>"></div>
                <div style="flex:1">
                    <div style="font-size:.74rem;font-weight:700;color:<?php echo $atc;?>"><?php echo ucfirst($am['type']);?></div>
                    <?php if($am['motif']):?><div style="font-size:.74rem;color:var(--txt-s)"><?php echo htmlspecialchars($am['motif']);?></div><?php endif;?>
                    <div style="font-size:.67rem;color:var(--txt-m)">Par <?php echo htmlspecialchars($am['author']??'—');?> · <?php echo date('d/m/Y H:i',strtotime($am['created_at']));?></div>
                </div>
            </div>
            <?php endforeach;?>
        </div>
        <?php endif;?>
    </div>
</div>

<?php elseif($activeLaw):
    [$lt,$lc] = $LTYPES[$activeLaw['type']]??['Code','#1d4ed8'];
    [$sl,$sc] = $LSTATUS[$activeLaw['status']]??['—','#94a3b8'];
?>
<!-- ── VUE LISTE ARTICLES ──────────────────────────────────────────── -->
<div class="loi-topbar">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.65rem">
        <span style="font-family:'IBM Plex Mono',monospace;font-size:.75rem;font-weight:800;color:<?php echo $lc;?>;background:<?php echo $lc;?>15;padding:.22rem .65rem;border-radius:6px;border:1px solid <?php echo $lc;?>30"><?php echo htmlspecialchars($activeLaw['code']);?></span>
        <span style="font-weight:700;font-size:.88rem;color:#fff"><?php echo htmlspecialchars($activeLaw['title']);?></span>
        <span style="font-size:.65rem;color:<?php echo $sc;?>"><?php echo $sl;?></span>
        <span class="badge" style="background:<?php echo $lc;?>15;color:<?php echo $lc;?>;border:1px solid <?php echo $lc;?>30;font-size:.62rem"><?php echo $activeLaw['nb_actifs'];?>/<?php echo $activeLaw['nb_articles'];?> articles</span>
        <?php if($canEdit):?>
        <div style="margin-left:auto;display:flex;gap:.4rem">
            <button onclick="Gov.openModal('newArticleModal')" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Article</button>
            <button onclick="openEditLaw(<?php echo htmlspecialchars(json_encode($activeLaw));?>)" class="btn btn-ghost btn-sm" title="Paramètres"><i class="fas fa-cog"></i></button>
        </div>
        <?php endif;?>
    </div>
    <!-- Filtres -->
    <form method="GET" style="display:flex;gap:.45rem;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="law" value="<?php echo $activeLawId;?>">
        <div class="loi-search-box" style="flex:1;min-width:180px"><i class="fas fa-search"></i><input type="text" name="q" value="<?php echo htmlspecialchars($search);?>" placeholder="Rechercher dans les articles..."></div>
        <select name="s" class="form-select" style="font-size:.75rem;padding:.38rem .65rem;width:130px" onchange="this.form.submit()">
            <option value="all">Tous statuts</option>
            <option value="en_vigueur" <?php echo $fStatus==='en_vigueur'?'selected':'';?>>En vigueur</option>
            <option value="abroge" <?php echo $fStatus==='abroge'?'selected':'';?>>Abrogés</option>
            <option value="modifie" <?php echo $fStatus==='modifie'?'selected':'';?>>Modifiés</option>
        </select>
        <?php if(!empty($chapters)):?>
        <select name="ch" class="form-select" style="font-size:.75rem;padding:.38rem .65rem;width:140px" onchange="this.form.submit()">
            <option value="">Tous chapitres</option>
            <?php foreach($chapters as $ch):?><option value="<?php echo htmlspecialchars($ch['chapter']);?>" <?php echo $fChapter===$ch['chapter']?'selected':'';?>>Chap. <?php echo htmlspecialchars($ch['chapter']);?> <?php if($ch['chapter_title']):?>— <?php echo htmlspecialchars(mb_substr($ch['chapter_title'],0,20));?><?php endif;?></option><?php endforeach;?>
        </select>
        <?php endif;?>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
        <?php if($search||$fStatus!=='en_vigueur'||$fChapter||$fSection):?><a href="?law=<?php echo $activeLawId;?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a><?php endif;?>
    </form>
</div>

<div class="loi-content">
<?php if(empty($articles)):?>
<div class="empty-state">
    <i class="fas fa-scroll" style="font-size:3.5rem;opacity:.1;display:block;margin-bottom:1rem"></i>
    <div style="font-weight:700;color:var(--txt-s);margin-bottom:.5rem">Aucun article<?php echo $search?' correspondant à "'.$search.'"':'';?></div>
    <?php if($canEdit && !$search):?><button onclick="Gov.openModal('newArticleModal')" class="btn btn-primary" style="margin-top:1rem"><i class="fas fa-plus"></i> Ajouter le premier article</button><?php endif;?>
</div>

<?php elseif($isLP):
// ── LIVRET DES PEINES — tableau ───────────────────────────────────────
$lpBySection = [];
foreach($articles as $a) $lpBySection[$a['section']?: 'Autre'][] = $a;
$LP_SECTION_ORDER = ['Crime','Délit Majeur','Délit Mineur','Infraction','Financier','Autre'];
uksort($lpBySection, fn($a,$b) => (array_search($a,$LP_SECTION_ORDER)??99) - (array_search($b,$LP_SECTION_ORDER)??99));
?>
<?php foreach($lpBySection as $secName => $secArts):
    $secColor = $LP_SECTION_COLOR[$secName]??'#94a3b8';
?>
<div style="margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.65rem;padding:.55rem .9rem;background:<?php echo $secColor;?>10;border:1px solid <?php echo $secColor;?>30;border-radius:9px">
        <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $secColor;?>;flex-shrink:0"></span>
        <span style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:<?php echo $secColor;?>"><?php echo htmlspecialchars($secName);?></span>
        <span style="font-size:.7rem;color:var(--txt-m)">(<?php echo count($secArts);?> peines)</span>
    </div>
    <div class="tbl-wrap" style="margin-bottom:0">
    <table class="lp-table">
        <thead><tr><th>Article</th><th>Infraction</th><th>Prison</th><th>Amende</th><th>Aggravantes</th><th></th></tr></thead>
        <tbody>
        <?php foreach($secArts as $a):
            // Parse content to extract fields
            $prison = '—'; $amende = '—'; $agg = '';
            foreach(explode("\n",$a['content']) as $line){
                if(str_starts_with($line,'Temps de prison :')) $prison=trim(substr($line,17));
                if(str_starts_with($line,'Amende :')) $amende=trim(substr($line,8));
                if(str_starts_with($line,'Circonstances aggravantes :')) $agg=trim(substr($line,27));
            }
            if($prison==='Aucun') $prison='—';
        ?>
        <tr onclick="location.href='?law=<?php echo $activeLawId;?>&article=<?php echo $a['id'];?>'" style="cursor:pointer">
            <td><span class="mono" style="font-size:.72rem;color:var(--gov-xl)"><?php echo htmlspecialchars($a['article_number']);?></span></td>
            <td style="font-weight:600"><?php echo htmlspecialchars($a['title']);?></td>
            <td style="color:<?php echo $prison==='—'?'var(--txt-m)':'#fca5a5';?>"><?php echo htmlspecialchars($prison);?></td>
            <td style="font-family:'IBM Plex Mono',monospace;color:<?php echo $amende==='—'?'var(--txt-m)':'#fde68a';?>"><?php echo htmlspecialchars($amende);?></td>
            <td style="font-size:.75rem;color:var(--txt-m)"><?php echo $agg?htmlspecialchars(mb_substr($agg,0,40)):'—';?></td>
            <td><a href="?law=<?php echo $activeLawId;?>&article=<?php echo $a['id'];?>" class="btn btn-ghost btn-xs" onclick="event.stopPropagation()"><i class="fas fa-expand"></i></a></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach;?>

<?php else:
// ── VUE ACCORDÉON NORMALE ──────────────────────────────────────────────
// Articles sans chapitre en premier
$noChapter = $grouped['__none__']['__none__'] ?? [];
foreach($noChapter as $a): [$asl,$asb]=$ASTATUS[$a['status']]??['—','bg-gray'];?>
<div class="article-card <?php echo $a['status'];?>" id="art-<?php echo $a['id'];?>">
    <div class="article-head" onclick="toggleArt(<?php echo $a['id'];?>)">
        <span class="article-num"><?php echo htmlspecialchars($a['article_number']);?></span>
        <div class="article-title-txt"><?php echo htmlspecialchars($a['title']??'');?></div>
        <span class="badge <?php echo $asb;?>" style="font-size:.6rem;flex-shrink:0"><?php echo $asl;?></span>
        <div style="display:flex;gap:.3rem;margin-left:.65rem;flex-shrink:0" onclick="event.stopPropagation()">
            <a href="?law=<?php echo $activeLawId;?>&article=<?php echo $a['id'];?>" class="btn btn-ghost btn-xs" title="Détail"><i class="fas fa-expand"></i></a>
            <?php if($canEdit):?><button onclick="openEditArticle(<?php echo htmlspecialchars(json_encode($a));?>)" class="btn btn-ghost btn-xs"><i class="fas fa-pen"></i></button><?php endif;?>
        </div>
        <i class="fas fa-chevron-down" id="chevron-<?php echo $a['id'];?>" style="font-size:.7rem;color:var(--txt-m);flex-shrink:0;transition:transform .2s"></i>
    </div>
    <div class="article-body hidden" id="body-<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['content']);?></div>
</div>
<?php endforeach;?>

<?php
// Groupes avec chapitre
foreach($grouped as $chKey => $sections):
    if($chKey === '__none__') continue;
    // Get chapter title from first article
    $chTitle = '';
    $chCount = 0;
    foreach($sections as $sArts) { $chCount+=count($sArts); if(!$chTitle&&!empty($sArts[0]['chapter_title'])) $chTitle=$sArts[0]['chapter_title']; }
?>
<div class="chapter-hdr">
    <span><i class="fas fa-book-open" style="margin-right:.45rem;opacity:.6"></i>Chapitre <?php echo htmlspecialchars($chKey);?><?php if($chTitle):?> — <?php echo htmlspecialchars($chTitle);?><?php endif;?></span>
    <span style="font-size:.65rem;color:var(--txt-m);font-weight:400"><?php echo $chCount;?> article(s)</span>
</div>
<?php
    foreach($sections as $secKey => $sArts):
        if($secKey !== '__none__'):?>
<div class="section-hdr"><i class="fas fa-minus" style="font-size:.6rem;margin-right:.45rem;opacity:.4"></i><?php echo htmlspecialchars($secKey);?></div>
        <?php endif;?>
        <?php foreach($sArts as $a): [$asl,$asb]=$ASTATUS[$a['status']]??['—','bg-gray'];?>
<div class="article-card <?php echo $a['status'];?>" id="art-<?php echo $a['id'];?>">
    <div class="article-head" onclick="toggleArt(<?php echo $a['id'];?>)">
        <span class="article-num"><?php echo htmlspecialchars($a['article_number']);?></span>
        <div class="article-title-txt"><?php echo htmlspecialchars($a['title']??'');?></div>
        <span class="badge <?php echo $asb;?>" style="font-size:.6rem;flex-shrink:0"><?php echo $asl;?></span>
        <div style="display:flex;gap:.3rem;margin-left:.65rem;flex-shrink:0" onclick="event.stopPropagation()">
            <a href="?law=<?php echo $activeLawId;?>&article=<?php echo $a['id'];?>" class="btn btn-ghost btn-xs" title="Détail"><i class="fas fa-expand"></i></a>
            <?php if($canEdit):?><button onclick="openEditArticle(<?php echo htmlspecialchars(json_encode($a));?>)" class="btn btn-ghost btn-xs"><i class="fas fa-pen"></i></button><?php endif;?>
        </div>
        <i class="fas fa-chevron-down" id="chevron-<?php echo $a['id'];?>" style="font-size:.7rem;color:var(--txt-m);flex-shrink:0;transition:transform .2s"></i>
    </div>
    <div class="article-body hidden" id="body-<?php echo $a['id'];?>"><?php echo htmlspecialchars($a['content']);?></div>
</div>
        <?php endforeach;?>
    <?php endforeach;?>
<?php endforeach;?>

<?php endif;?>
</div><!-- /loi-content -->

<?php else:?>
<div class="empty-state">
    <i class="fas fa-landmark" style="font-size:4rem;opacity:.08;display:block;margin-bottom:1rem"></i>
    <div style="font-weight:700;color:var(--txt-s)">Sélectionnez un code dans la liste</div>
</div>
<?php endif;?>

</div><!-- /loi-main -->
</div><!-- /loi-layout -->

<?php if($canEdit):?>
<!-- MODAL NOUVEL ARTICLE -->
<div id="newArticleModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-file-plus"></i> Nouvel article — <?php echo htmlspecialchars($activeLaw['code']??'');?></h2><button class="modal-cl" onclick="Gov.closeModal('newArticleModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_article"><input type="hidden" name="law_id" value="<?php echo $activeLawId;?>">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">N° Article *</label><input type="text" name="article_number" class="form-input" required placeholder="Art. CP.301, Préambule..."></div>
        <div class="form-group"><label class="form-label">Titre de l'article</label><input type="text" name="article_title" class="form-input" placeholder="Titre court..."></div>
        <div class="form-group"><label class="form-label">Chapitre</label><input type="text" name="chapter" class="form-input" placeholder="I, II, III..."></div>
        <div class="form-group"><label class="form-label">Titre du chapitre</label><input type="text" name="chapter_title" class="form-input" placeholder="Des dispositions générales..."></div>
        <div class="form-group"><label class="form-label">Section</label><input type="text" name="section" class="form-input" placeholder="Section 1 — ..."></div>
        <div class="form-group"><label class="form-label">Statut</label><select name="status" class="form-select"><option value="en_vigueur">En vigueur</option><option value="projet">Projet</option></select></div>
        <div class="form-group"><label class="form-label">Ordre d'affichage</label><input type="number" name="sort_order" class="form-input" value="0" min="0"></div>
        <div class="form-group form-full"><label class="form-label">Contenu *</label><textarea name="content" class="form-textarea" required style="min-height:180px;font-family:'IBM Plex Mono',monospace;font-size:.82rem" placeholder="Texte intégral de l'article..."></textarea></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newArticleModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer l'article</button></div>
</form></div></div>

<!-- MODAL EDIT ARTICLE -->
<div id="editArticleModal" class="modal-ov hidden">
<div class="modal modal-lg"><div class="modal-hd"><h2><i class="fas fa-pen"></i> Modifier l'article</h2><button class="modal-cl" onclick="Gov.closeModal('editArticleModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="edit_article"><input type="hidden" name="article_id" id="ea_id">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">N° Article *</label><input type="text" name="article_number" id="ea_num" class="form-input" required></div>
        <div class="form-group"><label class="form-label">Titre</label><input type="text" name="article_title" id="ea_title" class="form-input"></div>
        <div class="form-group"><label class="form-label">Chapitre</label><input type="text" name="chapter" id="ea_chap" class="form-input"></div>
        <div class="form-group"><label class="form-label">Titre du chapitre</label><input type="text" name="chapter_title" id="ea_chap_t" class="form-input"></div>
        <div class="form-group"><label class="form-label">Section</label><input type="text" name="section" id="ea_sect" class="form-input"></div>
        <div class="form-group"><label class="form-label">Statut</label><select name="status" id="ea_status" class="form-select"><option value="en_vigueur">En vigueur</option><option value="modifie">Modifié</option><option value="abroge">Abrogé</option></select></div>
        <div class="form-group"><label class="form-label">Ordre</label><input type="number" name="sort_order" id="ea_sort" class="form-input" min="0"></div>
        <div class="form-group form-full"><label class="form-label">Contenu *</label><textarea name="content" id="ea_content" class="form-textarea" required style="min-height:220px;font-family:'IBM Plex Mono',monospace;font-size:.82rem"></textarea></div>
        <div class="form-group form-full"><label class="form-label">Motif de modification (optionnel)</label><input type="text" name="motif" class="form-input" placeholder="Raison de la modification..."></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editArticleModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Sauvegarder</button></div>
</form></div></div>

<!-- MODAL NOUVEAU CODE LOI -->
<div id="newLawModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-book"></i> Nouveau code de loi</h2><button class="modal-cl" onclick="Gov.closeModal('newLawModal')">×</button></div>
<form method="POST"><div class="modal-bd"><input type="hidden" name="action" value="create_law">
    <div class="form-grid">
        <div class="form-group"><label class="form-label">Code abrégé *</label><input type="text" name="code" class="form-input" required placeholder="CP, CDT..." maxlength="10" style="text-transform:uppercase"></div>
        <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-select"><?php foreach($LTYPES as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group form-full"><label class="form-label">Titre complet *</label><input type="text" name="title" class="form-input" required placeholder="Code du travail..."></div>
        <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" class="form-textarea" style="min-height:60px"></textarea></div>
        <div class="form-group"><label class="form-label">Version</label><input type="text" name="version" class="form-input" value="1.0"></div>
        <div class="form-group"><label class="form-label">Statut</label><select name="status" class="form-select"><?php foreach($LSTATUS as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Ordre</label><input type="number" name="sort_order" class="form-input" value="99"></div>
    </div>
</div><div class="modal-ft"><button type="button" class="btn btn-ghost" onclick="Gov.closeModal('newLawModal')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button></div>
</form></div></div>

<!-- MODAL EDIT CODE LOI -->
<div id="editLawModal" class="modal-ov hidden">
<div class="modal"><div class="modal-hd"><h2><i class="fas fa-cog"></i> Paramètres du code</h2><button class="modal-cl" onclick="Gov.closeModal('editLawModal')">×</button></div>
<div class="modal-bd">
    <div class="form-grid">
        <div class="form-group form-full"><label class="form-label">Titre *</label><input type="text" id="el_title" class="form-input"></div>
        <div class="form-group form-full"><label class="form-label">Description</label><textarea id="el_desc" class="form-textarea" style="min-height:60px"></textarea></div>
        <div class="form-group"><label class="form-label">Type</label><select id="el_type" class="form-select"><?php foreach($LTYPES as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Statut</label><select id="el_status" class="form-select"><?php foreach($LSTATUS as $k=>[$l,$c]):?><option value="<?php echo $k;?>"><?php echo $l;?></option><?php endforeach;?></select></div>
        <div class="form-group"><label class="form-label">Version</label><input type="text" id="el_version" class="form-input"></div>
        <div class="form-group"><label class="form-label">Ordre</label><input type="number" id="el_sort" class="form-input"></div>
    </div>
</div>
<div class="modal-ft" style="gap:.5rem">
    <?php if($canAdmin):?>
    <form method="POST" style="margin:0;margin-right:auto" id="deleteLawForm">
        <input type="hidden" name="action" value="delete_law">
        <input type="hidden" name="law_id" id="el_del_id">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Supprimer ce code et tous ses articles ?')"><i class="fas fa-trash"></i> Supprimer</button>
    </form>
    <?php endif;?>
    <button type="button" class="btn btn-ghost" onclick="Gov.closeModal('editLawModal')">Annuler</button>
    <form method="POST" style="margin:0" id="editLawForm">
        <input type="hidden" name="action" value="edit_law">
        <input type="hidden" name="law_id" id="el_id">
        <input type="hidden" name="title" id="el_title_h">
        <input type="hidden" name="description" id="el_desc_h">
        <input type="hidden" name="type" id="el_type_h">
        <input type="hidden" name="status" id="el_status_h">
        <input type="hidden" name="version" id="el_version_h">
        <input type="hidden" name="sort_order" id="el_sort_h">
        <button type="submit" class="btn btn-primary" onclick="syncEditLaw()"><i class="fas fa-save"></i> Sauvegarder</button>
    </form>
</div>
</div></div>
<?php endif;?>

<script>
function filterLaws(q){
    const lq=q.toLowerCase();
    document.querySelectorAll('.loi-item').forEach(el=>{
        el.style.display=(!lq||el.dataset.search.includes(lq))?'':'none';
    });
}
function toggleArt(id){
    const body=document.getElementById('body-'+id);
    const chev=document.getElementById('chevron-'+id);
    if(!body)return;
    body.classList.toggle('hidden');
    if(chev) chev.style.transform=body.classList.contains('hidden')?'':'rotate(180deg)';
}
function openEditArticle(data){
    document.getElementById('ea_id').value=data.id||'';
    document.getElementById('ea_num').value=data.article_number||'';
    document.getElementById('ea_title').value=data.title||'';
    document.getElementById('ea_chap').value=data.chapter||'';
    document.getElementById('ea_chap_t').value=data.chapter_title||'';
    document.getElementById('ea_sect').value=data.section||'';
    document.getElementById('ea_content').value=data.content||'';
    document.getElementById('ea_sort').value=data.sort_order||'0';
    const sel=document.getElementById('ea_status');
    if(sel) sel.value=data.status||'en_vigueur';
    Gov.openModal('editArticleModal');
}
function syncEditLaw(){
    document.getElementById('el_title_h').value  = document.getElementById('el_title').value;
    document.getElementById('el_desc_h').value   = document.getElementById('el_desc').value;
    document.getElementById('el_type_h').value   = document.getElementById('el_type').value;
    document.getElementById('el_status_h').value = document.getElementById('el_status').value;
    document.getElementById('el_version_h').value= document.getElementById('el_version').value;
    document.getElementById('el_sort_h').value   = document.getElementById('el_sort').value;
    return true;
}
function openEditLaw(data){
    document.getElementById('el_id').value=data.id||'';
    document.getElementById('el_id').dataset.nb=data.nb_articles||'0';
    document.getElementById('el_title').value=data.title||'';
    document.getElementById('el_desc').value=data.description||'';
    const ty=document.getElementById('el_type'); if(ty) ty.value=data.type||'code';
    const st=document.getElementById('el_status'); if(st) st.value=data.status||'en_vigueur';
    document.getElementById('el_version').value=data.version||'1.0';
    document.getElementById('el_sort').value=data.sort_order||'0';
    const del=document.getElementById('el_del_id'); if(del) del.value=data.id||'';
    Gov.openModal('editLawModal');
}
// Expand article via URL hash
document.addEventListener('DOMContentLoaded',()=>{
    const hash=window.location.hash.replace('#','');
    if(hash && hash.startsWith('art-')) toggleArt(hash.replace('art-',''));
});
</script>
<?php include __DIR__ . '/includes/footer.php';?>