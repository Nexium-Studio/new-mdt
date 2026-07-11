<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Life Invader';
$companyColor   = '#4f7cff';
$companyFaction = 'LIFE_INVADER';
$pageTitle      = 'Salle de rédaction';
$pageSubtitle   = 'Le journal Life Invader en direct — édition du '.date('d F Y');

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal']))
    redirect(SITE_URL.'/pages/dashboard.php');

$db  = Database::getInstance()->getConnection();
// Permissions — RBAC granulaire
require_once ROOT_PATH . '/includes/faction_acl.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!faction_acl_can($db, $uid, $companyFaction, 'dashboard.view')) faction_acl_deny();
$canStats = faction_acl_can($db, $uid, $companyFaction, 'dashboard.stats');
$msg = ''; $msgType = '';

/* ── Recalcul finances ─────────────────────────────────── */
if (($_POST['action'] ?? '') === 'recalculate_finances') {
    try {
        $db->beginTransaction();
        $ca  = $db->prepare("SELECT COALESCE(SUM(final_amount),0) FROM company_invoices WHERE faction=? AND status='paid'");
        $ca->execute([$companyFaction]); $ca_brut = (float)$ca->fetchColumn();
        $ch  = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM company_expenses WHERE faction=? AND is_tax_deductible=1");
        $ch->execute([$companyFaction]); $charges = (float)$ch->fetchColumn();
        $bn  = $ca_brut - $charges;
        $imp = $bn<=50000?0:($bn<=100000?($bn-50000)*.10:($bn<=500000?5000+($bn-100000)*.15:65000+($bn-500000)*.20));
        $net = $bn - $imp;
        $db->prepare("INSERT INTO company_finances (faction,ca_brut,charges,benefice_brut,impot_du,benefice_net,tresorerie)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ca_brut=VALUES(ca_brut),charges=VALUES(charges),
            benefice_brut=VALUES(benefice_brut),impot_du=VALUES(impot_du),benefice_net=VALUES(benefice_net),tresorerie=VALUES(tresorerie)")
            ->execute([$companyFaction,$ca_brut,$charges,$bn,$imp,$net,$net*.30]);
        $db->commit(); $msg = '✓ Finances recalculées !'; $msgType='success';
    } catch (Exception $e) { $db->rollBack(); $msg=$e->getMessage(); $msgType='error'; }
}

/* ── Requêtes données ──────────────────────────────────── */
$fin = $db->prepare("SELECT * FROM company_finances WHERE faction=?");
$fin->execute([$companyFaction]);
$F = $fin->fetch(PDO::FETCH_ASSOC) ?: ['ca_brut'=>0,'charges'=>0,'benefice_brut'=>0,'impot_du'=>0,'benefice_net'=>0,'tresorerie'=>0];

// Stats articles globales
$as = $db->query("
    SELECT COUNT(*) total, SUM(statut='publie') publies, SUM(statut='revision') revision,
           SUM(statut='brouillon') brouillons, SUM(statut='refuse') refuses,
           SUM(statut='publie' AND DATE(date_publication)=CURDATE()) publies_auj,
           COALESCE(SUM(CASE WHEN statut='publie' THEN nb_vues ELSE 0 END),0) total_vues,
           COALESCE(SUM(CASE WHEN statut='publie' THEN nb_likes ELSE 0 END),0) total_likes
    FROM li_articles")->fetch(PDO::FETCH_ASSOC) ?: [];

// Stats missions
$ms = $db->query("SELECT COUNT(*) total, SUM(statut='assigne') assigne, SUM(statut='en_cours') en_cours,
    SUM(statut='termine') termine FROM li_missions")->fetch(PDO::FETCH_ASSOC) ?: [];

// Stats communiqués
$cs = $db->query("SELECT COUNT(*) total, SUM(statut='recu') recu, SUM(statut='en_traitement') traitement
    FROM li_communiques")->fetch(PDO::FETCH_ASSOC) ?: [];

// Employés / en service
$nbEmp = $db->prepare("SELECT COUNT(*) FROM company_employees WHERE faction=?");
$nbEmp->execute([$companyFaction]); $nbEmp = $nbEmp->fetchColumn();
$enSvc = $db->prepare("SELECT COUNT(*) FROM company_shifts WHERE faction=? AND shift_end IS NULL");
$enSvc->execute([$companyFaction]); $enSvc = $enSvc->fetchColumn();

// Factures du mois
$fm = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(final_amount),0) s FROM company_invoices
    WHERE faction=? AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$fm->execute([$companyFaction]); $FM = $fm->fetch(PDO::FETCH_ASSOC);

// Flash / urgents non publiés
$urgQ = $db->query("SELECT a.*,c.nom cat_nom,c.couleur cat_col,u.username auteur,
    p.firstname auteur_fn, p.lastname auteur_ln
    FROM li_articles a LEFT JOIN li_categories c ON a.categorie_id=c.id
    JOIN users u ON a.auteur_id=u.id
    LEFT JOIN profiles p ON p.id=u.profile_id
    WHERE a.priorite IN('urgente','flash') AND a.statut NOT IN('publie','archive','refuse')
    ORDER BY FIELD(a.priorite,'flash','urgente'), a.created_at DESC LIMIT 5");
$urgents = $urgQ ? $urgQ->fetchAll(PDO::FETCH_ASSOC) : [];

// En attente de validation
$valQ = $db->query("SELECT a.*,c.nom cat_nom,c.couleur cat_col,u.username auteur,
    p.firstname auteur_fn, p.lastname auteur_ln
    FROM li_articles a LEFT JOIN li_categories c ON a.categorie_id=c.id
    JOIN users u ON a.auteur_id=u.id
    LEFT JOIN profiles p ON p.id=u.profile_id
    WHERE a.statut='revision' ORDER BY a.updated_at DESC LIMIT 6");
$enVal = $valQ ? $valQ->fetchAll(PDO::FETCH_ASSOC) : [];

// Top journalistes — on enrichit l'affichage avec le nom RP (logique inchangée)
$topQ = $db->query("
    SELECT s.*, p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM li_stats_journalistes s
    LEFT JOIN users u ON u.username = s.username
    LEFT JOIN profiles p ON p.id = u.profile_id
    ORDER BY s.nb_publies DESC, s.total_vues DESC
    LIMIT 5");
$topJ = $topQ ? $topQ->fetchAll(PDO::FETCH_ASSOC) : [];

// Missions actives
$misQ = $db->query("SELECT m.*,u.username jnom, pj.firstname jfn, pj.lastname jln
    FROM li_missions m JOIN users u ON m.journaliste_id=u.id
    LEFT JOIN profiles pj ON pj.id=u.profile_id
    WHERE m.statut IN('assigne','en_cours') ORDER BY FIELD(m.priorite,'urgente','importante','normale'), m.date_limite ASC LIMIT 6");
$missions = $misQ ? $misQ->fetchAll(PDO::FETCH_ASSOC) : [];

// Communiqués non traités
$cpQ = $db->query("SELECT * FROM li_communiques WHERE statut='recu'
    ORDER BY FIELD(priorite,'urgente','importante','normale'), created_at DESC LIMIT 4");
$cpNT = $cpQ ? $cpQ->fetchAll(PDO::FETCH_ASSOC) : [];

// Derniers publiés
$dpQ = $db->query("SELECT a.*,c.nom cat_nom,c.couleur cat_col,u.username auteur
    FROM li_articles a LEFT JOIN li_categories c ON a.categorie_id=c.id JOIN users u ON a.auteur_id=u.id
    WHERE a.statut='publie' ORDER BY a.date_publication DESC LIMIT 6");
$dpubs = $dpQ ? $dpQ->fetchAll(PDO::FETCH_ASSOC) : [];

// Stats par catégorie
$catStats = $db->query("SELECT c.nom,c.couleur,c.icone,COUNT(a.id) nb,
    COALESCE(SUM(CASE WHEN a.statut='publie' THEN a.nb_vues ELSE 0 END),0) vues
    FROM li_categories c LEFT JOIN li_articles a ON a.categorie_id=c.id
    GROUP BY c.id ORDER BY nb DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>
<style>
.dash-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:1.4rem}
.dash-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.4rem}
.ticker{background:linear-gradient(90deg,rgba(239,68,68,.08),rgba(239,68,68,.02));border:1px solid rgba(239,68,68,.22);border-radius:var(--r-md);padding:.55rem 1.1rem;display:flex;align-items:center;gap:.85rem;margin-bottom:1.4rem;overflow:hidden}
.ticker-label{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:#ef4444;white-space:nowrap;display:flex;align-items:center;gap:.4rem;padding-right:.85rem;border-right:1px solid rgba(239,68,68,.22)}
.ticker-text{font-size:.8rem;color:var(--tx-2);white-space:nowrap;animation:tickerScroll 28s linear infinite;font-family:'Inter',sans-serif;font-weight:500}
.ticker-text strong{color:var(--tx);font-weight:700}
@keyframes tickerScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.cat-bar{display:flex;align-items:center;gap:.8rem;padding:.55rem 0;border-bottom:1px solid var(--bd)}
.cat-bar:last-child{border:none}
.cat-bar-name{font-size:.82rem;font-weight:600;color:var(--tx);flex:1;font-family:'Playfair Display',serif}
.cat-bar-track{flex:1.5;height:6px;background:var(--surface-3);border-radius:3px;overflow:hidden}
.cat-bar-fill{height:100%;border-radius:3px;transition:width .6s ease}
.cat-bar-num{font-size:.76rem;font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--tx-3);min-width:34px;text-align:right}
.pulse-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;animation:pulseDot 2s ease-in-out infinite;vertical-align:middle}
@keyframes pulseDot{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.4)}50%{box-shadow:0 0 0 5px rgba(34,197,94,0)}}
.dash-edition{display:flex;align-items:center;gap:.85rem;padding:.85rem 1.15rem;background:var(--surface);border:1px solid var(--bd);border-left:3px solid var(--li);border-radius:var(--r-md);margin-bottom:1.5rem}
.dash-edition-meta{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--tx-3)}
.dash-edition-title{font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;color:var(--tx);line-height:1.3}
.dash-edition-sub{font-size:.78rem;color:var(--tx-3);margin-top:.12rem}
.dash-edition-actions{margin-left:auto;display:flex;gap:.5rem;flex-wrap:wrap}
.kpi-fin-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px dashed var(--bd);font-size:.82rem}
.kpi-fin-row:last-child{border:none}
.kpi-fin-lbl{display:flex;align-items:center;gap:.5rem;color:var(--tx-3);font-weight:500}
.kpi-fin-lbl i{width:18px;text-align:center;color:var(--tx-mute)}
.kpi-fin-val{font-family:'JetBrains Mono',monospace;font-weight:700;color:var(--tx)}
.kpi-fin-pos{color:#10b981}
.kpi-fin-neg{color:#ef4444}
.kpi-fin-big{font-size:1.05rem}
.dash-item{display:flex;align-items:center;gap:.8rem;padding:.7rem .9rem;background:var(--surface-2);border:1px solid var(--bd);border-radius:var(--r-md);margin-bottom:.5rem;transition:all .15s}
.dash-item:hover{border-color:var(--li-bd);background:var(--li-softer);transform:translateX(2px)}
.dash-item-stripe{width:4px;height:36px;border-radius:3px;flex-shrink:0}
.dash-item-body{flex:1;min-width:0}
.dash-item-title{font-weight:700;font-size:.85rem;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'Playfair Display',serif}
.dash-item-meta{font-size:.7rem;color:var(--tx-3);margin-top:.12rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.dash-item-actions{display:flex;gap:.3rem;flex-shrink:0}
.dash-view-all{display:flex;align-items:center;justify-content:center;gap:.4rem;font-size:.75rem;font-weight:600;color:var(--li);margin-top:.8rem;padding:.5rem;border-radius:var(--r-sm);text-decoration:none;transition:background .15s}
.dash-view-all:hover{background:var(--li-softer)}
@media(max-width:1100px){.dash-grid{grid-template-columns:1fr}.dash-edition{flex-direction:column;align-items:flex-start;gap:.65rem}.dash-edition-actions{margin-left:0}}
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $msg; ?></div>
<?php endif; ?>

<!-- Ticker breaking -->
<?php if(!empty($urgents)): ?>
<div class="ticker">
    <div class="ticker-label"><i class="fas fa-bolt"></i> Breaking</div>
    <div style="overflow:hidden;flex:1">
        <div class="ticker-text">
            <?php foreach($urgents as $u):
                $auteurDisp = mdt_rp_display_name($u['auteur_fn']??null,$u['auteur_ln']??null,(string)($u['auteur']??''));
            ?>
            &nbsp;&nbsp;● <strong><?php echo htmlspecialchars($u['titre']); ?></strong> — <?php echo htmlspecialchars($auteurDisp); ?>
            <?php endforeach; ?>
            &nbsp;&nbsp;&nbsp;&nbsp;
            <?php foreach($urgents as $u):
                $auteurDisp = mdt_rp_display_name($u['auteur_fn']??null,$u['auteur_ln']??null,(string)($u['auteur']??''));
            ?>
            &nbsp;&nbsp;● <strong><?php echo htmlspecialchars($u['titre']); ?></strong> — <?php echo htmlspecialchars($auteurDisp); ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Édition du jour -->
<div class="dash-edition">
    <div>
        <div class="dash-edition-meta"><span class="pulse-dot"></span> &nbsp;En direct · <?php echo mb_strtoupper(date('l d F Y')); ?></div>
        <div class="dash-edition-title">Salle de rédaction Life Invader</div>
        <div class="dash-edition-sub"><?php echo ($as['publies_auj']??0); ?> article<?php echo ($as['publies_auj']??0)>1?'s':''; ?> publié<?php echo ($as['publies_auj']??0)>1?'s':''; ?> aujourd'hui &nbsp;·&nbsp; <?php echo number_format($as['total_vues']??0); ?> lectures totales &nbsp;·&nbsp; <?php echo $enSvc; ?> en service</div>
    </div>
    <div class="dash-edition-actions">
        <a href="articles.php?open=new" class="btn btn-primary btn-sm"><i class="fas fa-pen"></i> Rédiger</a>
        <a href="redaction.php?open=new" class="btn btn-secondary btn-sm"><i class="fas fa-map-marker-alt"></i> Mission</a>
        <a href="<?php echo SITE_URL; ?>/pages/companies/life-invader/public/index.php" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i> Site public</a>
        <form method="POST" style="display:inline"
              data-confirm="Le recalcul met à jour le chiffre d'affaires, les charges et le bénéfice net en se basant sur les factures et dépenses enregistrées."
              data-confirm-title="Recalculer les finances ?"
              data-confirm-tone="info"
              data-confirm-icon="fa-calculator"
              data-confirm-ok="Recalculer">
            <input type="hidden" name="action" value="recalculate_finances">
            <button class="btn btn-ghost btn-sm" type="submit" title="Recalculer les finances"><i class="fas fa-calculator"></i></button>
        </form>
    </div>
</div>

<!-- Stats bar -->
<div class="li-stats">
    <div class="li-stat">
        <div class="li-stat-ico"><i class="fas fa-newspaper"></i></div>
        <div>
            <div class="li-stat-val"><?php echo $as['publies']??0; ?></div>
            <div class="li-stat-lbl">Articles publiés</div>
            <div class="li-stat-sub">+<?php echo $as['publies_auj']??0; ?> aujourd'hui</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-amber"><i class="fas fa-tasks"></i></div>
        <div>
            <div class="li-stat-val"><?php echo $as['revision']??0; ?></div>
            <div class="li-stat-lbl">En validation</div>
            <div class="li-stat-sub"><?php echo $as['brouillons']??0; ?> brouillon<?php echo ($as['brouillons']??0)>1?'s':''; ?></div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-violet"><i class="fas fa-map-marker-alt"></i></div>
        <div>
            <div class="li-stat-val"><?php echo ($ms['en_cours']??0)+($ms['assigne']??0); ?></div>
            <div class="li-stat-lbl">Missions actives</div>
            <div class="li-stat-sub"><?php echo $ms['termine']??0; ?> terminée<?php echo ($ms['termine']??0)>1?'s':''; ?></div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-cyan"><i class="fas fa-envelope-open-text"></i></div>
        <div>
            <div class="li-stat-val"><?php echo $cs['recu']??0; ?></div>
            <div class="li-stat-lbl">Communiqués</div>
            <div class="li-stat-sub">À traiter</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-emerald"><i class="fas fa-users"></i></div>
        <div>
            <div class="li-stat-val"><?php echo $nbEmp; ?></div>
            <div class="li-stat-lbl">Journalistes</div>
            <div class="li-stat-sub"><?php echo $enSvc; ?> en service</div>
        </div>
    </div>
    <div class="li-stat">
        <div class="li-stat-ico ico-red"><i class="fas fa-eye"></i></div>
        <div>
            <div class="li-stat-val" style="font-size:1.15rem"><?php echo number_format($as['total_vues']??0); ?></div>
            <div class="li-stat-lbl">Lectures totales</div>
            <div class="li-stat-sub"><i class="fas fa-heart" style="color:#ef4444;font-size:.6rem"></i> <?php echo number_format($as['total_likes']??0); ?> likes</div>
        </div>
    </div>
</div>

<!-- Quick actions -->
<div class="quick-actions" style="margin-bottom:1.5rem">
    <a href="articles.php?open=new" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,var(--li),var(--li-d))"><i class="fas fa-pen"></i></div>
        <div class="qa-lbl">Rédiger un article</div>
    </a>
    <a href="redaction.php?open=new" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-map-marker-alt"></i></div>
        <div class="qa-lbl">Assigner une mission</div>
    </a>
    <a href="communiques.php?open=new" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"><i class="fas fa-envelope-open-text"></i></div>
        <div class="qa-lbl">Nouveau communiqué</div>
    </a>
    <a href="articles.php?statut=revision" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-tasks"></i></div>
        <div class="qa-lbl">File validation</div>
        <?php if(($as['revision']??0)>0): ?><span style="font-size:.63rem;background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:.1rem .4rem;font-weight:700"><?php echo $as['revision']; ?> en attente</span><?php endif; ?>
    </a>
    <a href="facturation.php" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,#10b981,#059669)"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="qa-lbl">Facturation pub</div>
    </a>
    <a href="effectifs.php" class="qa-btn">
        <div class="qa-ico" style="background:linear-gradient(135deg,#64748b,#475569)"><i class="fas fa-users"></i></div>
        <div class="qa-lbl">Journalistes</div>
    </a>
</div>

<!-- Grille principale 2 colonnes -->
<div class="dash-grid">

<!-- COLONNE GAUCHE -->
<div>

<!-- File de validation -->
<div class="li-widget">
    <div class="li-widget-title">
        <i class="fas fa-tasks"></i> File de validation
        <?php if(!empty($enVal)): ?>
        <span style="margin-left:auto;background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);border-radius:7px;font-size:.65rem;font-weight:700;padding:.12rem .5rem"><?php echo count($enVal); ?> en attente</span>
        <?php endif; ?>
    </div>
    <?php if(empty($enVal)): ?>
    <div style="text-align:center;padding:1.8rem;color:#10b981;font-size:.85rem;background:rgba(16,185,129,.04);border:1px dashed rgba(16,185,129,.2);border-radius:var(--r-md)"><i class="fas fa-check-circle" style="font-size:1.4rem;margin-bottom:.5rem;display:block"></i> Aucun article en attente de validation</div>
    <?php else: ?>
    <?php foreach($enVal as $ev):
        $evAuteur = mdt_rp_display_name($ev['auteur_fn']??null,$ev['auteur_ln']??null,(string)($ev['auteur']??''));
    ?>
    <div class="dash-item">
        <div class="dash-item-stripe" style="background:<?php echo $ev['cat_col']??'#4f7cff'; ?>"></div>
        <div class="dash-item-body">
            <div class="dash-item-title"><?php echo htmlspecialchars($ev['titre']); ?></div>
            <div class="dash-item-meta"><i class="fas fa-user-edit"></i> <?php echo htmlspecialchars($evAuteur); ?> <?php if($ev['cat_nom']): ?>· <span style="color:<?php echo $ev['cat_col']; ?>"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($ev['cat_nom']); ?></span><?php endif; ?> · <i class="far fa-clock"></i> <?php echo date('d/m H:i',strtotime($ev['updated_at'])); ?></div>
        </div>
        <div class="dash-item-actions">
            <a href="articles.php?id=<?php echo $ev['id']; ?>" class="btn btn-sm btn-secondary btn-icon" title="Voir"><i class="fas fa-eye"></i></a>
            <form method="POST" action="articles.php" style="display:inline"
                  data-confirm="L'article %t sera mis en ligne sur le site public et visible par tous les lecteurs."
                  data-confirm-title="Publier l'article ?"
                  data-confirm-tone="success"
                  data-confirm-icon="fa-newspaper"
                  data-confirm-ok="Publier"
                  data-confirm-target="<?php echo htmlspecialchars($ev['titre']); ?>">
                <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $ev['id']; ?>"><input type="hidden" name="new_statut" value="publie">
                <button type="submit" class="btn btn-sm btn-success btn-icon" title="Publier"><i class="fas fa-check"></i></button>
            </form>
            <form method="POST" action="articles.php" style="display:inline"
                  data-confirm="L'article %t sera refusé et renvoyé au rédacteur pour modification."
                  data-confirm-title="Refuser l'article ?"
                  data-confirm-tone="danger"
                  data-confirm-icon="fa-circle-xmark"
                  data-confirm-ok="Refuser"
                  data-confirm-target="<?php echo htmlspecialchars($ev['titre']); ?>">
                <input type="hidden" name="action" value="change_statut"><input type="hidden" name="article_id" value="<?php echo $ev['id']; ?>"><input type="hidden" name="new_statut" value="refuse">
                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Refuser"><i class="fas fa-times"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="articles.php?statut=revision" class="dash-view-all">Tous les articles à valider <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>

<!-- Derniers publiés -->
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-newspaper"></i> Derniers articles publiés</div>
    <?php if(empty($dpubs)): ?>
    <div style="text-align:center;padding:1.5rem;color:var(--tx-3);font-size:.85rem">Aucun article publié pour l'instant</div>
    <?php else: ?>
    <?php foreach($dpubs as $ap): ?>
    <div class="dash-item">
        <div class="dash-item-stripe" style="background:<?php echo $ap['cat_col']??'#4f7cff'; ?>"></div>
        <div class="dash-item-body">
            <div class="dash-item-title"><?php echo htmlspecialchars($ap['titre']); ?></div>
            <div class="dash-item-meta">
                <?php if($ap['cat_nom']): ?><span style="color:<?php echo $ap['cat_col']; ?>"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($ap['cat_nom']); ?></span> · <?php endif; ?>
                <i class="fas fa-eye"></i> <?php echo number_format($ap['nb_vues']); ?> · <i class="far fa-clock"></i> <?php echo date('d/m H:i',strtotime($ap['date_publication'])); ?>
            </div>
        </div>
        <div class="dash-item-actions" style="align-items:center">
            <?php if($ap['a_la_une']): ?><span style="font-size:.6rem;font-weight:800;background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.28);border-radius:5px;padding:.1rem .42rem">UNE</span><?php endif; ?>
            <?php if($ap['exclusif']): ?><span style="font-size:.6rem;font-weight:800;background:rgba(139,92,246,.12);color:#8b5cf6;border:1px solid rgba(139,92,246,.28);border-radius:5px;padding:.1rem .42rem">EXCL.</span><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="articles.php?statut=publie" class="dash-view-all">Voir tous les articles publiés <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>

<!-- Stats par catégorie -->
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-tags"></i> Répartition par Rubrique</div>
    <?php $maxCat = max(array_column($catStats,'nb')+[1]); foreach($catStats as $cat): ?>
    <div class="cat-bar">
        <div style="width:22px;height:22px;border-radius:6px;background:<?php echo $cat['couleur']; ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.65rem;color:<?php echo $cat['couleur']; ?>"><i class="fas <?php echo $cat['icone']??'fa-newspaper'; ?>"></i></div>
        <div class="cat-bar-name"><?php echo htmlspecialchars($cat['nom']); ?></div>
        <div class="cat-bar-track"><div class="cat-bar-fill" style="width:<?php echo round($cat['nb']/$maxCat*100); ?>%;background:<?php echo $cat['couleur']; ?>"></div></div>
        <div class="cat-bar-num"><?php echo $cat['nb']; ?></div>
    </div>
    <?php endforeach; ?>
</div>

</div><!-- /col gauche -->

<!-- COLONNE DROITE -->
<div>

<!-- Top journalistes -->
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-trophy" style="color:#f59e0b"></i> Top journalistes du mois</div>
    <?php if(empty($topJ)): ?>
    <div class="empty-state" style="padding:1.5rem"><i class="fas fa-users"></i><p>Aucune donnée</p></div>
    <?php else: ?>
    <?php $rkCls=['rk-gold','rk-silver','rk-bronze']; foreach($topJ as $i=>$j):
        $jName = mdt_rp_display_name($j['profile_firstname']??null,$j['profile_lastname']??null,(string)($j['username']??''));
    ?>
    <div class="journ-row">
        <div class="journ-rank <?php echo $rkCls[$i]??'rk-other'; ?>"><?php echo $i+1; ?></div>
        <div style="flex:1;min-width:0">
            <div class="journ-name"><?php echo htmlspecialchars($jName); ?></div>
            <div class="journ-sub"><?php echo htmlspecialchars($j['rank']); ?></div>
        </div>
        <div style="text-align:right">
            <div class="journ-val"><?php echo $j['nb_publies']??0; ?> publié<?php echo ($j['nb_publies']??0)>1?'s':''; ?></div>
            <div style="font-size:.66rem;color:var(--tx-3);font-family:'JetBrains Mono',monospace"><i class="fas fa-eye"></i> <?php echo number_format($j['total_vues']??0); ?> · <i class="fas fa-map-marker-alt"></i> <?php echo $j['nb_missions']??0; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="effectifs.php" class="dash-view-all">Voir tous les journalistes <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>

<!-- Missions actives -->
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-map-marker-alt" style="color:#8b5cf6"></i> Missions terrain actives</div>
    <?php if(empty($missions)): ?>
    <div style="text-align:center;padding:1.5rem;color:var(--tx-3);font-size:.85rem">Aucune mission active sur le terrain</div>
    <?php else: ?>
    <?php $tico=['reportage'=>'fa-video','interview'=>'fa-microphone','conference_presse'=>'fa-chalkboard-teacher','investigation'=>'fa-search','flash_info'=>'fa-bolt','portrait'=>'fa-user-circle','critique'=>'fa-star','direct_live'=>'fa-broadcast-tower'];
    foreach($missions as $m):
        $mJourn = mdt_rp_display_name($m['jfn']??null,$m['jln']??null,(string)($m['jnom']??''));
    ?>
    <a href="redaction.php?id=<?php echo $m['id']; ?>" style="text-decoration:none" class="dash-item">
        <div style="width:34px;height:34px;border-radius:9px;background:rgba(139,92,246,.12);border:1px solid rgba(139,92,246,.22);display:flex;align-items:center;justify-content:center;font-size:.82rem;color:#8b5cf6;flex-shrink:0"><i class="fas <?php echo $tico[$m['type_mission']]??'fa-map-marker'; ?>"></i></div>
        <div class="dash-item-body">
            <div class="dash-item-title"><?php echo htmlspecialchars($m['titre']); ?></div>
            <div class="dash-item-meta"><i class="fas fa-user"></i> <?php echo htmlspecialchars($mJourn); ?><?php if($m['lieu']): ?> · <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($m['lieu']); ?><?php endif; ?><?php if($m['date_limite']): ?> · <i class="far fa-clock"></i> <?php echo date('d/m',strtotime($m['date_limite'])); ?><?php endif; ?></div>
        </div>
        <span class="badge badge-<?php echo $m['statut']; ?>"><?php echo $m['statut']; ?></span>
    </a>
    <?php endforeach; ?>
    <a href="redaction.php" class="dash-view-all">Voir toutes les missions <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>

<!-- Communiqués en attente -->
<?php if(!empty($cpNT)): ?>
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-envelope-open-text" style="color:#06b6d4"></i> Communiqués à traiter</div>
    <?php foreach($cpNT as $cp): ?>
    <div class="dash-item" style="background:rgba(6,182,212,.04);border-color:rgba(6,182,212,.15)">
        <div class="dash-item-stripe" style="background:#06b6d4"></div>
        <div class="dash-item-body">
            <div class="dash-item-title"><?php echo htmlspecialchars($cp['titre']); ?></div>
            <div class="dash-item-meta"><i class="fas fa-building"></i> <?php echo htmlspecialchars($cp['source']); ?> · <i class="far fa-clock"></i> <?php echo date('d/m H:i',strtotime($cp['recu_le'])); ?></div>
        </div>
        <span class="badge badge-<?php echo $cp['priorite']; ?>"><?php echo $cp['priorite']; ?></span>
        <a href="communiques.php?id=<?php echo $cp['id']; ?>" class="btn btn-sm btn-secondary btn-icon" title="Ouvrir"><i class="fas fa-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
    <a href="communiques.php" class="dash-view-all">Tous les communiqués <i class="fas fa-arrow-right"></i></a>
</div>
<?php endif; ?>

<!-- Finances -->
<div class="li-widget">
    <div class="li-widget-title"><i class="fas fa-chart-pie" style="color:#10b981"></i> Finances de la rédaction</div>
    <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-cash-register"></i>Chiffre d'affaires brut</div><div class="kpi-fin-val">$<?php echo number_format($F['ca_brut'],0); ?></div></div>
    <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-minus-circle"></i>Charges déductibles</div><div class="kpi-fin-val kpi-fin-neg">-$<?php echo number_format($F['charges'],0); ?></div></div>
    <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-landmark"></i>Impôts dus</div><div class="kpi-fin-val kpi-fin-neg">-$<?php echo number_format($F['impot_du'],0); ?></div></div>
    <div class="kpi-fin-row" style="background:var(--li-softer);margin:.3rem -.5rem;padding:.65rem .5rem;border-radius:var(--r-sm);border-bottom:none">
        <div class="kpi-fin-lbl" style="font-weight:800;color:var(--tx)"><i class="fas fa-trophy" style="color:#f59e0b"></i>Bénéfice net</div>
        <div class="kpi-fin-val kpi-fin-pos kpi-fin-big">$<?php echo number_format($F['benefice_net'],0); ?></div>
    </div>
    <div style="border-top:1px solid var(--bd);margin-top:.6rem;padding-top:.6rem">
        <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--tx-mute);margin-bottom:.35rem">Répartition</div>
        <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-gift"></i>Pool primes <span style="color:var(--tx-mute);font-size:.72rem">(30%)</span></div><div class="kpi-fin-val kpi-fin-pos">$<?php echo number_format($F['benefice_net']*.30,0); ?></div></div>
        <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-crown"></i>Dividendes PDG <span style="color:var(--tx-mute);font-size:.72rem">(40%)</span></div><div class="kpi-fin-val kpi-fin-pos">$<?php echo number_format($F['benefice_brut']*.40,0); ?></div></div>
        <div class="kpi-fin-row"><div class="kpi-fin-lbl"><i class="fas fa-piggy-bank"></i>Trésorerie <span style="color:var(--tx-mute);font-size:.72rem">(30%)</span></div><div class="kpi-fin-val">$<?php echo number_format($F['tresorerie'],0); ?></div></div>
    </div>
</div>

</div><!-- /col droite -->
</div><!-- /dash-grid -->

<?php include __DIR__ . '/includes/footer.php'; ?>