<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$companyFaction = 'USSS';
if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL . '/pages/dashboard.php');
$pageTitle = 'Dashboard Central';
$db = Database::getInstance()->getConnection();

// ── Data ──────────────────────────────────────────────────────────────────
$agentsStats=['total'=>0,'en_service'=>0,'hors_service'=>0,'conge'=>0,'suspension'=>0];
try{$r=$db->query("SELECT COUNT(*) as total,SUM(statut='EN_SERVICE') as en_service,SUM(statut='HORS_SERVICE') as hors_service,SUM(statut='CONGE') as conge,SUM(statut='SUSPENSION') as suspension FROM usss_agents")->fetch();if($r)$agentsStats=$r;}catch(Exception $e){}

$opsEnCours=0;try{$opsEnCours=(int)$db->query("SELECT COUNT(*) FROM usss_operations WHERE statut IN ('PLANIFIEE','EN_COURS')")->fetchColumn();}catch(Exception $e){}
$mandatsActifs=0;try{$mandatsActifs=(int)$db->query("SELECT COUNT(*) FROM usss_mandats WHERE statut='ACTIF'")->fetchColumn();}catch(Exception $e){}
$dossiersOuverts=0;try{$dossiersOuverts=(int)$db->query("SELECT COUNT(*) FROM usss_dossiers WHERE statut IN ('OUVERT','EN_COURS')")->fetchColumn();}catch(Exception $e){}

$niveauAlerte=['niveau'=>'VERT','description'=>'Situation nominale','created_at'=>date('Y-m-d H:i:s')];
try{$r=$db->query("SELECT * FROM usss_niveau_alerte ORDER BY created_at DESC LIMIT 1")->fetch();if($r)$niveauAlerte=$r;}catch(Exception $e){}

$dossiersRecents=[];
try{$dossiersRecents=$db->query("SELECT d.*,a.nom_rp,a.prenom_rp,a.badge_number,div.nom as div_nom FROM usss_dossiers d JOIN usss_agents a ON d.agent_responsable=a.id LEFT JOIN usss_divisions div ON d.division_responsable=div.id ORDER BY d.created_at DESC LIMIT 8")->fetchAll();}catch(Exception $e){}

$opsActives=[];
try{$opsActives=$db->query("SELECT o.*,a.nom_rp,a.prenom_rp FROM usss_operations o JOIN usss_agents a ON o.chef_operation=a.id WHERE o.statut IN ('PLANIFIEE','EN_COURS') ORDER BY o.date_debut DESC LIMIT 5")->fetchAll();}catch(Exception $e){}

$divisions=[];
try{$divisions=$db->query("SELECT d.*,COUNT(a.id) as eff FROM usss_divisions d LEFT JOIN usss_agents a ON d.id=a.division_id AND a.statut='EN_SERVICE' WHERE d.actif=1 GROUP BY d.id ORDER BY d.ordre LIMIT 8")->fetchAll();}catch(Exception $e){}

$annonces=[];
try{$annonces=$db->query("SELECT ann.*,u.username as auteur FROM usss_annonces ann JOIN users u ON ann.auteur_id=u.id WHERE ann.actif=1 AND (ann.date_expiration IS NULL OR ann.date_expiration>=NOW()) ORDER BY ann.date_publication DESC LIMIT 4")->fetchAll();}catch(Exception $e){}

$mandatsAttente=[];
try{$mandatsAttente=$db->query("SELECT m.*,a.nom_rp,a.badge_number FROM usss_mandats m JOIN usss_agents a ON m.agent_demandeur=a.id WHERE m.statut='EN_ATTENTE_VALIDATION' ORDER BY m.created_at DESC LIMIT 5")->fetchAll();}catch(Exception $e){}

// Alerte color map
$alerteCfg=[
    'VERT'   =>['--ab-color:#4ade80','rgb(74,222,128)',  'cls-unrestr', 'fa-shield-halved'],
    'JAUNE'  =>['--ab-color:#fbbf24','rgb(251,191,36)',  'cls-conf',    'fa-triangle-exclamation'],
    'ORANGE' =>['--ab-color:#f97316','rgb(249,115,22)',  'cls-secret',  'fa-exclamation-circle'],
    'ROUGE'  =>['--ab-color:#f56270','rgb(245,98,112)',  'cls-ts',      'fa-radiation'],
];
$ac=$alerteCfg[$niveauAlerte['niveau']]??$alerteCfg['VERT'];

$prioColors=['FAIBLE'=>'b-slate','MOYENNE'=>'b-blue','HAUTE'=>'b-amber','CRITIQUE'=>'b-red'];
$statColors=['OUVERT'=>'b-blue','EN_COURS'=>'b-amber','RESOLU'=>'b-green','CLASSE'=>'b-slate','TRANSMIS_DOJ'=>'b-purple'];

include __DIR__ . '/includes/header.php';
?>

<!-- ALERTE NIVEAU -->
<div class="alerte-banner" style="<?php echo $ac[0]; ?>;--ab-rgb:<?php echo $ac[1]; ?>">
  <div class="ab-dot"></div>
  <div style="flex:1">
    <div class="ab-level">NIVEAU D'ALERTE : <?php echo $niveauAlerte['niveau']; ?></div>
    <div class="ab-desc"><?php echo htmlspecialchars($niveauAlerte['description']); ?></div>
  </div>
  <span class="cls-stamp <?php echo $ac[2]; ?>">
    <i class="fas <?php echo $ac[3]; ?>"></i> <?php echo $niveauAlerte['niveau']; ?>
  </span>
  <span style="font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:var(--txt-m)"><?php echo date('d.m.Y H:i',strtotime($niveauAlerte['created_at'])); ?></span>
</div>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(5,1fr)">
  <?php $kpis=[
    ['Agents Totaux',$agentsStats['total'],'fas fa-id-badge','var(--blue)'],
    ['En Service',$agentsStats['en_service'],'fas fa-circle-check','var(--green-l)'],
    ['Opérations',$opsEnCours,'fas fa-crosshairs','var(--red-l)'],
    ['Mandats Actifs',$mandatsActifs,'fas fa-gavel','var(--amber-l)'],
    ['Dossiers Ouverts',$dossiersOuverts,'fas fa-folder-open','var(--blue-xl)'],
  ];
  foreach($kpis as [$lbl,$val,$ico,$col]):?>
  <div class="kpi" style="--kpi-color:<?php echo $col; ?>">
    <div class="kpi-ico"></div>
    <div class="kpi-ico-inner"><i class="<?php echo $ico; ?>"></i></div>
    <div class="kpi-lbl" style="margin-left:0;margin-top:2.1rem"><?php echo $lbl; ?></div>
    <div class="kpi-val"><?php echo number_format((int)$val); ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Annonces -->
<?php if(!empty($annonces)):?>
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-title"><i class="fas fa-bullhorn"></i> Annonces Officielles</div>
  <div style="display:flex;flex-direction:column;gap:.5rem">
  <?php foreach($annonces as $ann):
    $urgent=in_array($ann['niveau_importance'],['URGENT','ALERTE']);
  ?>
  <div style="display:flex;align-items:flex-start;gap:.85rem;padding:.75rem .9rem;background:<?php echo $urgent?'rgba(191,28,46,.06)':'rgba(255,255,255,.02)'; ?>;border:1px solid <?php echo $urgent?'rgba(191,28,46,.2)':'var(--bdr)'; ?>;border-radius:8px;border-left:3px solid <?php echo $urgent?'var(--red)':'var(--blue)'; ?>">
    <?php if($urgent):?><i class="fas fa-circle-exclamation" style="color:var(--red-l);flex-shrink:0;margin-top:.1rem"></i><?php endif; ?>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;font-size:.85rem;margin-bottom:.18rem"><?php echo htmlspecialchars($ann['titre']); ?></div>
      <div style="font-size:.78rem;color:var(--txt-s);line-height:1.5"><?php echo nl2br(htmlspecialchars(mb_substr($ann['contenu'],0,160))); ?></div>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <div class="badge <?php echo $urgent?'b-red':'b-blue'; ?>"><?php echo htmlspecialchars($ann['niveau_importance']); ?></div>
      <div style="font-family:'IBM Plex Mono',monospace;font-size:.62rem;color:var(--txt-m);margin-top:.3rem"><?php echo date('d.m.Y',strtotime($ann['date_publication'])); ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid2">
<!-- Dossiers récents -->
<div class="card">
  <div class="sec-head">
    <div class="card-title" style="border:none;margin:0;padding:0">
      <i class="fas fa-folder-open"></i> Dossiers Récents
    </div>
    <a href="dossiers.php" class="btn btn-ghost btn-xs">Voir tout <i class="fas fa-arrow-right"></i></a>
  </div>
  <?php if(empty($dossiersRecents)):?>
  <div style="text-align:center;padding:2.5rem;color:var(--txt-m);font-size:.82rem">Aucun dossier</div>
  <?php else: foreach($dossiersRecents as $d):
    $pc=$prioColors[$d['priorite']]??'b-slate';
    $sc=$statColors[$d['statut']]??'b-slate';
  ?>
  <div style="display:flex;align-items:center;gap:.65rem;padding:.6rem .15rem;border-bottom:1px solid var(--bdr)">
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:.45rem;margin-bottom:.15rem;flex-wrap:wrap">
        <span class="ref"><?php echo htmlspecialchars($d['reference']); ?></span>
        <span class="badge <?php echo $pc; ?>" style="font-size:.55rem"><?php echo $d['priorite']; ?></span>
        <span class="badge <?php echo $sc; ?>" style="font-size:.55rem"><?php echo str_replace('_',' ',$d['statut']); ?></span>
      </div>
      <div style="font-size:.82rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($d['titre']); ?></div>
      <div style="font-size:.69rem;color:var(--txt-m);margin-top:.1rem"><?php echo htmlspecialchars($d['nom_rp'].' '.$d['prenom_rp']); ?> · <?php echo $d['badge_number']; ?></div>
    </div>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:var(--txt-m);flex-shrink:0"><?php echo date('d.m.Y',strtotime($d['date_ouverture'])); ?></div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Opérations actives + Mandats en attente -->
<div>
  <div class="card" style="margin-bottom:1.25rem">
    <div class="sec-head">
      <div class="card-title" style="border:none;margin:0;padding:0"><i class="fas fa-crosshairs"></i> Opérations Actives</div>
      <a href="operations.php" class="btn btn-ghost btn-xs">Voir tout <i class="fas fa-arrow-right"></i></a>
    </div>
    <?php if(empty($opsActives)):?>
    <div style="text-align:center;padding:1.5rem;color:var(--txt-m);font-size:.8rem">Aucune opération en cours</div>
    <?php else: foreach($opsActives as $op): ?>
    <div style="display:flex;align-items:center;gap:.65rem;padding:.55rem .15rem;border-bottom:1px solid var(--bdr)">
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:.45rem;margin-bottom:.12rem">
          <span class="cls-stamp <?php echo $op['niveau_classification']==='TOP_SECRET'||$op['niveau_classification']==='TS'?'cls-ts':($op['niveau_classification']==='SECRET'?'cls-secret':'cls-conf'); ?>" style="font-size:.52rem"><?php echo $op['niveau_classification']; ?></span>
          <span class="badge <?php echo $op['statut']==='EN_COURS'?'b-amber':'b-blue'; ?>" style="font-size:.55rem"><?php echo str_replace('_',' ',$op['statut']); ?></span>
        </div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:.9rem;letter-spacing:.06em">OP-<?php echo htmlspecialchars($op['nom_code']); ?></div>
        <div style="font-size:.69rem;color:var(--txt-m)"><?php echo htmlspecialchars($op['nom_rp'].' '.$op['prenom_rp']); ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if(!empty($mandatsAttente)): ?>
  <div class="card">
    <div class="card-title"><i class="fas fa-clock"></i> Mandats En Attente</div>
    <?php foreach($mandatsAttente as $m): ?>
    <div style="display:flex;align-items:center;gap:.65rem;padding:.5rem .15rem;border-bottom:1px solid var(--bdr)">
      <div style="flex:1;min-width:0">
        <div class="ref" style="font-size:.69rem;margin-bottom:.1rem"><?php echo htmlspecialchars($m['reference']??'—'); ?></div>
        <div style="font-size:.82rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($m['sujet']??$m['type']); ?></div>
        <div style="font-size:.68rem;color:var(--txt-m)"><?php echo htmlspecialchars($m['nom_rp']); ?> · <?php echo $m['badge_number']; ?></div>
      </div>
      <div class="badge b-amber" style="font-size:.55rem;flex-shrink:0">Attente</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Divisions effectifs -->
<?php if(!empty($divisions)): ?>
<div class="card">
  <div class="card-title"><i class="fas fa-sitemap"></i> Effectifs par Division</div>
  <div class="grid3">
  <?php foreach($divisions as $div): $maxEff=10; $pct=min(round(($div['eff']/$maxEff)*100),100); ?>
  <div style="padding:.75rem .9rem;background:var(--bg-ov);border:1px solid var(--bdr);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--bdr-b)'" onmouseout="this.style.borderColor='var(--bdr)'">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.35rem">
      <div>
        <div style="font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:var(--blue-l);margin-bottom:.12rem"><?php echo htmlspecialchars($div['code']); ?></div>
        <div style="font-size:.82rem;font-weight:600;line-height:1.2"><?php echo htmlspecialchars($div['nom']); ?></div>
      </div>
      <div style="font-family:'IBM Plex Mono',monospace;font-size:1.1rem;font-weight:600;color:var(--blue-xl)"><?php echo $div['eff']; ?></div>
    </div>
    <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $pct; ?>%"></div></div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>