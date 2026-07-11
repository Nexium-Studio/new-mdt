<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$companyName = '🇺🇸 United States Secret Service';
$companyColor = '#1e3a8a';
$companyFaction = 'USSS';

if ($_SESSION['user_faction'] !== $companyFaction && !hasRole(['*','Administrateur','Développeur','Staff']) && !is_demo_mode()) {
    redirect(SITE_URL . '/pages/dashboard.php');
}

$pageTitle = 'Statistiques & Analytics';
$db = Database::getInstance()->getConnection();

// Période
$periode = $_GET['periode'] ?? 'mois';
$dateDebut = '';
$dateFin = date('Y-m-d');

switch ($periode) {
    case 'semaine':
        $dateDebut = date('Y-m-d', strtotime('-7 days'));
        break;
    case 'mois':
        $dateDebut = date('Y-m-d', strtotime('-30 days'));
        break;
    case 'trimestre':
        $dateDebut = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'annee':
        $dateDebut = date('Y-m-d', strtotime('-365 days'));
        break;
    case 'custom':
        $dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateFin = $_GET['date_fin'] ?? date('Y-m-d');
        break;
}

// Stats globales
$statsGlobales = [
    'total_agents' => $db->query("SELECT COUNT(*) FROM usss_agents")->fetchColumn(),
    'agents_actifs' => $db->query("SELECT COUNT(*) FROM usss_agents WHERE statut = 'EN_SERVICE'")->fetchColumn(),
    'total_dossiers' => $db->query("SELECT COUNT(*) FROM usss_dossiers")->fetchColumn(),
    'dossiers_ouverts' => $db->query("SELECT COUNT(*) FROM usss_dossiers WHERE statut IN ('OUVERT', 'EN_COURS')")->fetchColumn(),
    'mandats_actifs' => $db->query("SELECT COUNT(*) FROM usss_mandats WHERE statut = 'ACTIF'")->fetchColumn(),
    'operations_en_cours' => $db->query("SELECT COUNT(*) FROM usss_operations WHERE statut IN ('PLANIFIEE', 'EN_COURS')")->fetchColumn(),
    'surveillances_actives' => $db->query("SELECT COUNT(*) FROM usss_intelligence WHERE statut_surveillance = 'ACTIVE'")->fetchColumn(),
    'crimes_financiers' => $db->query("SELECT COUNT(*) FROM usss_crimes_financiers WHERE statut IN ('ENQUETE', 'GELE')")->fetchColumn(),
];

// Évolution dossiers
$evolutionDossiers = $db->query("
    SELECT 
        DATE_FORMAT(date_ouverture, '%Y-%m') as mois,
        COUNT(*) as nb_dossiers
    FROM usss_dossiers
    WHERE date_ouverture >= '$dateDebut' AND date_ouverture <= '$dateFin'
    GROUP BY mois
    ORDER BY mois
")->fetchAll();

// Dossiers par type
$dossiersParType = $db->query("
    SELECT 
        type,
        COUNT(*) as nb_dossiers,
        SUM(CASE WHEN statut = 'RESOLU' THEN 1 ELSE 0 END) as nb_resolus
    FROM usss_dossiers
    WHERE date_ouverture >= '$dateDebut' AND date_ouverture <= '$dateFin'
    GROUP BY type
    ORDER BY nb_dossiers DESC
")->fetchAll();

// Dossiers par priorité
$dossiersParPriorite = $db->query("
    SELECT 
        priorite,
        COUNT(*) as nb_dossiers
    FROM usss_dossiers
    WHERE date_ouverture >= '$dateDebut' AND date_ouverture <= '$dateFin'
    GROUP BY priorite
    ORDER BY FIELD(priorite, 'CRITIQUE', 'HAUTE', 'MOYENNE', 'FAIBLE')
")->fetchAll();

// Performance agents
$performanceAgents = $db->query("
    SELECT 
        a.nom_rp,
        a.prenom_rp,
        a.badge_number,
        d.nom as division_nom,
        COUNT(DISTINCT dos.id) as nb_dossiers,
        COUNT(DISTINCT o.id) as nb_operations,
        SUM(CASE WHEN dos.statut = 'RESOLU' THEN 1 ELSE 0 END) as nb_resolus
    FROM usss_agents a
    LEFT JOIN usss_divisions d ON a.division_id = d.id
    LEFT JOIN usss_dossiers dos ON a.id = dos.agent_responsable AND dos.date_ouverture >= '$dateDebut' AND dos.date_ouverture <= '$dateFin'
    LEFT JOIN usss_operations o ON a.id = o.chef_operation AND o.date_debut >= '$dateDebut' AND o.date_debut <= '$dateFin'
    WHERE a.statut = 'EN_SERVICE'
    GROUP BY a.id
    HAVING nb_dossiers > 0 OR nb_operations > 0
    ORDER BY (nb_dossiers + nb_operations) DESC
    LIMIT 10
")->fetchAll();

// Performance divisions
$performanceDivisions = $db->query("
    SELECT 
        d.nom,
        d.code,
        COUNT(DISTINCT a.id) as effectif,
        COUNT(DISTINCT dos.id) as nb_dossiers,
        COUNT(DISTINCT o.id) as nb_operations
    FROM usss_divisions d
    LEFT JOIN usss_agents a ON d.id = a.division_id AND a.statut = 'EN_SERVICE'
    LEFT JOIN usss_dossiers dos ON d.id = dos.division_responsable AND dos.date_ouverture >= '$dateDebut' AND dos.date_ouverture <= '$dateFin'
    LEFT JOIN usss_operations o ON d.id = o.division_responsable AND o.date_debut >= '$dateDebut' AND o.date_debut <= '$dateFin'
    WHERE d.actif = 1
    GROUP BY d.id
    ORDER BY (nb_dossiers + nb_operations) DESC
")->fetchAll();

// Mandats par type
$mandatsParType = $db->query("
    SELECT 
        type,
        COUNT(*) as nb_mandats,
        SUM(CASE WHEN statut = 'EXECUTE' THEN 1 ELSE 0 END) as nb_executes
    FROM usss_mandats
    WHERE date_emission >= '$dateDebut' AND date_emission <= '$dateFin'
    GROUP BY type
")->fetchAll();

// Crimes financiers
$statsFinancieres = $db->query("
    SELECT 
        type,
        COUNT(*) as nb_crimes,
        SUM(montant_estime) as montant_total
    FROM usss_crimes_financiers
    WHERE DATE(created_at) >= '$dateDebut' AND DATE(created_at) <= '$dateFin'
    GROUP BY type
")->fetchAll();

$typeColors = [
    'FINANCIER' => '#22c55e',
    'TERRORISME' => '#dc2626',
    'ENLEVEMENT' => '#f59e0b',
    'CORRUPTION' => '#8b5cf6',
    'TRAFIC' => '#ef4444',
    'MENACE_VIP' => '#dc2626',
    'CYBERCRIME' => '#3b82f6',
    'CONTREFACON' => '#f59e0b',
    'AUTRE' => '#64748b'
];

$prioriteColors = [
    'CRITIQUE' => '#dc2626',
    'HAUTE' => '#f59e0b',
    'MOYENNE' => '#3b82f6',
    'FAIBLE' => '#64748b'
];

// ── Data ─────────────────────────────────────────────────────────────────
$periode=$_GET['periode']??'mois';
$dateFin=date('Y-m-d');
$dateDebut=match($periode){'semaine'=>date('Y-m-d',strtotime('-7 days')),'trimestre'=>date('Y-m-d',strtotime('-90 days')),'annee'=>date('Y-m-d',strtotime('-365 days')),default=>date('Y-m-d',strtotime('-30 days'))};

$statsGlobal=[];
try{$statsGlobal=$db->query("SELECT (SELECT COUNT(*) FROM usss_agents WHERE statut='EN_SERVICE') as agents_actifs,(SELECT COUNT(*) FROM usss_dossiers WHERE date_ouverture BETWEEN '$dateDebut' AND '$dateFin') as dossiers_periode,(SELECT COUNT(*) FROM usss_mandats WHERE date_emission BETWEEN '$dateDebut' AND '$dateFin') as mandats_periode,(SELECT COUNT(*) FROM usss_operations WHERE date_debut BETWEEN '$dateDebut' AND '$dateFin') as ops_periode,(SELECT COUNT(*) FROM usss_dossiers WHERE statut='RESOLU' AND date_ouverture BETWEEN '$dateDebut' AND '$dateFin') as dossiers_resolus,(SELECT SUM(montant_estime) FROM usss_crimes_financiers WHERE created_at BETWEEN '$dateDebut' AND '$dateFin') as montant_financier")->fetch();}catch(Exception $e){}

$topAgents=[];
try{$topAgents=$db->query("SELECT a.nom_rp,a.prenom_rp,a.badge_number,a.grade,COUNT(DISTINCT d.id) as nb_dossiers,COUNT(DISTINCT m.id) as nb_mandats FROM usss_agents a LEFT JOIN usss_dossiers d ON d.agent_responsable=a.id AND d.date_ouverture BETWEEN '$dateDebut' AND '$dateFin' LEFT JOIN usss_mandats m ON m.agent_demandeur=a.id AND m.date_emission BETWEEN '$dateDebut' AND '$dateFin' WHERE a.statut='EN_SERVICE' GROUP BY a.id HAVING (nb_dossiers+nb_mandats)>0 ORDER BY (nb_dossiers+nb_mandats) DESC LIMIT 8")->fetchAll();}catch(Exception $e){}

$dossiersByType=[];
try{$dossiersByType=$db->query("SELECT type,COUNT(*) n FROM usss_dossiers WHERE date_ouverture BETWEEN '$dateDebut' AND '$dateFin' GROUP BY type ORDER BY n DESC")->fetchAll();}catch(Exception $e){}

$mandatsByType=[];
try{$mandatsByType=$db->query("SELECT type,COUNT(*) n FROM usss_mandats WHERE date_emission BETWEEN '$dateDebut' AND '$dateFin' GROUP BY type ORDER BY n DESC")->fetchAll();}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
?>
<!-- Sélecteur période -->
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;align-items:center">
  <span style="font-size:.72rem;color:var(--txt-m);font-family:'Barlow Condensed',sans-serif;letter-spacing:.1em;text-transform:uppercase">Période :</span>
  <?php foreach(['semaine'=>'7 jours','mois'=>'30 jours','trimestre'=>'90 jours','annee'=>'1 an'] as $p=>$l):?>
  <a href="?periode=<?php echo $p;?>" class="btn <?php echo $periode===$p?'btn-primary':'btn-ghost';?> btn-sm"><?php echo $l;?></a>
  <?php endforeach;?>
  <span style="font-size:.72rem;color:var(--txt-m);margin-left:auto;font-family:'IBM Plex Mono',monospace"><?php echo date('d.m.Y',strtotime($dateDebut));?> → <?php echo date('d.m.Y',strtotime($dateFin));?></span>
</div>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(6,1fr);margin-bottom:1.25rem">
  <div class="kpi" style="--kpi-color:var(--green-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-id-badge"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Agents Actifs</div><div class="kpi-val"><?php echo $statsGlobal['agents_actifs']??0;?></div></div>
  <div class="kpi" style="--kpi-color:var(--blue-xl)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-folder-open"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Dossiers</div><div class="kpi-val"><?php echo $statsGlobal['dossiers_periode']??0;?></div></div>
  <div class="kpi" style="--kpi-color:var(--green-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-check"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Résolus</div><div class="kpi-val"><?php echo $statsGlobal['dossiers_resolus']??0;?></div></div>
  <div class="kpi" style="--kpi-color:var(--amber-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-gavel"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Mandats</div><div class="kpi-val"><?php echo $statsGlobal['mandats_periode']??0;?></div></div>
  <div class="kpi" style="--kpi-color:var(--red-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-crosshairs"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Opérations</div><div class="kpi-val"><?php echo $statsGlobal['ops_periode']??0;?></div></div>
  <div class="kpi" style="--kpi-color:var(--gold-l)"><div class="kpi-ico"></div><div class="kpi-ico-inner"><i class="fas fa-coins"></i></div><div class="kpi-lbl" style="margin-top:2.1rem">Financier</div><div class="kpi-val" style="font-size:1rem">$<?php echo number_format($statsGlobal['montant_financier']??0,0);?></div></div>
</div>

<div class="grid2">
<!-- Top agents -->
<div class="card">
  <div class="card-title"><i class="fas fa-medal"></i> Agents les Plus Actifs</div>
  <?php if(empty($topAgents)):?><div style="text-align:center;padding:2rem;color:var(--txt-m)">Aucune activité sur la période</div>
  <?php else: foreach($topAgents as $idx=>$a): $total=($a['nb_dossiers']+$a['nb_mandats']); $max=($topAgents[0]['nb_dossiers']+$topAgents[0]['nb_mandats']); $pct=round(($total/max(1,$max))*100);?>
  <div style="margin-bottom:.7rem">
    <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.25rem">
      <div style="width:22px;height:22px;border-radius:5px;background:<?php echo $idx===0?'rgba(200,168,75,.2)':($idx===1?'rgba(148,163,184,.12)':'rgba(26,86,219,.08)');?>;border:1px solid <?php echo $idx===0?'rgba(200,168,75,.5)':($idx===1?'rgba(148,163,184,.3)':'rgba(26,86,219,.2)');?>;display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:.72rem;color:<?php echo $idx===0?'var(--gold-l)':($idx===1?'var(--txt-s)':'var(--blue-l)');?>;flex-shrink:0"><?php echo $idx+1;?></div>
      <div style="flex:1;min-width:0"><div style="font-size:.84rem;font-weight:600"><?php echo htmlspecialchars($a['nom_rp'].' '.$a['prenom_rp']);?></div><div style="font-size:.68rem;color:var(--txt-s)"><?php echo htmlspecialchars($a['grade']);?> · <?php echo $a['badge_number'];?></div></div>
      <div class="mono" style="font-size:.85rem;font-weight:700;color:var(--blue-xl)"><?php echo $total;?></div>
    </div>
    <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $pct;?>%"></div></div>
  </div>
  <?php endforeach; endif;?>
</div>

<!-- Répartition -->
<div>
  <div class="card" style="margin-bottom:1rem">
    <div class="card-title"><i class="fas fa-folder-open"></i> Dossiers par Type</div>
    <?php if(empty($dossiersByType)):?><div style="text-align:center;padding:1.5rem;color:var(--txt-m);font-size:.8rem">Aucune donnée</div>
    <?php else: $maxD=max(array_column($dossiersByType,'n')); foreach($dossiersByType as $r): $pct=round(($r['n']/max(1,$maxD))*100);?>
    <div style="margin-bottom:.5rem">
      <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:.2rem">
        <span><?php echo str_replace('_',' ',$r['type']);?></span>
        <span class="mono"><?php echo $r['n'];?></span>
      </div>
      <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $pct;?>%"></div></div>
    </div>
    <?php endforeach; endif;?>
  </div>
  <div class="card">
    <div class="card-title"><i class="fas fa-gavel"></i> Mandats par Type</div>
    <?php if(empty($mandatsByType)):?><div style="text-align:center;padding:1.5rem;color:var(--txt-m);font-size:.8rem">Aucune donnée</div>
    <?php else: $maxM=max(array_column($mandatsByType,'n')); foreach($mandatsByType as $r): $pct=round(($r['n']/max(1,$maxM))*100);?>
    <div style="margin-bottom:.5rem">
      <div style="display:flex;justify-content:space-between;font-size:.76rem;margin-bottom:.2rem">
        <span><?php echo str_replace('_',' ',$r['type']);?></span>
        <span class="mono"><?php echo $r['n'];?></span>
      </div>
      <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $pct;?>;background:var(--amber-l)"></div></div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>