<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
$pageTitle = 'Code Pénal & Lois';
$db = Database::getInstance()->getConnection();

$CATS = [
    'crime'        => ['label' => 'Crime',        'color' => '#dc2626', 'icon' => 'fa-skull',             'badge' => 'bg-red'],
    'delit_majeur' => ['label' => 'Délit majeur', 'color' => '#f97316', 'icon' => 'fa-triangle-exclamation','badge' => 'bg-orange'],
    'delit_mineur' => ['label' => 'Délit mineur', 'color' => '#fcd34d', 'icon' => 'fa-circle-exclamation', 'badge' => 'bg-yellow'],
    'infraction'   => ['label' => 'Infraction',   'color' => '#93c5fd', 'icon' => 'fa-circle-info',        'badge' => 'bg-blue'],
];
$SEV_COLORS = ['','#4ade80','#a3e635','#fcd34d','#fb923c','#f87171'];

$search      = trim($_GET['q']    ?? '');
$filterCat   = $_GET['cat']       ?? 'all';
$filterArrest= isset($_GET['arrest']);

$q = "SELECT * FROM penal_code WHERE active=1";
$params = [];
if ($filterCat !== 'all') { $q .= " AND category=?"; $params[] = $filterCat; }
if ($filterArrest)        { $q .= " AND is_arrestable=1"; }
if ($search)              { $q .= " AND (title LIKE ? OR code LIKE ? OR description_rich LIKE ?)"; $s="%$search%"; $params[]=$s;$params[]=$s;$params[]=$s; }
$q .= " ORDER BY FIELD(category,'crime','delit_majeur','delit_mineur','infraction'), severity DESC, code";

$stmt=$db->prepare($q);$stmt->execute($params);$allLois=$stmt->fetchAll();

$byCategory=[];
foreach($allLois as $l) $byCategory[$l['category']][]=$l;

$statsStmt=$db->query("SELECT category,COUNT(*) as n FROM penal_code WHERE active=1 GROUP BY category");
$stats=[]; foreach($statsStmt->fetchAll() as $r) $stats[$r['category']]=$r['n'];
$totalActifs=array_sum($stats);

include __DIR__ . '/includes/header.php';
?>
<style>
.law-filters{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:1.4rem;background:var(--bg-c);border:1px solid var(--bdr);border-radius:11px;padding:1rem 1.25rem}
.law-search{position:relative;flex:1;min-width:220px}
.law-search input{width:100%;padding:.65rem 1rem .65rem 2.4rem;background:#04060e;border:1px solid rgba(217,119,6,.2);border-radius:8px;color:var(--txt);font-family:inherit;font-size:.845rem}
.law-search input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(217,119,6,.1)}
.law-search input::placeholder{color:var(--txt-m)}
.law-search i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--txt-m);font-size:.8rem}
.cat-pills{display:flex;gap:.4rem;flex-wrap:wrap}
.cat-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;border-radius:20px;font-size:.78rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .18s;border:1px solid var(--bdr);background:transparent;color:var(--txt-s)}
.cat-pill:hover{background:rgba(217,119,6,.08);color:var(--gold-l);border-color:rgba(217,119,6,.3)}
.cat-pill.on{background:rgba(217,119,6,.15);border-color:var(--gold);color:var(--gold-l)}
.law-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.85rem;margin-bottom:1.4rem}
.law-stat{background:var(--bg-c);border:1px solid var(--bdr);border-radius:10px;padding:1rem;text-align:center}
.ls-n{font-size:1.5rem;font-weight:700;font-family:'IBM Plex Mono',monospace;margin-bottom:.2rem}
.ls-l{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txt-m)}
.cat-section{margin-bottom:1.75rem}
.cat-hd{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;border-radius:10px 10px 0 0;border:1px solid;border-bottom:none}
.cat-hd-left{display:flex;align-items:center;gap:.65rem}
.loi-item{display:flex;align-items:flex-start;gap:1rem;padding:.9rem 1.1rem;border:1px solid;border-top:none;transition:background .15s;cursor:pointer}
.loi-item:last-child{border-radius:0 0 10px 10px}
.loi-item:hover{background:rgba(255,255,255,.025)}
.loi-sev{width:6px;flex-shrink:0;align-self:stretch;border-radius:3px;margin-top:.1rem}
.loi-main{flex:1;min-width:0}
.loi-code{font-family:'IBM Plex Mono',monospace;font-size:.68rem;color:var(--txt-m);margin-bottom:.18rem}
.loi-title{font-weight:700;font-size:.875rem;color:#f5edd6;margin-bottom:.4rem;line-height:1.3}
.loi-desc{font-size:.8rem;color:var(--txt-s);line-height:1.65;margin-bottom:.5rem;display:none;padding-top:.5rem}
.loi-desc.open{display:block}
.loi-tags{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center}
.loi-tag{display:inline-flex;align-items:center;gap:.28rem;padding:.2rem .55rem;border-radius:5px;font-size:.65rem;font-weight:700}
.loi-right{flex-shrink:0;text-align:right;min-width:110px}
.loi-fine{font-family:'IBM Plex Mono',monospace;font-size:.8rem;font-weight:700;color:#fcd34d}
.loi-jail{font-size:.7rem;color:var(--txt-s);margin-top:.15rem}
.chev{color:var(--txt-m);font-size:.68rem;transition:transform .2s;flex-shrink:0;margin-top:.25rem}
.chev.open{transform:rotate(180deg)}
.bg-orange{background:rgba(249,115,22,.1);color:#fb923c;border:1px solid rgba(249,115,22,.2)}
.bg-purple{background:rgba(139,92,246,.1);color:#c084fc;border:1px solid rgba(139,92,246,.2)}
.result-c{font-size:.78rem;color:var(--txt-m);white-space:nowrap}
</style>

<!-- Stats -->
<div class="law-stats">
    <div class="law-stat"><div class="ls-n" style="color:var(--gold-l)"><?php echo $totalActifs;?></div><div class="ls-l">Total actifs</div></div>
    <?php foreach($CATS as $k=>$c):?>
    <div class="law-stat"><div class="ls-n" style="color:<?php echo $c['color'];?>"><?php echo $stats[$k]??0;?></div><div class="ls-l"><?php echo $c['label'];?>s</div></div>
    <?php endforeach;?>
</div>

<!-- Filtres -->
<div class="law-filters">
    <form method="GET" style="display:contents">
        <div class="law-search">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Rechercher article, code, titre…"
                   value="<?php echo htmlspecialchars($search);?>"
                   <?php if($filterCat!=='all'):?>
                   onfocus="this.nextElementSibling || false"<?php endif;?>>
            <?php if($filterCat!=='all'):?><input type="hidden" name="cat" value="<?php echo htmlspecialchars($filterCat);?>"><?php endif;?>
            <?php if($filterArrest):?><input type="hidden" name="arrest" value="1"><?php endif;?>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
    </form>
    <div class="cat-pills">
        <a href="lois.php<?php echo $search?'?q='.urlencode($search):'';?>" class="cat-pill <?php echo $filterCat==='all'&&!$filterArrest?'on':'';?>">
            <i class="fas fa-list"></i> Tout
        </a>
        <?php foreach($CATS as $k=>$c):?>
        <a href="lois.php?cat=<?php echo $k;?><?php echo $search?'&q='.urlencode($search):'';?>"
           class="cat-pill <?php echo $filterCat===$k&&!$filterArrest?'on':'';?>"
           style="<?php echo $filterCat===$k&&!$filterArrest?"background:{$c['color']}18;border-color:{$c['color']}44;color:{$c['color']};":''?>">
            <i class="fas <?php echo $c['icon'];?>"></i> <?php echo $c['label'];?> <span style="opacity:.6">(<?php echo $stats[$k]??0;?>)</span>
        </a>
        <?php endforeach;?>
        <a href="lois.php?arrest=1<?php echo $filterCat!=='all'?'&cat='.$filterCat:'';?><?php echo $search?'&q='.urlencode($search):'';?>"
           class="cat-pill <?php echo $filterArrest?'on':'';?>">
            <i class="fas fa-handcuffs"></i> Arrestation seulement
        </a>
        <a href="lois.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </div>
    <span class="result-c"><?php echo count($allLois);?> résultat(s)</span>
</div>

<!-- Résultats -->
<?php if(empty($allLois)):?>
<div style="text-align:center;padding:4rem 2rem;color:var(--txt-m)">
    <i class="fas fa-book" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
    <p>Aucun article trouvé<?php echo $search?' pour « '.htmlspecialchars($search).' »':'';?></p>
</div>
<?php else:?>
<?php foreach($CATS as $catKey=>$catInfo):
    if(!isset($byCategory[$catKey])) continue;
    $catLois=$byCategory[$catKey];
    $cc=$catInfo['color'];
?>
<div class="cat-section">
    <div class="cat-hd" style="background:<?php echo $cc;?>0d;border-color:<?php echo $cc;?>33">
        <div class="cat-hd-left">
            <i class="fas <?php echo $catInfo['icon'];?>" style="color:<?php echo $cc;?>;width:18px;text-align:center"></i>
            <span style="font-family:'Playfair Display',serif;font-size:.95rem;font-weight:700;color:<?php echo $cc;?>"><?php echo $catInfo['label'];?>s</span>
            <span style="font-size:.72rem;color:var(--txt-m)"><?php echo count($catLois);?> article(s)</span>
        </div>
    </div>

    <?php foreach($catLois as $loi):
        $lid='loi-'.$loi['id'];
        $sc=$SEV_COLORS[$loi['severity']??1]??'#94a3b8';
        $hasContent=!empty(trim($loi['description_rich']??''))||!empty(trim($loi['examples']??''));
        $minJail=(int)($loi['min_jail_months']??0);
        $maxJail=(int)($loi['max_jail_months']??0);
        $minFine=(float)($loi['min_fine']??0);
        $maxFine=(float)($loi['max_fine']??0);
    ?>
    <div class="loi-item" style="background:<?php echo $cc;?>04;border-color:<?php echo $cc;?>22"
         onclick="<?php echo $hasContent?"tgl('$lid')":''; ?>">
        <div class="loi-sev" style="background:<?php echo $sc;?>;opacity:.75"></div>
        <div class="loi-main">
            <div class="loi-code"><?php echo htmlspecialchars($loi['code']);?></div>
            <div class="loi-title"><?php echo htmlspecialchars($loi['title']);?></div>
            <?php if($hasContent):?>
            <div class="loi-desc" id="desc-<?php echo $lid;?>">
                <?php if(!empty(trim($loi['description_rich']??''))):?>
                <div style="margin-bottom:.5rem"><?php echo nl2br(htmlspecialchars(strip_tags($loi['description_rich'])));?></div>
                <?php endif;?>
                <?php if(!empty(trim($loi['examples']??''))):?>
                <div style="background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:7px;padding:.6rem .85rem;font-size:.78rem;color:var(--txt-s)">
                    <i class="fas fa-lightbulb" style="color:var(--gold);margin-right:.35rem"></i>
                    <strong style="color:var(--gold-l)">Exemples :</strong> <?php echo htmlspecialchars($loi['examples']);?>
                </div>
                <?php endif;?>
            </div>
            <?php endif;?>
            <div class="loi-tags">
                <?php if($loi['is_arrestable']):?><span class="loi-tag bg-red"><i class="fas fa-handcuffs" style="font-size:.58rem"></i> Arrestation</span><?php endif;?>
                <?php if($loi['is_warrantable']):?><span class="loi-tag bg-purple"><i class="fas fa-scroll" style="font-size:.58rem"></i> Mandat</span><?php endif;?>
                <?php if($loi['points']>0):?><span class="loi-tag bg-yellow"><i class="fas fa-id-card" style="font-size:.58rem"></i> <?php echo $loi['points'];?> pts</span><?php endif;?>
                <span class="loi-tag" style="background:<?php echo $sc;?>18;color:<?php echo $sc;?>;border:1px solid <?php echo $sc;?>33;letter-spacing:.05em">
                    <?php echo str_repeat('●',$loi['severity']??1).str_repeat('○',5-($loi['severity']??1));?>
                </span>
            </div>
        </div>
        <div class="loi-right">
            <?php if($maxFine>0):?>
            <div class="loi-fine">
                <?php if($minFine>0&&$minFine!==$maxFine):?>$<?php echo number_format($minFine,0,'.',',');?> – <?php endif;?>$<?php echo number_format($maxFine,0,'.',',');?>
            </div>
            <?php endif;?>
            <?php if($maxJail>0):?>
            <div class="loi-jail">
                <i class="fas fa-building-lock" style="margin-right:.3rem;opacity:.6"></i>
                <?php if($minJail>0&&$minJail!==$maxJail):?><?php echo $minJail;?>–<?php endif;?><?php echo $maxJail;?> mois
            </div>
            <?php endif;?>
            <?php if($maxFine===0.0&&$maxJail===0):?><span style="font-size:.7rem;color:var(--txt-m)">Variable</span><?php endif;?>
        </div>
        <?php if($hasContent):?><i class="fas fa-chevron-down chev" id="chev-<?php echo $lid;?>"></i><?php else:?><div style="width:14px;flex-shrink:0"></div><?php endif;?>
    </div>
    <?php endforeach;?>
</div>
<?php endforeach;?>
<?php endif;?>

<script>
function tgl(id) {
    const d=document.getElementById('desc-'+id), c=document.getElementById('chev-'+id);
    if(!d) return;
    const o=d.classList.toggle('open');
    if(c) c.classList.toggle('open',o);
}
</script>
<?php include __DIR__ . '/includes/footer.php';?>