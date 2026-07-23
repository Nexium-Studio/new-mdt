<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
if (!in_array($_SESSION['user_faction']??'',['LSPD','LSSD','DOJ'])&&!hasRole(['*','Administrateur','Développeur','Staff','Modérateur','Helper','Pôle 911','Pôle Illégale','Pôle Légal'])) redirect(SITE_URL.'/pages/dashboard.php');
$pageTitle='Statistiques'; $db=Database::getInstance()->getConnection();

$period=$_GET['p']??'month';
$dateFrom=$period==='week'?date('Y-m-d',strtotime('-7 days')):($period==='year'?date('Y-01-01'):date('Y-m-01'));

$stats=[];
$qs=[
    'rapports'     =>"SELECT COUNT(*) FROM reports WHERE faction='LSSD' AND DATE(created_at)>=?",
    'casiers'      =>"SELECT COUNT(*) FROM criminal_records WHERE DATE(created_at)>=?",
    'recherches'   =>"SELECT COUNT(*) FROM arrest_warrants WHERE DATE(issued_date)>=?",
    'vehicules'    =>"SELECT COUNT(*) FROM vehicles WHERE DATE(created_at)>=?",
    'sanctions'    =>"SELECT COUNT(*) FROM lssd_sanctions WHERE DATE(created_at)>=?",
    'officiers'    =>"SELECT COUNT(*) FROM users WHERE faction='LSSD' AND is_active=1",
];
foreach($qs as $k=>$q){try{$s=$db->prepare($q);$s->execute($k==='officiers'?[]:[$dateFrom]);$stats[$k]=$s->fetchColumn();}catch(Exception $e){$stats[$k]=0;}}

// Rapports par type
$byType=[];try{$r=$db->prepare("SELECT type,COUNT(*) as n FROM reports WHERE faction='LSSD' AND DATE(created_at)>=? GROUP BY type ORDER BY n DESC");$r->execute([$dateFrom]);$byType=$r->fetchAll();}catch(Exception $e){}

// Top officiers
$topOff=[];
try{$r=$db->prepare("SELECT u.username,u.faction_rank,COUNT(DISTINCT rep.id) as nb_rep,COUNT(DISTINCT sh.id) as nb_sh FROM users u LEFT JOIN reports rep ON rep.created_by=u.id AND DATE(rep.created_at)>=? AND rep.faction='LSSD' LEFT JOIN company_shifts sh ON sh.user_id=u.id AND sh.faction='LSSD' AND DATE(sh.shift_start)>=? WHERE u.faction='LSSD' AND u.is_active=1 GROUP BY u.id ORDER BY (nb_rep+nb_sh) DESC LIMIT 8");$r->execute([$dateFrom,$dateFrom]);$topOff=$r->fetchAll();}catch(Exception $e){}

// Casiers par type
$byJudge=[];try{$r=$db->prepare("SELECT record_type,COUNT(*) as n FROM criminal_records WHERE DATE(created_at)>=? GROUP BY record_type ORDER BY n DESC");$r->execute([$dateFrom]);$byJudge=$r->fetchAll();}catch(Exception $e){}

// Infractions les plus fréquentes
$topOff2=[];try{$r=$db->prepare("SELECT offense_title,COUNT(*) as n FROM criminal_records WHERE DATE(created_at)>=? GROUP BY offense_title ORDER BY n DESC LIMIT 8");$r->execute([$dateFrom]);$topOff2=$r->fetchAll();}catch(Exception $e){}

// Activité par jour (30 jours)
$actDaily=[];try{$r=$db->prepare("SELECT DATE(created_at) as j,COUNT(*) as n FROM reports WHERE faction='LSSD' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY j");$r->execute();$actDaily=$r->fetchAll(PDO::FETCH_KEY_PAIR);}catch(Exception $e){}

include __DIR__ . '/includes/header.php';
?>
<style>
.bar-row{display:flex;align-items:center;gap:.85rem;margin-bottom:.5rem;font-size:.8rem}
.bar-label{min-width:140px;color:var(--txt-s);text-align:right;font-size:.77rem}
.bar-track{flex:1;height:20px;background:rgba(16,185,129,.08);border-radius:4px;overflow:hidden}
.bar-fill{height:100%;border-radius:4px;display:flex;align-items:center;padding-left:.55rem;font-size:.7rem;font-weight:700;color:#fff;min-width:28px}
.bar-val{min-width:35px;text-align:right;font-family:'IBM Plex Mono',monospace;font-weight:700}
.week-bars{display:flex;align-items:flex-end;gap:3px;height:70px;margin-top:.5rem}
</style>

<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <?php foreach(['week'=>'7 jours','month'=>'Ce mois','year'=>'Cette année'] as $k=>$l):?>
    <a href="?p=<?php echo $k;?>" class="btn btn-sm <?php echo $period===$k?'btn-primary':'btn-ghost';?>"><?php echo $l;?></a>
    <?php endforeach;?>
    <span style="margin-left:auto;font-size:.78rem;color:var(--txt-m);align-self:center">Depuis le <?php echo date('d/m/Y',strtotime($dateFrom));?></span>
</div>

<div class="kpi-row" style="margin-bottom:1.5rem">
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-file-alt"></i></div><div class="kpi-lbl">Rapports rédigés</div><div class="kpi-val blue"><?php echo $stats['rapports'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(239,68,68,.12);color:#fca5a5"><i class="fas fa-gavel"></i></div><div class="kpi-lbl">Casiers créés</div><div class="kpi-val red"><?php echo $stats['casiers'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(245,158,11,.12);color:#fde68a"><i class="fas fa-crosshairs"></i></div><div class="kpi-lbl">Avis de recherche</div><div class="kpi-val yellow"><?php echo $stats['recherches'];?></div></div>
    <div class="kpi"><div class="kpi-ico" style="background:rgba(16,185,129,.12);color:#6ee7b7"><i class="fas fa-users"></i></div><div class="kpi-lbl">Effectifs totaux</div><div class="kpi-val green"><?php echo $stats['officiers'];?></div></div>
    <div class="kpi"><div class="kpi-ico"><i class="fas fa-car"></i></div><div class="kpi-lbl">Véhicules enregistrés</div><div class="kpi-val"><?php echo $stats['vehicules'];?></div></div>
</div>

<div class="grid2">
  <div>
    <!-- Rapports par type -->
    <div class="card" style="margin-bottom:1.35rem">
        <div class="card-title"><i class="fas fa-file-alt"></i> Rapports par type</div>
        <?php $maxT=max(array_column($byType,'n')+[1]);?>
        <?php foreach($byType as $b):$p=round($b['n']/$maxT*100);?>
        <div class="bar-row"><div class="bar-label"><?php echo htmlspecialchars($b['type']);?></div><div class="bar-track"><div class="bar-fill" style="width:<?php echo $p;?>%;background:var(--blue)"><?php echo $b['n'];?></div></div></div>
        <?php endforeach;if(empty($byType)):?><div style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucune donnée</div><?php endif;?>
    </div>
    <!-- Casiers par catégorie -->
    <div class="card">
        <div class="card-title"><i class="fas fa-gavel"></i> Casiers par type</div>
        <?php $maxJ=max(array_column($byJudge,'n')+[1]);$TCOLORS=['felony'=>'#ef4444','misdemeanor'=>'#f59e0b','infraction'=>'#10b981','warning'=>'#6b7280'];?>
        <?php foreach($byJudge as $b):$p=round($b['n']/$maxJ*100);$c=$TCOLORS[$b['record_type']]??'#10b981';$labels=['felony'=>'Crime','misdemeanor'=>'Délit','infraction'=>'Contravention','warning'=>'Avertissement'];?>
        <div class="bar-row"><div class="bar-label"><?php echo $labels[$b['record_type']]??$b['record_type'];?></div><div class="bar-track"><div class="bar-fill" style="width:<?php echo $p;?>%;background:<?php echo $c;?>"><?php echo $b['n'];?></div></div></div>
        <?php endforeach;if(empty($byJudge)):?><div style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucune donnée</div><?php endif;?>
    </div>
  </div>
  <div>
    <!-- Top officiers -->
    <div class="card" style="margin-bottom:1.35rem">
        <div class="card-title"><i class="fas fa-medal"></i> Activité des officiers</div>
        <?php $maxA=max(array_map(fn($o)=>$o['nb_rep']+$o['nb_sh'],$topOff)+[1]);?>
        <?php foreach($topOff as $o):$tot=$o['nb_rep']+$o['nb_sh'];$p=round($tot/$maxA*100);?>
        <div class="bar-row">
            <div class="bar-label" style="text-align:left">
                <?php echo htmlspecialchars($o['username']);?>
                <div style="font-size:.63rem;color:var(--txt-m)"><?php echo htmlspecialchars($o['faction_rank']??'');?></div>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo max(3,$p);?>%;background:rgba(16,185,129,.7)"><?php echo $tot;?></div></div>
            <div class="bar-val" style="color:var(--txt-m);font-size:.7rem"><?php echo $o['nb_rep'];?>r <?php echo $o['nb_sh'];?>s</div>
        </div>
        <?php endforeach;if(empty($topOff)):?><div style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucune donnée</div><?php endif;?>
    </div>
    <!-- Top infractions -->
    <div class="card">
        <div class="card-title"><i class="fas fa-book-open"></i> Infractions les plus courantes</div>
        <?php $maxI=max(array_column($topOff2,'n')+[1]);?>
        <?php foreach($topOff2 as $b):$p=round($b['n']/$maxI*100);?>
        <div class="bar-row"><div class="bar-label" style="text-align:left;font-size:.72rem"><?php echo htmlspecialchars(mb_substr($b['offense_title'],0,22));?></div><div class="bar-track"><div class="bar-fill" style="width:<?php echo $p;?>%;background:rgba(239,68,68,.6)"><?php echo $b['n'];?></div></div></div>
        <?php endforeach;if(empty($topOff2)):?><div style="color:var(--txt-m);text-align:center;padding:1.5rem">Aucune donnée</div><?php endif;?>
    </div>
  </div>
</div>
<!-- Activité 30 jours -->
<?php if(!empty($actDaily)):?>
<div class="card">
    <div class="card-title"><i class="fas fa-chart-area"></i> Activité rapports — 30 derniers jours</div>
    <?php $days=[];for($i=29;$i>=0;$i--)$days[date('Y-m-d',strtotime("-{$i} day"))]=0;foreach($actDaily as $d=>$n)if(isset($days[$d]))$days[$d]=$n;$maxD=max(array_values($days)+[1]);?>
    <div class="week-bars">
        <?php foreach($days as $d=>$n):$h=$maxD>0?max(2,round($n/$maxD*100)):2;?>
        <div style="flex:1;height:<?php echo $h;?>%;background:<?php echo $n>0?'rgba(16,185,129,.6)':'rgba(16,185,129,.1)';?>;border-radius:3px 3px 0 0;transition:background .2s" title="<?php echo date('d/m',strtotime($d)).': '.$n.' rapport(s)';?>"></div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>
<?php include __DIR__ . '/includes/footer.php';?>