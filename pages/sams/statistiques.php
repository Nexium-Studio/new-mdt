<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if ($_SESSION['user_faction'] !== 'SAMS' && !hasRole(['*','Administrateur','Développeur','Staff'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle = 'Statistiques';
$db = Database::getInstance()->getConnection();

$period=$_GET['p']??'month';
$dateFrom=$period==='week'?date('Y-m-d',strtotime('-7 days')):($period==='year'?date('Y-01-01'):date('Y-m-01'));
$dateTo=date('Y-m-d');

// Stats globales période
$stats=[];
$qs=['interventions'=>"SELECT COUNT(*) FROM sams_interventions WHERE DATE(created_at)>=?",
     'inter_termine'=>"SELECT COUNT(*) FROM sams_interventions WHERE statut='termine' AND DATE(created_at)>=?",
     'rapports'=>"SELECT COUNT(*) FROM sams_reports WHERE DATE(created_at)>=?",
     'factures_total'=>"SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE DATE(created_at)>=?",
     'factures_paid'=>"SELECT COALESCE(SUM(total_amount),0) FROM sams_invoices WHERE status='paid' AND DATE(created_at)>=?",
     'ordo'=>"SELECT COUNT(*) FROM medical_prescriptions WHERE DATE(created_at)>=?",
     'certificats'=>"SELECT COUNT(*) FROM medical_certificates WHERE DATE(created_at)>=?",
     'triage_total'=>"SELECT COUNT(*) FROM sams_triage WHERE DATE(created_at)>=?",
     'naissances'=>"SELECT COUNT(*) FROM medical_certificates WHERE type='naissance' AND DATE(created_at)>=?",
     'deces'=>"SELECT COUNT(*) FROM death_certificates WHERE DATE(date_of_death)>=?",
];
foreach($qs as $k=>$q){try{$stmt=$db->prepare($q);$stmt->execute([$dateFrom]);$stats[$k]=$stmt->fetchColumn();}catch(Exception $e){$stats[$k]=0;}}

// Interventions par type
$byType=[];try{$r=$db->prepare("SELECT type,COUNT(*) as n FROM sams_interventions WHERE DATE(created_at)>=? GROUP BY type ORDER BY n DESC");$r->execute([$dateFrom]);$byType=$r->fetchAll();}catch(Exception $e){}

// Interventions par urgence
$byUrgence=[];try{$r=$db->prepare("SELECT urgence,COUNT(*) as n FROM sams_interventions WHERE DATE(created_at)>=? GROUP BY urgence ORDER BY n DESC");$r->execute([$dateFrom]);$byUrgence=$r->fetchAll();}catch(Exception $e){}

// Evolution CA mensuelle (12 mois)
$caMonthly=[];try{$r=$db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as mois,COALESCE(SUM(total_amount),0) as total FROM sams_invoices WHERE status='paid' AND created_at>=DATE_SUB(NOW(),INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois");$caMonthly=$r->fetchAll(\PDO::FETCH_KEY_PAIR);}catch(Exception $e){}

// Top médecins (affichés en Nom + Prénom du profil, jamais le pseudo — règle SAMS §5)
$topMed=[];try{$r=$db->prepare("SELECT pr.firstname AS med_fn, pr.lastname AS med_ln,COUNT(DISTINCT sr.id) as nb_rapports,COUNT(DISTINCT mp.id) as nb_ordo,COUNT(DISTINCT si.id) as nb_inter FROM users u LEFT JOIN profiles pr ON pr.id=u.profile_id LEFT JOIN sams_reports sr ON sr.created_by=u.id AND DATE(sr.created_at)>=? LEFT JOIN medical_prescriptions mp ON mp.doctor_id=u.id AND DATE(mp.created_at)>=? LEFT JOIN sams_interventions si ON si.created_by=u.id AND DATE(si.created_at)>=? WHERE u.faction='SAMS' GROUP BY u.id ORDER BY (nb_rapports+nb_ordo+nb_inter) DESC LIMIT 8");$r->execute([$dateFrom,$dateFrom,$dateFrom]);$topMed=$r->fetchAll();}catch(Exception $e){}

// Triage par niveau
$triageNiveau=[];try{$r=$db->prepare("SELECT niveau,COUNT(*) as n FROM sams_triage WHERE DATE(created_at)>=? GROUP BY niveau ORDER BY niveau");$r->execute([$dateFrom]);$triageNiveau=$r->fetchAll(\PDO::FETCH_KEY_PAIR);}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
$tauxRecouvrement=$stats['factures_total']>0?round($stats['factures_paid']/$stats['factures_total']*100):0;
$tauxInterTermine=$stats['interventions']>0?round($stats['inter_termine']/$stats['interventions']*100):0;
?>
<style>
.stat-section{margin-bottom:1.75rem}
.stat-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--txt-s);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:.5rem}
.stat-section-title i{color:var(--red-l)}
.bar-chart{display:flex;flex-direction:column;gap:.5rem}
.bar-row{display:flex;align-items:center;gap:.85rem;font-size:.8rem}
.bar-label{min-width:130px;color:var(--txt-s);text-align:right;font-size:.78rem}
.bar-track{flex:1;height:22px;background:rgba(255,255,255,.04);border-radius:5px;overflow:hidden}
.bar-fill{height:100%;border-radius:5px;display:flex;align-items:center;padding-left:.6rem;font-size:.72rem;font-weight:700;color:#fff;transition:width .5s ease;min-width:30px}
.bar-val{min-width:45px;text-align:right;font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:.82rem}
.pct-ring{width:90px;height:90px;border-radius:50%;position:relative;display:flex;align-items:center;justify-content:center;font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:1.1rem}
</style>

<!-- Filtres période -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <?php foreach(['week'=>'7 jours','month'=>'Ce mois','year'=>'Cette année'] as $k=>$l):?>
    <a href="?p=<?php echo $k;?>" class="btn btn-sm <?php echo $period===$k?'btn-primary':'btn-ghost';?>"><?php echo $l;?></a>
    <?php endforeach;?>
    <span style="margin-left:auto;font-size:.78rem;color:var(--txt-m);align-self:center">Du <?php echo date('d/m/Y',strtotime($dateFrom));?> au <?php echo date('d/m/Y');?></span>
</div>

<!-- KPIs principaux -->
<div class="kpi-grid" style="margin-bottom:1.5rem">
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-ambulance"></i></div><div class="kpi-lbl">Interventions</div><div class="kpi-val"><?php echo $stats['interventions'];?></div><div class="kpi-sub"><?php echo $tauxInterTermine;?>% terminées</div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-coins"></i></div><div class="kpi-lbl">CA total</div><div class="kpi-val">$<?php echo number_format($stats['factures_total'],0,'.',',');?></div><div class="kpi-sub">Recouvrement : <?php echo $tauxRecouvrement;?>%</div></div>
    <div class="kpi" data-color="blue"><div class="kpi-ico"><i class="fas fa-file-medical"></i></div><div class="kpi-lbl">Rapports</div><div class="kpi-val"><?php echo $stats['rapports'];?></div></div>
    <div class="kpi" data-color="purple"><div class="kpi-ico"><i class="fas fa-prescription"></i></div><div class="kpi-lbl">Ordonnances</div><div class="kpi-val"><?php echo $stats['ordo'];?></div></div>
    <div class="kpi" data-color="yellow"><div class="kpi-ico"><i class="fas fa-hospital"></i></div><div class="kpi-lbl">Passages triage</div><div class="kpi-val"><?php echo $stats['triage_total'];?></div></div>
    <div class="kpi" data-color="red"><div class="kpi-ico"><i class="fas fa-baby"></i></div><div class="kpi-lbl">Naissances</div><div class="kpi-val"><?php echo $stats['naissances'];?></div></div>
    <div class="kpi" data-color="gray"><div class="kpi-ico"><i class="fas fa-cross"></i></div><div class="kpi-lbl">Décès certifiés</div><div class="kpi-val"><?php echo $stats['deces'];?></div></div>
    <div class="kpi" data-color="green"><div class="kpi-ico"><i class="fas fa-clipboard-check"></i></div><div class="kpi-lbl">CA encaissé</div><div class="kpi-val">$<?php echo number_format($stats['factures_paid'],0,'.',',');?></div></div>
</div>

<div class="grid2">
  <div>
    <!-- Interventions par type -->
    <div class="card stat-section" style="margin-bottom:1.35rem">
        <div class="stat-section-title"><i class="fas fa-ambulance"></i> Interventions par type</div>
        <?php if(empty($byType)):?><p style="color:var(--txt-m);text-align:center;padding:1rem">Aucune donnée</p>
        <?php else:$maxT=max(array_column($byType,'n'));$TYPES=['traumatisme'=>'Traumatisme','maladie'=>'Maladie','accident'=>'Accident','overdose'=>'Overdose','noyade'=>'Noyade','incendie'=>'Incendie','violence'=>'Violence','autre'=>'Autre'];?>
        <div class="bar-chart">
        <?php foreach($byType as $b):$pct=$maxT>0?round($b['n']/$maxT*100):0;?>
        <div class="bar-row"><div class="bar-label"><?php echo $TYPES[$b['type']]??$b['type'];?></div><div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct;?>%;background:var(--red)"><?php echo $b['n'];?></div></div></div>
        <?php endforeach;?>
        </div><?php endif;?>
    </div>

    <!-- Triage par niveau -->
    <div class="card">
        <div class="stat-section-title"><i class="fas fa-hospital"></i> Triage par niveau de priorité</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem">
            <?php foreach(['P1'=>['#dc2626','Critique'],'P2'=>['#f59e0b','Urgent'],'P3'=>['#3b82f6','Semi-urgent'],'P4'=>['#10b981','Non urgent']] as $n=>[$c,$l]):?>
            <div style="text-align:center;background:var(--bg-ov);border-radius:10px;padding:1rem;border:1px solid <?php echo $c;?>33">
                <div style="font-family:'IBM Plex Mono',monospace;font-size:1.5rem;font-weight:800;color:<?php echo $c;?>"><?php echo $triageNiveau[$n]??0;?></div>
                <div style="font-size:.72rem;font-weight:700;color:<?php echo $c;?>;margin-top:.25rem"><?php echo $n;?></div>
                <div style="font-size:.65rem;color:var(--txt-m)"><?php echo $l;?></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
  </div>
  <div>
    <!-- Top médecins -->
    <div class="card stat-section" style="margin-bottom:1.35rem">
        <div class="stat-section-title"><i class="fas fa-medal"></i> Activité du personnel</div>
        <?php if(empty($topMed)):?><p style="color:var(--txt-m);text-align:center;padding:1rem">Aucune donnée</p>
        <?php else:$maxAct=max(array_map(fn($m)=>$m['nb_rapports']+$m['nb_ordo']+$m['nb_inter'],$topMed)+[1]);?>
        <div class="bar-chart">
        <?php foreach($topMed as $m):$total=$m['nb_rapports']+$m['nb_ordo']+$m['nb_inter'];$pct=$maxAct>0?round($total/$maxAct*100):0;?>
        <?php $medName=mdt_rp_display_name($m['med_fn']??null,$m['med_ln']??null,''); if($medName===''||$medName==='—') $medName='Profil non renseigné'; ?>
        <div class="bar-row">
            <div class="bar-label" style="text-align:left"><?php echo htmlspecialchars($medName);?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo max(3,$pct);?>%;background:rgba(220,38,38,.7)"><?php echo $total;?></div></div>
            <div class="bar-val" style="color:var(--txt-m);font-size:.7rem"><?php echo $m['nb_inter'];?>i <?php echo $m['nb_rapports'];?>r</div>
        </div>
        <?php endforeach;?>
        </div><?php endif;?>
    </div>

    <!-- Urgences par niveau -->
    <div class="card">
        <div class="stat-section-title"><i class="fas fa-triangle-exclamation"></i> Urgence des interventions</div>
        <?php if(empty($byUrgence)):?><p style="color:var(--txt-m);text-align:center;padding:1rem">Aucune donnée</p>
        <?php else:$maxU=max(array_column($byUrgence,'n'));$URGC=['critique'=>['#dc2626','Critique'],'elevee'=>['#f97316','Élevée'],'moderee'=>['#3b82f6','Modérée'],'faible'=>['#10b981','Faible']];?>
        <div class="bar-chart">
        <?php foreach($byUrgence as $b):$pct=$maxU>0?round($b['n']/$maxU*100):0;[$c,$l]=$URGC[$b['urgence']]??['#9ca3af','—'];?>
        <div class="bar-row"><div class="bar-label"><?php echo $l;?></div><div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct;?>%;background:<?php echo $c;?>"><?php echo $b['n'];?></div></div></div>
        <?php endforeach;?>
        </div><?php endif;?>
    </div>
  </div>
</div>

<!-- CA mensuel -->
<?php if(!empty($caMonthly)):?>
<div class="card">
    <div class="stat-section-title"><i class="fas fa-chart-line"></i> Évolution du CA (12 mois)</div>
    <?php $maxCA=max(array_values($caMonthly)+[1]);?>
    <div style="display:flex;align-items:flex-end;gap:6px;height:100px">
    <?php foreach($caMonthly as $m=>$ca):$h=round($ca/$maxCA*100);?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:.25rem">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:var(--txt-m);white-space:nowrap">$<?php echo number_format($ca/1000,1);?>k</div>
        <div style="width:100%;background:rgba(220,38,38,.7);border-radius:4px 4px 0 0;height:<?php echo max(3,$h);?>%" title="$<?php echo number_format($ca,0);?>"></div>
        <div style="font-size:.6rem;color:var(--txt-m)"><?php echo substr($m,5,2).'/'.substr($m,2,2);?></div>
    </div>
    <?php endforeach;?>
    </div>
</div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php';?>