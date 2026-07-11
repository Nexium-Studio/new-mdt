<?php
require_once __DIR__ . '/../../../config/config.php';
requireLogin();

$companyName    = 'Life Invader';
$companyColor   = '#4f7cff';
$companyFaction = 'LIFE_INVADER';
$pageTitle      = 'Terrain & Missions';
$pageSubtitle   = 'Briefings, missions journalistes et suivi terrain';

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

/* ═══════════════════════════════════════════════════════════
   ACTIONS POST
═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    /* ── Créer mission ─────────────────────────────────── */
    if ($action === 'create_mission') {
        try {
            $db->beginTransaction();
            $yr  = date('Y');
            $sq  = $db->prepare("SELECT dernier FROM li_sequences WHERE type='REP' FOR UPDATE"); $sq->execute();
            $last = $sq->fetchColumn();
            if ($last === false) { $db->prepare("INSERT INTO li_sequences(type,annee,dernier) VALUES('REP',?,1)")->execute([$yr]); $num=1; }
            else { $num=$last+1; $db->prepare("UPDATE li_sequences SET dernier=?,annee=? WHERE type='REP'")->execute([$num,$yr]); }
            $ref = "REP-$yr-".str_pad($num,5,'0',STR_PAD_LEFT);

            $db->prepare("
                INSERT INTO li_missions (reference,titre,description,journaliste_id,assigne_par,lieu,
                    type_mission,priorite,statut,date_debut,date_limite,notes_chef)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $ref,
                trim($_POST['titre']),
                li_sanitize_html($_POST['description']??''),
                (int)$_POST['journaliste_id'],
                $userId,
                trim($_POST['lieu']??''),
                $_POST['type_mission']??'reportage',
                $_POST['priorite']??'normale',
                'assigne',
                !empty($_POST['date_debut'])?$_POST['date_debut']:null,
                !empty($_POST['date_limite'])?$_POST['date_limite']:null,
                li_sanitize_html($_POST['notes_chef']??''),
            ]);
            $db->commit();
            $msg="✓ Mission $ref créée et assignée !"; $msgType='success';
        } catch(Exception $e){$db->rollBack();$msg=$e->getMessage();$msgType='error';}
    }

    /* ── Modifier mission ──────────────────────────────── */
    elseif ($action === 'update_mission') {
        try {
            $mid = (int)$_POST['mission_id'];
            // Seul le journaliste peut modifier ses notes terrain, le chef peut tout modifier
            if ($isChef) {
                $db->prepare("
                    UPDATE li_missions SET titre=?,description=?,journaliste_id=?,lieu=?,type_mission=?,
                    priorite=?,date_debut=?,date_limite=?,notes_chef=? WHERE id=?")->execute([
                    trim($_POST['titre']),
                    li_sanitize_html($_POST['description']??''),
                    (int)$_POST['journaliste_id'],
                    trim($_POST['lieu']??''),
                    $_POST['type_mission']??'reportage',
                    $_POST['priorite']??'normale',
                    !empty($_POST['date_debut'])?$_POST['date_debut']:null,
                    !empty($_POST['date_limite'])?$_POST['date_limite']:null,
                    li_sanitize_html($_POST['notes_chef']??''),
                    $mid
                ]);
            }
            // Notes terrain accessibles à tous
            if (isset($_POST['notes_terrain'])) {
                $db->prepare("UPDATE li_missions SET notes_terrain=? WHERE id=? AND journaliste_id=?")
                   ->execute([li_sanitize_html($_POST['notes_terrain']),$mid,$userId]);
            }
            $msg="✓ Mission mise à jour."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    /* ── Changer statut mission ────────────────────────── */
    elseif ($action === 'change_statut_mission') {
        try {
            $mid = (int)$_POST['mission_id'];
            $ns  = $_POST['new_statut'];
            $now = in_array($ns,['en_cours','assigne']) ? null : "NOW()";
            $extra = ($ns==='termine') ? ", date_rendu=NOW()" : "";
            // Vérifier que c'est son chef ou lui-même
            $chk = $db->prepare("SELECT journaliste_id FROM li_missions WHERE id=?");
            $chk->execute([$mid]);
            $jid = $chk->fetchColumn();
            if (!$isChef && $jid != $userId) throw new Exception("Permission refusée");
            $db->prepare("UPDATE li_missions SET statut=?$extra WHERE id=?")->execute([$ns,$mid]);
            $labels=['en_cours'=>'démarrée','termine'=>'terminée','annule'=>'annulée','archive'=>'archivée'];
            $msg="✓ Mission ".(isset($labels[$ns])?$labels[$ns]:$ns)." !"; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    /* ── Lier article à mission ────────────────────────── */
    elseif ($action === 'link_article') {
        try {
            $db->prepare("UPDATE li_missions SET article_id=? WHERE id=?")->execute([(int)$_POST['article_id'],(int)$_POST['mission_id']]);
            $msg="✓ Article lié à la mission."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }

    /* ── Supprimer mission ─────────────────────────────── */
    elseif ($action === 'delete_mission' && $isChef) {
        try {
            $db->prepare("DELETE FROM li_missions WHERE id=?")->execute([(int)$_POST['mission_id']]);
            $msg="✓ Mission supprimée."; $msgType='success';
        } catch(Exception $e){$msg=$e->getMessage();$msgType='error';}
    }
}

/* ═══════════════════════════════════════════════════════════
   REQUÊTES DONNÉES
═══════════════════════════════════════════════════════════ */
$fStatut = $_GET['statut'] ?? '';
$fJour   = $_GET['journaliste'] ?? '';
$fType   = $_GET['type'] ?? '';
$fPrio   = $_GET['priorite'] ?? '';
$fSearch = trim($_GET['q'] ?? '');
$openNew = isset($_GET['open']) && $_GET['open']==='new';
$viewId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$where=[]; $params=[];
if (!$isChef) { $where[]='m.journaliste_id=?'; $params[]=$userId; }
if ($fStatut) { $where[]='m.statut=?'; $params[]=$fStatut; }
if ($fJour)   { $where[]='m.journaliste_id=?'; $params[]=$fJour; }
if ($fType)   { $where[]='m.type_mission=?'; $params[]=$fType; }
if ($fPrio)   { $where[]='m.priorite=?'; $params[]=$fPrio; }
if ($fSearch) { $where[]='(m.titre LIKE ? OR m.lieu LIKE ?)'; $params[]="%$fSearch%"; $params[]="%$fSearch%"; }

$whereSQL = $where ? "WHERE ".implode(" AND ",$where) : "";

$missions = $db->prepare("
    SELECT m.*, u.username jnom,
           pj.firstname jfn, pj.lastname jln,
           u2.username chef_nom,
           pc.firstname cfn, pc.lastname cln,
           a.titre art_titre, a.reference art_ref, a.statut art_statut
    FROM li_missions m
    JOIN users u ON m.journaliste_id=u.id
    LEFT JOIN profiles pj ON pj.id = u.profile_id
    LEFT JOIN users u2 ON m.assigne_par=u2.id
    LEFT JOIN profiles pc ON pc.id = u2.profile_id
    LEFT JOIN li_articles a ON m.article_id=a.id
    $whereSQL
    ORDER BY FIELD(m.statut,'en_cours','assigne','termine','archive','annule'),
             FIELD(m.priorite,'urgente','importante','normale'),
             m.date_limite ASC
    LIMIT 150");
$missions->execute($params);
$missions = $missions->fetchAll(PDO::FETCH_ASSOC);

// Stats
$msStats = $db->prepare("SELECT statut,COUNT(*) n FROM li_missions ".(!$isChef?' WHERE journaliste_id='.$userId:'')." GROUP BY statut");
$msStats->execute(); $sArr=[];
foreach($msStats->fetchAll(PDO::FETCH_ASSOC) as $r) $sArr[$r['statut']]=$r['n'];

// Journalistes — bugfix : on lit depuis users.faction (et non company_employees)
$journos = $db->prepare("
    SELECT u.id, u.username,
           COALESCE(NULLIF(u.faction_rank,''),'Journaliste') AS rank,
           p.firstname AS profile_firstname, p.lastname AS profile_lastname
    FROM users u
    LEFT JOIN profiles p ON p.id=u.profile_id
    WHERE u.faction=? AND u.is_active=1
    ORDER BY u.faction_rank, u.username");
$journos->execute([$companyFaction]); $journos=$journos->fetchAll(PDO::FETCH_ASSOC);

// Articles disponibles à lier
$artLink = $db->query("SELECT id,reference,titre FROM li_articles WHERE statut NOT IN('archive','refuse') ORDER BY created_at DESC LIMIT 100");
$artLink = $artLink ? $artLink->fetchAll(PDO::FETCH_ASSOC) : [];

// Mission en vue
$viewMis = null;
if ($viewId) {
    $vq=$db->prepare("
        SELECT m.*, u.username jnom, pj.firstname jfn, pj.lastname jln,
               u2.username chef_nom, pc.firstname cfn, pc.lastname cln,
               a.titre art_titre, a.reference art_ref, a.statut art_statut
        FROM li_missions m
        JOIN users u ON m.journaliste_id=u.id
        LEFT JOIN profiles pj ON pj.id=u.profile_id
        LEFT JOIN users u2 ON m.assigne_par=u2.id
        LEFT JOIN profiles pc ON pc.id=u2.profile_id
        LEFT JOIN li_articles a ON m.article_id=a.id
        WHERE m.id=?");
    $vq->execute([$viewId]); $viewMis=$vq->fetch(PDO::FETCH_ASSOC);
}

$typesMission = [
    'reportage'=>['ico'=>'fa-video','lbl'=>'Reportage','col'=>'#3b82f6'],
    'interview'=>['ico'=>'fa-microphone','lbl'=>'Interview','col'=>'#8b5cf6'],
    'conference_presse'=>['ico'=>'fa-chalkboard-teacher','lbl'=>'Conférence de presse','col'=>'#06b6d4'],
    'investigation'=>['ico'=>'fa-search','lbl'=>'Investigation','col'=>'#f97316'],
    'flash_info'=>['ico'=>'fa-bolt','lbl'=>'Flash Info','col'=>'#ef4444'],
    'portrait'=>['ico'=>'fa-user-circle','lbl'=>'Portrait','col'=>'#a78bfa'],
    'critique'=>['ico'=>'fa-star','lbl'=>'Critique','col'=>'#f59e0b'],
    'direct_live'=>['ico'=>'fa-broadcast-tower','lbl'=>'Direct / Live','col'=>'#22c55e'],
];

include __DIR__.'/includes/header.php';
?>
<style>
.mis-card { background: var(--surface); border: 1px solid var(--bd); border-radius: var(--r-lg); padding: 1.15rem; transition: all .18s; position: relative; overflow: hidden; display: flex; flex-direction: column; gap: .8rem; }
.mis-card:hover { border-color: var(--li-bd); transform: translateY(-2px); box-shadow: var(--shadow-2); }
.mis-card-stripe { position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.mis-type-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; color: #fff; flex-shrink: 0; }
.mis-card-title { font-family: 'Playfair Display', serif; font-weight: 700; font-size: .98rem; color: var(--tx); line-height: 1.32; }
.mis-card-meta { font-size: .72rem; color: var(--tx-3); display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.deadline-badge { font-size: .66rem; font-weight: 700; padding: .14rem .5rem; border-radius: 7px; }
.dl-ok { background: rgba(16,185,129,.10); color: #10b981; border: 1px solid rgba(16,185,129,.28); }
.dl-warn { background: rgba(245,158,11,.10); color: #f59e0b; border: 1px solid rgba(245,158,11,.28); }
.dl-danger { background: rgba(239,68,68,.10); color: #ef4444; border: 1px solid rgba(239,68,68,.28); }
.dl-out { background: rgba(239,68,68,.20); color: #fff; border: 1px solid #ef4444; }
.mis-view-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.3rem; }
@media(max-width:900px){ .mis-view-grid { grid-template-columns: 1fr; } }
</style>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>"><i class="fas fa-<?php echo $msgType==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo $msg; ?></div>
<?php endif; ?>

<!-- Vue mission unique -->
<?php if($viewMis): ?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.2rem">
    <div style="display:flex;align-items:center;gap:.6rem">
        <a href="redaction.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
        <span style="font-size:.7rem;color:var(--tx2)"><?php echo $viewMis['reference']; ?></span>
    </div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if($viewMis['statut']==='assigne' && ($viewMis['journaliste_id']==$userId||$isChef)): ?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="change_statut_mission"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>"><input type="hidden" name="new_statut" value="en_cours"><button class="btn btn-warn btn-sm"><i class="fas fa-play"></i> Démarrer</button></form>
        <?php endif; ?>
        <?php if($viewMis['statut']==='en_cours' && ($viewMis['journaliste_id']==$userId||$isChef)): ?>
        <form method="POST" style="display:inline"
              data-confirm="La mission sera marquée comme terminée. Vous pourrez la lier à un article rédactionnel."
              data-confirm-title="Terminer la mission ?"
              data-confirm-tone="success"
              data-confirm-icon="fa-flag-checkered"
              data-confirm-ok="Terminer">
            <input type="hidden" name="action" value="change_statut_mission"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>"><input type="hidden" name="new_statut" value="termine">
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Terminer</button>
        </form>
        <?php endif; ?>
        <?php if($isChef && $viewMis['statut']!=='archive'): ?>
        <button class="btn btn-secondary btn-sm" onclick="Company.openModal('modalEditMis')"><i class="fas fa-edit"></i> Modifier</button>
        <button class="btn btn-purple btn-sm" onclick="Company.openModal('modalLinkArt')"><i class="fas fa-link"></i> Lier Article</button>
        <form method="POST" style="display:inline"
              data-confirm="La mission sera archivée et masquée des listes actives."
              data-confirm-title="Archiver la mission ?"
              data-confirm-tone="warn"
              data-confirm-icon="fa-box-archive"
              data-confirm-ok="Archiver">
            <input type="hidden" name="action" value="change_statut_mission"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>"><input type="hidden" name="new_statut" value="archive">
            <button type="submit" class="btn btn-secondary btn-sm" title="Archiver"><i class="fas fa-archive"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="mis-view-grid">
<div>
    <div class="li-widget">
        <div style="display:flex;align-items:flex-start;gap:1rem;margin-bottom:1.2rem">
            <?php $tm=$typesMission[$viewMis['type_mission']]??['ico'=>'fa-map-marker','lbl'=>$viewMis['type_mission'],'col'=>'#3b82f6']; ?>
            <div class="mis-type-ico" style="width:52px;height:52px;border-radius:14px;font-size:1.35rem;background:<?php echo $tm['col']; ?>22;border:1px solid <?php echo $tm['col']; ?>44;color:<?php echo $tm['col']; ?>"><i class="fas <?php echo $tm['ico']; ?>"></i></div>
            <div style="flex:1">
                <div style="font-size:1.45rem;font-weight:800;color:var(--tx);font-family:'Playfair Display',serif;line-height:1.25;margin-bottom:.5rem;letter-spacing:-.01em"><?php echo htmlspecialchars($viewMis['titre']); ?></div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
                    <span class="badge badge-<?php echo $viewMis['statut']; ?>"><?php echo $viewMis['statut']; ?></span>
                    <span class="badge badge-<?php echo $viewMis['priorite']; ?>"><?php echo $viewMis['priorite']; ?></span>
                    <span class="badge" style="background:<?php echo $tm['col']; ?>18;color:<?php echo $tm['col']; ?>;border-color:<?php echo $tm['col']; ?>44"><i class="fas <?php echo $tm['ico']; ?>"></i> <?php echo $tm['lbl']; ?></span>
                </div>
            </div>
        </div>
        <?php if($viewMis['description']): ?>
        <div style="font-size:.9rem;color:var(--tx-2);line-height:1.75;margin-bottom:1.2rem"><?php echo li_sanitize_html($viewMis['description']); ?></div>
        <?php endif; ?>
        <?php if($viewMis['notes_chef']): ?>
        <div style="background:var(--li-softer);border:1px solid var(--li-bd);border-radius:9px;padding:.95rem;font-size:.85rem;color:var(--tx-2);margin-bottom:.9rem"><i class="fas fa-user-tie" style="color:var(--li);margin-right:.4rem"></i><strong style="color:var(--li)">Briefing chef :</strong> <?php echo li_sanitize_html($viewMis['notes_chef']); ?></div>
        <?php endif; ?>
        <?php if($viewMis['notes_terrain']): ?>
        <div style="background:rgba(139,92,246,.06);border:1px solid rgba(139,92,246,.18);border-radius:9px;padding:.95rem;font-size:.85rem;color:var(--tx-2)"><i class="fas fa-map-marker-alt" style="color:#8b5cf6;margin-right:.4rem"></i><strong style="color:#8b5cf6">Notes terrain :</strong> <?php echo li_sanitize_html($viewMis['notes_terrain']); ?></div>
        <?php endif; ?>
        <!-- Ajouter notes terrain (journaliste ou chef) -->
        <?php if($viewMis['journaliste_id']==$userId || $isChef): ?>
        <form method="POST" style="margin-top:1.2rem">
            <input type="hidden" name="action" value="update_mission"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>">
            <div class="form-group"><label class="form-label">Ajouter / modifier les notes terrain</label><textarea name="notes_terrain" data-rt-editor data-min-height="150px" placeholder="Ce que vous observez sur le terrain…"><?php echo htmlspecialchars($viewMis['notes_terrain']??''); ?></textarea></div>
            <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:.6rem"><i class="fas fa-save"></i> Sauvegarder les notes</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<div>
    <div class="li-widget">
        <div class="li-widget-title"><i class="fas fa-info-circle"></i> Détails Mission</div>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-hashtag"></i>Référence</div><div style="font-family:'Space Grotesk',monospace;font-size:.75rem;color:var(--tx2)"><?php echo $viewMis['reference']; ?></div></div>
        <?php $jVdisp = mdt_rp_display_name($viewMis['jfn']??null,$viewMis['jln']??null,(string)($viewMis['jnom']??'')); ?>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-user"></i>Journaliste</div><div style="font-weight:700;font-size:.82rem"><?php echo htmlspecialchars($jVdisp); ?></div></div>
        <?php if($viewMis['chef_nom']): $cDisp = mdt_rp_display_name($viewMis['cfn']??null,$viewMis['cln']??null,(string)($viewMis['chef_nom']??'')); ?>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-user-tie"></i>Assigné par</div><div style="font-size:.82rem;color:var(--tx-2)"><?php echo htmlspecialchars($cDisp); ?></div></div><?php endif; ?>
        <?php if($viewMis['lieu']): ?><div class="fin-row"><div class="fin-lbl"><i class="fas fa-map-pin"></i>Lieu</div><div style="font-size:.82rem"><?php echo htmlspecialchars($viewMis['lieu']); ?></div></div><?php endif; ?>
        <?php if($viewMis['date_debut']): ?><div class="fin-row"><div class="fin-lbl"><i class="fas fa-play-circle"></i>Début</div><div style="font-size:.82rem"><?php echo date('d/m/Y H:i',strtotime($viewMis['date_debut'])); ?></div></div><?php endif; ?>
        <?php if($viewMis['date_limite']): ?>
        <?php $dl=strtotime($viewMis['date_limite']); $diff=ceil(($dl-time())/86400); ?>
        <div class="fin-row"><div class="fin-lbl"><i class="fas fa-flag-checkered"></i>Deadline</div><div><?php echo date('d/m/Y H:i',$dl); ?> <span class="deadline-badge <?php echo $diff<0?'dl-out':($diff<=1?'dl-danger':($diff<=3?'dl-warn':'dl-ok')); ?>"><?php echo $diff<0?"⚠ Dépassée":($diff===0?"Aujourd'hui":"J-$diff"); ?></span></div></div>
        <?php endif; ?>
        <?php if($viewMis['date_rendu']): ?><div class="fin-row"><div class="fin-lbl"><i class="fas fa-check-circle"></i>Rendu le</div><div style="color:#22c55e;font-size:.82rem"><?php echo date('d/m/Y H:i',strtotime($viewMis['date_rendu'])); ?></div></div><?php endif; ?>
    </div>
    <?php if($viewMis['art_titre']): ?>
    <div class="li-widget">
        <div class="li-widget-title"><i class="fas fa-link"></i> Article Produit</div>
        <a href="articles.php?id=<?php echo $viewMis['article_id']; ?>" style="display:flex;align-items:center;gap:.7rem;padding:.75rem;background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.14);border-radius:9px;text-decoration:none">
            <i class="fas fa-newspaper" style="color:#3b82f6;font-size:1.1rem"></i>
            <div>
                <div style="font-weight:700;font-size:.82rem;color:var(--tx)"><?php echo htmlspecialchars($viewMis['art_titre']); ?></div>
                <div style="font-size:.68rem;color:var(--tx2)"><?php echo $viewMis['art_ref']; ?> · <span class="badge badge-<?php echo $viewMis['art_statut']; ?>"><?php echo $viewMis['art_statut']; ?></span></div>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Modal modifier mission -->
<div class="modal-overlay hidden" id="modalEditMis">
    <div class="modal">
        <div class="modal-header"><h2><i class="fas fa-edit"></i> Modifier la Mission</h2><button class="modal-close" onclick="Company.closeModal('modalEditMis')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_mission"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre *</label><input name="titre" class="form-input" value="<?php echo htmlspecialchars($viewMis['titre']); ?>" required></div>
                    <div class="form-group">
                        <label class="form-label">Journaliste</label>
                        <select name="journaliste_id" class="form-select">
                            <?php foreach($journos as $j):
                                $jDisp = mdt_rp_display_name($j['profile_firstname']??null,$j['profile_lastname']??null,(string)$j['username']);
                            ?><option value="<?php echo $j['id']; ?>" <?php echo $viewMis['journaliste_id']==$j['id']?'selected':''; ?>><?php echo htmlspecialchars($jDisp); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type_mission" class="form-select">
                            <?php foreach($typesMission as $k=>$t): ?><option value="<?php echo $k; ?>" <?php echo $viewMis['type_mission']===$k?'selected':''; ?>><?php echo $t['lbl']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Priorité</label><select name="priorite" class="form-select"><?php foreach(['normale','importante','urgente'] as $p): ?><option value="<?php echo $p; ?>" <?php echo $viewMis['priorite']===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Lieu</label><input name="lieu" class="form-input" value="<?php echo htmlspecialchars($viewMis['lieu']??''); ?>"></div>
                    <div class="form-group"><label class="form-label">Date début</label><input type="datetime-local" name="date_debut" class="form-input" value="<?php echo $viewMis['date_debut']?date('Y-m-d\TH:i',strtotime($viewMis['date_debut'])):''; ?>"></div>
                    <div class="form-group"><label class="form-label">Deadline</label><input type="datetime-local" name="date_limite" class="form-input" value="<?php echo $viewMis['date_limite']?date('Y-m-d\TH:i',strtotime($viewMis['date_limite'])):''; ?>"></div>
                    <div class="form-group form-full"><label class="form-label">Description</label><textarea name="description" data-rt-editor data-min-height="180px"><?php echo htmlspecialchars($viewMis['description']??''); ?></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes chef</label><textarea name="notes_chef" data-rt-editor data-min-height="140px"><?php echo htmlspecialchars($viewMis['notes_chef']??''); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalEditMis')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- Modal lier article -->
<div class="modal-overlay hidden" id="modalLinkArt">
    <div class="modal modal-sm">
        <div class="modal-header"><h2><i class="fas fa-link"></i> Lier un Article</h2><button class="modal-close" onclick="Company.closeModal('modalLinkArt')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="link_article"><input type="hidden" name="mission_id" value="<?php echo $viewMis['id']; ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Article produit</label>
                    <select name="article_id" class="form-select">
                        <option value="">— Sélectionner —</option>
                        <?php foreach($artLink as $al): ?><option value="<?php echo $al['id']; ?>" <?php echo $viewMis['article_id']==$al['id']?'selected':''; ?>>[<?php echo $al['reference']; ?>] <?php echo htmlspecialchars(mb_substr($al['titre'],0,55)); ?>…</option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalLinkArt')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Lier</button></div>
        </form>
    </div>
</div>

<?php else: ?>

<!-- LISTE MISSIONS -->
<div class="page-header">
    <div>
        <div class="page-header-title"><i class="fas fa-map-marker-alt"></i> Terrain & Missions</div>
        <div class="page-header-sub">
            <strong style="color:var(--tx-2)"><?php echo array_sum($sArr); ?></strong> mission<?php echo array_sum($sArr)>1?'s':''; ?> ·
            <?php echo $sArr['en_cours']??0; ?> en cours ·
            <?php echo $sArr['assigne']??0; ?> assignée<?php echo ($sArr['assigne']??0)>1?'s':''; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if($isChef): ?>
        <button class="btn btn-primary" onclick="Company.openModal('modalCreate')"><i class="fas fa-paper-plane"></i> Assigner une mission</button>
        <?php endif; ?>
    </div>
</div>

<div class="stat-tab">
    <?php $sLabels=['all'=>'Toutes','assigne'=>'Assignées','en_cours'=>'En cours','termine'=>'Terminées','archive'=>'Archives','annule'=>'Annulées']; ?>
    <?php foreach($sLabels as $sv=>$sl): $cnt=$sv==='all'?array_sum($sArr):($sArr[$sv]??0); ?>
    <a href="redaction.php?statut=<?php echo $sv==='all'?'':$sv; ?>" class="stat-tab-item <?php echo ($fStatut===$sv||($sv==='all'&&!$fStatut))?'active':''; ?>"><?php echo $sl; ?> (<?php echo $cnt; ?>)</a>
    <?php endforeach; ?>
</div>

<!-- Filtres -->
<div class="filters-bar">
    <div class="filter-group" style="flex:2;min-width:140px"><label class="filter-lbl"><i class="fas fa-search"></i> Recherche</label><input type="text" class="form-input" id="searchMis" placeholder="Titre, lieu…" value="<?php echo htmlspecialchars($fSearch); ?>" onkeyup="applyFilter()"></div>
    <div class="filter-group"><label class="filter-lbl">Type</label>
        <select class="form-select" id="fType" onchange="applyFilter()">
            <option value="">Tous</option>
            <?php foreach($typesMission as $k=>$t): ?><option value="<?php echo $k; ?>" <?php echo $fType===$k?'selected':''; ?>><?php echo $t['lbl']; ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group"><label class="filter-lbl">Priorité</label>
        <select class="form-select" id="fPrioSel" onchange="applyFilter()">
            <option value="">Toutes</option>
            <option value="urgente" <?php echo $fPrio==='urgente'?'selected':''; ?>>Urgente</option>
            <option value="importante" <?php echo $fPrio==='importante'?'selected':''; ?>>Importante</option>
            <option value="normale" <?php echo $fPrio==='normale'?'selected':''; ?>>Normale</option>
        </select>
    </div>
    <?php if($isChef): ?>
    <div class="filter-group"><label class="filter-lbl">Journaliste</label>
        <select class="form-select" id="fJourSel" onchange="applyFilter()">
            <option value="">Tous</option>
            <?php foreach($journos as $j):
                $jDisp = mdt_rp_display_name($j['profile_firstname']??null,$j['profile_lastname']??null,(string)$j['username']);
            ?><option value="<?php echo $j['id']; ?>" <?php echo $fJour==$j['id']?'selected':''; ?>><?php echo htmlspecialchars($jDisp); ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-group" style="flex:0"><label class="filter-lbl">&nbsp;</label><a href="redaction.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a></div>
</div>

<!-- Stats rapides -->
<div class="li-stats" style="grid-template-columns:repeat(auto-fit,minmax(155px,1fr));margin-bottom:1.3rem">
    <div class="li-stat"><div class="li-stat-ico"><i class="fas fa-list"></i></div><div><div class="li-stat-val"><?php echo array_sum($sArr); ?></div><div class="li-stat-lbl">Total</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-amber"><i class="fas fa-play"></i></div><div><div class="li-stat-val"><?php echo $sArr['en_cours']??0; ?></div><div class="li-stat-lbl">En cours</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-slate"><i class="fas fa-inbox"></i></div><div><div class="li-stat-val"><?php echo $sArr['assigne']??0; ?></div><div class="li-stat-lbl">Assignées</div></div></div>
    <div class="li-stat"><div class="li-stat-ico ico-emerald"><i class="fas fa-check"></i></div><div><div class="li-stat-val"><?php echo $sArr['termine']??0; ?></div><div class="li-stat-lbl">Terminées</div></div></div>
</div>

<!-- Grille missions -->
<?php if(empty($missions)): ?>
<div class="empty-state"><i class="fas fa-map-marker-alt"></i><p>Aucune mission trouvée</p><?php if($isChef): ?><button class="btn btn-purple" onclick="Company.openModal('modalCreate')"><i class="fas fa-plus"></i> Créer une mission</button><?php endif; ?></div>
<?php else: ?>
<div id="misGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:1.1rem">
    <?php foreach($missions as $m): ?>
    <?php $tm=$typesMission[$m['type_mission']]??['ico'=>'fa-map-marker','lbl'=>$m['type_mission'],'col'=>'#3b82f6'];
    $dl=$m['date_limite']?ceil((strtotime($m['date_limite'])-time())/86400):null; ?>
    <div class="mis-card">
        <div class="mis-card-stripe" style="background:<?php echo $tm['col']; ?>"></div>
        <div style="display:flex;align-items:flex-start;gap:.85rem;margin-bottom:.85rem">
            <div class="mis-type-ico" style="background:<?php echo $tm['col']; ?>22;border:1px solid <?php echo $tm['col']; ?>44;color:<?php echo $tm['col']; ?>"><i class="fas <?php echo $tm['ico']; ?>"></i></div>
            <div style="flex:1;min-width:0">
                <div class="mis-card-title"><?php echo htmlspecialchars($m['titre']); ?></div>
                <div class="mis-card-meta">
                    <?php $jDispC = mdt_rp_display_name($m['jfn']??null,$m['jln']??null,(string)($m['jnom']??'')); ?>
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($jDispC); ?>
                    <?php if($m['lieu']): ?><span>· <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($m['lieu']); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:.8rem">
            <span class="badge badge-<?php echo $m['statut']; ?>"><?php echo $m['statut']; ?></span>
            <span class="badge badge-<?php echo $m['priorite']; ?>"><?php echo $m['priorite']; ?></span>
            <span class="badge" style="background:<?php echo $tm['col']; ?>18;color:<?php echo $tm['col']; ?>;border-color:<?php echo $tm['col']; ?>44;font-size:.6rem"><?php echo $tm['lbl']; ?></span>
            <?php if($dl!==null): ?><span class="deadline-badge <?php echo $dl<0?'dl-out':($dl<=1?'dl-danger':($dl<=3?'dl-warn':'dl-ok')); ?>"><?php echo $dl<0?"⚠ Dépassée":($dl===0?"Auj.":"J-$dl"); ?></span><?php endif; ?>
            <?php if($m['art_titre']): ?><span class="badge" style="background:rgba(34,197,94,.1);color:#22c55e;border-color:rgba(34,197,94,.26);font-size:.6rem"><i class="fas fa-link"></i> Article</span><?php endif; ?>
        </div>
        <?php if($m['description']): ?><div style="font-size:.75rem;color:var(--tx2);line-height:1.55;margin-bottom:.8rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo htmlspecialchars($m['description']); ?></div><?php endif; ?>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap">
            <a href="redaction.php?id=<?php echo $m['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-eye"></i> Voir</a>
            <?php if($m['statut']==='assigne' && ($m['journaliste_id']==$userId||$isChef)): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="change_statut_mission"><input type="hidden" name="mission_id" value="<?php echo $m['id']; ?>"><input type="hidden" name="new_statut" value="en_cours"><button class="btn btn-sm btn-warn"><i class="fas fa-play"></i> Démarrer</button></form>
            <?php endif; ?>
            <?php if($m['statut']==='en_cours' && ($m['journaliste_id']==$userId||$isChef)): ?>
            <form method="POST" style="display:inline"
                  data-confirm="La mission %t sera marquée comme terminée."
                  data-confirm-title="Terminer la mission ?"
                  data-confirm-tone="success"
                  data-confirm-icon="fa-flag-checkered"
                  data-confirm-ok="Terminer"
                  data-confirm-target="<?php echo htmlspecialchars($m['titre']); ?>">
                <input type="hidden" name="action" value="change_statut_mission"><input type="hidden" name="mission_id" value="<?php echo $m['id']; ?>"><input type="hidden" name="new_statut" value="termine">
                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Terminer</button>
            </form>
            <?php endif; ?>
            <?php if($isChef): ?>
            <form method="POST" style="display:inline"
                  data-confirm="La mission %t sera définitivement supprimée. Cette action est irréversible."
                  data-confirm-title="Supprimer la mission ?"
                  data-confirm-tone="danger"
                  data-confirm-icon="fa-trash"
                  data-confirm-ok="Supprimer définitivement"
                  data-confirm-target="<?php echo htmlspecialchars($m['titre']); ?>">
                <input type="hidden" name="action" value="delete_mission"><input type="hidden" name="mission_id" value="<?php echo $m['id']; ?>">
                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Supprimer"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ MODAL CRÉER MISSION ═══ -->
<div class="modal-overlay <?php echo $openNew?'':'hidden'; ?>" id="modalCreate">
    <div class="modal">
        <div class="modal-header"><h2><i class="fas fa-map-marker-alt"></i> Nouvelle Mission Terrain</h2><button class="modal-close" onclick="Company.closeModal('modalCreate')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="create_mission">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group form-full"><label class="form-label">Titre de la mission *</label><input name="titre" class="form-input" placeholder="Ex: Reportage fusillade Strawberry…" required></div>
                    <div class="form-group">
                        <label class="form-label">Journaliste assigné *</label>
                        <select name="journaliste_id" class="form-select" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach($journos as $j):
                                $jDisp = mdt_rp_display_name($j['profile_firstname']??null,$j['profile_lastname']??null,(string)$j['username']);
                            ?><option value="<?php echo $j['id']; ?>"><?php echo htmlspecialchars($jDisp); ?> (<?php echo htmlspecialchars($j['rank']); ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type de mission</label>
                        <select name="type_mission" class="form-select">
                            <?php foreach($typesMission as $k=>$t): ?><option value="<?php echo $k; ?>"><?php echo $t['lbl']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priorité</label>
                        <select name="priorite" class="form-select">
                            <option value="normale">Normale</option>
                            <option value="importante">Importante</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Lieu / Adresse</label><input name="lieu" class="form-input" placeholder="Strawberry Ave, LS…"></div>
                    <div class="form-group"><label class="form-label">Date de début</label><input type="datetime-local" name="date_debut" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Deadline</label><input type="datetime-local" name="date_limite" class="form-input"></div>
                    <div class="form-group form-full"><label class="form-label">Description / Briefing</label><textarea name="description" data-rt-editor data-min-height="180px" placeholder="Contexte, objectifs, contacts sur place…"></textarea></div>
                    <div class="form-group form-full"><label class="form-label">Notes pour le journaliste</label><textarea name="notes_chef" data-rt-editor data-min-height="140px" placeholder="Instructions spéciales, angles à couvrir…"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="Company.closeModal('modalCreate')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Assigner la mission</button></div>
        </form>
    </div>
</div>

<script>
let _applyTimer = null;
function applyFilter() {
    clearTimeout(_applyTimer);
    _applyTimer = setTimeout(() => {
        const params = new URLSearchParams(window.location.search);
        const q = document.getElementById('searchMis')?.value || '';
        const t = document.getElementById('fType')?.value || '';
        const p = document.getElementById('fPrioSel')?.value || '';
        const j = document.getElementById('fJourSel')?.value || '';
        q ? params.set('q', q) : params.delete('q');
        t ? params.set('type', t) : params.delete('type');
        p ? params.set('priorite', p) : params.delete('priorite');
        j ? params.set('journaliste', j) : params.delete('journaliste');
        window.location.search = params.toString();
    }, 350);
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>